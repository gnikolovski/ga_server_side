<?php

/**
 * @file
 * Provides Views integration for GA Server Side event logs.
 */

/**
 * Implements hook_views_data().
 */
function ga_server_side_views_data() {
  $data = [];

  $data['ga_server_side_event_log']['table']['group'] = t('GA4 Event Logs');

  $data['ga_server_side_event_log']['table']['base'] = [
    'field' => 'id',
    'title' => t('GA4 Event Logs'),
    'help' => t('Contains logs of Google Analytics 4 events sent from the server side.'),
    'weight' => -10,
  ];

  // ID field.
  $data['ga_server_side_event_log']['id'] = [
    'title' => t('Log ID'),
    'help' => t('The unique ID of the event log entry.'),
    'field' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
  ];

  // Event name field.
  $data['ga_server_side_event_log']['event_name'] = [
    'title' => t('Event Name'),
    'help' => t('The name of the GA4 event (e.g., view_item, add_to_cart).'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  // Event data field.
  $data['ga_server_side_event_log']['event_data'] = [
    'title' => t('Event Data'),
    'help' => t('JSON encoded event data sent to GA4.'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
  ];

  // Client ID field.
  $data['ga_server_side_event_log']['client_id'] = [
    'title' => t('Client ID'),
    'help' => t('GA4 client ID from _ga cookie.'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  // Session ID field.
  $data['ga_server_side_event_log']['session_id'] = [
    'title' => t('Session ID'),
    'help' => t('GA4 session ID from _ga_{measurement_id} cookie.'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  // User ID field.
  $data['ga_server_side_event_log']['user_id'] = [
    'title' => t('User ID'),
    'help' => t('Drupal user ID if user is logged in.'),
    'field' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'relationship' => [
      'title' => t('User'),
      'help' => t('The user who triggered this event.'),
      'base' => 'users_field_data',
      'base field' => 'uid',
      'id' => 'standard',
      'label' => t('User'),
    ],
  ];

  // HTTP status code field.
  $data['ga_server_side_event_log']['http_status_code'] = [
    'title' => t('HTTP Status Code'),
    'help' => t('HTTP status code from GA4 API response.'),
    'field' => [
      'id' => 'numeric',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
  ];

  // Response body field.
  $data['ga_server_side_event_log']['response_body'] = [
    'title' => t('Response Body'),
    'help' => t('Response body from GA4 API.'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
  ];

  // Error message field.
  $data['ga_server_side_event_log']['error_message'] = [
    'title' => t('Error Message'),
    'help' => t('Error message if event push failed.'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
  ];

  // Log type field.
  $data['ga_server_side_event_log']['log_type'] = [
    'title' => t('Log Type'),
    'help' => t('Type of log entry: event, cookie_parse, validation_error.'),
    'field' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  // Timestamp field.
  $data['ga_server_side_event_log']['timestamp'] = [
    'title' => t('Timestamp'),
    'help' => t('Unix timestamp when the event was logged.'),
    'field' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
  ];

  return $data;
}
