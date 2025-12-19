<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_thumb;


/**
 * This is an ORM class providing model-based communication
 *  with mySQL databases, using JSON composed model structures
 * It also supports image caching including S3 support
 */

class _uho_orm
{
    /**
     * indicates if filesDecache should be performed
     */
    private $filesDecache = false;
    private $filesDecache_style = 'standard';
    private $halt_on_error = true;
    private $temp_public_folder = '/temp';
    /**
     * indicates if for elements_double fields we should
     * use integer is only one value is set
     */
    private $elements_double_first_integer = false;
    /**
     * array of shortcuts of current languages available
     */
    private $langs = [];
    private $lang = '';
    /**
     * array of root_paths where orm is looking for JSON model files
     */
    private $root_paths = [];
    /**
     * indicates if debug is enabled
     */
    private $debug;
    /**
     * array of query errors
     */
    private $errors = [];
    /**
     * indicates if we should log_errors
     */
    private $log_errors = false;
    /**
     * indicates if there is folder to be replaced
     * for media assets
     */
    private $folder_replace = null;
    /**
     * alternative tables for selected models
     */
    private $altTables = [];
    /**
     * caching array for S3 suppoer
     */
    private $uhoS3 = null;
    private $s3cache = null;
    private $s3compress = null;

    private $sql;
    private $lang_add;
    private $keys;
    private $test;

    /**
     * Constructor
     * @param object $model instance of _uho_model class
     * @param object $sql instance of _uho_mysqli class
     * @param string $lang shortcut of current language
     * @param array $keys pair of encryption keys
     * @return null
     */

    function __construct($model, $sql, $lang, $keys, $test = false)
    {
        $this->sql = $sql;
        $this->lang = $lang;
        $this->keys = $keys;
        $this->test = $test;

        $this->root_paths[] = '/application/models/json/';
        if ($lang) $this->lang_add = '_' . strtoupper($lang);
    }

    /**
     * Changes current language
     *
     * @param string $lang
     */
    public function changeLang($lang): void
    {
        $this->lang = $lang;
        if ($lang)
            $this->lang_add = '_' . strtoupper($lang);
    }

    private $addImageSizes = false;

    /**
     * Sets errors log
     *
     * @param string $q
     */
    public function setLogErrors($q): void
    {
        $this->log_errors = $q;
    }

    /**
     * Prints errors log
     */
    public function printErrors(): void
    {
        print_r($this->errors);
    }

    /**
     * Clear all root paths
     */
    public function removeRootPaths(): void
    {
        $this->root_paths = [];
    }

    /**
     * Adds a root path
     */
    public function addRootPath($path): void
    {
        $this->root_paths[] = $path;
    }

    /**
     * Returns all possible Json file paths
     * @return array
     */

    public function getLoadJsonPaths()
    {
        $result = [];
        foreach ($this->root_paths as $v) {
            $result[] = $_SERVER['DOCUMENT_ROOT'] . $v;
        }
        if (!$result) $result[] = 'No path specified';
        return $result;
    }

    /**
     * Loads and parses JSON file
     * @param string $filename
     * @return array
     */

    public function loadJson($filename)
    {

        $loaded = false;

        if (!strpos($filename, '.json')) $filename .= '.json';
        foreach ($this->root_paths as $v)
            if (!isset($m)) {
                $load = $_SERVER['DOCUMENT_ROOT'] . $v . $filename;
                $m = @file_get_contents($load);
                if (!$m) {
                    $load =  $v . $filename;
                    $m = @file_get_contents($load);
                }

                if ($m) $loaded = true;
                if ($m) $json = json_decode($m, true);
                else unset($m);
                if (isset($m) && !isset($json)) exit('_uho_orm::JSON parsing error: ' . $v . $filename);
            }
        if (!isset($json) && $loaded) $this->errors[] = 'JSON corrupted: ' . $filename;
        elseif (!isset($json)) $this->errors[] = 'JSON not found:loadJson: ' . $filename;
        if (isset($json)) return $json;
    }

    /**
     * Sets keys for encryption
     *
     * @param array $keys
     */
    public function setKeys($keys): void
    {
        $this->keys = $keys;
    }

    /**
     * Sets set of available languages
     *
     * @param array $t
     */
    public function setLangs($t): void
    {
        foreach ($t as $v)
            $this->langs[] = ['lang' => $v, 'lang_add' => '_' . strtoupper($v)];
    }

    /**
     * Sets filedecache variable
     *
     * @param string $q
     */
    public function setFilesDecache($q): void
    {
        if (is_string($q) && in_array($q, ['standard', 'medium'])) {
            $this->filesDecache_style = $q;
            $q = 1;
        }
        $this->filesDecache = $q;
    }

    /**
     * mySQL string sanitizatin
     * @param string $s
     * @return string
     */

    public function sqlSafe($s)
    {
        if (!$this->sql && $this->test) return $s;
        if (!$this->sql) exit('_uho_orm::sqlSafe::sql-not-defined');
        if ($s && !is_array($s)) return ($this->sql->getBase()->real_escape_string($s));
    }

    /**
     * Sets debug
     *
     * @param boolean $q
     */
    public function setDebug($q): void
    {
        $this->debug = $q;
    }

    /**
     * Runs mySQL read-only query
     * @param string $query
     * @param boolean $single
     * @param boolean $stripslashes
     * @param string $key
     * @param string $do_field_only
     * @param boolean $force_sql_cache
     * @return array
     */

    public function query($query, $single = false, $stripslashes = true, $key = null, $do_field_only = null, $force_sql_cache = false)
    {
        if (!$this->sql) return;

        // replaceing :lang occurency
        if (strpos($query, ':lang')) {
            $query = explode('FROM', $query);
            $select = $query[0];
            while ($i = strpos($select, ':lang')) {
                $j = $i;
                while ($j >= 0 && $select[$j] != ' ' && $select[$j] != ',') $j--;
                $field = substr($select, $j + 1, $i - $j - 1);
                $field_only = explode('.', $field);
                $field_only = array_pop($field_only);

                if ($this->lang_add) {
                    $field = $field . $this->lang_add;
                    $next = strtolower(trim(substr($select, $i + 5)));
                    if (substr($next, 0, 2) == 'as');
                    else $field .= ' AS `' . $field_only . '`';
                } elseif ($this->langs) {
                    $f = [];
                    foreach ($this->langs as $v2) {
                        $f[] = $field . $v2['lang_add'];
                    }
                    $field = implode(', ', $f);
                }
                $select = substr($select, 0, $j + 1) . $field . substr($select, $i + 5);
            }
            $query[0] = $select;
            $query = implode('FROM', $query);
        }

        // sql query
        $result = $this->sql->query($query, $single, $stripslashes, $key, $force_sql_cache);

        if ($result && is_array($result) && $do_field_only) {
            foreach ($result as $k => $v) if (is_array($v)) $result[$k] = $v[$do_field_only];
        }
        return $result;
    }


    /**
     * Runs mySQL write-only query
     * @param string $query
     * @return boolean
     */

    public function queryOut($query)
    {
        $result = $this->sql->queryOut($query);
        if (!$result && $this->log_errors)
            $this->sql->queryOut('INSERT INTO uho_orm_errors SET query="' . $this->sqlSafe($query) . '"');
        return $result;
    }

    /**
     * Runs mySQL write multi-query
     * @param string $query
     * @return boolean
     */

    public function queryMultiOut($query)
    {
        return ($this->sql->queryMultiOut($query));
    }


    /**
     * Runs mySQL write multi-query
     * @param string $query
     * @return boolean
     */

    public function multiQueryOut($query)
    {
        return ($this->sql->multiQueryOut($query));
    }


    /**
     * Updates model field with language functions
     * @param string $field
     * @param $v0
     * @param $full 
     * @return boolean
     */

    public function getJsonModelUpdateField($field, $v0, $full = null)
    {

        if ($v0)
            switch ($field) {
                case "text":
                    if (isset($full['function']))
                        switch ($full['function']) {
                            case "nl2br":
                                $v0 = explode(chr(13) . chr(10), $v0);
                                break;
                            default:
                                break;
                        }
                    break;

                case "datetime":

                    if (!empty($full['settings']['format']))
                        switch ($full['settings']['format']) {
                            case "ISO8601":
                            case "UTC":
                                try {
                                    $dt = new \DateTime($v0, new \DateTimeZone('UTC'));
                                    $v0 = $dt->format('Y-m-d\TH:i:s\Z');
                                } catch (\Exception $e) {
                                    $v0 = null;
                                }

                                break;
                        }
                    break;
                case "table":

                    if (is_string($v0)) {
                        $temp = $v0;
                        $v0 = json_decode(($v0), true);
                        if ($v0 && isset($full['settings']['fields']) && $full['settings']['fields']) {
                            $rows = [];
                            foreach ($v0 as $v) {
                                $row = [];
                                foreach ($full['settings']['header'] as $kk => $vv)
                                    $row[$vv['field']] = $v[$kk];
                                $rows[] = $row;
                            }
                            $v0 = $rows;
                        }

                        if ($v0 && isset($full['settings']['format']) && $full['settings']['format'] == 'object') {
                            $v1 = $v0;
                            $v0 = [];
                            foreach ($v1 as $k => $v)
                                $v0[] = [$k, $v];
                        }
                        if (!$v0 && isset($full['settings']['read_string_format'])) {
                            $temp = explode(chr(13) . chr(10), $temp);
                            foreach ($temp as $k => $v) $temp[$k] = explode(';', $v);
                            $v0 = $temp;
                        }
                    }


                    break;
                case "template":

                    if ($v0 && is_string($v0)) $v0 = @json_decode(stripslashes($v0), true);
                    $vv = array();

                    if ($full['fields'])
                        foreach ($full['fields'] as $v) {
                            if (!is_array($v)) {
                                $v = explode('#', $v);
                                $v = array('field' => $v[0], 'label' => $v[1], 'type' => $v[2]);
                            }

                            if (strpos($v['field'], ':lang')) {
                                $field = explode(':lang', $v['field']);
                                $field = array_shift($field);
                                $vv[$field] = $v0[$field . $this->lang_add];
                            } else $vv[$v['field']] = $v0[$v['field']];
                        }
                    $v0 = $vv;
                    break;
            }
        return $v0;
    }


    /**
     * Updates HTML template, based on %string%
     * @param string $vv
     * @param array $v
     * @param boolean $twig 
     * @return string
     */

    private function updateTemplate($vv, $v, $twig = false)
    {
        if ($v)
            foreach ($v as $k3 => $v3)
                if (is_string($v3))
                    $vv = str_replace('%' . $k3 . '%', $v3, $vv);
        if ($twig) $vv = $this->getTwigFromHtml($vv, $v);
        return $vv;
    }

    /**
     * Converts filters model to mySQL query
     * @param string $model
     * @return string
     */

    public function getJsonModelFiltersQuery($model)
    {

        $swap = [];
        if (is_array($model['filters']))
            foreach ($model['filters'] as $k => $v)
                if ($v === NULL) unset($model['filters'][$k]);
                else {
                    // possible variations for filter item
                    // { "value" : 1 }
                    // { [{"operator" : ">", { "value": 1 }],[{"operator" : "<", { "value": 10 }] }
                    // { {"operator" : "in", { "value": ["2020-02-01","date_from","date_to"] }
                    // { [1,2,3 ] }
                    // { "type": "sql", "value":"CONCAT (....)"
                    // { "type": "custom", "join":"||","value":["",""] }

                    // disabling field_swap
                    //if (is_array($v) && isset($v['field'])) $field_swap = $v['field'];
                    //else $field_swap = null;

                    $field_key = $k;

                    if (is_array($v) && isset($v['field'])) {
                        $field_key = $v['field'];
                        $v = $v['value'];
                    }

                    $raw = false;
                    if (isset($v['function'])) $function = $v['function'];
                    else $function = null;
                    if (isset($v['collate'])) $collate = ' collate utf8_general_ci ';
                    else $collate = null;

                    if (isset($v['operator']) && $v['operator'] == '!=' && !isset($v['value']))
                        $v['value'] = '';

                    if (is_array($v) && @$v['type'] == 'custom') {
                        if (is_string($v['value']))
                            $model['filters'][$k] = '(' . $v['value'] . ')';
                        else $model['filters'][$k] = '(' . implode(' ' . @$v['join'] . ' ', $v['value']) . ')';
                    } elseif (isset($v['type']) && $v['type'] == 'sql') {
                        $eq = '=';
                        $raw = true;
                        $v = $v['value'];
                    } elseif (is_array($v) && isset($v['value'])) {
                        $eq = @$v['operator'];
                        $v = $v['value'];
                    } else $eq = '=';


                    // field

                    $field = _uho_fx::array_filter($model['fields'], 'field', $field_key, array('first' => true));

                    $or = null;

                    if (isset($field['hash']) && !$this->keys) exit('_uho_orm::getJsonModelFiltersQuery::nokeys');
                    if (isset($field['hash'])) $v = _uho_fx::encrypt($v, $this->keys, $field['hash']);

                    if ($field)
                        switch (@$field['type']) {
                            case "boolean":
                                $v = intval($v);
                                break;

                            case "elements":
                            case "checkboxes":

                                $iDigits = 8;
                                if (@$field['output'] == '4digits') $iDigits = 4;
                                if (@$field['output'] == '6digits') $iDigits = 6;
                                if (@$field['output'] == '8digits') $iDigits = 8;
                                if (@$field['output'] == 'string') $iDigits = 0;

                                if ($eq == '!=' && !$v) {

                                    // leaving stanadrd !=''
                                } else {
                                    if (!is_array($v)) $v = explode(',', $v);
                                    foreach ($v as $k2 => $v2)
                                        if ($iDigits) {
                                            if (!intval($v2)) unset($v[$k2]);
                                            else $v[$k2] = _uho_fx::dozeruj($v2, $iDigits);
                                        }
                                    if ($eq == '!=') $eq = '%!LIKE%';
                                    else $eq = '%LIKE%';
                                    if (@$field['type2'] == 'strict') $or = ' && ';
                                }
                                if ($eq != '!=' && !$v) $v = null;
                                break;
                        }

                    if (strpos($k, ':lang')) {
                        $k2 = explode(':lang', $k)[0] . $this->lang_add;
                        $swap[$k] = $k2;
                        $k = $k2;
                    }

                    if (!empty($field['settings']['case'])) $pre_field = 'BINARY ';
                    else $pre_field = '';

                    // disable field_swap
                    //if ($field_swap) $field = $field_swap;
                    //else $field = $k;\

                    $field = $field_key;


                    // multiple values
                    if (is_array($v) && @$v['type'] == 'custom');
                    elseif (is_array($v)) {
                        if (is_array($model['filters'][$k]) && ($eq == '=' || $eq == '!='))
                            foreach ($model['filters'][$k] as $k2 => $v2)
                                $model['filters'][$k][$k2] = $this->sqlSafe($v2);

                        if ($or);
                        elseif ($eq == '!=') $or = ' && ';
                        else $or = ' || ';

                        if ($eq == 'in') {
                            if (count($v) == 2)
                                $model['filters'][$k] = '(`' . $field . '`>="' . $v[0] . '" && `' . $field . '`<="' . $v[1] . '")';
                            else $model['filters'][$k] = '(' . $v[1] . '<="' . $v[0] . '" && ' . $v[2] . '>="' . $v[0] . '")';
                        } elseif ($eq == '%LIKE%') $model['filters'][$k] = '(`' . $field . '` LIKE "%' . implode('%" ' . $or . ' `' . $field . '` LIKE "%', $v) . '%")';
                        elseif ($eq == '%!LIKE%') {
                            $or = '&&';
                            $model['filters'][$k] = '(`' . $field . '` NOT LIKE "%' . implode('%" ' . $or . ' `' . $field . '` NOT LIKE "%', $v) . '%")';
                        } else {
                            $model['filters'][$k] =
                                '(' . $field . $eq . '"' . @implode('" ' . $or . ' ' . $field . $eq . '"', $v) . '")';
                        }
                    } elseif ($v === NULL) unset($model['filters'][$k]);
                    else if ($eq == '%LIKE%') {
                        if ($function)
                            $model['filters'][$k] = $function . '(`' . $field . '`' . $collate . ') LIKE "%' . $this->sqlSafe($v) . '%"';
                        else {
                            $model['filters'][$k] = $pre_field . '`' . $field . '`' . $collate . ' LIKE "%' . $this->sqlSafe($v) . '%"';
                        }
                    } else if ($eq == 'LIKE%') $model['filters'][$k] = $field . ' LIKE "' . $this->sqlSafe($v) . '%"';
                    else if ($eq == '%LIKE') $model['filters'][$k] = $field . ' LIKE "%' . $this->sqlSafe($v);
                    else if ($eq == '=' && $collate) {
                        $model['filters'][$k] = $function . '(' . $field . $collate . ') = "' . $this->sqlSafe($v) . '"';
                    } else if ($raw) $model['filters'][$k] = '`' . $field . '`' . $eq . $v;
                    else {
                        //
                        //if ($field['hash']) $model['filters'][$k] = $k . $eq.'md5("' . $this->sqlSafe($v) . '")';
                        //    else 
                        if (is_integer($v))
                            $model['filters'][$k] = $field . $eq . $v;
                        else $model['filters'][$k] = $pre_field . '`' . $field . '`' . $eq . '"' . $this->sqlSafe($v) . '"';
                    }
                }
        if ($swap) {


            $f = [];
            foreach ($model['filters'] as $k => $v)
                if (isset($swap[$k]));
                else $f[] = $v;
            $model['filters'] = $f;
        }

        return $model['filters'];
    }

