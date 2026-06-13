<?php

namespace Huncwot\UhoFramework;

/**
 * PostgreSQL ORM subclass using PDO prepared statements.
 *
 * Key differences from the MySQL driver:
 *  - Identifiers quoted with double-quotes instead of backticks
 *  - Logical AND written as AND, not &&
 *  - No "INSERT … SET" syntax; uses standard (fields) VALUES (…)
 *  - Prepared placeholders are $1/$2/… instead of ?
 *  - Upsert uses ON CONFLICT (id) DO UPDATE instead of ON DUPLICATE KEY UPDATE
 *  - INSERT returns id via RETURNING id
 *
 * SELECT queries are still executed as plain SQL strings (with values
 * already escaped via sqlSafe → PDO::quote). Only write ops are fully
 * prepared.
 */
class _uho_orm_pgsql_prepared extends _uho_orm
{
    function __construct(_uho_pgsql|null $sql, string|null $lang, array $keys, bool $test = false)
    {
        parent::__construct($sql, $lang, $keys, $test);
    }

    // -------------------------------------------------------------------------
    // SQL dialect overrides
    // -------------------------------------------------------------------------

    /**
     * Escape a value for use in a plain-string query.
     * Uses PDO::quote() and strips the surrounding single-quotes.
     */
    public function sqlSafe($s)
    {
        if (!$this->sql && $this->test) return $s;
        if (!$this->sql) $this->halt('sqlSafe::sql-not-defined');
        if ($s && !is_array($s)) return $this->sql->safe($s);
    }

    /**
     * Quote field names with double-quotes (PostgreSQL style).
     */
    protected function sqlSanitizeLangFields($fields): array
    {
        foreach ($fields as $k => $v)
            if (!strpos($v, ':lang') && substr($v, 0, 6) != 'COUNT(')
                $fields[$k] = '"' . $v . '"';
        return $fields;
    }

    /**
     * Converts a query built for MySQL into PostgreSQL-compatible SQL:
     *  - backtick identifiers  →  double-quote identifiers
     *  - logical && operator   →  AND
     */
    private function pgify(string $query): string
    {
        $query = str_replace('`', '"', $query);
        $query = preg_replace('/ && /', ' AND ', $query);
        return $query;
    }

    // -------------------------------------------------------------------------
    // Query execution overrides
    // -------------------------------------------------------------------------

    public function query($query, $single = false, $stripslashes = true, $key = null, $do_field_only = null, $force_sql_cache = false)
    {
        if (!$this->sql) return;

        $query  = $this->processLangQuery($query);
        $query  = $this->pgify($query);

        $result = $this->sql->query($query, $single, $stripslashes, $key, $force_sql_cache);

        if ($result && is_array($result) && $do_field_only) {
            foreach ($result as $k => $v) if (is_array($v)) $result[$k] = $v[$do_field_only];
        }
        return $result;
    }

    public function queryOut($query): bool|null
    {
        $result = $this->sql->queryOut($this->pgify($query));
        if (!$result) $this->errors[] = $query;
        return $result;
    }

    public function multiQueryOut($query): bool|null
    {
        return $this->sql->multiQueryOut($this->pgify($query));
    }

    // -------------------------------------------------------------------------
    // buildOutputQuery — PostgreSQL syntax (double-quote identifiers)
    // Used for external callers and for building WHERE strings.
    // -------------------------------------------------------------------------

