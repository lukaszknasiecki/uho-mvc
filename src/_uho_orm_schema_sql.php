<?php

namespace Huncwot\UhoFramework;

/**
 * UHO Schema SQL Manager
 *
 * Handles SQL table creation and updates based on UHO ORM schemas
 *
 * Methods:
 * - getSchemaSQL($schema): array
 * - createTable($schema, $sql): array
 * - updateTable($schema, $action): array
 * - creator(array $schema, $options, $recursive = false, $update_languages = true): array
 */

class _uho_orm_schema_sql
{
    private _uho_orm $orm;

    public function __construct(_uho_orm $orm)
    {
        $this->orm = $orm;
    }

    /**
     * Converts UHO ORM schema to SQL field definitions
     */
    private function getSchemaSQL($schema): array
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
                case "timestamp":
                    $type = 'datetime';
                    break;
                case "integer":
                    $type = 'int(11)';
                    break;
                case "blocks":
                    $type = 'json';
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

                    if (!empty($v['settings']['length']))
                    {
                        $type = 'varchar(' . $v['settings']['length'] . ')';
                    } elseif (!empty($v['source']['model']))
                    {
                        $source_model = $this->orm->getSchema($v['source']['model']);

                        if ($source_model) {
                            $ids = _uho_fx::array_filter($source_model['fields'], 'field', 'id', ['first' => true]);
                            if ($ids && $ids['type'] == 'string') {
                                $length = empty($ids['settings']['length']) ? 255 : $ids['settings']['length'];
                                $type = 'varchar(' . $length . ')';
                            }
                        }
                    } elseif (!empty($v['options']))
                    {
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

                    $length = empty($v['settings']['length']) ? 255 : $v['settings']['length'];
                    if (!empty($v['settings']['static_length'])) $type = 'char(' . $length . ')';
                    else $type = 'varchar(' . $length . ')';
                    break;
            }

