<?php

namespace Huncwot\UhoFramework;

/**
 * Facebook authentication trait for _uho_client
 * Provides Facebook OAuth login functionality
 */

trait _uho_client_auth_facebook
{
  /**
   * Initiates Facebook login by redirecting to Facebook OAuth dialog
   */
  public function loginFacebookInit()
  {
    $redirectUri = "https://www.facebook.com/v23.0/dialog/oauth?" . http_build_query([
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
   * @param string $accessToken token from Google API
   * @return boolean returns true if successfull
   */

  public function loginFacebook($accessToken = null, $code = null)
  {

    if (!$this->oAuth['facebook']) return ['result' => false, 'Facebook oAuth config missing'];

    // we can get data with token or code (from hard redirect)

    if (!$accessToken && !$code) return ['result' => false, 'message' => 'No code/token specified'];

    // $code --> $accessToken
    if ($code) {
      $tokenUrl = "https://graph.facebook.com/v19.0/oauth/access_token?" . http_build_query([
        'client_id' => $this->oAuth['facebook']['client_id'],
        'client_secret' => $this->oAuth['facebook']['client_secret'],
        'redirect_uri'  => $this->oAuth['facebook']['redirect_uri'],
        'code'          => $code
      ]);

      $response = @file_get_contents($tokenUrl);
      $data = @json_decode($response, true);
      $accessToken = isset($data['access_token']) ? $data['access_token'] : null;
    }

    if (empty($accessToken)) {
      http_response_code(400);
      exit('Authentication failed or was cancelled.');
    }

    // Fetch user profile
    $graphUrl = "https://graph.facebook.com/me?"
      . "fields=id,first_name,last_name,email,picture.width(200).height(200)"
      . "&access_token=" . urlencode($accessToken);

    $response = @file_get_contents($graphUrl);
    $data = @json_decode($response, true);

    if (empty($data['id'])) {
      http_response_code(400);
      exit('Failed to fetch user profile.');
    }

    $data = [
      'name' => $data['first_name'],
      'surname' => $data['last_name'],
      'email' => $data['email'],
      'facebook_id' => $data['id'],
      'image_uri' => isset($data['picture']['data']['url']) ? $data['picture']['data']['url'] : null,
      'image_present' => isset($data['picture']['data']['url']) ? 1 : 0,
      'status' => 'confirmed'
    ];

    $result = $this->register($data);

    if ($result) {
      $image = $data['image_uri'];
      $result = $this->login(null, null, ['facebook_id' => $data['facebook_id']]);
      @$result['client']['image_uri'] = $image;
    }

    return ($result);
  }
}
