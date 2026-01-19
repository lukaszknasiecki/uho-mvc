<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_mailer;
use Huncwot\UhoFramework\_uho_thumb;
use Huncwot\UhoFramework\_uho_fx;
use Huncwot\UhoFramework\_uho_client_favourites;
use Huncwot\UhoFramework\_uho_client_gdpr;
use Huncwot\UhoFramework\_uho_client_newsletter;

/**
 * This class provides CLIENT object methods
 * connected with register, login and various profile settings
 *
 * Methods:
 * - __construct($orm, $settings, $lang = null)
 * - setFields($t): void
 * - setModel($t): void
 * - setKeys($k): void
 * - userSetCustomField($field, $value)
 * - storeDataParam($key, $value): void
 * 
 * - enableCookie($name, $days, $domain, $login = false): void
 * - cookieLogin($force = false): void
 * - cookieLoginStore($id): void
 * - getCookieName()
 * 
 * - getToken($key = '')
 * - validateToken($token, $key = '')
 * - removeUserTokens($user_id, $type = null)
 * - generateUserToken($type, $date, $user_id = 0)
 * - getUserToken($token, $type = null, $remove_if_present = false)
 * 
 * - logsAdd($action, int|null $result = null): void
 * - logsAddLogged($action, $result = null)
 * - logsAddUnlogged($action, $result = null)
 *  
 * - isLogged()
 * - getData($reload = false)
 * - getDataField($field)
 * - getId($reload = false): string|int
 * - getLoggedClient($reload = false)
 * - getClient(array $params, $skip_provider = false, $skip_pass_check = false)
 * - getClientId(bool $reload = false)
 * 
 * - beforeLogin()
 * - beforeLogout()
 * - beforeLoginCallback($data)
 * 
 * - getMaxLoginAttempts()
 * - getRemainingLoginAttempts($login)
 * - login($email, $pass, array|null $params = null)
 * - loginByToken($token)
 * - logout(): bool
 * - loginCheckBan(): bool
 * 
 * - anyUserExists()
 * - adminExists()

 * - create($data, $returnId = false)
 * - createAdmin($login, $pass)
 * - register($data, $url = null): array
 * - registerConfirmation($key)
 * - update($user_id = 0, $data = null)
 * - accountRemoveRequest($password, $url)
 * - accountRemoveByKey($key)
 * 
 * - passwordValidateFormat($pass)
 * - passwordGenerate()
 * - passwordCheck($pass)
 * - passwordCheckExpired($days)
 * - passwordChange($pass, $user = null)
 * - passwordChangeByEmail($email, $url)
 * - passwordSetFirstTime($pass)
 * - passwordChangeByOldPassword($oldpass, $pass)
 * - passwordChangeByKey($key, $pass)
 * 
 * - mailing($slug, $emails, $data = [], $user_id = null)
 * - mailingRaw($email, $subject, $message)
 * 
 * - newsletter(): ?_uho_client_newsletter
 * - newsletterAdd($email, $mailing = false, $url = null, $list = null): array|bool
 * - newsletterRemove($key)
 * - newsletterSend()
 * - newsletterAddData($email, $list = null)
 * - newsletterStandardAddData($email): array
 * - newsletterConfirmation($key)
 * 
 * - favourites(): ?_uho_client_favourites
 * - favouritesGet($type)
 * - favouritesCheck($type, $id)
 * - favouritesToggle($type, $id)
 * 
 * - gdpr(): ?_uho_client_gdpr
 * - gdpr_extension_mailing($days_agree, $mailing_url): int
 * - gdpr_expiration_check(): int
 * 
 * - getIp()
 */

