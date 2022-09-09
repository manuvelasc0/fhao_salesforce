<?php

namespace Drupal\fhao_salesforce\Plugin\KeyProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\lockr\Plugin\KeyProvider\LockrKeyProvider;

use Drupal\key\KeyInterface;

/**
 * Adds lockr-based key provider that retrieves & decodes base 64 encoded keys.
 *
 * @KeyProvider(
 *   id = "lockr_base64_encoded",
 *   label = "Lockr Base 64 Encoded",
 *   description = @Translation("Extends the Lockr key provider to retrieve the key from Lockr and base 64 decode it."),
 *   storage_method = "lockr",
 *   key_value = {
 *     "accepted" = TRUE,
 *     "required" = TRUE
 *   }
 * )
 */
class LockrBase64EncodedKeyProvider extends LockrKeyProvider {

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key) {
    $key_value = parent::getKeyValue($key);
    return base64_decode($key_value);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['key_input_settings']['base64_encoded'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Base64-encoded'),
      '#description' => $this->t('Please ensure the key you are pasting is already Base64 encoded.'),
      '#default_value' => $this->getConfiguration()['key_input_settings']['base64_encoded'] ?? TRUE,
      '#disabled' => TRUE,
    ];

    return $form;
  }

}
