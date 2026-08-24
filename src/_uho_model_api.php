<?php

/*
    This class extends _uho_model with API methods
*/

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_model;

class _uho_model_api extends _uho_model
{

    private $routing = [
        'no_auth' => [],
        'auth' => []
    ];
    private $captcha = [
        'no_auth' => [],
        'auth' => []
    ];

    private $skip_wrong_token=true;
    private $models_path = '';
    private $allow_form_bearer_token=false;
    public $path = [];


    public function setRoutingAuth($items)
    {
        $this->routing['auth'] = $items;
    }
    public function setRoutingNoAuth($items)
    {
        $this->routing['no_auth'] = $items;
    }
    public function setPathModels($path)
    {
        $this->models_path = $path;
    }
    public function setFormBearerToken(bool $allow)
    {
        $this->allow_form_bearer_token = $allow;
    }

    public function setSkipWrongToken(bool $skip)
    {
        $this->skip_wrong_token = $skip;
    }

    public function request($method, $action, $data, $cfg)
    {
        $this->path=explode('/',$action);

        if (!empty($cfg['debug'])) $this->sql->setDebug($cfg['debug']);
        $captcha = isset($data['captcha']) ? $data['captcha'] : null;

        $this->allowOptionsHeader();

        // check Auth
        $user_id = null;
        $bearer_token = _uho_rest::getBearerToken();
        
        if ($this->allow_form_bearer_token && !$bearer_token && !empty($data['token'])) $bearer_token = $data['token'];

        if ($bearer_token) {
            $result = $this->validateUserToken($bearer_token);
            if ($result['header'] == 200) $user_id = $result['user'];
            elseif ($this->skip_wrong_token) $user_id=null;
            else return $result;
        }

        // resolve Paths
        $input = _uho_fx::resolveRoute($action, $this->routing['no_auth']);
        if (!$input) $input = _uho_fx::resolveRoute($method . '.' . $action, $this->routing['no_auth']);

        $input_auth = null;
        if ($user_id) {
            $input_auth = _uho_fx::resolveRoute($action, $this->routing['auth']);
            if (!$input_auth) $input_auth = _uho_fx::resolveRoute($method . '.' . $action, $this->routing['auth']);
        }

        $rest = [];

        if (!empty($input_auth['class'])) {
            $rest = [
                'class' => $input_auth['class'],
                'params' => $input_auth['params'],
                'captcha' => $this->captcha['auth']
            ];
        } elseif (!empty($input['class'])) {
            $rest = [
                'class' => $input['class'],
                'params' => $input['params'],
                'captcha' => $this->captcha['no_auth']
            ];
        }

        if ($rest) {
            
            $requires_captcha = false;
            $requires_turnstile = false;

            $rest['class'] = str_replace('-', '_', $rest['class']);

            require_once($this->models_path . "model_app_api_" . $rest['class'] . ".php");
            $class_name = 'model_app_api_' . $rest['class'];
            $object = new $class_name($this, null);

            // Check #[RequiresCaptcha] #[RequiresTurnstile] attribute on the specific HTTP method
            if (!$requires_captcha && method_exists($object, $method)) {
                $reflection = new \ReflectionMethod($object, $method);
                $requires_captcha = !empty($reflection->getAttributes(\Huncwot\UhoFramework\Attributes\RequiresCaptcha::class));
                $requires_turnstile = !empty($reflection->getAttributes(\Huncwot\UhoFramework\Attributes\RequiresTurnstile::class));                
            }

            $allowed = true;

            if ($requires_captcha) {
                $result = _uho_rest::captcha($captcha, $this->getApiKey('google_recaptcha', 'private'));
                $allowed = $allowed && ($result === true);
            };
            if ($requires_turnstile) {
                $result = _uho_rest::turnstile($captcha, $this->getApiKey('turnstile', 'private'));
                $allowed = $allowed && ($result === true);
            };

            if ($allowed) {

                if (method_exists($object, $method)) {

                    // handle validation / required fields if defined

                    if ($object instanceof _uho_model_api_endpoint)
                    {
                        $validation = _uho_rest::validateRequest([
                            'sanitize' => [
                                [
                                    'value'     => array_merge($data,$rest['params']),
                                    'supported' => $object->getSupported($method),
                                    'required'  => $object->getRequired($method)
                                ]
                            ]
                        ]);

                        if (!empty($validation['header'])) return $validation;

                        $result = $object->$method($validation['sanitize'][0]['value'],$cfg);
                        

                    }
                    else
                    {
                        $result = $object->$method(null, $rest['params'], $data, $cfg);
                    }
                } else $result = ['result' => false, 'header' => '404', 'error' => 'Method not supported'];
            } else $result = ['result' => false, 'header' => '404', 'error' => 'Captcha missing'];
        } else
        // unknown path
        {
            $result = null;
        }

        // return 404 if no result

        if (empty($result) && (!isset($result) || $result !== [])) {
            $result = ['result' => false, 'header' => '404', 'error' => 'Unknown API path','authorized'=>$user_id?true:false];
        }

        if (isset($result['header'])) {
            _uho_rest::setHttpStatusHeader($result['header']);
            unset($result['header']);
        }

        return $result;
    }

    /*
    Helper: Remove Cached Files
    */

    public function cacheApiKill($dir = 'cache')
    {
        if ($dir) {
            $dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . trim($dir, '/');
            $scan = @scandir($dir);
            if ($scan)
                foreach ($scan as $item) {
                    $path_parts = pathinfo($item);
                    if ($item == '.' || $item == '..' || $path_parts['extension'] != 'cache') continue;
                    unlink($dir . DIRECTORY_SEPARATOR . $item);
                }
        }
    }

    /*
    Helper: Validate User's Token
  */

    public function validateUserToken($token = null, $type = 'session')
    {
        if (empty($token)) $token = _uho_rest::getBearerToken();

        if (!empty($token))
            $token = $this->get('client_tokens', ['value' => $token, 'type' => $type, 'expiration' => ['operator' => '>=', 'value' => date('Y-m-d H:i:s')]], true);

        if (!empty($token))
            $result = ['header' => 200, 'result' => true, 'message' => 'Authorization valid', 'user' => intval($token['user'])];
        else $result = ['header' => 401, 'error' => 'Authorization not valid'];

        return $result;
    }

    /*
    Helper: Options header
  */

    public function allowOptionsHeader()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
        if ($method == 'OPTIONS') {
            http_response_code('200');
            echo json_encode(['result' => true]);
            exit();
        }
    }
}
