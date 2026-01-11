<?php

namespace Huncwot\UhoFramework;

/**
 * This is the class to connect with
 * LOGIV.GOV.PL (also known as EPuap)
 * 
 * Requires $params array with access data:
 * 'type' - environment type,  prod|symulator                        
 * 'debug' - true|false, outputs comments
 * 'temp_folder' - folder to store temporary processing files
 * 'sso_return_url' - authorizes return URL
 * 'issuer' - name of issuer of the key
 * 'p12_sig_path' - path to P12 access key
 * 'p12_sig_pass' - P12 key password
 * 'java_sign_xml' - path to JAR sigining file: uho_epuap_xml_sig.jar, you can find it in /bin folder
 */

class _uho_client_epuap
{
    public $issuer;
    public $p12_sig_path;
    public $p12_sig_pass;
    public $java_sign_xml;
    public $artifact_resolve_url;
    public $sso_service_url;
    public $java_decrypt_artifact;
    public $java_decrypt_artifact_properties;
    public $sso_logout_url;
    public $sso_return_url;
    private $debug = false;
    private string $sso_service_url_destination;
    private string $temp_folder;

    /**
     * Constructor
     * @param $params
     * @return null
     */

    function __construct($params)
    {
        if ($params['debug']) $this->debug = true;

        if ($params['type'] == 'symulator') {
            $p = [
                'sso_service_url' => 'https://symulator.login.gov.pl/login/SingleSignOnService',
                'artifact_resolve_url' => 'https://symulator.login.gov.pl/login-services/idpArtifactResolutionService',
                'sso_logout_url' => 'https://symulator.login.gov.pl/login-services/singleLogoutService'
            ];
        } elseif ($params['type'] == 'prod') {
            $p = [
                'sso_service_url' => 'https://login.gov.pl/login/SingleSignOnService',
                'artifact_resolve_url' => 'https://login.gov.pl/login-services/idpArtifactResolutionService',
                'sso_logout_url' => 'https://login.gov.pl/login-services/singleLogoutService'
            ];
        } elseif ($params['type'] == 'int') {
            $p = [
                'sso_service_url' => 'https://int.login.gov.pl/login/SingleSignOnService',
                'artifact_resolve_url' => 'https://int.login.gov.pl/login-services/idpArtifactResolutionService',
                'sso_logout_url' => 'https://int.login.gov.pl/login-services/singleLogoutService'
            ];
        } elseif ($params['type'] == 'int2') {
            $p = [
                'sso_service_url' => 'https://int.login.gov.pl/login/SingleSignOnService',
                'sso_service_url_destination' => 'https://login.gov.pl/login/SingleSignOnService',
                'artifact_resolve_url' => 'https://int.login.gov.pl/login-services/idpArtifactResolutionService',
                'sso_logout_url' => 'https://int.login.gov.pl/login-services/singleLogoutService'
            ];
        } else exit('_uho_client_epuap wrong type=' . $params['type']);

        foreach ($p as $k => $v)
            if (empty($params[$k])) $params[$k] = $v;

        $this->sso_service_url = $this->sso_service_url_destination = $params['sso_service_url'];
        if (!empty($params['sso_service_url_destination'])) $this->sso_service_url_destination = $params['sso_service_url_destination'];
        $this->sso_return_url = $params['sso_return_url'];
        $this->sso_logout_url = $params['sso_logout_url'];
        $this->artifact_resolve_url = $params['artifact_resolve_url'];
        $this->issuer = $params['issuer'];
        $this->p12_sig_path = $params['p12_sig_path'];
        $this->p12_sig_pass = $params['p12_sig_pass'];
        $this->java_sign_xml = $params['java_sign_xml'];
        $this->java_decrypt_artifact = @$params['java_decrypt_artifact'];
        $this->java_decrypt_artifact_properties = @$params['java_decrypt_artifact_properties'];
        $this->temp_folder = $params['temp_folder'];
    }


