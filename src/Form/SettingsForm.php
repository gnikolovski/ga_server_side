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

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Measurement Protocol'),
      '#default_value' => $config->get('enabled'),
    ];

    $form['measurement_protocol'] = [
      '#type' => 'details',
      '#title' => $this->t('Measurement Protocol (Google Analytics 4)'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['measurement_protocol']['measurement_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Measurement ID'),
      '#description' => $this->t('You can find your Measurement ID in the Admin section of Google Analytics, specifically under the Data Streams settings of your property.'),
      '#default_value' => $config->get('measurement_id'),
      '#required' => TRUE,
    ];

    $form['measurement_protocol']['api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API secret'),
      '#description' => $this->t('On the same Data Streams settings page where you found your Measurement ID, you will also see an option for Measurement Protocol API secrets.'),
      '#default_value' => $config->get('api_secret'),
      '#required' => TRUE,
    ];

    $form['tracking_snippet'] = [
      '#type' => 'details',
      '#title' => $this->t('Tracking snippet'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['tracking_snippet']['tracking_snippet_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('How is GTM tracking snippet added to the website?'),
      '#description' => $this->t("If you don't have the tracking snippet inserted user sessions won't be tracked."),
      '#default_value' => $config->get('tracking_snippet_type') ?? 'another_module',
      '#options' => [
        'another_module' => $this->t('By using another Drupal module'),
        'gtm' => $this->t('I want to insert Google Tag Manager snippet (you need GTM container ID for this)'),
      ],
    ];

    $form['tracking_snippet']['ignore_admin_pages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Ignore Admin pages'),
      '#default_value' => $config->get('ignore_admin_pages') ?? TRUE,
      '#states' => [
        '!visible' => [
          ':input[name="tracking_snippet_type"]' => ['value' => 'another_module'],
        ],
      ],
    ];

    $form['tracking_snippet']['disable_for_admins'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable for admins'),
      '#default_value' => $config->get('disable_for_admins') ?? TRUE,
      '#states' => [
        '!visible' => [
          ':input[name="tracking_snippet_type"]' => ['value' => 'another_module'],
        ],
      ],
    ];

    $form['tracking_snippet']['gtm_container_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GTM container ID'),
      '#default_value' => $config->get('gtm_container_id'),
      '#states' => [
        'visible' => [
          ':input[name="tracking_snippet_type"]' => ['value' => 'gtm'],
        ],
      ],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['advanced']['send_user_id'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send user ID'),
      '#description' => $this->t("Measure activity across platforms with User-ID. Find out more about this <a href='https://support.google.com/analytics/answer/9213390' target='_blank'>here</a>. This only works for logged-in users."),
      '#default_value' => $config->get('send_user_id') ?? FALSE,
    ];

    $form['advanced']['log_events'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Detailed event logging'),
      '#description' => $this->t('Save a log message for each event sent to Google Analytics.'),
      '#default_value' => $config->get('log_events') ?? FALSE,
    ];

    $form['advanced']['log_client_and_session_id'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Detailed client ID and session ID logging'),
      '#description' => $this->t('Save a log message for each attempt to retrieve the client ID and session ID from cookies.'),
      '#default_value' => $config->get('log_client_and_session_id') ?? FALSE,
    ];

    $form['advanced']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug mode'),
      '#description' => $this->t('Test events against the Measurement Protocol validation server.'),
      '#default_value' => $config->get('debug_mode') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Gets the event names.
   *
   * @return array
   *   An array of event names.
   */
  protected function getEventNames() {
    $events = [
      'view_item' => $this->t('View item'),
      'add_to_cart' => $this->t('Add to cart'),
      'remove_from_cart' => $this->t('Remove from cart'),
    ];

    if (defined('Drupal\commerce_checkout\Event\CheckoutEvents::INIT')) {
      $events['begin_checkout'] = $this->t('Begin checkout');
    }

    $events['purchase'] = $this->t('Purchase');

    return $events;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (
      $form_state->getValue('tracking_snippet_type') === 'gtm' &&
      empty($form_state->getValue('gtm_container_id'))
    ) {
      $form_state->setErrorByName('gtm_container_id', $this->t('Field GTM container ID cannot be empty.'));
    }
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
