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
    private $models_path = '';

    public function setRoutingAuth($items)
    {
        $this->routing['auth'] = $items;
    }
    public function setRoutingNoAuth($items)
    {
        $this->routing['no_auth'] = $items;
    }
    public function setCaptchaAuth($items)
    {
        $this->captcha['auth'] = $items;
    }
    public function setCaptchaNoAuth($items)
    {
        $this->captcha['no_auth'] = $items;
    }
    public function setPathModules($path)
    {
        $this->models_path=$path;
    }

    public function request($method, $action, $data, $url, $cfg)
    {
        if (!empty($cfg['debug'])) $this->sql->setDebug($cfg['debug']);
        $captcha = isset($data['captcha']) ? $data['captcha'] : null;
        $this->allowOptionsHeader();

        // check Auth
        $user_id = null;
        $bearer_token = _uho_rest::getBearerToken();

        if ($bearer_token) {
            $result = $this->validateUserToken($bearer_token);
            if ($result['header'] == 200) $user_id = $result['user'];
            else return $result;
        }

        // resolve Paths
        $input = _uho_fx::resolveRoute($action, $this->routing['no_auth']);
        if (!$input) $input = _uho_fx::resolveRoute($method . '.' . $action, $this->routing['no_auth']);

        if ($user_id) {
            $input_auth = _uho_fx::resolveRoute($action, $this->routing['auth']);
            if (!$input_auth) $input_auth = _uho_fx::resolveRoute($method . '.' . $action, $this->routing['auth']);
        }

        $rest=[];

        if (!empty($input_auth['class']))
        {
            $rest=[
                'class'=>$input_auth['class'],
                'captcha'=>$this->captcha['auth']
            ];
        }
        elseif (!empty($input['class']))
            {
              $rest=[
                'class'=>$input['class'],
                'captcha'=>$this->captcha['no_auth']
                ];  
            }        

        if ($rest)
        {

            if (in_array($rest['class'], $rest['captcha']))
            {
                $result = _uho_rest::captcha($captcha);
                $allowed = ($result === true);
            } else $allowed = true;

            if ($allowed)
            {
                $rest['class'] = str_replace('-', '_', $rest['class']);
                require_once($this->models_path."model_app_api_" . $rest['class'] . ".php");
                $class_name = 'model_app_api_' . $rest['class'];
                $object = new $class_name($this, null);
                if (method_exists($object,$method))
                    $result = $object->$method(null, $input['params'], $data, $cfg);
                    else $result = ['result' => false, 'header' => '404', 'error' => 'Method not supported'];
            } else $result = ['result' => false, 'header' => '404', 'error' => 'Captcha missing'];
        } else
        // unknown path
        {
            $result = null;
        }

        // return 404 if no result

        if (empty($result) && (!isset($result) || $result !== [])) {
            $result = ['result' => false, 'header' => '404', 'error' => 'Unknown API path'];
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

    public function validateUserToken($token = null)
    {
        if (empty($token)) $token = _uho_rest::getBearerToken();

        if (!empty($token) && $token == 'test') {
            $token = $this->get('client_tokens', ['type' => 'session', 'user' => 1], true);
        } elseif (substr($token, 0, 5) == 'user_') {
            return ['header' => 200, 'result' => true, 'message' => 'TEST Authorization valid', 'user' => intval(substr($token, 5))];
        } else
    if (!empty($token))
            $token = $this->get('client_tokens', ['value' => $token, 'expiration' => ['operator' => '>=', 'value' => date('Y-m-d H:i:s')]], true);

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