    /**
     * getJsonModel method helper, using params
     * @param string $name
     * @param array $p
     * @return array
     */

    public function getJsonModel0($name, $p)
    {
        $defaults = array(
            'filters' => null,
            'single' => false,
            'order' => null,
            'limit' => null,
            'count' => false,
            'dataOverwrite' => null,
            'cache' => false
        );

        foreach ($defaults as $k => $v)
            if (!isset($p[$k])) $p[$k] = $v;


        return $this->getJsonModel(
            $name,
            $p['filters'],
            $p['single'],
            $p['order'],
            $p['limit'],
            $p['count'],
            $p['dataOverwrite'],
            $p['cache']
        );
    }


    /**
     * Return model filters
     * @param $name
     * @param array $filters
     * @param boolean $single
     * @param string $order
     * @param string $lmit
     * @param boolean $count
     * @param boolean dataOverwrite
     * @param boolean cache
     * @param string groupBy 
     * @return array
     */

    public function getJsonModelFilters($name, $filters = null, $single = false, $order = null, $limit = null, $count = false, $dataOverwrite = null, $cache = false, $groupBy = null)
    {

        if ($cache) {
            $md5 = array($name, $filters, $single, $order, $limit, $count, $dataOverwrite, $this->lang_add);
            $md5 = md5(serialize($md5));
            if ($_SESSION['uho_model_' . $md5]) return $_SESSION['uho_model_' . $md5];
        }

        if (is_array($name)) {
            $model = $name;
        } else {
            $filename = $name . '.json';
            $model = $this->loadJson($filename);
            if (!$model) {
                if ($this->debug) exit('_uho_orm::' . $this->getLastError() . ' @ ' . implode(', ', $this->getLoadJsonPaths()));
                return array();
            }
        }

        if ($count) {
            // not possible because of filters            
        }


        if (!$model) exit('model corrupted:' . $name);

        // filters ==================================================================        
        if (!isset($model['filters'])) $model['filters'] = array();

        if (is_array($filters)) {
            if ($filters) $model['filters'] = array_merge($model['filters'], $filters);
            $model['filters'] = $this->getJsonModelFiltersQuery($model);
            if ($model['filters']) $model['filters'] = 'WHERE ' . implode(' && ', $model['filters']);
        } elseif ($filters) $model['filters'] = 'WHERE ' . $filters;

        if (!$model['filters']) $model['filters'] = '';
        return $model['filters'];
    }

    /**
     * Checks mySQL connection
     *
     * @param null|string $message
     */
    private function checkConnection(string|null $message = null): void
    {
        if (!$this->sql) exit('_uho_orm::No SQL defined::' . $message);
    }

    /**
     * Return model data by filters
     * @param string $model
     * @param array $filters
     * @param array $params
     * @return array
     */

    public function getJsonModelShort($model, $filters, $params)
    {
        $schema = $this->getJsonModelSchema($model);
        if (!$filters) $filters = [];
        if ($schema['filters']) $filters = array_merge($schema['filters'], $filters);
        $data = $this->getJsonModel($schema, $filters);
        if ($schema['model']) {
            foreach ($data as $k => $v) {
                $m = $schema['model'];
                foreach ($m as $k2 => $v2)
                    $m[$k2] = $this->getTwigFromHtml($v2, $v);
                $m['id'] = $v['id'];
                $m['_model_label'] = $schema['label'];
                $data[$k] = $m;
            }
        }
        return $data;
    }

    /**
     * Loads model schema using PageUpdate
     * @param string $name
     * @param  $lang
     * @return array
     */

    public function getJsonModelSchemaWithPageUpdate($name, $lang = false)
    {
        $d = $this->getJsonModelSchema($name, $lang);

        if (isset($d['page_update'])) {
            if (!is_array($d['page_update'])) $d['page_update'] = ['file' => $d['page_update']];
            $pattern = $d['page_update']['file'];

            $models = [];
            $d = $this->updateSchemaSources($d);
            foreach ($d['fields'] as $v) {
                if (isset($v['options']))
                    foreach ($v['options'] as $v2) {
                        $v2[$v['field']] = @$v2['values'];
                        if ($v2) {
                            $new = $this->getTwigFromHtml($pattern, $v2);
                            if ($new != $pattern) $models[] = $new;
                        }
                    }
            }
        }
        if (isset($models)) $d = $this->getJsonModelSchema(array_merge([$name], $models), $lang);
        return $d;
    }

    /**
     * Loads model schema
     * @param $name
     * @param $lang
     * @param array $params
     * @return array
     */

    public function getJsonModelSchema($name, $lang = false, $params = [])
    {

        if (!$name) exit('_uho_orm::getJsonModelSchema::no model name specified');

        // return itself if calling with actual schema
        if (is_array($name) && isset($name['table'])) return $name;

        // getting and merging array of models
        if (is_array($name)) {

            $inital_names = [];
            $model = [];
            foreach ($name as $k => $v)
                if ($v) {
                    if (is_array($v)) {
                        $name = $v['model'];
                        $position_after = @$v['position_after'];
                    } else {
                        $name = $v;
                        $position_after = null;
                    }

                    if (is_array($name)) exit('_uho_orm::getJsonModelSchema::model as array');

                    $m = $this->loadJson($name);

                    if ($k > 0 && isset($m['fields']))
                        foreach ($m['fields'] as $kk => $_) {
                            if (!isset($m['fields'][$kk]['_original_models'])) $m['fields'][$kk]['_original_models'] = [];
                            $m['fields'][$kk]['_original_models'][] = $name;
                        }
                    //if ($m) 
                    $inital_names[] = $name;

                    if (!$model) $model = $m;
                    else
                    if (is_array($m)) {
                        foreach ($m as $k2 => $v2)
                            if ($model[$k2]) {
                                // removing previous field of the same field name
                                foreach ($v2 as $k3 => $v3)
                                    if ($v3['field']) {
                                        $exists = _uho_fx::array_filter($model[$k2], 'field', $v3['field'], ['first' => true, 'keys' => true]);
                                        if ($exists !== false) {
                                            if (isset($model[$k2][$exists]['_original_models'])) {

                                                if (!isset($v2[$k3]['_original_models'])) $v2[$k3]['_original_models'] = [];
                                                $v2[$k3]['_original_models'] = array_merge($v2[$k3]['_original_models'], $model[$k2][$exists]['_original_models']);
                                            }
                                            unset($model[$k2][$exists]);
                                        }
                                    }
                                // positioning new fields
                                if ($k2 == 'fields' && $position_after) {

                                    $k3 = _uho_fx::array_filter($model[$k2], 'field', $position_after, ['first' => true, 'keys' => true]);
                                    if (isset($k3))
                                        $model[$k2] = array_merge(
                                            array_slice($model[$k2], 0, $k3 + 1),
                                            $v2,
                                            array_slice($model[$k2], $k3 + 1)
                                        );
                                }
                                // adding records to the end of the array
                                else $model[$k2] = array_merge($model[$k2], $v2);
                            } else  $model[$k2] = $v2;
                    }
                    if ($model && !isset($model['model_name'])) $model['model_name'] = $name;
                }
            if (!$model && $this->debug) $this->halt('_uho_orm::JSON not found @ ' . implode(', ', $inital_names));
        }
        // getting just one model
        else {

            $filename = $name . '.json';
            $model = $this->loadJson($filename);

            if ($model && !isset($model['model_name'])) $model['model_name'] = $name;
            $message = '_uho_orm::JSON not found: ' . $filename . ' @ ' . implode(', ', $this->root_paths);
            if (!$model && (!$this->halt_on_error || isset($params['return_error'])))
                return ['result' => false, 'message' => $message];
            if (!$model && $this->debug) $this->halt($message);
        }



        // 
        if (isset($model['table']) && !empty($this->altTables[$model['table']])) {
            $model['table'] = $this->altTables[$model['table']];
        }

        // order ------------------------------------------------------------
        if (isset($model['order']) && is_string($model['order'])) {
            $asc = 'ASC';
            if ($model['order'][0] == '!') {
                $asc = 'DESC';
                $model['order'] = substr($model['order'], 1);
            }
            $model['order'] = ['field' => $model['order'], 'sort' => $asc];
        }

        // setting field defaults ------------------------------------------------------------
        if ($model && is_array($model['fields']))
            foreach ($model['fields'] as $k => $v)
                if (!isset($v['type'])) {
                    if ($v['field'] == 'id') $model['fields'][$k]['type'] = 'integer';
                    else $model['fields'][$k]['type'] = 'string';
                }

        // setting langs  ------------------------------------------------------------
        if ($lang && $model && $this->langs) {

            $f = [];
            foreach ($model['fields'] as $k => $v)
                if (isset($v['field']) && strpos($v['field'], ':lang'))
                    foreach ($this->langs as $v2) {
                        $v['field'] = str_replace(':lang', $v2['lang_add'], $model['fields'][$k]['field']);
                        $f[] = $v;
                    }
                else $f[] = $v;

            $model['fields'] = $f;
        }

        // updating include fields

        if (isset($model['fields']) && is_array($model['fields']))
            foreach ($model['fields'] as $k2 => $v2)
                if (isset($v2['include'])) {
                    $v3 = $this->loadJson($v2['include']);
                    if (!$v3) exit('_uho_orm::loadJson::' . $v2['include']);

                    $model['fields'][$k2] = array_merge($v2, $v3);
                    unset($model['fields'][$k2]['include']);
                }

        // updating image/video fields  ------------------------------------------------------------
        $uid = false;

        if (isset($model['fields']) && is_array($model['fields']))
            foreach ($model['fields'] as $k => $v)
                switch ($v['type']) {

                    case "checkboxes":
                        if (isset($v['settings']['output']) && isset($v['options'])) {
                            foreach ($v['options'] as $k2 => $v2)
                                if ($v['settings']['output'] == 'value')
                                    $model['fields'][$k]['options'][$k2] = $v2['value'];
                        }

                        break;
                    case "image_media":

                        $im_schema = $this->getJsonModelSchema($v['source']['model']);
                        $im = _uho_fx::array_filter($im_schema['fields'], 'type', 'image', ['first' => true]);
                        if ($im) {
                            $model['fields'][$k]['filename'] = str_replace('{{id}}', '{{' . $v['field'] . '}}', $im['filename']);
                            $model['fields'][$k]['folder'] = $im['folder'];
                            $model['fields'][$k]['images'] = $im['images'];
                            $model['fields'][$k]['settings']['field_exists'] = $v['field'];
                        }

                        break;

                    case "image":

                        if (empty($v['filename']) && empty($v['images'][0]['filename'])) {
                            $model['fields'][$k]['filename'] = '%uid%';
                            $uid = true;
                        }

                        if (@$v['settings']['original'] !== false  &&  (@$v['images'][0]['width'] || @$v['images'][0]['height']))
                            array_unshift($model['fields'][$k]['images'], ['folder' => 'original', 'label' => 'Original']);
                        if (@$v['images_panorama'] && ($v['images_panorama'][0]['width'] || $v['images_panorama'][0]['height']))
                            array_unshift($model['fields'][$k]['images_panorama'], ['folder' => 'original', 'label' => 'Original']);
                        foreach ($model['fields'][$k]['images'] as $k5 => $v5)
                            if (!isset($v5['id'])) $model['fields'][$k]['images'][$k5]['id'] = $v5['folder'];

                        // migrating depreceated properties to settings
                        $v = $model['fields'][$k];
                        if (empty($v['settings'])) $v['settings'] = [];
                        if (isset($v['filename'])) {
                            $v['settings']['filename'] = $v['filename'];
                            unset($v['filename']);
                        }
                        if (isset($v['folder'])) {
                            $v['settings']['folder'] = $v['folder'];
                            unset($v['folder']);
                        }
                        if (isset($v['folder_preview'])) {
                            $v['settings']['folder_preview'] = $v['folder_preview'];
                            unset($v['folder_preview']);
                        }
                        $model['fields'][$k] = $v;


                        break;

                    case "video":

                        if (!@$v['filename']) {
                            $model['fields'][$k]['filename'] = '%uid%';
                            $uid = true;
                        }
                        $model['fields'][$k]['extension'] = 'mp4';

                        if (@$v['images'] && ($v['images'][0]['width'] || $v['images'][0]['height']))
                            array_unshift($model['fields'][$k]['images'], ['folder' => 'original', 'label' => 'Original']);

                        // migrating depreceated properties to settings
                        $v = $model['fields'][$k];
                        if (empty($v['settings'])) $v['settings'] = [];
                        if (isset($v['filename'])) {
                            $v['settings']['filename'] = $v['filename'];
                            unset($v['filename']);
                        }
                        if (isset($v['folder'])) {
                            $v['settings']['folder'] = $v['folder'];
                            unset($v['folder']);
                        }
                        if (isset($v['extension'])) {
                            $v['settings']['extension'] = $v['extension'];
                            unset($v['extension']);
                        }
                        $model['fields'][$k] = $v;

                        break;

                    case "audio":

                        if (!isset($v['filename'])) {
                            $model['fields'][$k]['filename'] = '%uid%';
                            $uid = true;
                        }
                        $model['fields'][$k]['extension'] = 'mp3';


                        break;
                }

        if ($uid && !_uho_fx::array_filter($model['fields'], 'field', 'uid')) {
            $model['fields'][] = ['type' => 'uid', 'field' => 'uid', 'list' => 'read'];
        }

        // depreceated fields reformatting

        if (isset($model['fields']) && is_array($model['fields']))
            foreach ($model['fields'] as $k => $v) {
                // list
                if (isset($v['list']) && $v['list'] === true)
                    $model['fields'][$k]['list'] = 'show';
                if (in_array($v['type'], ['file', 'audio', 'video', 'image'])) {
                    if (empty($v['settings'])) $v['settings'] = [];
                    if (isset($v['folder'])) {
                        $v['settings']['folder'] = $v['folder'];
                        unset($v['folder']);
                    }
                    if (isset($v['folder_audio'])) {
                        $v['settings']['folder_audio'] = $v['folder_audio'];
                        unset($v['folder_audio']);
                    }
                    if (isset($v['folder_video'])) {
                        $v['settings']['folder_video'] = $v['folder_video'];
                        unset($v['folder_video']);
                    }
                    if (isset($v['extension'])) {
                        $v['settings']['extension'] = $v['extension'];
                        unset($v['extension']);
                    }
                    if (isset($v['extensions'])) {
                        $v['settings']['extensions'] = $v['extensions'];
                        unset($v['extensions']);
                    }
                    if (isset($v['extension_field'])) {
                        $v['settings']['extension_field'] = $v['extension_field'];
                        unset($v['extension_field']);
                    }
                    $model['fields'][$k] = $v;
                }
            }

        // reposition ------------------------------------------------------------

        $fields = [];

        if (isset($model['fields']) && is_array($model['fields']))
            foreach ($model['fields'] as $v)
                if (isset($v['position_after'])) {
                    $i = _uho_fx::array_filter($fields, 'field', $v['position_after'], ['first' => true, 'keys' => true]);
                    if (isset($i)) {

                        $fields = array_merge(array_slice($fields, 0, $i + 1), [$v], array_slice($fields, $i + 1));
                    } else $fields[] = $v;
                } else $fields[] = $v;

        $model['fields'] = $fields;

        return $model;
    }

