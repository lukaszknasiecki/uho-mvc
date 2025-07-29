<?php

namespace Huncwot\UhoFramework;

use SimplePHPCache\Cache;

/**
 * This class provides HTML caching
 * supports encoding with SALT parameter
 * uses https://github.com/cosenary/Simple-PHP-Cache
 */

class _uho_cache
{
    /**
     * salt for caching
     */
    private $salt;
    /**
     * query result (boolean) 
     */
    private $result;
    /**
     * instance of cache_class
     */
    private $cache;
    /**
     * custom param for creating cache files
     */
    private $additionalParam;
    private $requestParams;
    private $addPost;
    private $ajaxDifferent;


    /**
     * Class constructor
     * @param string $salt unique salt for encryption
     * @param boolean $ajaxDifferent if true cache create separate files if Ajax is detected
     * @param array $additionalParam custom param for creating cache files
     * @return null
     */

    public function __construct($salt, $ajaxDifferent = false, $additionalParam = null, $addPost = false, $request = [])
    {
        $this->ajaxDifferent = $ajaxDifferent;
        $this->additionalParam = $additionalParam;
        $this->requestParams = $request;
        $this->salt = $salt;
        $this->addPost = $addPost;
        $this->result = '';
        $this->cache = new Cache(
            ['path' => '/cache/']
        );
        $this->cache->setCache($this->getKey());
    }

    /**
     * Check if it's AJAX request
     * @return boolean
     */

    private function isAjax()
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            return true;
        } else return false;
    }

    /**
     * Returns unique key based on HTTP request, hashed with md5
     * @return string key
     */

    public function getKey($md5 = true)
    {
        $get = $_SERVER['REQUEST_URI'];
        $post = '';
        if ($this->ajaxDifferent && $this->isAjax()) {
            $ajax = '&_uho_cache_ajaxable';
        } else {
            $ajax = '';
        }
        if ($this->additionalParam) {
            $ajax .= '&' . $this->additionalParam;
        }

        if ($this->addPost && $_POST) {
            $post = '&' . http_build_query($_POST);
        }

        $add = [];
        foreach ($this->requestParams as $k => $v) {
            switch ($k) {
                case "headers":

                    $h = [];
                    $h0 = getallheaders();
                    foreach ($h0 as $kk => $vv)
                        $h[strtolower($kk)] = $vv;

                    foreach ($v as $kk => $vv)
                        if (!empty($h[$vv]))
                            $add[$vv] = $h[$vv];
            }
        }
        if ($add) $add = '&' . http_build_query($add);
        else $add = '';

        $key = $this->salt . $get . $ajax . $post . $add;
        if ($md5) $key = md5($key);
        return ($key);
    }

    /**
     * Sets unique key based on HTTP request, hashed with md5
     * @param string $key key
     * @param boolean $ms5 if true, md5 is performed on key
     * @return string key
     */

    public function setKey($s, $md5 = true)
    {
        if ($md5) {
            $key = md5($this->salt . $s);
        } else {
            $key = $s;
        }
        $this->cache->setCache($key);
        return $key;
    }

    /**
     * Checks if current key contains any data in cache
     * @return boolean returns true if positive
     */

    public function checkCache()
    {
        $this->result = '';
        $this->result = $this->cache->retrieve('html');
        if ($this->result) {
            return (true);
        } else {
            return (false);
        }
    }

    /**
     * Gets cached data from current key
     * @return string returns cached data
     */

    public function getCache()
    {
        return ($this->result);
    }

    /**
     * Stores cached data from current key
     * @param string $key hashed key to use in caching storage
     * @param string $data data to be stored
     * @param timestamp $expiration expiration time stamp, if null no expiration date is set
     * @return null
     */

    public function store($key, $data, $expiration = null)
    {
        $this->cache->store('html', $data, $expiration);
    }

    /**
     * Removes all expired files from cache folder
     * @return null
     */

    public function eraseExpired()
    {
        $this->cache->eraseExpired();
    }

    /**
     * Removes all the files from cache folder
     * @return null
     */

    public function eraseAll()
    {
        $this->cache->eraseAll();
    }
}
