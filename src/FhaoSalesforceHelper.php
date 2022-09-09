<?php

namespace Drupal\fhao_salesforce;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\DateTime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\salesforce\Rest\RestClient;
use Drupal\salesforce\Rest\RestException;
use Drupal\salesforce\SelectQuery;
use Drupal\salesforce\SelectQueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides helpful helper methods for fhao_salesforce.
 */
class FhaoSalesforceHelper extends ServiceProviderBase {

  // Salesforce Basic Object info.
  const SF_CONTACT_OBJECT_NAME = 'Contact';
  const SF_ACCOUNT_OBJECT_NAME = 'Account';
  const SF_CAMPAIGN_MEMBER_OBJECT_NAME = 'CampaignMember';
  const SF_OPTION_TYPE = 'picklist-values';
  const SF_ID_FIELDNAME = 'field_salesforce_contact_id';

  /**
   * Array of Salesforce Contact "Role" values for an educator.
   */
  const EDUCATOR_ROLES = [
    'Teacher', 'School or District Leader', 'Other Educator',
  ];

  /**
   * Salesforce Client object.
   *
   * @var \Drupal\salesforce\Rest\RestClient
   */
  protected $salesforce;

  /**
   * The Logger Channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannel
   */
  protected $logger;

  /**
   * The Cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The datetime.time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $timeService;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an FhaoSalesforceHelper.
   *
   * @param \Drupal\salesforce\Rest\RestClient $salesforce
   *   The RestClient implementation to use.
   * @param \Drupal\Core\Logger\LoggerChannel $logger
   *   The LoggerChannelFactoryInterface implementation to use.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The CacheBackendInterface implementation to use.
   * @param \Drupal\Component\Datetime\TimeInterface $time_service
   *   The datetime.time service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(RestClient $salesforce, LoggerChannel $logger, CacheBackendInterface $cache, TimeInterface $time_service, EntityTypeManagerInterface $entity_type_manager) {
    $this->salesforce = $salesforce;
    $this->logger = $logger;
    $this->cache = $cache;
    $this->timeService = $time_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('salesforce.client'),
      $container->get('logger.channel.default'),
      $container->get('cache.default'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Return Salesforce Contact Record.
   *
   * @param string $contact_id
   *   Salesforce Contact ID.
   * @param bool $log_error
   *   Set TRUE (default) to log any error to logger channel.
   *
   * @return \Drupal\salesforce\SObject|int
   */
  public function getContact(string $contact_id, $log_error = TRUE) {
    try {
      $contact = $this->salesforce->objectRead(static::SF_CONTACT_OBJECT_NAME, $contact_id);
      $this->debugLog(__FUNCTION__, $contact);
      return $contact;
    }
    catch (RestException $e) {
      // Generic exception handling if something else gets thrown.
      if ($log_error) {
        $this->logger->error($e->getMessage());
      }
      $code = $e->getCode();
      return $code;
    }
  }

  /**
   * Retrieve Salesforce Contact Record using the user's email.
   *
   * @param string $email
   *   Email to use in query.
   *
   * @return \Drupal\salesforce\SObject|bool
   *   Return Salesforce SObject if only one record exists,
   *   otherwise return FALSE.
   */
  public function getContactByEmail(string $email) {
    try {
      $email = $this->sanitizeSoql($email);
      $query = new SelectQuery(static::SF_CONTACT_OBJECT_NAME);
      $query->fields = ['Id'];
      $query->conditions[] = [
        "(Work_Email__c", '=', "'$email'",
        'OR', 'Personal_Email__c', '=', "'$email'",
        'OR', 'Alternate_Email__c', '=', "'$email')",
      ];

      $result = $this->salesforce->query($query);
      // Check if query is done and only one record was returned.
      if ($result->done()) {
        if ($result->size() === 1) {
          $records = $result->records();
          $contact = array_pop($records);
          $this->debugLog(__FUNCTION__, $contact);
          return $contact;
        }
        if ($result->size() > 1) {
          $this->logger->error('Only one Salesforce contact expected for email but found several for @email.', ['@email' => $email]);
        }
      }
    }
    catch (RestException $e) {
      // Generic exception handling if something else gets thrown.
      $this->logger->error($e->getMessage());
    }
    return FALSE;
  }