    /**
     * Updates model schema sources based on record
     * @param array $schema
     * @param array $record
     * @param array $params
     * @return array
     */

    public function updateSchemaSources($schema, $record = null, $params = null)
    {

        // update model options from source model
        // model.model.field -> source.field

        foreach ($schema['fields'] as $k => $v)
            if (isset($v['source']['model'])) {
                $model_schema = $this->getJsonModelSchema($v['source']['model']);
                if (isset($model_schema['model']))
                    foreach ($model_schema['model'] as $k2 => $v2)
                        if (!isset($v['source'][$k2])) {
                            $schema['fields'][$k]['source'][$k2] = $v2;
                        }
            }

        // main rework

        foreach ($schema['fields'] as $k => $v)
            // source --> options
            if (@$v['source'] && !@$v['options'] && @$v['input'] != 'search') {
                $prefix = '';
                // many models -> lets' get first for a start
                /*if ($v['source']['models'])
            {
                $v['source']=$v['source']['models'][0];
                $prefix=$v['source']['model'].'_';
            }*/

                $filters = @$v['source']['filters'];
                // update dynamic filters
                if ($filters && $record) {
                    foreach ($filters as $k2 => $v2) {
                        $filters[$k2] = $this->getTwigFromHtml($v2, $record);
                        if ($params) foreach ($params as $k3 => $v3)
                            $filters[$k2] = str_replace($k3, $v3, $filters[$k2]);
                    }
                } //else $filters=[]; Filters might be static as well!
                if (isset($v['source']['order'])) $order = 'ORDER BY ' . $v['source']['order'];
                else $order = '';

                if ($v['source']['model']) {
                    if (!empty($v['source']['model_fields'])) $params0 = ['fields' => $v['source']['model_fields']];
                    else $params0 = [];

                    $t = $this->getJsonModel(
                        $v['source']['model'],
                        $filters,
                        false,
                        null,
                        null,
                        $params0
                    );
                } else {
                    $t = $this->query('SELECT id AS value,' . implode(',', $v['source']['fields']) . ' FROM ' . $v['source']['table'] . ' ' . $order);
                }

                foreach ($t as $kk => $vv) {
                    if (!@$v['source']['label']) $v['source']['label'] = '{{label}}';
                    $label = $this->getTwigFromHtml($v['source']['label'], $vv);
                    if (!isset($vv['value'])) $vv['value'] = $vv['id'];
                    $t[$kk] = ['values' => $vv, 'value' => $prefix . $vv['value'], 'label' => $label];
                    if (@is_array($vv['image'])) $image = @array_slice($vv['image'], 1, 1);
                    if (isset($image)) $t[$kk]['image'] = array_pop($image);
                }


                if (isset($v['source']['order']))
                    $t = _uho_fx::array_multisort($t, $v['source']['order']);


                $schema['fields'][$k]['options'] = $t;
            }
            // source --> by options
            elseif (in_array($v['type'], ['select', 'checkboxes']) && empty($v['source']) && empty($v['options'])) {
                $query = 'SHOW FIELDS FROM ' . $schema['table'] . ' LIKE "' . $v['field'] . '"';
                $t = $this->query($query, true);
                if ($t && $t['Type'] && substr($t['Type'], 0, 4) == 'enum') {
                    $enum = explode(',', substr($t['Type'], 5, strlen($t['Type']) - 6));
                    foreach ($enum as $k2 => $v2)
                        $enum[$k2] = ['value' => trim($v2, "'"), 'label' => trim($v2, "'")];
                    if ($enum) $schema['fields'][$k]['options'] = $enum;
                }
            }
            // options
            elseif (isset($v['options']) && $v['options']) {

                foreach ($v['options'] as $kk => $vv)
                    if (is_string($vv)) {
                        if (isset($v['settings']['output']) && $v['settings']['output'] == 'id')
                            $schema['fields'][$k]['options'][$kk] = $vv;
                        else
                            $schema['fields'][$k]['options'][$kk] = ['value' => $vv, 'label' => $vv];
                    }
            }

        return $schema;
    }

    /**
     * Gets model from mySQL, with its children models
     * @param string $name
     * @param array $filters
     * @param boolean $single
     * @param array $settings
     * @param array $parents
     * @return array
     */

    public function getJsonModelDeep($name, $filters = null, $single = false, $settings = null, $params = null, $parents = null)
    {
        if (!$params) $params[0] = '';
        if (!$parents) $parents = [];
        $schema = $this->getJsonModelSchemaWithPageUpdate($name);

        if (!$filters && $schema['filters']) {
            $filters = _uho_fx::fillPattern($schema['filters'], ['numbers' => $params]);
        }

        $combined_params = $params + $parents;

        if (!is_array($settings)) $settings = [];

        $model = $this->getJsonModel($schema, $filters, $single, @$settings['order'], @$settings['limit'], ['additionalParams' => $combined_params]);
        if ($single && $model) $model = [$model];

        if (!@$settings['deep_max']) $settings['deep_max'] = 999;

        $settings['deep_max']--;


        if (@$schema['children'] && $model && $settings['deep_max'] >= 0)
            foreach ($model as $kk => $vv) {
                $parents0 = $parents;
                if (@$parents0['parent']) $vv['parent'] = $parents0['parent'];
                $parents0['parent'] = $vv;


                foreach ($schema['children'] as $v) {
                    $parent = @$v['id'];
                    if (!$parent) $parent = 'parent';
                    if (!@$v['filters']) $v['filters'] = [];
                    $v['filters'][$parent] = $model[$kk]['id'];
                    $p = $params;
                    $p[] = $model[$kk]['id'];
                    $model[$kk][$v['field']] = $this->getJsonModelDeep($v['model'], $v['filters'], false, $settings, $p, $parents0);
                }
            }
        if ($single) {
            if (isset($model[0])) $model = $model[0];
            else $model = null;
        }
        return $model;
    }

    /**
     * REST Aliases for methods
     */

    public function get($name, $filters = null, $single = false, $order = null, $limit = null, $params = null)
    {
        return $this->getJsonModel($name, $filters, $single, $order, $limit, $params);
    }
    public function post($model, $data, $multiple = false)
    {
        return $this->postJsonModel($model, $data, $multiple);
    }
    public function delete($model, $filters, $multiple = false): bool
    {
        return  $this->deleteJsonModel($model, $filters, $multiple);
    }
    public function put($model, $data, $filters = null, $multiple = false, $externals = true, $params = [])
    {
        return $this->putJsonModel($model, $data, $filters, $multiple, $externals, $params);
    }
    public function patch($model, $data, $filters = null, $multiple = false, $externals = true, $params = [])
    {
        return $this->putJsonModel($model, $data, $filters, $multiple, $externals, $params);
    }
    public function getSchema($name, $lang = false, $params = [])
    {
        return $this->getJsonModelSchema($name, $lang, $params);
    }

    /**
     * Gets model from mySQL
     * @param $name
     * @param array $filters
     * @param boolean $single
     * @param string $order
     * @param string $limit
     * @param array $params
     * @return array
     */

