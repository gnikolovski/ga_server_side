<?php

namespace Drupal\ga_server_side\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the SettingsForm class.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ga_server_side_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ga_server_side.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ga_server_side.settings');

    $form['measurement_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Measurement ID'),
      '#default_value' => $config->get('measurement_id'),
      '#required' => TRUE,
    ];

    $form['api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API secret'),
      '#default_value' => $config->get('api_secret'),
      '#required' => TRUE,
    ];

    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug mode'),
      '#description' => $this->t('Test events against the Measurement Protocol validation server.'),
      '#default_value' => $config->get('debug_mode'),
    ];

    $form['log_events'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Detailed event logging'),
      '#description' => $this->t('Save a log message for each event sent to Google Analytics.'),
      '#default_value' => $config->get('log_events'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->cleanValues();

    $config = $this->config('ga_server_side.settings');
    $config->setData($form_state->getValues());
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