    /**
     * Generuje AuthRequest
     * @return string
     */
    public function authRequest()
    {
        $authRequest = $this->genAuthRequest();
        $signed = $this->signXml($authRequest, 'AuthRequest');
        $result = base64_encode($signed);

        if ($this->debug) {
            //header("Content-type: text/xml");
            //echo $authRequest;
            //echo $signed;
            echo ('<hr><p>1. authRequest created<pre>' . $this->printXml($authRequest) . '</pre></p>');
            echo ('<hr><p>2. authRequest signed <pre>' . $this->printXml($signed) . '</pre></p>');
        }

        return $result;
    }

    /**
     * Podpisuje AuthRequest przy pomocy jar-ki
     *
     * @param $authRequest
     *
     * @return string
     *
     * @psalm-param 'ArtifactResolve'|'AuthRequest'|'LogoutRequest' $element
     */
    private function signXml($xml, string $element)
    {

        $uid = uniqid();
        $tmpXmlFile = $this->temp_folder . $uid . '_' . $element . '_source.xml';
        $tmpXmlSignedFile = $this->temp_folder . $uid . '_' . $element . '_signed.xml';

        file_put_contents($tmpXmlFile, $xml);
        $exe = 'java -jar ' . $this->java_sign_xml . ' "' . $this->p12_sig_path . '" ' . $this->p12_sig_pass . ' "' . $this->issuer . '" "' . $tmpXmlFile . '" "' . $tmpXmlSignedFile . '"' . ' ' . $element;
        if ($this->debug) echo ('[XML Sign process]<pre>' . $exe . '</pre>');

        exec($exe);

        $ret = @file_get_contents($tmpXmlSignedFile);

        return $ret;
    }