    public function getJsonModel($name, $filters = null, $single = false, $order = null, $limit = null, $params = null)
    {

        if (!$name) {
            exit('_uho_orm::getJsonModel::no-model-name');
        }
        if (is_array($name)) $name_string = $name['model_name'];
        else $name_string = $name;
        $this->checkConnection('getJsonModel::' . $name_string);

        if (isset($params['count'])) $count = $params['count'];
        if (!empty($params['addLanguages'])) $add_languages = true;
        else $add_languages = false;

        if (isset($params['returnQuery'])) $return_query = true;
        if (isset($params['dataOverwrite'])) $dataOverwrite = $params['dataOverwrite'];
        if (isset($params['groupBy'])) $groupBy = $params['groupBy'];
        if (isset($params['skipSchemaFilters'])) $skipSchemaFilters = $params['skipSchemaFilters'];
        else $skipSchemaFilters = false;
        if (isset($params['additionalParams'])) $additionalParams = $params['additionalParams'];
        if (isset($params['key'])) $returnByKey = $params['key'];

        $fields_to_read = isset($params['fields']) ? $params['fields'] : null;


        if (is_array($limit)) $limit = (($limit[0] - 1) * $limit[1]) . ',' . $limit[1];

        if (isset($order) && is_array($order) && isset($order['type']) && $order['type'] == 'field') {
            $order = 'FIELD (id,' . implode(',', $order['value']) . ')';
        }

        if (is_array($name)) $model = $name;
        elseif (isset($params['page_update'])) {
            $model = $this->getJsonModelSchemaWithPageUpdate($name);
        } else {
            $model = $this->getJsonModelSchema($name);
        }

        if ($fields_to_read && !is_array($fields_to_read)) {
            $fields_to_read = !empty($model['fields_to_read'][$fields_to_read]) ? $model['fields_to_read'][$fields_to_read] : null;
        }


        $model = $this->updateJsonModelSchemaRanges($model, $single);
        if (!$model) exit('_uho_orm::JSON model corrupted - ' . $name);

        // filters ==================================================================        
        if (isset($params['skip_filters'])) $model['filters'] = array();
        if (!isset($model['filters']) || $skipSchemaFilters) $model['filters'] = array();

        // i.e. { type:%1%} 
        if (isset($model['filters']) && @$additionalParams)
            foreach ($model['filters'] as $k => $v)
                foreach ($additionalParams as $k2 => $v2)
                    if (is_string($v2))
                        $model['filters'][$k] = str_replace('%' . $k2 . '%', $v2, $v);


        if (is_array($filters) || is_array($model['filters'])) {

            if (is_array($filters)) $model['filters'] = array_merge($model['filters'], $filters);

            $model['filters'] = $this->getJsonModelFiltersQuery($model);

            if ($model['filters']) $model['filters'] = 'WHERE ' . implode(' && ', $model['filters']);
        } elseif ($filters) $model['filters'] = 'WHERE ' . $filters;

        if (!$model['filters']) $model['filters'] = '';

        // order ==================================================================
        if (isset($model['order']) && is_array($model['order'])) {
            $model['order'] = $model['order']['field'] . ' ' . $model['order']['sort'];
        }

        if (isset($model['order']))
            $model['order'] = ' ORDER BY ' . $this->updateOrderBy($model['order']);

        // fields update ==================================================================
        $fields = ['id'];

        $fields_models = array();
        $fields_auto = array();

        if (is_array($model['fields']))
            foreach ($model['fields'] as $v) {
                if (isset($v['container']));
                elseif (in_array(@$v['field'], $fields));
                elseif ($fields_to_read && isset($v['field']) && !in_array($v['field'], $fields_to_read));
                elseif ($v['type'] == 'model') array_push($fields_models, $v);
                elseif (isset($v['outside']['model'])) array_push($fields_models, ['model' => $v['outside']]);
                elseif (isset($v['external']));
                elseif (in_array($v['type'], ['file', 'image', 'video', 'audio', 'media', 'virtual', 'plugin'])) array_push($fields_auto, $v);
                elseif (isset($v['field_output'])) array_push($fields, $v['field'] . ' AS ' . $v['field_output']);
                elseif (!empty($v['field'])) {
                    array_push($fields, $v['field']);
                    if ($v['type'] == 'image_media') array_push($fields_auto, $v);
                }

                if (($add_languages || isset($v['add_languages'])) && strpos($v['field'], ':lang')) {
                    $vv = explode(':lang', $v['field'])[0];
                    foreach ($this->langs as $v2)
                        $fields[] = $vv . $v2['lang_add'];
                }
            }

        if (!$limit) $limit = '';
        else $limit = 'LIMIT ' . $limit;

        // order
        if (is_array($order)) {
            if (isset($order) && isset($order['type']) && $order['type'] == 'FIELD') $qorder = ' ORDER BY FIELD (' . $order['field'] . ',"' . implode('","', $order['values']) . '")';
        } else if ($order) $qorder = ' ORDER BY ' . $order;
        else if (isset($model['order'])) $qorder = $model['order'];

        // single
        if ($single) $limit .= ' LIMIT 0,1';

        // count
        if (@$count == 'strict') $fields = array('COUNT(*)');
        if (isset($count['type']) && $count['type'] == 'average') {
            if ($count['function']) $fields = array('AVG(' . $count['function'] . '(' . $count['field'] . ')) AS average');
            else $fields = array('AVG(' . $count['field'] . ') AS average');
        }

        // building query
        $fields_read = $fields;

        if (@$model['ranges']['multiple_read'] && empty($count)) $fields_read = $model['ranges']['multiple_read'];

        if (isset($model['table']))
            $query = 'SELECT ' . implode(',', $this->sanitizeFields($fields_read)) . ' FROM ' . $model['table'] . ' ' . $model['filters'];
        else {
            if ($this->halt_on_error) exit('_uho_orm::getJsonModel->table not found [' . @$name . ']');
            else return false;
        }


        if (@$groupBy) $query .= ' GROUP BY ' . $groupBy;
        if (isset($qorder)) $query .= ' ' . $qorder;
        $query .= ' ' . $limit;

        if (@$return_query) return $query;

        if (@$dataOverwrite) $data = $dataOverwrite;
        else {
            if (isset($params['force_sql_cache']))
                $data = $this->query($query, false, true, null, null, true);
            else $data = $this->query($query);
        }

        //if ($data==='error') return 'error';

        if (@$count == 'strict') {
            return @$data[0]['COUNT(*)'];
        }
        if (@$count['type'] == 'average') {
            return @$data[0]['average'];
        }

        // replace_values
        if (@$params['replace_values']) {
            foreach ($data as $k => $v)
                foreach ($params['replace_values'] as $k2 => $v2)
                    if (isset($v[$k2])) {
                        $data[$k][$k2] = $v2;
                    }
        }

        // duplicating fields for ORDER BY FIELD
        if (isset($order['type']) && $order['type'] == 'FIELD') {
            $d = array();
            foreach ($order['values'] as $v) {
                $d0 = _uho_fx::array_filter($data, $order['field'], $v, array('first' => true));
                if ($d0) array_push($d, $d0);
            }
            $data = $d;
        }


        // data && fields update / ================================================================================================

        foreach ($data as $k => $v) {

            // containers --> to fields

            foreach ($model['fields'] as $k2 => $v2) {

                if (isset($v2['external'])) {
                    $val = implode(',', array_keys($this->query('SELECT ' . $v2['external']['id2'] . ' AS object FROM archive_collections2objects WHERE ' . $v2['external']['id'] . '=' . $v['id'] . ' ORDER BY nr', false, true, 'object')));
                    $data[$k][$v2['field']] = $v[$v2['field']] = $val;
                }

                // decryptying hash fields
                if (isset($v2['hash']) && isset($data[$k][$v2['field']])) {
                    $data[$k][$v2['field']] = _uho_fx::decrypt($data[$k][$v2['field']], $this->keys, $v2['hash']);
                }

                // dehashing source
                if (isset($v2['source']) && $k == 0 && @is_array($v2['source']['fields'])) {
                    foreach ($v2['source']['fields'] as $k3 => $v3)
                        if ($v3 != rtrim($v3, '#')) {
                            $model['fields'][$k2]['source']['fields'][$k3] = rtrim($v3, '#');
                            $model['fields'][$k2]['source']['fields_hashed'][] = rtrim($v3, '#');
                        }
                    if (is_array(@$model['fields'][$k2]['source']['fields_hashed']))
                        $model['fields'][$k2]['source']['fields_hashed'] = array_flip($model['fields'][$k2]['source']['fields_hashed']);
                }

                if (isset($v2['container']) && $v[$v2['container']]) {
                    $vv = json_decode($v[$v2['container']], true);
                    $data[$k][$v2['field']] = $v[$v2['field']] = @$vv[$v2['field']];
                }
            }




            foreach ($model['fields'] as $k2 => $v2) {
                // incuding other model with fields
                if (isset($v2['include'])) {
                    // disabling as it's actually shoule be made on getSchema
                    //$v3=$this->loadJson($v2['include']);
                    //$v2=$model['fields'][$k2]=array_merge($v2,$v3);
                }

                // removing :lang fields as they are already duplicated to output languages
                if (isset($v2['field']) && strpos(@$v2['field'], ':lang')) unset($data[$k][$v2['field']]);
                // type of model                
                elseif ($v2['type'] == 'model') {

                    $v2['filters'] = _uho_fx::arrayReplace($v2['filters'], $v, '%', '%');
                    $data[$k][$v2['field']] = $this->getJsonModel($v2['model'], $v2['filters'], false, $v2['order']);
                }
                // no field specified, doing nothing
                elseif (@!$v[$v2['field']]) {
                }
                // elements as model
                elseif ($v2['type'] == 'elements' && isset($v2['source']['model'])) {

                    $f = explode(',', $v[$v2['field']]);

                    $f4 = array();
                    foreach ($f as $k3 => $v3) {
                        $v3 = explode(':', $v3);
                        if (isset($v3[1])) $v3 = $v3[1];
                        else $v3 = $v3[0];
                        if (@$v2['output'] == 'string')
                            $f4[$k3] = $v3;
                        else $f4[$k3] = intval($v3);
                    }

                    if (!empty($v2['source']['model_fields'])) $params0 = ['fields' => $v2['source']['model_fields']];
                    else $params0 = [];
                    $data[$k][$v2['field']] = $this->getJsonModel($v2['source']['model'], ['id' => $f4], false, ['type' => 'FIELD', 'field' => 'id', 'values' => $f4], null, $params0);
                }
                // checkboxes as model
                elseif ($v2['type'] == 'checkboxes' && isset($v2['source']['model'])) {

                    $f = explode(',', $v[$v2['field']]);
                    $f4 = array();
                    foreach ($f as $k3 => $v3) {
                        $v3 = explode(':', $v3);
                        if (isset($v3[1])) $v3 = $v3[1];
                        else $v3 = $v3[0];
                        if (isset($v2['output']) && $v2['output'] == 'string') $f4[$k3] = $v3;
                        else $f4[$k3] = intval($v3);
                        //if (!is_numeric($v3)) exit('_uho_orm::internal error getjsonmodel value:::'.$v[$v2['field']]);

                    }

                    if (!empty($v2['source']['model_fields'])) $params0 = ['fields' => $v2['source']['model_fields']];
                    else $params0 = [];
                    $data[$k][$v2['field']] = $this->getJsonModel($v2['source']['model'], ['id' => $f4], false, null, null, $params0);
                } elseif ($v2['type'] == 'checkboxes' && isset($v2['options'])) {
                    $val1 = explode(',', $data[$k][$v2['field']]);
                    if (isset($v2['settings']['output']) && $v2['settings']['output'] == 'value') {
                        $val2 = $val1;
                    } else {
                        $val2 = [];
                        foreach ($v2['options'] as $v3)
                            if (in_array($v3['value'], $val1)) $val2[] = ['label' => $v3['label'], 'id' => $v3['value']];
                    }
                    $data[$k][$v2['field']] = $val2;
                }
                // select as model
                elseif (@$v2['type'] == 'select' && @$v2['model']) // || $v2['source']['model']))
                {

                    if ($v2['source']['filters']) {
                        $f = array($v2['source']['filters']);
                        $f = _uho_fx::arrayReplace($f, $v, '%', '%');
                        $getSingle = false;
                        $order = $v2['source']['order'];
                        $f = $f[0];
                    } else {
                        $f = array('id' => $v[$v2['field']]);
                        $getSingle = true;
                        $order = '';
                    }
                    if ($v2['model']) $model0 = $v2['model'];
                    else  $model0 = $v2['source']['model'];
                    $data[$k][$v2['field']] = $this->getJsonModel($model0, $f, $getSingle, $order);
                }
                // ================================================================================================
                // source single

                elseif (@$v2['source'] && ($v2['type'] == 'elements' || $v2['type'] == 'select' || $v2['type'] == 'checkboxes')) {

                    if (!@$v2['source']['data']) {

                        if (@$v2['source']['model']) {

                            $ids = [];
                            foreach ($data as $v5)
                                if (!in_array($v5[$v2['field']], $ids)) $ids[] = $v5[$v2['field']];

                            if (isset($v2['source']['id'])) $id_field = $v2['source']['id'];
                            else  $id_field = 'id';

                            if (!empty($v2['source']['model_fields'])) $params0 = ['fields' => $v2['source']['model_fields']];
                            else $params0 = [];

                            $vv = $this->getJsonModel($v2['source']['model'], [$id_field => $ids], false, null, null, $params0);

                            $v4 = [];
                            foreach ($vv as $v3)
                                $v4[$v3[$id_field]] = $v3;

                            if (isset($v2['source']['field']))
                                foreach ($v4 as $k6 => $_) $v4[$k6] = $v4[$k6][$v2['source']['field']];

                            $v2['source']['data'] = $model['fields'][$k2]['source']['data'] = $v4;
                        } else {

                            if (!$v2['source']['fields']) exit('_uho_model::error No source fields for ' . $v2['field']);
                            $query = 'SELECT id,' . implode(',', $v2['source']['fields']) . ' FROM ' . $v2['source']['table'];

                            $v2['source']['data'] = $model['fields'][$k2]['source']['data'] = $this->query($query, false, null, 'id');
                        }


                        if (@$v2['source']['fields_hashed'])
                            foreach ($v2['source']['data'] as $k4 => $v4)
                                foreach ($v4 as $k5 => $v5) {
                                    if (isset($v2['source']['fields_hashed'][$k5]))
                                        $model['fields'][$k2]['source']['data'][$k4][$k5] = $v2['source']['data'][$k4][$k5] = _uho_fx::decrypt($v5, $this->keys, $v2['source']['fields_hashed'][$k5]);
                                }



                        if (@$v2['source']['url'] && $v2['source']['data'])
                            foreach ($v2['source']['data'] as $k3 => $v3) {
                                $v2['source']['data'][$k3]['url'] = $this->updateTemplate($v2['source']['url'], $v3);
                            }
                    }

                    switch ($v2['type']) {
                        case 'select':
                            $id0 = ($v[$v2['field']]);
                            if (is_numeric($id0)) $id0 = intval($id0);
                            if (isset($v2['source']['data'][$id0]))
                                $data[$k][$v2['field']] = $v[$v2['field']] = $v2['source']['data'][$id0];


                            break;
                        case 'elements':
                        case 'checkboxes':

                            $elements = explode(',', $v[$v2['field']]);

                            foreach ($elements as $k3 => $v3)
                                if (intval($v3) || (isset($v2['output']) && $v2['output'] == 'string' && $v3)) {
                                    $v3 = explode(':', $v3);
                                    if (isset($v3[1])) $v3 = $v3[1];
                                    else $v3 = $v3[0];
                                    if (isset($v2['output']) && $v2['output'] == 'string')
                                        $elements[$k3] = $v2['source']['data'][($v3)];
                                    else $elements[$k3] = @$v2['source']['data'][intval($v3)];
                                } else unset($elements[$k3]);

                            $data[$k][$v2['field']] = $v[$v2['field']] = $elements;
                            break;
                    }
                }
                // source double ================================================================
                elseif (@$v2['source_double'] && ($v2['type'] == 'elements_double')) {
                    $sd = array();
                    $elements = explode(',', $v[$v2['field']]);

                    // first setting exact values we need for tables
                    $eTables = array();
                    foreach ($elements as $v5) {
                        $v5 = explode(':', $v5);
                        if (!isset($eTables[$v5[0]]) || !$eTables[$v5[0]]) $eTables[$v5[0]] = array();
                        array_push($eTables[$v5[0]], intval($v5[1]));
                    }


                    $d_models = [];
                    // then getting only needed values
                    foreach ($v2['source_double'] as $v3)
                        if ($v3['model']) {
                            $d_models[$v3['slug']] = $v3['model'];
                            if (isset($eTables[$v3['slug']]) && $eTables[$v3['slug']])
                                $d0 = $this->getJsonModel($v3['model'], array('id' => $eTables[$v3['slug']]));
                            else $d0 = array();
                            $d1 = array();
                            foreach ($d0 as $v4)
                                $d1[$v4['id']] = $v4;
                            $sd[$v3['slug']] = $d1;
                        } else {
                            $query = 'SELECT id,' . implode(',', $v3['fields']) . ' FROM ' . $v3['table'];
                            $sd[$v3['slug']] = $this->query($query, false, null, 'id');
                        }

                    // then assigning those values to actual records
                    $v4 = array();

                    foreach ($elements as  $v3) {
                        $v3 = explode(':', $v3);
                        if ($this->elements_double_first_integer) $_slug = intval($v3[0]);
                        else $_slug = $v3[0];
                        $v3[1] = intval($v3[1]);

                        $_table = $v3[0];
                        $_table = _uho_fx::array_filter($v2['source_double'], 'slug', $_table, array('first' => true));
                        if (isset($_table['table'])) $_table = $_table['table'];

                        $v5 = @$sd[$v3[0]][$v3[1]];

                        if (isset($v5)) {
                            $mm = _uho_fx::array_filter($v2['source_double'], 'slug', $v3[0], array('first' => true));
                            if (isset($mm['label'])) $v5['label'] = $this->updateTemplate($mm['label'], $v5);
                            if (isset($mm['image'])) $v5['image'] = $this->updateTemplate($mm['image'], $v5);
                            $v5['_table'] = $_table;
                            $v5['_slug'] = $_slug;
                            $v5['_model'] = $d_models[$_slug];
                            array_push($v4, $v5);
                        }
                    }

                    $data[$k][$v2['field']] = $v[$v2['field']] = $v4;
                }
                // source pair ================================================================
                elseif (@$v2['source_double'] && ($v2['type'] == 'elements_pair')) {
                    $sd = array();
                    $sections = explode(';', $v[$v2['field']]);



                    // first setting exact values we need for tables
                    $eTables = array();
                    foreach ($sections as $v6) {
                        $elements = explode(',', $v6);
                        foreach ($elements as $v5) {
                            $v5 = explode(':', $v5);
                            if (!$eTables[$v5[0]]) $eTables[$v5[0]] = array();
                            array_push($eTables[$v5[0]], intval($v5[1]));
                        }
                    }

                    // then getting only needed values
                    foreach ($v2['source_double'] as $v3)
                        if ($v3['model']) {
                            if ($eTables[$v3['slug']])
                                $d0 = $this->getJsonModel($v3['model'], array('id' => $eTables[$v3['slug']]));
                            else $d0 = array();
                            $d1 = array();
                            foreach ($d0 as $v4)
                                $d1[$v4['id']] = $v4;
                            $sd[$v3['slug']] = $d1;
                        } elseif ($eTables[$v3['slug']] && is_array($eTables[$v3['slug']])) {
                            $query = 'SELECT id,' . implode(',', $v3['fields']) . ' FROM ' . $v3['table'] . ' WHERE id=' . implode(' || id=', $eTables[$v3['slug']]);
                            $sd[$v3['slug']] = $this->query($query, false, null, 'id');
                        }



                    // then assigning those values to actual records
                    $v4 = array();

                    foreach ($sections as $k6 => $v6) {
                        $v4[$k6] = array();
                        $elements = explode(',', $v6);
                        foreach ($elements as $v3) {
                            $v3 = explode(':', $v3);
                            $_slug = intval($v3[0]);
                            $v3[1] = intval($v3[1]);

                            $_table = $v3[0];
                            $_table = _uho_fx::array_filter($v2['source_double'], 'slug', $_table, array('first' => true));
                            $_table = $_table['table'];

                            $v5 = $sd[$v3[0]][$v3[1]];

                            if (isset($v5)) {
                                $mm = _uho_fx::array_filter($v2['source_double'], 'slug', $v3[0], array('first' => true));
                                if ($mm['label']) $v5['label'] = $this->updateTemplate($mm['label'], $v5);
                                if ($mm['image']) $v5['image'] = $this->updateTemplate($mm['image'], $v5);
                                $v5['_table'] = $_table;
                                $v5['_slug'] = $_slug;
                                array_push($v4[$k6], $v5);
                            }
                        }
                    }
                    $data[$k][$v2['field']] = $v[$v2['field']] = $v4;
                }
                if (isset($v2['field']) && strpos(@$v2['field'], ':lang')) {
                    $v3 = explode(':', $v2['field']);
                    $field = array_shift($v3);
                } else $field = @$v2['field'];

                if (isset($data[$k][$field])) {
                    $data[$k][$field] = $this->getJsonModelUpdateField($v2['type'], $data[$k][$field], $v2);
                }
            }
        }

        // type updates ----------------------------------------------------------------------------------------
        foreach ($data as $k => $v)
            foreach ($model['fields'] as $v2)
                if (isset($v2['field']) && isset($v[$v2['field']]))
                    switch ($v2['type']) {
                        case "integer":
                            $data[$k][$v2['field']] = intval($data[$k][$v2['field']]);
                            break;
                        case "float":
                            $data[$k][$v2['field']] = floatval($data[$k][$v2['field']]);
                            break;
                    }

        // autofields and type updates ----------------------------------------------------------------------------------------
        foreach ($data as $k => $v)
            foreach ($fields_auto as $v2) {
                switch ($v2['type']) {
                    // file
                    case "file":
                    case "audio":
                    case "video":

                        if (!empty($v2['settings']['field_exists']) && empty($v[$v2['settings']['field_exists']])) {
                            $data[$k][$v2['field']] = null;
                        } else {
                            // {"type":"original","field":"filename"}
                            if (@is_array($v2['settings']['filename'])) {
                                switch ($v2['settings']['filename']['type']) {
                                    case "original":
                                        $v2['settings']['filename'] = $v[$v2['settings']['filename']['field']];
                                        break;
                                }
                            }

                            if (!@$v2['settings']['filename']) {
                                $v2['settings']['filename'] = '%uid%.%extension%';
                            } elseif (!strpos($v2['settings']['filename'], '.') && @$v2['settings']['extension'] && !is_array($v2['settings']['extension']))
                                $v2['settings']['filename'] .= '.' . $v2['settings']['extension'];

                            foreach ($v as $k3 => $v3)
                                if (is_string($v3))
                                    $v2['settings']['filename'] = str_replace('%' . $k3 . '%', $v3, $v2['settings']['filename']);

                            $v2['settings']['filename'] = $this->getTwigFromHtml($v2['settings']['filename'], $v);
                            $v2['settings']['folder'] = $this->getTwigFromHtml($v2['settings']['folder'], $v);
                            if (isset($v2['settings']['extension_field'])) $v2['settings']['extension'] = $v[$v2['settings']['extension_field']];

                            if (@$v2['settings']['extension'] && !is_array($v2['settings']['extension']))
                                $v2['settings']['filename'] = str_replace("%extension%", $v2['settings']['extension'], $v2['settings']['filename']);

                            if (!empty($v2['settings']['filename'])) $v2['settings']['filename_bare'] = explode('.', $v2['settings']['filename']);
                            if (!empty($v2['settings']['filename_bare']) && is_array($v2['settings']['filename_bare'])) {
                                array_pop($v2['settings']['filename_bare']);
                                $v2['settings']['filename_bare'] = implode('.', $v2['settings']['filename_bare']);
                            }

                            $src = $v2['settings']['folder'] . '/' . $v2['settings']['filename']; //.$v2['extension'];

                            if (!empty($src)) {

                                $this->fileAddTime($src);
                                @$data[$k][$v2['field']] = ['src' => $src];
                            }

                            if (@$v2['images']) {
                                $poster = $v2['settings']['folder'] . '/' . $v2['images'][1]['folder'] . '/' . $v2['settings']['filename_bare'] . '.jpg';
                                $this->fileAddTime($poster);
                                if ($poster) $data[$k][$v2['field']]['poster'] = $poster;
                            }
                        }

                        break;

                    case "image_media":
                    case "image":

                        $m = array();

                        if (!empty($v2['settings']['field_exists']) && empty($v[$v2['settings']['field_exists']])) {
                            $data[$k][$v2['field']] = null;
                        } else {

                            foreach ($v2['images'] as $k4 => $v4)
                                if (@$v4['retina'] === true)
                                    $v2['images'][$k4]['retina'] = [['count' => 2, 'label' => @$v4['label'] . '_x2', 'folder' => $v4['folder'] . '_x2']];


                            foreach ($v2['images'] as $k4 => $v4)
                                if (@$v4['retina']) {
                                    foreach ($v4['retina'] as $v5) {
                                        $v2['images'][] = $v5;
                                    }
                                    unset($v2['images'][$k4]['retina']);
                                }

                            $extension = 'jpg';

                            if (@$v2['settings']['extension_field']) $extension = $v[$v2['settings']['extension_field']];
                            elseif (@$v2['settings']['extensions'] && count($v2['settings']['extensions']) == 1)
                                $extension = $v2['settings']['extensions'][0];

                            $v2['settings']['folder'] = $this->updateTemplate($v2['settings']['folder'], $v);

                            /*
                                optional: add image sizes
                            */

                            if ($this->addImageSizes && !empty($v2['settings']['sizes'])) {
                                $sizes = $data[$k][$v2['settings']['sizes']];
                                if (is_string($sizes)) $sizes = json_decode($sizes, true);
                            } else $sizes = [];

                            /*
                                update filename patterns
                            */


                            foreach ($v2['images'] as $v4) {

                                if (isset($v4['filename'])) {
                                    $filename0 = $this->updateTemplate($v4['filename'], $v, true);
                                } elseif (isset($v2['filename'])) {
                                    $filename0 = $this->updateTemplate($v2['filename'], $v, true);
                                } else $filename0 = $this->updateTemplate('%uid%', $v);




                                if (@$v4['id']) $image_id = $v4['id'];
                                else $image_id = $v4['folder'];

                                $m[$image_id] = $v2['settings']['folder'] . '/' . $v4['folder'] . '/' . $filename0 . '.' . $extension;
                                $m[$image_id] = str_replace('//', '/', $m[$image_id]);


                                /*
                                    optional - add image size
                                */
                                if (isset($v4['size'])) {
                                    $this->imageAddSize($m[$image_id]);
                                } elseif (isset($v2['server'])) $this->imageAddServer($m[$image_id], $v2['server']);
                                /*
                                    default - add image time as suffix to avoid cache                                
                                */
                                else {
                                    $this->fileAddTime($m[$image_id]);
                                }



                                if ($sizes) {
                                    $m[$image_id] = ['src' => $m[$image_id]];
                                    if (!empty($sizes[$image_id])) {
                                        $m[$image_id]['width'] = $sizes[$image_id][0];
                                        $m[$image_id]['height'] = $sizes[$image_id][1];
                                    }
                                }


                                // webp
                                if (isset($v2['settings']['webp']) && $image_id != 'original') {
                                    $m[$image_id . '_webp'] = $v2['settings']['folder'] . '/' . $v4['folder'] . '/' . $filename0 . '.webp';
                                    if (isset($v4['size']))
                                        $this->imageAddSize($m[$image_id . '_webp']);
                                    else $this->fileAddTime($m[$image_id . '_webp']);

                                    if ($sizes) {
                                        $m[$image_id . '_webp'] = ['src' => $m[$image_id . '_webp']];
                                        if (!empty($sizes[$image_id])) {
                                            $m[$image_id . '_webp']['width'] = $sizes[$image_id][0];
                                            $m[$image_id . '_webp']['height'] = $sizes[$image_id][1];
                                        }
                                    }
                                }
                            }

                            $data[$k][$v2['field']] = $m;
                        }



                        break;

                    case "virtual":

                        if (isset($v2['value']) && $v2['value'] && isset($v2['field']))
                            $data[$k][$v2['field']] = $this->getTwigFromHtml($v2['value'], $data[$k]);

                        break;

                    case "media":

                        $model_name = isset($model['model_name']) ? $model['model_name'] : null;
                        if (!$model_name) $model_name = isset($model['table']) ? $model['model_name'] : null;

                        if (!empty($params['media_model_name'])) {
                            $find = _uho_fx::array_filter($params['media_model_name'], 'field', $v2['field'], ['first' => true]);
                            if ($find) $model_name = $find['model_name'];
                        }

                        $media_model = isset($v2['source']['model']) ? $v2['source']['model'] : null;

                        if (!$media_model) exit('no source model defined for: ' . $name . '::' . $v2['field']);

                        $media = $this->getJsonModel($media_model, ['model' => $model_name . @$v2['media']['suffix'], 'model_id' => $v['id']], false, 'model_id_order');

                        foreach ($media as $k5 => $v5) {
                            unset($media[$k5]['date']);
                            unset($media[$k5]['model']);
                            unset($media[$k5]['model_id']);
                            unset($media[$k5]['model_id_order']);

                            switch ($v5['type']) {
                                case "file":

                                    unset($v5['image']);
                                    if (@!$media[$k5]['extension']) {
                                        $ext = explode('?', $v5['file']['src']);
                                        $ext = explode('.', $ext[0]);
                                        $ext = array_pop($ext);
                                        $media[$k5]['extension'] = $ext;
                                    }

                                    break;
                            }
                        }


                        $data[$k][$v2['field']] = $media;

                        break;
                }
            }

        // load field models

        foreach ($data as $k => $v)
            foreach ($fields_models as $v2)
                if (@$v2['type'] != 'model') {

                    $v2['model'] = _uho_fx::arrayReplace($v2['model'], $v, '%', '%');
                    if ($v2['model']['order']) $order = $v2['model']['order'];
                    else $order = null;

                    if (isset($v2['field'])) {
                        $data[$k][$v2['field']] = $this->getJsonModel($v2['model']['model'], $v2['model']['filters'], false, $order);
                    }
                }


        // last update - template fields as field_output

        foreach ($data as $k => $v)
            foreach ($model['fields'] as $v2)
                if (!$fields_to_read || (!empty($v2['field']) && in_array($v2['field'], $fields_to_read))) {
                    switch ($v2['type']) {
                        case "template":
                            if ($v2['field_output'])
                                $data[$k][$v2['field_output']] = $this->getJsonModelUpdateField($v2['type'], $data[$k][$v2['field_output']], $v2);

                            break;
                        case "json":

                            if (strpos(@$v2['field'], ':lang')) {
                                $v3 = explode(':', $v2['field']);
                                $field0 = array_shift($v3);
                            } else $field0 = @$v2['field'];

                            $data[$k][$field0] = @json_decode($v[$field0], true);
                            break;
                        case "video":
                            if (@$v2['poster'] && @$data[$k][$v2['poster']]) {
                                $data[$k][$v2['field']]['poster'] = $data[$k][$v2['poster']];
                            }

                            break;
                    }
                }

        // urls
        if ($data && isset($model['url'])) {
            foreach ($data as $kk => $vv) {
                if (isset($additionalParams) && $additionalParams) $vv = $vv + $additionalParams;

                $data[$kk]['url'] = $url = $model['url'];
                foreach ($url as $k => $v)
                    if (is_string($v)) {
                        // % pattern
                        while (strpos(' ' . $v, '%')) {
                            $i = strpos($v, '%');
                            $j = strpos($v, '%', $i + 1);
                            if (!$j) $j = strlen($v) - 1;
                            $cut = substr($v, $i + 1, $j - $i - 1);
                            $cut = explode('.', $cut);

                            if (count($cut) == 1 && isset($vv[$cut[0]])) $cut = $vv[$cut[0]];
                            else {
                                // TBD toArray
                                if (count($cut) == 2) $cut = @$vv[$cut[0]][$cut[1]];
                                else {
                                    $cut = @$vv[$cut[0]][$cut[1]][$cut[2]];
                                }
                            }

                            $v = substr($v, 0, $i) . $cut . substr($v, $j + 1);
                            $data[$kk]['url'][$k] = $v;
                        }
                        // twig pattern
                        $data[$kk]['url'][$k] = $this->getTwigFromHtml($data[$kk]['url'][$k], $vv);
                    }
            }
        }

        // remove unusued fields base on page_update modelss
        if (isset($params['page_update_strict']) && $params['page_update_strict']) {
            $pattern = $model['page_update'];
            foreach ($data as $k => $v) {
                $p = $this->getTwigFromHtml($pattern, $v);
                foreach ($model['fields'] as $v2)
                    if (isset($v2['_original_models']) && !in_array($p, $v2['_original_models'])) {
                        $v2['field'] = str_replace(':lang', '', $v2['field']);
                        unset($data[$k][$v2['field']]);
                    }
            }
        }

        // single

        if ($single === true && isset($data[0])) $data = $data[0];
        if (@$count) $data = count($data);
        if (isset($returnByKey)) {
            $d = [];
            foreach ($data as $v)
                $d[$v[$returnByKey]] = $v;
            $data = $d;
        }


        return $data;
    }