            if ($v['field'] && $type) {
                $not_null=false;
                $default = null;
                if ($v['type'] == 'integer' || $v['type'] == 'boolean')
                {
                    $default = "'0'";
                    $not_null=true;
                }
                if (!empty($v['settings']['default'])) {
                    switch ($v['settings']['default']) {
                        case "{{now}}":
                            $default = 'current_timestamp()';
                            break;
                    }
                }

                $q = '`' . $v['field'] . '` ' . $type;
                if ($not_null) $q .= ' NOT NULL';
                if ($default) $q .= ' DEFAULT ' . $default;

                if ($v['field'] == 'id') $id = $type;
                $fields[] = $q;
                $fields_sql[] = ['Field' => $v['field'], 'Type' => $type, 'Null' => !$not_null, 'Default' => $default];
            }
        }

        if (!$id) {
            $id = 'int(11)';
            array_unshift($fields, '`id` int(11)');
        }

        return ['fields' => $fields, 'fields_sql' => $fields_sql, 'id' => $id];
    }

    /**
     * Creates mySQL table based on UHO_ORM schema
     */

    private function createTable($schema, $sql): array
    {
        $performed_action = null;
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
            if (!$this->orm->queryOut($v)) $this->orm->halt('SQL ERROR: <pre>' . $this->orm->getLastError() . '</pre>');

        $performed_action = 'table_create';
        return ['action' => $performed_action];
    }


    /**
     * Updates mySQL table based on UHO_ORM schema
     * $action = 'alert' | 'auto' | 'info'
     */
    private function updateTable($schema, $action): array
    {

        $sql_schema = $this->getSchemaSQL($schema);
        $columns = $this->orm->query('SHOW COLUMNS FROM `' . $schema['table'] . '`');

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

        foreach ($sql_schema['fields_sql'] as $v)
        {

            $find = _uho_fx::array_filter($columns, 'Field', $v['Field'], ['first' => true]);
            if ($find && isset($find['Type']) && $find['Type'] == $v['Type']);
            elseif ($find)
            {
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
        $performed_action = null;

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

            if ($action == 'info') {
                $message = 'Schema for [' . $schema['table'] . '] needs to be updated.';
                foreach ($add as $v)
                    $message .= '- New field: ' . $v['Field'] . ' (' . $v['Type'] . ')';

                foreach ($update as $v)
                    $message .= '- Field to be updated: ' . $v['Field'] . ' (' . $v['OldType'] . ' -> ' . $v['Type'] . ')';

                return ['action' => $performed_action,'message'=>$message];
            }

            if ($action == 'auto') {
                
                foreach ($update as $v) {
                    $performed_action = 'table_update';
                    $query = 'ALTER TABLE `' . $schema['table'] . '` CHANGE `' . $v['Field'] . '` `' . $v['Field'] . '` ' . $v['Type'];
                    if ($v['Null']) $query .= ' NULL'; else $query .= ' NOT NULL';
                    if ($v['Default']) $query .= ' DEFAULT '.$v['Default'];
                    if (!$this->orm->queryOut($query)) $this->orm->halt('SQL error: ' . $query);
                }

                foreach ($add as $v) {
                    $performed_action = 'table_create';
                    $query = 'ALTER TABLE `' . $schema['table'] . '` ADD `' . $v['Field'] . '` ' . $v['Type'];
                    if ($v['Null']) $query .= ' NULL'; else $query .= ' NOT NULL';
                    if ($v['Default']) $query .= ' DEFAULT '.$v['Default'];
                    if (!$this->orm->queryOut($query)) $this->orm->halt('SQL error: ' . $query);
                }
            }
        }

        return ['action' => $performed_action];
    }


    /*
        Utility function to check if SQL tables align to defined schemas
        And if not - to update/create those tables
    */

    public function creator(array $schema, $options, $recursive = false, $update_languages = true): array
    {
        $messages = [];
        $actions = [];

        if ($update_languages) $schema = $this->orm->schemaManager->updateSchemaLanguages($schema);

        $exists = $this->orm->query("SHOW TABLES LIKE '" . $schema['table'] . "'", true);;

        if (!$exists)
        {
            /* create table */
            if (isset($options) && !empty($options['create']) && in_array($options['create'], ['auto','alert']))
            {
                $sql = isset($options['create_sql']) ? $options['create_sql'] : null;
                $response = $this->createTable($schema, $sql);
                $messages[] = 'Table has been created';
                if ($response['action']) $actions[] = $response['action'];
            } else
            {
                $actions[] = 'table_create';
                $messages[]= 'Table ['.$schema['table'].'] needs to be created';
            }
        } else {
            if (isset($options) && !empty($options['update'])) {
                $response = $this->updateTable($schema, $options['update']);

                if (!empty($response['message'])) $messages[] = $response['message'];
                    elseif ($response['action'] == 'table_update') $messages[] = 'Table has been updated';

                if ($response['action']) $actions[] = $response['action'];
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
                $schema = $this->orm->getSchema($v);
                if ($schema) $additional_results[] = $this->creator($schema, $options);
            }
        }


        return [
            'actions' => $actions,
            'messages' => $messages,
            'additional' => $additional_results
        ];
    }

    /**
     * Converts filters from schema to mySQL query
     * @param string $model
     * @return string
     * 
     * { "value" : 1 }
     * { [{"operator" : ">", { "value": 1 }],[{"operator" : "<", { "value": 10 }] }
     * { {"operator" : "in", { "value": ["2020-02-01","date_from","date_to"] }
     * { [1,2,3 ] }
     * { "type": "sql", "value":"CONCAT (....)"
     * { "type": "custom", "join":"||","value":["",""] }
     */

    public function getFiltersQueryArray($model)
    {

        $swap = [];
        if (is_array($model['filters']))
            foreach ($model['filters'] as $k => $v)
                if ($v === NULL) unset($model['filters'][$k]);
                else {

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

                    if (isset($field['settings']['hash']) && !$this->orm->getKeys()) $this->orm->halt('_uho_orm::getFiltersQueryArray::nokeys');
                    if (isset($field['settings']['hash'])) $v = _uho_fx::encrypt($v, $this->orm->getKeys(), $field['settings']['hash']);

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
                                // skipping standard !=''
                                } else {
                                    if (!is_array($v)) $v = explode(',', $v);
                                    foreach ($v as $k2 => $v2)
                                        if ($iDigits) {
                                            if (!intval($v2)) unset($v[$k2]);
                                            else $v[$k2] = _uho_fx::dozeruj($v2, $iDigits);
                                        }
                                    if ($eq == '!=') $eq = '%!LIKE%';
                                    else $eq = '%LIKE%';
                                    if (isset($field['settings']['multiple_filters'])
                                        && in_array($field['settings']['multiple_filters'],['&&','||'])
                                        ) $or = ' '.$field['settings']['multiple_filters'].' ';
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

                    $field = $field_key;

                    // multiple values
                    if (is_array($v) && @$v['type'] == 'custom');
                    elseif (is_array($v)) {
                        if (is_array($model['filters'][$k]) && ($eq == '=' || $eq == '!='))
                            foreach ($model['filters'][$k] as $k2 => $v2)
                                $model['filters'][$k][$k2] = $this->orm->sqlSafe($v2);

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
                            $model['filters'][$k] = $function . '(`' . $field . '`' . $collate . ') LIKE "%' . $this->orm->sqlSafe($v) . '%"';
                        else {
                            $model['filters'][$k] = $pre_field . '`' . $field . '`' . $collate . ' LIKE "%' . $this->orm->sqlSafe($v) . '%"';
                        }
                    } else if ($eq == 'LIKE%') $model['filters'][$k] = $field . ' LIKE "' . $this->orm->sqlSafe($v) . '%"';
                    else if ($eq == '%LIKE') $model['filters'][$k] = $field . ' LIKE "%' . $this->orm->sqlSafe($v);
                    else if ($eq == '=' && $collate) {
                        $model['filters'][$k] = $function . '(' . $field . $collate . ') = "' . $this->orm->sqlSafe($v) . '"';
                    } else if ($raw) $model['filters'][$k] = '`' . $field . '`' . $eq . $v;
                    else {
                        //
                        //if ($field['hash']) $model['filters'][$k] = $k . $eq.'md5("' . $this->sqlSafe($v) . '")';
                        //    else 
                        if (is_integer($v))
                            $model['filters'][$k] = $field . $eq . $v;
                        else $model['filters'][$k] = $pre_field . '`' . $field . '`' . $eq . '"' . $this->orm->sqlSafe($v) . '"';
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
}
