<?php

use CRM_Civirules_ExtensionUtil as E;

/**
 * Scheduled job that purges old CiviRules rule log records.
 */
class CRM_Civirules_Job_PurgeRuleLog {

  /**
   * Number of days of log data to keep when no parameter is supplied.
   */
  const DEFAULT_RETENTION_DAYS = 90;

  /**
   * Smallest retention window the job will accept.
   */
  const MIN_RETENTION_DAYS = 1;

  /**
   * Maximum rows removed in a single run when no parameter is supplied.
   */
  const DEFAULT_MAX_ROWS = 500000;

  /**
   * Rows removed per DELETE statement, to avoid long running locks.
   */
  const BATCH_SIZE = 5000;

  /**
   * Deletes rule log records older than the retention period.
   *
   * @param array<string,mixed> $params
   *
   * @return array{deleted:int,retention_days:int,max_rows:int,ceiling_reached:bool,message:string}|null
   */
  public function run(array $params): ?array {
    $retentionDays = $this->readIntAtLeast($params, 'retention_days', self::DEFAULT_RETENTION_DAYS, self::MIN_RETENTION_DAYS);
    $maxRows = $this->readIntAtLeast($params, 'max_rows', self::DEFAULT_MAX_ROWS, 0);

    if ($retentionDays === NULL || $maxRows === NULL) {
      return NULL;
    }

    $effectiveMaxRows = $maxRows > 0 ? $maxRows : PHP_INT_MAX;

    try {
      $deleted = $this->deleteOlderThan($retentionDays, $effectiveMaxRows);
    }
    catch (Throwable $e) {
      Civi::log()->error(E::ts('Error purging CiviRules rule log: %1', [1 => $e->getMessage()]));

      return NULL;
    }

    $ceilingReached = $deleted >= $effectiveMaxRows;
    $message = E::ts('CiviRules rule log purge: %1 log(s) deleted (older than %2 days).', [
      1 => $deleted,
      2 => $retentionDays,
    ]);

    if ($ceilingReached) {
      $message .= ' ' . E::ts('The per run ceiling of %1 row(s) was reached; the remainder will be removed across the next runs.', [
        1 => $maxRows,
      ]);
    }

    Civi::log()->info($message);
    CRM_Core_Session::setStatus($message, E::ts('Success'), 'success');

    return [
      'deleted' => $deleted,
      'retention_days' => $retentionDays,
      'max_rows' => $maxRows,
      'ceiling_reached' => $ceilingReached,
      'message' => $message,
    ];
  }

  /**
   * Reads a parameter that must be a whole number at or above $min.
   *
   * @param array<string,mixed> $params
   * @param string $name
   * @param int $default
   * @param int $min
   *
   * @return int|null
   *   NULL when the supplied value is unusable, having logged the reason.
   */
  private function readIntAtLeast(array $params, string $name, int $default, int $min): ?int {
    $value = $params[$name] ?? $default;
    $parsed = filter_var($value, FILTER_VALIDATE_INT);

    if ($parsed === FALSE || $parsed < $min) {
      Civi::log()->error(E::ts('CiviRules rule log purge: invalid %1 "%2", expected a whole number of %3 or more.', [
        1 => $name,
        2 => is_scalar($value) ? (string) $value : gettype($value),
        3 => $min,
      ]));

      return NULL;
    }

    return $parsed;
  }

  /**
   * Deletes, in batches, rule log rows older than the retention period.
   *
   * @param int $retentionDays
   * @param int $maxRows
   *
   * @return int
   *   Total number of deleted rows.
   */
  private function deleteOlderThan(int $retentionDays, int $maxRows): int {
    $previousLoggingFlag = $this->disableLogging();
    $total = 0;

    try {
      do {
        $limit = min(self::BATCH_SIZE, $maxRows - $total);
        $dao = CRM_Core_DAO::executeQuery(
          'DELETE FROM civirule_rule_log WHERE log_date < NOW() - INTERVAL %1 DAY order by id LIMIT %2',
          [
            1 => [$retentionDays, 'Integer'],
            2 => [$limit, 'Integer'],
          ]
        );
        $affected = (int) $dao->affectedRows();
        $total += $affected;
      } while ($affected === $limit && $total < $maxRows);
    }
    finally {
      $this->restoreLogging($previousLoggingFlag);
    }

    return $total;
  }

  /**
   * Suppresses the CiviCRM logging triggers on the current DB connection.
   *
   * @return int
   */
  private function disableLogging(): int {
    if (empty(CRM_Core_Config::singleton()->logging)) {
      return 0;
    }

    $previous = (int) CRM_Core_DAO::singleValueQuery('SELECT @civicrm_disable_logging');
    CRM_Logging_Schema::disableLoggingForThisConnection();

    return $previous;
  }

  /**
   * Restores the logging suppression flag to its previous value.
   *
   * @param int $previous
   */
  private function restoreLogging(int $previous): void {
    if (empty(CRM_Core_Config::singleton()->logging)) {
      return;
    }

    CRM_Core_DAO::executeQuery('SET @civicrm_disable_logging = %1', [
      1 => [$previous, 'Integer'],
    ]);
  }

}
