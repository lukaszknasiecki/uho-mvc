<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides newsletter-related methods for _uho_client
 * Handles newsletter subscriptions, confirmations, and sending
 */

class _uho_client_newsletter
{
  private $orm;
  private $models;
  private $keys;
  private $client;

  /**
   * Class constructor
   * @param object $orm _uho_orm class instance
   * @param array $models model names configuration
   * @param array $keys API keys configuration
   * @param _uho_client $client parent client instance
   */
  function __construct($orm, $models, $keys, $client)
  {
    $this->orm = $orm;
    $this->models = $models;
    $this->keys = $keys;
    $this->client = $client;
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
   */
  public function add($email, $mailing = false, $url = null, $list = null): array|bool
  {
    $result = $this->addData($email, $list);

    if ($mailing && !empty($result['key_confirm'])) {
      $this->client->mailing('newsletter_confirmation', $email, ['url' => str_replace('%key%', $result['key_confirm'], $url)]);
      $result = ['result' => true, 'mailing' => true];
    }
    return $result;
  }

  /**
   * Removes newsletter email from DB
   * @param string $key unique token
   * @return boolean
   */
  public function remove($key)
  {
    $exists = $this->orm->get($this->models['newsletter_users'], ['key_remove' => $key], true);
    if ($exists)
      $this->orm->put(
        $this->models['newsletter_users'],
        ['email' => '', 'key_remove' => '', 'key_confirm' => '', 'status' => 'cancelled'],
        ['id' => $exists['id']]
      );
    return $exists;
  }

  /**
   * Sends any currently queued newsletter
   * @return array returns ['result'=>true] if went well
   */
  public function send(): array
  {
    $package_count = 10;
    $issues = $this->orm->get('client_newsletter_issues', ['status' => 'sending']);
    $i = [];
    foreach ($issues as $v) $i[] = $v['id'];

    if ($issues) $emails = $this->orm->get('client_newsletter_mailing', ['status' => 'waiting', 'issue' => $i], false, 'id', '0,' . $package_count);

    $count = 0;
    $errors = 0;

    if ($emails) {
      foreach ($emails as $v) {
        $issue = _uho_fx::array_filter($issues, 'id', $v['issue'], ['first' => true]);
        $user = $this->orm->get('client_users_newsletter', ['id' => $v['user'], 'status' => 'confirmed'], true);
        $error = false;
        if ($user) {

          $key_remove = $user['key_remove'];
          if (!$key_remove) {
            $key_remove = $this->uniqid();
            $this->orm->put(
              'client_users_newsletter',
              ['id' => $user['id'], 'key_remove' => $key_remove]
            );
          }
          $body = $issue['body_' . strtoupper($user['lang'])];
          $body = str_replace('%key%', $key_remove, $body);
          $subject = $issue['label_' . strtoupper($user['lang'])];
          $result = $this->client->mailingRaw($user['email'], $subject, $body);
          if (!$result) $error = true;
        } else $error = true;

        if ($error) {
          $this->orm->put(
            'client_newsletter_mailing',
            ['id' => $v['id'], 'status' => 'error']
          );
          $errors++;
        } else {
          $count++;
          $this->orm->put(
            'client_newsletter_mailing',
            ['id' => $v['id'], 'status' => 'sent']
          );
        }
      }
    } else {
      foreach ($issues as $v)
        $this->orm->put(
          'client_newsletter_issues',
          ['id' => $v['id'], 'status' => 'sent']
        );
    }
    return ['result' => true, 'count' => $count, 'error' => $errors];
  }

  /**
   * Adds newsletter to internal database or external service
   * @param string $email email to be added
   * @param string $list list_id
   * @return array
   */
  public function addData($email, $list = null): array
  {
    switch (@$this->models['newsletter_type']) {
      case "getresponse":
        $result = $this->getResponseAddData($email, $list);
        break;
      default:
        $result = $this->standardAddData($email);
        break;
    }
    return $result;
  }

  /**
   * Handles getResponse system subscription
   * @param string $email email to be added
   * @param string $list list_id
   * @return array
   */
  private function getResponseAddData($email, $list = null): array
  {
    $message = '';
    $result = false;

    if (!isset($this->keys['getresponse']['api_key'])) $message = 'GetResponse API Key not found';
    else {
      if (!$list) $list = @array_shift($this->keys['getresponse']['lists']);
      else $list = @$this->keys['getresponse']['lists'][$list];
      if (!$list) $message = 'GetResponse List not found';
      else
        $result = $this->getResponseAddDataToList($this->keys['getresponse']['api_key'], $list, $email);
    }
    if (!$result) return ['result' => false, 'message' => $message];
    return $result;
  }

  /**
   * Handles getResponse system subscription to a specific list
   *
   * @param string $api_key token
   * @param string $list_token list_id
   * @param string $email email to be added
   *
   * @return array
   */
  private function getResponseAddDataToList($api_key, $list_token, $email): array
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
    curl_close($ch);
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
   * @return array
   */
  public function standardAddData($email): array
  {
    $result = false;
    $key_confirm = $this->uniqid();

    if (!isset($this->models['newsletter_users'])) return ['result' => false, 'message' => 'newsletter_users model not configured'];

    $exists = $this->orm->get($this->models['newsletter_users'], ['email' => $email], true);

    // new address
    if (!$exists) {
      $result = $this->orm->post($this->models['newsletter_users'], ['email' => $email, 'status' => 'submitted', 'groups' => '0001', 'key_confirm' => $key_confirm]);
      if (!$result) return ['result' => false, 'message' => 'System error'];
    }
    // re-activating cancelled address
    elseif ($exists && $exists['status'] == 'cancelled')
      $result = $this->orm->put(
        $this->models['newsletter_users'],
        ['id' => $exists['id'], 'email' => $email, 'status' => 'submitted', 'groups' => '0001', 'key_confirm' => $key_confirm]
      );

    // adding confirm key if missing
    elseif ($exists && !@$exists['key_confirm'])
      $result = $this->orm->put(
        $this->models['newsletter_users'],
        ['id' => $exists['id'], 'key_confirm' => $key_confirm]
      );

    // setting confirm key for previously submitted email
    elseif ($exists && $exists['status'] == 'submitted') {
      $result = true;
      $key_confirm = $exists['key_confirm'];
    }

    // false if address exists and is activated
    elseif ($exists) {
      $result = false;
    }

    if ($result) return ['result' => true, 'key_confirm' => $key_confirm];
    else return ['result' => true];
  }

  /**
   * Confirms newsletter subscription by unique token
   * @param string $key token
   * @return boolean
   */
  public function confirmation($key)
  {
    $exists = $this->orm->get($this->models['newsletter_users'], ['key_confirm' => $key], true);
    if ($exists) $result = $this->orm->put(
      $this->models['newsletter_users'],
      ['id' => $exists['id'], 'status' => 'confirmed']
    );
    else $result = false;
    return $result;
  }

  /**
   * Generates uid string
   * @return string
   */
  private function uniqid(): string
  {
    return (str_shuffle(str_replace('.', '', uniqid('', true))));
  }
}
