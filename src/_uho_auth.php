<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_mailer;
use Huncwot\UhoFramework\_uho_thumb;
use Huncwot\UhoFramework\_uho_fx;
use Huncwot\UhoFramework\_uho_auth;

class _uho_auth
{
  use _uho_auth_google;

  private $orm;

  private $clientModel = 'client_users';
  private $tokenModel = 'client_tokens';
  private $mailingModel = 'client_mailing';
  private $logsModel = 'client_logs';
  private $website = [];

  private $user = null;
  private $current_token = null;
  private $passwordFormat = '8,1,1,1,1';

  private $oauth = [];
  private $salt = [];
  private $settings = [];
  private $fields = [];

  private $lang = 'en';
  private $session_token = 'client_token';
  private $mailer;


  /**
   * @param object $orm      _uho_orm instance
   * @param array  $settings configuration: mailer, oauth, website, salt
   * @param bool   $login    whether to attempt auto-login on construction
   */
  public function __construct($orm, $settings, $login = true)
  {
    $this->orm = $orm;
    if (isset($settings['mailer'])) $this->mailer = new _uho_mailer(['smtp' => $settings['mailer']['smtp']]);
    $this->oauth = isset($settings['oauth']) ? $settings['oauth'] : null;

    $this->website = isset($settings['website']) ? $settings['website'] : null;
    $this->salt = ['type' => 'double', 'field' => 'salt', 'value' => $settings['salt'] ?? ''];
    $this->settings = [
      'gdpr_days' => 365
    ];

    $this->fields = [
      'login'                => 'email',
      'email'                => 'email',
      'password'             => 'password',
      'password_salt'        => 'salt',
      'date_created'         => 'date_created',
      'date_password'        => 'date_password',
      'date_gdpr_expiration' => 'date_gdpr_expiration',
      'ip'                   => 'ip'
    ];

    if ($login) $this->loginAuto();
  }


  // -------------------------------------------------------------------------
  // Session
  // -------------------------------------------------------------------------


  /**
   * Authenticates a user by e-mail and password, then issues a session cookie.
   *
   * @param string $email
   * @param string $password plain-text password
   * @return array{user: array, token: string}|false user data and token on success, false on failure
   */
  public function login($email, $password)
  {
    $user = $this->getUserByParams([
      $this->fields['password'] => $password,
      $this->fields['email']    => $email,
      'status'                  => 'confirmed',
    ]);

    if ($user) {
      $token = $this->generateUserToken($user['id'], 'session', '+4 hours');
      $this->setLoginToken($token);
      return ['user' => $user, 'token' => $token];
    }

    return false;
  }


  /**
   * Destroys the current session: removes the token record and clears the cookie.
   */
  public function logout(): void
  {
    if ($this->user) {
      $this->removeUserTokens($this->user['id'], 'session');
      $this->current_token = null;
      $this->user = null;
    }
    setcookie($this->session_token, '', time() - 3600, '/');
  }


  /**
   * Returns the current session token value.
   *
   * @return string|null
   */
  public function getCurrentToken()
  {
    return $this->current_token;
  }

  /**
   * Updates user's profile with new data
   * @param int $user_id user's id
   * @param array $data data to be updated
   * @return boolean returns true if any user exists
   */

  public function update($user_id = 0, $data = null)
  {
    if (!$user_id) $user_id = $this->getUserId();

    $data['id'] = $user_id;
    $result = $this->orm->put($this->clientModel, $data);
 
    /*
    $client = $this->getUser();
    if (@$data['image'] == '[remove]')
      $this->removeImage($client['uid']);
    elseif (isset($data['image']))
      $this->setImageFromUrl($data['image'], $user_id, $client['uid']);    
    $this->getData(true);
        */

    return $result!==false;
  }


  // -------------------------------------------------------------------------
  // Registration
  // -------------------------------------------------------------------------


