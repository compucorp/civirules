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
   * @return bool
   */
  public function run(array $params): bool {
    $retentionDays = $this->readNonNegativeInt($params, 'retention_days', self::DEFAULT_RETENTION_DAYS);
    $maxRows = $this->readNonNegativeInt($params, 'max_rows', self::DEFAULT_MAX_ROWS);

    if ($retentionDays === NULL || $maxRows === NULL) {
      return FALSE;
    }

    $effectiveMaxRows = $maxRows > 0 ? $maxRows : PHP_INT_MAX;
    $thresholdDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

    try {
      $deleted = $this->deleteOlderThan($thresholdDate, $effectiveMaxRows);
      $message = E::ts('CiviRules rule log purge: %1 log(s) deleted (older than %2 days, before %3).', [
        1 => $deleted,
        2 => $retentionDays,
        3 => $thresholdDate,
      ]);

      if ($deleted >= $effectiveMaxRows) {
        $message .= ' ' . E::ts('The per run ceiling of %1 row(s) was reached; the remainder will be removed on the next run.', [
          1 => $maxRows,
        ]);
      }

      Civi::log()->info($message);
    }
    catch (Throwable $e) {
      Civi::log()->error(E::ts('Error purging CiviRules rule log: %1', [1 => $e->getMessage()]));

      return FALSE;
    }

    CRM_Core_Session::setStatus($message, E::ts('Success'), 'success');

    return TRUE;
  }

  /**
   * Reads and validates a non negative integer parameter.
   *
   * @param array<string,mixed> $params
   * @param string $name
   * @param int $default
   *
   * @return int|null
   */
  private function readNonNegativeInt(array $params, string $name, int $default): ?int {
    $value = $params[$name] ?? $default;

    if (!is_numeric($value) || (int) $value < 0) {
      Civi::log()->error(E::ts('CiviRules rule log purge: invalid %1 "%2", expected a non negative number.', [
        1 => $name,
        2 => is_scalar($value) ? (string) $value : gettype($value),
      ]));

      return NULL;
    }

    return (int) $value;
  }

  /**
   * Deletes, in batches, rule log rows logged before the given date.
   *
   * @param string $thresholdDate
   * @param int $maxRows
   *
   * @return int
   *   Total number of deleted rows.
   */
  private function deleteOlderThan(string $thresholdDate, int $maxRows): int {
    $previousLoggingFlag = $this->disableLogging();
    $total = 0;

    try {
      do {
        $limit = min(self::BATCH_SIZE, $maxRows - $total);
        $dao = CRM_Core_DAO::executeQuery(
          'DELETE FROM civirule_rule_log WHERE log_date < %1 LIMIT %2',
          [
            1 => [$thresholdDate, 'String'],
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
