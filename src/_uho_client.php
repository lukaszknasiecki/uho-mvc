<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_mailer;
use Huncwot\UhoFramework\_uho_thumb;
use Huncwot\UhoFramework\_uho_fx;
use Google\Client as GoogleClient;
use Google\Service\Oauth2 as GoogleOauth2;

/**
 * This class provides CLIENT object methods
 * connected with register, login and various profile settings
 */

class _uho_client
{
  /**
   * _uho_orm client model name
   */
  private $clientModel;
  /**
   * oAuth settings
   */
  private $tokenModel;
  /**
   * oAuth settings
   */
  private $oAuth;
  /**
   * model names if different from default ones
   */
  private $models;
  /**
   * Website title
   */
  private $website;
  /**
   * PasswordFormat array [8,1,1,1,1], where every number counts for
   * min-length,min-az,min-AZ,min-digits,min-specials
   */
  private $passwordFormat;
  /**
   * Global settings array for client instance
   */
  private $settings;
  /**
   * default SALT for hasing
   */
  private $salt;
  /**
   * default model field for user/login field
   */
  private $fieldLogin = 'user';
  /**
   * default model field for user bad_logins count
   */
  private $fieldBadLogin;
  /**
   * default numver of max bad login attempts
   */
  private $maxBadLogin = 0;
  /**
   * default model field for user email field
   */
  private $fieldEmail = 'email';
  /**
   * array for cookie login
   */
  private $http_host = '';

  private $cookie;
  /**
   * hashing keys array [key1,key2]
   */
  private $keys;
  /**
   * array of model fields where keys
   * are their functions
   */
  private $fields;
  /**
   * settings for Favourites functions
   */
  private $favourites;
  /**
   * indicates is Cookie Login is enabled
   */
  private $cookieLoginEnabled = false;
  /**
   * indicates user model custom fields
   */
  private $userCustomFields = [];
  /**
   * indicates if current action is logout
   */
  private $isLogoutNow = false;
  /**
   * default sql_hash_option,
   * see mysql_hash method
   */
  private $sql_hash = 'MD5';
  /**
   * indicated external oAuth provider
   */
  private $provider;
  /**
   * indicates current HTTP prefix (http/https)
   */
  private $http = 'http';

  private $orm;
  private $lang;
  private $session_key;
  private $model;
  private $client;
  private $mailer;

  /**
   * Class constructor
   * @param _uho_orm $orm _uho_orm class instance
   * @param array $settings
   * @param string $lang current language shortcut
   * @return null
   */

  function __construct($orm, $settings, $lang = null)
  {

    /* settings array structure
          [
                'title' => 'Application Title',         // App title, i.e. used in mailing
                'models' =>                             // Names of models if different that default
                [
                    'client_model' => '',               // Main user model
                    'client_logs_model' => '',          // Logs model
                    'client_logins_model' => '',        // Logins logs model
                    'mailing' => '',                    // Mailing model
                    'newsletter_users' => ''            // Newsletter subscription model
                ],
                'users' =>                              // User specific settings
                [
                    'login' => 'email',                 // field for user's login
                    'bad_login' => 'bad_login',         // field for number of bad_logins
                    'check_if_logged_exists' => true,   // indication to check every time is logged user exists in DB
                    'custom_fields'=>['bilety24_id']    // custom fields to add to the model                    
                ],
                'fields_subset' => 'standard'           // Default set of fields for client_model
                'fields' => [],                         // Overwrites custom fields for client_model
                'favourites' =>                         // Favourites specific settings
                  ['model' => 'client_favourites'],     // Favourites model
                'cookie' =>                             // Use cookie logins, if ==true default settings are used
                 [
                  'days' => 365,                        // cookie lifespan
                  'name' => ''                          // cookie name
                  'domain' => $_SERVER['HTTP_HOST'],    // cookie domain
                  'login' => true                       // use login cookie
                 ],
                'provider => true,                      // Indicates use of Auth0
                'mailer' =>                             // Mailer settings
                    ['smtp' => $smtp],                  // _uho_mailer SMTP array
                'salt' =>                               // Salt used for hashing sensitive data, required
                    ['type' => 'double', 'value' => $cfg['password_salt'], 'field' => 'salt'],
                'hash' => @$cfg['password_hash'],       // Enc type for passwords
                'oauth' => [                            // OAuth tokens for external logins
                    'google' => [ClientId, ClientSecret]              // google keys
                    'facebook' => [ClientId, ClientSecret]            // facebook keys
                    'epuap' => [ "p12_sig" => "", p12_sig_pass =>""]  // epuap keys
                ]
                'settings' =>                           // general settings
                [
                    'password_format' => @$cfg['password_required'],    // password format, check private $passwordFormat for details
                    'max_bad_login' => @$cfg['max_bad_login'],          // max number of bad logins allowed
                    'gdpr_days' => @$this->dictGet('settings', 'data-processing-days')['value'],    // max number of days without activity to anonimize account
                ],
            ]
    */

    $this->orm = $orm;

    if (is_array($settings)) {
      if (isset($settings['hash']) && $settings['hash']) $this->sql_hash = $settings['hash'];
      $this->sql_hash = strtoupper($this->sql_hash);
      $this->salt = @$settings['salt'];

      $this->passwordFormat = @$settings['settings']['password_format'];
      if (!$this->passwordFormat) $this->passwordFormat = '8,1,1,1,1';
      $this->passwordFormat = explode(',', $this->passwordFormat);

      $this->maxBadLogin = @$settings['settings']['max_bad_login'];

      $this->clientModel = @$settings['models']['client_model'];
      $this->tokenModel = isset($settings['models']['client_tokens']) ? $settings['models']['client_tokens'] : 'users_tokens';


      if (isset($settings['models']['users'])) $this->clientModel = $settings['models']['users'];
      $this->website = @$settings['title'];
      $this->models = @$settings['models'];

      if (isset($this->models['client_logs_model']) && !is_array($this->models['client_logs_model'])) {
        $this->models['client_logs_model'] = [
          'model' => $this->models['client_logs_model'],
          'fields' => ['user', 'action', 'datetime', 'session'],
          'check_ban' => false
        ];
      }

      if (isset($settings['mailer'])) $this->mailer = new _uho_mailer(['smtp' => $settings['mailer']['smtp']]);
      if (isset($settings['users']['login'])) $this->fieldLogin = $settings['users']['login'];
      if (isset($settings['users']['bad_login'])) $this->fieldBadLogin = $settings['users']['bad_login'];

      if (isset($settings['users']['custom_fields'])) $this->userCustomFields = $settings['users']['custom_fields'];
      else $this->userCustomFields = [];

      if (@$settings['oauth']) $this->oAuth = $settings['oauth'];
      if (@$settings['favourites']) $this->favourites = $settings['favourites'];
      if (@$settings['settings']['http_host'])  $this->http_host = $settings['settings']['http_host'];

      // supported: auth0
      if (isset($settings['provider'])) {
        require_once('_uho_client_auth0.php');
        $this->provider =
          [
            'name' => $settings['provider'],
            'model' => new _uho_client_auth0($this, $settings['oauth']['auth0'])
          ];
      }

      if (@$settings['fields_subset'] == 'standard')
        $fields =
          [
            'login' => 'email',
            'password' => 'password',
            'email' => 'email',
            'email_key' => 'email_key',
            'salt' => 'salt',
            'uid' => 'uid',
            'date' => 'date_set',
            'lang' => 'lang',
            'facebook_id' => 'facebook_id',
            'google_id' => 'google_id',
            'epuap_id' => 'epuap_id',
            'other' => ['name', 'surname', 'newsletter'],
            'status' => 'status'
          ];

      if (isset($settings['fields'])) {
        $other = @$settings['fields']['other'];
        unset($settings['fields']['other']);
        $fields = array_merge($fields, $settings['fields']);
        $fields['other'] = array_merge($fields['other'], $other);
      }
      if (isset($fields)) $this->setFields($fields);

      if (isset($settings['settings'])) $this->settings = $settings['settings'];
    }
    // depreceated
    else exit('_uho_client:depreceated::settings shoud be an array');

    $this->lang = $lang;
    if (!isset($settings['title'])) $settings['title'] = $_SERVER['HTTP_HOST'];

    $this->session_key = 'uho_client_' . $settings['title'] . '_' . $this->hash($this->salt['value'] . '5eh');
    $this->http = $this->http . '://' . $_SERVER['HTTP_HOST'];

    if (isset($_SESSION[$this->session_key]) && @$settings['users']['check_if_logged_exists']) {
      $data = $this->getData(true);
      if (!$data) $this->logout();
    }

    if (empty($_SESSION[$this->session_key . '_token'])) {
      $_SESSION[$this->session_key . '_token'] = bin2hex(random_bytes(32));
    }

    if (isset($settings['cookie'])) {
      if (!is_array($settings['cookie']))
        $settings['cookie'] = [
          'days' => 365,
          'name' => $this->hashPass($settings['title']),
          'domain' => $_SERVER['HTTP_HOST'],
          'login' => true
        ];
      $this->enableCookie($settings['cookie']['name'], $settings['cookie']['days'], $settings['cookie']['domain'], $settings['cookie']['login']);
    }
  }


  /**
   * Sets client record fields structure
   *
   * @param array $t fields to be set
   */
  public function setFields($t): void
  {
    if ($t['login']) $this->fieldLogin = $t['login'];
    $this->fields = $t;
  }

