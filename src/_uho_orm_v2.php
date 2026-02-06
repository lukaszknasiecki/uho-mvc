<?php

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_orm;
use Huncwot\UhoFramework\_uho_fx;

/**
 * UHO ORM V2 - Prepared Statements Version
 *
 * This class extends _uho_orm and overrides CRUD methods to use
 * MySQL prepared statements for improved security.
 *
 * Changes from v1:
 * - get() uses prepared statements for WHERE clauses
 * - post() uses prepared INSERT statements
 * - put() uses prepared UPDATE statements
 * - delete() uses prepared DELETE statements
 */

class _uho_orm_v2 extends _uho_orm
{
    /**
     * Gets the parameter type for mysqli prepared statements
     * @param mixed $value
     * @param string $fieldType - ORM field type
     * @return string - 's' for string, 'i' for integer, 'd' for double, 'b' for blob
     */
    private function getParamType($value, string $fieldType = ''): string
    {
        if ($fieldType) {
            switch ($fieldType) {
                case 'integer':
                case 'boolean':
                case 'order':
                    return 'i';
                case 'float':
                    return 'd';
                default:
                    return 's';
            }
        }

        if (is_int($value)) return 'i';
        if (is_float($value)) return 'd';
        return 's';
    }

    /**
     * Builds prepared statement parameters from model data for INSERT/UPDATE
     * @param array $model - schema model
     * @param array $data - data to process
     * @return array - ['params' => [...], 'fields' => [...]]
     */
    private function buildPreparedParams(array $model, array $data): array
    {
        $skip_fields = ['image', 'video', 'file', 'audio', 'virtual', 'media'];
        $params = [];

        foreach ($data as $k => $v) {
            if ($k == 'id') continue;

            $field = _uho_fx::array_filter($model['fields'], 'field', $k, ['first' => true]);

            if (!$field) {
                if (isset($model['filters'][$k])) {
                    $params[] = [$this->getParamType($v), $v, $k];
                }
                continue;
            }

            if (in_array($field['type'], $skip_fields)) continue;

            $processedValue = $this->processFieldValueForWrite($field, $v);

            if ($processedValue === null && !isset($field['settings']['nullable'])) {
                continue;
            }

            $type = $this->getParamType($processedValue, $field['type']);
            $params[] = [$type, $processedValue, $k];
        }

        return $params;
    }

    /**
     * Process field value for writing to database
     * @param array $field - field definition from schema
     * @param mixed $v - value to process
     * @return mixed - processed value
     */
    private function processFieldValueForWrite(array $field, $v)
    {
        switch ($field['type']) {
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
                    foreach ($v as $k2 => $v2) {
                        if ($iDigits) {
                            $v[$k2] = _uho_fx::dozeruj($v2, $iDigits);
                        }
                    }
                    $v = implode(',', $v);
                }
                break;

            case 'boolean':
                if ($v === true || $v === 'on' || $v === 1 || $v === '1') $v = 1;
                else $v = 0;
                break;

            case "float":
                $v = floatval($v);
                break;

            case "integer":
                $v = intval($v);
                break;

            case "json":
                if (is_array($v)) $v = json_encode($v, true);
                break;

            case 'table':
                if (isset($field['settings']['format']) && $field['settings']['format'] == 'object') {
                    $v0 = $v;
                    $v = [];
                    foreach ($v0 as $v2) {
                        $v[$v2[0]] = $v2[1];
                    }
                }
                $v = json_encode($v);
                break;

            case 'order':
                $v = intval($v);
                break;
        }

        if (isset($field['settings']['hash'])) {
            $v = _uho_fx::encrypt($v, $this->getKeys(), $field['settings']['hash']);
        }

