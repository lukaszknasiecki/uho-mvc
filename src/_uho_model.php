<?php

namespace Huncwot\UhoFramework;

/**
 * This is the main Model class
 */

class _uho_model
{
    /**
     * instance of _uho_mysqli
     */
    public  $sql;
    /**
     * current language shortcut
     */
    public $lang;
    /**
     * current language prefix
     */
    public $lang_add;
    /**
     * current config_folder
     * usually 'application_folder'
     */
    public $config_folder;
    /**
     * instance of _uho_orm class
     */
    public $orm;
    /**
     * current root path
     */
    public $root_path;
    /**
     * root_path trimed with right '/'
     */
    public $root_doc;
    /**
     * upload server url to replace links of all
     * the media assets
     */
    public $uploadServer;
    /**
     * current smtp server configuration
     */
    public $smtp;
    /**
     * current http_server address
     */
    public $http_server;
    /**
     * client section of config file
     */
    public $clients_config;
    /**
     * params section of config file
     */
    public $config_params;
    /**
     * api_keys section of config file
     */
    private $api_keys;
    /**
     * csrf token for safe forms actions
     */
    private $csrf_token_name;
    /**
     * keys section of config file
     */
    public $keys;

    public $model_path;
    public $config_path;
    public $app_url_prefix;

    /**
     * Constructor
     * @param  $sql instance of _uho_mysql class
     * @param array $lang language shortcut, i.e. en
     * @param array $salt out of use
     * @param array $lang_model language suffix, i.e. _EN
     * @param array $params
     * @return null
     */

    public function __construct($sql, $lang, $salt = null, $lang_model = null, $params = null)
    {
        $this->sql = $sql;

        $this->lang = $lang;
        if ($lang_model === null && $lang) {
            $this->lang_add = '_' . strtoupper($lang);
        } elseif ($lang_model == '') {
            $this->lang_add = '';
        } else {
            $this->lang_add = '_' . strtoupper($lang);
        }

        $this->root_path = isset($params['root_path']) ? $params['root_path'] : '';
        $this->root_doc = isset($params['root_doc']) ? $params['root_doc'] : '';

        $this->model_path = $this->root_path . 'application/models';

        if (isset($params['keys'])) $this->setKeys($params['keys']);
        if (isset($params['upload_server'])) $this->uploadServer = $params['upload_server'];
        if (isset($params['smtp'])) $this->smtp = $params['smtp'];
        if (isset($params['client'])) $this->clients_config = $params['client'];
        if (isset($params['params'])) $this->config_params = $params['params'];
        if (isset($params['api_keys'])) $this->api_keys = $params['api_keys'];
        if (isset($params['config_folder'])) {
            $this->config_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $params['config_folder']);
            $this->config_folder = $params['config_folder'];
        } else {
            if (!isset($this->config_path)) $this->config_path = '';
        }

        $this->app_url_prefix = isset($params['url_prefix']) ? $params['url_prefix'] : '';

        if (isset($_SERVER['HTTPS']) || isset($_SERVER['SSL_PROTOCOL'])) $this->http_server = 'https://';
        else $this->http_server = 'http://';
        $this->http_server .= $_SERVER['HTTP_HOST'];
        $this->orm = new _uho_orm($this, $this->sql, $this->lang, @$params['keys']);

        if (isset($params['languages'])) $this->orm->setLangs($params['languages']);
        if (!$this->uploadServer) {
            if (isset($params['files_decache']) && $params['files_decache']) $this->orm->setFilesDecache($params['files_decache']);
            elseif (isset($params['files_decache']) && $params['files_decache'] === false);
            else $this->orm->setFilesDecache(true);
        }

