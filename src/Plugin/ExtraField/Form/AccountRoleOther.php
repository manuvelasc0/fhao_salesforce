<?php

namespace Drupal\fhao_salesforce\Plugin\ExtraField\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\extra_field\Plugin\ExtraFieldFormBase;

/**
 * Salesforce Other Account Role field form display.
 *
 * @ExtraFieldForm(
 *   id = "fhao_account_role_other",
 *   label = @Translation("Salesforce Account Role Other"),
 *   description = @Translation("Account Role Other Salesforce Contact value"),
 *   bundles = {
 *     "user.user"
 *   },
 *   visible = true
 * )
 */
class AccountRoleOther extends ExtraFieldFormBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(array &$form, FormStateInterface $form_state) {

    // Visible and required if account role field is set to Other.
    $elemenent['sf_account_role_other'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Other'),
      '#states' => [
        'visible' => [
          ':input[name="sf_account_role"]' => [
            'value' => 'Other',
          ],
        ],
        'required' => [
          ':input[name="sf_account_role"]' => [
            'value' => 'Other',
          ],
        ],
      ],
    ];

    return $elemenent;
  }

}