    /**
     * Builds query for writing record
     *
     * @param string $model
     * @param array $data
     * @param string $join
     *
     */

    public function buildOutputQuery($model, $data, string $join = ','): array|string
    {

        $skip_fields = ['image', 'video', 'file', 'audio', 'virtual', 'media'];

        foreach ($data as $k => $v) {

            $skip_safe = false;
            $field = _uho_fx::array_filter($model['fields'], 'field', $k, ['first' => true]);

            if ($k == 'id') $data[$k] = $k . '="' . ($v) . '"';
            elseif ($field && in_array($field['type'], $skip_fields)) unset($data[$k]);
            elseif (isset($field['external'])) unset($data[$k]);
            elseif ($field) {

                // convert values
                switch (@$field['type']) {

                    case "checkboxes":
                    case "elements":

                        $iDigits = 8;
                        if (!empty($field['settings']['output'])) {
                            if ($field['settings']['output'] == '4digits') $iDigits = 4;
                            if ($field['settings']['output'] == '6digits') $iDigits = 6;
                            if ($field['settings']['output'] == '8digits') $iDigits = 8;
                            if ($field['settings']['output'] == 'string') $iDigits = 0;
                        }

                        if (is_array($v)) {
                            foreach ($v as $k2 => $v2)
                                if ($iDigits)
                                    $v[$k2] = _uho_fx::dozeruj($v2, $iDigits);
                            $v = implode(',', $v);
                        }

                        break;

                    case 'boolean':

                        if ($v === true || $v === 'on' || $v === 1 || $v === '1') $v = 1;
                        else $v = 0;
                        break;
                    case "float":
                        $v = floatval($v);
                        $skip_safe = true;
                        break;
                    case "integer":
                        $v = intval($v);
                        $skip_safe = true;
                        break;
                    case "json":
                        if (is_array($v)) $v = json_encode($v, true);
                        break;
                    case "select":
                        if (is_numeric($v)) $skip_safe = true;
                        break;
                    case 'table':

                        if (isset($field['settings']) && isset($field['settings']['format']) && $field['settings']['format'] == 'object') {

                            $v0 = $v;
                            $v = [];
                            foreach ($v0 as $v2)
                                $v[$v2[0]] = $v2[1];
                        }
                        $v = json_encode($v);

                        break;
                    case 'order':
                        $v = intval($v);
                        break;
                }

                // save type

                if (isset($v['type']) && $v['type'] == 'sql') {
                    $data[$k] = '`' . $k . '`=' . $v['value'];
                } else {
                    if (isset($field['hash'])) $data[$k] = '`' . $k . '`="' . _uho_fx::encrypt($v, $this->keys, $field['hash']) . '"';
                    elseif ($v === 0) $data[$k] = '`' . $k . '`=0';
                    elseif (isset($field['type']) && $skip_safe) $data[$k] = '`' . $k . '`="' . $v . '"';
                    else $data[$k] = '`' . $k . '`="' . $this->sqlSafe($v) . '"';
                }
            } elseif (isset($model['filters'][$k])) {
                // field is present in filters so assuming it's OK to write it
                $data[$k] = '`' . $k . '`="' . $this->sqlSafe($v) . '"';
            } else {
                unset($data[$k]);
            }
        }

        if ($data) $data = implode($join, $data);

        return $data;
    }