  /**
   * Sets client model
   *
   * @param string $t client model name
   */
  public function setModel($t): void
  {
    $this->model = $t;
  }

  /**
   * Sets hash keys used for encryption
   *
   * @param array $k array of keys
   */
  public function setKeys($k): void
  {
    $this->keys = $k;
  }

  /**
   * Returns max login attempts number
   * @return int max login attempts number
   */

  public function getMaxLoginAttempts()
  {
    return $this->maxBadLogin;
  }

  /**
   * Returns remaining max login attempts number for user
   * @param string $login users login id
   * @return int max login attempts number
   */

  public function getRemainingLoginAttempts($login)
  {
    $result = -1;
    if ($this->fieldBadLogin && $this->maxBadLogin) {
      $filters = [$this->fieldLogin => $login, 'status' => ['confirmed']];
      $exists = $this->getClient($filters);
      if ($exists && isset($exists[$this->fieldBadLogin])) {
        $result = $this->maxBadLogin - $exists[$this->fieldBadLogin];
        if ($result < 0) $result = 0;
      }
    }
    return $result;
  }

  /**
   * Resets remaining max login attempts number
   *
   * @param int $user_id user id for this action
   */
  private function resetRemainingLoginAttempts($user_id): void
  {
    if ($user_id && $this->fieldBadLogin && !$this->provider) {
      $data = [$this->fieldBadLogin => 0];
      $this->orm->putJsonModel($this->clientModel, $data, ['id' => $user_id]);
    }
  }

  /**
   * Removes remaining max login attempts for the user and blocks him
   *
   * @param string $login users login id
   */
  private function removeRemainingLoginAttempts($login): void
  {
    if ($login && $this->fieldBadLogin && !$this->provider) {
      $filters = [$this->fieldLogin => $login, 'status' => ['confirmed']];
      $exists = $this->getClient($filters);
      if ($exists && isset($exists[$this->fieldBadLogin])) {
        $i = $exists[$this->fieldBadLogin] + 1;
        $data = [$this->fieldBadLogin => $i];
        if ($this->maxBadLogin && $this->fields['locked'] && $i >= $this->maxBadLogin)
          $data[$this->fields['locked']] = 1;

        $this->orm->putJsonModel($this->clientModel, $data, ['id' => $exists['id']]);
      }
    }
  }

  /**
   * Stores current user's data in Session var
   *
   * @param array $data user's data
   */
  private function storeData($data): void
  {
    $_SESSION[$this->session_key] = $data;
  }

  /**
   * Adds data to current user's data in Session var
   *
   * @param string $key record's key
   * @param string $value record's value
   */
  public function storeDataParam($key, $value): void
  {
    $_SESSION[$this->session_key][$key] = $value;
  }

  /**
   * Enables cookie login
   *
   * @param string $name cookie's name
   * @param int $days cookie's lifespam in days
   * @param string $domain cookie's domain
   * @param boolean $login if true, tries to auto-login with this cookie
   */
  public function enableCookie($name, $days, $domain, $login = false): void
  {
    $this->cookieLoginEnabled = true;
    $this->cookie = ['name' => $name, 'days' => $days, 'domain' => $domain];
    if ($login) $this->cookieLogin();
  }

  /**
   * Performs login via cookie
   */
  public function cookieLogin($force = false): void
  {
    if ($this->cookie || $force) $uid = @$_COOKIE[$this->cookie['name']];
    if (isset($uid)) {
      $this->login(null, null, ['cookie' => $uid]);
      if (!@$_SESSION[$this->session_key])
        $this->cookie = false;
    }
  }

  /**
   * Saves current cookie in DB, so it can be used if session is cleared
   *
   * @param int $id user's id
   */
  public function cookieLoginStore($id): void
  {
    if ($this->cookie && $this->cookieLoginEnabled) {
      $uid = $this->hashPass(uniqid());
      $this->orm->putJsonModel($this->clientModel, ['id' => $id, 'cookie_key' => $uid . $this->salt['value']]);
      setcookie(
        $this->cookie['name'],
        $uid,
        [
          'expires' => time() + 3600 * 24 * $this->cookie['days'],
          'path' => "/",
          'domain' => $this->cookie['domain'],
          'secure' => strpos($_SERVER['HTTP_HOST'], '.lh') === false,
          'httponly' => 1,
          'samesite' => 'Strict'
        ]
      );
    }
  }

  /**
   * Clears cookie in DB for selected user, so it cannot be used anymore
   *
   * @param int $id user's id
   */
  private function cookieLoginClear($id): void
  {
    $this->orm->putJsonModel($this->clientModel, ['id' => $id, 'cookie_key' => '']);
  }

  public function getCookieName()
  {
    return $this->cookie['name'];
  }

  /**
   * Destroys current login cookie
   */
  private function cookieLogout(): void
  {
    $id = $this->getClientId();
    if ($this->cookie) {
      setcookie(
        $this->cookie['name'],
        "",
        time() - 3600,
        "/",
        $this->cookie['domain']
      );
    }
    if ($id) $this->cookieLoginClear($id);
  }

  /**
   * Returns current session token
   * @return null
   */

  public function getToken($key = '')
  {
    if ($key) return $_SESSION[$key . '_token'];
    return $_SESSION[$this->session_key . '_token'];
  }

  /**
   * Validates session token
   * @param string $token token to be validated
   * @return boolean
   */

  public function validateToken($token, $key = '')
  {
    $result = !empty($token) && ($token == $this->getToken($key));
    return $result;
  }

  /**
   * Retiurns current user's data
   * @param boolean $reload if true, data are loaded form the DB, not from the session
   * @return array user's data
   */

  public function getData($reload = false)
  {
    if ($this->isLogoutNow) return;
    if ($this->provider) {
      return $this->provider['model']->getData();
    } else {
      $data = @$_SESSION[$this->session_key];
      if (!is_array($data)) $data = null;
      if ($reload || (!$data && $this->cookie)) {
        $this->cookieLogin();
        $data = @$_SESSION[$this->session_key];
        if (@$data['id'] && $reload) {
          $data = $this->orm->getJsonModel($this->clientModel, ['id' => $data['id'], 'status' => 'confirmed'], true);
          $this->storeData($data);
        }
      }
    }

    return $data;
  }

  /**
   * Returns single field value from current user's data
   * @param string $field field to be retrieved
   * @return string users' data field value
   */

  public function getDataField($field)
  {
    $data = $this->getData();
    if (isset($data[$field]))
      return $data[$field];
  }

  /**
   * Returns current user's id
   *
   * @return int|string user's id
   */
  public function getId($reload = false): string|int
  {
    if ($reload) return $this->getClientId(true);
    else return $this->getDataField('id');
  }

  /**
   * Returns true is any user is currently logged in
   * @return boolean
   */

  public function isLogged()
  {
    if ($this->getData()) return true;
    else return false;
  }

  /**
   * Returns true is any user is currently logged via Epuap
   *
   * @return null|true
   */
  public function isLoggedEpuap()
  {
    if (@$this->getData()['epuap_id']) return true;
  }

  /**
   * Get's currently logged user's data
   * @return array
   */

  public function getLoggedClient($reload = false)
  {
    $d = $this->getData($reload);
    if ($d) {
      if ($d['password']) $d['password'] = 1;
      else $d['password'] = 0;
      unset($d['key_confirm']);
      unset($d['salt']);
    }
    return $d;
  }

  /**
   * Finds user by filters and returns its data form DB
   *
   * @param array $filters filters used to find the user
   * @param boolean $skip_provide if true provider is not being used for the query
   * @param (int|mixed|string|string[])[] $params
   *
   * @return array user's data
   *
   * @psalm-param array<array-key|mixed, 0|array{0?: 'confirmed', type?: 'sql', value?: string}|mixed|string> $params
   */
  public function getClient(array $params, $skip_provider = false, $skip_pass_check = false)
  {
    if ($this->provider && !$skip_provider) return $this->provider['model']->getClient($params);
    else {
      $filters = $params;
      $pass = isset($filters['password']) ? $filters['password'] : null;

      unset($filters['password']);

      $t = $this->orm->getJsonModel($this->clientModel, $filters, true);

      if ($this->salt['type'] == 'double')
        $pass .= @$t[$this->salt['field']];

      if (!$skip_pass_check && (!$pass || empty($t) || !password_verify($pass, $t['password']))) $t = null;

      if ($t) return $t;
    }
  }

  /**
   * Returns current client's id
   * @return integer
   */
  public function getClientId(bool $reload = false)
  {
    $data = $this->getData($reload);
    if (isset($data['id'])) return $data['id'];
  }

  /**
   * Performs any actions nedded before user is logged in
   * @return null
   */

  public function beforeLogin()
  {
    if (isset($this->provider)) return $this->provider['model']->beforeLogin();
  }

  /**
   * Performs any actions nedded before user is logged out
   * @return null
   */

  public function beforeLogout()
  {
    if (isset($this->provider)) return $this->provider['model']->beforeLogout();
  }

  /**
   * Performs any actions nedded before login callback is being run
   * @return null
   */

  public function beforeLoginCallback($data)
  {
    if (isset($this->provider)) return $this->provider['model']->beforeLoginCallback($data);
  }

  private function logAdd($type, $result): void
  {
    if (!empty($this->models['logs']))
      $this->orm->postJsonModel($this->models['logs'], ['type' => $type, 'result' => $result]);
  }

