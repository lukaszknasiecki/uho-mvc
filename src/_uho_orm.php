<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_orm_upload;
use Huncwot\UhoFramework\_uho_orm_schema;
use Huncwot\UhoFramework\_uho_orm_schema_loader;
use Huncwot\UhoFramework\_uho_orm_schema_sql;

/**
 * This is an ORM class providing model-based communication
 *  with mySQL databases, using JSON composed model structures
 * It also supports image caching including S3 support
 *
 * CLASS METHODS INDEX
 *
 * Constructor:
 * - __construct($sql, $lang, $keys, $test = false)
 *
 * Global Options
 * - setKeys($keys): void
 * - setDebug(bool $debug): void
 * - isDebug(): bool
 * 
 * Language Methods
 * - getLanguages():array
 * - setLanguages($t): void
 * - setLanguage($lang): void
 * 
 * Twig utility methods
 * - getTwigFromHtml($html, $data)
 * - getTwigFromFile($folder, $file, $data)
 * - getTemplate($vv, $v, $twig = false)
 * 
 * Error Methods
 * - getLastError(): string
 * - halt(string $message)
 * 
 * Managing schema json paths (delegated to _uho_orm_schema_loader)
 * - getRootPaths($add_root=false): array
 * - addRootPath($path): void
 * - removeRootPaths(): void
 * - loadJson($filename)
 *
 * Schemas (delegated to _uho_orm_schema)
 * - getSchema($name, $lang = false, $params = [])
 * - getSchemaWithPageUpdate($name, $lang = false)
 * - updateSchemaSources($schema, $record = null, $params = null)
 * - validateSchema(array $schema, bool $strict = false): array
 * 
 * mySQL direct methods (delegate to _uho_mysqli)
 * - query($query, $single = false, $stripslashes = true, $key = null, $do_field_only = null, $force_sql_cache = false)
 * - queryOut($query)
 * - multiQueryOut($query)
 * - sqlSafe($s)
 * - sqlCheckConnection(string|null $message = null): void
 * 
 * Methods translating between SQL tables and UHO_ORM schemas (delegated to _uho_orm_schema_sql)
 * - sqlCreator(array $schema, $options, $recursive = false, $update_languages = true): array
 * 
 * Get model methods
 * - get ($name, $filters = null, $single = false, $order = null, $limit = null, $params = null)
 * - getDeep(array $params)
 * - getFilters($name, $filters = null, $single = false, $order = null, $limit = null, $count = false, $dataOverwrite = null, $cache = false, $groupBy = null)
 * - sqlSanitizeLangFields($fields)
 * - updateFieldValue($field, $v0, $full = null)
 * - updateFieldImageAddServer(&$f, $server): void

 * 
 * Post/Patch/Delete methods
 * - post($model, $data, $multiple = false)
 * - getInsertId()
 * - delete($model, $filters, $multiple = false): bool
 * - truncate($model)
 * - put ($model, $data, $filters = null, $multiple = false, $params = [])
 * - patch($model, $data, $filters = null, $multiple = false, $params = [])
 * - buildOutputQuery($model, $data, string $join = ','): array|string
 * - buildOutputQueryMultiple($model, $data, $output = 'query')
 * - filterResults($schema, $data, $filters, $any = false)
 * - updateRecordSources($schema, $record)
 * - setImageSizes($onOff): void
 * - setFolderReplace($source, $destination, $s3 = null): void
 *
 * FILENAME CACHING METHODS
 * - fileAddTime(&$f): void
 * - fileRemoveTime($f)
 * - setFilesDecache($q): void
 * - imageAddSize(&$f): void
 * 
 * FILE/IMAGE UPLOAD METHODS (Delegated to _uho_orm_upload)
 *
 * - uploadBase64Image($model_name, $record_id, $field_name, $image)
 * - uploadImage($schema, $record, $field_name, $image, $temp_filename = null)
 * - removeImage($model_name, $record_id, $field_name)
 * - setTempPublicFolder($folder)
 * 
 * S3 Bucket Support (Delegated to _uho_orm_s3)
 * - isS3()
 * - getS3()
 * - setS3($object): void
 * - setS3Compress($compress): bool
 * - s3setCache($cache): void
 * - s3getCacheFilename()
 * - s3getCache($force = false): void
 * - s3get(string $filename)
 * - s3copy($src, $dest)
 *
 */

class _uho_orm
{
    /**
     * There is one current language ($lang/$lang_add)
     * and a list of all available languages ($langs)
     */
    private $lang;
    private $lang_add;
    private $langs = [];
    /**
     * indicates if filesDecache should be performed
     */
    private $addImageSizes = false;
    private $filesDecache = false;
    private $filesDecache_style = 'standard';
    private $temp_public_folder = '/temp';
    /**
     * indicates if for elements_double fields we should
     * use integer is only one value is set
     */
    private $elements_double_first_integer = false;

    /**
     * array of query errors
     */
    private $errors = [];
    /**
     * indicates if there is folder to be replaced
     * for media assets
     */
    private $folder_replace = null;
    /**
     * S3 Manager for handling all S3 operations
     */
    private _uho_orm_s3 $s3Manager;
    /**
     * Upload Manager for handling file and image upload operations
     */
    private _uho_orm_upload $uploadManager;
    /**
     * Schema Loader for handling schema file path management and JSON loading
     */
    private _uho_orm_schema_loader $schemaLoader;
    /**
     * Schema Manager for handling schema operations
     */
    public _uho_orm_schema $schemaManager;
    /**
     * Schema SQL Manager for handling SQL table creation and updates
     */
    private _uho_orm_schema_sql $schemaSqlManager;

