<?php

namespace Huncwot\UhoFramework;

/**
 * MySQL ORM subclass using prepared statements for all write operations
 * (INSERT / UPDATE / DELETE). SELECT queries remain string-based because
 * the WHERE clauses are already escaped via sqlSafe() / real_escape_string().
 *
 * Falls back to the parent string-based implementation for bulk (multiple)
 * operations that rely on ON DUPLICATE KEY UPDATE batching.
 */
class _uho_orm_mysql_prepared extends _uho_orm_mysql
{
    /**
     * Inserts a single record using a prepared statement.
     * Falls back to parent for bulk inserts.
     */
    public function post($model, $data, $multiple = false): bool|int
    {
        if ($multiple) {
            return parent::post($model, $data, true);
        }

        $schema = $this->getSchema($model, true);
        if (!$schema) return false;

        $prepared = $this->buildPreparedData($schema, $data);
        if (empty($prepared['fields'])) return false;

        $params = [];
        foreach ($prepared['fields'] as $i => $field) {
            $params[] = [$prepared['types'][$i], $prepared['values'][$i], $field];
        }

        $result = $this->sql->insertPrepared($schema['table'], $params);
        return $result !== false ? (int) $result : false;
    }

    /**
     * Updates a single record using a prepared statement.
     * Falls back to parent for bulk (multiple) operations.
     */
    public function put($model, $data, $filters = null, $multiple = false, $params = []): int|bool
    {
        if ($multiple) {
            return parent::put($model, $data, $filters, $multiple, $params);
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
        } else {
            $id = @$data['id'];
            if (!$id) {
                $this->errors[] = 'put:: ID not found:: ' . $model;
                return false;
            }
            $where = 'WHERE id="' . $this->sqlSafe($id) . '"';
        }

        $existsQuery = 'SELECT id FROM ' . $schema['table'] . ' ' . $where;
        $exists = $this->query($existsQuery);

        if (!$exists) {
            return $this->post($model, $data);
        }

        unset($data['id']);
        $prepared = $this->buildPreparedData($schema, $data);

        if (empty($prepared['fields'])) {
            $this->errors[] = 'mysql error:: buildPreparedData empty for table: ' . $schema['table'];
            return false;
        }

        $setParams = [];
        foreach ($prepared['fields'] as $i => $field) {
            $setParams[] = [$prepared['types'][$i], $prepared['values'][$i], $field];
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
     * Deletes record(s) using a prepared statement.
     */
    public function delete($model, $filters, bool $multiple = false): int|bool
    {
        if (!is_array($filters)) $filters = ['id' => $filters];

        $schema = $this->getSchema($model, true);
        if (!$schema || empty($schema['table'])) {
            $this->errors[] = 'delete:: schema error:: ' . $model;
            return false;
        }

        if ($multiple) {
            $where = $this->getFilters($model, $filters);
            $r = $this->sql->deletePrepared($schema['table'], [], $where);
        } elseif (isset($filters['id']) && !is_array($filters['id'])) {
            $r = $this->sql->deletePrepared($schema['table'], [['i', intval($filters['id']), 'id']]);
        } else {
            // Complex filter — build an escaped WHERE and pass as a clause string
            $where = $this->buildOutputQuery($schema, $filters, ' && ');
            $r = $this->sql->deletePrepared($schema['table'], [], 'WHERE ' . $where);
        }

        if (!$r) {
            $this->errors[] = 'delete:: prepared failed';
            return false;
        }

        return $this->getAffectedRows();
    }
}