    /**
     * Builds query for writing multiple records record
     * @param string $model
     * @param array $data
     * @param string $output
     * @return mixed
     */

    private function buildOutputQueryMultiple($model, $data, $output = 'query')
    {

        $skip_fields = ['image', 'virtual', 'media', 'video', 'file', 'audio'];
        $result = ['fields' => [], 'values' => []];
        $fields = $model['fields'];
        $is_id = false;
        foreach ($fields as $k => $v) {
            if (!$v['field'] || in_array($v['type'], $skip_fields)) unset($fields[$k]);
            elseif ($v['field'] == 'id') $is_id = true;
        }

        if (!$is_id) $fields[] = ['field' => 'id'];

        foreach ($fields as $k => $v) {
            $fields[$k]['values'] = [];
            foreach ($data as $k2 => $v2) {

                if (isset($v2[$v['field']])) {
                    $val = $v2[$v['field']];
                    if ($val === null) $val = '';
                } else $val = null;

                if (isset($v['hash'])) $val = _uho_fx::encrypt($val, $this->keys, $v['hash']);

                if (isset($val)) {
                    switch (@$v['type']) {
                        case 'boolean':

                            if ($val == 'on' || $val === "1" || $val === 1) $val = 1;
                            else $val = "0";

                            break;
                        case 'table':
                            $val = json_encode($val);
                            break;
                        case 'order':
                            $val = intval($val);
                            break;
                        case 'json':
                            $val = json_encode($val);
                            break;
                        case 'elements':
                        case 'checkboxes':
                            $iDigits = 8;
                            if (!empty($v['settings']['output']) && $v['settings']['output'] == 'string') $iDigits = 0;
                            elseif (!empty($v['settings']['output'])) $iDigits = trim($v['settings']['output'], 'digits');
                            if (is_array($val)) {
                                foreach ($val as $kk => $vv)
                                    $val[$kk] = _uho_fx::dozeruj($vv, $iDigits);
                                $val = implode(',', $val);
                            }

                            break;
                    }
                    $fields[$k]['values_exists'] = true;
                    $fields[$k]['values'][] = $val;
                    $result['values'][$k2][] = $val;
                }
            }
            if (isset($fields[$k]['values_exists'])) {
                $result['fields'][] = $fields[$k]['field'];
            }
        }

        if ($output == 'query') {
            foreach ($result['values'] as $k => $record) {
                foreach ($record as $k2 => $v2) {
                    //if (is_int($v2)) $record[$k2] = '"' . intval($v2) . '"';
                    //else 
                    $record[$k2] = '"' . $this->sqlSafe($v2) . '"';
                }

                while (count($record) < count($result['fields']))
                    $record[] = '""';
                $result['values'][$k] = '(' . implode(',', $record) . ')';
            }


            foreach ($result['fields'] as $kk => $vv)
                $result['fields'][$kk] = '`' . $vv . '`';
            $query = '(' . implode(',', $result['fields']) . ') VALUES ' . implode(', ', $result['values']);

            return $query;
        } else return $result;
    }

    /**
     * Gets ID of last added model
     * @return int
     */

    public function getInsertId()
    {
        return $this->sql->insert_id();
    }

    /**
     * Truncates table from mySQL
     * @param string $model
     * @return boolean
     */

    public function truncateModel($model)
    {
        $model = $this->getJsonModelSchema($model, true);
        if (!$model || empty($model['table'])) return false;
        else {
            $this->queryOut('TRUNCATE TABLE ' . $model['table']);
            return true;
        }
    }

    /**
     * Deletes object from mySQL
     * @param string $model
     * @param array $filters
     * @param boolean $multiple
     * @return boolean
     */

    public function deleteJsonModel($model, $filters, $multiple = false): bool
    {

        if (!is_array($filters)) $filters = ['id' => $filters];

        if ($multiple) {
            $filters = $this->getJsonModelFilters($model, $filters);
        }

        $model = $this->getJsonModelSchema($model, true);
        if (!$model) return false;

        if (is_array($filters)) $filters = $this->buildOutputQuery($model, $filters, ' && ');

        $query = 'DELETE FROM ' . $model['table'] . ' WHERE ' . $filters;
        $query = str_replace('WHERE WHERE', 'WHERE', $query);

        $r = $this->queryOut($query);
        if (!$r) $this->errors[] = 'deleteJsonModel:: ' . $query;
        return $r;
    }

    /**
     * Posts (adds) model to mySQL
     *
     * @param string $model
     * @param array $data
     * @param boolean $multiple
     *
     * @return boolean|int
     */
    public function postJsonModel($model, $data, $multiple = false): bool|int
    {

        $model = $this->getJsonModelSchema($model, true);
        if (!$model) return false;

        if ($multiple) {

            $query = $this->buildOutputQueryMultiple($model, $data);
            if ($data) {

                $query = 'INSERT INTO ' . $model['table'] . ' ' . $query;

                $r = $this->queryOut($query);
                if (!$r) $this->errors[] = 'postJsonModel:: ' . $query;
                else $r = $this->getInsertId();
                return $r;
            }
        } else {

            $full_data = $data;

            $data = $this->buildOutputQuery($model, $data);

            if ($data) {
                $query = 'INSERT INTO ' . $model['table'] . ' SET ' . $data;

                $r = $this->queryOut($query);
                if (!$r) $this->errors[] = 'postJsonModel:: ' . $query;
                else {
                    $r = $this->getInsertId();
                    $full_data['id'] = $this->getInsertId();
                    // external on new
                    $this->putExternals($model, $full_data);
                }

                return $r;
            }
        }
        return false;
    }

    /**
     * Puts (updates) model to mySQL
     * @param string $model
     * @param array $data
     * @param array $filters
     * @param boolean $multiple
     * @param boolean $externals
     * @return boolean
     */

    public function putJsonModel($model, $data, $filters = null, $multiple = false, $externals = true, $params = [])
    {
        if (isset($params['page_update']))
            $schema = $this->getJsonModelSchemaWithPageUpdate($model, true);
        else $schema = $this->getJsonModelSchema($model, true);

        if (isset($schema['filters']) && isset($params['skipSchemaFilters'])) unset($schema['filters']);

        // ---------------------------------------------------------------------------
        // filters --> get existing elements matching filters
        // data --> all records matching filters to update
        // 
        if ($multiple && $filters) {
            // looking for existing objects
            if ($filters) $f[] = str_replace('WHERE ', '', $this->getJsonModelFilters($schema, $filters));
            $exists = 'SELECT id FROM ' . $schema['table'] . ' WHERE (' . implode(') || (', $f) . ')';
            $exists = $this->query($exists);
            foreach ($exists as $k => $v) $exists[$k] = $v['id'];

            $stack = [];
            foreach ($data as $v)
                if ($v['id']) {
                    $stack[] = $v['id'];
                    if (in_array($v['id'], $exists)) {
                        $this->putJsonModel($model, $v);
                    }
                } else {
                    $this->errors[] = 'deleteJsonModel error:: data record has no .id field';
                    return false;
                }

            if ($stack)
                $filters['id'] = ['operator' => '!=', 'value' => $stack];

            // disable as clear full tables
            //$this->deleteJsonModel($model, $filters, true);

            return true;
        }
        // ---------------------------------------------------------------------------
        // other version, older
        if ($multiple) {
            $f = [];
            $fields = [];
            foreach ($data as $v) {
                foreach ($v as $k2 => $_)
                    if (!in_array($k2, $fields)) $fields[] = $k2;

                $f[] = str_replace('WHERE ', '', $this->getJsonModelFilters($schema, $v));
            }
            $exists = 'SELECT id,' . implode(',', $fields) . ' FROM ' . $schema['table'] . ' WHERE (' . implode(') || (', $f) . ')';
            $exists = $this->query($exists);

            $insert = $data;
            $update = [];

            if ($exists)
                foreach ($insert as $k => $v) {
                    $exact = false;
                    foreach ($exists as $k2 => $v2) {
                        unset($v2['id']);
                        if ($v == $v2) {
                            $exact = true;
                            $id = $exists[$k2]['id'];
                        }
                    }
                    if ($exact) {
                        $insert[$k]['id'] = $id;
                        $update[] = $insert[$k];
                        unset($insert[$k]);
                    }
                }


            if ($insert) $this->postJsonModel($model, $insert, true);
            if ($update) {
                foreach ($update as $k => $v) {
                    unset($v['id']);
                    $data = $this->buildOutputQuery($schema, $v);
                    $query = 'UPDATE ' . $schema['table'] . ' SET ' . $data . ' WHERE id=' . $update[$k]['id'];

                    $this->queryOut($query);
                }
            }
            return true;
        }
        // --------------------------------------------------------------------------------------------------------
        elseif ($filters) {
            $where = $this->getJsonModelFilters($schema, $filters); // = null,$single=false,$order=null,$limit=null,$count=false,$dataOverwrite=null,$cache=false,$groupBy=null)                        

        } else {
            $id = @$data['id'] = (@$data['id']);
            if (!$data['id']) {
                $this->errors[] = 'putJsonModel:: ID not found:: ' . $model;
                return false;
            }
            $where = 'WHERE id="' . $this->sqlSafe($id) . '"';
        }



        $model = $schema;


        if (!$model) {
            $this->errors[] = 'Model not found:: ' . $model;
            return false;
        }

        $exists_query = 'SELECT id FROM ' . $model['table'] . ' ' . $where;

        $exists = $this->query($exists_query);


        if (!$exists) {
            return $this->postJsonModel($model, $data);
        }

        // external tables on update
        if ($externals) $this->putExternals($model, $data);

        unset($data['id']);

        $data = $this->buildOutputQuery($model, $data);

        if ($data) {
            $query = 'UPDATE ' . $model['table'] . ' SET ' . $data . ' ' . $where;
            $r = $this->queryOut($query);
            return $r;
        } else {

            $this->errors[] = 'mysql error:: buildOutputQuery empty for table: ' . $model['table'];
            return false;
        }
    }
    public function getLastError(): string
    {
        $e = $this->errors;
        if ($e) return ('_uho_orm:: ' . array_pop($e));
        else return ('No errors found, last query: ' . $this->sql->getLastQueryLog());
    }

    /**
     * Puts fields to external models
     *
     * @param array $model
     * @param array $data
     */
    private function putExternals($model, $data): void
    {
        foreach ($model['fields'] as $v)
            if (isset($v['external'])) {
                $val = explode(',', $data[$v['field']]);
                $query = 'DELETE FROM ' . $v['external']['table'] . ' WHERE ' . $v['external']['id'] . '=' . $data['id'];
                $this->queryOut($query);

                if ($val) {
                    foreach ($val as $k2 => $v2)
                        $val[$k2] = '(' . $data['id'] . ',' . intval($v2) . ',' . ($k2 + 1) . ')';
                    $query = 'INSERT INTO ' . $v['external']['table'] . ' (' . $v['external']['id'] . ',' . $v['external']['id2'] . ',nr) VALUES ' . implode(',', $val);
                    $this->queryOut($query);
                }
            }
    }

    /**
     * Decaches filename
     * @param string $f
     * @return string
     */

    private function fileRemoveTime($f)
    {
        if ($this->filesDecache) {
            $f = explode('?', $f)[0];
        }
        return $f;
    }

    public function getS3()
    {
        return $this->uhoS3;
    }

    /**
     * Adds cache to filename
     *
     * @param string $f
     */
    public function fileAddTime(&$f): void
    {

        /*
            s3 support
        */

        if (isset($this->uhoS3)) {
            if ($this->filesDecache) {
                $time = $this->uhoS3->file_time($f);
                if ($time) $f .= '?' . $time;
                else $f = '';
            }

            if ($f) {
                $f = $this->uhoS3->getFilenameWithHost($f, true);
                //if (!$this->filesDecache &&  !_uho_fx::remote_file_exists($f)) $f='';
            }
        }
        /*
            standard files, uploaded to the folder
        */ elseif ($this->filesDecache && isset($this->folder_replace)) {


            if ($this->folder_replace['source'])
                $f = str_replace($this->folder_replace['source'], $this->folder_replace['destination'], $f);

            if (isset($this->s3cache['data'])) {
                $f0 = str_replace($this->folder_replace['destination'], '', $f);
                $time = $this->s3get($f0);

                if ($time) $f .= '?v=' . md5($time['time']);

                elseif ($this->filesDecache_style == 'standard') $f = '';
            } elseif ($this->folder_replace['s3']) {
                $time = $this->folder_replace['s3']->file_time($f);
                if ($time) $f .= '?v=' . $time;
                else $f = '';
            }
        } else
        if ($this->filesDecache) {

            $filename = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $f;

            if (!is_dir($filename)) $time = @filemtime($filename);

            if (isset($time) && $time && $this->filesDecache === '___') {
                $filename = explode('.', $f);
                $ext = array_pop($filename);
                $f = implode('.', $filename) . '___' . $time . '.' . $ext;
            } elseif (isset($time) && $time) $f .= '?v=' . $time;
            else $f = '';
        } elseif (isset($this->folder_replace)) {
            if ($this->folder_replace['source'])
                $f = str_replace($this->folder_replace['source'], $this->folder_replace['destination'], $f);
        }
    }