    private $sql;
    private $keys;
    private bool $debug = false;
    private bool $test = false;

    /**
     * Constructor
     * @return null
     */

    function __construct(_uho_mysqli|null $sql, string|null $lang, array $keys, bool $test = false)
    {
        $this->sql = $sql;

        /* current language */
        $this->setLanguage($lang);

        /* keys used for encryption */
        $this->keys = $keys;

        /* skips sanitization if no sql is defined */
        $this->test = $test;

        /* initialize S3 Manager */
        $this->s3Manager = new _uho_orm_s3();
        $this->s3Manager->setTempPublicFolder($this->temp_public_folder);

        /* initialize Upload Manager */
        $this->uploadManager = new _uho_orm_upload($this, $this->s3Manager);

        /* initialize Schema Loader */
        $this->schemaLoader = new _uho_orm_schema_loader();
        $this->schemaLoader->addRootPath('/application/models/json/');

        /* initialize Schema Manager */
        $this->schemaManager = new _uho_orm_schema($this, $this->schemaLoader);

        /* initialize Schema SQL Manager */
        $this->schemaSqlManager = new _uho_orm_schema_sql($this);
    }

    /**
     * Sets keys for encryption
     */
    public function setKeys(array $keys): void
    {
        $this->keys = $keys;
    }
    public function getKeys()
    {
        return $this->keys;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }
    public function isDebug(): bool
    {
        return $this->debug;
    }


    /**
     *   LANGUAGE METHODS
     */

    /*
    *    Returns list of all available languages
    */
    public function getLanguages(): array
    {
        return $this->langs;
    }

    /**
     * Sets list of available languages
     */
    public function setLanguages($t): void
    {
        $this->langs = [];
        foreach ($t as $v)
            $this->langs[] = ['lang' => $v, 'lang_add' => '_' . strtoupper($v)];
    }

    /*
      Sets current language
     */
    public function setLanguage($lang): void
    {
        $this->lang = $lang;
        if ($lang)
            $this->lang_add = '_' . strtoupper($lang);
    }

    /**
     * Renders twig from string
     */

    public function getTwigFromHtml(string $html, array $data): string|null
    {
        if (!$html) return null;
        $twig = @new \Twig\Environment(new \Twig\Loader\ArrayLoader(array()));
        if ($twig) {
            $template = $twig->createTemplate($html);
            $html = $template->render($data);
        }
        return $html;
    }

    /**
     * Renders twig from file
     * @return string
     */

    public function getTwigFromFile(string $folder, string $file, array $data): string
    {
        $loader = new \Twig\Loader\FilesystemLoader($_SERVER['DOCUMENT_ROOT'] . $folder);
        $twig = new \Twig\Environment($loader);
        $html = $twig->render($file, $data);
        return $html;
    }

    /**
     * Updates HTML template, based on older %string% pattern, with Twig support
     */

    private function getTemplate(string $vv, array $v, bool $twig = false): string
    {
        if ($v)
            foreach ($v as $k3 => $v3)
                if (is_string($v3))
                    $vv = str_replace('%' . $k3 . '%', $v3, $vv);
        if ($twig) $vv = $this->getTwigFromHtml($vv, $v);
        return $vv;
    }

    /**
     *  Returns last sql error
     */
    public function getLastError(): string
    {
        $e = $this->errors;
        if ($e) return ('_uho_orm:: ' . array_pop($e));

        // Check schema loader errors
        $schema_error = $this->schemaLoader->getLastError();
        if ($schema_error) return ('_uho_orm:: ' . $schema_error);

        return ('No errors found, last query: ' . $this->sql->getLastQueryLog());
    }

    /**
     *  Stops execution
     */
    public function halt(string $message)
    {
        exit('<pre>' . $message . '</pre>');
    }

    /**
     * MANAGEMENT OF SCHEMA JSON PATHS/FOLDERS (delegated to _uho_orm_schema_loader)
     */

    public function getRootPaths($add_root = false): array
    {
        return $this->schemaLoader->getRootPaths($add_root);
    }

    public function addRootPath(string $path): void
    {
        $this->schemaLoader->addRootPath($path);
    }

    public function removeRootPaths(): void
    {
        $this->schemaLoader->removeRootPaths();
    }

    /**
     * Loads and parses JSON file
     * searching in root_paths (delegated to _uho_orm_schema_loader)
     */

    public function loadJson(string $filename): array|null
    {
        return $this->schemaLoader->loadJson($filename);
    }

    /**
     * Loads model schema
     * @param $name
     * @param $lang
     * @param array $params
     * @return array
     */

    public function getSchema($name, $lang = false, $params = [])
    {
        return $this->schemaManager->getSchema($name, $lang, $params);
    }

    /**
     * Loads model schema using PageUpdate
     * @param string $name
     * @param  $lang
     * @return array
     */

    public function getSchemaWithPageUpdate($name, $lang = false)
    {
        return $this->schemaManager->getSchemaWithPageUpdate($name, $lang);
    }

    /**
     * Updates model schema sources based on record
     * @param array $schema
     * @param array $record
     * @param array $params
     * @return array
     */

