<?php

namespace Huncwot\UhoFramework;

use SimplePHPCache\Cache;
use Huncwot\UhoFramework\_uho_fx;

require_once "cache.class.php";

/**
 * This is a class dedicated to direct mySQL connections
 * It supports mySQL query caching to local files
 */

class _uho_mysqli
{
    /**
     * array log of all queries
     */
    private $query_log = [];
    /**
     * indicates if memcache is enabled
     */
    private $memQuery = false;
    /**
     * indicates start timestamp of the query
     */
    private $perfromance_start = null;
    /**
     * indicates if debug should be rendered
     */
    private $debug;
    /**
     * current mySQL database link
     */
    private $base_link;
    /**
     * folder for error logs
     */
    private $error_folder = '';
    private $halt_on_error = true;
    /**
     * number of performed queries
     */
    private $iQuery = 0;
    /**
     * indicates if queries should be cached via files
     */
    private $cache = false;
    private $cacheSalt = '';
    private $cacheSkipTables = [];
    /**
     * array for memcache
     */
    private $memcache = array();
    /**
     * default charset
     */
    private $charset = 'utf8mb4'; //utf8';

    /**
     * Constructor
     * @param boolean $df debug true/false
     * @param string $error_folder for debug logs
     * @return null
     */

    public function __construct($df = null, $error_folder = '')
    {
        $this->debug = $df;
        $this->error_folder = $error_folder;
        if (!defined("MYSQL_ASSOC")) define('MYSQL_ASSOC', 1);
    }


    /**
     * Sets error logs folder
     *
     * @param string $error_folder for debug logs
     */
    public function setErrorFolder($folder): void
    {
        $this->error_folder = $folder;
    }

    /**
     * Add query to debug log
     *
     * @param string $query
     */
    public function addQueryLog($query): void
    {
        $this->query_log[] = $query;
    }

    /**
     * Returns last error log
     * @return string
     */

    public function getLastQueryLog()
    {
        if ($this->query_log) return $this->query_log[count($this->query_log) - 1];
    }

    /**
     * Returns last error log
     * @return string
     */

    public function getQueryLogs()
    {
        if ($this->query_log) return $this->query_log;
    }

    /**
     * Returns last mysql error
     * @return string
     */

    public function getError()
    {
        return $this->base_link->error;
    }

    /**
     * Increments number of executed queries
     * @return int
     */

    public function addIQuery()
    {
        $this->iQuery++;
        return $this->iQuery;
    }

    /**
     * Returns number of executed queries
     * @return int
     */

    public function getIQuery()
    {
        return $this->iQuery;
    }

    /**
     * Returns current DB link
     * @return object
     */

    public function getBase()
    {
        return $this->base_link;
    }

    /**
     * Enables memory (session) cache for queries
     */
    public function setMemCache(): void
    {
        $this->memQuery = true;
    }

    /**
     * Disables memory (session) cache for queries
     */
    public function disableMemCache(): void
    {
        $this->memQuery = false;
        $this->cache = null;
    }

    /**
     * Adds error-query to the log
     *
     * @param string $query
     */
    private function errorAdd($query): void
    {
        if ($this->error_folder) {
            $fname = $this->error_folder . '/sql_errors.txt';
            $f = fopen($fname, "a");
            if (!$f) exit('MySQL (sqli) report error at: ' . $fname);
            fputs($f, $query . chr(13) . chr(10));
            fclose($f);
        }
    }

    /**
     * Connects to the database
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $name
     * @return boolean
     */

    public function init($host, $user, $pass, $name)
    {

        $v = explode(':', $host);
        if (count($v) > 1) {
            $port = array_pop($v);
            $host = implode(':', $v);
        } else {
            $port = null;
        }
        try {
            $this->base_link = new \mysqli($host, $user, $pass, $name, $port);
        } catch (\Exception $e) {
            exit('SQL Connection Error.');
        }


        if ($this->base_link->connect_errno) {
            if ($this->debug) {
                $this->debug->add('mysql connect error');
            }
            return (false);
        } else {
            $this->base_link->set_charset($this->charset);
            mysqli_report(MYSQLI_REPORT_OFF);
            return (true);
        }
    }

    /**
     * Changes DB to another one
     * @param object $string
     * @return object
     */

    public function changeDB($db)
    {
        $r = $this->base_link->select_db($db);
        return $r;
    }