    public function buildOutputQuery($model, $data, string $join = ','): array|string
    {
        $skip_fields = ['image', 'video', 'file', 'audio', 'virtual', 'media'];

        foreach ($data as $k => $v) {
            $skip_safe = false;
            $field = _uho_fx::array_filter($model['fields'], 'field', $k, ['first' => true]);

            if ($k == 'id') {
                $data[$k] = $k . '="' . ($v) . '"';
            } elseif ($field && in_array($field['type'], $skip_fields)) {
                unset($data[$k]);
            } elseif ($field) {

                switch (@$field['type']) {
                    case 'checkboxes':
                    case 'elements':
                        $iDigits = 8;
                        if (!empty($field['settings']['output'])) {
                            if ($field['settings']['output'] == '4digits') $iDigits = 4;
                            if ($field['settings']['output'] == '6digits') $iDigits = 6;
                            if ($field['settings']['output'] == '8digits') $iDigits = 8;
                            if ($field['settings']['output'] == 'string')  $iDigits = 0;
                        }
                        if (is_array($v)) {
                            foreach ($v as $k2 => $v2)
                                if ($iDigits) $v[$k2] = _uho_fx::dozeruj($v2, $iDigits);
                            $v = implode(',', $v);
                        }
                        break;
                    case 'boolean':
                        $v = ($v === true || $v === 'on' || $v === 1 || $v === '1') ? 1 : 0;
                        break;
                    case 'timestamp':
                        $v = (new \DateTimeImmutable($v, new \DateTimeZone(date_default_timezone_get())))
                            ->setTimezone(new \DateTimeZone('UTC'))
                            ->format('Y-m-d H:i:s');
                        break;
                    case 'float':
                        $v = floatval($v);
                        $skip_safe = true;
                        break;
                    case 'integer':
                        $v = intval($v);
                        $skip_safe = true;
                        break;
                    case 'json':
                    case 'blocks':
                        if (is_array($v)) $v = json_encode($v, true);
                        break;
                    case 'select':
                        if (is_numeric($v)) $skip_safe = true;
                        break;
                    case 'table':
                        if (isset($field['settings']['format']) && $field['settings']['format'] == 'object') {
                            $v0 = $v; $v = [];
                            foreach ($v0 as $v2) $v[$v2[0]] = $v2[1];
                        }
                        $v = json_encode($v);
                        break;
                    case 'order':
                        $v = intval($v);
                        break;
                }

                if (isset($v['type']) && $v['type'] == 'sql') {
                    $data[$k] = '"' . $k . '"=' . $v['value'];
                } elseif (isset($field['settings']['hash'])) {
                    $hash = $field['settings']['hash'];
                    if ($hash[0] == '~')
                        $data[$k] = '"' . $k . '"=\'' . _uho_fx::encrypt($v, $this->getKeys(), substr($hash, 1), true) . '\'';
                    else
                        $data[$k] = '"' . $k . '"=\'' . _uho_fx::encrypt($v, $this->getKeys(), $hash) . '\'';
                } elseif ($v === 0) {
                    $data[$k] = '"' . $k . '"=0';
                } elseif ($v === null) {
                    $data[$k] = '"' . $k . '"=NULL';
                } elseif (isset($field['type']) && $skip_safe) {
                    $data[$k] = '"' . $k . '"=\'' . $v . '\'';
                } else {
                    $data[$k] = '"' . $k . '"=\'' . $this->sqlSafe($v) . '\'';
                }

            } elseif (isset($model['filters'][$k])) {
                $data[$k] = '"' . $k . '"=\'' . $this->sqlSafe($v) . '\'';
            } else {
                unset($data[$k]);
            }
        }

        if ($data) $data = implode($join, $data);
        return $data;
    }

    // -------------------------------------------------------------------------
    // Write operations — fully prepared
    // -------------------------------------------------------------------------

    public function post($model, $data, $multiple = false): bool|int
    {
        $schema = $this->getSchema($model, true);
        if (!$schema) return false;

        if ($multiple) {
            return $this->postMultiplePg($schema, $data);
        }

        $prepared = $this->buildPreparedData($schema, $data);
        if (empty($prepared['fields'])) return false;

        $params = [];
        foreach ($prepared['fields'] as $i => $field) {
            $params[] = ['s', $prepared['values'][$i], $field];
        }

        $result = $this->sql->insertPrepared($schema['table'], $params);
        return $result !== false ? (int) $result : false;
    }

    private function postMultiplePg(array $schema, array $data): bool|int
    {
        $lastId = false;
        foreach ($data as $record) {
            $r = $this->post($schema, $record, false);
            if ($r !== false) $lastId = $r;
        }
        return $lastId;
    }

