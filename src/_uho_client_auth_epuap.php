<?php

namespace Huncwot\UhoFramework;

/**
 * ePUAP authentication trait for _uho_client
 * Provides ePUAP (Polish government) OAuth login functionality
 */

trait _uho_client_auth_epuap
{
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
   * Handles start of ePuap login processs
   *
   * @return (false|string)[]|null user's data
   *
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
}
