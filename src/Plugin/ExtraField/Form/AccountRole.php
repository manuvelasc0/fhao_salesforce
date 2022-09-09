<?php

namespace Drupal\fhao_salesforce\Plugin\ExtraField\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\extra_field\Plugin\ExtraFieldFormBase;

/**
 * Salesforce Account Role field form display.
 *
 * @ExtraFieldForm(
 *   id = "fhao_account_role",
 *   label = @Translation("Salesforce Account Role"),
 *   description = @Translation("Account Role Salesforce Contact value"),
 *   bundles = {
 *     "user.user"
 *   },
 *   visible = true
 * )
 */
class AccountRole extends ExtraFieldFormBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(array &$form, FormStateInterface $form_state) {

    $element['sf_account_role'] = [
      '#type' => 'select',
      '#title' => $this->t('I am a'),
      '#default_value' => NULL,
      '#empty_option' => $this->t('- None -'),
      '#options' => [],
      '#required' => TRUE,
      '#description' => t('Please select one that best reflects your relationship with Facing History.'),
    ];

    $roles = \Drupal::currentUser()->getRoles();
    if (in_array('developer', $roles, TRUE)) {
      $element['sf_account_role']['#required'] = FALSE;
      $element['sf_account_role']['#description'] = $this->t("Normally this field is required, but it's optional for developers to enable testing with an empty Account role.");
    }

    return $element;
  }

}
