<?php

namespace Drupal\fhao_salesforce;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\salesforce\Rest\RestClient;
use Drupal\salesforce\Rest\RestException;
use Drupal\salesforce\SelectQuery;
use Drupal\salesforce\SObject;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides helpful methods for fhao_salesforce email verifications.
 */
class FhaoSalesforceEmailVerification {


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
   * Class Constructor.
   */
  public function __construct(RestClient $salesforce, LoggerChannel $logger) {
    $this->salesforce = $salesforce;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
      // Load the service required to construct this class.
        $container->get('salesforce.client'),
        $container->get('logger.channel.default')
      );
  }

  /**
   * Email address verication.
   *
   * Verify if the email address is equal to preferred email address in sf.
   * If it is different, so the user is redirected to support page.
   *
   * @param \Drupal\salesforce\SObject $sf_object
   *   Salesforce object.
   * @param \Drupal\user\Entity\User $user
   *   Drupal user entity.
   * @param string $expected_email_address
   *   This will be set to the expected email address.  To use this, pass in an
   *   empty (but already initialized) variable.
   *
   * @return bool
   *   Return a bool if the email address are diferent or equal.
   */
  public function validateEmail(SObject $sf_object, User $user, string &$expected_email_address):bool {
    if ($sf_object->field('Preferred_Email__c') === 'Work') {
      $sf_preferred_email_address = 'Work_Email__c';
    }
    else {
      $sf_preferred_email_address = 'Personal_Email__c';
    }
    $expected_email_address = (string) $sf_object->field($sf_preferred_email_address);
    return strtolower($user->getEmail()) !== strtolower($expected_email_address);
  }

  /**
   * Email verification exist on salesforce.
   *
   * Verify if the new email address is being used by an existing user.
   *
   * @param \Drupal\user\Entity\User $user
   *   Drupal user entity.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Salesforce object.
   *
   * @return bool
   *   Return a bool if the new email address is being  used by another user.
   */
  public function emailAlreadyInUse(User $user, FormStateInterface $form_state) :bool {
    $query = new SelectQuery('Contact');
    $query->fields = ['Id', 'Name', 'Work_Email__c'];
    $email = $form_state->getValue('mail');
    $email = utf8_encode($email);
    $email = str_replace('+', '%2B', $email);
    $query->conditions[] = [
      "(Work_Email__c", '=', "'$email'",
      'OR', 'Personal_Email__c', '=', "'$email'",
      'OR', 'Alternate_Email__c', '=', "'$email')",
    ];

    $result = $this->salesforce->query($query);
    $response = FALSE;
    foreach ($result->records() as $record) {
      $field_salesforce_contact_id = $user->get('field_salesforce_contact_id')->getValue()[0]["value"];
      if ($record->field('Id') != $field_salesforce_contact_id) {
        $response = TRUE;
      }
    }
    return $response;
  }

  /**
   * Salesforce Email Validation.
   *
   * Update the new email in salesforce.
   *
   * * @param string $email_adress
   *   Salesforce object.
   * * @param string $old_email_adress
   *   Salesforce object.
   * * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Salesforce object.
   */
  public function updateEmail($email_adress, $old_email_adress, $sf_object) {

    $preferred_email_options = [
      'Work' => 'Work_Email__c',
      'Personal' => 'Personal_Email__c',
    ];
    $sf_preferred_email = $sf_object->field('Preferred_Email__c');
    $sf_alternate_email = $sf_object->field('Alternate_Email__c');
    $sf_work_email = $sf_object->field('Work_Email__c');
    $sf_personal_email = $sf_object->field('Personal_Email__c');

    $sf_preferred_email_address = $preferred_email_options[$sf_preferred_email];

    // If the new email is the same as the alternate,
    // clear the alternate so we don't save a duplicate email.
    if ($email_adress == $sf_alternate_email) {
      $sf_alternate_email = NULL;
    }

    // If the new email address is personal/work, and my old email is the other.
    if ($email_adress == $sf_work_email || $email_adress == $sf_personal_email) {
      if ($old_email_adress == $sf_work_email) {
        $sf_preferred_email = 'Personal';
        $sf_preferred_email_address = 'Personal_Email__c';
      }
      if ($old_email_adress == $sf_personal_email) {
        $sf_preferred_email = 'Work';
        $sf_preferred_email_address = 'Work_Email__c';
      }
    }

    // If the new email does not exit so record the new email in sf.
    $params = [
      $sf_preferred_email_address => $email_adress,
      'Alternate_Email__c' => $sf_alternate_email,
      'Preferred_Email__c' => $sf_preferred_email,
      'HasOptedOutOfEmail' => FALSE,
    ];

    try {
      $this->salesforce->objectUpdate('Contact', $sf_object->field('Id'), $params);
      return TRUE;
    }
    catch (RestException $e) {
      // Generic exception handling if something else gets thrown.
      $this->logger->error($e->getMessage());
      return FALSE;
    }
  }

}
