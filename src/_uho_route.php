<?php

namespace Huncwot\UhoFramework;

/**
 * This class supports basic routing
 */

class _uho_route
{
    /**
     * config array
     */
    private $cfg;
    /**
     * current URL
     */
    private $urlString;
    /**
     * array of available languages
     */
    private $urlLang;
    /**
     * current url sliced in to array
     */
    private $urlArray;
    /**
     * current routeClass
     */
    private $routeClass;
    /**
     * current _GET array
     */
    private $getArray;
    /**
     * current http|https
     */
    private $http;
    private $closingSlash = false;

    /**
     * Constructor
     * @param array $config
     * @param boolean $init
     * @param boolean $force_ssl
     * @return null
     */

    public function __construct($cfg, $init = false, $force_ssl = false)
    {

        if (
            $force_ssl
            || isset($_SERVER['SSL_PROTOCOL'])
            || @$_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' // ec2 ooh
            || @$_SERVER['HTTPS'] == 'on'
            || isset($_SERVER['SSL_TLS_SNI'])
        )
            $this->http = 'https';

        else $this->http = 'http';

        if (!isset($cfg['cookieDays'])) {
            $cfg['cookieDays'] = 365;
        }
        if (!isset($cfg['url404'])) {
            $cfg['url404'] = '404';
        }

        if (isset($cfg['langArray'])) {
            foreach ($cfg['langArray'] as $k => $v) {
                $cfg['langArray'][$k] = trim($v, '_');
            }
        }

        $this->cfg = $cfg;
        $this->cfg['httpDomain'] = str_replace('http://', $this->http . '://', $this->cfg['httpDomain']);
        $this->cfg['cookieTime'] = $cfg['cookieDays'] * 3600 * 24;
        $this->cfg['langCookie'] = str_replace('.', '_', $this->cfg['langCookie']);
        $this->cfg['langEmpty'] = @$cfg['langEmpty'];

        if ($init) {
            $this->init();
        }

        $this->routeClass = $this->findRouteClass($this->cfg['routeArray'][0], $this->cfg['routeArray'][1]);
    }

