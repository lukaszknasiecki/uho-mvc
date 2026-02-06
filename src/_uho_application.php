<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_fx;
use Huncwot\UhoFramework\_uho_social;
use Huncwot\UhoFramework\_uho_controller;
use Huncwot\UhoFramework\_uho_model;
use Huncwot\UhoFramework\_uho_orm;
use Huncwot\UhoFramework\_uho_route;
use Huncwot\UhoFramework\_uho_mysqli;


/**
 * This is the main class of the UHO8 application
 */

class _uho_application
{
    /**
     * _uho_sqli instanve
     */
    private $sql;
    /**
     * _uho_view instance
     */
    private $view;
    /**
     * _uho_controller instance
     */
    private $controller;
    /**
     * _uho_model instance
     */
    private $cms;
    /**
     * root path to /public_html
     */
    private $root_path;

    private $application_params;
    private $route;
    private $development=false;

    /**
     * Application constructor
     * @param string $root_path index.php server path
     * @param boolean $development indicated development mode
     * @param string $config_folder application config folder path
     * @param boolean $force_ssl forces HTTPS paths even if no SSL detected
     * @return null
     */

    public function __construct($root_path, $development, $config_folder = null, $force_ssl = false)
    {
        if (!isset($_SESSION)) {
            session_start();
        }

        $app_path = $root_path . 'application/';
        $this->development=$development;
        $this->root_path = $root_path;
        $root_doc = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/';

        // backward compability
        if (!defined("root_path")) define("root_path", $root_path);
        if (!defined("root_doc")) define("root_doc", $root_doc);
        if (!defined("development")) define("development", 0);

        // application config ----------------------------------------
        if (is_array($config_folder) && !empty($config_folder['pre']))
        {
            $this->application_params = $this->getConfig($config_folder['main'],$config_folder['pre']);
            $config_folder=$config_folder['main'];
        }
        // predefined object
        elseif (is_array($config_folder))
        {
            $this->application_params = $config_folder;
            $config_folder = '';
        }
        // single file
        else if ($config_folder)
        {
            $this->application_params = $this->getConfig($config_folder);
        } else {
            $this->application_params = ['application_title' => 'app', 'application_class' => 'app', 'nosql' => true];
        }

        // $this->application_title = @$this->application_params['application_title'];

        $app_class = $this->application_params['application_class'];

        $src_routing_config=$app_path.'routes/route_'.$app_class.'.json';        

        if (file_exists($src_routing_config))
        {
            $routing_config=file_get_contents($src_routing_config);
            if ($routing_config) $routing_config=json_decode($routing_config,true);
        } else $routing_config=null;

        if (!$routing_config) exit('No ROUTING config found at: '.$src_routing_config);

        $overwriteUrl = false;

        if (isset($_SERVER['SSL_PROTOCOL']) || $force_ssl) $http = 'https';
        else $http = 'http';

        if (!defined("http")) define('http', $http); // backward compability

        $route_cfg = [
            'httpDomain' => (@$this->application_params['application_domain'] == '' ? '' : $http . "://" . @$this->application_params['application_domain']),
            'langCookie' => @$this->application_params['application_domain'] . '_lang',
            'langArray' => @$this->application_params['application_languages'],
            'langPrefix' => @$this->application_params['application_languages_url'],
            'langDetect' => @$this->application_params['application_languages_detect'],
            'langEmpty' => @$this->application_params['application_languages_empty'],
            'urlPrefix' => @$this->application_params['application_url_prefix'],
            'strict_url_parts' => @$this->application_params['strict_url_parts'],
            'routeArray' => [$routing_config['controllers'], isset($routing_config['headers']) ? $routing_config['headers'] : [] ],
            'pathArray' => $routing_config['paths'], [],
            'overwriteUrl' => $overwriteUrl
        ];

        $this->route = new _uho_route(
            $route_cfg,
            true,
            $force_ssl
        );

        $lang = @$this->application_params['application_language'];
        if (!$lang) {
            $lang = $this->route->getLang();
        }
        if (!$lang) $lang = @$this->application_params['application_languages'][0];

        switch ($lang) {
            case "pl":
                setlocale(LC_ALL, 'pl_PL.utf-8');
                break;
            case "en":
                setlocale(LC_ALL, 'en_EN.utf-8');
                break;
            case "fr":
                setlocale(LC_ALL, 'fr_FR.utf-8');
                break;
            case "de":
                setlocale(LC_ALL, 'de_DE.utf-8');
                break;
            case "ru":
                setlocale(LC_ALL, 'ru_RU.utf-8');
                break;
            case "cn":
                setlocale(LC_ALL, 'zh_CN.utf-8');
                break;
        }

        // --- init MODEL and VIEW
        if (!@$this->application_params['nosql']) $this->sql_init();

        $class = $this->route->getRouteClass();
        
        if (!$class) exit('_uho_application::error::no-routing-class');
        $model_class = 'model_' . $app_class . '_' . $class;
        $view_class = 'view_' . $app_class;

        require_once($app_path . "/views/" . $view_class . ".php");
        require_once($app_path . "models/" . $model_class . ".php");

        $langs = @$this->application_params['application_languages'];
        if ($langs) {
            $langs = array_flip($langs);
            if (isset($langs['_' . $lang])) {
                $lang_model = '';
            } else {
                $lang_model = '_' . strtoupper($lang);
            }
        } else {
            $lang_model = null;
        }
        
        $this->view = new $view_class($root_path,'/application/views/');
        $this->view->setLang($lang);
        $this->view->setDebug($development);

        $this->cms = new $model_class(
            $this->sql,
            $lang,
            @$this->application_params['serdelia_user_salt'],
            $lang_model,
            [
                'config_folder' => $config_folder,
                'params' => @$this->application_params['params'],
                'files_decache' => @$this->application_params['files_decache'],
                'keys' => @$this->application_params['keys'],
                'orm_version' => empty($this->application_params['orm_version']) ? 1 : $this->application_params['orm_version'],
                'api_keys' => @$this->application_params['api_keys'],
                'languages' => @$this->application_params['application_languages'],
                'upload_server' => @$this->application_params['upload_server'],
                'client' => @$this->application_params['clients'],
                'smtp' => @$this->application_params['smtp'],
                'root_path' => $root_path,
                'root_doc' => $root_doc,
                'url_prefix' => isset($this->application_params['application_url_prefix']) ? $this->application_params['application_url_prefix'] : ''
            ]
        );


        if (isset($this->application_params['keys'])) {
            $this->cms->setKeys($this->application_params['keys']);
        }

        // get CONTROLLER class  ----------------------------------------

        $controller_class = 'controller_' . $app_class . '_' . $this->route->getRouteClass();

        require_once($app_path . "controllers/" . $controller_class . '.php');

        $this->controller = new $controller_class($this->application_params, $this->cms, $this->view, $this->route);

        // starting CONTROLLER ------------------------------------------

        if (@$this->application_params['nosql']) {
            $this->controller->setNoSql();
        }

        $this->controller->actionBefore($_POST, _uho_fx::getGetArray());
        $this->controller->getAppData();
    }