    /**
     * Adds server path to filename
     *
     * @param string $f
     * @param string $server
     */
    public function imageAddServer(&$f, $server): void
    {
        $f = $server . _uho_fx::trim($f, '/');
    }

    /**
     * Adds image size cacheto filename
     *
     * @param string $f
     */
    private function imageAddSize(&$f): void
    {
        if ($this->filesDecache) {
            $filename = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $f;
            $size = @getimagesize($filename);
            if ($size) {
                $this->fileAddTime($f);
                $f = ['src' => $f, 'width' => $size[0], 'height' => $size[1]];
            }
        }
    }

    /**
     * Renders twig
     * @param string $html
     * @param array $data
     * @return string
     */

    public function getTwigFromHtml($html, $data)
    {
        if (!$html) return;
        $twig = @new \Twig\Environment(new \Twig\Loader\ArrayLoader(array()));
        if ($twig) {
            $template = $twig->createTemplate($html);
            $html = $template->render($data);
        }
        return $html;
    }

    /**
     * Renders twig from file
     * @param string $folder
     * @param string $file
     * @param array $data
     * @return string
     */

    public function getTwigFromFile($folder, $file, $data)
    {
        $loader = new \Twig\Loader\FilesystemLoader($_SERVER['DOCUMENT_ROOT'] . $folder);
        $this->twig = new \Twig\Environment($loader);
        $html = $this->twig->render($file, $data);
        return $html;
    }

    /**
     * Filters data by virtual filters
     * @param array $schema
     * @param array $data
     * @param array $filters
     * @param boolean $any
     * @return array
     */

    public function filterResults($schema, $data, $filters, $any = false)
    {
        if (is_array($filters))
            foreach ($data as $k => $v) {
                foreach ($filters as $k2 => $v2)
                    if ($v) {
                        if (!is_array($v2)) $v2 = ['operator' => '=', 'value' => $v2];
                        $val = $v[$k2];
                        $val_filter = $v2['value'];
                        $result = false;
                        $field = _uho_fx::array_filter($schema['fields'], 'field', $k2, ['first' => true]);

                        switch ($v2['operator']) {
                            case "%LIKE%":

                                if ($field['options'] && $any) {
                                    foreach ($field['options'] as $v3)
                                        if (strpos(strtolower(' ' . $v3['label']), strtolower($val_filter))) {
                                            if ($v3['value'] == $val) $result = true;
                                        }
                                } else $result = strpos(strtolower(' ' . $val), strtolower($val_filter));
                                break;

                            case "=":
                                $result = ($val == $val_filter);
                                break;
                        }
                        if ($any && $result);
                        elseif (!$any && !$result) unset($data[$k]);
                    }
                //if (($any && !$ok) || (!$any && !$v)) unset($data[$k]);
            }
        $data = array_values($data);
        return $data;
    }

    /**
     * Adds field structure to bare record, based on sources
     * @param array $schema
     * @param array $record
     * @return array
     */

    public function updateRecordSources($schema, $record)
    {
        foreach ($schema['fields'] as $v)
            if (isset($v['source']) && isset($record[$v['field']])) {
                $value = $record[$v['field']];
                if ($v['source']['model']) {
                    if ($v['type'] == 'elements' || $v['type'] == 'checkboxes') {
                        if (!is_array($value)) $value = explode(',', $value);
                        else
                            foreach ($value as $kk => $vv)
                                if (is_array($vv) && @$vv['id']) $value[$kk] = $vv['id'];

                        $value = $this->getJsonModel($v['source']['model'], ['id' => $value]);
                    } else $value = $this->getJsonModel($v['source']['model'], ['id' => $value], true);
                }
                $record[$v['field']] = $value;
            }
        return $record;
    }

    /**
     * Updates model schema range
     * @param array $schema
     * @param array $range
     * @return array
     */

    public function updateJsonModelSchemaRange($schema, $range)
    {

        foreach ($schema['fields'] as $k => $v) {
            $f = explode(':', $v['field'])[0];
            if (!in_array($v['field'], $range) && !in_array($f, $range))  unset($schema['fields'][$k]);
        }
        return $schema;
    }

    /**
     * Updates model schema ranges
     * @param array $schema
     * @param boolean $single
     * @return array
     */
    public function updateJsonModelSchemaRanges($schema, $single)
    {

        if (isset($schema['ranges']))
            foreach ($schema['ranges'] as $k => $v)
                if ($k == 'multiple' && !$single) $schema = $this->updateJsonModelSchemaRange($schema, $v);
        return $schema;
    }

    /**
     * Checks if  model exists
     *
     * @param array $model
     *
     * @return null|true
     */
    public function checkModel($model)
    {
        $this->checkConnection('getJsonModel::' . $model['name']);
        $schema = $this->getJsonModelSchema($model);
        if ($schema) return true;
    }

    /**
     * Validates model schema - simplified version
     *
     * @param array $schema
     *
     * @return (bool|string[])[]
     *
     */

    public function schemaValidate($schema): array
    {
        $types = ['plugin', 'virtual'];
        $errors = [];
        foreach ($schema['fields'] as $k => $v) {
            if (!isset($v['outside']) && !in_array($v['type'], $types) && !isset($v['field']))
                $errors[] = 'Missing [field] for field nr ' . ($k + 1) . ' of type ' . $v['type'] . '.';
            switch ($v['type']) {
            }
        }

        if ($errors) return ['result' => false, 'errors' => $errors];
        else return ['result' => true];
    }

    /**
     * Samitizes lang fields
     * @param array $fields
     * @return array
     */

    public function sanitizeFields($fields)
    {
        foreach ($fields as $k => $v)
            if (!strpos($v, ':lang') && substr($v, 0, 6) != 'COUNT(') $fields[$k] = '`' . $v . '`';
        return $fields;
    }

    /**
     * Sets folder replace path for media/images
     *
     * @param string $source
     * @param string $destination
     * @param object $s3
     */
    public function setFolderReplace($source, $destination, $s3 = null): void
    {

        $this->folder_replace = [
            'source' => $source,
            'destination' => $destination,
            's3' => $s3
        ];
    }

    /**
     * Gets S3 cache
     *
     * @param boolean $force
     */
    private function s3getCache($force = false): void
    {
        if ($force || !$this->s3cache['data']) {
            $data = @file_get_contents($this->s3cache['filename']);
            if ($data) $data = json_decode($data, true);
            if ($data) $this->s3cache['data'] = $data;
        }
    }

    public function setS3Compress($compress): bool
    {
        if (in_array($compress, ['', 'md5'])) {
            $this->s3compress = $compress;
            return true;
        } else return false;
    }

    public function setUhoS3($object): void
    {
        $this->uhoS3 = $object;
    }

    /**
     * Gets S3 cache object
     * @param string $filename
     * @return array
     */
    private function s3get(string $filename)
    {
        switch ($this->s3compress) {
            case "md5":
                $filename = md5($filename);
                break;
        }

        if (!empty($this->s3cache['data'][$filename])) {
            $result = $this->s3cache['data'][$filename];
        } else $result = null;

        if ($result)
            switch ($this->s3compress) {
                case "md5":
                    $result = ['time' => $result];
                    break;
            }


        return $result;
    }

    /**
     * Sets S3 cache
     *
     * @param string $cache
     */
    public function s3setCache($cache): void
    {
        $this->s3cache = ['filename' => str_replace('//', '/', $_SERVER['DOCUMENT_ROOT'] . '/' . $cache), 'data' => null];
        $this->s3getCache(true);
    }
    public function s3getCacheFilename()
    {
        if (isset($this->s3cache) && !empty($this->s3cache['filename'])) return $this->s3cache['filename'];
    }

    /**
     * Set alternative tables for selected models
     */
    public function setAltTables($items): void
    {
        $this->altTables = $items;
    }

    private function updateOrderBy($query)
    {
        if (substr($query, 0, 5) == 'FIELD') return $query;
        $query = explode(',', $query);
        foreach ($query as $k => $v) {
            $v = explode(' ', trim($v));
            if ($v[0][0] != '!' && $v[0][0] != '`') $v[0] = '`' . $v[0] . '`';
            $query[$k] = implode(' ', $v);
        }
        return implode(',', $query);
    }
    public function setImageSizes($onOff): void
    {
        $this->addImageSizes = $onOff;
    }

    public function getLanguages()
    {
        return $this->langs;
    }

    // Table Creators

    public function getSchemaSQL($schema): array
    {

        $fields = [];
        $fields_sql = [];
        $id = false;

        // converts UHO ORM field types to SQL types

        foreach ($schema['fields'] as $v) {

            $type = '';
            switch ($v['type']) {
                case "date":
                    $type = 'date';
                    break;
                case "datetime":
                    $type = 'datetime';
                    break;
                case "integer":
                    $type = 'int(11)';
                    break;
                case "uid":
                    $type = 'varchar(13)';
                    break;
                case "checkboxes":
                case "elements":
                    $type = 'varchar(512)';
                    if (!empty($v['settings']['length'])) $type = 'varchar(' . $v['settings']['length'] . ')';
                    break;
                case "order":
                    $type = 'int(11)';
                    break;
                case "boolean":
                    $type = 'tinyint(4)';
                    break;
                case "text":
                case "html":
                case "json":
                case "table":
                    $type = 'text';
                    if (!empty($v['settings']['long']) && $v['settings']['long'])
                        $type = 'longtext';

                    break;
                case "select":

                    $type = 'int(11)';

                    if (!empty($v['settings']['length'])) {
                        $type = 'varchar(' . $v['settings']['length'] . ')';
                    } elseif (!empty($v['source']['model'])) {

                        $source_model = $this->getJsonModelSchema($v['source']['model']);

                        if ($source_model) {
                            $ids = _uho_fx::array_filter($source_model['fields'], 'field', 'id', ['first' => true]);
                            if ($ids && $ids['type'] == 'string') {
                                $length = empty($ids['settings']['length']) ? 256 : $ids['settings']['length'];
                                $type = 'varchar(' . $length . ')';
                            }
                        }
                    } elseif (!empty($v['options'])) {
                        $options = _uho_fx::array_extract($v['options'], 'value');
                        $type = "enum('" . implode("','", $options) . "')";
                    }

                    break;

                case "file":
                case "image":
                case "media":
                case "video":
                    $type = null;
                    break;

                case "string":

                    $length = empty($v['settings']['length']) ? 256 : $v['settings']['length'];
                    if (!empty($v['settings']['static_length'])) $type = 'char(' . $length . ')';
                    else $type = 'varchar(' . $length . ')';
                    break;
            }

            if ($v['field'] && $type) {
                $default = null;
                if ($v['type'] == 'integer' || $v['type'] == 'boolean') $default = "'0'";
                if (!empty($v['default'])) {
                    switch ($v['default']) {
                        case "{{now}}":
                            $default = 'current_timestamp()';
                            break;
                    }
                }

                $q = '`' . $v['field'] . '` ' . $type;
                if ($default) $q .= ' DEFAULT ' . $default;

                if ($v['field'] == 'id') $id = $type;
                $fields[] = $q;
                $fields_sql[] = ['Field' => $v['field'], 'Type' => $type, 'Null' => 'YES', 'Default' => $default];
            }
        }

        if (!$id) {
            $id = 'int(11)';
            array_unshift($fields, '`id` int(11)');
        }

        return ['fields' => $fields, 'fields_sql' => $fields_sql, 'id' => $id];
    }

    /**
     * @return void
     */
    public function createTable($schema, $sql)
    {
        $sql_schema = $this->getSchemaSQL($schema);


        $charset = 'utf8mb4';
        $collate = 'utf8mb4_general_ci';

        $query = 'CREATE TABLE `' . $schema['table'] . '` ';
        $query .= '(' . implode(',', $sql_schema['fields']) . ') ';
        $query .= 'ENGINE=InnoDB DEFAULT CHARSET=' . $charset . ' COLLATE=' . $collate . ';';

        $queries = [];
        $queries[] = $query;

        if (!empty($sql_schema['id'])) {
            $queries[] = 'ALTER TABLE `' . $schema['table'] . '` ADD PRIMARY KEY (`id`);';
            if ($sql_schema['id'] == 'int(11)') $queries[] = 'ALTER TABLE `' . $schema['table'] . '` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;';
        }

        if (!empty($sql)) {
            if (is_string($sql)) array_push($queries, $sql);
            else $queries = array_merge($queries, $sql);
        }

        foreach ($queries as $v)
            if (!$this->queryOut($v)) exit('SQL ERROR: <pre>' . $this->getLastError() . '</pre>');
    }


    /**
     * @return void
     */
    public function updateTable($schema, $action)
    {
        $sql_schema = $this->getSchemaSQL($schema);

        $columns = $this->query('SHOW COLUMNS FROM `' . $schema['table'] . '`');

        /*
            Array with the same depreceated types
        */
        $the_same = [
            'int' => ['int(11)', 'int(4)'],
            'tinyint' => ['tinyint(4)'],
            'int(11)' => ['int'],
            'int(4)' => ['int'],
            'tinyint(4)' => ['tinyint']
        ];

        $update = [];
        $add = [];

        foreach ($sql_schema['fields_sql'] as $v) {
            $find = _uho_fx::array_filter($columns, 'Field', $v['Field'], ['first' => true]);
            if ($find && isset($find['Type']) && $find['Type'] == $v['Type']);
            elseif ($find) {
                if (isset($the_same[$find['Type']]) && in_array($v['Type'], $the_same[$find['Type']]));
                else {
                    $v['OldType'] = $find['Type'];
                    $update[] = $v;
                }
            } else {
                $add[] = $v;
            }
        }

        if (isset($_POST['uho_orm_action'])) $action = $_POST['uho_orm_action'];

        if ($update || $add) {
            if ($action == 'alert') {
                $html = '<h3>Schema for [<code>' . $schema['table'] . '</code>] needs to be updated.</h3><ul>';
                foreach ($add as $v)
                    $html .= '<li>New field: ' . $v['Field'] . ' (' . $v['Type'] . ')</li>';

                foreach ($update as $v)
                    $html .= '<li>Field to be updated: ' . $v['Field'] . ' (' . $v['OldType'] . ' -> ' . $v['Type'] . ')</li>';

                $html .= '</ul><form action="" method="POST"><input type="hidden" name="uho_orm_action" value="auto"><input type="submit" value="Proceed"></form>';
                exit($html);
            }

            if ($action == 'auto') {
                foreach ($update as $v) {
                    $query = 'ALTER TABLE `' . $schema['table'] . '` CHANGE `' . $v['Field'] . '` `' . $v['Field'] . '` ' . $v['Type'];
                    if ($v['Null']) $query .= ' NULL';
                    if (!$this->queryOut($query)) $this->halt('SQL error: ' . $query);
                }

                foreach ($add as $v) {
                    $query = 'ALTER TABLE `' . $schema['table'] . '` ADD `' . $v['Field'] . '` ' . $v['Type'];
                    if ($v['Null']) $query .= ' NULL';
                    if (!$this->queryOut($query)) $this->halt('SQL error: ' . $query);
                }
            }
        }
    }