  /**
   * Registers a new user or re-sends confirmation for an existing unconfirmed account.
   *
   * Handles both standard email/password registration and SSO (Facebook, Google, ePUAP).
   * Sends a confirmation e-mail when $url is provided.
   *
   * @param array       $data user fields; must include the login e-mail field
   * @param string|null $url  confirmation URL template containing a '%key%' placeholder
   * @return array{result: bool, message: string, fields: array}
   */
  public function register($data, $url = null): array
  {
    $result = false;

    $sso = (isset($data['facebook_id']) || isset($data['google_id']) || isset($data['epuap_id']));

    if (!isset($data['lang'])) $data['lang'] = $this->lang;
    if (!isset($data['status'])) $data['status'] = 'submitted';

    $exists = $this->getUserByParams([$this->fields['login'] => $data[$this->fields['login']]], false, true);

    $fields = [];

    $data['password'] = isset($data['password']) ? trim($data['password']) : '';
    if (!$data['password']) unset($data['password']);

    if (!$data[$this->fields['email']]) $fields[$this->fields['email']] = 'email_required';
    if (!isset($data['password']) && !$sso) $fields[$this->fields['password']] = 'pass_required';
    elseif (!$sso && strlen($data['password']) < 8) $fields[$this->fields['password']] = 'pass_min8';

    $message = '';

    // validation errors — $fields is non-empty; $result stays false, returned at the end
    if ($fields);

    // unconfirmed account exists — reset status and re-send confirmation mail
    elseif ($exists && $exists['status'] != 'confirmed' && !$sso) {
      $fields['id'] = $exists['id'];
      $this->update($exists['id'], ['status' => 'submitted']);
      $token = $this->generateUserToken($exists['id'], 'registration_confirmation', '+10 days');
      $result = $this->mailing('register_confirmation', $data['email'], ['url' => str_replace('%key%', $token, $url)]);
      $message = $result ? 'client_email_sent' : 'mailing_system_error';
    }

    // unconfirmed SSO account — confirm immediately
    elseif ($exists && $exists['status'] != 'confirmed' && $sso) {
      $data['status'] = 'confirmed';
      $this->update($exists['id'], $data);
      $message = 'client_confirmed';
      $result = true;
    }

    // already confirmed — update profile data
    elseif ($exists && $exists['status'] == 'confirmed') {
      $result = true;
      $this->update($exists['id'], $data);
      $message = 'client_already_registered';
    }

    // new registration
    else {
      $data['key_confirm'] = $this->generateToken();
      $data['image_present'] = isset($data['image']) ? 1 : 0;

      $result = $this->createUser($data);

      if ($result && !$sso) {
        $result = $this->mailing('register_confirmation', $data['email'], ['url' => str_replace('%key%', $data['key_confirm'], $url)]);
        $message = $result ? 'client_email_sent' : 'system_error';
      } elseif ($result) {
        $message = 'client_registered';
      } else {
        $message = 'client_create_error';
      }
    }

    return ['result' => $result, 'message' => $message, 'fields' => $fields];
  }


  /**
   * Confirms a pending registration using the one-time token sent by e-mail.
   *
   * @param string $key registration confirmation token
   * @return array{result: bool, user?: int}
   */
  public function registerConfirmation($key): array
  {
    $user = $this->getUserToken($key, 'registration_confirmation', true);
    if ($user) $user = $this->orm->get($this->clientModel, ['id' => $user, 'status' => 'submitted'], true);

    if ($user) {
      $this->orm->put($this->clientModel, ['id' => $user['id'], 'status' => 'confirmed']);
      return ['result' => true, 'user' => $user['id']];
    }

    return ['result' => false];
  }


