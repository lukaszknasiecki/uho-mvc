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

  public function loginGoogleInit()
  {
    $client = $this->loginGoogleClient();

    // CSRF protection: random state
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth2state'] = $state;

    // Build auth URL
    $authUrl = $client->createAuthUrl();

    // Append state safely
    $sep = (parse_url($authUrl, PHP_URL_QUERY) ? '&' : '?');
    $authUrl .= $sep . 'state=' . urlencode($state);

    header('Location: ' . $authUrl, true, 302);
    exit;
  }

  /**
   * Google login
   * @param string $access_token token from Google API
   * @param string $code authorization code from Google redirect
   * @return array returns result array with login status
   */

  public function loginGoogle($access_token, $code = null)
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

      if ($data && $data['sub']) {
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

      if (isset($token['error'])) return ['result' => false, 'message' => 'No token found: ' . $token['error']];
      try {
        $client->setAccessToken($token);
      } catch (\Exception $e) {
        return ['result' => false, 'message' => $e->getMessage()];
      }

      $google_oauth = new GoogleOauth2($client);
      $google_account_info = $google_oauth->userinfo->get();

      if (!$google_account_info->id) return ['result' => false, 'message' => 'No google ID found'];

      $data = [
        'name' => $google_account_info->given_name,
        'surname' => $google_account_info->family_name,
        'email' => $google_account_info->email,
        'google_id' => $google_account_info->id,
        'image_uri' => $google_account_info->picture,
        'status' => 'confirmed'
      ];
    }

    // ------------------------------------------------------------------------------------
    // not registered via Google but maybe via EMAIL?

    $result = $this->register($data);

    // logujemy
    if ($result) {
      $image = $data['image_uri'];
      $result = $this->login(null, null, ['google_id' => $data['google_id']]);
      @$result['client']['image_uri'] = $image;
    }

    return ($result);
  }
}