    /**
     * Starts routing
     */
    public function init(): void
    {
        if ($this->cfg['overwriteUrl'] && $this->cfg['overwriteUrl'] != 'overwriteUrl') {
            $this->urlString = $this->cfg['overwriteUrl'];
        } elseif (isset($_SERVER['TEST_REQUEST_URI']))
            $this->urlString = $_SERVER['TEST_REQUEST_URI'];
        else {
            $this->urlString = filter_input(INPUT_GET, 'url', FILTER_SANITIZE_SPECIAL_CHARS);
        }

        if (isset($this->urlString)) $this->urlString = rtrim($this->urlString, '/');
        if (isset($this->urlString)) $this->urlArray = explode('/', $this->urlString);
        else $this->urlArray = [];

        if (!isset($this->urlArray[0])) {
            $this->urlArray = null;
        }

        // ==============================================================================================================
        // no language set or bad language - redirect to default page with language

        if ($this->cfg['langEmpty']) {

            if ($this->urlArray[0]) {
                $i = array_search($this->urlArray[0], $this->cfg['langArray']);
            } else {
                $i = null;
            }

            if ($i) {
                $this->urlLang = $this->cfg['langArray'][$i];
                array_shift($this->urlArray);
            } else {
                $this->urlLang = $this->cfg['langEmpty'];
            }
        }

        //
        elseif ($this->cfg['langArray'] && $this->cfg['langPrefix'] && (!$this->urlArray || array_search($this->urlArray[0], $this->cfg['langArray']) === false)) {
            // check if cookie is set
            if ($this->cfg['langCookie'] && array_key_exists($this->cfg['langCookie'], $_COOKIE)) {
                $lang = $_COOKIE[$this->cfg['langCookie']];
            } else $lang = null;
            // if no language found - looking for default system language
            if (array_search($lang, $this->cfg['langArray']) === false) {
                if ($this->cfg['langDetect']) {
                    $langs = explode(',', $_SERVER["HTTP_ACCEPT_LANGUAGE"]);
                    if ($langs) {
                        foreach ($langs as $v) {
                            $v = explode(';', $v);
                            $v = @$v[0];
                            foreach ($this->cfg['langDetect'] as $k2 => $v2) {
                                if ($v2 && !$lang) {
                                    if (strtolower($v2) == strtolower($v)) {
                                        $lang = $k2;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $lang = null;
                }

                if (!$lang && $this->cfg['langDetect'] === false) {
                    $lang = $this->cfg['langArray'][0];
                }
                if (!$lang && isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])) {
                    $lang = null;
                    $s = explode(';', $_SERVER["HTTP_ACCEPT_LANGUAGE"]);
                    $s = ' ' . $s[0];
                    $fi = 9999;
                    foreach ($this->cfg['langArray'] as $value) {
                        if (strpos($s, $value) && strpos($s, $value) < $fi && (!isset($this->cfg['langExcludeAutoLanguage']) || !$this->cfg['langExcludeAutoLanguage'] || array_search($value, $this->cfg['langExcludeAutoLanguage']) === false)) {
                            $lang = $value;
                            $fi = strpos($s, $value);
                        }
                    }
                }
                // not found? setting first language from the array

                if (!$lang) {
                    $lang = $this->cfg['langArray'][0];
                }

                $this->urlLang = $lang;
                if ($this->urlString) {
                    $this->redirect404($this->cfg['langArray'][0]);
                }
            }

            $this->redirect($lang, null, false);
        }
        // ==============================================================================================================
        // we have the right language or we use no language
        else {
            if ($this->cfg['langArray'] && $this->cfg['langPrefix']) {
                $this->urlLang = $this->urlArray[0];
                array_shift($this->urlArray);
            }
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            $get = explode('?', (string) $_SERVER['REQUEST_URI']);
        } else $get = [];
        if (isset($get[1])) parse_str(@$get[1], $this->getArray);
    }

    /**
     * Set lang prefix
     *
     * @param string $lang
     */
    public function setLang($lang): void
    {
        $this->urlLang = $lang;
    }

    /**
     * Enabled language cookie
     *
     * @param string $lang
     */
    public function setCookieLang($lang = null): void
    {
        if (!$lang) {
            $lang = $this->urlLang;
        }
        if (array_search($lang, $this->cfg['langArray']) === false);
        else {
            $domain = str_replace('http://', '', $this->cfg['httpDomain']);
            $domain = str_replace('https://', '', $domain);

            setcookie(
                $this->cfg['langCookie'],
                $lang,
                [
                    'expires' => time() + $this->cfg['cookieTime'],
                    'path' => "/",
                    'domain' => $domain,
                    'secure' => strpos($_SERVER['HTTP_HOST'], '.lh') === false,
                    'httponly' => 1,
                    'samesite' => 'strict'
                ]
            );
        }
    }

    /**
     * Sets prefix URL for routing
     *
     * @param string $url
     */
    public function setPrefixUrl($url): void
    {
        $this->cfg['urlPrefix'] = $url;
    }

    /**
     * Removes prefix from URL
     * @param string $url
     * @return string
     */

    private function getPrefixUrl($url)
    {
        if ($this->cfg['urlPrefix']) {
            $url = '/' . $this->cfg['urlPrefix'] . '/' . trim($url, '/');
        };
        $url = str_replace('//', '/', $url);
        return ($url);
    }

    /**
     * Gets full URL with lang prefix
     * @param string $url
     * @return string
     */

    public function getUrlLang($url)
    {
        if ($this->cfg['langEmpty'] == $this->urlLang) {
            return $url;
        } else {
            return $this->urlLang . '/' . $url;
        }
    }

    /**
     * Redirecting tool
     *
     * @param string $url
     * @param array $get
     * @param boolean $addUrlLang
     *
     * @return never
     */
    public function redirect($url, $get = null, $addUrlLang = true)
    {
        if ($this->urlLang && $addUrlLang && $this->cfg['langPrefix']) {
            $url = $this->getUrlLang($url);
        } elseif (!$url && $this->cfg['langArray'] && $this->cfg['langPrefix']) {
            $url = $this->cfg['langArray'][0] . '/' . $url;
        }

        if ($get) {
            $url .= '?' . http_build_query($get);
        }
        $url = $this->getPrefixUrl($url);

        if ($this->cfg['httpDomain']) $url = $this->cfg['httpDomain'] . '/' . trim($url, '/');

        if (isset($this->cfg['afterRedirectLangUrl'])) {
            $url = rtrim($url, '/') . '/' . $this->cfg['afterRedirectLangUrl'];
        }
        if ($this->closingSlash) $url .= '/';
        header("Location: " . $url);
        exit();
    }

    public function setClosingSlash(): void
    {
        $this->closingSlash = true;
    }

    /**
     * Redirecting tool for homepage
     */
    public function redirectHome(): void
    {
        $this->redirect($this->cfg['urlHome']);
    }

    /**
     * Redirecting tool for 404 page
     */
    public function redirect404(): void
    {
        $this->redirect($this->cfg['url404']);
    }

    /**
     * Finds app class name base on routing array
     * @param array $routeArray
     * @return string
     */

    private function findRouteClass($routeArray, $headerArray)
    {
        
        if ($headerArray) {
            $h0 = getallheaders();
            $h = [];
            foreach ($h0 as $k => $v)
                $h[strtolower($k)] = $v;

            foreach ($headerArray as $k => $v)
                if (isset($h[strtolower($k)])) return $v;
        }


        $urlArray = $this->urlArray;

        if (is_array($urlArray)) $i = count($urlArray);
        else $i = 0;
        $result = null;

        if ($i == 0) {
            $result = $routeArray[''];
        } else {
            //--------------------------------------
            while ($i >= 0 && !$result) {
                $path = implode('/', $urlArray);
                // equal
                if ($path && isset($routeArray[$path])) {
                    $result = $routeArray[$path];
                }

                // %...
                $u = $urlArray;
                $j = 0;
                while (!$result && count($u) > 1) {
                    array_pop($u);
                    $j++;
                    $path = implode('/', $u);
                    for ($k = 0; $k < $j; $k++) {
                        $path .= '/%';
                    }
                    if (isset($routeArray[$path])) {
                        $result = $routeArray[$path];
                    }
                }

                array_pop($urlArray);

                $i--;
            }
        }
        //--------------------------------------
        if (!$result) {
            $result = $routeArray[@$this->cfg['url404']];
        }

        return ($result);
    }

    /**
     * Return current routing class name
     * @return string
     */

    public function getRouteClass()
    {
        return ($this->routeClass);
    }

    /**
     * Returns current language shortcut
     * @return string
     */

    public function getLang()
    {
        return ($this->urlLang);
    }

    /**
     * Changes element of url array
     *
     * @param int $i
     * @param string $value
     */
    public function urlChange($i, $value): void
    {
        $this->urlArray[$i] = $value;
    }

    /**
     * Returns element of URL query
     * @param int $i
     * @return string
     */

    public function e($nr = false)
    {
        if ($nr === false) {
            return ($this->urlArray);
        } else {
            return (@$this->urlArray[$nr]);
        }
    }

    /**
     * Returns full url based on routing settings
     * @param string $url
     * @param boolean $domain
     * @param boolean $lang
     * @param boolean $prefix
     * @return string
     */

    public function getUrl($url, $domain = false, $lang = null, $prefix = true)
    {
        if ($url == '/') {
            $url = '';
        }
        if ($domain) {
            $domain = $this->cfg['httpDomain'];
        } else {
            $domain = '';
        }

        if ($this->cfg['urlPrefix'] && $prefix) {
            $domain .= '/' . $this->cfg['urlPrefix'];
        }
        if ($lang) {
            $url = $lang . '/' . $url;
        } elseif ($this->urlLang && $this->cfg['langEmpty'] != $this->urlLang) {
            $url = $this->urlLang . '/' . $url;
        }
        if ($url && @$url[strlen($url) - 1] == '/') $url = substr($url, 0, strlen($url) - 1);
        return ($domain . '/' . $url);
    }

    /**
     * Returns current full URL
     * @return string
     */

    public function getPathNow()
    {
        if (is_array($this->urlArray)) {
            $urlString = implode('/', $this->urlArray);
        } else {
            $urlString = '';
        }
        return $urlString;
    }

    /**
     * Returns current full URL based on adv options
     * @param $domain
     * @param array $getAdd
     * @param array $getNew
     * @param array $getRemove
     * @param string $lang
     * @param $prefix
     * @return string
     */

    public function getUrlNow($domain = false, $getAdd = null, $getNew = null, $getRemove = null, $lang = null, $prefix = true)
    {
        if (is_array($this->urlArray)) {
            $urlString = implode('/', $this->urlArray);
        } else {
            $urlString = '';
        }

        if ($getAdd || $getNew) {
            $get = [];

            if ($getAdd == '[all]') {
                $get = $this->getArray;
            } elseif ($getAdd) {
                foreach ($getAdd as $v) {
                    $get[$v] = @$this->getArray[$v];
                }
            }

            if ($getRemove && !is_array($getRemove)) {
                $getRemove = array($getRemove);
            }

            if ($getRemove) {
                foreach ($getRemove as $k => $v) {
                    if ($v) {
                        unset($get[$v]);
                    } else {
                        unset($get[$k]);
                    }
                }
            }

            if (!is_array($get)) $get = [];

            if (is_array($getNew)) {
                $get = array_merge($get, $getNew);
            }

            if ($get) {
                foreach ($get as $k => $v) {
                    if ($v === '') {
                        $get[$k] = '';
                    }
                }
            }
            $get = '?' . http_build_query($get);
        } else {
            $get = '';
        }

        return $this->getUrl($urlString . $get, $domain, $lang, $prefix);
    }

    /**
     * Return current domain
     * @return string
     */

    public function getDomain()
    {
        return $this->cfg['httpDomain'];
    }

    /**
     * Update URLs in array based on URL types
     *
     * @param array $array
     * @param string $field
     */
    public function updateUrls(&$array, $field = 'url'): void
    {
        if (is_array($array)) {
            foreach ($array as $k => $v) {
                if (is_array($v)) {
                    $this->updateUrls($array[$k], $field);
                } elseif ($k == $field) {
                    $array[$k] = array('slug' => $v);
                    if (substr($v, 0, 4) == 'http') {
                        $array[$k]['url'] = $v;
                        $array[$k]['target'] = "_blank";
                        $array[$k]['data-history'] = "false";
                    } else {
                        $array[$k]['url'] = $this->getUrl($v);
                        $array[$k]['data-history'] = "true";
                    }
                }
            }
        }
    }

    public function getLanguages()
    {
        return $this->cfg['langArray'];
    }

    /**
     * Returns true is AJAX detected
     * @return boolean
     */

    public function isAjax()
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return true;
        } else return false;
    }

    private function updatePath($v, $paths)
    {

        $result = $v;
        $type = $v['type'];
        $skip = false;

        $path = isset($paths[$v['type']]) ? $paths[$v['type']] : null;

        if (isset($path)) {
            $val = [];

            foreach ($path as $pk => $pv)
                if ($pk != 'type' && $pk != 'params' && isset($v[$pk])) $val[$pv] = $v[$pk];
        }

        if (empty($path['type']) && !empty($path['value'])) {
            $v = $path['value'];
        }

        if (isset($path['type']))
            switch ($path['type']) {

                case "facebook":
                    if (isset($val['slug']))
                        $v = _uho_social::getFacebookShare($this->getUrl($val['slug'], true));
                    else $v = _uho_social::getFacebookShare($this->getUrlNow(true));
                    break;
                case "twitter":
                    if (isset($val['slug']))
                        $v = _uho_social::getTwitterShare($this->getUrl($val['slug'], true), @$val['title']);
                    else $v = _uho_social::getTwitterShare($this->getUrlNow(true), @$val['title']);
                    break;
                case "linkedin":
                    
                    if (isset($val['slug']))
                        $v = _uho_social::getLinkedinShare($this->getUrl($val['slug'], true));
                    else $v = _uho_social::getLinkedinShare($this->getUrlNow(true));
                    break;
                    
                    break;
                case "pinterest":
                    if (isset($val['slug']))
                        $v = _uho_social::getPinterestShare($this->getUrl($val['slug'], true), @$val['title'], @$val['image']);
                    else $v = _uho_social::getPinterestShare($this->getUrlNow(true), @$val['title'], @$val['image']);
                    break;
                case "email":
                    $v = _uho_social::getEmailShare($this->getUrlNow(true), @$val['title']);
                    $type = 'hash';
                    break;
                case "home":
                    $v = '';
                    break;
                case "url_now":

                    $getNew = @$val['get'];

                    if (!$getNew) $getNew = [];
                    if (!empty($val['setlang'])) $getNew['setlang'] = 'true';

                    if ($getNew) $v = $this->getUrlNow(false, '[all]', $getNew, @$v['get_remove'], $val['lang']);
                    else $v = $this->getUrlNow(false, null, null, null, @$val['lang']);

                    $v = rtrim($v, '/');
                    $v = str_replace('=&', '&', $v);

                    $skip = true;

                    break;

                case "url_now_http":
                    $v = $this->getUrlNow(true);
                    break;


                case "twig":

                    $input = [];

                    if (isset($path['input']) && is_array($path['input']))
                    foreach ($path['input'] as $vp) {
                        if (isset($v[$vp])) {

                            $input[$vp] = $v[$vp];

                            if (isset($path['input_format'][$vp])) {

                                switch ($path['input_format'][$vp]) {
                                    case "raw":
                                        break;
                                    case "json":
                                        $input[$vp] = urlencode(json_encode($input[$vp]));
                                        break;
                                    default:
                                }
                            }
                        }
                    }

                    if (!empty($path['params'])) {
                        $query = [];
                        foreach ($path['params'] as $key => $val)
                            if (isset($input[$key])) {
                                switch ($val) {
                                    case "raw":
                                        break;
                                    case "json":
                                        $input[$key] = urlencode(json_encode($input[$key]));
                                        break;
                                    default:
                                        exit('error');
                                }
                                $query[$key] = $input[$key];
                            }
                        
                        $input['build_query'] = '?' . http_build_query($query);
                    }

                    $v = $this->getTwigFromHtml($path['value'], $input);
                    break;
            }

        if ($skip) $result = $v;
        else if ($type == 'hash') $result = $v;
        else if (is_array($v));
        else if ($v && substr($v, 0, 4) == 'http') $result = $v;
        else $result = $this->getUrl($v);

        return $result;
    }

    public function updatePaths(array $t, $root = true)
    {
        if (empty($this->cfg['pathArray'])) return $t;

        if (is_array($t))
            foreach ($t as $k => $v) {
                $hash = null;

                // update everything with prefix url and .type set
                if (substr($k, 0, 3) === 'url' && isset($v['type'])) {
                    $t[$k] = $this->updatePath($v, $this->cfg['pathArray']);
                }
                /*
                    if url prefix is set, value is string with no http prefix, use full string 
                */ elseif ($v && substr($k, 0, 3) === 'url' && substr($v, 0, 4) != 'http') {
                    if ($v == 'home') $t[$k] = '/';
                    else $t[$k] = rtrim($this->getUrl($v), '/');
                }
                /*
                    for other arrays - lets recursively call the same function
                */ elseif (is_array($v)) $t[$k] = $this->updatePaths($v, false);

                if ($hash) $t[$k] .= '#' . $hash;
            }

        return $t;
    }

    /**
     * Renders twig
     * @param string $html
     * @param array $data
     * @return string
     */

    private function getTwigFromHtml($html, $data)
    {
        if (!$html) return;
        $twig = @new \Twig\Environment(new \Twig\Loader\ArrayLoader(array()));
        if ($twig) {
            $template = $twig->createTemplate($html);
            $html = $template->render($data);
        }
        return $html;
    }


}