  /**
   * Main login function
   *
   * @param string $email user's login
   * @param string $pass user's password
   * @param array $pass additional parameters for login
   * @param (mixed|string)[]|null $params
   *
   * @return (array|bool|mixed|string)[]|null
   *
   */
  public function login($email, $pass, array|null $params = null)
  {
    if ((!$email && $this->fieldLogin) && !$pass && !$params) {
      return;
    }


    if (isset($params['facebook_id'])) {
      $client = $this->getClient(array('facebook_id' => $params['facebook_id']), false, true);
      if ($client) $this->cookieLoginStore($client['id']);
    } elseif (isset($params['google_id'])) {
      $client = $this->getClient(array('google_id' => $params['google_id']), false, true);
      if ($client) $this->cookieLoginStore($client['id']);
    } elseif (isset($params['epuap_id'])) {
      $client = $this->getClient(array('epuap_id' => $params['epuap_id']));
      if ($client) $this->cookieLoginStore($client['id']);
    } elseif (isset($params['sso_id'])) {
      $client = $this->getClient(array('sso_id' => $params['sso_id']));
      if ($client) $this->cookieLoginStore($client['id']);
    } else if (isset($params['cookie']) && $this->cookieLoginEnabled) {
      $client = $this->getClient(array('cookie_key' => $params['cookie'] . $this->salt['value'], 'status' => ['confirmed']));
      if ($client && isset($client['id'])) $this->cookieLoginStore($client['id']);
    } else {

      if ($this->loginCheckBan())
        return ['result' => false, 'message' => 'client_login_ban'];


      $pass = trim($pass) . $this->salt['value'];

      if ($this->fieldLogin)
        $filters = [$this->fieldLogin => $email, 'password' => $pass, 'status' => ['confirmed']];
      else $filters = array('password' => $pass, 'status' => ['confirmed']);

      if (isset($this->fields['locked'])) $filters[$this->fields['locked']] = 0;

      $client = $this->getClient($filters);

      if ($client) {
        session_regenerate_id(true);
        $this->resetRemainingLoginAttempts($client['id']);
        $this->cookieLoginStore($client['id']);
        $this->logsAdd('login', 1);
      } else {
        $this->logsAdd('login', 0);
        $this->removeRemainingLoginAttempts($filters[$this->fieldLogin]);
      }
    }

    if ($client) {
      $result = true;
      $this->storeData($client);
    } else {
      $result = false;
      $message = 'client_login_failed';
    }

    if (isset($this->models['client_logins_model'])) {
      $val = ['login' => $email, 'success' => intval($result), 'ip' => $this->getIp()];
      $this->orm->postJsonModel($this->models['client_logins_model'], $val);
      $_SESSION['login_session_id'] = $this->orm->getInsertId();
    }

    if ($result) $this->favouritesLoad();

    if ($result) {
      if (!isset($message)) $message = 'client_login_success';
      return (array('result' => $result, 'client' => $client, 'message' => $message));
    } else return (array('result' => $result, 'message' => $message));
  }

  public function loginByToken($token)
  {
    $user_id = $this->getUserToken($token);

    if (!empty($user_id))
      $client = $this->orm->getJsonModel($this->clientModel, ['id' => intval($user_id)], true);
    else $client = null;

    if ($client) {
      $this->storeData($client);
      return true;
    }
  }

  /**
   * Facebook login
   * @param string $accessToken token from FB API
   * @return boolean returns true if successfull
   */
  /*
  public function loginFacebook($accessToken)
  {

    // token derived from JS popup login
    // params --> additional data to update

    if (!$this->oAuth['facebook']) return ['result' => false, 'Facebook oAuth config missing'];

    $fb = new \Facebook\Facebook(
      array(
        'app_id'  => $this->oAuth['facebook'][0],
        'app_secret' => $this->oAuth['facebook'][1]
      )
    );


    if (!$accessToken) {
      $helper = $fb->getRedirectLoginHelper();
      // TOKEN
      try {
        $accessToken = $helper->getAccessToken();
      } catch (\Facebook\Exceptions\FacebookResponseException $e) {
        // When Graph returns an error
        echo 'Graph returned an error: ' . $e->getMessage();
        exit;
      } catch (\Facebook\Exceptions\FacebookSDKException $e) {
        // When validation fails or other local issues
        echo 'Facebook SDK returned an error: ' . $e->getMessage();
        exit;
      }

      if (!isset($accessToken)) {
        if ($helper->getError()) {
          header('HTTP/1.0 401 Unauthorized');
          echo "Error: " . $helper->getError() . "\n";
          echo "Error Code: " . $helper->getErrorCode() . "\n";
          echo "Error Reason: " . $helper->getErrorReason() . "\n";
          echo "Error Description: " . $helper->getErrorDescription() . "\n";
        } else {
          header('HTTP/1.0 400 Bad Request');
          echo 'Bad request';
          exit();
        }
        exit;
      }
    }


    try {
      $oAuth2Client = $fb->getOAuth2Client();
      $tokenMetadata = $oAuth2Client->debugToken($accessToken);
      $tokenMetadata->validateAppId($this->oAuth['facebook'][0]);
      $tokenMetadata->validateExpiration();
    } catch (\Facebook\Exceptions\FacebookResponseException $e) {
      echo 'Graph returned an error: ' . $e->getMessage();
      exit;
    } catch (\Facebook\Exceptions\FacebookSDKException $e) {
      echo 'Facebook SDK returned an error: ' . $e->getMessage();
      exit;
    }

    try {
      // Returns a `Facebook\FacebookResponse` object
      $response = $fb->get('/me?fields=id,first_name,last_name,email', $accessToken);
    } catch (\Facebook\Exceptions\FacebookResponseException $e) {
      exit('Graph returned an error: ' . $e->getMessage());
    } catch (\Facebook\Exceptions\FacebookSDKException $e) {
      exit('Facebook SDK returned an error: ' . $e->getMessage());
    }

    $facebook_user_profile = $response->getGraphUser();  // id, name, email

    if ($facebook_user_profile) {
      $data = array();
      $data['name'] = $facebook_user_profile['first_name'];
      $data['surname'] = $facebook_user_profile['last_name'];
      $data['email'] = $facebook_user_profile['email'];
      $data['facebook_id'] = $facebook_user_profile['id'];
      $data['image'] = 'https://graph.facebook.com/' . $data['facebook_id'] . '/picture?type=large';
      $data['status'] = 'confirmed';
      $result = $this->register($data);
    } else $result = false;


    if ($result) {

      $result = $this->login(null, null, ['facebook_id' => $facebook_user_profile['id']]);
    }


    return ($result);
  }
*/

  /**
   * Facebook Oauth Section
   * 
   * loginFacebookClient    --> creates Facebook Client
   * loginFacebookInit      --> shows Facebook Oauth Popup
   * loginFacebook          --> registers / logins user
   */

  private function loginFacebookClient()
  {
    
    $client = new \Facebook\Facebook([
      'app_id' => $this->oAuth['facebook']['client_id'],
      'app_secret' => $this->oAuth['facebook']['client_secret'],
      'default_graph_version' => 'v19.0'
    ]);


    return $client;
  }

  public function loginFacebookInit()
  {
    /*
    $client = $this->loginFacebookClient();
    $helper = $client->getRedirectLoginHelper();

    $permissions = ['email', 'public_profile'];
    $authUrl = $helper->getLoginUrl($this->oAuth['facebook']['redirect_uri'], $permissions);*/

    $redirectUri = "https://www.facebook.com/v23.0/dialog/oauth?" . http_build_query([
        'client_id' => $this->oAuth['facebook']['client_id'],
        'response_type'=>'code',
        'redirect_uri'  => $this->oAuth['facebook']['redirect_uri'],
        'scope'=>'email,public_profile'
      ]);

  
      header('Location: ' . $redirectUri, true, 302);
      exit;
  }

  /**
   * Facebook login
   * @param string $accessToken token from Google API
   * @return boolean returns true if successfull
   */