    /**
     * Config file loader
     * $folder (string) - application config folder
     * @return array config object
     */
    private function getConfig(string $folder = 'application_config',$pre_additional_cfg_files=[])
    {
        if (file_exists($folder . '/.env')) {
            require_once('_uho_load_env.php');
            $env_loader = new _uho_load_env($folder . '/.env');
            $env_loader->load();
        }

        // pre - config.php
        $pre=[];
        foreach ($pre_additional_cfg_files as $v)
        {
            $cfg=[];
            include($v . '/config.php');
            if (!empty($cfg)) $pre=$cfg+$pre;
        }

        if ($folder[0] == '/') {
            include($folder . '/config.php');
            $hosts_folder=$folder;            
            $additional = @file_get_contents($folder . '/config_additional.json');
        } else
        {
            include($this->root_path . $folder . '/config.php');
            $hosts_folder=$this->root_path . $folder;
            $additional = @file_get_contents($this->root_path . $folder . '/config_additional.json');
        }
        
        if ($pre) $cfg = $cfg+$pre;
        if ($additional) $cfg = array_merge($cfg, json_decode($additional, true));

        // load hosts
        foreach ($pre_additional_cfg_files as $v)
        {
            if (file_exists($v.'/hosts.php')) include($v . '/hosts.php');
        }
        include($hosts_folder . '/hosts.php');

        $hostname = @gethostname();

        // find domain by hostname
        $found = null;
        if (!is_array($cfg_domains)) $this->halt('_uho_application::No cfg_domains defined');
        foreach ($cfg_domains as $k => $v) {
            $vv = explode('@', $k);
            if ($vv[0] == @$_SERVER['HTTP_HOST'] && isset($vv[1])) {
                $vv[1] = array_flip(explode('|', $vv[1]));
                if (isset($vv[1][$hostname])) {
                    $found = $cfg_domains[$k];
                }
            }
        }

        if (isset($_SERVER['HTTP_HOST']))
            $subdomain = explode('.', $_SERVER['HTTP_HOST']);
        else $subdomain = [];

        if (count($subdomain) > 2) {
            $subdomain[0] = '*';
            $subdomain = implode('.', $subdomain);
        } else {
            $subdomain = null;
        }


        if ($found) {
            $cfg_domains = $found;
            $cfg_domains['application_domain'] = $_SERVER['HTTP_HOST'];
        }
        // found domain by strictname
        elseif (isset($cfg_domains[$_SERVER['HTTP_HOST']]))
        {
            $cfg_domains = $cfg_domains[$_SERVER['HTTP_HOST']];
            $cfg_domains['application_domain'] = $_SERVER['HTTP_HOST'];
        }
        // found by subdomain *.domain.com
        elseif ($subdomain && isset($cfg_domains[$subdomain]) && $cfg_domains[$subdomain]) {
            $cfg_domains = $cfg_domains[$subdomain];
            $cfg_domains['application_domain'] = $_SERVER['HTTP_HOST'];
        } elseif ($cfg_domains) {
            $keys = array_keys($cfg_domains);
            $first_key = array_shift($keys);
            $cfg_domains = array_shift($cfg_domains);
            header("Location:http://" . $first_key);
        } else {
            $cfg_domains = [];
            $cfg['application_domain'] = $_SERVER['HTTP_HOST'];
        }

        if (!isset($cfg['clients']) || !$cfg['clients']) $cfg['clients'] = [];
        if (@$cfg_domains['clients']) $cfg['clients'] = array_merge($cfg['clients'], $cfg_domains['clients']);
        if (isset($cfg_domains['api_keys'])) $cfg['api_keys'] = $cfg_domains['api_keys'];
        $cfg = array_merge($cfg_domains, $cfg);

        if ($cfg['application_languages_url'] === false) {
            $cfg['application_language'] = $cfg['application_languages'][0];
            $cfg['application_languages'] = null;
        }

        if (isset($cfg_domains['http'])) $cfg['application_http'] = $cfg_domains['http'];
        if (!isset($cfg['application_http'])) {
            $cfg['application_http'] = 'http';
        }
        $cfg['config_folder'] = $folder;
        if (isset($cfg['application_domain']))
            $cfg['cookie_alert'] = str_replace('.', '_', $cfg['application_domain']) . '_cookie_alert';
        else $cfg['cookie_alert'] = 'cookie_alert';
        if (!$cfg['application_class']) {
            $cfg['application_class'] = 'app';
        }

        return ($cfg);
    }

