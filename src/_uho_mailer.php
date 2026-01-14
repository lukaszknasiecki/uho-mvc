<?php

namespace Huncwot\UhoFramework;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;

/**
 * This is a class dedicated to mailing, works
 *  currently with PHPMailer as its extension
 *
 * Available methods:

 * - setOauth($data): void                       - Set OAuth verification (currently supports Google)
 * - setDebug($debug): void                      - Set debug option (based on PHPMailer debug levels, 3 is most detailed)
 * - setSMTP($server, $port, $login, $pass): bool - Set SMTP server access credentials
 * - getSMTP()                                   - Get SMTP configuration array
 * - addMessage($message): void                  - Add plain text message to the email
 * - addMessageHtml($message): void              - Add HTML message to the email
 * - addSubject($subject): void                  - Set subject for the email
 * - addEmail($email, $remove = false)           - Add single email address (optionally remove previous addresses)
 * - addEmails($emails, $remove = false)         - Add multiple email addresses (optionally remove previous addresses)
 * - send()                                      - Send email using previously set SMTP, email, subject and messages
 */

class _uho_mailer
{
  private $debug;
  private $oAuth;
  private $cfg, $subject, $messageHtml, $message;
  private $emails;

  /**
   * Constructor
   * @param array $cfg
   * @return null
   */

  function __construct($cfg = null)
  {
    $this->emails = array();
    if (!$cfg) $cfg = array();
    $this->cfg = $cfg;

    if (isset($cfg['smtp']['oAuth'])) $this->setOauth($cfg['smtp']['oAuth']);
    if (isset($cfg['oAuth'])) $this->setOauth($cfg['oAuth']);
  }

  /*
    Set oAuth verification
    support currently google
  */

  public function setOauth($data): void
  {
    if ($data['provider'] == 'google')
      $data['provider'] = new Google(
        [
          'clientId' => $data['clientId'],
          'clientSecret' => $data['clientSecret'],
        ]
      );
    $this->oAuth = $data;
  }

  /**
   * Set debug option
   *
   * @param integer $debug based on PHPMailer debug where 3 is the most detailed one
   */
  public function setDebug($debug): void
  {
    $this->debug = $debug;
  }

  /**
   * Set SMTP server access
   *
   * @param string $server
   * @param int $port
   * @param string $login
   * @param string $pass
   *
   * @return true
   */
  function setSMTP($server, $port, $login, $pass): bool
  {
    $this->cfg['smtp'] = array('server' => $server, 'port' => $port, 'login' => $login, 'pass' => $pass);
    return true;
  }

  /**
   * @return array
   */
  function getSMTP()
  {
    return (isset($this->cfg['smtp'])) ? $this->cfg['smtp'] : null;
  }

  /**
   * Add message to the e-mail (plain text)
   *
   * @param string $message
   */
  public function addMessage($message): void
  {
    $this->message = $message;
  }

  /**
   * Add HTML message to the e-mail
   *
   * @param string $message
   */
  public function addMessageHtml($message): void
  {
    $this->messageHtml = $message;
  }

  /**
   * Set SUBJECT for the e-mail
   *
   * @param string $subject
   */
  public function addSubject($subject): void
  {
    $this->subject = $subject;
  }

  /**
   * Add e-mail address to the e-mail
   * @param string $email
   * @param boolean $remove remove previosly added e-mail addresses
   * @return boolean
   */

  public function addEmail($email, $remove = false)
  {
    if ($remove) $this->emails = array();
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
      array_push($this->emails, $email);
      return true;
    } else return false;
  }

  /**
   * Add e-mail addresses to the e-mail
   * @param array $emails
   * @param boolean $remove remove previosly added e-mail addresses
   * @return boolean
   */

  public function addEmails($emails, $remove = false)
  {
    $result = true;
    if ($remove) $this->emails = array();
    foreach ($emails as $v)
      if (filter_var($v, FILTER_VALIDATE_EMAIL))
        array_push($this->emails, $v);
      else $result = false;
    return $result;
  }

  /**
   * Send-email using previousle set SMTP, email, subject and messages
   * @return boolean
   */

  public function send()
  {
    
    if (!$this->cfg['smtp']) return;
    $mail = new PHPMailer(true);
    $mail->CharSet = "UTF-8";
    $mail->isSMTP();                                      // Set mailer to use SMTP
    $mail->SMTPAuth = true;

    $mail->Host = $this->cfg['smtp']['server'];
    $mail->Port = $this->cfg['smtp']['port'];

    if (isset($this->cfg['smtp']['pass'])) {
      $mail->Username = $this->cfg['smtp']['login'];
      $mail->Password = $this->cfg['smtp']['pass'];
    }
    if ($this->oAuth) {
      $mail->AuthType = 'XOAUTH2';
      $mail->setOAuth(new OAuth($this->oAuth));
    }

    if ($this->cfg['smtp']['secure']) $mail->SMTPSecure = $this->cfg['smtp']['secure'];

    $mail->From = $this->cfg['smtp']['fromEmail'];
    $mail->FromName = $this->cfg['smtp']['fromName'];
    $mail->Subject = $this->subject;

    if (isset($this->messageHtml)) {
      $mail->IsHTML(true);
      $mail->Body = $this->messageHtml;
      if (isset($this->message)) $mail->AltBody = strip_tags($this->message);
      else $mail->AltBody = strip_tags($this->messageHtml);
    } else {
      $mail->Body    = nl2br($this->message);
      $mail->AltBody = $this->message;
    }

    $iEmail = 0;
    foreach ($this->emails as $v)
      if ($v) {
        $mail->addAddress($v);
        $iEmail++;
      }

    if ($this->debug) $mail->SMTPDebug = $this->debug;

    try
    {
      $mail->smtpConnect(
        array(
          "ssl" => array(
            "verify_peer" => false,
            "verify_peer_name" => false,
            "allow_self_signed" => true
          )
        )
      );
    }
    catch (Exception $e)
    {
      return false;
    }

    if ($iEmail > 0) $result = $mail->send();
    else $result = true;

    return ($result);
  }
}
