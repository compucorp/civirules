<?php

use CRM_Civirules_ExtensionUtil as E;

abstract class CRM_Civirules_Trigger_Cron extends CRM_Civirules_Trigger {

  /**
   * @var \CRM_Core_Lock
   */
  private $lock;

  /**
   * This function returns a CRM_Civirules_TriggerData_TriggerData this entity is used for triggering the rule
   *
   * Return false when no next entity is available
   *
   * @return CRM_Civirules_TriggerData_TriggerData|false
   */
  abstract protected function getNextEntityTriggerData();

  /**
   * @return array
   */
  public function process() {
    $result = [
      'count' => 0,
      'is_valid_count' => 0,
      'is_error_count' => 0,
    ];

    if (!$this->acquireLock()) {
      return $result;
    }

    while ($triggerData = $this->getNextEntityTriggerData()) {
      try {
        $this->alterTriggerData($triggerData);
        $isValid = CRM_Civirules_Engine::triggerRule($this, $triggerData);
      }
      catch (Throwable $e) {
        \Civi::log()->error('Failed to trigger rule: ' . $e->getMessage());
        $result['is_error_count']++;
        continue;
      }

      if ($isValid) {
        $result['is_valid_count']++;
      }
      $result['count']++;
    }

    $this->releaseLock();

    return $result;
  }

  /**
   * The earliest entity date this rule acts on, as 'YmdHis'.
   *
   * Taken from the trigger_from_date trigger param, falling back to the date
   * the rule was created. Returns NULL when neither is usable: callers must
   * then skip the query entirely rather than run it without a lower bound.
   *
   * Bind the result as a 'Timestamp' parameter - an empty 'String' parameter
   * becomes `date_field > ''`, which MySQL reads as the zero date and matches
   * every historical row.
   *
   * @return string|null
   */
  protected function getTriggerFromDate(): ?string {
    $date = $this->triggerParams['trigger_from_date'] ?? '';
    if (empty($date)) {
      $date = $this->getRuleCreatedDate();
    }
    $timestamp = empty($date) ? FALSE : strtotime($date);
    return $timestamp ? date('YmdHis', $timestamp) : NULL;
  }

  /**
   * A note for getTriggerDescription() naming the rule's earliest trigger date.
   *
   * Reads the configured value directly rather than through
   * getTriggerFromDate(): the rules list renders descriptions without a rule
   * id, so the created-date fallback cannot be resolved here.
   *
   * @return string
   */
  protected function getTriggerFromDateDescription(): string {
    if (!empty($this->triggerParams['trigger_from_date'])) {
      return E::ts('This rule will not trigger for dates before %1.', [
        1 => CRM_Utils_Date::customFormat($this->triggerParams['trigger_from_date']),
      ]);
    }
    return E::ts('This rule will not trigger for dates before the date the rule was created.');
  }

  /**
   * Acquires a lock. Returns true when the lock was free and is acquired
   * Returns false when the lock was not free or could not be acquired.
   *
   * @return bool
   */
  protected function acquireLock() {
    try {
      $name = 'civirules_cron_rule_' . $this->getRuleId();
      if ($this->lock == NULL) {
        $this->lock = new CRM_Core_Lock($name, $this->getLockTimeout());
        if ($this->lock->isFree()) {
          $this->lock->acquire();
          if ($this->lock->isAcquired()) {
            return TRUE;
          }
        }
      }
    }
    catch (\CRM_Core_Exception $e) {
      // Do nothing.
    }
    return FALSE;
  }

  /**
   * Releases the lock
   *
   */
  protected function releaseLock() {
    if ($this->lock) {
      $this->lock->release();
    }
  }

  /**
   * Returns the lock timeout for this trigger in seconds
   *
   * @return int
   */
  protected function getLockTimeout() {
    //1800 seconds = 30 minutes
    return 1800;
  }

  /**
   *
   * Returns the name of the trigger data class.
   *
   * This function could be overridden in a child class.
   *
   * @return String
   *
   */
  public function getTriggerDataClassName() {
    return 'CRM_Civirules_TriggerData_Cron';
  }

}