    public function updateSchemaSources($schema, $record = null, $params = [])
    {
        return $this->schemaManager->updateSchemaSources($schema, $record, $params);
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
     * Sanitizes language fields
     */

    private function sqlSanitizeLangFields($fields)
    {
        foreach ($fields as $k => $v)
            if (!strpos($v, ':lang') && substr($v, 0, 6) != 'COUNT(') $fields[$k] = '`' . $v . '`';
        return $fields;
    }

    /**
     * Updates model field value by type 
     * and field additional settings
     */

    private function updateFieldValue(string $field_type, $value, array|null $field = null)
    {

        if ($value)
            switch ($field_type) {
                case "text":
                    if (isset($field['settings']['function']))
                        switch ($field['settings']['function']) {
                            case "nl2br":
                                $value = explode(chr(13) . chr(10), $value);
                                break;
                            default:
                                break;
                        }
                    break;

                case "datetime":

                    if (!empty($field['settings']['format']))
                        switch ($field['settings']['format']) {
                            case "ISO8601":
                            case "UTC":
                                try {
                                    $dt = new \DateTime($value, new \DateTimeZone('UTC'));
                                    $value = $dt->format('Y-m-d\TH:i:s\Z');
                                } catch (\Exception $e) {
                                    $value = null;
                                }
                                break;
                        }
                    break;

                case "table":

                    if (is_string($value)) {
                        $temp = $value;
                        $value = json_decode(($value), true);

                        if ($value && !empty($field['settings']['fields'])) {
                            $rows = [];
                            foreach ($value as $v) {
                                $row = [];
                                foreach ($field['settings']['header'] as $kk => $vv)
                                    $row[$vv['field']] = $v[$kk];
                                $rows[] = $row;
                            }
                            $value = $rows;
                        }

                        if ($value && isset($field['settings']['format']) && $field['settings']['format'] == 'object') {
                            $v1 = $value;
                            $value = [];
                            foreach ($v1 as $k => $v)
                                $value[] = [$k, $v];
                        }
                        /*
                        if (!$value && !empty($field['settings']['read_string_format'])) {
                            $temp = explode(chr(13) . chr(10), $temp);
                            foreach ($temp as $k => $v) $temp[$k] = explode(';', $v);
                            $value = $temp;
                        }*/
                    }
                    break;

                    /*
                case "template":

                    if ($value && is_string($value)) $value = @json_decode(stripslashes($value), true);
                    $vv = array();

                    if ($field['fields'])
                        foreach ($field['fields'] as $v) {
                            if (!is_array($v)) {
                                $v = explode('#', $v);
                                $v = array('field' => $v[0], 'label' => $v[1], 'type' => $v[2]);
                            }

                            if (strpos($v['field'], ':lang')) {
                                $f = explode(':lang', $v['field']);
                                $f = array_shift($f);
                                $vv[$f] = $value[$f . $this->lang_add];
                            } else $vv[$v['field']] = $value[$v['field']];
                        }
                    $value = $vv;
                    break;*/
            }
        return $value;
    }

    /**
     * Adds server path to filename
     */
    private function updateFieldImageAddServer(string &$f, string $server): void
    {
        $f = $server . _uho_fx::trim($f, '/');
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

    public function getFilters($name, $filters = null, $single = false, $order = null, $limit = null, $count = false, $dataOverwrite = null, $cache = false, $groupBy = null)
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
                return array();
            }
        }

        if ($count) {
            // not possible because of filters            
        }


        if (!$model) $this->halt('_uho_orm::getFilters::model corrupted:' . $name);

        if (!isset($model['filters'])) $model['filters'] = array();

        if (is_array($filters)) {
            if ($filters) $model['filters'] = array_merge($model['filters'], $filters);
            $model['filters'] = $this->schemaSqlManager->getFiltersQueryArray($model);
            if ($model['filters']) $model['filters'] = 'WHERE ' . implode(' && ', $model['filters']);
        } elseif ($filters) $model['filters'] = 'WHERE ' . $filters;

