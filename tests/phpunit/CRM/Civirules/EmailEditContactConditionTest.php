<?php

use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Api4\ContactType;
use Civi\Api4\Email;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\UFMatch;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;

/**
 * Tests "Email-address is changed" rules with contact conditions.
 *
 * CiviCRM saves some emails with only id and email, for example when a CMS
 * user's email is synced (CRM_Core_BAO_UFMatch::updateContactEmail()). The
 * post data then has no contact_id, so contact conditions must get the
 * contact from the data captured before the edit.
 *
 * Not transactional on purpose. A post trigger only runs once the outermost
 * transaction commits, and TransactionalInterface holds one open for the whole
 * test and rolls it back, so no rule would ever run. tearDown() removes what
 * the test created instead.
 *
 * @group headless
 */
class CRM_Civirules_EmailEditContactConditionTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface {

  /**
   * Subtype name, unique per run so a crashed run cannot block the next one.
   *
   * @var string
   */
  private $subtype;

  /**
   * @var int[]
   */
  private $ruleIds = [];

  /**
   * @var int[]
   */
  private $contactIds = [];

  /**
   * @var int[]
   */
  private $groupIds = [];

  /**
   * Collects what CiviRules logs, so a failing rule is visible.
   *
   * @var \Psr\Log\AbstractLogger
   */
  private $logger;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    $this->logger = new class() extends \Psr\Log\AbstractLogger {

      public $records = [];

      public function log($level, $message, array $context = []): void {
        $this->records[] = [$level, $message, $context];
      }

    };
    $this->setRulesLogger($this->logger);