    /**
     * Genereuje xml AuthRequest
     * @return mixed
     */
    private function genAuthRequest()
    {
        $xml = new \SimpleXMLElement(
            '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"/>',
            LIBXML_ERR_NONE,
            false,
            'samlp',
            true
        );

        $xml->addAttribute('ID', 'ID_' . sha1(uniqid((string)mt_rand(), true)));
        $xml->addAttribute('Version', '2.0');
        $xml->addAttribute('IssueInstant', date('c'));
        $xml->addAttribute('Destination', $this->sso_service_url_destination);
        $xml->addAttribute('ProtocolBinding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Artifact');
        $xml->addAttribute('AssertionConsumerServiceURL', $this->sso_return_url); // parametr
        $xml->addAttribute('ForceAuthn', 'true');

        $xml->addChild('saml:Issuer', $this->issuer, 'urn:oasis:names:tc:SAML:2.0:assertion');

        $extensions = $xml->addChild('Extensions');
        $extensions->addChild('eidas:SPType', 'public', 'http://eidas.europa.eu/saml-extensions');
        $requestedAttributes = $extensions->addChild(
            'eidas:RequestedAttributes',
            null,
            'http://eidas.europa.eu/saml-extensions'
        );

        $familyName = $requestedAttributes->addChild(
            'eidas:RequestedAttribute',
            null,
            'http://eidas.europa.eu/saml-extensions'
        );
        $familyName->addAttribute('FriendlyName', 'FamilyName');
        $familyName->addAttribute('Name', 'http://eidas.europa.eu/attributes/naturalperson/CurrentFamilyName');
        $familyName->addAttribute('NameFormat', 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri');
        $familyName->addAttribute('isRequired', 'true');

        $firstName = $requestedAttributes->addChild(
            'eidas:RequestedAttribute',
            null,
            'http://eidas.europa.eu/saml-extensions'
        );
        $firstName->addAttribute('FriendlyName', 'FirstName');
        $firstName->addAttribute('Name', 'http://eidas.europa.eu/attributes/naturalperson/CurrentGivenName');
        $firstName->addAttribute('NameFormat', 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri');
        $firstName->addAttribute('isRequired', 'true');

        $dateOfBirth = $requestedAttributes->addChild(
            'eidas:RequestedAttribute',
            null,
            'http://eidas.europa.eu/saml-extensions'
        );
        $dateOfBirth->addAttribute('FriendlyName', 'DateOfBirth');
        $dateOfBirth->addAttribute('Name', 'http://eidas.europa.eu/attributes/naturalperson/DateOfBirth');
        $dateOfBirth->addAttribute('NameFormat', 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri');
        $dateOfBirth->addAttribute('isRequired', 'true');

        $personIdentifier = $requestedAttributes->addChild(
            'eidas:RequestedAttribute',
            null,
            'http://eidas.europa.eu/saml-extensions'
        );
        $personIdentifier->addAttribute('FriendlyName', 'PersonIdentifier');
        $personIdentifier->addAttribute('Name', 'http://eidas.europa.eu/attributes/naturalperson/PersonIdentifier');
        $personIdentifier->addAttribute('NameFormat', 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri');
        $personIdentifier->addAttribute('isRequired', 'true');

        $nameIDPolicy = $xml->addChild('NameIDPolicy');
        $nameIDPolicy->addAttribute('AllowCreate', 'true');
        $nameIDPolicy->addAttribute('Format', 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified');

        $requestedAuthnContext = $xml->addChild('RequestedAuthnContext');
        $requestedAuthnContext->addAttribute('Comparison', 'minimum');
        $requestedAuthnContext->addChild(
            'saml2:AuthnContextClassRef',
            'http://eidas.europa.eu/LoA/low',
            'urn:oasis:names:tc:SAML:2.0:assertion'
        );

        return $xml->saveXML();
    }

    /**
     * Zwraca dane uzytkownika z artefaktu
     * @param $SAMLart
     * @return array|bool
     */
    public function artifactResolve($SAMLart)
    {

        if ($this->debug) echo ('<p>1. Received SAMLART<pre>' . $SAMLart . '</pre></p>');

        $artifactResolve = $this->genArtifactResolve($SAMLart);

        if ($this->debug) echo ('<hr><p>2. Unsigned Artifact Resolve To Send<pre>' . $this->printXml($artifactResolve) . '</pre></p>');

        $artifactResolveSigned = $this->signXml($artifactResolve, 'ArtifactResolve');
        return $this->getArtifactResolved($artifactResolveSigned);
    }

    /**
     * Generuje koperte do rozwiazania artefaktur
     * @param $SAMLart
     * @return mixed
     */
    private function genArtifactResolve($SAMLart)
    {

        $xml = new \SimpleXMLElement(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"/>',
            LIBXML_ERR_NONE,
            false,
            'soap',
            true
        );

        $xml->addChild('Header', '');
        $body = $xml->addChild('Body', '');

        $artifactResolve = $body->addChild('saml2p:ArtifactResolve', null, 'urn:oasis:names:tc:SAML:2.0:protocol');
        $artifactResolve->addAttribute('ID', 'ID_' . sha1(uniqid((string)mt_rand(), true)));
        $artifactResolve->addAttribute('IssueInstant', date('c'));
        $artifactResolve->addAttribute('Version', '2.0');
        $artifactResolve->addChild('saml:Issuer', $this->issuer, 'urn:oasis:names:tc:SAML:2.0:assertion');
        $artifactResolve->addChild('saml2:Artifact', $SAMLart, 'urn:oasis:names:tc:SAML:2.0:protocol');

        return $xml->saveXML();
    }

    /**
     * Pobiera rozwiazanie artefaktu
     *
     * @param $req
     *
     * @return false|string[]
     *
     * @psalm-return array{session_id: string, session_name_id: string, imie: string, nazwisko: string, data_urodzenia: string, pesel: string}|false
     */
    private function getArtifactResolved(string $req): array|false
    {

        $tmpEncryptedArtifactResolved = $this->temp_folder . uniqid() . '_encrypted_artifact_resolved.xml';
        $test = false;

        // uncomment to get real!!!
        if (!$test) {
            $headers = array(
                'Content-type: text/xml',
                'Accept: text/xml',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'SOAPAction: ',
                'Content-length: ' . strlen($req),
            );

            if ($this->debug)
                echo ('<hr><p>3. Signed Artifact Resolve to Send to ' . $this->artifact_resolve_url . '<pre>' . $this->printXml($req) . '</pre></p>');

            $ch = curl_init($this->artifact_resolve_url);

            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1'); // added 2023 because of SSL errors

            $output = curl_exec($ch);
            //echo '[Curl error: ' . curl_error($ch).']';
            curl_close($ch);

            if ($this->debug) echo ('<hr>4. Received answer <pre>' . $this->printXml($output) . '</pre> to file ' . $tmpEncryptedArtifactResolved);

            file_put_contents($tmpEncryptedArtifactResolved, $output);
        } else {
            copy($this->temp_folder . '_encrypted_artifact_resolved_real.xml', $tmpEncryptedArtifactResolved);
        }

        $decryptedXml = $this->decrytpArtifactResolved($tmpEncryptedArtifactResolved);

        // @unlink($tmpEncryptedArtifactResolved);

        if ($this->debug) echo ('<hr><p>6. Decrypted data<pre>' . $this->printXml($decryptedXml) . '</pre>');


        if ($decryptedXml !== false) {
            $loggedUserData = simplexml_load_string($decryptedXml);

            return [
                'session_id' => (string)$loggedUserData->xpath('//saml2:AuthnStatement/@SessionIndex')[0],
                'session_name_id' => (string)$loggedUserData->xpath('//saml2:NameID')[0],
                'imie' => (string)$loggedUserData->xpath("//saml2:AttributeStatement/saml2:Attribute[@FriendlyName='FirstName']/saml2:AttributeValue")[0],
                'nazwisko' => (string)$loggedUserData->xpath("//saml2:AttributeStatement/saml2:Attribute[@FriendlyName='FamilyName']/saml2:AttributeValue")[0],
                'data_urodzenia' => (string)$loggedUserData->xpath("//saml2:AttributeStatement/saml2:Attribute[@FriendlyName='DateOfBirth']/saml2:AttributeValue")[0],
                'pesel' => (string)$loggedUserData->xpath("//saml2:AttributeStatement/saml2:Attribute[@FriendlyName='PersonIdentifier']/saml2:AttributeValue")[0],
            ];
        } elseif ($this->debug) echo ('<hr><p>7. Decrypted RAW data<pre>' . $decryptedXml . '</pre>');


        return false;
    }

    /**
     * Prints XML to readable format
     *
     * @param $xmp
     * @param bool|string $xml
     *
     * @return string
     */
    private function printXml(string|bool $xml)
    {
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = true;
        $dom->loadXML($xml);
        $xml_pretty = $dom->saveXML();
        $result = nl2br(htmlspecialchars($xml_pretty));
        return $result;
    }

    /**
     * Odszyfrowuje rozwiazania artefaktu
     * @param $encryptedXmlFile
     * @return bool|false|string
     */
    private function decrytpArtifactResolved(string $encryptedXmlFile)
    {


        $exe = 'java -jar -Dspring.config.location=' . $this->java_decrypt_artifact_properties . ' ' . $this->java_decrypt_artifact . ' --file=' . $encryptedXmlFile . ' --decryptec';

        if ($this->debug) echo ('<hr><p>5. Decrypt received ArtifactResolved<pre>' . $exe . '</pre></p>');

        exec($exe, $out);

        $decryptedXmlFile = str_replace('.xml', '.decrypted.xml', $encryptedXmlFile);

        if (is_file($decryptedXmlFile)) {
            $ret = file_get_contents($decryptedXmlFile);
            unlink($decryptedXmlFile);
            return $ret;
        }

        return false;
    }

    /**
     * Wylogowanie
     * @param $sessionId
     * @param $nameId
     * @return bool
     */
    public function logout($sessionId, $nameId)
    {
        //echo('logout...'.$sessionId.' | '.$nameId);
        $logoutRequest = $this->genLogoutRequest($sessionId, $nameId);
        $logoutRequestSigned = $this->signXml($logoutRequest, 'LogoutRequest');
        $xml = $this->sendLogoutRequest($logoutRequestSigned);
        $result = false;
        $i = strpos($xml, '<saml2p:StatusCode');
        $j = strpos($xml, '>', $i);
        if ($i && $j) {
            $xml = substr($xml, $i, $j - $i + 1);
            if (strpos($xml, 'urn:oasis:names:tc:SAML:2.0:status:Success')) $result = true;
        }
        return $result;
    }

    /**
     * Generuje koperte do wylogowania
     * @param $sessionId
     * @param $nameId
     * @return mixed
     */
    private function genLogoutRequest($sessionId, $nameId)
    {
        $xml = new \SimpleXMLElement(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"/>',
            LIBXML_ERR_NONE,
            false,
            'soap',
            true
        );

        $xml->addChild('Header', '');
        $body = $xml->addChild('Body', '');

        $logoutRequest = $body->addChild('urn:LogoutRequest', null, 'urn:oasis:names:tc:SAML:2.0:protocol');
        $logoutRequest->addAttribute('ID', 'ID_' . sha1(uniqid((string)mt_rand(), true)));
        $logoutRequest->addAttribute('IssueInstant', date('c'));
        $logoutRequest->addAttribute('Version', '2.0');
        $logoutRequest->addChild('urn1:Issuer', $this->issuer, 'urn:oasis:names:tc:SAML:2.0:assertion');
        $nameID = $logoutRequest->addChild('urn1:NameID', $nameId, 'urn:oasis:names:tc:SAML:2.0:assertion');
        $nameID->addAttribute('Format', 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified');
        $logoutRequest->addChild('urn:SessionIndex', $sessionId, 'urn:oasis:names:tc:SAML:2.0:protocol');

        return $xml->saveXML();
    }

    /**
     * Wysyla koperte do wylogowania
     *
     * @param $req
     */
    private function sendLogoutRequest(string $req): bool|string
    {
        $headers = array(
            'Content-type: text/xml',
            'Accept: text/xml',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'SOAPAction: ',
            'Content-length: ' . strlen($req),
        );

        //echo('<pre>CURL '.$this->sso_logout_url.'</pre>');
        $ch = curl_init($this->sso_logout_url);

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $output = curl_exec($ch);
        curl_close($ch);

        $tmpLogoutResponse = $this->temp_folder . uniqid() . '_decrypted_logout_response.xml';

        file_put_contents($tmpLogoutResponse, $output);

        return $output;
    }

    /**
     * Przekierowuje redirect - funkcja do debugu
     *
     * @param $auth
     * @param $debug
     *
     * @return never
     */
    public function loginRedirect(string $auth, $debug = false)
    {


        if ($this->debug) {
            echo ('<hr><p>3. POST to be sent to <pre>' . $this->sso_service_url . '</pre> with SAMLRequest=<pre>' . $auth . '</pre</p>');
            exit('<hr>
            <FORM METHOD="POST" ACTION="' . $this->sso_service_url . '">
            <INPUT NAME="SAMLRequest" VALUE="' . $auth . '"/>         
            <INPUT TYPE="submit" value="Przeslij">
            </FORM>');
        } else
            exit('<!DOCTYPE html><HTML><BODY Onload="document.forms[0].submit()"> 
        <FORM METHOD="POST" ACTION="' . $this->sso_service_url . '"><INPUT TYPE="HIDDEN" NAME="SAMLRequest" VALUE="' . $auth . '"/>         
        <NOSCRIPT><P>JavaScript jest wyłączony. Rekomendujemy włączenie. Aby kontynuować, proszę nacisnąć przycisk poniżej.</P><INPUT TYPE="SUBMIT" VALUE="Kontynuuj" /></NOSCRIPT>
        </FORM></BODY></HTML>');
    }
}
