<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides GDPR-related methods for _uho_client
 */

class _uho_client_gdpr
{
  private $orm;
  private $clientModel;
  private $settings;
  private $client;

  /**
   * Class constructor
   * @param object $orm _uho_orm class instance
   * @param string $clientModel client model name
   * @param array $settings settings array
   * @param _uho_client $client parent client instance
   */
  function __construct($orm, $clientModel, $settings, $client)
  {
    $this->orm = $orm;
    $this->clientModel = $clientModel;
    $this->settings = $settings;
    $this->client = $client;
  }

  /**
   * Sends user's GDPR expiration email
   *
   * @param int $days_agree number of max days before expiration
   * @param string $mailing_url url to avoid expiration
   * @param array $user user's data
   * @param int $days days from today when expiration occurs
   */
  private function extensionMailingSend($days_agree, $mailing_url, $user, $days): bool
  {
    $token = $this->client->generateUserToken('gdpr_extension', '+10 days', $user['id']);

    if (!$token) $result = false;
    else {
      $result = $this->client->mailing(
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
   * Sends user's GDPR extension email
   *
   * @param int $days_agree number of max days before expiration
   * @param string $mailing_url url to avoid expiration
   *
   */
  public function extensionMailing($days_agree, $mailing_url): int
  {
    $alerts = [100, 50, 30, 15, 5, 1];
    $i = 0;
    $date = date('Y-m-d');
    $date = date('Y-m-d', strtotime($date . ' + ' . $alerts[0] . ' days'));

    $users = $this->orm->get('users', ['status' => 'confirmed', 'gdpr_expiration_date' => ['operator' => '<=', 'value' => $date]]);
    foreach ($users as $v)
      if (!_uho_fx::getGet('dbg') || $v['email'] == 'lukasz@huncwot.com') {
        $diff = strtotime($v['gdpr_expiration_date']) - strtotime(date('Y-m-d'));
        $diff = round($diff / 86400);
        if (in_array($diff, $alerts)) {
          $f = ['action' => 'mailing_gdpr_expiry_alert', 'user' => $v['id'], 'date' => ['operator' => '%LIKE%', 'value' => date('Y-m-d')]];
          $exists = $this->orm->get('users_logs', $f, true);
          if (!$exists) {
            $this->extensionMailingSend($days_agree, $mailing_url, $v, $diff);
            $i++;
          }
        }
      }
    return $i;
  }

  /**
   * Anonymizes user and sends the email
   *
   * @param array $user user's data
   * @param string $why type of expiration
   * @param boolean $mailing if true email is being sent
   */
  private function anonimize($user, $why = 'expiration', bool $mailing = false): void
  {
    // anonimize
    $this->orm->put($this->clientModel, ['id' => $user['id'], 'email' => '', 'institution' => '', 'surname' => '', 'uid' => '', 'status' => 'anonimized']);

    // mailing
    if ($mailing && $why == 'expiration') $this->client->mailing('gdpr_expiry_information', $user['email']);
    elseif ($mailing) $this->client->mailing('gdpr_remove_information', $user['email']);
  }

  /**
   * Anonymizes all the users whose accounts expired
   * @return int count of anonymized accounts
   */
  public function expirationCheck(): int
  {
    $users = $this->orm->get('users', ['status' => 'confirmed', 'gdpr_expiration_date' => ['operator' => '<', 'value' => date('Y-m-d')]]);
    foreach ($users as $v)
      $this->anonimize($v, 'expiration', true);
    return count($users);
  }
}