    $this->subtype = 'CivirulesEmailEditTest' . uniqid();
    ContactType::create(FALSE)
      ->setValues([
        'name' => $this->subtype,
        'label' => $this->subtype,
        'parent_id:name' => 'Individual',
        'is_active' => TRUE,
      ])
      ->execute();
  }

  public function tearDown(): void {
    foreach ($this->ruleIds as $ruleId) {
      $params = [1 => [$ruleId, 'Integer']];
      CRM_Core_DAO::executeQuery('DELETE FROM civirule_rule_log WHERE rule_id = %1', $params);
      CRM_Core_DAO::executeQuery('DELETE FROM civirule_rule_condition WHERE rule_id = %1', $params);
      CRM_Core_DAO::executeQuery('DELETE FROM civirule_rule WHERE id = %1', $params);
    }
    if ($this->contactIds) {
      UFMatch::delete(FALSE)->addWhere('contact_id', 'IN', $this->contactIds)->execute();
      Contact::delete(FALSE)->setUseTrash(FALSE)->addWhere('id', 'IN', $this->contactIds)->execute();
    }
    if ($this->groupIds) {
      Group::delete(FALSE)->addWhere('id', 'IN', $this->groupIds)->execute();
    }
    ContactType::delete(FALSE)->addWhere('name', '=', $this->subtype)->execute();
    $this->setRulesLogger(NULL);
    parent::tearDown();
  }

  /**
   * The CMS email sync shape: only id and email are passed.
   */
  public function testPartialEmailSaveRunsSubtypeRuleForTheContact(): void {
    $ruleId = $this->createSubtypeRule();
    [$contactId, $emailId] = $this->createContactWithEmail('Subtyped', [$this->subtype]);

    civicrm_api3('Email', 'create', ['id' => $emailId, 'email' => 'subtyped.changed@example.org']);

    $this->assertSame([], $this->loggedErrors(), 'The rule must not fail on a save without contact_id.');
    $this->assertSame([$this->logRow($contactId, $emailId)], $this->ruleLog($ruleId));
  }

  public function testFullEmailSaveStillRunsSubtypeRule(): void {
    $ruleId = $this->createSubtypeRule();
    [$contactId, $emailId] = $this->createContactWithEmail('FullParams', [$this->subtype]);

    civicrm_api3('Email', 'create', [
      'id' => $emailId,
      'contact_id' => $contactId,
      'email' => 'fullparams.changed@example.org',
    ]);

    $this->assertSame([], $this->loggedErrors());
    $this->assertSame([$this->logRow($contactId, $emailId)], $this->ruleLog($ruleId));
  }

  public function testPartialEmailSaveSkipsContactWithoutTheSubtype(): void {
    $ruleId = $this->createSubtypeRule();
    [, $emailId] = $this->createContactWithEmail('NoSubtype', []);

    civicrm_api3('Email', 'create', ['id' => $emailId, 'email' => 'nosubtype.changed@example.org']);

    $this->assertSame([], $this->loggedErrors(), 'The rule must not fail on a save without contact_id.');
    $this->assertSame([], $this->ruleLog($ruleId), 'The condition must not match a contact without the subtype.');
  }

  /**
   * A group condition got no contact either, and so silently never matched.
   */
  public function testPartialEmailSaveRunsGroupRuleForTheContact(): void {
    $groupId = Group::create(FALSE)
      ->setValues(['title' => $this->subtype, 'name' => $this->subtype])
      ->execute()->first()['id'];
    $this->groupIds[] = $groupId;
    $ruleId = $this->createRule('contact_in_group', [
      'operator' => 'in one of',
      'group_ids' => [$groupId],
    ]);
    [$contactId, $emailId] = $this->createContactWithEmail('InGroup', []);
    GroupContact::create(FALSE)
      ->setValues(['group_id' => $groupId, 'contact_id' => $contactId, 'status' => 'Added'])
      ->execute();

    civicrm_api3('Email', 'create', ['id' => $emailId, 'email' => 'ingroup.changed@example.org']);

    $this->assertSame([], $this->loggedErrors());
    $this->assertSame([$this->logRow($contactId, $emailId)], $this->ruleLog($ruleId));
  }

  /**
   * The real CMS sync call, which saves the email with only id and email.
   */
  public function testCmsEmailSyncRunsSubtypeRuleForTheContact(): void {
    $ruleId = $this->createSubtypeRule();
    [$contactId, $emailId] = $this->createContactWithEmail('CmsSync', [$this->subtype]);
    UFMatch::create(FALSE)
      ->setValues([
        'uf_id' => 9000 + $contactId,
        'uf_name' => 'cmssync.' . uniqid() . '@example.org',
        'contact_id' => $contactId,
        'domain_id' => CRM_Core_Config::domainID(),
      ])
      ->execute();

    CRM_Core_BAO_UFMatch::updateContactEmail($contactId, 'cmssync.changed@example.org');

    $this->assertSame('cmssync.changed@example.org', Email::get(FALSE)
      ->addWhere('id', '=', $emailId)
      ->execute()->single()['email'], 'The sync must have saved the email.');
    $this->assertSame([], $this->loggedErrors(), 'The rule must not fail on the CMS email sync.');
    $this->assertSame([$this->logRow($contactId, $emailId)], $this->ruleLog($ruleId));
  }

  /**
   * The data from before the edit is kept for the rest of the process.
   *
   * After the email moved to another contact, a later save with only id and
   * email must still run the rule for the new owner, not the old one.
   */
  public function testPartialEmailSaveAfterOwnerChangeRunsRuleForNewOwner(): void {
    $ruleId = $this->createSubtypeRule();
    [, $emailId] = $this->createContactWithEmail('FormerOwner', [$this->subtype]);
    [$newOwnerId] = $this->createContactWithEmail('NewOwner', [$this->subtype]);

    civicrm_api3('Email', 'create', [
      'id' => $emailId,
      'contact_id' => $newOwnerId,
      'email' => 'moved@example.org',
    ]);
    civicrm_api3('Email', 'create', ['id' => $emailId, 'email' => 'moved.changed@example.org']);

    $this->assertSame([], $this->loggedErrors());
    $this->assertSame([
      $this->logRow($newOwnerId, $emailId),
      $this->logRow($newOwnerId, $emailId),
    ], $this->ruleLog($ruleId));
  }

  /**
   * An "Email-address is deleted" rule gets the contact the email belonged to.
   */
  public function testEmailDeleteRunsSubtypeRuleForTheOwner(): void {
    $ruleId = $this->createSubtypeRule('deleted_emailaddress');
    [$contactId, $emailId] = $this->createContactWithEmail('DeleteSubtyped', [$this->subtype]);

    civicrm_api3('Email', 'delete', ['id' => $emailId]);

    $this->assertSame([], $this->loggedErrors());
    $this->assertSame([$this->logRow($contactId, $emailId)], $this->ruleLog($ruleId));
  }

  /**
   * The subtype condition still applies to the owner of a deleted email.
   */
  public function testEmailDeleteSkipsContactWithoutTheSubtype(): void {
    $ruleId = $this->createSubtypeRule('deleted_emailaddress');
    [, $emailId] = $this->createContactWithEmail('DeleteNoSubtype', []);

    civicrm_api3('Email', 'delete', ['id' => $emailId]);

    $this->assertSame([], $this->loggedErrors());
    $this->assertSame([], $this->ruleLog($ruleId), 'The condition must not match a contact without the subtype.');
  }

  /**
   * Creates an active email rule with a subtype condition.
   */
  private function createSubtypeRule(string $triggerName = 'changed_emailaddress'): int {
    return $this->createRule('contact_has_subtype', [
      'operator' => 'in one of',
      'subtype_names' => [$this->subtype],
    ], $triggerName);
  }

  /**
   * Creates an active email rule with one condition.
   */
  private function createRule(string $conditionName, array $conditionParams, string $triggerName = 'changed_emailaddress'): int {
    $triggerId = CiviRulesTrigger::get(FALSE)
      ->addWhere('name', '=', $triggerName)
      ->execute()->single()['id'];
    $conditionId = CiviRulesCondition::get(FALSE)
      ->addWhere('name', '=', $conditionName)
      ->execute()->single()['id'];

    $ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'name' => 'phpunit_' . $triggerName . '_' . $conditionName,
        'label' => 'PHPUnit ' . $triggerName . ' ' . $conditionName,
        'trigger_id' => $triggerId,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
    $this->ruleIds[] = $ruleId;

    CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_condition (rule_id, condition_id, condition_params, is_active) VALUES (%1, %2, %3, 1)',
      [
        1 => [$ruleId, 'Integer'],
        2 => [$conditionId, 'Integer'],
        3 => [serialize($conditionParams), 'String'],
      ]
    );
    return $ruleId;
  }

  /**
   * @return int[]
   *   The contact id and the id of its primary email.
   */
  private function createContactWithEmail(string $firstName, array $subtypes): array {
    $values = [
      'contact_type' => 'Individual',
      'first_name' => $firstName,
      'last_name' => 'EmailEditTest',
    ];
    if ($subtypes) {
      $values['contact_sub_type'] = $subtypes;
    }
    $contactId = Contact::create(FALSE)->setValues($values)->execute()->first()['id'];
    $this->contactIds[] = $contactId;

    $emailId = Email::create(FALSE)
      ->setValues([
        'contact_id' => $contactId,
        'email' => strtolower($firstName) . '@example.org',
        'is_primary' => TRUE,
        'location_type_id:name' => 'Home',
      ])
      ->execute()->first()['id'];

    return [$contactId, $emailId];
  }

  private function logRow(int $contactId, int $emailId): array {
    return ['contact_id' => $contactId, 'entity_table' => 'civicrm_email', 'entity_id' => $emailId];
  }

  private function ruleLog(int $ruleId): array {
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT contact_id, entity_table, entity_id FROM civirule_rule_log WHERE rule_id = %1 ORDER BY id',
      [1 => [$ruleId, 'Integer']]
    );
    $rows = [];
    while ($dao->fetch()) {
      $rows[] = [
        'contact_id' => $dao->contact_id === NULL ? NULL : (int) $dao->contact_id,
        'entity_table' => $dao->entity_table,
        'entity_id' => $dao->entity_id === NULL ? NULL : (int) $dao->entity_id,
      ];
    }
    return $rows;
  }

  /**
   * Errors CiviRules logged, with the underlying exception message.
   *
   * @return string[]
   */
  private function loggedErrors(): array {
    $errors = [];
    foreach ($this->logger->records as [$level, $message, $context]) {
      if (in_array($level, ['emergency', 'alert', 'critical', 'error'], TRUE)) {
        $errors[] = $message . ' | reason: ' . ($context['reason'] ?? '')
          . ' | ' . ($context['exception_message'] ?? '');
      }
    }
    return $errors;
  }

  /**
   * Points the CiviRules logger at $logger, or resets it when NULL.
   */
  private function setRulesLogger(?\Psr\Log\LoggerInterface $logger): void {
    $class = new ReflectionClass(CRM_Civirules_Utils_LoggerFactory::class);
    $class->getProperty('logger')->setValue(NULL, $logger);
    $class->getProperty('loggerHookInvoked')->setValue(NULL, $logger !== NULL);
  }

}