  public function loginFacebook($accessToken=null,$code=null)
  {

    if (!$this->oAuth['facebook']) return ['result' => false, 'Facebook oAuth config missing'];

    // we can get data with token or code (from hard redirect)

    if (!$accessToken && !$code) return ['result' => false, 'message' => 'No code/token specified'];

    // $code --> $accessToken    
    if ($code)
    {
      $tokenUrl = "https://graph.facebook.com/v19.0/oauth/access_token?" . http_build_query([
        'client_id' => $this->oAuth['facebook']['client_id'],
        'client_secret' => $this->oAuth['facebook']['client_secret'],      
        'redirect_uri'  => $this->oAuth['facebook']['redirect_uri'],      
        'code'          => $code
      ]);
  
      $response = @file_get_contents($tokenUrl);
      $data = @json_decode($response, true);
      $accessToken=isset($data['access_token']) ? $data['access_token'] : null;

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

  /**
   * Google Oauth Section
   * 
   * loginGoogleClient    --> creates Google Client
   * loginGoogleInit      --> shows Google Oauth Popup
   * loginGoogle          --> registers / logins user
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
   * @param string $accessToken token from Google API
   * @return boolean returns true if successfull
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

  /**
   * Performs current user's logout
   */
  public function logout(): bool
  {
    $result = !empty($_SESSION[$this->session_key]);
    $this->logsAdd('logout', 1);
    $this->cookieLogout();
    unset($_SESSION[$this->session_key]);
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      0,
      $params['path'],
      $params['domain'],
      $params['secure'],
      isset($params['httponly'])
    );
    @session_destroy();
    @session_regenerate_id(true);
    $this->isLogoutNow = true;
    return $result;
  }

  /**
   * Adds logs to the database
   *
   * @param string $action action to add to the logs
   *
   * @psalm-param 0|1|null $result
   */
  public function logsAdd($action, int|null $result = null): void
  {
    if (!empty($this->models['client_logs_model'])) {
      $user = $this->getClientId();
      if (!$user) $user = 0;
      $session = intval(@$_SESSION['login_session_id']);
      $data = ['datetime' => _uho_fx::sqlNow(), 'user' => $user, 'action' => $action, 'session' => $session, 'ip' => $this->getIp()];
      foreach ($data as $k => $_) if (!in_array($k, $this->models['client_logs_model']['fields'])) unset($data[$k]);
      if (isset($result)) $data['result'] = $result;
      $this->orm->postJsonModel($this->models['client_logs_model']['model'], $data);
    }
  }

  public function loginCheckBan(): bool
  {
    $result = false;
    if (!empty($this->models['client_logs_model']) && $this->models['client_logs_model']['check_ban']) {
      $date = date('Y-m-d H:i:s', strtotime(' -5 minutes '));
      $f = [
        'action' => 'login',
        'ip' => $this->getIp(),
        'datetime' => ['operator' => '>', 'value' => $date],
        'result' => 0
      ];
      foreach ($f as $k => $_) if (!in_array($k, $this->models['client_logs_model'])) unset($f[$k]);

      $find = $this->orm->getJsonModel($this->models['client_logs_model']['model'], $f, false, null, null);


      if ($find && count($find) >= 5) $result = true;
    }

    return $result;
  }

  public function logsAddLogged($action, $result = null)
  {
    if ($this->getClientId()) return $this->logsAdd($action, $result);
  }
  public function logsAddUnlogged($action, $result = null)
  {
    if ($this->getClientId());
    else return $this->logsAdd($action, $result);
  }

  /**
   * Curl_copy function, copies file from one location to another
   *
   * @param string $remote_file file to be copied
   * @param string $local_file destination path
   */
  private function curl_copy($remote_file, $local_file): void
  {
    curl_init();
    $fp = @fopen($local_file, 'w+');
    if ($fp) {
      $ch = curl_init($remote_file);
      curl_setopt($ch, CURLOPT_TIMEOUT, 50);
      curl_setopt($ch, CURLOPT_FILE, $fp);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
      curl_setopt($ch, CURLOPT_ENCODING, "");
      curl_exec($ch);
      curl_close($ch);
      fclose($fp);
    }
  }

  /**
   * Saves user's portrait image from remote location
   *
   * @param string $source image source path
   * @param int $user user's id
   * @param string $user user's uid
   *
   * @return void
   */
  private function setImageFromUrl($source, int $user, string $uid, $debug = false)
  {

    $log = [];
    if (!$source) return;
    $schema = $this->orm->getJsonModelSchema($this->clientModel);
    $image = _uho_fx::array_filter($schema['fields'], 'field', 'image', ['first' => true]);
    if ($image)
    {
      $destination = $_SERVER['DOCUMENT_ROOT'] . $image['folder'] . '/';
      $original = null;

      foreach ($image['images'] as $v) {
        if (!$original) {
          $original = $destination . $v['folder'] . '/' . $uid . '.jpg';
          if (!@copy($source, $original)) {
            if ($this->curl_copy($source, $original))
              $log[] = 'original copied with curl';
          } else $log[] = 'original copied';
        } else {
          if (file_exists($original))
            $log[] = _uho_thumb::convert($original, $original, $destination . $v['folder'] . '/' . $uid . '.jpg', $v); //, $copyOnly=false, $nr=1, $predefined_crop=null, $useNative=false, $magicBytesCheck=true);
          else $log[] = 'No original (' . $original . ') found: no resizing performed';
        }
      }
      if ($original) {
        @unlink($original);
        $log[] = 'original removed';
      }
    }
    if ($debug) print_r($log);
  }

  /**
   * Remove user's portrait image
   *
   * @param string $user user's uid
   */
  private function removeImage($uid): void
  {
    $schema = $this->orm->getJsonModelSchema($this->clientModel);
    $image = _uho_fx::array_filter($schema['fields'], 'field', 'image', ['first' => true]);
    if ($image) {
      $destination = $_SERVER['DOCUMENT_ROOT'] . $image['folder'] . '/';
      foreach ($image['images'] as $v)
        @unlink($destination . $v['folder'] . '/' . $uid . '.jpg');
    }
  }

  /**
   * Sets user's permissions GDPR expiration date
   * @param string $token user's unque token
   * @param string $date expiration date
   * @return boolean returns true if succeed
   */
  /*
  public function setGdprExpirationDate($date, $token = null)
  {
    $result = false;
    if ($token) {
      $user = $this->getUserToken($token);
      if ($user) {
        $data = ['id' => $user['user'], 'gdpr_expiration_date' => $date];
        $result = $this->orm->putJsonModel($this->clientModel, $data);
        $this->setUserTokenUsed($token);
      }
    } elseif ($this->isLogged()) {
      $data = ['id' => $this->getClientId(), 'gdpr_expiration_date' => $date];
      $result = $this->orm->putJsonModel($this->clientModel, $data);
    }
    return $result;
  }
  */

  /*
  public function getGdprExpirationDate($token)
  {
    $result = null;
    $user = $this->getUserToken($token, 0);
    if (!$user) $user = $this->getUserToken($token, 1);
    if ($user) {
      $result = $this->orm->getJsonModel($this->clientModel, ['id' => $user['user']], true);
      if ($result) $result = $result['gdpr_expiration_date'];
    }
    return $result;
  }*/

  /**
   * Creates new user
   * @param array $data user's data
   * @param boolean $returnId returns user's id if true
   * @return int returns true if success
   */

  public function create($data, $returnId = false)
  {

    $data['date_set'] = date('Y-m-d H:i:s');
    $data['ip'] = $this->getIp();

    if (!empty($this->settings['gdpr_days']))
      $data['gdpr_expiration_date'] = date('Y-m-d', strtotime("+" . $this->settings['gdpr_days'] . " days"));

    $data['uid'] = $this->uniqid();
    $data['salt'] = substr(bin2hex(random_bytes(32)), 0, 3);

    if (isset($data['password']))
      $data['password'] = $this->encodePassword($data['password'], true, $data['salt']);

    $result = $this->orm->postJsonModel($this->clientModel, $data);

    if ($result)
    {
      $user = $this->orm->getInsertId();
      if (isset($data['image']) && $user)
      {
        if ($this->orm->convertBase64($data['image'],['jpg', 'jpeg', 'png', 'gif', 'webp']))
        {
          $this->orm->addImage($this->clientModel,$user,'image',$data['image']) ;
        }        
        else $this->setImageFromUrl($data['image'], $user, $data['uid']);
      }
      if ($returnId) $result = $user;

      // use separate token table
      if (!empty($data['key_confirm']) && $this->tokenModel) {
        $days = $this->settings['registration_confirmation_days'] ?? 7;
        $this->orm->postJsonModel(
          $this->tokenModel,
          [
            'expiration' => date('Y-m-d', strtotime("+" . $days . " days")),
            'user' => $user,
            'value' => $data['key_confirm'],
            'type' => 'registration_confirmation'
          ]
        );
      }
    } else
    {
      $error=$this->orm->getLastError();      
    }
    return $result;
  }

  /**
   * Creates admin (user with admin privileges)
   * @param string $login admin's login
   * @param string $pass admin's password
   * @return boolean returns true if success
   */

  public function createAdmin($login, $pass)
  {
    if (!$this->adminExists()) {
      $data = ['name' => 'Admin', 'login' => $login, 'password' => $pass, 'admin' => 1, 'status' => 'confirmed', 'edit_all' => 1];
      $r = $this->create($data);
      if (!$r) {
        exit('admin creation error::permanent');
      }
      return $r;
    } else return false;
  }

  /**
   * Checks if any user exists in the Database
   * @return boolean returns true if any user exists
   */

  public function anyUserExists()
  {
    $exists = $this->orm->getJsonModel($this->clientModel);
    if (!$exists) return false;
    else return true;
  }

  /**
   * Checks if any admin exists in the Database
   * @return boolean returns true if any admin exists
   */

  public function adminExists()
  {
    $exists = $this->orm->getJsonModel($this->clientModel, ['admin' => 1]);
    if ($exists === 'error' || !$exists) return false;
    else return true;
  }

  /**
   * Updates user's profile with new data
   * @param int $user_id user's id
   * @param array $data data to be updated
   * @return boolean returns true if any user exists
   */

  public function update($user_id = 0, $data = null)
  {
    if (!$user_id) $user_id = $this->getClientId();

    $data['id'] = $user_id;
    $result = $this->orm->putJsonModel($this->clientModel, $data);
    $client = $this->getData();

    if (@$data['image'] == '[remove]')
      $this->removeImage($client['uid']);
    elseif (isset($data['image']))
      $this->setImageFromUrl($data['image'], $user_id, $client['uid']);

    $this->getData(true);
    return $result;
  }

  /**
   * Handles deactivate/remove request
   * @param string $password user's password for double-check
   * @param string $url url to be sent to the user with action confirmation
   * @return array returns ['result'=>true] if any user exists
   */

  public function accountRemoveRequest($password, $url)
  {

    $client = $this->getData();

    if (!$client || ($client['password'] && !$this->passwordCheck($password)))
      return ['result' => false, 'message' => 'client_password_wrong'];

    $key = $this->uniqid();
    $url = $this->http . $url;
    $url = str_replace('%key%', $key, $url);

    $this->update(null, ['key_remove' => $key]);
    $result = $this->mailing('account_remove_request', $client['email'], ['url_remove' => $url]);

    return ['result' => $result];
  }

  /**
   * Performs deactivate/remove request
   * @param string $key unique token for the action
   * @return array returns ['result'=>true] if any user exists
   */

  public function accountRemoveByKey($key)
  {
    $result = ['result' => false];
    if ($key) {
      $client = $this->orm->getJsonModel($this->clientModel, ['key_remove' => $key]);
      if (count($client) == 1) {
        $data = [
          'id' => $client[0]['id'],
          'key_remove' => '',
          'key_confirm' => '',
          'name' => '',
          'surname' => '',
          'email' => '',
          'institution' => '',
          'status' => 'disabled',
          'uid' => '',
          'google_id' => '',
          'facebook_id' => '',
          'epuap_id' => ''
        ];
        $this->orm->putJsonModel($this->clientModel, $data);
        $result = ['result' => true];
      }
    }

    return $result;
  }

  /**
   * Performs registering process including checking for existing user, validation etc.
   *
   * @param array $data user's data
   * @param string $url url to register process confirmation url
   *
   * @return array
   */

  public function register($data, $url = null): array
  {

    $result = false;

    $sso = (isset($data['facebook_id']) || isset($data['google_id']) || isset($data['epuap_id']) || isset($this->provider));

    if (!isset($data['lang'])) $data['lang'] = $this->lang;
    if (!isset($data['status'])) $data['status'] = 'submitted';

    $status = $this->getClient([$this->fieldLogin => $data[$this->fieldLogin]], false, true);

    // get login and pass fields
    $fields = [];

    $data['password'] = isset($data['password']) ? trim($data['password']) : "";
    if (!$data['password']) unset($data['password']);

    if (!$data[$this->fieldEmail]) $fields[$this->fieldEmail] = 'email_required';
    if (!isset($data['password']) && !$sso) $fields['password'] = 'pass_required';
    elseif (!$sso && strlen($data['password']) < 8) $fields['password'] = 'pass_min8';

    $message = '';

    // validation error
    if ($fields);

    // already submitted...
    elseif ($status && $status['status'] != 'confirmed' && !@$sso) {
      $fields['id']=$status['id'];
      $this->update($status['id'], ['status' => 'submitted']);
      $token = $this->generateUserToken('registration_confirmation', '+10 days', $status['id']);
      $result = $this->mailing('register_confirmation', $data['email'], ['url' => str_replace('%key%', $token, $url)]);
      if ($result) $message = 'client_email_sent';
      else {
        $message = 'mailing_system_error';
      }
    }

    // already submitted SSO
    elseif ($status && $status['status'] != 'confirmed' && $sso) {
      $data['status'] = 'confirmed';
      $this->update($status['id'], $data);
      $message = 'client_confirmed';
      $result = true;
    }

    // already confirmed
    elseif ($status && $status['status'] == 'confirmed')
    {
      $result=true;
      $this->update($status['id'], $data);
      $message = 'client_already_registered';      
    } elseif ($this->provider)
    {
      $result = $this->provider['model']->create($data);
    }
    // create new user      
    else
    {
      $data['key_confirm'] = $this->generateToken();
      $data['image_present'] = isset($data['image']) ? 1: 0;

      $result = $this->create($data);
      // mail for confirmation
      if ($result && !@$sso)
      {
        $result = $this->mailing('register_confirmation', $data['email'], ['url' => str_replace('%key%', $data['key_confirm'], $url)]);
        if ($result) $message = 'client_email_sent';
        else {
          $message = 'system_error';
        }
      } elseif ($result) $message = 'client_registered';      
      else $message = 'client_create_error';
    }

    $result = (array('result' => $result, 'message' => $message, 'fields' => $fields));
    return $result;
  }

  private function base64url(string $bin): string
  {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
  }

  private function generateToken(): string
  {
    $raw = $this->base64url(random_bytes(32));         // ~256-bit
    $hash = hash('sha256', $raw);                      // 64 hex chars
    return $hash;
  }

  /**
   * Completes registering proccess
   * @param string $key unique token create during registration process
   * @return boolean returns true if any user exists
   */

  public function registerConfirmation($key)
  {
    if ($this->tokenModel) {
      $user = $this->getUserToken($key, 'registration_confirmation', true);
      if ($user) $user = $this->orm->getJsonModel($this->clientModel, ['id' => $user, 'status' => 'submitted'], true);
    } else {
      $user = $this->orm->getJsonModel($this->clientModel, ['key_confirm' => $key, 'status' => 'submitted'], true);
    }

    if ($user) $result = $this->orm->putJsonModel($this->clientModel, ['id' => $user['id'], 'status' => 'confirmed']);
    else $result = false;

    return $result;
  }

  /**
   * Perform hash/encryption based on current method, on mysql query
   * @param string $query query to be encrypted
   * @return string returns updated query with encryption added
   */

  private function mysql_hash($query)
  {
    if ($this->sql_hash == 'MD5') $query = 'MD5(' . $query . ')';
    elseif ($this->sql_hash == 'SHA256') $query = 'SHA2(' . $query . ',256)';
    // depreceated --> elseif ($this->sql_hash == 'PASSWORD') $query = 'PASSWORD(' . $query . ')';
    elseif ($this->sql_hash == 'PASSWORD') $query = "CONCAT('*',UPPER(SHA1(UNHEX(SHA1(" . $query . ")))))";
    else exit('_uho_client::no-hash-defined');

    return $query;
  }

  /**
   * Perform hash/encryption based on current method, on given string
   *
   * @param string $pass string to be encrypted
   *
   * @return null|string returns encrypted string
   */
  private function hashPass($pass)
  {
    if ($this->sql_hash == 'SHA256') return hash('sha256', $pass);
    elseif ($this->sql_hash == 'BCRYPT') return password_hash($pass, PASSWORD_BCRYPT);
    else return password_hash($pass, PASSWORD_DEFAULT);
  }

  private function hash($token)
  {
    return hash('sha256', $token);
  }

  /**
   * Creates query for hash/encrypted fields
   *
   * @param string $pass string to be encrypted
   * @param boolean $filter create ORM's style filters
   * @param string $salt uses additional salt for encrpytion
   *
   * @return string|string[] returns encrypted string/query
   *
   * @psalm-return array{type: 'sql', value: string}|string
   */
  private function encodePassword($pass, $filter = false, $salt = null): array|string
  {

    switch ($this->salt['type']) {
      case "standard":
        $pass = $this->hashPass(trim($pass) . $this->salt['value']);
        break;
      case "double":
        $pass = $this->hashPass(trim($pass . $this->salt['value'] . $salt));
        break;
    }

    return $pass;
  }

  /**
   * Encodes password with current encryption type
   * @param string $pass pass to be encrypted
   * @param string $salt uses additional salt for encrpytion
   * @return string returns encrypted string
   */

  private function encodePasswordForWrite($pass, $salt = null)
  {
    if ($salt) $pass = $this->hashPass($pass . $salt . $this->salt['value']);
    else $pass = $this->hashPass($pass . $this->salt['field'] . $this->salt['value']);
    return $pass;
  }

  /**
   * Validates password if requires current minimum settings
   * @param string $pass password to be validated
   * @return array returns ['result'=>true] if validated
   */

  public function passwordValidateFormat($pass)
  {
    $errors = [];
    $format = $this->passwordFormat;
    $pass = trim($pass);
    $pass = str_replace(' ', '', $pass);
    $special = '^!$%&*()}{@#~?,|=_+-';

    if (strlen($pass) < $format[0]) $errors[] = ['min_length', $format[0]];
    if (preg_match_all("/[a-z]/", $pass) < $format[1]) $errors[] = ['min_lower', $format[1]];
    if (preg_match_all("/[A-Z]/", $pass) < $format[2]) $errors[] = ['min_upper', $format[2]];
    if (preg_match_all("/[0-9]/", $pass) < $format[3]) $errors[] = ['min_numbers', $format[3]];
    if (preg_match_all('/[' . preg_quote($special, '/') . ']/', $pass) < $format[4]) $errors[] = ['min_special', $format[4]];
    return ['password' => $pass, 'errors' => $errors, 'result' => count($errors) == 0];
  }

  /**
   * Auto-generates password usind current minimum settings
   * @return string returns generated password
   */

  public function passwordGenerate()
  {
    $sets = [
      'abcdefghijklmnopqrstuvwxyz',
      'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
      '0123456789',
      '^!$%&*()}{@#~?,|=_+-'
    ];
    $format = $this->passwordFormat;
    if ($format[0] < 10) $format[0] = 10;
    if ($format[1] < 6) $format[1] = 6;
    if ($format[2] < 2) $format[2] = 2;
    if ($format[3] < 2) $format[3] = 2;
    if ($format[4] < 2) $format[4] = 2;
    if ($format[1] + $format[2] + $format[3] + $format[4] < $format[0])
      $format[1] = $format[0] - $format[2] - $format[3] - $format[4];
    $pass = [];
    foreach ($sets as $k => $v)
      for ($i = 0; $i < $format[$k + 1]; $i++)
        $pass[] = $v[rand(0, strlen($v))];
    shuffle($pass);
    $pass = implode('', $pass);
    return $pass;
  }

  /**
   * Checks is string equals current user's password in the database
   *
   * @param string $pass password to be checked
   *
   * @return bool|null returns true if passwords are the same
   */
  public function passwordCheck($pass)
  {
    $data = $this->getData();
    if ($data) {

      $pass = trim($pass . $this->salt['value']);
      $filters = ['id' => $data['id']];

      $t = $this->orm->getJsonModel($this->clientModel, $filters, true);

      if ($t && $pass) {
        if ($this->salt['type'] == 'double')
          $pass .= $t[$this->salt['field']];
        if (empty($t['password']) || !password_verify($pass, $t['password'])) $t = null;
      }

      if ($t) return true;
      else return false;
    }
  }

  /**
   * Checks is current password expired given days number
   * @param int $days max number of days for password expiration
   * @return mixed returns false if passwords has not expired, number of exired days other way
   */

  public function passwordCheckExpired($days)
  {
    $data = $this->getData();
    if ($data && $data['date_set']) {
      $limit = date('Y-m-d', strtotime(" -" . $days . " days"));
      $result = $limit > $data['date_set'];
    } else $result = false;

    return $result;
  }

  /**
   * Changes user's password
   * @param string $pass new password
   * @param int $user user's id, current user is used if not defined
   * @return boolean returns true if everything went well
   */

  public function passwordChange($pass, $user = null)
  {
    if (!$user) {
      $data = $this->getData();
      if ($data) $user = $data['id'];
    }
    if ($user) {
      $salt = substr(bin2hex(random_bytes(32)), 0, 3);
      $pass = $this->encodePassword($pass, false, $salt);
      $data = ['id' => $user, 'salt' => $salt, 'date_set' => date('Y-m-d H:i:s'), 'password' => $pass];

      $result = $this->orm->putJsonModel($this->clientModel, $data);
      if ($result) $this->cookieLoginStore($user);
      else {
        exit($this->orm->getLastError());
      }
      return $result;
    }
  }

  /**
   * Handles user's password reset request
   * @param string $email user's email
   * @param string $url url to be clicked for confirmation
   * @return array returns ['result'=>true] if everything went well
   */

  public function passwordChangeByEmail($email, $url)
  {

    if (!isset($this->models['users'])) exit('_uho_client::passwordChangeByEmail::missing_model');
    $users = $this->models['users'];

    $exists = $this->orm->getJsonModel($users, ['email' => $email, 'status' => 'confirmed'], true);
    if (!$exists) return ['result' => false, 'code' => 'user_not_exists'];

    $key_confirm = $this->uniqid();
    $result = $this->orm->putJsonModel($users, ['id' => $exists['id'], 'key_confirm' => $key_confirm]);
    if (!$result) return ['result' => false, 'code' => 'system_error'];

    $result = $this->mailing('password_change', $email, ['url' => str_replace('%key%', $key_confirm, $url)]);

    if (!$result)
      $result = ['result' => true];
    return $result;
  }

  /**
   * Set password for (current) first-time user
   * @param string $pass password to eb set
   * @return array returns ['result'=>true] if everything went well
   */

  public function passwordSetFirstTime($pass)
  {
    $client = $this->getData(true);
    if (!$client || $client['password'])
      return ['result' => false, 'message' => 'client_system_error'];
    else {
      return ['result' => $this->passwordChange($pass)];
    }
  }

  /**
   * Changes user's password for current user, using old password as validation
   * @param string $oldpass old password
   * @param string $pass password to eb set
   * @return array returns ['result'=>true] if everything went well
   */

  public function passwordChangeByOldPassword($oldpass, $pass)
  {
    $validate = $this->passwordValidateFormat($pass);
    if (!$validate['result'])
      return ['result' => false, 'message' => 'client_password_invalid_format'];

    $client = $this->getData();

    if (!$client || !$this->passwordCheck($oldpass)) {
      return ['result' => false, 'message' => 'client_old_password_wrong'];
    } else {
      return ['result' => $this->passwordChange($pass)];
    }
  }

  /**
   * Changes user's password for unique token
   * @param string $key token
   * @param string $pass password to eb set
   * @return array returns ['result'=>true] if everything went well
   */

  public function passwordChangeByKey($key, $pass)
  {

    $validate = $this->passwordValidateFormat($pass);
    if (!$validate['result'])
      return ['result' => false, 'message' => 'client_password_invalid_format'];

    if (!$key || !$pass) return ['result' => false, 'code' => 'key_or_pass_not_found'];
    if (!isset($this->models['users'])) exit('_uho_client::passwordChangeByKey::missing_model');
    $users = $this->models['users'];

    $exists = $this->orm->getJsonModel($users, ['key_confirm' => $key, 'status' => 'confirmed'], true);

    if (!$exists) return ['result' => false, 'code' => 'user_not_found'];
    if (!$exists['salt']) $exists['salt'] = substr(bin2hex(random_bytes(32)), 0, 3);
    $set = [
      'id' => $exists['id'],
      'password' => $this->encodePasswordForWrite($pass, $exists['salt']),
      'salt' => $exists['salt'],
      'key_confirm' => ''
    ];

    $result = $this->orm->putJsonModel($users, $set);
    if ($result) return ['result' => true];
    else return ['result' => false, 'code' => 'system_error'];
  }

  /**
   * Removes all user's Tokens
   */

  public function removeUserTokens($user_id, $type = null)
  {
    $filters = ['user' => $user_id];
    if (!empty($type)) $filters['type'] = $type;
    return $this->orm->deleteJsonModel($this->tokenModel, $filters, true);
  }

  /**
   * Generates and saves unique token to user's record
   *
   * @param string $type user type
   * @param string $date token validation date
   *
   * @return null|string returns token
   */
  public function generateUserToken($type, $date, $user_id = 0)
  {
    if ($user_id || $this->isLogged()) {
      if ($date[0] == '+') $date = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' ' . $date));
      $token = md5($this->uniqid());
      if (!$user_id) $user_id = $this->getClientId();
      $data = ['user' => $user_id, 'expiration' => $date, 'type' => $type, 'value' => $token];

      $result = $this->orm->postJsonModel($this->tokenModel, $data);
      if ($result) {
        //$exists=$this->orm->getJsonModel('users_tokens',['token'=>$token,'id'=>$result]);
        //if ($exists) return $token;
        return $token;
      }
    }
  }

  /**
   * Marks user token as used so it's not used again
   *
   * @param string $token token value
   */
  private function setUserTokenUsed($token): void
  {
    $this->orm->putJsonModel($this->tokenModel, ['used' => 1], ['value' => $token]);
  }

  /**
   * Gets user's id by token from the database
   * @param string $token token value to be found
   * @param integer $used check for unused (0) or used (1) token
   * @return integer|null
   */

  public function getUserToken($token, $type = null, $remove_if_present = false)
  {
    $f = ['value' => $token, 'expiration' => ['operator' => '>=', 'value' => date('Y-m-d H:i:s')]];

    if ($type) $f['type'] = $type;
    $item = $this->orm->getJsonModel($this->tokenModel, $f, true);

    if ($item && $remove_if_present) $this->orm->deleteJsonModel($this->tokenModel, ['id' => $item['id']]);
    if ($item) return $item['user'];
    else return null;
  }

  /**
   * Performs mailing
   * @param string $slug mailing template identifier
   * @param array $emails list of destination emails
   * @param array $data data to fill the template
   * @param int $user_id user id to be added to the logs
   * @return boolean returns true if everything went well
   */

  public function mailing($slug, $emails, $data = [], $user_id = null)
  {

    if (!isset($this->models['mailing'])) exit('_uho_client::mailing::missing_model');
    if (!$emails) return false;

    $mailing = $this->orm->getJsonModel($this->models['mailing'], ['slug' => $slug], true);

    if (!$mailing) exit('_uho_client::mailing::missing_mailing_model::' . $slug);

    $data['website'] = $this->website;
    $data['http'] = $this->getHttpHost();

    $mailing['subject'] = $this->orm->getTwigFromHtml($mailing['subject'], $data);
    $mailing['message'] = $this->orm->getTwigFromHtml($mailing['message'], $data);

    $mailing['message'] = str_replace('{{http}}', $data['http'], $mailing['message']);

    if (!is_array($emails)) $this->mailer->addEmail($emails, true);
    else
      foreach ($emails as $v)
        $this->mailer->addEmail($v, true);

    $this->mailer->addSubject($mailing['subject']);
    if ($mailing['message'][0] == '<')
      $this->mailer->addMessageHtml($mailing['message']);
    else $this->mailer->addMessage($mailing['message']);

    $result = $this->mailer->send();
    $this->addLog('mailing_' . $slug, intval($result), $user_id);
    return $result;
  }

  /**
   * Add logs entry
   *
   * @param string $action action
   * @param string $value additional value
   * @param int $user_id user id
   */
  private function addLog($action, $value, $user_id = null): void
  {
    if (!$user_id && $this->isLogged()) $user_id = $this->getClientId();
    if ($user_id)
      $this->orm->postJsonModel('users_logs', ['user' => $user_id, 'action' => $action, 'value' => $value]);
  }

  /**
   * Simple raw mailer
   * @param string $email destination email
   * @param string $subject message subject
   * @param string $message message body
   * @return null
   */

  public function mailingRaw($email, $subject, $message)
  {
    if (!$this->mailer) exit('mailer not defined');
    $this->mailer->addEmail($email, true);
    $this->mailer->addSubject($subject);
    if ($message[0] == '<')
      $this->mailer->addMessageHtml($message);
    else $this->mailer->addMessage($message);

    $result = $this->mailer->send();
    return $result;
  }

  /**
   * Adds newsletter email to DB
   *
   * @param string $email destination email
   * @param boolean $mailing if true confirmation mail is being sent
   * @param string $url confirmation url
   * @param string $list mailing software list_id
   *
   * @return bool|true[] returns ['result'=>true] if went well
   *
   * @psalm-return array{result: true, mailing: true}|bool
   */
  public function newsletterAdd($email, $mailing = false, $url = null, $list = null): array|bool
  {
    $result = $this->newsletterAddData($email, $list);

    if ($mailing && !empty($result['key_confirm'])) {
      $this->mailing('newsletter_confirmation', $email, ['url' => str_replace('%key%', $result['key_confirm'], $url)]);
      $result = ['result' => true, 'mailing' => true];
    }
    return $result;
  }

  /**
   * Removes newsletter email to DB
   * @param string $token unique token
   * @return boolean
   */

  public function newsletterRemove($key)
  {
    $exists = $this->orm->getJsonModel($this->models['newsletter_users'], ['key_remove' => $key], true);
    if ($exists)
      $this->orm->putJsonModel($this->models['newsletter_users'], ['email' => '', 'key_remove' => '', 'key_confirm' => '', 'status' => 'cancelled'], ['id' => $exists['id']]);
    return $exists;
  }

  /**
   * Semds any currently qued newsletter
   * @return array returns ['result'=>true] if went well
   */

  public function newsletterSend()
  {
    $package_count = 10;
    $issues = $this->orm->getJsonModel('client_newsletter_issues', ['status' => 'sending']);
    $i = [];
    foreach ($issues as $v) $i[] = $v['id'];

    if ($issues) $emails = $this->orm->getJsonModel('client_newsletter_mailing', ['status' => 'waiting', 'issue' => $i], false, 'id', '0,' . $package_count);


    $count = 0;
    $errors = 0;

    if ($emails) {
      foreach ($emails as $v) {
        $i = _uho_fx::array_filter($issues, 'id', $v['issue'], ['first' => true]);
        $user = $this->orm->getJsonModel('client_users_newsletter', ['id' => $v['user'], 'status' => 'confirmed'], true);
        $error = false;
        if ($user) {

          $key_remove = $user['key_remove'];
          if (!$key_remove) {
            $key_remove = $this->uniqid();
            $this->orm->putJsonModel('client_users_newsletter', ['id' => $user['id'], 'key_remove' => $key_remove]);
          }
          $body = $i['body_' . strtoupper($user['lang'])];
          $body = str_replace('%key%', $key_remove, $body);
          $subject = $i['label_' . strtoupper($user['lang'])];
          $result = $this->mailingRaw($user['email'], $subject, $body);
          if (!$result) $error = true;
        } else $error = true;

        if ($error) {
          $this->orm->putJsonModel('client_newsletter_mailing', ['id' => $v['id'], 'status' => 'error']);
          $errors++;
        } else {
          $count++;
          $this->orm->putJsonModel('client_newsletter_mailing', ['id' => $v['id'], 'status' => 'sent']);
        }
      }
    } else {
      foreach ($issues as $v)
        $this->orm->putJsonModel('client_newsletter_issues', ['id' => $v['id'], 'status' => 'sent']);
    }
    return ['result' => true, 'count' => $count, 'error' => $errors];
  }

  /**
   * Adds newsltter to internal database or extennal service
   * @param string $email email to be added
   * @param string $list list_id
   * @return boolean
   */

  public function newsletterAddData($email, $list = null)
  {

    switch (@$this->models['newsletter_type']) {
      case "getresponse":
        $result = $this->newsletterGetResponseAddData($email, $list);
        break;
      default:
        $result = $this->newsletterStandardAddData($email);
        break;
    }
    return $result;
  }

  /**
   * Handles getResponse system subsription
   * @param string $email email to be added
   * @param string $list list_id
   * @return boolean
   */

  private function newsletterGetResponseAddData($email, $list = null)
  {

    if (!isset($this->keys['getresponse']['api_key'])) $message = 'GetResponse API Key not found';
    else {
      if (!$list) $list = @array_shift($this->keys['getresponse']['lists']);
      else $list = @$this->keys['getresponse']['lists'][$list];
      if (!$list) $message = 'GetResponse List not found';
      else
        $result = $this->newsletterGetResponseAddDataToList($this->keys['getresponse']['api_key'], $list, $email);
    }
    if (!$result) return ['result' => false, 'message' => $message];
    return $result;
  }

  /**
   * Handles getResponse system subsription
   *
   * @param string $api_key token
   * @param string $list list_id
   * @param string $email email to be added
   *
   * @return (bool|mixed)[]
   *
   * @psalm-return array{result: bool, message?: mixed}
   */
  private function newsletterGetResponseAddDataToList($api_key, $list_token, $email): array
  {
    $authorization = "X-Auth-Token: api-key " . $api_key;
    $url = 'https://api.getresponse.com/v3/contacts';
    $d = ['email' => $email, 'campaign' => ['campaignId' => $list_token]];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $authorization));
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($d));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = @json_decode(curl_exec($ch), true);
    $response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // 202 --> ok
    // 400 --> error
    // 409 --> exists
    if ($response == 202 || $response == 409) return ['result' => true];
    else return ['result' => false, 'message' => @$result['message']];
  }


  /**
   * Adds newsletter email to internal system
   *
   * @param string $email email to be added
   *
   * @return (bool|mixed|string)[]
   *
   * @psalm-return array{result: bool, message?: 'System error', key_confirm?: mixed|string}
   */
  public function newsletterStandardAddData($email): array
  {

    $result = false;
    $key_confirm = $this->uniqid();

    if (!isset($this->models['newsletter_users'])) exit('_uho_client::newsletterAdd::missing_model');

    $exists = $this->orm->getJsonModel($this->models['newsletter_users'], ['email' => $email], true);

    // new address
    if (!$exists) {
      //echo('insert:'.$this->models['newsletter_users']);
      $result = $this->orm->postJsonModel($this->models['newsletter_users'], ['email' => $email, 'status' => 'submitted', 'groups' => '0001', 'key_confirm' => $key_confirm]);
      if (!$result) return ['result' => false, 'message' => 'System error'];
    }
    // re-activating cancelled address
    elseif ($exists && $exists['status'] == 'cancelled')
      $result = $this->orm->putJsonModel($this->models['newsletter_users'], ['id' => $exists['id'], 'email' => $email, 'status' => 'submitted', 'groups' => '0001', 'key_confirm' => $key_confirm]);

    // adding confirm key if missing
    elseif ($exists && !@$exists['key_confirm'])
      $result = $this->orm->putJsonModel($this->models['newsletter_users'], ['id' => $exists['id'], 'key_confirm' => $key_confirm]);

    // setting confirm key for previously submitted email
    elseif ($exists && $exists['status'] == 'submitted') {
      $result = true;
      $key_confirm = $exists['key_confirm'];
    }

    // false if address exists and is activated
    elseif ($exists) {
      $result = false;
    }

    if ($result)  return ['result' => true, 'key_confirm' => $key_confirm];
    else return ['result' => true];
  }

  /**
   * Adds newsletter confirmation by unique token
   * @param string $key token
   * @return boolean
   */

  public function newsletterConfirmation($key)
  {
    $exists = $this->orm->getJsonModel($this->models['newsletter_users'], ['key_confirm' => $key], true);
    if ($exists) $result = $this->orm->putJsonModel($this->models['newsletter_users'], ['id' => $exists['id'], 'status' => 'confirmed']);
    else $result = false;
    return $result;
  }

  /**
   * Loads favourites data from DB to Session VAR
   */
  private function favouritesLoad(): void
  {
    if ($this->favourites && $this->isLogged()) {
      $t = $this->orm->getJsonModel($this->favourites['model'], ['user' => $this->getClientId()], false);
      $types = [];
      foreach ($t as $v) {
        if (!isset($types[$v['type']])) $types[$v['type']] = [];
        $types[$v['type']][$v['object_id']] = 1;
      }
      $_SESSION['fav'] = $types;
    }
  }

  /**
   * Returns favourites data by type
   *
   * @param string $type data type
   *
   * @return array|null
   *
   * @psalm-return list<mixed>|null
   */
  public function favouritesGet($type)
  {
    if ($this->isLogged()) {
      $items = @$_SESSION['fav'][$type];
      $i = [];
      if ($items)
        foreach ($items as $k => $_)
          $i[] = $k;
      return $i;
    }
  }

  /**
   * Checks if object is in favourites
   * @param string $type data type
   * @param int $id objects' id
   * @return array
   */

  public function favouritesCheck($type, $id)
  {
    if ($this->isLogged()) return @$_SESSION['fav'][$type][$id];
  }

  /**
   * Toggles objects in favourites
   * @param string $type data type
   * @param int $id objects' id
   * @return array
   */

  public function favouritesToggle($type, $id)
  {
    if (!isset($type) || !isset($id) || !$this->isLogged()) return ['result' => false];

    if (isset($_SESSION['fav'][$type][$id])) {
      unset($_SESSION['fav'][$type][$id]);
      if ($this->favourites['model']) $this->orm->deleteJsonModel($this->favourites['model'], ['user' => $this->getClientId(), 'type' => $type, 'object_id' => $id]);
    } else {
      if (!isset($_SESSION['fav'][$type])) $_SESSION['fav'][$type] = [];
      $_SESSION['fav'][$type][$id] = 1;
      if ($this->favourites['model']) $this->orm->postJsonModel($this->favourites['model'], ['user' => $this->getClientId(), 'type' => $type, 'object_id' => $id]);
    }
    return ['result' => true, 'status' => intval(@$_SESSION['fav'][$type][$id])];
  }

  /**
   * Sends user's GDPR expiratiomn email
   *
   * @param int $days_agree number of max days before expiration
   * @param string $mailing_url url to avoid expiration
   * @param int $user user's id
   * @param int $days days from today when expiration occurs
   */
  private function user_gdpr_extension_mailing_send($days_agree, $mailing_url, $user, $days): bool
  {
    $token = $this->generateUserToken('gdpr_extension', '+10 days', $user['id']);

    if (!$token) $result = false;
    else {
      $result = $this->mailing(
        'gdpr_expiry_alert',
        $user['email'],
        [
          'days_agree' => $days_agree,
          'url' => '{{http}}' . str_replace('%token%', $token, $mailing_url),
          'days' => $days
        ],
        $user['id']
      );
    }
    return $result;
  }

  /**
   * Sends user's GDPR exntension email
   *
   * @param int $days_agree number of max days before expiration
   * @param string $mailing_url url to avoid expiration
   *
   * @psalm-return int<0, max>
   */
  public function gdpr_extension_mailing($days_agree, $mailing_url): int
  {
    $alerts = [100, 50, 30, 15, 5, 1];
    $i = 0;
    $date = date('Y-m-d');
    $date = date('Y-m-d', strtotime($date . ' + ' . $alerts[0] . ' days'));

    $users = $this->orm->getJsonModel('users', ['status' => 'confirmed', 'gdpr_expiration_date' => ['operator' => '<=', 'value' => $date]]);
    foreach ($users as $v)
      if (!_uho_fx::getGet('dbg') || $v['email'] == 'lukasz@huncwot.com') {
        $diff = strtotime($v['gdpr_expiration_date']) - strtotime(date('Y-m-d'));
        $diff = round($diff / 86400);
        if (in_array($diff, $alerts)) {
          $f = ['action' => 'mailing_gdpr_expiry_alert', 'user' => $v['id'], 'date' => ['operator' => '%LIKE%', 'value' => date('Y-m-d')]];
          $exists = $this->orm->getJsonModel('users_logs', $f, true);
          if (!$exists) {
            $this->user_gdpr_extension_mailing_send($days_agree, $mailing_url, $v, $diff);
            $i++;
          }
        }
      }
    return $i;
  }

  /**
   * Anonimizes user and sends the email
   *
   * @param int $user user's id
   * @param string $why type of expiration
   * @param boolean if true email is being sent
   */
  private function anonimize($user, $why = 'exipration', bool $mailing = false): void
  {
    // anonimize
    $this->orm->putJsonModel($this->clientModel, ['id' => $user['id'], 'email' => '', 'institution' => '', 'surname' => '', 'uid' => '', 'status' => 'anonimized']);

    // mailing
    if ($mailing && $why == 'expiration') $this->mailing('gdpr_expiry_information', $user['email']);
    elseif ($mailing) $this->mailing('gdpr_remove_information', $user['email']);
  }

  /**
   * Anonimizes all the users whos accounts expired
   * @return int count of anonimized accounts
   */

  public function gdpr_expiration_check()
  {
    $users = $this->orm->getJsonModel('users', ['status' => 'confirmed', 'gdpr_expiration_date' => ['operator' => '<', 'value' => date('Y-m-d')]]);
    foreach ($users as $v)
      $this->anonimize($v, 'expiration', true);
    return count($users);
  }

  /**
   * Returns user's ip
   * @return string
   */

  public function getIp()
  {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
      $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
      $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
  }

  /**
   * Handles start of ePuap login processs
   *
   * @return (false|string)[]|null user's data
   *
   * @psalm-return array{result: false, message: 'oAuth.epuap not found'}|null
   */
  public function loginEpuapStart($type, $sso_return_url, $debug = false)
  {

    //$type = [symulator,int,prod]

    require_once('_uho_client_epuap.php');

    if (!isset($this->oAuth['epuap'])) return ['result' => false, 'message' => 'oAuth.epuap not found'];

    //$java_decrypt_artifact_properties=$this->oAuth['epuap']['artifact_properties'];

    $lib_path = $_SERVER['DOCUMENT_ROOT'] . '/application/_uho/library/epuap/';
    $config_path = $_SERVER['DOCUMENT_ROOT'] . '/application_config/';


    $ePuap = new _uho_client_epuap(
      [
        'type' => $type,
        'debug' => $debug,
        'temp_folder' => $_SERVER['DOCUMENT_ROOT'] . '/temp/',
        'sso_return_url' => $sso_return_url,
        'issuer' => $this->oAuth['epuap']['issuer'],
        'p12_sig_path' => $config_path . $this->oAuth['epuap']['p12_sig'],
        'p12_sig_pass' => $this->oAuth['epuap']['p12_sig_pass'],
        'java_sign_xml' => $lib_path . 'uho_epuap_xml_sig.jar'
      ]
    );

    $auth = $ePuap->authRequest();
    $ePuap->loginRedirect($auth);
  }

  public function loginEpuap($type, $SAMLart, $debug = false, $return_data_only = false): array|bool
  {

    require_once('_uho_client_epuap.php');
    $lib_path = $_SERVER['DOCUMENT_ROOT'] . '/application/_uho/library/epuap/';
    $config_path = $_SERVER['DOCUMENT_ROOT'] . '/application_config/';

    $params = [
      'type' => $type,
      'debug' => $debug,
      'temp_folder' => $_SERVER['DOCUMENT_ROOT'] . '/temp/',
      'sso_return_url' => '',
      'issuer' => $this->oAuth['epuap']['issuer'],
      'p12_sig_path' => $config_path . $this->oAuth['epuap']['p12_sig'],
      'p12_sig_pass' => $this->oAuth['epuap']['p12_sig_pass'],
      'java_sign_xml' => $lib_path . 'uho_epuap_xml_sig.jar',
      'java_decrypt_artifact_properties' => $config_path . $this->oAuth['epuap']['artifact_properties'],
      'java_decrypt_artifact' => $lib_path . 'uho_epuap_du-encryption-tool-1.1.jar'
    ];

    $ePuap = new _uho_client_epuap($params);

    $data = $ePuap->artifactResolve($SAMLart);

    if (isset($data['session_id']) && isset($data['nazwisko'])) {

      if (!$return_data_only) {
        $data = [
          'name' => $data['imie'],
          'surname' => $data['nazwisko'],
          'email' => hash('sha256', 'zd5' . $data['pesel'] . $this->salt['value']),
          'status' => 'confirmed',
          'session_id' => $data['session_id'],
          'session_name_id' => $data['session_name_id'],
          'epuap_id' => hash('sha256', 'ciq' . $data['pesel'] . $this->salt['value']),
          'result' => true
        ];

        $result = $this->register($data);
        if ($result) {
          $this->login(null, null, ['epuap_id' => $data['epuap_id']]);
        }
      } else {
        $data = [
          'name' => $data['imie'],
          'surname' => $data['nazwisko'],
          'session_id' => $data['session_id'],
          'session_name_id' => $data['session_name_id'],
          'epuap_id' => $data['pesel'],
          'result' => true
        ];
      }
    }

    return $data;
  }

  /**
   * Handles start of ePuap logout processs
   * @return boolean
   */

  public function logoutEpuap($type, $sso_return_url, $sessionId, $nameId, $debug = false)
  {

    require_once('_uho_client_epuap.php');
    $lib_path = $_SERVER['DOCUMENT_ROOT'] . '/application/_uho/library/epuap/';
    $config_path = $_SERVER['DOCUMENT_ROOT'] . '/application_config/';

    $ePuap = new _uho_client_epuap(
      [
        'type' => $type,
        'debug' => $debug,
        'temp_folder' => $_SERVER['DOCUMENT_ROOT'] . '/temp/',
        'sso_return_url' => $sso_return_url, ///epuap-sso
        'issuer' => $this->oAuth['epuap']['issuer'],
        'p12_sig_path' => $config_path . $this->oAuth['epuap']['p12_sig'],
        'p12_sig_pass' => $this->oAuth['epuap']['p12_sig_pass'],
        'java_sign_xml' => $lib_path . 'uho_epuap_xml_sig.jar',
        'java_decrypt_artifact_properties' => $config_path . $this->oAuth['epuap']['artifact_properties'],
        'java_decrypt_artifact' => $lib_path . 'uho_epuap_du-encryption-tool-1.1.jar'
      ]
    );

    $result = $ePuap->logout($sessionId, $nameId);
    return $result;
  }

  /**
   * Set user's custom field
   * @param string $field key
   * @param string $value value
   * @return boolean
   */

  public function userSetCustomField($field, $value)
  {

    $id = $this->getId();
    if ($id && in_array($field, $this->userCustomFields)) {
      $result = $this->orm->putJsonModel($this->clientModel, [$field => $value], ['id' => $id]);
      $this->getData(true);
    } else $result = false;
    return $result;
  }

  /**
   * Generates uid string
   * @return string
   */

  private function uniqid()
  {
    return (str_shuffle(str_replace('.', '', uniqid('', true))));
  }

  private function getHttpHost()
  {
    if ($this->http_host) return $this->http_host;
    else return ($this->http . '://' . $_SERVER['HTTP_HOST']);
  }
}