    public function put($model, $data, $filters = null, $multiple = false, $params = []): int|bool
    {
        if ($multiple) {
            return $this->putMultiplePg($model, $data, $filters, $params);
        }

        if (isset($params['schema_update']))
            $schema = $this->getSchemaWithPageUpdate($model, true);
        else
            $schema = $this->getSchema($model, true);

        if (!$schema) return false;

        if (isset($schema['filters']) && isset($params['skipSchemaFilters']))
            unset($schema['filters']);

        if ($filters) {
            $where = $this->getFilters($schema, $filters);
            $where = $this->pgify($where);
        } else {
            $id = @$data['id'];
            if (!$id) {
                $this->errors[] = 'put:: ID not found:: ' . $model;
                return false;
            }
            $where = 'WHERE id=\'' . $this->sqlSafe($id) . '\'';
        }

        $existsQuery = 'SELECT id FROM "' . $schema['table'] . '" ' . $where;
        $exists = $this->sql->query($existsQuery);

        if (!$exists) {
            return $this->post($model, $data);
        }

        unset($data['id']);
        $prepared = $this->buildPreparedData($schema, $data);

        if (empty($prepared['fields'])) {
            $this->errors[] = 'pgsql error:: buildPreparedData empty for table: ' . $schema['table'];
            return false;
        }

        $setParams = [];
        foreach ($prepared['fields'] as $i => $field) {
            $setParams[] = ['s', $prepared['values'][$i], $field];
        }

        if (!empty($filters)) {
            $r = $this->sql->updatePrepared($schema['table'], $setParams, [], $where);
        } else {
            $whereParams = [['i', intval($id), 'id']];
            $r = $this->sql->updatePrepared($schema['table'], $setParams, $whereParams);
        }

        if (!$r) return false;
        return $this->getAffectedRows();
    }

    /**
     * Multiple-record upsert for PostgreSQL.
     * Uses ON CONFLICT (id) DO UPDATE for existing rows.
     */
    private function putMultiplePg($model, array $data, $filters, array $params): int|bool
    {
        $schema = $this->getSchema($model, true);
        if (!$schema) return false;

        // Determine existing ids from the table
        $f = [];
        if ($filters) {
            $f[] = str_replace('WHERE ', '', $this->getFilters($schema, $filters));
            $f[0] = $this->pgify($f[0]);
        }

        $existsQuery = 'SELECT id FROM "' . $schema['table'] . '"'
            . ($f ? ' WHERE (' . implode(') OR (', $f) . ')' : '');
        $exists = $this->sql->query($existsQuery);
        $existIds = array_column($exists ?: [], 'id');

        $insert = [];
        $update = [];

        foreach ($data as $v) {
            if (!empty($v['id']) && in_array($v['id'], $existIds)) $update[] = $v;
            else $insert[] = $v;
        }

        $result = true;
        if ($insert) $result = $this->post($model, $insert, true);

        foreach ($update as $v) {
            $r = $this->put($model, $v, null, false, $params);
            if ($r === false) $result = false;
        }

        return $result;
    }

    public function delete($model, $filters, bool $multiple = false): int|bool
    {
        if (!is_array($filters)) $filters = ['id' => $filters];

        $schema = $this->getSchema($model, true);
        if (!$schema || empty($schema['table'])) {
            $this->errors[] = 'delete:: schema error:: ' . $model;
            return false;
        }

        if ($multiple) {
            $where = $this->pgify($this->getFilters($model, $filters));
            $r = $this->sql->deletePrepared($schema['table'], [], $where);
        } elseif (isset($filters['id']) && !is_array($filters['id'])) {
            $r = $this->sql->deletePrepared($schema['table'], [['i', intval($filters['id']), 'id']]);
        } else {
            $where = $this->pgify($this->buildOutputQuery($schema, $filters, ' AND '));
            $r = $this->sql->deletePrepared($schema['table'], [], 'WHERE ' . $where);
        }

        if (!$r) {
            $this->errors[] = 'delete:: pgsql prepared failed';
            return false;
        }

        return $this->getAffectedRows();
    }

    public function truncate(string $model)
    {
        $model = $this->getSchema($model, true);
        if (!$model || empty($model['table'])) return false;
        $this->sql->queryOut('TRUNCATE TABLE "' . $model['table'] . '"');
        return true;
    }
}
