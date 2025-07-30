<?php

namespace Huncwot\UhoFramework;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;

/**
 * This is a class dedicated to mailing, works
 *  currently with PHPMailer as its extension
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
   * Set FROM header for the e-mail
   *
   * @param string $name
   * @param string $email
   */
  public function setFrom($name, $email): void
  {
    $this->from = $name . ' <' . $email . '>';
  }

  /**
   * Set REPLY header for the e-mail
   *
   * @param string $name
   * @param string $email
   */
  public function setReply($name, $email): void
  {
    $this->reply = $name . ' <' . $email . '>';
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
   * Send-email
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

    $mail->smtpConnect(
      array(
        "ssl" => array(
          "verify_peer" => false,
          "verify_peer_name" => false,
          "allow_self_signed" => true
        )
      )
    );


    if ($iEmail > 0) $result = $mail->send();
    else $result = true;

    return ($result);
  }
}
