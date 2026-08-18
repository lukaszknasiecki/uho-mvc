<?php

namespace Huncwot\UhoFramework;

/**
 * Facebook authentication trait for _uho_client
 * Provides Facebook OAuth login functionality
 */

trait _uho_client_auth_facebook
{
  /**
   * Graph API version used by every Facebook call in this trait
   */
  private $facebookApiVersion = 'v23.0';

  /**
   * Builds a versioned Graph API url
   *
   * @param string $path  graph path without leading slash, e.g. 'me'
   * @param array  $query query parameters
   * @return string
   */
  private function facebookGraphUrl(string $path, array $query = []): string
  {
    return 'https://graph.facebook.com/' . $this->facebookApiVersion . '/' . $path
      . ($query ? '?' . http_build_query($query) : '');
  }

  /**
   * Performs a GET request to the Graph API and decodes the JSON response
   *
   * @param string $url
   * @return array|null null when the request or decoding failed
   */
  private function facebookGraphGet(string $url): ?array
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!is_string($response)) return null;
    $data = json_decode($response, true);

    return is_array($data) ? $data : null;
  }

  /**
   * Verifies that an access token was really issued for THIS Facebook app.
   *
   * Without this check any valid Facebook token would be accepted, so a token
   * minted for a completely unrelated app (which its owner fully controls)
   * could be replayed here to log in as that app's user - the classic
   * confused-deputy / "token substitution" flaw. Fails closed: any network,
   * decoding or validation problem returns null.
   *
   * @param string $accessToken token to inspect
   * @return array|null the debug_token 'data' payload, null when not valid for this app
   */
  private function facebookDebugToken(string $accessToken): ?array
  {
    $appId = (string) $this->oAuth['facebook']['client_id'];
    $appSecret = (string) $this->oAuth['facebook']['client_secret'];

    if (!$appId || !$appSecret) return null;

    $data = $this->facebookGraphGet($this->facebookGraphUrl('debug_token', [
      'input_token' => $accessToken,
      // app access token - never expose this to the browser
      'access_token' => $appId . '|' . $appSecret
    ]));

    $data = isset($data['data']) && is_array($data['data']) ? $data['data'] : null;

    if (!$data) return null;
    if (empty($data['is_valid'])) return null;
    // the token must belong to our app and to a resolvable user
    if (!isset($data['app_id']) || (string) $data['app_id'] !== $appId) return null;
    if (empty($data['user_id'])) return null;

    return $data;
  }

  /**
   * Initiates Facebook login by redirecting to Facebook OAuth dialog
   */
  public function loginFacebookInit()
  {
    $redirectUri = "https://www.facebook.com/" . $this->facebookApiVersion . "/dialog/oauth?" . http_build_query([
      'client_id' => $this->oAuth['facebook']['client_id'],
      'response_type' => 'code',
      'redirect_uri'  => $this->oAuth['facebook']['redirect_uri'],
      'scope' => 'email,public_profile'
    ]);


    header('Location: ' . $redirectUri, true, 302);
    exit;
  }

  /**
   * Facebook login
   * @param string $accessToken token from Facebook API
   * @param string $code authorization code from Facebook redirect
   * @return array returns result array with login status
   */

  public function loginFacebook($accessToken = null, $code = null)
  {

    if (!$this->oAuth['facebook']) return ['result' => false, 'Facebook oAuth config missing'];

    // we can get data with token or code (from hard redirect)
    if (!$accessToken && !$code) return ['result' => false, 'message' => 'No code/token specified'];

    // $code --> $accessToken
    if ($code) {
      $token = $this->facebookGraphGet($this->facebookGraphUrl('oauth/access_token', [
        'client_id' => $this->oAuth['facebook']['client_id'],
        'client_secret' => $this->oAuth['facebook']['client_secret'],
        'redirect_uri'  => $this->oAuth['facebook']['redirect_uri'],
        'code'          => $code
      ]));

      $accessToken = isset($token['access_token']) ? $token['access_token'] : null;
    }

    if (empty($accessToken)) {
      return ['result' => false, 'message' => 'Authentication failed or was cancelled.'];
    }

    // the token is only trustworthy once Facebook confirms it was issued for our app
    $debug = $this->facebookDebugToken($accessToken);
    if (!$debug) {
      return ['result' => false, 'message' => 'Facebook token is not valid for this application.'];
    }

    // Fetch user profile
    $data = $this->facebookGraphGet($this->facebookGraphUrl('me', [
      'fields' => 'id,first_name,last_name,email,picture.width(200).height(200)',
      'access_token' => $accessToken
    ]));

    if (empty($data['id'])) {
      return ['result' => false, 'message' => 'Failed to fetch user profile.'];
    }

    // the profile must be the very user the token was issued to
    if ((string) $data['id'] !== (string) $debug['user_id']) {
      return ['result' => false, 'message' => 'Facebook token user mismatch.'];
    }

    $data = [
      'name' => $data['first_name'],
      'surname' => $data['last_name'],
      'email' => isset($data['email']) ? $data['email'] : null,
      'facebook_id' => $data['id'],
      'image_uri' => isset($data['picture']['data']['url']) ? $data['picture']['data']['url'] : null,
      'image_present' => isset($data['picture']['data']['url']) ? 1 : 0,
      'status' => 'confirmed'
    ];

    if (!$data['email']) return ['result' => false, 'message' => 'No e-mail granted by Facebook.'];

    // The Graph API exposes no email_verified flag, but we are assuming
    // that Facebook is giving verified email
    $emailVerified = true; 

    $result = $this->register($data, null, false, $emailVerified);

    if ($result && $result['result']) {
      $image = $data['image_uri'];
      $result = $this->login(null, null, ['facebook_id' => $data['facebook_id']]);
      @$result['client']['image_uri'] = $image;
    }

    return ($result);
  }
}