    /**
     * Sets DB charset
     *
     * @param string $charset
     */
    public function setCharset($charset): void
    {
        $this->base_link->set_charset($charset);
    }

    /**
     * Closes mySQL connection
     */
    public function close(): void
    {
        $this->base_link->close();
    }


    /**
     * Fetches mySQL query
     * @param $t
     * @param boolean $stripslashes
     * @return array
     */

    private function fetchQuery($t, $stripslashes = false)
    {
        $tt = array();

        if ($t) {
            while ($t1 = $t->fetch_array(MYSQLI_ASSOC)) {
                foreach ($t1 as $k => $v) {
                    if ($stripslashes) {
                        $t1[$k] = stripslashes($v);
                    }
                }
                array_push($tt, $t1);
            }
        }
        return $tt;
    }

    public function queryPrepared($query, $params, $first=false)
    {
        if (!empty($this->base_link)) {

            $stmt = $this->base_link->prepare($query);

            $types = '';
            $values = [];
            foreach ($params as $p) {
                $types  .= $p[0];
                $values[] = $p[1];
            }

            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result) {                                
                $result = $this->fetchQuery($result);
                if ($first && is_array($result)) $result=$result[0];
            }
            return $result;
        }
    }



    /**
     * Runs mySQL read-only query, using cache if possible
     * @param string $query
     * @param boolean $single
     * @param boolean $stripslashes
     * @param string $key
     * @param boolean $force_sql_cache
     * @return array
     */

    public function query($query, $single = false, $stripslashes = true, $key = null, $force_sql_cache = false)
    {

        if ($this->memQuery) {
            if (!isset($this->memcache[$query])) {
                $this->memcache[$query] = $this->queryReal($query, $single, $stripslashes, $key);
            }
            return $this->memcache[$query];
        } else {
            return $this->queryReal($query, $single, $stripslashes, $key, $force_sql_cache);
        }
    }

    /**
     * Runs mySQL read-only query
     * @param string $query
     * @param boolean $single
     * @param boolean $stripslashes
     * @param string $key
     * @param boolean $force_sql_cache
     * @return array
     */

    public function queryReal($query, $single = false, $stripslashes = true, $key = null, $force_sql_cache = false)
    {
        $this->perfromance_start=_uho_fx::microtime_float();
        $this->iQuery++;

        $cached = '[sql]';
        if (isset($this->cache) && $this->cache && ($force_sql_cache || $this->cacheSkip($query))) {
            $t = true;
            $tt = $this->cacheGet($query);

            if ($tt['cached']) {
                $cached = '[sql-cached] ';
            }
            $tt = $tt['result'];
        } else {

            $t = $this->base_link->query($query);
            if ($t) {
                $tt = $this->fetchQuery($t);
            }
        }

        /*
            debug
        */
        if ($this->debug && _uho_fx::getGet('dbg') && ($cached == '[sql]' || _uho_fx::getGet('dbg') != 'performance')) {
            if (_uho_fx::getGet('dbg') == 'performance')  $time = '[T=' . number_format((_uho_fx::microtime_float() - $this->perfromance_start), 4) . '] ';
            else $time = '';
            if (!isset($tt) || !$tt) $i = 0;
            else $i = count($tt);
            if ($i > 50 || intval($time) >= 1) $warning = '[WARNING] ';
            else $warning = '';
            echo ('<!--' . $cached . ' ' . $warning . $time . '[R=' . $i . '] ' . $query . '
        -->');
        }

        if (!$t) {
            if (_uho_fx::getGet('dbg') && $this->debug) {
                exit('mysql error:' . $query . '<br>Error: ' . $this->base_link->error);
            } else {
                $this->errorAdd($query . ' ... ' . $this->base_link->error);
                if ($this->halt_on_error) exit('error:' . $query . '<br>Error: ' . $this->base_link->error);
                else return false;
            }
        } else {
            if ($key) {
                $new = array();
                foreach ($tt as $v) {
                    $new[$v[$key]] = $v;
                }
                $tt = $new;
            }

            if ($single && isset($tt[0])) {
                $tt = $tt[0];
            }
            return ($tt);
        }
    }
    /**
     * Runs mySQL write query
     * @param string $query
     * @return boolean
     */

    public function queryOut($query)
    {
        if (!$this->base_link) {
            $this->errorAdd('No MySQL Connection');
        } else {
            $this->addQueryLog($query);
            $t = @$this->base_link->query($query);
        }

        if (!$t) {
            $this->errorAdd($query);
        }

        if (_uho_fx::getGet('dbg') && $this->debug) {
            echo ('<!-- ' . $query . ' -->');
        }

        return ($t);
    }

    /**
     * Runs mySQL write queries
     * @param string $query
     * @return boolean
     */

    public function queryMultiOut($query)
    {
        $t = $this->base_link->multi_query($query);
        if (!$t) {
            $this->errorAdd($query);
        }
        return ($t);
    }

    /**
     * Runs mySQL write queries
     * @param string $query
     * @return boolean
     */
    public function multiQueryOut($query)
    {
        $t = $this->base_link->multi_query($query);
        if (!$t) {
            $this->errorAdd($query);
        }
        return ($t);
    }

    /**
     * Returns mySQL last added record id
     * @return int
     */
    public function insert_id()
    {
        return $this->base_link->insert_id;
    }

    /**
     * Returns escaped string
     * @param string $s
     * @return string
     */

    public function real_escape_string($s)
    {
        return $this->base_link->real_escape_string($s);
    }

    /**
     * Returns escaped string
     * @param string $s
     * @return string
     */
    public function safe($s)
    {
        return $this->base_link->real_escape_string($s);
    }

    /**
     * Returns number of affected rows in last query
     * @return int
     */

    public function affected_rows()
    {
        return $this->base_link->affected_rows;
    }

    /**
     * Checkes if query can be cached
     * @param string $query
     * @return boolean
     */

    private function cacheSkip($query)
    {
        $result = true;
        if ($this->cacheSkipTables) {
            $query = explode(' from ', strtolower($query));
            if (isset($query[1])) $query = explode(' where ', strtolower($query[1]));
            $query = explode(' order ', strtolower($query[0]));
            $query = explode(',', $query[0]);
            foreach ($query as $k => $v) {
                $query[$k] = trim($v);
            }
            foreach ($this->cacheSkipTables as $v) {
                if (in_array($v, $query)) {
                    $result = false;
                }
            }
        }
        return $result;
    }

    /**
     * Enables cache,
     *
     * @param string $salt
     * @param array $skipTables
     */
    public function cacheSet($salt, $skipTables = null, $filename_salt = '', $extension = null): void
    {
        $this->cacheSalt = $salt;
        $this->cacheSkipTables = $skipTables;

        $this->cache = new Cache(
            ['path' => 'cache/', 'salt' => $filename_salt, 'extension' => $extension]
        );
    }

    /**
     * Disables cache
     */
    public function cacheDisable(): void
    {
        $this->cacheSkipTables = [];
        unset($this->cache);
    }

    /**
     * Sets cache folder
     *
     * @param string $folder
     */
    public function cacheSetFolder($folder): void
    {
        if ($this->cache) {
            $this->cache->setCachePath($folder . '/');
        }
    }

    /**
     * Gets data from cache
     * @param string $query
     * @return array
     */

    private function cacheGet($query)
    {
        if ($this->cache) {
            $this->cache->setCache(md5($query));
            $result = $this->cache->retrieve('sql');
            if ($result === null) {
                $result = $this->base_link->query($query);
                $result = $this->fetchQuery($result);
                $this->cache->store('sql', $result);
                $result = array('cached' => false, 'result' => $result);
            } else {
                $result = array('cached' => true, 'result' => $result);
            }
        }
        return $result;
    }

    /**
     * Clears file cache
     *
     * @param string $dir
     */
    public function cacheKill($dir, $extensions = ['cache']): void
    {
        if ($dir) {
            $dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . trim($dir, '/');
            ini_set('memory_limit', '256M');
            $scan = @scandir($dir);
            if ($scan)
                foreach ($scan as $item) {
                    $path_parts = pathinfo($item);
                    if ($item == '.' || $item == '..' || !in_array($path_parts['extension'], $extensions)) continue;
                    unlink($dir . DIRECTORY_SEPARATOR . $item);
                }
        }
    }

    public function setHaltOnError(bool $halt): void
    {
        $this->halt_on_error = $halt;
    }

    public function setDebug($dbg)
    {
        $this->debug=$dbg;
    }

}
