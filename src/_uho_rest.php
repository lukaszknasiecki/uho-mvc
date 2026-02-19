<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides a set of static functions (helpers) for REST APIs
 */

class _uho_rest
{
    private static $initialized = false;

    /**
     * Class constructor
     */
    private static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
    }


    /*
    Helper: Set HTTP Status Full Responses
    */

    public static function setHttpStatusHeader($num)
    {
        $http = array(
            100 => 'HTTP/1.1 100 Continue',
            101 => 'HTTP/1.1 101 Switching Protocols',
            200 => 'HTTP/1.1 200 OK',
            201 => 'HTTP/1.1 201 Created',
            202 => 'HTTP/1.1 202 Accepted',
            203 => 'HTTP/1.1 203 Non-Authoritative Information',
            204 => 'HTTP/1.1 204 No Content',
            205 => 'HTTP/1.1 205 Reset Content',
            206 => 'HTTP/1.1 206 Partial Content',
            300 => 'HTTP/1.1 300 Multiple Choices',
            301 => 'HTTP/1.1 301 Moved Permanently',
            302 => 'HTTP/1.1 302 Found',
            303 => 'HTTP/1.1 303 See Other',
            304 => 'HTTP/1.1 304 Not Modified',
            305 => 'HTTP/1.1 305 Use Proxy',
            307 => 'HTTP/1.1 307 Temporary Redirect',
            400 => 'HTTP/1.1 400 Bad Request',
            401 => 'HTTP/1.1 401 Unauthorized',
            402 => 'HTTP/1.1 402 Payment Required',
            403 => 'HTTP/1.1 403 Forbidden',
            404 => 'HTTP/1.1 404 Not Found',
            405 => 'HTTP/1.1 405 Method Not Allowed',
            406 => 'HTTP/1.1 406 Not Acceptable',
            407 => 'HTTP/1.1 407 Proxy Authentication Required',
            408 => 'HTTP/1.1 408 Request Time-out',
            409 => 'HTTP/1.1 409 Conflict',
            410 => 'HTTP/1.1 410 Gone',
            411 => 'HTTP/1.1 411 Length Required',
            412 => 'HTTP/1.1 412 Precondition Failed',
            413 => 'HTTP/1.1 413 Request Entity Too Large',
            414 => 'HTTP/1.1 414 Request-URI Too Large',
            415 => 'HTTP/1.1 415 Unsupported Media Type',
            416 => 'HTTP/1.1 416 Requested Range Not Satisfiable',
            417 => 'HTTP/1.1 417 Expectation Failed',
            500 => 'HTTP/1.1 500 Internal Server Error',
            501 => 'HTTP/1.1 501 Not Implemented',
            502 => 'HTTP/1.1 502 Bad Gateway',
            503 => 'HTTP/1.1 503 Service Unavailable',
            504 => 'HTTP/1.1 504 Gateway Time-out',
            505 => 'HTTP/1.1 505 HTTP Version Not Supported',
        );

        header($http[$num]);

        return
            array(
                'code' => $num,
                'error' => $http[$num],
            );
    }

    /*
    Helper: Validate Required Method
  */
    public static function validateHttpRequestMethod($method = null, $allowed = [])
    {
        if (empty($method)) $method = $_SERVER['REQUEST_METHOD'];

        if ($method == 'OPTIONS') {
            http_response_code('200');
            echo json_encode(['result' => true]);
            exit();
        }

        return in_array($method, $allowed);
    }

    /*
    Helper: Get Auth Headers
  */

    public static function getAuthorizationHeader()
    {
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }

    /*
    Helper: Get Bearer Token
  */

    public static function getBearerToken()
    {
        $headers = _uho_rest::getAuthorizationHeader();
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /*
    Validate Required Parameters
  */

    public static function validateRequiredInput($data = null, $required = [])
    {
        foreach ($required as $k => $v)
            if (empty($data[$v])) return false;
        return true;
    }

    /*
    Sanitize Input Paramaters
  */

    public static function sanitizeInput($data = null, array $allowed = [])
    {
        $result = [];

        // filter by allowed keys
        foreach ($allowed as $key => $v)
            if (!empty($data[$key])) $result[$key] = $data[$key];

        // sanitize
        return _uho_fx::sanitize_input($result, $allowed);
    }



    /*
    Input validation - Method and Data

    [
                'method' => ['value' => [method], 'supported' => ['GET','POST']],
                'sanitize' =>
                [
                    [
                        'value' => [array],
                        'supported' =>
                        [
                            'label' => 'string'
                            'email' => 'email'
                        ],
                        'required'=>
                        [
                            'email'
                        ]
                    ]
                ]
            ]

  */


    public static function validateRequest(array $data)
    {

        // validate request method
        if (isset($data['method']) && !_uho_rest::validateHttpRequestMethod($data['method']['value'], $data['method']['supported']))
            return ['header' => 405, 'error' => 'Invalid method'];

        // sanitize request data

        if (!empty($data['sanitize'])) {
            foreach ($data['sanitize'] as $k => $v) {
                $data['sanitize'][$k]['value'] = _uho_rest::sanitizeInput($v['value'], $v['supported']);
                // required
                if (!empty($v['required'])) {
                    $val = isset($data['sanitize'][$k]['value']) ? $data['sanitize'][$k]['value'] : null;
                    if (!_uho_rest::validateRequiredInput($val, $v['required']))
                        return ['header' => 401, 'error' => 'Missing required params'];
                }
            }
        }

        return $data;
    }

    /*
    Helper: Google Captcha
  */

    public static function captcha($captcha, $secret)
    {
        if (!$captcha) return ['header' => 400, 'message' => 'Captcha missing'];
        if (!$secret) return ['header' => 500, 'message' => 'Captcha key fot found'];

        $data = array(
            'secret' => $secret,
            'response' => $captcha
        );

        $verify = curl_init();
        curl_setopt($verify, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
        curl_setopt($verify, CURLOPT_POST, true);
        curl_setopt($verify, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($verify, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($verify, CURLOPT_RETURNTRANSFER, true);
        $jsonresponse = curl_exec($verify);
        $responseKeys = json_decode($jsonresponse, true);
        if (intval($responseKeys["success"]) == 1) return true;
        else return ['header' => 400, 'message' => 'Captcha invalid'];
    }
}
