<?php

namespace Huncwot\UhoFramework;

use Google\Client as GoogleClient;
use Google\Service\Oauth2 as GoogleOauth2;

/**
 * Google authentication trait for _uho_client
 * Provides Google OAuth login functionality
 */

trait _uho_client_auth_google
{
  /**
   * Creates Google Client instance
   */
  private function loginGoogleClient()
  {
    $client = new GoogleClient();

    $client->setClientId($this->oAuth['google']['client_id']);
    $client->setClientSecret($this->oAuth['google']['client_secret']);
    $client->setRedirectUri($this->oAuth['google']['redirect_uri']);
    $client->setScopes([
      'openid',
      'email',
      'profile',
    ]);

    $client->setAccessType('offline');       // to receive refresh_token (first consent)
    $client->setPrompt('consent');           // force consent screen to get refresh_token reliably
    return $client;
  }

  /**
   * Google login init, redirects to login screen
   */

  public function loginGoogleInit($do_state = true)
  {
    $client = $this->loginGoogleClient();

    // CSRF protection: random state
    if ($do_state) {
      $state = bin2hex(random_bytes(16));
      $_SESSION['oauth2state'] = $state;
    }

    // Build auth URL
    $authUrl = $client->createAuthUrl();

    // Append state safely
    $sep = (parse_url($authUrl, PHP_URL_QUERY) ? '&' : '?');
    if ($do_state) $authUrl .= $sep . 'state=' . urlencode($state);

    header('Location: ' . $authUrl, true, 302);
    exit;
  }

  /**
   * Google login
   * @param string $access_token token from Google API
   * @param string $code authorization code from Google redirect
   * @param string $action = register|login|data
   * @return array returns result array with login status
   */

  public function loginGoogle($access_token, $code = null, $action = 'register', $do_state = true)
  {

    if (!$this->oAuth['google']) return ['result' => false, 'Google oAuth config missing'];

    // we can get data with token or code (from hard redirect)

    if (!$access_token && !$code) return ['result' => false, 'message' => 'No token/code specified'];

    $client = $this->loginGoogleClient();

    // Option#1 - getting data via TOKEN

    if ($access_token) {

      try {
        $data = $client->verifyIdToken($access_token);
      } catch (\Exception $e) {
        return ['result' => false, 'message' => 'Login with token failed'];
      }

      if ($do_state) {
        $state = _uho_fx::getGet('state');
        if (!$state || !isset($_SESSION['oauth2state']) || $state !== $_SESSION['oauth2state']) {
          return ['result' => false, 'message' => 'Invalid state parameter'];
        }
      }

      if ($data && $data['sub']) {
        // an unverified Google address proves nothing about e-mail ownership,
        // so it must not be trusted for matching an existing account
        $emailVerified = isset($data['email_verified'])
          && filter_var($data['email_verified'], FILTER_VALIDATE_BOOLEAN);

        $data = [
          'name' => $data['given_name'],
          'surname' => $data['family_name'],
          'email' => $data['email'],
          'google_id' => $data['sub'],
          'image_uri' => isset($data['picture']) ? $data['picture'] : "",
          'image_present' => isset($data['picture']) ? 1 : 0,
          'status' => 'confirmed'
        ];
      } else {
        return ['result' => false, 'message' => 'Login with token failed'];
      }
    }

    // Option#2 - getting data via CODE

    elseif ($code) {

      $token = $client->fetchAccessTokenWithAuthCode($code);

      if (isset($token['error'])) return [
        'result' => false,
        'message' => 'No token found: ' . $token['error']
      ];
      try {
        $client->setAccessToken($token);
      } catch (\Exception $e) {
        return ['result' => false, 'type' => 'code exchange to token', 'message' => $e->getMessage()];
      }

      $google_oauth = new GoogleOauth2($client);
      $google_account_info = $google_oauth->userinfo->get();

      if (!$google_account_info->id) return ['result' => false, 'message' => 'No google ID found'];

      $emailVerified = filter_var($google_account_info->getVerifiedEmail(), FILTER_VALIDATE_BOOLEAN);

      $data = [
        'name' => $google_account_info->given_name,
        'surname' => $google_account_info->family_name,
        'email' => $google_account_info->email,
        'google_id' => $google_account_info->id,
        'image_uri' => $google_account_info->picture,
        'status' => 'confirmed'
      ];
    }

    if (empty($data['email'])) return ['result' => false, 'message' => 'No e-mail granted by Google'];

    if ($action == 'data')
      return ['result' => true, 'data' => $data];

    if ($action == 'login'  && $emailVerified) {
      $client = $this->orm->get($this->clientModel, ['email' => $data['email']], true);
      if ($client) {
        $this->storeData($client);
        return ['result' => true];
      } else return ['result' => false];
    }

    // ------------------------------------------------------------------------------------
    // not registered via Google but maybe via EMAIL?
    // matching by e-mail is only allowed when Google itself verified the address

    $result = $this->register($data, null, false, $emailVerified);

    // logujemy
    if ($result && $result['result']) {
      $image = $data['image_uri'];
      $result = $this->login(null, null, ['google_id' => $data['google_id']]);
      @$result['client']['image_uri'] = $image;
    }

    return ($result);
  }
}
