<?php

namespace Drupal\ga_server_side;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines the PushService class.
 */
class PushService {

  const GA_BASE_URL = 'https://www.google-analytics.com';

  const GA_DEBUG_ENDPOINT = '/debug/mp/collect';

  const GA_PRODUCTION_ENDPOINT = '/mp/collect';

  const CLIENT_ID_KEY = 'ga4_client_id';

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  private AccountInterface $currentUser;

  /**
   * The config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The uuid service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuid;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a PushService object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The uuid service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    AccountInterface $current_user,
    ConfigFactoryInterface $config_factory,
    UuidInterface $uuid,
    Connection $database,
    RequestStack $request_stack,
  ) {
    $this->currentUser = $current_user;
    $this->config = $config_factory->get('ga_server_side.settings');
    $this->uuid = $uuid;
    $this->database = $database;
    $this->requestStack = $request_stack;
  }

  /**
   * Logs an event to the custom database table.
   *
   * @param string $log_type
   *   The type of log entry (event, cookie_parse, validation_error).
   * @param string $event_name
   *   The event name.
   * @param array|null $event_data
   *   The event data to log.
   * @param string|null $client_id
   *   The client ID.
   * @param string|null $session_id
   *   The session ID.
   * @param int|null $http_status_code
   *   HTTP status code from GA4 API response.
   * @param string|null $response_body
   *   Response body from GA4 API.
   * @param string|null $error_message
   *   Error message if any.
   */
  protected function logToDatabase(
    string $log_type,
    string $event_name,
    ?array $event_data = NULL,
    ?string $client_id = NULL,
    ?string $session_id = NULL,
    ?int $http_status_code = NULL,
    ?string $response_body = NULL,
    ?string $error_message = NULL
  ): void {
    try {
      $this->database->insert('ga_server_side_event_log')
        ->fields([
          'event_name' => $event_name,
          'event_data' => $event_data ? json_encode($event_data) : NULL,
          'client_id' => $client_id,
          'session_id' => $session_id,
          'user_id' => $this->currentUser->isAuthenticated() ? $this->currentUser->id() : 0,
          'http_status_code' => $http_status_code,
          'response_body' => $response_body,
          'error_message' => $error_message,
          'log_type' => $log_type,
          'timestamp' => time(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      // Silently fail to avoid breaking the main functionality.
      // We could potentially log this to watchdog, but since we're trying
      // to replace watchdog, we'll just fail silently.
    }
  }

  /**
   * Gets or generates the client ID.
   *
   * @return string
   *   The client ID.
   */
  protected function generateClientId(): string {
    $request = $this->requestStack->getCurrentRequest();

    if ($request->cookies->has(self::CLIENT_ID_KEY)) {
      $client_id = $request->cookies->get(self::CLIENT_ID_KEY);
    }
    elseif (isset($_SESSION[self::CLIENT_ID_KEY])) {
      $client_id = $_SESSION[self::CLIENT_ID_KEY];
    }
    else {
      // No client_id found in cookie or session so we need to create it.
      $client_id = $this->uuid->generate();
      // Store client_id in both cookie and a session that expires in 1 year.
      setcookie(self::CLIENT_ID_KEY, $client_id, time() + 60 * 60 * 24 * 365, '/');
      $_SESSION[self::CLIENT_ID_KEY] = $client_id;
    }

    return $client_id;
  }

  /**
   * Gets the client ID.
   *
   * @param string $event_name
   *   The event name.
   *
   * @return string
   *   The client ID.
   */
  protected function getClientId(string $event_name): string {
    $request = $this->requestStack->getCurrentRequest();

    if ($request->cookies->has('_ga') === FALSE) {
      $client_id = $this->generateClientId();
      if ($this->config->get('log_client_and_session_id') === TRUE) {
        $this->logToDatabase(
          'cookie_parse',
          $event_name,
          NULL,
          $client_id,
          NULL,
          NULL,
          NULL,
          'Cookie _ga not found while sending ' . $event_name . '. Falling back to a randomly generated client ID: ' . $client_id
        );
      }
      return $client_id;
    }

    $cookie_value = $request->cookies->get('_ga');
    $parts = explode('.', $cookie_value);
    if (count($parts) !== 4) {
      $client_id = $this->generateClientId();
      if ($this->config->get('log_client_and_session_id') === TRUE) {
        $this->logToDatabase(
          'cookie_parse',
          $event_name,
          NULL,
          $client_id,
          NULL,
          NULL,
          NULL,
          'Cookie _ga found while sending ' . $event_name . ', but it is malformed. Falling back to a randomly generated client ID: ' . $client_id
        );
      }
      return $client_id;
    }

    $client_id = $parts[2] . '.' . $parts[3];
    if ($this->config->get('log_client_and_session_id') === TRUE) {
      $this->logToDatabase(
        'cookie_parse',
        $event_name,
        NULL,
        $client_id,
        NULL,
        NULL,
        NULL,
        'Cookie _ga found while sending ' . $event_name . ', client ID: ' . $client_id
      );
    }

    return $client_id;
  }

  /**
   * Gets the session ID.
   *
   * @param string $event_name
   *   The event name.
   *
   * @return string|null
   *   The session ID.
   */
  protected function getSessionId(string $event_name): ?string {
    $request = $this->requestStack->getCurrentRequest();
    $measurement_id = str_replace('G-', '', $this->config->get('measurement_id'));
    $cookie_name = '_ga_' . $measurement_id;

    if ($request->cookies->has($cookie_name) === FALSE) {
      if ($this->config->get('log_client_and_session_id') === TRUE) {
        $this->logToDatabase(
          'cookie_parse',
          $event_name,
          NULL,
          NULL,
          NULL,
          NULL,
          NULL,
          'Cookie ' . $cookie_name . ' not found while sending ' . $event_name . '.'
        );
      }
      return NULL;
    }

    $cookie_value = $request->cookies->get($cookie_name);
    $parts = explode('.', $cookie_value);

    // Check if cookie format is valid (minimum 3 parts).
    if (count($parts) < 3) {
      if ($this->config->get('log_client_and_session_id') === TRUE) {
        $this->logToDatabase(
          'cookie_parse',
          $event_name,
          NULL,
          NULL,
          NULL,
          NULL,
          NULL,
          'Cookie ' . $cookie_name . ' found while sending ' . $event_name . ', but it is malformed.'
        );
      }
      return NULL;
    }

    $session_id = NULL;

    // Detect GS2 format (new format): GS2.1.s1746825440$o14$g0$t1746825440...
    if ($parts[0] === 'GS2') {
      // Parse GS2 format: key-value pairs separated by $.
      $data_string = $parts[2] ?? '';
      $pairs = explode('$', $data_string);
      foreach ($pairs as $pair) {
        // Session ID is prefixed with 's'.
        if (str_starts_with($pair, 's')) {
          $session_id = substr($pair, 1);
          break;
        }
      }
    }
    // Detect GS1 format (old format):
    // GS1.1.1746825440.14.0.1746825440.0.0.295082955.
    elseif ($parts[0] === 'GS1') {
      $session_id = $parts[2];
    }
    // Fallback: try position 2 for unknown formats.
    else {
      $session_id = $parts[2];
    }

    if (empty($session_id)) {
      if ($this->config->get('log_client_and_session_id') === TRUE) {
        $this->logToDatabase(
          'cookie_parse',
          $event_name,
          NULL,
          NULL,
          NULL,
          NULL,
          NULL,
          'Cookie ' . $cookie_name . ' found while sending ' . $event_name . ', but session ID could not be extracted.'
        );
      }
      return NULL;
    }

    if ($this->config->get('log_client_and_session_id') === TRUE) {
      $this->logToDatabase(
        'cookie_parse',
        $event_name,
        NULL,
        NULL,
        $session_id,
        NULL,
        NULL,
        'Cookie ' . $cookie_name . ' found while sending ' . $event_name . ', session ID: ' . $session_id . '.'
      );
    }

    return $session_id;
  }

  /**
   * Builds the endpoint URL.
   *
   * @return string
   *   The endpoint URL.
   */
  protected function buildEndpointUrl(): string {
    if ($this->config->get('debug_mode') === TRUE) {
      $url = self::GA_DEBUG_ENDPOINT;
    }
    else {
      $url = self::GA_PRODUCTION_ENDPOINT;
    }

    $measurement_id = $this->config->get('measurement_id');
    $api_secret = $this->config->get('api_secret');
    $url .= '?api_secret=' . $api_secret . '&measurement_id=' . $measurement_id;
    return $url;
  }

  /**
   * Pushes event data to Google Analytics.
   *
   * @param string $event_name
   *   The event name.
   * @param array $event_params
   *   The event data.
   */
  public function push(string $event_name, array $event_params): void {
    $client = new Client([
      'base_uri' => self::GA_BASE_URL,
      'timeout' => 5,
    ]);

    $session_id = $this->getSessionId($event_name);
    if (!empty($session_id)) {
      $event_params['session_id'] = $session_id;
    }

    $client_id = $this->getClientId($event_name);
    $data = [
      'client_id' => $client_id,
      'events' => [
        [
          'name' => $event_name,
          'params' => $event_params,
        ],
      ],
    ];

    if (
      $this->config->get('send_user_id') === TRUE &&
      $this->currentUser->isAuthenticated()
    ) {
      $data['user_id'] = $this->currentUser->id();
    }

    try {
      $url = $this->buildEndpointUrl();
      $response = $client->post($url, [
        'headers' => [
          'Content-Type' => 'application/json',
        ],
        'body' => json_encode($data),
      ]);

      if ($this->config->get('log_events') === TRUE) {
        $response_message = $response->getBody()->getContents();

        $this->logToDatabase(
          'event',
          $event_name,
          $event_params,
          $client_id,
          $session_id,
          $response->getStatusCode(),
          $response_message,
          NULL
        );
      }
    }
    catch (\Exception $e) {
      $this->logToDatabase(
        'event',
        $event_name,
        $event_params,
        $client_id ?? NULL,
        $session_id ?? NULL,
        NULL,
        NULL,
        'Error while sending data to Google Analytics: ' . $e->getMessage()
      );
    }
  }

}