        $this->init();
    }

    /**
     * Sets debug for the ORM instance
     *
     * @param int $q
     */
    public function setDebug($q): void
    {
        $this->orm->setDebug($q);
    }

    /**
     * Adds unique keys for encryption
     *
     * @param array $keys
     */
    public function setKeys($keys): void
    {
        $this->keys = $keys;
    }

    /**
     * Returns unique keys for encryption
     * @return array
     */

    public function getKeys()
    {
        return $this->keys;
    }

    /**
     * Initialize function, for children of this instance to overwrite
     */
    public function init(): void {}

    /**
     * Get model data, for children of this instance to overwrite
     */
    public function getData(): void {}

    /**
     * Returns default model for 404 page
     * @return array
     */

    public function get404($url = '404')
    {
        $data = array('message' => 'Page not found...');
        return ($data);
    }

    public function setFileTimeCache($q): void
    {
        $this->orm->setFilesDecache($q);
    }

    /**
     * CSRF TOKEN comparsion, these tokens are being used to keep <form> elements safe
     *
     * @param string $known_string
     * @param string $user_string
     */
    public function hash_equals($known_string = null, $user_string = null): bool|null
    {
        $argc = func_num_args();
        // Check the number of arguments
        if ($argc < 2) {
            trigger_error(sprintf('hash_equals() expects exactly 2 parameters, %d given', $argc), E_USER_WARNING);
            return null;
        }
        // Check $known_string type
        if (!is_string($known_string)) {
            trigger_error(sprintf('hash_equals(): Expected known_string to be a string, %s given', strtolower(gettype($known_string))), E_USER_WARNING);
            return false;
        }
        // Check $user_string type
        if (!is_string($user_string)) {
            trigger_error(sprintf('hash_equals(): Expected user_string to be a string, %s given', strtolower(gettype($user_string))), E_USER_WARNING);
            return false;
        }
        // Ensures raw binary string length returned
        $strlen = /**
         * @psalm-return int<0, max>
         */
        function ($string): int {
            //if (USE_MB_STRING) {
            //  return mb_strlen($string, '8bit');
            //}
            return strlen($string);
        };
        // Compare string lengths
        if (($length = $strlen($known_string)) !== $strlen($user_string)) {
            return false;
        }
        $diff = 0;
        // Calculate differences
        for ($i = 0; $i < $length; $i++) {
            $diff |= ord($known_string[$i]) ^ ord($user_string[$i]);
        }

        return $diff === 0;
    }

    /**
     * Return CSRF TOKEN name
     * @return string
     */

    public function csrf_token_name()
    {
        return $this->csrf_token_name;
    }

    /**
     * Return current CSRF TOKEN value
     * @return string
     */

    public function csrf_token_value()
    {
        return $_SESSION[$this->csrf_token_name];
    }

    /**
     * Creates new CSRF TOKEN in Sessios var
     * @param string $uid
     * @param boolean $force
     */

    public function csrf_token_create($uid, $force = false)
    {
        if (isset($uid)) $uid = md5($uid);
        $this->csrf_token_name = 't' . $uid;

        if (empty($_SESSION[$this->csrf_token_name]) || $force) {
            $_SESSION[$this->csrf_token_name] = bin2hex(openssl_random_pseudo_bytes(32));
        }
        $token = $_SESSION[$this->csrf_token_name];
        return $token;
    }


    /**
     * Verify CSRF TOKEN
     * @param string $token
     * @return boolean
     */

    public function csrf_token_verify($token)
    {
        $original_token = $_SESSION[$this->csrf_token_name];

        $result = false;

        if ($original_token && $token) {
            $result = $this->hash_equals($token, $original_token);
        }

        return $result;
    }

    /**
     * Returns all API keys from the config file
     * @param array
     * @return string
     */

    public function  getApiKeys($section)
    {
        return @$this->api_keys[$section];
    }

    public function getOrm()
    {
        return $this->orm;
    }

    /**
     * Converts string for safe use with mySQL
     * @param string $s
     * @return string
     */

    public function sqlSafe($s)
    {
        return $this->orm->sqlSafe($s);
    }

    /**
     * Runs mySQL read-only query via ORM instance
     * @param string $query
     * @param boolean $single
     * @param boolean $stripslashes
     * @param string $key
     * @param boolean $do_field_only
     * @return array
     */

    public function query($query, $single = false, $stripslashes = true, $key = null, $do_field_only = null)
    {
        return $this->orm->query($query, $single, $stripslashes, $key, $do_field_only);
    }

    /**
     * Runs mySQL write query via ORM instance
     * @param string $query
     * @return boolean
     */

    public function queryOut($query)
    {
        return ($this->orm->queryOut($query));
    }

    /**
     * Runs mySQL write queries via ORM instance
     * @param string $query
     * @return boolean
     */

    public function queryMultiOut($query)
    {
        return ($this->orm->queryMultiOut($query));
    }

    /**
     * Runs mySQL write query via ORM instance
     * @param string $query
     * @return boolean
     */

    public function multiQueryOut($query)
    {
        return ($this->orm->multiQueryOut($query));
    }

    /**
     * Gets model from mySQL via ORM
     * @param string $name
     * @param array $filters
     * @param boolean $single
     * @param string $order
     * @param string $limit
     * @param array $params
     * @return array
     */

    public function getJsonModel($name, $filters = null, $single = false, $order = null, $limit = null, $params = null)
    {
        return $this->orm->getJsonModel($name, $filters, $single, $order, $limit, $params);
    }

    /**
     * Gets model from mySQL via ORM, with its children models
     * @param string $name
     * @param array $filters
     * @param boolean $single
     * @param array $settings
     * @return array
     */

    public function getJsonModelDeep($name, $filters = null, $single = false, $settings = null)
    {
        return $this->orm->getJsonModelDeep($name, $filters, $single, $settings);
    }

    /**
     * Posts model to mySQL via ORM
     * @param string $model
     * @param array $data
     * @param boolean $multiple
     * @return boolean
     */

    public function postJsonModel($model, $data, $multiple = false)
    {
        return $this->orm->postJsonModel($model, $data, $multiple);
    }

    /**
     * Puts model to mySQL via ORM
     * @param string $model
     * @param array $data
     * @param array $filters
     * @param boolean $multiple
     * @return boolean
     */

    public function putJsonModel($model, $data, $filters = null, $multiple = false)
    {
        return $this->orm->putJsonModel($model, $data, $filters, $multiple);
    }

    /**
     * Deletes model to mySQL via ORM
     * @param string $model
     * @param array $filters
     * @param boolean $multiple
     * @return boolean
     */

    public function deleteJsonModel($model, $filters, $multiple = false)
    {
        return $this->orm->deleteJsonModel($model, $filters, $multiple);
    }

    /**
     * Gets ID of last added model, via ORM
     * @return int
     */

    public function getInsertId()
    {
        return $this->orm->getInsertId();
    }

    /**
     * Sets uplaod server path, if other than local one
     *
     * @param string $s
     */
    public function setUploadServer($s): void
    {
        $this->uploadServer = $s;
    }

    /**
     * Return decached image filename
     * @param string $image
     * @return string
     */

    public function image_decache($image)
    {
        $image = explode('?', $image);
        $image = array_shift($image);
        $image = explode('.', $image);
        $ext = array_pop($image);
        $base = implode('.', $image);
        $base = explode('___', $base);
        $base = array_shift($base);
        $image = $base . '.' . $ext;
        return $image;
    }

    /**
     * File_exists function using CURL for remote files
     *
     * @param string $f
     *
     * @return bool
     */
    public function file_exists(string $f): bool
    {
        $f = str_replace('//', '/', $f);
        $f = $this->image_decache($f);

        if ($this->uploadServer) {
            $ch = curl_init($this->uploadServer . $f);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_exec($ch);
            $retCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($retCode == 200) {
                return true;
            } else return false;
        } else {
            return file_exists($this->root_path . $f);
        }
    }

    /**
     * getimagesize function using decache and remote servers
     *
     * @param string $f
     *
     * @return (int|string)[]|false|null
     *
     * @psalm-return array{0: int, 1: int, 2: int, 3: string, mime: string, channels?: 3|4, bits?: int}|false|null
     */
    public function getimagesize($f): array|false|null
    {
        $f = $this->image_decache($f);

        if ($this->uploadServer) {
            return null;
        } else {
            return @getimagesize($this->root_path . $f);
        }
    }

    /**
     * Sets S3 instance for the ORM
     *
     * @param array $s3
     */
    public function setS3($s3): void
    {
        if (isset($s3['host'])) {
            $host = $s3['host'] . '/';
            if (!empty($s3['folder']) && $s3['folder'] != 'folder') $host .= $s3['folder'] . '/';
            $this->orm->setFolderReplace('/public/upload/', $host);
        }

        if (isset($s3['cache'])) $this->orm->s3setCache($s3['cache']);
        if (isset($s3['compress'])) $this->orm->setS3Compress($s3['compress']);
        if (!empty($s3['cache_build_on_run']) && !empty($s3['cache']) && !file_exists($this->orm->s3getCacheFilename()))
            $this->generateS3Cache($s3);
    }

    private function generateS3Cache(array $params): void
    {
        require_once('_uho_s3.php');
        $s3 = new _uho_s3($params, false, ['orm' => $this->orm]);
        if ($s3->ready()) {
            $s3->buildCache();
        }
    }

    //============================================================================================
}