  /**
   * Persists a new user record with hashed password, timestamps, and optional profile image.
   *
   * @param array $data user fields; 'password' is hashed in-place before insert
   * @return int|false inserted user ID, or false on failure
   */
  public function createUser($data)
  {
    if (isset($this->fields['ip'])) $data[$this->fields['ip']] = $this->getIp();
    if (isset($this->fields['date_created'])) $data[$this->fields['date_created']] = date('Y-m-d H:i:s');
    if (isset($this->fields['date_password'])) $data[$this->fields['date_password']] = date('Y-m-d H:i:s');

    if (!empty($this->settings['gdpr_days']))
      $data[$this->fields['date_gdpr_expiration']] = date('Y-m-d', strtotime('+' . $this->settings['gdpr_days'] . ' days'));

    $data['uid'] = $this->uniqid();

    if (isset($data['password'])) {
      $pass_params = $this->encodePasswordParams($data['password']);
      $data[$this->fields['password_salt']] = $pass_params['salt'];
      $data[$this->fields['password']] = $pass_params['password'];
    }

    $result = $this->orm->post($this->clientModel, $data);

    if ($result) {
      $user = $this->orm->getInsertId();

      if (isset($data['image']) && $user) {
        if ($this->orm->decodeBase64Image($data['image'], ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
          $this->orm->uploadBase64Image($this->clientModel, $user, 'image', $data['image']);
        } else {
          $this->setImageFromUrl($data['image'], $user, $data['uid']);
        }
      }

      if (!empty($data['key_confirm']) && $this->tokenModel) {
        $days = $this->settings['registration_confirmation_days'] ?? 7;
        $this->orm->post($this->tokenModel, [
          'expiration' => date('Y-m-d', strtotime('+' . $days . ' days')),
          'user'       => $user,
          'value'      => $data['key_confirm'],
          'type'       => 'registration_confirmation',
        ]);
      }

      $result = $user;
    }

    return $result;
  }


  // -------------------------------------------------------------------------
  // User
  // -------------------------------------------------------------------------


  /**
   * Returns the currently authenticated user record, or null if not logged in.
   *
   * @return array|null
   */
  public function getUser(): ?array
  {
    return $this->user;
  }


  /**
   * Returns the currently authenticated user ID, or null if not logged in.
   *
   * @return int|null
   */
  public function getUserId(): ?int
  {
    return $this->user['id'] ?? null;
  }


  /**
   * Fetches a user record matching the given parameters.
   *
   * Strips the 'password' key from $params before querying, then verifies
   * the plain-text password against the stored hash unless $skip_pass_check is true.
   *
   * @param array $params          field => value filters; may include 'password'
   * @param bool  $skip_provider   reserved for SSO flows (unused internally)
   * @param bool  $skip_pass_check skip password verification (e.g. auto-login via cookie)
   * @return array|null user row, or null when not found or credentials are wrong
   */
  public function getUserByParams(array $params, $skip_provider = false, $skip_pass_check = false)
  {
    $filters = $params;
    $pass = isset($filters['password']) ? $filters['password'] : null;
    unset($filters['password']);

    $t = $this->orm->get($this->clientModel, $filters, true);

    if ($t) {
      $pass = trim($pass . $this->salt['value'] . $t[$this->salt['field']]);
      if (!$skip_pass_check && (!$pass || !password_verify($pass, $t['password']))) $t = null;
    }

    if ($t) return $t;
  }


  // -------------------------------------------------------------------------
  // Tokens
  // -------------------------------------------------------------------------


  /**
   * Creates a new token record for the given user, removing any existing tokens of the same type first.
   *
   * @param int    $user_id owner user ID
   * @param string $type    token type (e.g. 'session', 'registration_confirmation')
   * @param string $date    absolute datetime or relative offset (e.g. '+4 hours')
   * @return string|null generated token value, or null on DB failure
   */
  public function generateUserToken($user_id, $type, $date): ?string
  {
    $this->removeUserTokens($user_id, $type);

    if ($date[0] == '+') $date = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' ' . $date));

    $token = $this->generateToken();
    $result = $this->orm->post($this->tokenModel, [
      'user'       => $user_id,
      'expiration' => $date,
      'type'       => $type,
      'value'      => $token,
    ]);

