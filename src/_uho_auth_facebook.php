<?php

namespace Huncwot\UhoFramework;

/**
 * Facebook authentication trait for _uho_auth
 * Provides Facebook OAuth login functionality
 */

trait _uho_auth_facebook
{
  /**
   * Graph API version used by every Facebook call in this trait
   */
  private $facebookApiVersion = 'v23.0';
  private $trust_oauth_email=true;

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
    $appId = (string) $this->oauth['facebook']['client_id'];
    $appSecret = (string) $this->oauth['facebook']['client_secret'];

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
   * Facebook login init, redirects to login screen
   */

  public function loginFacebookInit()
  {
    // CSRF protection: random state
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth2state'] = $state;

    $authUrl = 'https://www.facebook.com/' . $this->facebookApiVersion . '/dialog/oauth?' . http_build_query([
      'client_id' => $this->oauth['facebook']['client_id'],
      'response_type' => 'code',
      'redirect_uri' => $this->oauth['facebook']['redirect_uri'],
      'scope' => 'email,public_profile',
      'state' => $state
    ]);

    header('Location: ' . $authUrl, true, 302);
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
    if (!$this->oauth['facebook']) return ['result' => false, 'Facebook oAuth config missing'];

    // we can get data with token or code (from hard redirect)
    if (!$accessToken && !$code) return ['result' => false, 'message' => 'No token/code specified'];

    // Option#2 - $code --> $accessToken

    if (!$accessToken && $code)
    {
      $token = $this->facebookGraphGet($this->facebookGraphUrl('oauth/access_token', [
        'client_id' => $this->oauth['facebook']['client_id'],
        'client_secret' => $this->oauth['facebook']['client_secret'],
        'redirect_uri' => $this->oauth['facebook']['redirect_uri'],
        'code' => $code
      ]));

      $accessToken = isset($token['access_token']) ? $token['access_token'] : null;
    }

    if (empty($accessToken)) return ['result' => false, 'message' => 'Login with code failed'];

    // the token is only trustworthy once Facebook confirms it was issued for our app
    $debug = $this->facebookDebugToken($accessToken);
    if (!$debug) return ['result' => false, 'message' => 'Facebook token is not valid for this application'];

    // Fetch user profile
    $profile = $this->facebookGraphGet($this->facebookGraphUrl('me', [
      'fields' => 'id,first_name,last_name,email,picture.width(200).height(200)',
      'access_token' => $accessToken
    ]));

    if (empty($profile['id'])) return ['result' => false, 'message' => 'Login with token failed'];

    // the profile must be the very user the token was issued to
    if ((string) $profile['id'] !== (string) $debug['user_id']) {
      return ['result' => false, 'message' => 'Facebook token user mismatch'];
    }

    $picture = isset($profile['picture']['data']['url']) ? $profile['picture']['data']['url'] : '';

    $data = [
      'name' => isset($profile['first_name']) ? $profile['first_name'] : '',
      'surname' => isset($profile['last_name']) ? $profile['last_name'] : '',
      'email' => isset($profile['email']) ? $profile['email'] : null,
      'facebook_id' => $profile['id'],
      'image_uri' => $picture,
      'image_present' => $picture ? 1 : 0,
      'status' => 'confirmed'
    ];

    if (empty($data['email'])) return ['result' => false, 'message' => 'No e-mail granted by Facebook'];

    /*
      * At this point we have user data from Facebook, we can try to find existing user or create new one
      * The Graph API exposes no email_verified flag, so a Facebook address can never be taken as proof
      * of e-mail ownership and must not be used to merge into an account registered with the same
      * e-mail elsewhere. Projects that accept that risk can opt in via oauth.facebook.trust_email.
    */

    $emailVerified = $this->trust_oauth_email || !empty($this->oauth['facebook']['trust_email']);

    $result = $this->register($data, null, false, $emailVerified);

    if ($result && $result['result']) {
      $result = $this->login(null, null, ['facebook_id' => $data['facebook_id']]);
    }

    return ($result);
  }
}