        return $v;
    }

    /**
     * Build prepared parameters for multiple records INSERT
     * @param array $model - schema model
     * @param array $data - array of records
     * @return array - ['fields' => [...], 'records' => [...]]
     */
    private function buildPreparedParamsMultiple(array $model, array $data): array
    {
        $skip_fields = ['image', 'virtual', 'media', 'video', 'file', 'audio'];
        $fields = [];
        $records = [];

        $modelFields = $model['fields'];
        $is_id = false;

        foreach ($modelFields as $k => $v) {
            if (empty($v['field']) || in_array($v['type'], $skip_fields)) {
                unset($modelFields[$k]);
            } elseif ($v['field'] == 'id') {
                $is_id = true;
            }
        }

        if (!$is_id) {
            $modelFields[] = ['field' => 'id', 'type' => 'integer'];
        }

        foreach ($modelFields as $v) {
            $hasValue = false;
            foreach ($data as $record) {
                if (isset($record[$v['field']])) {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $fields[] = $v['field'];
            }
        }

        foreach ($data as $record) {
            $recordParams = [];
            foreach ($fields as $fieldName) {
                $field = _uho_fx::array_filter($model['fields'], 'field', $fieldName, ['first' => true]);
                $val = isset($record[$fieldName]) ? $record[$fieldName] : null;

                if ($val !== null) {
                    if ($field) {
                        $val = $this->processFieldValueForWrite($field, $val);
                    }
                } else {
                    $val = '';
                }

                $type = $field ? $this->getParamType($val, $field['type']) : $this->getParamType($val);
                $recordParams[] = [$type, $val];
            }
            $records[] = $recordParams;
        }

        return ['fields' => $fields, 'records' => $records];
    }

    /**
     * Build WHERE clause with prepared statement placeholders and parameters
     * @param array $model - schema model
     * @param array $filters - filters array
     * @return array - ['clause' => 'WHERE ...', 'params' => [...]]
     */
    private function buildPreparedWhere(array $model, array $filters): array
    {
        if (empty($filters)) {
            return ['clause' => '', 'params' => []];
        }

        $whereParts = [];
        $params = [];

        foreach ($filters as $k => $v) {
            if ($v === null) continue;

            $field = _uho_fx::array_filter($model['fields'], 'field', $k, ['first' => true]);
            $fieldType = $field ? $field['type'] : '';

            if (is_array($v) && isset($v['type']) && $v['type'] == 'custom') {
                if (is_string($v['value'])) {
                    $whereParts[] = '(' . $v['value'] . ')';
                } else {
                    $whereParts[] = '(' . implode(' ' . ($v['join'] ?? 'AND') . ' ', $v['value']) . ')';
                }
                continue;
            }

            if (is_array($v) && isset($v['type']) && $v['type'] == 'sql') {
                $whereParts[] = '`' . $k . '`=' . $v['value'];
                continue;
            }

            $operator = '=';
            if (is_array($v) && isset($v['operator'])) {
                $operator = $v['operator'];
                $v = $v['value'] ?? null;
            }

            if ($v === null) continue;

            if ($field && $fieldType == 'boolean') {
                $v = intval($v);
            }

            if ($field && in_array($fieldType, ['elements', 'checkboxes'])) {
                $iDigits = 8;
                if (!empty($field['settings']['output'])) {
                    switch ($field['settings']['output']) {
                        case '4digits': $iDigits = 4; break;
                        case '6digits': $iDigits = 6; break;
                        case '8digits': $iDigits = 8; break;
                        case 'string': $iDigits = 0; break;
                    }
                }

                if (!is_array($v)) $v = explode(',', $v);
                foreach ($v as $k2 => $v2) {
                    if ($iDigits) {
                        if (!intval($v2)) {
                            unset($v[$k2]);
                        } else {
                            $v[$k2] = _uho_fx::dozeruj($v2, $iDigits);
                        }
                    }
                }
                $v = array_values($v);

                if (empty($v)) continue;

                $likeOr = isset($field['settings']['multiple_filters']) ? $field['settings']['multiple_filters'] : '||';
                $likeParts = [];
                foreach ($v as $likeVal) {
                    $likeParts[] = '`' . $k . '` LIKE ?';
                    $params[] = ['s', '%' . $likeVal . '%', $k];
                }
                $whereParts[] = '(' . implode(' ' . $likeOr . ' ', $likeParts) . ')';
                continue;
            }

            if (is_array($v)) {
                if (empty($v)) continue; // skip empty arrays to avoid WHERE ()

                if ($operator == 'in') {
                    if (count($v) == 2) {
                        $whereParts[] = '(`' . $k . '`>=? AND `' . $k . '`<=?)';
                        $params[] = [$this->getParamType($v[0], $fieldType), $v[0], $k];
                        $params[] = [$this->getParamType($v[1], $fieldType), $v[1], $k];
                    }
                    continue;
                }

                $or = $operator == '!=' ? ' AND ' : ' OR ';
                $multiParts = [];
                foreach ($v as $subVal) {
                    $multiParts[] = '`' . $k . '`' . $operator . '?';
                    $params[] = [$this->getParamType($subVal, $fieldType), $subVal, $k];
                }
                $whereParts[] = '(' . implode($or, $multiParts) . ')';
                continue;
            }

            if ($operator == '%LIKE%') {
                $whereParts[] = '`' . $k . '` LIKE ?';
                $params[] = ['s', '%' . $v . '%', $k];
            } elseif ($operator == 'LIKE%') {
                $whereParts[] = '`' . $k . '` LIKE ?';
                $params[] = ['s', $v . '%', $k];
            } elseif ($operator == '%LIKE') {
                $whereParts[] = '`' . $k . '` LIKE ?';
                $params[] = ['s', '%' . $v, $k];
            } else {
                $whereParts[] = '`' . $k . '`' . $operator . '?';
                $params[] = [$this->getParamType($v, $fieldType), $v, $k];
            }
        }

        $clause = '';
        if (!empty($whereParts)) {
            $clause = 'WHERE ' . implode(' AND ', $whereParts);
        }

        return ['clause' => $clause, 'params' => $params];
    }

    /**
     * Posts (adds) model to mySQL using prepared statements
     *
     * @param string|array $model
     * @param array $data
     * @param boolean $multiple
     *
     * @return boolean|int
     */
    public function post($model, $data, $multiple = false): bool|int
    {
        if (is_string($model)) {
            $model = $this->getSchema($model, true);
        }
        if (!$model) return false;

        $sql = $this->sql;
        if (!$sql) {
            $this->errors[] = 'post:: SQL not defined';
            return false;
        }

        if ($multiple) {
            $prepared = $this->buildPreparedParamsMultiple($model, $data);

            if (empty($prepared['fields']) || empty($prepared['records'])) {
                return false;
            }

            $result = $sql->insertMultiplePrepared(
                $model['table'],
                $prepared['fields'],
                $prepared['records']
            );

            if ($result === false) {
                $this->errors[] = 'post multiple:: ' . $sql->getError();
                return false;
            }

            return $result;
        } else {
            $params = $this->buildPreparedParams($model, $data);

            if (empty($params)) {
                return false;
            }

            $result = $sql->insertPrepared($model['table'], $params);

            if ($result === false) {
                $this->errors[] = 'post:: ' . $sql->getError();
                return false;
            }

            return $result;
        }
    }

    /**
     * Puts (updates) model to mySQL using prepared statements
     * @param string|array $model
     * @param array $data
     * @param array $filters
     * @param boolean $multiple
     * @param array $params
     * @return int|bool Number of affected rows on success (for single updates), true for multiple updates, false on failure
     */
    public function put($model, $data, $filters = null, $multiple = false, $params = []): int|bool
    {
        if (is_string($model)) {
            if (isset($params['page_update'])) {
                $schema = $this->getSchemaWithPageUpdate($model, true);
            } else {
                $schema = $this->getSchema($model, true);
            }
        } else {
            $schema = $model;
        }

        if (!$schema) {
            $this->errors[] = 'put:: Schema not found';
            return false;
        }

        if (isset($schema['filters']) && isset($params['skipSchemaFilters'])) {
            unset($schema['filters']);
        }

        $sql = $this->sql;
        if (!$sql) {
            $this->errors[] = 'put:: SQL not defined';
            return false;
        }

        if ($multiple && $filters) {
            if ($filters) {
                $f[] = str_replace('WHERE ', '', $this->getFilters($schema, $filters));
            }
            $exists = 'SELECT id FROM ' . $schema['table'] . ' WHERE (' . implode(') OR (', $f) . ')';
            $exists = $this->query($exists);
            foreach ($exists as $k => $v) {
                $exists[$k] = $v['id'];
            }

            $stack = [];
            foreach ($data as $v) {
                if ($v['id']) {
                    $stack[] = $v['id'];
                    if (in_array($v['id'], $exists)) {
                        $this->put($schema, $v);
                    }
                } else {
                    $this->errors[] = 'put:: data record has no .id field';
                    return false;
                }
            }

            if ($stack) {
                $filters['id'] = ['operator' => '!=', 'value' => $stack];
            }

            return true;
        }

        if ($multiple) {
            $f = [];
            $fields = [];
            foreach ($data as $v) {
                foreach ($v as $k2 => $_) {
                    if (!in_array($k2, $fields)) $fields[] = $k2;
                }

                $f[] = str_replace('WHERE ', '', $this->getFilters($schema, $v));
            }
            $exists = 'SELECT id,' . implode(',', $fields) . ' FROM ' . $schema['table'] . ' WHERE (' . implode(') OR (', $f) . ')';
            $exists = $this->query($exists);

            $insert = $data;
            $update = [];

            if ($exists) {
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
            }

            if ($insert) $this->post($schema, $insert, true);
            if ($update) {
                foreach ($update as $v) {
                    $id = $v['id'];
                    unset($v['id']);
                    $setParams = $this->buildPreparedParams($schema, $v);
                    $whereParams = [['i', $id, 'id']];
                    $sql->updatePrepared($schema['table'], $setParams, $whereParams);
                }
            }
            return true;
        }

        if ($filters) {
            $wherePrepared = $this->buildPreparedWhere($schema, $filters);
            $whereClause = $wherePrepared['clause'];
            $whereParams = $wherePrepared['params'];
        } else {
            $id = $data['id'] ?? null;
            if (!$id) {
                $this->errors[] = 'put:: ID not found';
                return false;
            }
            $whereClause = 'WHERE `id`=?';
            $whereParams = [['s', $id, 'id']];
        }

        $existsQuery = 'SELECT id FROM `' . $schema['table'] . '` ' . $whereClause;
        $existsParams = [];
        foreach ($whereParams as $p) {
            $existsParams[] = [$p[0], $p[1]];
        }
        $exists = $sql->queryPrepared($existsQuery, $existsParams);

        if (!$exists) {
            return $this->post($schema, $data);
        }

        unset($data['id']);
        $setParams = $this->buildPreparedParams($schema, $data);

        if (empty($setParams)) {
            $this->errors[] = 'put:: No valid fields to update';
            return false;
        }

        $result = $sql->updatePrepared($schema['table'], $setParams, $whereParams, $whereClause);

        if (!$result) {
            $this->errors[] = 'put:: ' . $sql->getError();
            return false;
        }

        return $this->getAffectedRows();
    }

    /**
     * Deletes object(s) using prepared statements
     * @param string|array $model
     * @param array|int|string $filters
     * @param bool $multiple
     * @return int|bool Number of affected rows on success, false on failure
     */
    public function delete($model, $filters, bool $multiple = false): int|bool
    {
        if (!is_array($filters)) {
            $filters = ['id' => $filters];
        }

        if (is_string($model)) {
            $schema = $this->getSchema($model, true);
        } else {
            $schema = $model;
        }

        if (!$schema) {
            $this->errors[] = 'delete:: Schema not found';
            return false;
        }

        $sql = $this->sql;
        if (!$sql) {
            $this->errors[] = 'delete:: SQL not defined';
            return false;
        }

        $wherePrepared = $this->buildPreparedWhere($schema, $filters);

        if (empty($wherePrepared['clause'])) {
            $this->errors[] = 'delete:: No WHERE clause - refusing to delete all records';
            return false;
        }

        $result = $sql->deletePrepared(
            $schema['table'],
            $wherePrepared['params'],
            $wherePrepared['clause']
        );

        if (!$result) {
            $this->errors[] = 'delete:: ' . $sql->getError();
            return false;
        }

        return $this->getAffectedRows();
    }

    /**
     * Gets model from mySQL using prepared statements where applicable
     * @return array
     */
    public function get(string|array $schema, $filters = null, $single = false, $order = null, $limit = null, array $params = [])
    {
        $allowed_params = [
            'schema' => ['name'],
            'additionalParams' => ['additionalParams', []],
            'addLanguages' => ['add_languages', false],
            'count' => ['count', false],
            'groupBy' => ['groupBy', ''],
            'fields' => ['fields_to_read', []],
            'filters' => ['filters', []],
            'first' => ['single', false],
            'limit' => ['limit', null],
            'key' => ['returnByKey', null],
            'order' => ['order', null],
            'returnQuery' => ['return_query', false],
            'replace_values' => ['replace_values', []],
            'skipSchemaFilters' => ['skipSchemaFilters', false]
        ];

        $keep_existing_vars = true;
        $predefined_schema = null;

        if (is_array($schema) && !empty($schema['model_name'])) {
            $predefined_schema = $schema;
            $name = $schema['model_name'];
        } elseif (is_array($schema)) {
            $params = $schema;
            $keep_existing_vars = false;
        }

        foreach ($allowed_params as $source => $dest) {
            if (isset($params[$source])) {
                ${$dest[0]} = $params[$source];
            } elseif (isset($dest[1]) && (!$keep_existing_vars || !isset(${$dest[0]}))) {
                ${$dest[0]} = $dest[1];
            }
        }

        if (is_string($schema)) $name = $schema;

        if ($count === true) {
            $count = ['type' => 'quick'];
        }

        if (empty($name)) {
            $this->halt('get::no-schema-name');
        }

        $this->sqlCheckConnection('get::' . $name);

        if ($single && !$fields_to_read) $fields_to_read = '_single';
        elseif (!$fields_to_read) $fields_to_read = '_multiple';

        if (is_array($limit) && count($limit) == 2) {
            $limit_page = intval($limit[0]);
            $limit_perpage = intval($limit[1]);

            if ($limit_page > 0 && $limit_perpage > 0) {
                $limit = ($limit_page - 1) * $limit_perpage . ',' . $limit_perpage;
            } else {
                $limit = '';
            }
        } elseif (is_array($limit)) {
            $limit = '';
        }

        if (!empty($predefined_schema)) {
            $model = $predefined_schema;
        } elseif (isset($params['page_update'])) {
            $model = $this->getSchemaWithPageUpdate($name);
        } else {
            $model = $this->getSchema($name, false, ['return_error' => true]);
            if (isset($model['result']) && !$model['result']) {
                $this->halt($model['message']);
            }
        }

        if (empty($model['table'])) {
            $this->halt('_uho_orm::get->.table not found in schema [' . $name . ']');
        }
        if (empty($model['fields'])) {
            $this->halt('_uho_orm::get->.fields not found in schema [' . $name . ']');
        }

        if ($fields_to_read && !is_array($fields_to_read)) {
            $fields_to_read = !empty($model['fields_to_read'][$fields_to_read]) ? $model['fields_to_read'][$fields_to_read] : null;
        }

        if ($skipSchemaFilters) $model['filters'] = [];

        if (!empty($model['filters']) && !empty($additionalParams)) {
            foreach ($model['filters'] as $k => $v) {
                foreach ($additionalParams as $k2 => $v2) {
                    if (is_string($v2)) {
                        $model['filters'][$k] = str_replace('%' . $k2 . '%', $v2, $v);
                    }
                }
            }
        }

        $combinedFilters = [];
        if (!empty($model['filters'])) {
            $combinedFilters = $model['filters'];
        }
        if (!empty($filters)) {
            if (is_array($filters)) {
                $combinedFilters = array_merge($combinedFilters, $filters);
            }
        }

        $wherePrepared = $this->buildPreparedWhere($model, $combinedFilters);

        if (!$order && !empty($model['order'])) $order = $model['order'];

        $fields = ['id'];
        $fields_models = [];
        $fields_auto = [];

        foreach ($model['fields'] as $v) {
            $field = isset($v['field']) ? $v['field'] : null;
            $field_type = isset($v['type']) ? $v['type'] : null;

            if ($field && in_array($field, $fields));
            elseif ($field && $fields_to_read && !in_array($field, $fields_to_read));
            elseif (in_array($v['type'], ['file', 'image', 'video', 'audio', 'media', 'virtual', 'plugin'])) {
                array_push($fields_auto, $v);
            } elseif ($field_type == 'model') {
                array_push($fields_models, $v);
            } elseif (isset($v['outside']['model'])) {
                array_push($fields_models, ['model' => $v['outside']]);
            } elseif ($field) {
                if (!empty($v['settings']['field_output'])) {
                    array_push($fields, $field . '` AS `' . $v['settings']['field_output']);
                } else {
                    array_push($fields, $field);
                }
                if ($v['type'] == 'image_media') array_push($fields_auto, $v);
            }

            if (($add_languages || isset($v['add_languages'])) && strpos($v['field'], ':lang')) {
                $vv = explode(':lang', $v['field'])[0];
                foreach ($this->getLanguages() as $v2) {
                    $fields[] = $vv . $v2['lang_add'];
                }
            }
        }

        if ($single) $query_limit = '0,1';
        elseif (!$limit) $query_limit = '';
        else $query_limit = $limit;

        if (!empty($order)) {
            if (is_array($order) && !empty($order['type']) && !empty($order['values']) && $order['type'] == 'field') {
                $order = 'FIELD (' . implode(',', $order['values']) . ')';
            } elseif (is_array($order) && !empty($order['field']) && !empty($order['sort'])) {
                $order = $order['field'] . ' ' . $order['sort'];
            } elseif (!is_string($order)) {
                $order = '';
            }
        }

        $query_order = $order ? $order : '';

        if (!empty($count['type'])) {
            switch ($count['type']) {
                case "quick":
                    $fields = ['COUNT(*)'];
                    break;
                case "average":
                    if ($count['function']) {
                        $fields = ['AVG(' . $count['function'] . '(' . $count['field'] . ')) AS average'];
                    } else {
                        $fields = ['AVG(' . $count['field'] . ') AS average'];
                    }
                    break;
            }
        }

//        foreach ($fields as $k => $v)
  //          if (!strpos($v, ':lang') && substr($v, 0, 6) != 'COUNT(') $fields[$k] = '`' . $v . '`';

        $fieldList = [];
        foreach ($fields as $f)
        {
            if (empty($f)) continue; // skip empty field names to avoid double commas

            if (strpos($f, ':lang')) {
                $parts = explode(':lang', $f);
                $fieldList[] = '`' . $parts[0] .$this->lang_add. '` AS `' . $parts[0] . '`';
            } elseif (strpos($f, 'COUNT(') === 0 || strpos($f, 'AVG(') === 0) {
                $fieldList[] = $f;
            } elseif (strpos($f, ' AS ') !== false) {
                $fieldList[] = '`' . $f . '`';
            } else {
                $fieldList[] = '`' . $f . '`';
            }
        }

        $sql = $this->sql;
        if (!$sql) {
            $this->halt('get:: SQL not defined');
        }

        $query = 'SELECT ' . implode(',', $fieldList) . ' FROM `' . $model['table'] . '`';

        if ($wherePrepared['clause']) {
            $query .= ' ' . $wherePrepared['clause'];
        }

        if ($groupBy) {
            $query .= ' GROUP BY ' . $groupBy;
        }

        if ($query_order) {
            $query .= ' ORDER BY ' . $query_order;
        }

        if ($query_limit) {
            $query .= ' LIMIT ' . $query_limit;
        }

        if ($return_query) return $query;

        $preparedParams = [];
        foreach ($wherePrepared['params'] as $p) {
            $preparedParams[] = [$p[0], $p[1]];
        }

        $data = $sql->queryPrepared($query, $preparedParams);

        if (!$data) {
            $data = [];
        }

        if (!empty($count['type'])) {
            switch ($count['type']) {
                case "quick":
                    return $data[0]['COUNT(*)'] ?? 0;
                case "average":
                    return $data[0]['average'] ?? 0;
            }
        }

        if ($replace_values) {
            foreach ($data as $k => $v) {
                foreach ($replace_values as $k2 => $v2) {
                    if (isset($v[$k2])) {
                        $data[$k][$k2] = $v2;
                    }
                }
            }
        }

        if (isset($order['type']) && $order['type'] == 'FIELD') {
            $data_new_order = [];
            foreach ($order['values'] as $v) {
                $d0 = _uho_fx::array_filter($data, $order['field'], $v, ['first' => true]);
                if ($d0) array_push($data_new_order, $d0);
            }
            $data = $data_new_order;
        }

        $data = $this->getUpdateRecords($model, $data);
        $data = $this->getUpdateRecordsMedia($model, $data, $fields_auto);

        foreach ($data as $k => $v) {
            foreach ($fields_models as $v2) {
                if (($v2['type'] ?? '') != 'model') {
                    $v2['model'] = _uho_fx::arrayReplace($v2['model'], $v, '%', '%');
                    $modelOrder = $v2['model']['order'] ?? null;

                    if (isset($v2['field'])) {
                        $data[$k][$v2['field']] = $this->get($v2['model']['model'], $v2['model']['filters'], false, $modelOrder);
                    }
                }
            }
        }

        if ($fields_to_read && !empty($model['fields_to_read'])) {
            foreach ($data as $k => $record) {
                foreach ($record as $field => $val) {
                    if (!in_array($field, $fields_to_read)) {
                        unset($data[$k][$field]);
                    }
                }
            }
        }

        if ($data && isset($model['url'])) {
            $data = $this->getUpdateUrls($model['url'], $data, $additionalParams);
        }

        if ($single === true && isset($data[0])) $data = $data[0];

        if (!empty($returnByKey)) {
            $d = [];
            foreach ($data as $v) {
                $d[$v[$returnByKey]] = $v;
            }
            $data = $d;
        }

        if ($count) return count($data);
        return $data;
    }

}