    /*
        Utility function to check if SQL tables align to defined schemas
        And if not - to update/create those tables
    */


    private function updateSchemaLanguages($schema)
    {
        $fields = [];
        foreach ($schema['fields'] as $field)
            if (!empty($field['field']) && strpos($field['field'], ':lang') !== false) {
                $field_name = explode(':lang', $field['field'])[0];
                foreach ($this->langs as $lang) {
                    $new_field = $field;
                    $new_field['field'] = $field_name . $lang['lang_add'];
                    $fields[] = $new_field;
                }
            } else $fields[] = $field;

        $schema['fields'] = $fields;
        return $schema;
    }

    /*
        Utility function to check if SQL tables align to defined schemas
        And if not - to update/create those tables
    */


    public function creator(array $schema, $options, $recursive = false, $update_languages = true): array
    {
        $messages = [];
        $actions = [];

        if ($update_languages) $schema = $this->updateSchemaLanguages($schema);

        $exists = $this->query("SHOW TABLES LIKE '" . $schema['table'] . "'", true);;

        if (!$exists) {
            if (isset($options) && !empty($options['create'])) {
                $sql = isset($options['create_sql']) ? $options['create_sql'] : null;
                $this->createTable($schema, $sql);
                $messages[] = 'Table has been created';
            } else $actions[] = 'table_create';
        } else {
            if (isset($options) && !empty($options['update'])) {
                $this->updateTable($schema, $options['update']);
                $messages[] = 'Table has been updated';
            } else $actions[] = 'table_update';
        }

        $additional_schemas = [];
        $additional_results = [];

        if ($recursive) {
            foreach ($schema['fields'] as $v)
                switch ($v['type']) {
                    case "media":
                        if (!in_array($v['source']['model'], $additional_schemas)) $additional_schemas[] = $v['source']['model'];
                }

            foreach ($additional_schemas as $v) {
                $schema = $this->getJsonModelSchema($v);
                if ($schema) $additional_results[] = $this->creator($schema, $options);
            }
        }


        return ['actions' => $actions, 'messages' => $messages, 'additional' => $additional_results];
    }

    public function validateSchemaObject($object, $schema)
    {
        $errors = [];
        foreach ($object as $property => $value) {
            if (isset($schema['properties'][$property])) {
                $expected_type = $schema['properties'][$property]['type'];
                if (!is_array($expected_type)) $expected_type = [$expected_type];
                $actual_type = gettype($value);
                if (!in_array($actual_type, $expected_type))
                    $errors[] = 'Invalid property format [' . $property . '], expected ' . implode(' || ', $expected_type) . ', found ' . $actual_type;
            } else $errors[] = 'Invalid property [' . $property . ']';
        }

        if ($errors) return ['errors' => $errors];
        else return ['errors' => null];
    }

    public function validateSchemaField(array $field, bool $strict): array
    {

        $types = [
            "boolean" => [],
            "date" => [],
            "datetime" => [],
            "float" => [],
            "integer" => [],
            "uid" => [],
            "checkboxes" => [],
            "elements" => [],
            "file" => ['field' => false],
            "html" => [],
            "image" => ['field' => false],
            "json" => [],
            "media" => ['field' => false],
            "plugin" => [],
            "order" => [],
            "string" => [],
            "select" => [],
            "text" => [],
            "table" => [],
            "video" => ['field' => false]
        ];

        $properties = [
            // orm
            'captions' => ['type' => 'array'],
            'field' => ['type' => 'string'],
            'hash' => ['type' => 'string'],
            'images' => ['type' => 'array'],
            'type' => ['type' => 'string'],
            'options' => ['type' => 'array'],
            'settings' => [
                'type' => 'object',
                'properties' =>
                [
                    'extension' => ['type' => 'string'],
                    'filename' => ['type' => 'string'],
                    'hashable' => ['type' => 'boolean'],
                    'folder' => ['type' => 'string'],
                    'folder_preview' => ['type' => 'string'],
                    'header' => ['type' => 'array'],
                    'length' => ['type' => 'integer'],
                    "long" => ['type' => 'boolean'],
                    'media' => ['type' => 'string'],
                    'media_field' => ['type' => 'string'],
                    "null" => ['type' => 'boolean'],
                    'plugin' => ['type' => 'string'],
                    'webp' => ['type' => 'boolean']
                ]
            ],
            'source' => ['type' => 'array'],
            'filters' => ['type' => 'array'],

            // cms
            'cms' => [
                'type' => 'object',
                'properties' => [
                    'auto' => ['type' => 'array'],
                    'case' => ['type' => 'boolean'],
                    'code' => ['type' => 'boolean'],
                    'default' => ['type' => 'string'],
                    'edit' => ['type' => 'boolean'],
                    'header' => ['type' => 'string'],
                    'help' => ['type' => ['string', 'array']],
                    'hidden' => ['type' => 'boolean'],
                    'hr' => ['type' => 'boolean'],
                    'max' => ['type' => 'integer'],
                    'label' => ['type' => 'string'],
                    'label_PL' => ['type' => 'string'],
                    'label_EN' => ['type' => 'string'],
                    'list' => ['type' => ['string', 'array']],
                    'on_demand' => ['type' => 'boolean'],
                    'position_before' => ['type' => 'string'],  //tbd
                    'position_after' => ['type' => 'string'],     //tbd
                    'required' => ['type' => 'boolean'],
                    'rows' => ['type' => 'integer'],
                    'small' => ['type' => 'boolean'],
                    'style' => ['type' => 'string'],
                    'tab' => ['type' => 'string'],
                    'tab_EN' => ['type' => 'string'],
                    'tab_PL' => ['type' => 'string'],
                    'toggle_fields' => ['type' => 'array'],
                    'search' => ['type' => ['boolean', 'string']],
                    'tall' => ['type' => 'boolean'],
                    'wide' => ['type' => 'boolean'],
                    'width' => ['type' => 'integer']
                ]
            ],


            'label' => ['type' => 'string'],
            'label_PL' => ['type' => 'string'],
            'label_EN' => ['type' => 'string'],
            'list' => ['type' => ['string', 'array']]


        ];

        if ($strict) {
            unset($properties['list']);
            unset($properties['label']);
            unset($properties['label_EN']);
            unset($properties['label_PL']);
        }

        if (!isset($types[$field['type']])) {
            $response = ['errors' => ['Field type invalid: ' . $field['type']]];
            return $response;
        }

        foreach ($field as $property => $value) {
            if (isset($properties[$property])) {
                $expected_type = $properties[$property]['type'];
                if (!is_array($expected_type)) $expected_type = [$expected_type];
                $actual_type = gettype($field[$property]);

                if ($expected_type == ['object'] && $actual_type == 'array') {
                    $response = $this->validateSchemaObject($value, $properties[$property]);
                    if ($response['errors']) return $response;
                } elseif (!in_array($actual_type, $expected_type)) {
                    $response = ['errors' => ['Field property [' . $property . '] type invalid: expected ' . implode(' || ', $expected_type) . ', found ' . $actual_type]];
                    return $response;
                }
            } else return ['errors' => ['Property [' . $property . '] unknown']];
        }

        return ['errors' => []];
    }

    /**
     * Validates basic schema structure
     *
     * @param array $schema
     * @return array
     */

    public function validateSchema(array $schema, bool $strict = false): array
    {
        $errors = [];

        $properties = [
            'buttons_edit' => ['type' => ['array']],
            'buttons_page' => ['type' => ['array']],
            'data' => ['type' => ['array']],
            'disable' => ['type' => ['array']],
            'fields' => ['type' => 'array'],
            'filters' => ['type' => ['array']],
            'label' => ['type' => ['string', 'array']],
            'layout' => ['type' => ['array']],
            'help' => ['type' => ['string']],
            'helper_models' => ['type' => ['array']],
            'model' => ['type' => ['array']],
            'model_name' => ['type' => ['string']],
            'order' => ['type' => ['array']],
            'page_update' => ['type' => ['string']],
            'table' => ['type' => 'string', 'required' => true],
            'url' => ['type' => ['string', 'array']]
        ];

        foreach ($properties as $property => $rules) {
            if (!empty($rules['required']) && !isset($schema[$property])) {
                $errors[] = 'Missing required property [' . $property . '].';
            } elseif (isset($schema[$property])) {
                $expected_type = $rules['type'];
                if (!is_array($expected_type)) $expected_type = [$expected_type];
                $actual_type = gettype($schema[$property]);
                if (!in_array($actual_type, $expected_type)) {
                    $errors[] = 'Property [' . $property . '] type invalid: expected ' . implode(' || ', $expected_type) . ', found ' . $actual_type . '.';
                }
            }
        }

        foreach ($schema as $property => $value) {
            if (!isset($properties[$property])) {
                $errors[] = 'Property [' . $property . '] unknown.';
            }
        }


        if (!empty($schema['fields']) && is_array($schema['fields'])) {
            foreach ($schema['fields'] as $k => $v) { {
                    $name = isset($v['field']) ? $v['field'] : 'nr ' . ($k + 1);
                    $response = $this->validateSchemaField($v, $strict);
                    if ($response['errors'])
                        $errors[] = 'Schema field [' . $name . '] is invalid --> ' . implode(', ', $response['errors']);
                }
            }
        }

        return ['errors' => $errors];
    }

    /**
     * @return never
     */
    private function halt(string $message)
    {
        exit('<pre>' . $message . '</pre>');
    }

    public function setHaltOnError(bool $halt): void
    {
        $this->halt_on_error = $halt;
        $this->sql->setHaltOnError($halt);
    }

    public function convertBase64($image, $allowed_extensions)
    {
        if ($image && is_string($image)) {
            if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
                $image = substr($image, strpos($image, ',') + 1);
                $type = strtolower($type[1]);
                if (in_array($type, $allowed_extensions))  return base64_decode($image);
            }
        }
        return false;
    }

    private function getTempFilename()
    {
        $filename = $this->temp_public_folder . '/' . uniqid();
        $filename = $_SERVER['DOCUMENT_ROOT'] . $filename;
        return $filename;
    }

    public function setTempPublicFolder($folder)
    {
        $this->temp_public_folder = $folder;
    }

    private function copy($src, $dest, $remove_src = false)
    {
        if ($this->getS3())
        {
            $s3=$this->getS3();
            if (substr($src,0,4)=='http')
            {
                // s3 cannot get source stream from another s3
                $temp_filename=$this->getTempFilename(true);
                copy($src,$temp_filename);
                $s3->copy($temp_filename,$dest);
                unlink($temp_filename);
            } else
            {
                $s3->copy($src,$dest);
            }     
            
        } else
        {
            $dest = $_SERVER['DOCUMENT_ROOT'] . $dest;
            copy($src, $dest);            
        }

        if ($remove_src) @unlink($src);
    }

    /*
        Upload image to the model
    */

    public function uploadImage($schema, $record, $field_name, $image, $temp_filename = null)
    {
        
        $root = $_SERVER['DOCUMENT_ROOT'];
        $field = _uho_fx::array_filter($schema['fields'], 'field', $field_name, ['first' => true]);
        if (!$field) return false;

        /* retina? */
        $retina = [];
        foreach ($field['images'] as $v)
            if (!empty($v['retina'])) {
                if (isset($v['width'])) $v['width'] = $v['width'] * 2;
                if (isset($v['height'])) $v['height'] = $v['height'] * 2;
                $v['folder'] .= '_x2';
                $retina[] = $v;
            }
        if ($retina) $field['images'] = array_merge($field['images'], $retina);

        /* create original image */

        $extension = 'jpg';
        $filename = str_replace($field['settings']['filename'], '%uid%', $record['uid']) . '.' . $extension;
        $original = array_shift($field['images']);
        $original_filename = $field['settings']['folder'] . '/' . $original['folder'] . '/' . $filename;
        
        if ($image && !$temp_filename) {            
            $temp_filename = $this->getTempFilename(true);
            if (!file_put_contents($temp_filename, $image)) {
                return false;
            }
        }
        
        $temp_original_filename=$temp_filename;
        $this->copy($temp_filename, $original_filename); // no-remove

        /* resize */

        $result = true;

        foreach ($field['images'] as $v) {
            if (isset($v['crop'])) $v['cut'] = $v['crop'];
            $v['enlarge'] = true;

            if ($this->getS3())
            {
                $src=$temp_original_filename;
                $dest = $this->getTempFilename(true);
                $dest_s3=$field['settings']['folder'] . '/' . $v['folder'] . '/' . $filename;
            } else
            {
                $src=$root . $original_filename;
                $dest = $root . $field['settings']['folder'] . '/' . $v['folder'] . '/' . $filename;
            }

            $r = _uho_thumb::convert(
                $filename,
                $src,
                $dest,
                $v
            );

            if (!$r['result']) $result = false;
                elseif ($this->getS3())
                {
                    $this->copy($dest,$dest_s3);
                }
        }

        return $result;
    }

    /*
        Remove image from the model
    */

    public function removeImage($model_name, $record_id, $field_name)
    {        
        $result = false;
        $schema = $this->getSchema($model_name);
        $record = $this->getJsonModel($model_name, ['id' => $record_id], true);
        $s3 = $this->getS3();
        if (isset($record[$field_name])) {
            $result = true;            
            foreach ($record[$field_name] as $image) {
                $image=$this->fileRemoveTime($image);
                if ($s3) $s3->unlink($image);
                else unlink($_SERVER['DOCUMENT_ROOT'] . $image);
            }
        }

        return $result;;
    }



    /*
        Add base64 image to the model
    */

    public function addImage($model_name, $record_id, $field_name, $image)
    {

        $image = $this->convertBase64($image, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $schema = $this->getSchema($model_name);
        if ($image) $record = $this->getJsonModel($model_name, ['id' => $record_id], true);
        else $record = null;

        if ($schema && $record && isset($record[$field_name])) {
            return $this->uploadImage($schema, $record, $field_name, $image);
        }

        return false;
    }

    /*
        Add src image to the model
    */

    public function addImageSrc($model_name, $record_id, $field_name, $filename)
    {
        if ($this->getS3() && substr($filename,0,4)=='http')
        {
            $temp_filename=$this->getTempFilename(true);
            copy($filename,$temp_filename);
            $filename=$temp_filename;
        } else $temp_filename=null;

        $schema = $this->getSchema($model_name);
        $record = $this->getJsonModel($model_name, ['id' => $record_id], true);

        if ($schema && $record && isset($record[$field_name])) {
            $response=$this->uploadImage($schema, $record, $field_name, null, $filename);
            if ($temp_filename) unlink($temp_filename);
            return $response;
        }

        return false;
    }
}