class _uho_client
{
  use _uho_client_auth_facebook;
  use _uho_client_auth_google;
  use _uho_client_auth_epuap;
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
   * Favourites service instance
   */
  private $_uho_client_favourites;
  /**
   * GDPR service instance
   */
  private $_uho_client_gdpr;
  /**
   * Newsletter service instance
   */
  private $_uho_client_newsletter;
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
      if (@$settings['favourites']) {
        $this->favourites = $settings['favourites'];
        $this->_uho_client_favourites = new _uho_client_favourites($orm, $settings['favourites'], fn() => $this->getClientId());
      }
      if (@$settings['settings']['gdpr_days']) {
        $this->_uho_client_gdpr = new _uho_client_gdpr($orm, $this->clientModel, $settings['settings'], $this);
      }
      if (@$settings['models']['newsletter_users'] || @$settings['models']['newsletter_type']) {
        $this->_uho_client_newsletter = new _uho_client_newsletter($orm, $this->models, @$this->keys, $this);
      }
      if (@$settings['settings']['http_host'])  $this->http_host = $settings['settings']['http_host'];

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
    if ($user_id && $this->fieldBadLogin) {
      $data = [$this->fieldBadLogin => 0];
      $this->orm->put($this->clientModel, $data, ['id' => $user_id]);
    }
  }

  /**
   * Removes remaining max login attempts for the user and blocks him
   *
   * @param string $login users login id
   */
  private function removeRemainingLoginAttempts($login): void
  {
    if ($login && $this->fieldBadLogin) {
      $filters = [$this->fieldLogin => $login, 'status' => ['confirmed']];
      $exists = $this->getClient($filters);
      if ($exists && isset($exists[$this->fieldBadLogin])) {
        $i = $exists[$this->fieldBadLogin] + 1;
        $data = [$this->fieldBadLogin => $i];
        if ($this->maxBadLogin && $this->fields['locked'] && $i >= $this->maxBadLogin)
          $data[$this->fields['locked']] = 1;

        $this->orm->put($this->clientModel, $data, ['id' => $exists['id']]);
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
      $this->orm->put($this->clientModel, 
        ['id' => $id, 'cookie_key' => $uid . $this->salt['value']]);
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
    $this->orm->put(
      $this->clientModel,
      ['id' => $id, 'cookie_key' => '']
      );
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

    $data = @$_SESSION[$this->session_key];
    if (!is_array($data)) $data = null;
    if ($reload || (!$data && $this->cookie)) {
      $this->cookieLogin();
      $data = @$_SESSION[$this->session_key];
      if (@$data['id'] && $reload) {
        $data = $this->orm->get($this->clientModel, ['id' => $data['id'], 'status' => 'confirmed'], true);
        $this->storeData($data);
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
   * @param (int|mixed|string|string[])[] $params
   *
   * @return array user's data
   *
   */
  public function getClient(array $params, $skip_provider = false, $skip_pass_check = false)
  {

    $filters = $params;
    $pass = isset($filters['password']) ? $filters['password'] : null;

    unset($filters['password']);

    $t = $this->orm->get($this->clientModel, $filters, true);

    if ($this->salt['type'] == 'double')
      $pass .= @$t[$this->salt['field']];

    if (!$skip_pass_check && (!$pass || empty($t) || !password_verify($pass, $t['password']))) $t = null;

    if ($t) return $t;
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
    
  }

  /**
   * Performs any actions nedded before user is logged out
   * @return null
   */

  public function beforeLogout()
  {
    
  }

  /**
   * Performs any actions nedded before login callback is being run
   * @return null
   */

  public function beforeLoginCallback($data)
  {
    
  }

  private function logAdd($type, $result): void
  {
    if (!empty($this->models['logs']))
      $this->orm->post($this->models['logs'], ['type' => $type, 'result' => $result]);
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
      $this->orm->post($this->models['client_logins_model'], $val);
      $_SESSION['login_session_id'] = $this->orm->getInsertId();
    }

    if ($result && $this->_uho_client_favourites) $this->_uho_client_favourites->load();

    if ($result) {
      if (!isset($message)) $message = 'client_login_success';
      return (array('result' => $result, 'client' => $client, 'message' => $message));
    } else return (array('result' => $result, 'message' => $message));
  }

  public function loginByToken($token)
  {
    $user_id = $this->getUserToken($token);

    if (!empty($user_id))
      $client = $this->orm->get($this->clientModel, ['id' => intval($user_id)], true);
    else $client = null;

    if ($client) {
      $this->storeData($client);
      return true;
    }
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
      $this->orm->post($this->models['client_logs_model']['model'], $data);
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

      $find = $this->orm->get($this->models['client_logs_model']['model'], $f, false, null, null);


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
    $schema = $this->orm->getSchema($this->clientModel);
    $image = _uho_fx::array_filter($schema['fields'], 'field', 'image', ['first' => true]);
    if ($image) {
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
    $schema = $this->orm->getSchema($this->clientModel);
    $image = _uho_fx::array_filter($schema['fields'], 'field', 'image', ['first' => true]);
    if ($image) {
      $destination = $_SERVER['DOCUMENT_ROOT'] . $image['folder'] . '/';
      foreach ($image['images'] as $v)
        @unlink($destination . $v['folder'] . '/' . $uid . '.jpg');
    }
  }

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

    $result = $this->orm->post($this->clientModel, $data);

    if ($result) {
      $user = $this->orm->getInsertId();
      if (isset($data['image']) && $user) {
        if ($this->orm->decodeBase64Image($data['image'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
          $this->orm->uploadBase64Image($this->clientModel, $user, 'image', $data['image']);
        } else $this->setImageFromUrl($data['image'], $user, $data['uid']);
      }
      if ($returnId) $result = $user;

      // use separate token table
      if (!empty($data['key_confirm']) && $this->tokenModel) {
        $days = $this->settings['registration_confirmation_days'] ?? 7;
        $this->orm->post(
          $this->tokenModel,
          [
            'expiration' => date('Y-m-d', strtotime("+" . $days . " days")),
            'user' => $user,
            'value' => $data['key_confirm'],
            'type' => 'registration_confirmation'
          ]
        );
      }
    } else {
      $error = $this->orm->getLastError();
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
    if (!$this->adminExists())
    {
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
    $exists = $this->orm->get($this->clientModel);
    if (!$exists) return false;
    else return true;
  }

  /**
   * Checks if any admin exists in the Database
   * @return boolean returns true if any admin exists
   */

  public function adminExists()
  {
    $exists = $this->orm->get($this->clientModel, ['admin' => 1]);
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
    $result = $this->orm->put($this->clientModel, $data);
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
      $client = $this->orm->get($this->clientModel, ['key_remove' => $key]);
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
        $this->orm->put($this->clientModel, $data);
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

    $sso = (isset($data['facebook_id']) || isset($data['google_id']) || isset($data['epuap_id']));

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
      $fields['id'] = $status['id'];
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
    elseif ($status && $status['status'] == 'confirmed') {
      $result = true;
      $this->update($status['id'], $data);
      $message = 'client_already_registered';
    } 
    // create new user      
    else {
      $data['key_confirm'] = $this->generateToken();
      $data['image_present'] = isset($data['image']) ? 1 : 0;

      $result = $this->create($data);
      // mail for confirmation
      if ($result && !@$sso) {
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
      if ($user) $user = $this->orm->get($this->clientModel, ['id' => $user, 'status' => 'submitted'], true);
    } else {
      $user = $this->orm->get($this->clientModel, ['key_confirm' => $key, 'status' => 'submitted'], true);
    }

    if ($user) {
      $result = $this->orm->put(
        $this->clientModel,
        ['id' => $user['id'], 'status' => 'confirmed']
        );
      $result = ['result' => true, 'user' => $user['id']];
    } else $result = ['result' => false];

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

      $t = $this->orm->get($this->clientModel, $filters, true);

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

      $result = $this->orm->put($this->clientModel, $data);
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

    $exists = $this->orm->get($users, ['email' => $email, 'status' => 'confirmed'], true);
    if (!$exists) return ['result' => false, 'code' => 'user_not_exists'];

    $token = $this->generateUserToken('password_reset', '+10 days', $exists['id']);
    $result = $this->mailing('password_change', $email, ['url' => str_replace('%key%', $token, $url)]);

    if (!$result)
      $result = ['result' => false];
    else $result = ['result' => true];
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

    $exists = $this->orm->get($users, ['key_confirm' => $key, 'status' => 'confirmed'], true);

    if (!$exists) return ['result' => false, 'code' => 'user_not_found'];
    if (!$exists['salt']) $exists['salt'] = substr(bin2hex(random_bytes(32)), 0, 3);
    $set = [
      'id' => $exists['id'],
      'password' => $this->encodePasswordForWrite($pass, $exists['salt']),
      'salt' => $exists['salt'],
      'key_confirm' => ''
    ];

    $result = $this->orm->put($users, $set);
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
    return $this->orm->delete($this->tokenModel, $filters, true);
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

      $result = $this->orm->post($this->tokenModel, $data);
      if ($result) {
        //$exists=$this->orm->get('users_tokens',['token'=>$token,'id'=>$result]);
        //if ($exists) return $token;
        return $token;
      }
    }
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
    $item = $this->orm->get($this->tokenModel, $f, true);

    if ($item && $remove_if_present) $this->orm->delete($this->tokenModel, ['id' => $item['id']]);
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

    $mailing = $this->orm->get($this->models['mailing'], ['slug' => $slug], true);

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
      $this->orm->post('users_logs', ['user' => $user_id, 'action' => $action, 'value' => $value]);
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
   * Returns the newsletter service instance
   * @return _uho_client_newsletter|null
   */
  public function newsletter(): ?_uho_client_newsletter
  {
    return $this->_uho_client_newsletter;
  }

  /**
   * Adds newsletter email to DB
   */
  public function newsletterAdd($email, $mailing = false, $url = null, $list = null): array|bool
  {
    if ($this->_uho_client_newsletter) return $this->_uho_client_newsletter->add($email, $mailing, $url, $list);
    return ['result' => false, 'message' => 'Newsletter service not configured'];
  }

  /**
   * Removes newsletter email from DB
   */
  public function newsletterRemove($key)
  {
    if ($this->_uho_client_newsletter) return $this->_uho_client_newsletter->remove($key);
    return false;
  }

  /**
   * Sends any currently queued newsletter
   * @return array returns ['result'=>true] if went well
   */
  public function newsletterSend()
  {
    if ($this->_uho_client_newsletter) return $this->_uho_client_newsletter->send();
    return ['result' => false, 'count' => 0, 'error' => 0];
  }

  /**
   * Adds newsletter to internal database or external service
   */
  public function newsletterAddData($email, $list = null)
  {
    if ($this->_uho_client_newsletter) return $this->_uho_client_newsletter->addData($email, $list);
    return ['result' => false, 'message' => 'Newsletter service not configured'];
  }

  /**
   * Adds newsletter email to internal system
   */
  public function newsletterStandardAddData($email): array
  {
    if ($this->_uho_client_newsletter) return $this->_uho_client_newsletter->standardAddData($email);
    return ['result' => false, 'message' => 'Newsletter service not configured'];
  }

  /**
   * Confirms newsletter subscription by unique token
   */
  public function newsletterConfirmation($key)
  {
    if ($this->_uho_client_newsletter) return $this->_uho_client_newsletter->confirmation($key);
    return false;
  }

  /**
   * Returns the favourites service instance
   * @return _uho_client_favourites|null
   */
  public function favourites(): ?_uho_client_favourites
  {
    return $this->_uho_client_favourites;
  }

  /**
   * Returns favourites data by type
   *
   * @param string $type data type
   *
   * @return array|null
   */
  public function favouritesGet($type)
  {
    if ($this->_uho_client_favourites) return $this->_uho_client_favourites->get($type);
  }

  /**
   * Checks if object is in favourites
   * @param string $type data type
   * @param int $id objects' id
   * @return mixed
   */
  public function favouritesCheck($type, $id)
  {
    if ($this->_uho_client_favourites) return $this->_uho_client_favourites->check($type, $id);
  }

  /**
   * Toggles objects in favourites
   */
  public function favouritesToggle($type, $id)
  {
    if ($this->_uho_client_favourites) return $this->_uho_client_favourites->toggle($type, $id);
    return ['result' => false];
  }

  /**
   * Returns the GDPR service instance
   * @return _uho_client_gdpr|null
   */
  public function gdpr(): ?_uho_client_gdpr
  {
    return $this->_uho_client_gdpr;
  }

  /**
   * Sends user's GDPR extension email (delegated to _uho_client_gdpr)
   *
   * @param int $days_agree number of max days before expiration
   * @param string $mailing_url url to avoid expiration
   */
  public function gdpr_extension_mailing($days_agree, $mailing_url): int
  {
    if ($this->_uho_client_gdpr) return $this->_uho_client_gdpr->extensionMailing($days_agree, $mailing_url);
    return 0;
  }

  /**
   * Anonymizes all the users whose accounts expired (delegated to _uho_client_gdpr)
   * @return int count of anonymized accounts
   */
  public function gdpr_expiration_check(): int
  {
    if ($this->_uho_client_gdpr) return $this->_uho_client_gdpr->expirationCheck();
    return 0;
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
   * Set user's custom field
   * @param string $field key
   * @param string $value value
   * @return boolean
   */

  public function userSetCustomField($field, $value)
  {

    $id = $this->getId();
    if ($id && in_array($field, $this->userCustomFields)) {
      $result = $this->orm->put($this->clientModel, [$field => $value], ['id' => $id]);
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
      fclose($fp);
    }
  }

}