        if (!$model['filters']) $model['filters'] = '';
        return $model['filters'];
    }

    /**
     * Gets model from mySQL, with its nested children models
     */

    public function getDeep(array $params)
    {
        $result=$this->get($params);
        $schema=$this->getSchema($params['schema']);

        if ($result && !empty($schema['children']) && empty($schema['first']))
            {
                foreach ($result as $k=>$record)
                    foreach ($schema['children'] as $child_name => $child_definition)
                    {
                        $filters=empty($child_definition['filters']) ? [] : $child_definition['filters'];
                        $filters[$child_definition['parent']]=$record[$child_definition['id']];

                        $result[$k][$child_name]=$this->getDeep([
                            'schema'=>$child_definition['schema'],
                            'filters'=>$filters
                        ]);
                    }
            }

        return $result;
    }

    /**
     * REST Aliases for methods
     */

    public function patch($model, $data, $filters = null, $multiple = false, $params = [])
    {
        return $this->put($model, $data, $filters, $multiple, $params);
    }

    /**
     * Gets model from mySQL
     * Uses $schema if array, or line function params (older style)
     * @return array
     */

    public function get(string|array $schema, $filters = null, $single = false, $order = null, $limit = null, array $params = [])
    {

        $allowed_params = [
            'schema' => ['name'],
            'additionalParams' => ['additionalParams', []],
            'addLanguages' => ['add_languages', false],
            'count' => ['count', false],                           // if you want to return count of records for selected query
            'groupBy' => ['groupBy', ''],
            'fields' => ['fields_to_read', []],                // array of fields to read, is string value- gets this list from schema.fields_to_read.value array
            'filters' => ['filters', []],                        // array of filters for the query
            'first' => ['single', false],                          // return only first value, without records array wrapper
            'limit' => ['limit', null],                            // query limit in either SQL format '0,10' or paging, as an array [page,per_page]
            'key' => ['returnByKey', null],
            'order' => ['order', null],                            // query order, either SQL string or array for ORDER BY FIELD, i.e. ['type'=>'field','value'=>['title','date]]
            'returnQuery' => ['return_query', false],
            'replace_values' => ['replace_values', []],
            'skipSchemaFilters' => ['skipSchemaFilters', false]
        ];

        /**
         * $schema can be a string, then we are using linear input and $params array
         * if it's an array (1) all params are in there, skipping other input
         * (2) if it's array with table key, it means it's older call, where we just need
         * to take model name (.table) from this array, this will be removed in the future
         */

        $keep_existing_vars = true;
        $predefined_schema = null;

        if (is_array($schema) && !empty($schema['model_name'])) {
            $predefined_schema = $schema;
            $name = $schema['model_name'];
        } elseif (is_array($schema)) {
            $params = $schema;
            $keep_existing_vars = false;
        }

        foreach ($allowed_params as $source => $dest)
            if (isset($params[$source]))
                ${$dest[0]} = $params[$source];
            elseif (isset($dest[1]) && (!$keep_existing_vars || !isset(${$dest[0]})))
                ${$dest[0]} = $dest[1];


        if (is_string($schema)) $name = $schema;

        if ($count === true)
            $count = ['type' => 'quick'];

        if (empty($name)) {
            $this->halt('get::no-schema-name');
        }

        if ($name == 'cms_users') {
            var_dump($single);
        }

        $name_string = $name;

        // checks if SQL connection has been established

        $this->sqlCheckConnection('get::' . $name_string);

        // set default fields to read if not set in the query
        if ($single && !$fields_to_read) $fields_to_read='_single';
        elseif (!$fields_to_read) $fields_to_read='_multiple';

        // create sql-compliant limit params from paging array

        if (is_array($limit) && count($limit)==2)
        {
            $limit_page=intval($limit[0]);
            $limit_perpage=intval($limit[1]);

            if ($limit_page>0 && $limit_perpage>0)
                $limit = ($limit_page - 1) * $limit_perpage . ',' . $limit_perpage;
                else $limit='';
        } elseif (is_array($limit)) $limit='';

        /**
         * get model schema
         */

        if (!empty($predefined_schema))
            $model = $predefined_schema;
        elseif (isset($params['page_update'])) {
            $model = $this->getSchemaWithPageUpdate($name);
        } else {
            $model = $this->getSchema($name, false, ['return_error' => true]);
            if (isset($model['result']) && !$model['result'])
                $this->halt($model['message']);
        }

        if (empty($model['table']) || empty($model['fields']))
            $this->halt('_uho_orm::get->table/fields [' . @$name . '] not found in schema');


        /**
         * get from schema:
         * limits fields to read from schema.fields_to_read object if needed
         */

        if ($fields_to_read && !is_array($fields_to_read)) {
            $fields_to_read = !empty($model['fields_to_read'][$fields_to_read]) ? $model['fields_to_read'][$fields_to_read] : null;
        }

        /**
         * convert filters to sql WHERE query
         */

        if ($skipSchemaFilters) $model['filters'] = [];

        /**
         * filling filters with additionalParams, i.e. { type:%1%} 
         */
        if (!empty($model['filters']) && !empty($additionalParams))
            foreach ($model['filters'] as $k => $v)
                foreach ($additionalParams as $k2 => $v2)
                    if (is_string($v2))
                        $model['filters'][$k] = str_replace('%' . $k2 . '%', $v2, $v);

        /**
         * combine schema filters and GET query filters to create final WHERE query
         */
        $sql_query_filters = '';

        if (is_array($filters) || is_array($model['filters'])) {
            if (empty($model['filters'])) $model['filters'] = [];
            if (!empty($filters)) $model['filters'] = array_merge($model['filters'], $filters);

            $sql_query_filters = $this->schemaSqlManager->getFiltersQueryArray($model);
            if ($sql_query_filters) $sql_query_filters = 'WHERE ' . implode(' && ', $sql_query_filters);
            else $sql_query_filters = '';
        } elseif ($filters) $sql_query_filters = 'WHERE ' . $filters;

        /**
         * Set (default) order from schema, if input order is empty
         */
        if (!$order && !empty($model['order'])) $order = $model['order'];

        /**
         * Prepare fields to be read by SQL query
         */
        $fields = ['id'];   // id is required for all schemas
        $fields_models = array();
        $fields_auto = array();

        foreach ($model['fields'] as $v) {
            $field = isset($v['field']) ? $v['field'] : null;
            $field_type = isset($v['type']) ? $v['type'] : null;

            // first, skip non-sql fields 

            if ($field && in_array($field, $fields));
            elseif ($field && $fields_to_read && !in_array($field, $fields_to_read));
            elseif (in_array($v['type'], ['file', 'image', 'video', 'audio', 'media', 'virtual', 'plugin'])) array_push($fields_auto, $v);
            elseif ($field_type == 'model') array_push($fields_models, $v);
            elseif (isset($v['outside']['model'])) array_push($fields_models, ['model' => $v['outside']]);

            // now, add validated field

            elseif ($field) {
                if (!empty($v['settings']['field_output']))
                    array_push($fields, $field . '` AS `' . $v['settings']['field_output']);
                else array_push($fields, $field);
                if ($v['type'] == 'image_media') array_push($fields_auto, $v);
            }

            if (($add_languages || isset($v['add_languages'])) && strpos($v['field'], ':lang')) {
                $vv = explode(':lang', $v['field'])[0];
                foreach ($this->langs as $v2)
                    $fields[] = $vv . $v2['lang_add'];
            }
        }

        /*
            Convert limit to SQL query
        */

        if ($single) $query_limit = 'LIMIT 0,1';
        elseif (!$limit) $query_limit = '';
        else $query_limit = 'LIMIT ' . $limit;

        /**
         * Convert order to SQL query
         * ['type'=>'field', 'values'=>['title','date']]
         * ['field'=>'title', 'sort'=>'DESC']
         */

        if (!empty($order)) {
            if (is_array($order) && !empty($order['type']) && !empty($order['values']) && $order['type'] == 'field')
                $order = 'FIELD (' . implode(',', $order['values']) . ')';
            elseif (is_array($order) && !empty($order['field']) && !empty($order['sort']))
                $order = $order['field'] . ' ' . $order['sort'];
            elseif (!is_string($order)) $order = '';
        }

        if ($order) $query_order = ' ORDER BY ' . $order;
        else $query_order = '';

        /**
         * Manage SQL query if count is set (return count/avg of records only)
         */
        if (!empty($count['type'])) {
            switch ($count['type']) {
                case "quick":
                    $fields = array('COUNT(*)');
                    break;
                case "average":
                    if ($count['function']) $fields = array('AVG(' . $count['function'] . '(' . $count['field'] . ')) AS average');
                    else $fields = array('AVG(' . $count['field'] . ') AS average');
                    break;
            }
        }

        /**
         * Building and execute final SQL query
         */

        $fields_read = $fields;

        $query = 'SELECT ' . implode(',', $this->sqlSanitizeLangFields($fields_read)) . ' FROM ' . $model['table'] . ' ' . $sql_query_filters;

        if ($groupBy) $query .= ' GROUP BY ' . $groupBy;
        if ($query_order) $query .= ' ' . $query_order;
        if ($query_limit) $query .= ' ' . $query_limit;

        if ($return_query) return $query;

        $data = $this->query($query);

        /**
         * Return system COUNT(*) and AVG()
         */

        if (!empty($count['type']))
            switch ($count['type']) {
                case "quick":
                    return @$data[0]['COUNT(*)'];
                    break;
                case "average":
                    return @$data[0]['average'];
                    break;
            }

        /**
         * Replace certain fields of all the records
         * with predefined values
         */
        if ($replace_values) {
            foreach ($data as $k => $v)
                foreach ($replace_values as $k2 => $v2)
                    if (isset($v[$k2])) {
                        $data[$k][$k2] = $v2;
                    }
        }

        /**
         * Re-ordering records for ORDER BY FIELD
         */
        if (isset($order['type']) && $order['type'] == 'FIELD') {
            $data_new_order = array();
            foreach ($order['values'] as $v) {
                $d0 = _uho_fx::array_filter($data, $order['field'], $v, array('first' => true));
                if ($d0) array_push($data_new_order, $d0);
            }
            $data = $data_new_order;
        }

        /**
         * Re-working all returned records
         */

        $data = $this->getUpdateRecords($model, $data);
        $data = $this->getUpdateRecordsMedia($model, $data, $fields_auto);

        /**
         * Update fields of type 'model'
         */

        foreach ($data as $k => $v)
            foreach ($fields_models as $v2)
                if (@$v2['type'] != 'model') {

                    $v2['model'] = _uho_fx::arrayReplace($v2['model'], $v, '%', '%');
                    if ($v2['model']['order']) $order = $v2['model']['order'];
                    else $order = null;

                    if (isset($v2['field'])) {
                        $data[$k][$v2['field']] = $this->get($v2['model']['model'], $v2['model']['filters'], false, $order);
                    }
                }

        /*
            remove unwanted fields
        */

        if ($fields_to_read && !empty($model['fields_to_read']))
            {
                foreach ($data as $k=>$record)
                    foreach ($record as $field=>$val)
                        if (!in_array($field,$fields_to_read))
                            unset($data[$k][$field]);
            }

        /*
         update records urls
        */

        if ($data && isset($model['url']))
            $data = $this->getUpdateUrls($model['url'], $data, $additionalParams);

        /*
            Change output based on $count/$single/$returnByKey
        */

        if ($single === true && isset($data[0])) $data = $data[0];

        if (!empty($returnByKey)) {
            $d = [];
            foreach ($data as $v)
                $d[$v[$returnByKey]] = $v;
            $data = $d;
        }

        if ($count) return count($data);
        else return $data;
        
    }

    /**
     * Updates raw records get from SQL
     * by schema, mostly source-based fields
     */


    private function getUpdateRecords($model, $data)
    {
        foreach ($data as $k => $v) {

            /**
             * Dehashing values and sources (1st record only)
             */

            foreach ($model['fields'] as $k2 => $v2) {

                if (isset($v2['hash']) && isset($data[$k][$v2['field']])) {
                    $data[$k][$v2['field']] = _uho_fx::decrypt($data[$k][$v2['field']], $this->keys, $v2['hash']);
                }

                if (isset($v2['source']) && $k == 0 && @is_array($v2['source']['fields'])) {
                    foreach ($v2['source']['fields'] as $k3 => $v3)
                        if ($v3 != rtrim($v3, '#')) {
                            $model['fields'][$k2]['source']['fields'][$k3] = rtrim($v3, '#');
                            $model['fields'][$k2]['source']['fields_hashed'][] = rtrim($v3, '#');
                        }
                    if (is_array(@$model['fields'][$k2]['source']['fields_hashed']))
                        $model['fields'][$k2]['source']['fields_hashed'] = array_flip($model['fields'][$k2]['source']['fields_hashed']);
                }
            }

            foreach ($model['fields'] as $k2 => $v2) {
                /**
                 * removing :lang fields as they are already duplicated to output final languages
                 */

                if (isset($v2['field']) && strpos(@$v2['field'], ':lang')) unset($data[$k][$v2['field']]);

                /**
                 * getting values for 'model' fields
                 */
                elseif ($v2['type'] == 'model') {
                    if (empty($v2['settings']['order'])) $v2['settings']['order'] = '';
                    if (!empty($v2['settings']['filters']))
                        $v2['settings']['filters'] = _uho_fx::arrayReplace($v2['settings']['filters'], $v, '%', '%');
                    $data[$k][$v2['field']] = $this->get($v2['settings']['schema'], $v2['settings']['filters'], false, $v2['settings']['order']);
                }
                /**
                 * for fields with no field specified - doing nothing
                 */
                elseif (@!$v[$v2['field']]) {
                }
                /**
                 * elements type with source.model
                 */
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
                    $data[$k][$v2['field']] = $this->get($v2['source']['model'], ['id' => $f4], false, ['type' => 'FIELD', 'field' => 'id', 'values' => $f4], null, $params0);
                }
                /**
                 * checkboxes type with source.model
                 */
                elseif ($v2['type'] == 'checkboxes' && isset($v2['source']['model'])) {

                    $f = explode(',', $v[$v2['field']]);
                    $f4 = array();
                    foreach ($f as $k3 => $v3) {
                        $v3 = explode(':', $v3);
                        if (isset($v3[1])) $v3 = $v3[1];
                        else $v3 = $v3[0];
                        if (isset($v2['output']) && $v2['output'] == 'string') $f4[$k3] = $v3;
                        else $f4[$k3] = intval($v3);
                    }

                    if (!empty($v2['source']['model_fields'])) $params0 = ['fields' => $v2['source']['model_fields']];
                    else $params0 = [];
                    $data[$k][$v2['field']] = $this->get($v2['source']['model'], ['id' => $f4], false, null, null, $params0);
                }
                /**
                 * checkboxes type with options
                 */
                elseif ($v2['type'] == 'checkboxes' && isset($v2['options'])) {
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
                /**
                 * select type with source.model
                 */
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
                    $data[$k][$v2['field']] = $this->get($model0, $f, $getSingle, $order);
                }
                /**
                 * Select fields with source.model
                 */
                elseif (@$v2['source'] && ($v2['type'] == 'elements' || $v2['type'] == 'select' || $v2['type'] == 'checkboxes')) {

                    /**
                     * Create Source Data - all elements to choose from
                     */

                    if (!@$v2['source']['data']) {

                        if (@$v2['source']['model']) {

                            $ids = [];
                            foreach ($data as $v5)
                                if (!in_array($v5[$v2['field']], $ids)) $ids[] = $v5[$v2['field']];

                            if (isset($v2['source']['id'])) $id_field = $v2['source']['id'];
                            else  $id_field = 'id';

                            if (!empty($v2['source']['model_fields'])) $params0 = ['fields' => $v2['source']['model_fields']];
                            else $params0 = [];

                            $vv = $this->get($v2['source']['model'], [$id_field => $ids], false, null, null, $params0);

                            $v4 = [];
                            foreach ($vv as $v3)
                                $v4[$v3[$id_field]] = $v3;

                            if (isset($v2['source']['field']))
                                foreach ($v4 as $k6 => $_) $v4[$k6] = $v4[$k6][$v2['source']['field']];

                            $v2['source']['data'] = $model['fields'][$k2]['source']['data'] = $v4;
                        } else {

                            if (!$v2['source']['fields']) $this->halt('_uho_model::error No source fields for ' . $v2['field']);
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
                                $v2['source']['data'][$k3]['url'] = $this->getTemplate($v2['source']['url'], $v3);
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
                /**
                 * Source Double Type
                 */
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
                                $d0 = $this->get($v3['model'], array('id' => $eTables[$v3['slug']]));
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
                            if (isset($mm['label'])) $v5['label'] = $this->getTemplate($mm['label'], $v5);
                            if (isset($mm['image'])) $v5['image'] = $this->getTemplate($mm['image'], $v5);
                            $v5['_table'] = $_table;
                            $v5['_slug'] = $_slug;
                            $v5['_model'] = $d_models[$_slug];
                            array_push($v4, $v5);
                        }
                    }

                    $data[$k][$v2['field']] = $v[$v2['field']] = $v4;
                }
                /**
                 * elements_pair type
                 */
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
                                $d0 = $this->get($v3['model'], array('id' => $eTables[$v3['slug']]));
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
                                if ($mm['label']) $v5['label'] = $this->getTemplate($mm['label'], $v5);
                                if ($mm['image']) $v5['image'] = $this->getTemplate($mm['image'], $v5);
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
                    $data[$k][$field] = $this->updateFieldValue($v2['type'], $data[$k][$field], $v2);
                }
            }
        }

        /**
         * Update values by type
         */
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
                        case "json":

                            if (strpos(@$v2['field'], ':lang')) {
                                $v3 = explode(':', $v2['field']);
                                $field0 = array_shift($v3);
                            } else $field0 = @$v2['field'];

                            $data[$k][$field0] = @json_decode($v[$field0], true);
                            break;
                    }


        return $data;
    }

    /**
     * Update records - automatic media fields
     */

    private function getUpdateRecordsMedia($model, $data, $fields_auto)
    {

        foreach ($data as $k => $v)
            foreach ($fields_auto as $v2) {

                switch ($v2['type']) {

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

                            $v2['settings']['folder'] = $this->getTemplate($v2['settings']['folder'], $v);

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
                                    $filename0 = $this->getTemplate($v4['filename'], $v, true);
                                } elseif (isset($v2['filename'])) {
                                    $filename0 = $this->getTemplate($v2['filename'], $v, true);
                                } else $filename0 = $this->getTemplate('%uid%', $v);

                                if (@$v4['id']) $image_id = $v4['id'];
                                else $image_id = $v4['folder'];

                                $m[$image_id] = $v2['settings']['folder'] . '/' . $v4['folder'] . '/' . $filename0 . '.' . $extension;
                                $m[$image_id] = str_replace('//', '/', $m[$image_id]);


                                /*
                                    optional - add image size
                                */
                                if (isset($v4['size'])) {
                                    $this->imageAddSize($m[$image_id]);
                                } elseif (isset($v2['server'])) $this->updateFieldImageAddServer($m[$image_id], $v2['server']);
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

                        if (!$media_model) $this->halt('no source model defined for: ' . $name . '::' . $v2['field']);

                        $media = $this->get($media_model, ['model' => $model_name . @$v2['media']['suffix'], 'model_id' => $v['id']], false, 'model_id_order');

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


        foreach ($data as $k => $v)
            foreach ($model['fields'] as $v2)
                switch ($v2['type']) {
                    case "video":
                        if (@$v2['poster'] && @$data[$k][$v2['poster']]) {
                            $data[$k][$v2['field']]['poster'] = $data[$k][$v2['poster']];
                        }
                        break;
                }

        return $data;
    }

    /*
        Each model can have predefined URL schema,
        here we are filling it with values so it can 
        be later converted via Router class to the final URL
    */
    private function getUpdateUrls($url_schema, array $records, array $additionalParams)
    {
        foreach ($records as $kk => $vv) {
            if (isset($additionalParams) && $additionalParams) $vv = $vv + $additionalParams;

            $records[$kk]['url'] = $url = $url_schema;
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
                        $records[$kk]['url'][$k] = $v;
                    }
                    // twig pattern
                    $records[$kk]['url'][$k] = $this->getTwigFromHtml($records[$kk]['url'][$k], $vv);
                }
        }

        return $records;
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
                        case 'blocks':
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
     * Truncates model
     */

    public function truncate(string $model)
    {
        $model = $this->getSchema($model, true);
        if (!$model || empty($model['table'])) return false;
        else {
            $this->queryOut('TRUNCATE TABLE ' . $model['table']);
            return true;
        }
    }

    /**
     * Deletes object(s)
     */

    public function delete($model, $filters, bool $multiple = false): bool
    {

        if (!is_array($filters)) $filters = ['id' => $filters];

        if ($multiple) {
            $filters = $this->getFilters($model, $filters);
        }

        $model = $this->getSchema($model, true);
        if (!$model) return false;

        if (is_array($filters)) $filters = $this->buildOutputQuery($model, $filters, ' && ');

        $query = 'DELETE FROM ' . $model['table'] . ' WHERE ' . $filters;
        $query = str_replace('WHERE WHERE', 'WHERE', $query);

        $r = $this->queryOut($query);
        if (!$r) $this->errors[] = 'delete:: ' . $query;
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
    public function post($model, $data, $multiple = false): bool|int
    {

        $model = $this->getSchema($model, true);
        if (!$model) return false;

        if ($multiple) {

            $query = $this->buildOutputQueryMultiple($model, $data);
            if ($data) {

                $query = 'INSERT INTO ' . $model['table'] . ' ' . $query;

                $r = $this->queryOut($query);
                if (!$r) $this->errors[] = 'post:: ' . $query;
                else $r = $this->getInsertId();
                return $r;
            }
        } else {

            $full_data = $data;

            $data = $this->buildOutputQuery($model, $data);

            if ($data) {
                $query = 'INSERT INTO ' . $model['table'] . ' SET ' . $data;

                $r = $this->queryOut($query);
                if (!$r) $this->errors[] = 'post:: ' . $query;
                else {
                    $r = $this->getInsertId();
                    $full_data['id'] = $this->getInsertId();
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
     * @return boolean
     */

    public function put($model, $data, $filters = null, $multiple = false, $params = [])
    {
        if (isset($params['page_update']))
            $schema = $this->getSchemaWithPageUpdate($model, true);
        else $schema = $this->getSchema($model, true);

        if (isset($schema['filters']) && isset($params['skipSchemaFilters'])) unset($schema['filters']);

        // ---------------------------------------------------------------------------
        // filters --> get existing elements matching filters
        // data --> all records matching filters to update
        // 
        if ($multiple && $filters) {
            // looking for existing objects
            if ($filters) $f[] = str_replace('WHERE ', '', $this->getFilters($schema, $filters));
            $exists = 'SELECT id FROM ' . $schema['table'] . ' WHERE (' . implode(') || (', $f) . ')';
            $exists = $this->query($exists);
            foreach ($exists as $k => $v) $exists[$k] = $v['id'];

            $stack = [];
            foreach ($data as $v)
                if ($v['id']) {
                    $stack[] = $v['id'];
                    if (in_array($v['id'], $exists)) {
                        $this->put($model, $v);
                    }
                } else {
                    $this->errors[] = 'delete error:: data record has no .id field';
                    return false;
                }

            if ($stack)
                $filters['id'] = ['operator' => '!=', 'value' => $stack];


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

                $f[] = str_replace('WHERE ', '', $this->getFilters($schema, $v));
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


            if ($insert) $this->post($model, $insert, true);
            if ($update) {
                foreach ($update as $k => $v) {
                    unset($v['id']);
                    $data = $this->buildOutputQuery($schema, $v);
                    $query = 'UPDATE ' . $schema['table'] . ' SET ' . $data . ' WHERE id=' . $update[$k]['id'];

                    $this->queryOut($query);
                }
            }
            return true;
        } elseif ($filters) {
            $where = $this->getFilters($schema, $filters);
        } else {
            $id = @$data['id'] = (@$data['id']);
            if (!$data['id']) {
                $this->errors[] = 'put:: ID not found:: ' . $model;
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
            return $this->post($model, $data);
        }

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

    /**
     * FILENAME CACHING METHODS
     */

    /**
     * Adds cache to filename
     */
    public function fileAddTime(string &$f): void
    {

        /*
            s3 support
        */

        if ($this->isS3()) {
            if ($this->filesDecache) {
                $time = $this->s3Manager->getFileTime($f);
                if ($time) $f .= '?' . $time;
                else $f = '';
            }

            if ($f) {
                $f = $this->s3Manager->getFilenameWithHost($f, true);
            }
        }

        /*
            standard files, uploaded to the folder
        */ elseif ($this->filesDecache && isset($this->folder_replace)) {

            if ($this->folder_replace['source'])
                $f = str_replace($this->folder_replace['source'], $this->folder_replace['destination'], $f);

            if ($this->s3Manager->getCacheData() !== null) {
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

    /**
     * Adds image size cache to filename
     */
    private function imageAddSize(string &$f): void
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

                        $value = $this->get($v['source']['model'], ['id' => $value]);
                    } else $value = $this->get($v['source']['model'], ['id' => $value], true);
                }
                $record[$v['field']] = $value;
            }
        return $record;
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



    public function setImageSizes($onOff): void
    {
        $this->addImageSizes = $onOff;
    }

    /**
     * Validates schema structure
     *
     * @param array $schema
     * @return array
     */

    public function validateSchema(array $schema, bool $strict = false): array
    {
        return $this->schemaManager->validateSchema($schema, $strict);
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
        if (!$result) $this->errors[] = $query;
        return $result;
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
     * mySQL string sanitizatin
     * @param string $s
     * @return string
     */

    public function sqlSafe($s)
    {
        if (!$this->sql && $this->test) return $s;
        if (!$this->sql) $this->halt('sqlSafe::sql-not-defined');
        if ($s && !is_array($s)) return ($this->sql->getBase()->real_escape_string($s));
    }

    /**
     * Checks mySQL connection
     */
    private function sqlCheckConnection(string|null $message = null): void
    {
        if (!$this->sql) $this->halt('_uho_orm::No SQL defined::' . $message);
    }


    /*
        Utility function to check if SQL tables align to defined schemas
        And if not - to update/create those tables
        Delegates to Schema SQL Manager
    */
    public function sqlCreator(array $schema, $options, $recursive = false, $update_languages = true): array
    {
        return $this->schemaSqlManager->creator($schema, $options, $recursive, $update_languages);
    }

    /**
     * FILE/IMAGE UPLOAD METHODS
     * Delegates to Upload Manager
     */

    /**
     * Sets temporary publicly available temporary folder
     * Delegates to Upload Manager
     */
    public function setTempPublicFolder($folder)
    {
        $this->temp_public_folder = $folder;
        $this->s3Manager->setTempPublicFolder($folder);
        $this->uploadManager->setTempPublicFolder($folder);
    }

    /**
     * Add base64 image to the model
     * Delegates to Upload Manager
     */
    public function uploadBase64Image($model_name, $record_id, $field_name, $image)
    {
        return $this->uploadManager->uploadBase64Image($model_name, $record_id, $field_name, $image);
    }

    /**
     * Upload image to the model
     * Delegates to Upload Manager
     */
    public function uploadImage($schema, $record, $field_name, $image, $temp_filename = null)
    {
        return $this->uploadManager->uploadImage($schema, $record, $field_name, $image, $temp_filename);
    }

    /**
     * Remove image from the model
     * Delegates to Upload Manager
     */
    public function removeImage($model_name, $record_id, $field_name)
    {
        return $this->uploadManager->removeImage($model_name, $record_id, $field_name);
    }

    /**
     * S3 SUPPORT METHODS
     */

    /**
     * Checks/Setters/Getters for S3 iin this ORM
     */

    /**
     * Check if S3 is enabled
     * Delegates to S3Manager
     */
    public function isS3(): bool
    {
        return $this->s3Manager->isS3();
    }

    /**
     * Get S3 object
     * Delegates to S3Manager
     */
    public function getS3()
    {
        return $this->s3Manager->getS3();
    }

    /**
     * Set S3 object
     * Delegates to S3Manager
     */
    public function setS3($object): void
    {
        $this->s3Manager->setS3($object);
    }

    /**
     * Sets S3 cache compress method
     * Delegates to S3Manager
     */
    public function setS3Compress($compress): bool
    {
        return $this->s3Manager->setS3Compress($compress);
    }

    /**
     * Gets S3 cached object by filename
     * Delegates to S3Manager
     */
    private function s3get(string $filename)
    {
        return $this->s3Manager->s3get($filename);
    }

    /**
     * Loads full S3 cache array (or creates it)
     * Delegates to S3Manager
     */
    private function s3getCache($force_rebuild = false): void
    {
        $this->s3Manager->s3getCache($force_rebuild);
    }

    /**
     * Enabled S3 cache by setting cache filename and loading/creating it
     * Delegates to S3Manager
     */
    public function s3setCache(string $cache_filename): void
    {
        $this->s3Manager->s3setCache($cache_filename);
    }

    /**
     * Copy file using S3
     * Delegates to S3Manafger
     */
    private function s3copy(string $src, string $dest)
    {
        $getTempCallback = function () {
            return $this->uploadManager->getTempFilename(true);
        };
        $this->s3Manager->s3copy($src, $dest, $getTempCallback);
    }

    /**
     * Returns current S3 cache filename
     * Delegates to S3Manager
     */
    public function s3getCacheFilename()
    {
        return $this->s3Manager->s3getCacheFilename();
    }
}