    /**
     * Initializes mySQL DB connection
     */
    private function sql_init(): void
    {
        if ($this->application_params['sql_host']) {
            $this->sql = new _uho_mysqli(null, false);
            if (!empty($this->application_params['sql_debug'])) $this->sql->setDebug(true);
            
            if (!$this->sql->init(
                $this->application_params['sql_host'],
                $this->application_params['sql_user'],
                $this->application_params['sql_pass'],
                $this->application_params['sql_base']
            )) {
                $this->halt('SQL Database connection error.');
            }
        }
    }

    /**
     * Gets final output from the controller
     * @param string $type output type, supporteed is html, json
     * @return array output data
     */

    public function getOutput($type = null)
    {
        //if (_uho_fx::getGet('output') && _uho_fx::getGet('output') == 'json' && $type == 'json')
        //    $type = 'html';
        if ($type) {
            $this->controller->outputType = $type;
        }
        $output = $this->controller->getOutput($this->controller->outputType);

        if (isset($this->application_params['upload_server']) && is_string($output)) {
            $output = str_replace('/public/upload/', $this->application_params['upload_server'] . '/public/upload/', $output);
        }

        return (array(
            'output' => $output,
            'header' => $this->controller->outputType
        ));
    }

    /**
     * Halt utility
     *
     * @return never
     *
     * @psalm-param 'SQL Database connection error.' $message
     */
    public function halt(string $message)
    {
        if ($this->development) {
            exit($message);
        } else {
            exit('System error. Please, try again later.');
        }
    }
}
