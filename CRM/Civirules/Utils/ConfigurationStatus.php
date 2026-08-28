<?php

/**
 * Whether a rule action/condition has been configured.
 *
 * The type is chosen in one form and configured in another, and the row is written
 * between the two - so abandoning the configuration form leaves a row that is active
 * but has no parameters. This class is the single definition of "configured", in both
 * a SQL and a PHP form; the two must stay in agreement.
 *
 * A row counts as configured when its params column is not NULL, or when its class
 * needs no configuration at all (getExtraDataInputUrl() returns FALSE). NULL rather
 * than empty is deliberate: every configuration form writes serialize($data), so a
 * form saved with nothing filled in stores 'a:0:{}' and is correctly treated as
 * configured.
 */
class CRM_Civirules_Utils_ConfigurationStatus {

  /**
   * getExtraDataInputUrl() takes the rule action/condition id, which does not exist
   * yet when we are only asking the class whether it needs configuring at all.
   */
  private const CONFIG_PROBE_ID = 0;

  private const CACHE_PREFIX = 'civirules_no_config_';

  public static function getActionIdsNotRequiringConfiguration(): array {
    return self::getIdsNotRequiringConfiguration('civirule_action', 'CRM_Civirules_Action');
  }

  public static function getConditionIdsNotRequiringConfiguration(): array {
    return self::getIdsNotRequiringConfiguration('civirule_condition', 'CRM_Civirules_Condition');
  }

  /**
   * Cacheable because the answer depends only on the class, not on any row: no
   * action/condition class takes constructor arguments, and every implementation of
   * getExtraDataInputUrl() is a single unconditional return.
   */
  private static function getIdsNotRequiringConfiguration(string $table, string $baseClass): array {
    $cacheKey = self::CACHE_PREFIX . $table;
    $ids = Civi::cache('metadata')->get($cacheKey);
    if ($ids !== NULL) {
      return $ids;
    }
    $ids = [];
    $dao = CRM_Core_DAO::executeQuery("SELECT `id`, `class_name` FROM `{$table}`");
    while ($dao->fetch()) {
      try {
        if (empty($dao->class_name) || !class_exists($dao->class_name)) {
          continue;
        }
        $object = new $dao->class_name();
        if (!$object instanceof $baseClass) {
          continue;
        }
        if ($object->getExtraDataInputUrl(self::CONFIG_PROBE_ID) === FALSE) {
          $ids[] = (int) $dao->id;
        }
      }
      catch (Throwable $e) {
        // Assume configuration IS required, which only ever flags a row that also
        // has empty params - the conservative direction.
      }
    }
    Civi::cache('metadata')->set($cacheKey, $ids);
    return $ids;
  }

  public static function flushCache(): void {
    Civi::cache('metadata')->delete(self::CACHE_PREFIX . 'civirule_action');
    Civi::cache('metadata')->delete(self::CACHE_PREFIX . 'civirule_condition');
  }

  /**
   * @param array $ruleAction A civirule_rule_action row; needs action_params and action_id.
   */
  public static function isRuleActionConfigured(array $ruleAction): bool {
    if (($ruleAction['action_params'] ?? NULL) !== NULL) {
      return TRUE;
    }
    return in_array((int) ($ruleAction['action_id'] ?? 0), self::getActionIdsNotRequiringConfiguration(), TRUE);
  }

  /**
   * @param array $ruleCondition A civirule_rule_condition row; needs condition_params and condition_id.
   */
  public static function isRuleConditionConfigured(array $ruleCondition): bool {
    if (($ruleCondition['condition_params'] ?? NULL) !== NULL) {
      return TRUE;
    }
    return in_array((int) ($ruleCondition['condition_id'] ?? 0), self::getConditionIdsNotRequiringConfiguration(), TRUE);
  }

  /**
   * @see \Civi\Api4\Service\Spec\FieldSpec::setSqlRenderer()
   */
  public static function renderActionIsConfigured(array $field): string {
    return self::renderIsConfigured($field, 'action_id', self::getActionIdsNotRequiringConfiguration());
  }

  public static function renderConditionIsConfigured(array $field): string {
    return self::renderIsConfigured($field, 'condition_id', self::getConditionIdsNotRequiringConfiguration());
  }

  private static function renderIsConfigured(array $field, string $fkColumn, array $noConfigIds): string {
    // sql_name is the qualified params column, e.g. `a`.`action_params`; the FK we
    // need to test lives on the same table alias.
    $alias = substr($field['sql_name'], 0, strrpos($field['sql_name'], '.') + 1);
    $fk = $alias . '`' . $fkColumn . '`';
    $list = $noConfigIds ? implode(',', array_map('intval', $noConfigIds)) : '0';
    return "IF({$field['sql_name']} IS NOT NULL OR COALESCE({$fk}, 0) IN ({$list}), 1, 0)";
  }

}