    return $result ? $token : null;
  }


  // -------------------------------------------------------------------------
  // Password
  // -------------------------------------------------------------------------


  /**
   * Validates a password against the minimum format requirements.
   *
   * @param string $pass password to validate
   * @return array{password: string, errors: array, result: bool}
   */
  public function passwordValidateFormat($pass)
  {
    $errors = [];
    $format = $this->passwordFormat;
    $pass = trim($pass);
    $pass = str_replace(' ', '', $pass);
    $special = '^!$%&*()}{@#~?,|=_+-';

    if (strlen($pass) < $format[0]) $errors[] = ['min_length', $format[0]];
    if (preg_match_all('/[a-z]/', $pass) < $format[1]) $errors[] = ['min_lower', $format[1]];
    if (preg_match_all('/[A-Z]/', $pass) < $format[2]) $errors[] = ['min_upper', $format[2]];
    if (preg_match_all('/[0-9]/', $pass) < $format[3]) $errors[] = ['min_numbers', $format[3]];
    if (preg_match_all('/[' . preg_quote($special, '/') . ']/', $pass) < $format[4]) $errors[] = ['min_special', $format[4]];

    return ['password' => $pass, 'errors' => $errors, 'result' => count($errors) == 0];
  }


  /**
   * Checks whether a plain-text string matches the current user's stored password.
   *
   * @param string $pass password to verify
   * @return bool true if the password matches
   */
  public function passwordCheck($pass)
  {
    if ($pass && $this->user) {
      $user = $this->getUserByParams(['id' => $this->user['id'], 'password' => $pass]);
      if ($user) return true;
    }
    return false;
  }


  /**
   * Changes the password for the given (or current) user.
   *
   * @param string   $password new plain-text password
   * @param int|null $user     user ID; defaults to the currently authenticated user
   * @return array{result: bool, message?: string}
   */
  public function passwordChange($password, $user = null)
  {
    $validate = $this->passwordValidateFormat($password);
    if (!$validate['result'])
      return ['result' => false, 'message' => 'client_password_invalid_format'];

    if (!$user) {
      $data = $this->getUser();
      if ($data) $user = $data['id'];
    }

    if ($user) {
      $data = ['id' => $user];
      $pass_params = $this->encodePasswordParams($password);
      if (isset($this->fields['date_password'])) $data[$this->fields['date_password']] = date('Y-m-d H:i:s');
      $data[$this->fields['password_salt']] = $pass_params['salt'];
      $data[$this->fields['password']] = $pass_params['password'];

      $result = $this->orm->put($this->clientModel, $data);
      if ($result !== false) return ['result' => true];
      else return ['result' => false, 'message' => 'system_error'];
    }

    return ['result' => false, 'message' => 'client_user_not_found'];
  }


  /**
   * Changes the current user's password after verifying the old one.
   *
   * @param string $oldpass current plain-text password for verification
   * @param string $pass    new plain-text password
   * @return array{result: bool, message?: string}
   */
  public function passwordChangeByOldPassword($oldpass, $pass)
  {
    $validate = $this->passwordCheck($oldpass);
    if (!$validate)
      return ['result' => false, 'message' => 'client_old_password_wrong'];

    return $this->passwordChange($pass);
  }


  // -------------------------------------------------------------------------
  // Private — session
  // -------------------------------------------------------------------------


  /**
   * Attempts to restore the session from the session cookie.
   */
  private function loginAuto(): void
  {
    if (!empty($_COOKIE[$this->session_token])) {
      $token = $_COOKIE[$this->session_token];
      if ($token) {
        $this->current_token = $token;
        $user_id = $this->getUserIdByToken($token, 'session');
        $this->user = $this->getUserByParams(['id' => $user_id], false, true);
      }
    }
  }


  /**
   * Writes the session token into a cookie (4-hour TTL).
   *
   * @param string $token
   */
  private function setLoginToken($token): void
  {
    setcookie($this->session_token, $token, time() + 4 * 60 * 60, '/');
  }


  // -------------------------------------------------------------------------
  // Private — tokens
  // -------------------------------------------------------------------------


  /**
   * Looks up a token record and returns the associated user ID.
   *
   * @param string      $token             token value to look up
   * @param string|null $type              optional token type filter
   * @param bool        $remove_if_present delete the token record after retrieval
   * @return int|null user ID, or null when not found or expired
   */
  private function getUserIdByToken($token, $type = null, $remove_if_present = false)
  {
    $f = ['value' => $token, 'expiration' => ['operator' => '>=', 'value' => date('Y-m-d H:i:s')]];
    if ($type) $f['type'] = $type;

    $item = $this->orm->get($this->tokenModel, $f, true);

    if ($item && $remove_if_present) $this->orm->delete($this->tokenModel, ['id' => $item['id']]);

    return $item ? $item['user'] : null;
  }


  /**
   * Deletes all token records belonging to a user, optionally filtered by type.
   *
   * @param int         $user_id
   * @param string|null $type token type; null removes all types
   */
  private function removeUserTokens($user_id, $type = null): void
  {
    $filters = ['user' => $user_id];
    if (!empty($type)) $filters['type'] = $type;
    $this->orm->delete($this->tokenModel, $filters, true);
  }


  /**
   * Generates a cryptographically secure random token (SHA-256 hex string).
   *
   * @return string 64-character hex token
   */
  private function generateToken(): string
  {
    return hash('sha256', $this->base64url(random_bytes(32)));
  }


  // -------------------------------------------------------------------------
  // Private — password
  // -------------------------------------------------------------------------


  /**
   * Generates a random per-user salt and returns it alongside the hashed password.
   *
   * @param string $password plain-text password
   * @return array{salt: string, password: string}
   */
  private function encodePasswordParams($password): array
  {
    $salt = substr(bin2hex(random_bytes(32)), 0, 3);
    return [
      'salt'     => $salt,
      'password' => $this->encodePassword($password, true, $salt)
    ];
  }


  /**
   * Hashes a plain-text password using the global salt and a per-user salt.
   *
   * @param string      $pass   plain-text password
   * @param bool        $filter unused (reserved)
   * @param string|null $salt   per-user salt appended before hashing
   * @return string bcrypt hash
   */
  private function encodePassword($pass, $filter = false, $salt = null): string
  {
    $pass = trim($pass . $this->salt['value'] . $salt);
    return password_hash($pass, PASSWORD_DEFAULT);
  }


  // -------------------------------------------------------------------------
  // Private — helpers
  // -------------------------------------------------------------------------


  /**
   * Sends a templated e-mail via the configured mailer and writes an action log entry.
   *
   * @param string   $slug    mailing template slug (looked up in $mailingModel)
   * @param mixed    $emails  recipient address or array of addresses
   * @param array    $data    template variables merged with website metadata
   * @param int|null $user_id user ID written to the log; null for anonymous actions
   * @return bool true when the mailer reports success
   */
  private function mailing($slug, $emails, $data = [], $user_id = null): bool
  {
    if (empty($this->mailingModel)) exit('_uho_client::mailing::missing_model');
    if (!$emails) return false;

    $mailing = $this->orm->get($this->mailingModel, ['slug' => $slug], true);
    if (!$mailing) exit('_uho_auth::mailing::missing_mailing_model::' . $slug);

    $data['website'] = $this->website['title'];
    $data['http'] = $this->website['http'];

    $mailing['subject'] = $this->orm->getTwigFromHtml($mailing['subject'], $data);
    $mailing['message'] = $this->orm->getTwigFromHtml($mailing['message'], $data);
    $mailing['message'] = str_replace('{{http}}', $data['http'], $mailing['message']);

    if (!is_array($emails)) {
      $this->mailer->addEmail($emails, true);
    } else {
      foreach ($emails as $v) $this->mailer->addEmail($v, true);
    }

    $this->mailer->addSubject($mailing['subject']);

    if ($mailing['message'][0] == '<') $this->mailer->addMessageHtml($mailing['message']);
    else $this->mailer->addMessage($mailing['message']);

    $result = $this->mailer->send();
    $this->addLog('mailing_' . $slug, intval($result), $user_id);

    return $result;
  }


  /**
   * Writes an action entry to the logs table.
   *
   * @param string   $action  action identifier (e.g. 'mailing_register_confirmation')
   * @param int      $value   result value (typically 0 or 1)
   * @param int|null $user_id associated user ID; defaults to 0 for anonymous actions
   */
  private function addLog($action, $value, $user_id = null): void
  {
    $this->orm->post($this->logsModel, [
      'timestamp' => date('Y-m-d H:i:s'),
      'user'      => $user_id ?? 0,
      'action'    => $action,
      'value'     => $value,
    ]);
  }


  /**
   * URL-safe Base64 encodes binary data (no padding).
   *
   * @param string $bin raw binary string
   * @return string Base64url-encoded string
   */
  private function base64url(string $bin): string
  {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
  }


  /**
   * Returns the client's IP address, respecting common proxy headers.
   *
   * @return string
   */
  private function getIp(): string
  {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
    return $_SERVER['REMOTE_ADDR'];
  }


  /**
   * Generates a randomised unique identifier string.
   *
   * @return string
   */
  private function uniqid(): string
  {
    return str_shuffle(str_replace('.', '', uniqid('', true)));
  }
}