  /**
   * Log data to Watchdog on non-live environments.
   *
   * @param string $method
   * @param mixed $data
   */
  private function debugLog(string $method, $data) {
    if (Settings::get('server_environment') !== 'live') {
      $this->logger->debug('@class=>@method : @data', [
        '@class' => 'FhaoSalesforceHelper',
        '@method' => $method,
        '@data' => var_export($data, TRUE),
      ]);
      // Optional extra logging.
      // Set this in settings.local.php.
      if (Settings::get('salesforce_extra_debugging', FALSE)) {
        \Drupal::service('devel.dumper')->message($data, $method);
      }
    }
  }

  /**
   * Return Salesforce Account Record.
   *
   * @param string $account_id
   *   Salesforce Account ID.
   *
   * @return \Drupal\salesforce\SObject|void
   */
  public function getAccount(string $account_id) {
    try {
      $account = $this->salesforce->objectRead(static::SF_ACCOUNT_OBJECT_NAME, $account_id);
      $this->debugLog(__FUNCTION__, $account);
      return $account;
    }
    catch (RestException $e) {
      // Generic exception handling if something else gets thrown.
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * Get the value options from a salesforce picklist field.
   *
   * @param string $sf_field_name
   *   Name of Salesforce field.
   *
   * @return array
   *   Array of options.
   */
  public function getFieldOptions($sf_field_name): array {
    $cid = 'fhao_salesforce:' . static::SF_CONTACT_OBJECT_NAME . ':' . $sf_field_name;

    if ($cache = $this->cache->get($cid)) {
      $options = $cache->data;
    }
    else {
      $options = [];
      try {
        $contact_object = $this->salesforce->apiCall("ui-api/object-info/" . static::SF_CONTACT_OBJECT_NAME);
        $default_record_type_id = $contact_object["defaultRecordTypeId"];
        if ($default_record_type_id != NULL) {
          $field = $this->salesforce->apiCall("ui-api/object-info/" . static::SF_CONTACT_OBJECT_NAME . "/" . static::SF_OPTION_TYPE . "/" . $default_record_type_id . "/" . $sf_field_name);
          $this->debugLog(__FUNCTION__, $field);
          foreach ($field["values"] as $item) {
            $options[$item["value"]] = $item["label"];
          }
          // Cache results for 10 minutes.
          $this->cache->set($cid, $options, $this->timeService->getRequestTime() + 600);
        }
      }
      catch (RestException $e) {
        $this->logger->error($e->getMessage());
      }
    }

    return $options;
  }

  /**
   * Update a Contact's field values.
   *
   * @param string $contact_id
   *   Field value from user object.
   * @param array $params
   *   Associative array of field->value pairs to update.
   *
   * @return bool
   *   TRUE if object was updated. FALSE if there was an error.
   */
  public function updateContact(string $contact_id, array $params): bool {
    try {
      $this->salesforce->objectUpdate(static::SF_CONTACT_OBJECT_NAME, $contact_id, $params);
      return TRUE;
    }
    catch (RestException $e) {
      // Generic exception handling if something else gets thrown.
      $this->logger->error($e->getMessage());
      return FALSE;
    }
  }

  /**
   * Create a Contact.
   *
   * @param array $params
   *   Associative array of field->value pairs to create the contact.
   *
   * @return \Drupal\salesforce\SFID|bool
   *   Return SFID if contact creation is successful, otherwise FALSE.
   */
  public function createContact(array $params) {
    try {
      $contact_id = $this->salesforce->objectCreate(static::SF_CONTACT_OBJECT_NAME, $params);
      return $contact_id;
    }
    catch (RestException $e) {
      // Generic exception handling if something else gets thrown.
      $this->logger->error($e->getMessage());
      return FALSE;
    }
  }

  /**
   * Query Salesforce with SOQL.
   *
   * @param Drupal\salesforce\SelectQueryInterface $query
   *   A SelectQuery object with the SOQL query.
   *
   * @return Drupal\salesforce\SelectQueryResult
   */
  public function query(SelectQueryInterface $query) {
    return $this->salesforce->query($query);
  }

  /**
   * Query Salesforce to get contact campaigns.
   *
   * @param string $contact_id
   *   Salesforce contact id.
   *
   * @return array
   */
  public function getContactCampaigns(string $contact_id) {
    try {
      $query = new SelectQuery(static::SF_CAMPAIGN_MEMBER_OBJECT_NAME);
      $query->fields = [
        'Id',
        'FirstRespondedDate',
        'CampaignId',
        'Campaign.Name',
      ];
      $query->conditions[] = [
        "ContactId", '=', "'$contact_id'",
        "AND", "Campaign_Type__c", '=', "'Event'",
        "ORDER BY FirstRespondedDate DESC",
      ];
      $result = $this->query($query);
      $records = $result->records() ?? [];
      $this->debugLog(__FUNCTION__, $records);
      return $records;
    }
    catch (RestException $e) {
      // Generic exception handling if something else gets thrown.
      $this->logger->error($e->getMessage());
      return [];
    }
  }

  /**
   * Creates a MappedObject record linking an entity to a Salesforce Object.
   *
   * This is typically necessary if you programatically create Salesforce
   * objects and want automatic SF->entity updates to work.
   *
   * @param string $mapping_id
   *   The machine name of the Salesforce mapping.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The Drupal entity.
   * @param string $salesforce_id
   *   The ID of the Salesforce Object.
   */
  public function createMappedObject($mapping_id, EntityInterface $entity, $salesforce_id) {
    $mapped_object = $this->entityTypeManager->getStorage('salesforce_mapped_object')->create([
      'drupal_entity' => [
        'target_type' => $entity->getEntityTypeId(),
      ],
      'salesforce_mapping' => $mapping_id,
      'salesforce_id' => $salesforce_id,
    ]);
    $mapped_object->setDrupalEntity($entity)->save();
  }

  /**
   * Query Salesforce to get upcoming contact campaigns.
   *
   * @param string $contact_id
   *   Salesforce contact id.
   * @param string $date
   *   Date in format "YYYY-MM-DD";.
   *
   * @return array
   */
  public function getUpcomingContactCampaigns(string $contact_id, string $date) {
    try {
      $query = new SelectQuery(static::SF_CAMPAIGN_MEMBER_OBJECT_NAME);
      $query->fields = [
        'Id',
        'CampaignId',
        'Campaign.Name',
        'Campaign.StartDate',
        'Campaign.Status',
      ];
      $query->conditions[] = [
        "ContactId", '=', "'$contact_id'",
        "AND", "Campaign_Type__c", '=', "'Event'",
        "AND", "Campaign.StartDate", '>', $date,
        "ORDER BY Campaign.StartDate ASC",
      ];
      $result = $this->query($query);
      $records = $result->records() ?? [];
      $this->debugLog(__FUNCTION__, $records);
      return $records;
    }
    catch (RestException $e) {
      // Generic exception handling if something else gets thrown.
      $this->logger->error($e->getMessage());
      return [];
    }
  }

  /**
   * Escapes special characters in user-input used in Salesforce SOQL queries.
   *
   * @param string $value
   *   Text to sanitize.
   *
   * @return string
   *   The escaped string.
   */
  public function sanitizeSoql($value) {
    $value = str_replace("'", "\'", $value);
    $value = str_replace('"', '\"', $value);
    $value = str_replace('%', '\%', $value);
    // Replace one backslash with two.
    $value = str_replace('\\', '\\\\', $value);
    // @see https://www.drupal.org/project/salesforce/issues/3157335
    $value = str_replace('+', '%2B', $value);

    return $value;
  }

}
