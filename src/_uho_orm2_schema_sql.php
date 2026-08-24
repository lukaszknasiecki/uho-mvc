<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * The bridge between a schema and the SQL table behind it.
 *
 * Two jobs meet here because both translate schema types into SQL: creating
 * or migrating the table (creator()), and turning a filter array into the
 * WHERE clause of a query (getFiltersQueryArray()).
 *
 * Everything in the filter path treats its input as hostile: values are
 * escaped by the driver, column names are matched against the schema or a
 * strict charset, and a filter that cannot be resolved is dropped rather than
 * passed through. The one deliberately raw path,
 * getFiltersRawQueryArray(), is documented as never accepting request data.
 */
class _uho_orm2_schema_sql
{
    /** what updateTable() may do without asking */
    private const CREATE_ACTIONS = ['alert', 'auto', 'info'];

    /** separators getFiltersRawQueryArray() may join a custom value list with */
    private const RAW_JOINS = ['&&', '||', 'AND', 'OR'];

    /** operators a filter may name; anything else falls back to '=' */
    private const OPERATORS = ['in', '!=', '>', '<', '>=', '<=', 'LIKE', '%LIKE%', '%!LIKE%'];

    /** column types that differ only in the width MySQL prints back */
    private const EQUIVALENT_TYPES = [
        'int'        => ['int(11)', 'int(4)'],
        'tinyint'    => ['tinyint(4)'],
        'int(11)'    => ['int'],
        'int(4)'     => ['int'],
        'tinyint(4)' => ['tinyint'],
    ];

    /** @var array<int, string> */
    private array $allowedCreateActions = self::CREATE_ACTIONS;

    public function __construct(
        private _uho_orm2 $orm,
        private _uho_orm2_context $context,
        private _uho_orm2_query_runner $runner,
        private _uho_orm2_sql_guard $guard,
        ?array $allowedCreateActions = null
    ) {
        if ($allowedCreateActions !== null) $this->allowedCreateActions = $allowedCreateActions;
    }

    /**
     * Narrows what creator() is allowed to do. 'info' alone makes every
     * migration report-only.
     *
     * @param array<int, string> $actions subset of alert|auto|info
     */
    public function setAllowedCreateActions(array $actions): void
    {
        $this->allowedCreateActions = array_values(array_intersect($actions, self::CREATE_ACTIONS));
    }

    // -------------------------------------------------------------------------
    // Schema -> SQL types
    // -------------------------------------------------------------------------

    /**
     * Converts the schema field list into column definitions.
     *
     * @return array{fields: array<int, string>, fields_sql: array<int, array>, id: string}
     */
    private function getSchemaSQL(array $schema): array
    {
        $fields    = [];
        $fieldsSql = [];
        $id        = null;

        foreach ($schema['fields'] as $field) {
            $unique = ($field['type'] ?? null) === 'uid';
            $type   = $this->columnType($field);

            if (empty($field['field']) || $type === null) continue;

            $notNull = $unique;
            $default = null;

            if (in_array($field['type'], ['integer', 'boolean'], true)) {
                $default = "'0'";
                $notNull = true;
            }

            if (($field['settings']['default'] ?? null) === '{{now}}') $default = 'current_timestamp()';

            $definition = '`' . $field['field'] . '` ' . $type;
            if ($notNull) $definition .= ' NOT NULL';
            if ($default) $definition .= ' DEFAULT ' . $default;

            if ($field['field'] === 'id') $id = $type;

            $fields[] = $definition;

            $column = ['Field' => $field['field'], 'Type' => $type, 'Null' => !$notNull, 'Default' => $default];

            if ($unique) $column['Unique'] = true;
            foreach (['generated' => 'Generated', 'stored' => 'Stored', 'unique' => 'Unique', 'trigger' => 'Trigger', 'index' => 'Index'] as $key => $name)
                if (!empty($field['settings']['sql'][$key])) $column[$name] = $field['settings']['sql'][$key];

            $fieldsSql[] = $column;
        }

        if ($id === null) {
            $id = 'int(11)';
            array_unshift($fields, '`id` int(11)');
        }

        return ['fields' => $fields, 'fields_sql' => $fieldsSql, 'id' => $id];
    }

    /**
     * The SQL type one schema field maps to, or null when it has no column.
     */
    private function columnType(array $field): ?string
    {
        switch ($field['type'] ?? '') {

            case 'date':      return 'date';
            case 'datetime':  return 'datetime';
            case 'timestamp': return 'timestamp';
            case 'blocks':    return 'longtext';
            case 'uid':       return 'varchar(13)';

            case 'integer':
            case 'order':     return 'int(11)';
            case 'boolean':   return 'tinyint(4)';

            case 'checkboxes':
            case 'elements':
                return 'varchar(' . ($field['settings']['length'] ?? 512) . ')';

            case 'json':
            case 'text':
            case 'html':
            case 'table':
                return empty($field['settings']['long']) ? 'text' : 'longtext';

            case 'select':
                return $this->selectColumnType($field);

            case 'string':
                $length = $field['settings']['length'] ?? 255;
                return empty($field['settings']['static_length'])
                    ? 'varchar(' . $length . ')'
                    : 'char(' . $length . ')';

            // media lives on disk
            case 'file':
            case 'image':
            case 'media':
            case 'video':
            default:
                return null;
        }
    }

    /**
     * A select stores an int id by default, a varchar when the source model
     * keys on strings, and an enum when the options are static.
     */
    private function selectColumnType(array $field): string
    {
        if (!empty($field['settings']['length'])) return 'varchar(' . $field['settings']['length'] . ')';

        if (!empty($field['source']['model'])) {
            $source = $this->orm->getSchema($field['source']['model']);

            if ($source) {
                $sourceId = _uho_fx::array_filter($source['fields'], 'field', 'id', ['first' => true]);

                if ($sourceId && ($sourceId['type'] ?? null) === 'string')
                    return 'varchar(' . ($sourceId['settings']['length'] ?? 255) . ')';
            }
        }

        if (!empty($field['options']))
            return "enum('" . implode("','", _uho_fx::array_extract($field['options'], 'value')) . "')";

        return 'int(11)';
    }

    // -------------------------------------------------------------------------
    // Table creation / migration
    // -------------------------------------------------------------------------

    /**
     * @param string|array|null $sql extra statements to run after CREATE TABLE
     * @return array{action: ?string}
     */
    private function createTable(array $schema, string|array|null $sql): array
    {
        $sqlSchema = $this->getSchemaSQL($schema);

        $queries = [
            'CREATE TABLE `' . $schema['table'] . '` (' . implode(',', $sqlSchema['fields']) . ') '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;',
            'ALTER TABLE `' . $schema['table'] . '` ADD PRIMARY KEY (`id`);',
        ];

        if ($sqlSchema['id'] === 'int(11)')
            $queries[] = 'ALTER TABLE `' . $schema['table'] . '` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;';

        if (!empty($sql)) $queries = array_merge($queries, (array) $sql);

        foreach ($queries as $query)
            if (!$this->runner->queryOut($query))
                $this->context->halt('SQL ERROR: <pre>' . $this->orm->getLastError() . '</pre>');

        return ['action' => 'table_create'];
    }

    /**
     * Compares the live columns with the schema and applies the difference.
     *
     * @param string $action alert (ask), auto (apply), info (report only)
     * @return array{action: ?string, message?: string}
     */
    private function updateTable(array $schema, string $action): array
    {
        $sqlSchema = $this->getSchemaSQL($schema);
        $columns   = $this->runner->query('SHOW COLUMNS FROM `' . $schema['table'] . '`') ?? [];

        $update = [];
        $add    = [];

        foreach ($sqlSchema['fields_sql'] as $column) {
            $live = _uho_fx::array_filter($columns, 'Field', $column['Field'], ['first' => true]);

            if (!$live) {
                $add[] = $column;
                continue;
            }

            if (($live['Type'] ?? null) === $column['Type']) continue;
            if (in_array($column['Type'], self::EQUIVALENT_TYPES[$live['Type']] ?? [], true)) continue;

            $column['OldType'] = $live['Type'];
            $update[] = $column;
        }

        // the action comes from the caller only - a request must never be able
        // to turn a report-only check into an executed migration
        if (!in_array($action, $this->allowedCreateActions, true)) $action = 'alert';

        if (!$update && !$add) return ['action' => null];

        if ($action === 'alert') exit($this->migrationPrompt($schema['table'], $add, $update));
        if ($action === 'info')  return ['action' => null, 'message' => $this->migrationSummary($schema['table'], $add, $update)];

        return ['action' => $this->applyMigration($schema['table'], $add, $update)];
    }

    private function migrationPrompt(string $table, array $add, array $update): string
    {
        $e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $html = '<h3>Schema for [<code>' . $e($table) . '</code>] needs to be updated.</h3><ul>';

        foreach ($add as $column)    $html .= '<li>New field: ' . $e($column['Field']) . ' (' . $e($column['Type']) . ')</li>';
        foreach ($update as $column) $html .= '<li>Field to be updated: ' . $e($column['Field'])
            . ' (' . $e($column['OldType']) . ' -> ' . $e($column['Type']) . ')</li>';

        return $html . '</ul><form action="" method="POST">'
            . '<input type="hidden" name="uho_orm_action" value="auto">'
            . '<input type="submit" value="Proceed"></form>';
    }

    private function migrationSummary(string $table, array $add, array $update): string
    {
        $message = 'Schema for [' . $table . '] needs to be updated.';

        foreach ($add as $column)    $message .= '- New field: ' . $column['Field'] . ' (' . $column['Type'] . ')';
        foreach ($update as $column) $message .= '- Field to be updated: ' . $column['Field']
            . ' (' . $column['OldType'] . ' -> ' . $column['Type'] . ')';

        return $message;
    }

    /**
     * New columns are added as NULL first, filled, and only then constrained,
     * so an existing table with rows can take a NOT NULL column.
     */
    private function applyMigration(string $table, array $add, array $update): ?string
    {
        $action = null;

        foreach ($update as $column) {
            $action = 'table_update';

            $query = 'ALTER TABLE `' . $table . '` CHANGE `' . $column['Field'] . '` `' . $column['Field'] . '` '
                . $column['Type'] . ($column['Null'] ? ' NULL' : ' NOT NULL');

            if ($column['Default']) $query .= ' DEFAULT ' . $column['Default'];

            if (!$this->runner->queryOut($query)) $this->context->halt('SQL error: ' . $query);
        }

        foreach ($add as $k => $column) {
            $action = 'table_create';

            $tail = '`' . $column['Field'] . '` ' . $column['Type'];
            if (!empty($column['Generated'])) $tail .= ' GENERATED ' . $column['Generated'];
            $tail .= '{{NULL}}';
            if (!empty($column['Stored'])) $tail .= ' STORED';
            if (!empty($column['Unique'])) $tail .= ' UNIQUE';
            if ($column['Default']) $tail .= ' DEFAULT ' . $column['Default'];

            $add[$k]['alter_query'] = 'ALTER TABLE `' . $table . '` MODIFY ' . str_replace(
                '{{NULL}}',
                $column['Null'] ? ' NULL' : ' NOT NULL',
                $tail
            );

            $query = 'ALTER TABLE `' . $table . '` ADD ' . str_replace('{{NULL}}', ' NULL', $tail);
            if (!$this->runner->queryOut($query)) $this->context->halt('SQL error: ' . $query);

            if (!empty($column['Trigger'])) {
                $query = 'CREATE TRIGGER ' . $table . '_' . $column['Field'] . '_trigger ' . $column['Trigger'];
                if (!$this->runner->queryOut($query)) $this->context->halt('SQL error: ' . $query);
            }
        }

        foreach ($add as $column) {
            if ($column['Null']) continue;

            // a uid column has to hold a value before it can be NOT NULL UNIQUE
            if ($column['Type'] === 'varchar(13)') {
                $query = 'UPDATE `' . $table . '` SET `' . $column['Field'] . '` = '
                    . 'SUBSTRING(MD5(CONCAT(UUID(), RAND())), 1, 13) WHERE `' . $column['Field'] . '` IS NULL';

                if (!$this->runner->queryOut($query)) $this->context->halt('SQL error: ' . $query);
            }

            $this->runner->queryOut($column['alter_query']);
        }

        return $action;
    }

    /**
     * Checks whether the table behind a schema exists and matches it, creating
     * or updating it when the options allow.
     *
     * @param array $options ['create' => 'auto'|'alert', 'create_sql' => ..., 'update' => 'auto'|'alert'|'info']
     * @return array{actions: array<int, string>, messages: array<int, string>, additional: array}
     */
    public function creator(array $schema, ?array $options, bool $recursive = false, bool $updateLanguages = true): array
    {
        $messages = [];
        $actions  = [];

        if ($updateLanguages) $schema = $this->orm->schemaManager->updateSchemaLanguages($schema);

        $exists = $this->runner->query("SHOW TABLES LIKE '" . $schema['table'] . "'", true);

        if (!$exists) {
            if (!empty($options['create']) && in_array($options['create'], ['auto', 'alert'], true)) {
                $response   = $this->createTable($schema, $options['create_sql'] ?? null);
                $messages[] = 'Table has been created';
                if ($response['action']) $actions[] = $response['action'];
            } else {
                $actions[]  = 'table_create';
                $messages[] = 'Table [' . $schema['table'] . '] needs to be created';
            }
        } elseif (!empty($options['update'])) {
            $response = $this->updateTable($schema, $options['update']);

            if (!empty($response['message'])) $messages[] = $response['message'];
            elseif ($response['action'] === 'table_update') $messages[] = 'Table has been updated';

            if ($response['action']) $actions[] = $response['action'];
        } else {
            $actions[] = 'table_update';
        }

        $additional = [];

        if ($recursive) {
            $names = [];

            foreach ($schema['fields'] as $field)
                if (($field['type'] ?? null) === 'media' && !in_array($field['source']['model'], $names, true))
                    $names[] = $field['source']['model'];

            foreach ($names as $name) {
                $mediaSchema = $this->orm->getSchema($name);
                if ($mediaSchema) $additional[] = $this->creator($mediaSchema, $options);
            }
        }

        return ['actions' => $actions, 'messages' => $messages, 'additional' => $additional];
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    /**
     * Converts the schema's filter array into WHERE clauses.
     *
     * Accepted shapes, per entry:
     *   'field' => 'value'
     *   'field' => ['operator' => '>', 'value' => 1]
     *   'field' => ['operator' => 'in', 'value' => ['2020-02-01', 'date_from', 'date_to']]
     *   'field' => [1, 2, 3]                          (any of)
     *   n       => ['field' => 'other', 'value' => ...]
     *
     * @return array<int|string, string> clauses, to be joined with '&&'
     */
    public function getFiltersQueryArray(array $model): array
    {
        if (empty($model['filters']) || !is_array($model['filters'])) return [];

        $allowedColumns = ['id'];
        foreach ($model['fields'] ?? [] as $field)
            if (!empty($field['field'])) $allowedColumns[] = $field['field'];

        $result = [];

        foreach ($model['filters'] as $key => $value) {
            if ($value === null) continue;

            $clause = $this->buildFilter($model, (string) $key, $value, $allowedColumns);
            if ($clause === null) continue;

            [$resultKey, $sql] = $clause;
            $result[$resultKey] = $sql;
        }

        return $result;
    }

    /**
     * Builds one WHERE clause, or null when the filter has to be dropped.
     *
     * @return array{0: int|string, 1: string}|null the result key and the clause
     */
    private function buildFilter(array $model, string $key, mixed $value, array $allowedColumns): ?array
    {
        $fieldKey = $key;

        // { "field": "other", "value": ... } - the key is only a slot number
        if (is_array($value) && isset($value['field'])) {
            $fieldKey = $value['field'];
            $value    = $value['value'] ?? null;
        }

        // 'function' is caller input and becomes part of the query text, so it
        // is matched against a list exactly like a column name is
        $function = is_array($value) ? $this->guard->filterFunction($value['function'] ?? null) : null;
        $collate  = is_array($value) && isset($value['collate']) ? ' collate utf8_general_ci ' : '';

        // '!=' with no value means 'not empty'
        if (is_array($value) && ($value['operator'] ?? null) === '!=' && !isset($value['value'])) $value['value'] = '';

        $operator = '=';

        if (is_array($value) && isset($value['value'])) {
            $candidate = $value['operator'] ?? null;
            if (in_array($candidate, self::OPERATORS, true)) $operator = $candidate;
            $value = $value['value'];
        }

        $field = _uho_fx::array_filter($model['fields'] ?? [], 'field', $fieldKey, ['first' => true]);

        // an unknown column has to at least look like one
        if (!$field && !preg_match(_uho_orm2_sql_guard::COLUMN_PATTERN, $fieldKey)) return null;

        if (isset($field['settings']['hash'])) {
            if (!$this->context->getKeys()) $this->context->halt('_uho_orm2::getFiltersQueryArray::nokeys');

            $hash = $field['settings']['hash'];

            /**
             * Only the '~' form encrypts deterministically (_uho_fx::encrypt()
             * with $deterministic = true); without it every call produces a
             * fresh IV, so '=' could never match and '!=' would match every
             * row - a filter meant to narrow the result set would widen it to
             * the whole table. Such a filter is refused rather than silently
             * producing the wrong rows.
             */
            if ($hash[0] !== '~')
                $this->context->halt(
                    '_uho_orm2::getFiltersQueryArray::cannot filter on the randomly encrypted column ['
                    . ($field['field'] ?? $fieldKey) . '] - mark it settings.hash = "~' . $hash
                    . '" to store it deterministically, or filter on a plain column'
                );

            $value = _uho_fx::encrypt($value, $this->context->getKeys(), substr($hash, 1), true);
        }

        $join = null;

        if ($field) [$value, $operator, $join] = $this->applyFieldType($field, $value, $operator);

        if ($value === null) return null;

        // ':lang' filters address the column of the current language
        $resultKey = $key;
        if (str_contains($key, ':lang')) $resultKey = explode(':lang', $key)[0] . (string) $this->context->getLangAdd();

        $column = $field['field'] ?? $fieldKey;
        if (str_contains($column, ':lang')) $column = explode(':lang', $column)[0] . (string) $this->context->getLangAdd();

        // last line of defence for a column name that never was in the schema
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) return null;

        $prefix = empty($field['settings']['case']) ? '' : 'BINARY ';

        $sql = is_array($value)
            ? $this->buildListClause($column, $value, $operator, $join)
            : $this->buildScalarClause($column, $value, $operator, $prefix, $function, $collate);

        return $sql === null ? null : [$resultKey, $sql];
    }

    /**
     * Type-driven rewriting of a filter: booleans become ints, element lists
     * become LIKE searches over the padded id string.
     *
     * @return array{0: mixed, 1: string, 2: ?string} value, operator, list join
     */
    private function applyFieldType(array $field, mixed $value, string $operator): array
    {
        switch ($field['type'] ?? '') {

            case 'boolean':
                return [intval($value), $operator, null];

            case 'elements':
            case 'checkboxes':
                // '!=' with an empty value keeps its plain meaning
                if ($operator === '!=' && !$value) return [$value, $operator, null];

                $digits = match ($field['settings']['output'] ?? null) {
                    'string'  => 0,
                    '4digits' => 4,
                    '6digits' => 6,
                    default   => 8,
                };

                if (!is_array($value)) $value = explode(',', (string) $value);

                foreach ($value as $k => $entry)
                    if ($digits) {
                        if (!intval($entry)) unset($value[$k]);
                        else $value[$k] = _uho_fx::dozeruj($entry, $digits);
                    }

                $operator = $operator === '!=' ? '%!LIKE%' : '%LIKE%';

                $join = in_array($field['settings']['multiple_filters'] ?? null, ['&&', '||'], true)
                    ? ' ' . $field['settings']['multiple_filters'] . ' '
                    : null;

                if (!$value) return [null, $operator, $join];

                return [$value, $operator, $join];
        }

        return [$value, $operator, null];
    }

    /**
     * A filter holding several values: a range for 'in', otherwise the values
     * joined by OR (or AND for the negated operators).
     *
     * @param array<int, mixed> $values
     */
    private function buildListClause(string $column, array $values, string $operator, ?string $join): ?string
    {
        $values = array_values($values);
        foreach ($values as $k => $value) $values[$k] = $this->runner->sqlSafe($value);

        if ($operator === 'in') return $this->buildRangeClause($column, $values);

        if ($operator === '%LIKE%') {
            $join ??= ' || ';
            return '(`' . $column . '` LIKE "%' . implode('%" ' . $join . ' `' . $column . '` LIKE "%', $values) . '%")';
        }

        if ($operator === '%!LIKE%')
            return '(`' . $column . '` NOT LIKE "%' . implode('%" && `' . $column . '` NOT LIKE "%', $values) . '%")';

        $join ??= ($operator === '!=' ? ' && ' : ' || ');

        return '(`' . $column . '`' . $operator . '"' . implode('" ' . $join . ' `' . $column . '`' . $operator . '"', $values) . '")';
    }

    /**
     * 'in' with two values is a range on this column; with three, the first is
     * a value and the other two name the columns bounding it.
     *
     * @param array<int, mixed> $values
     */
    private function buildRangeClause(string $column, array $values): ?string
    {
        if (count($values) === 2)
            return '(`' . $column . '`>="' . $values[0] . '" && `' . $column . '`<="' . $values[1] . '")';

        if (count($values) !== 3) return null;

        // the bounds are column names, so they are validated as identifiers
        foreach ([$values[1], $values[2]] as $bound)
            if (!is_string($bound) || !preg_match('/^[A-Za-z0-9_]+$/', $bound)) return null;

        return '(`' . $values[1] . '`<="' . $values[0] . '" && `' . $values[2] . '`>="' . $values[0] . '")';
    }

    private function buildScalarClause(
        string $column,
        mixed $value,
        string $operator,
        string $prefix,
        ?string $function,
        string $collate
    ): string {
        $safe = $this->runner->sqlSafe($value);

        if ($operator === '%LIKE%')
            return $function
                ? $function . '(`' . $column . '`' . $collate . ') LIKE "%' . $safe . '%"'
                : $prefix . '`' . $column . '`' . $collate . ' LIKE "%' . $safe . '%"';

        if ($operator === '=' && $collate)
            return $function . '(`' . $column . '`' . $collate . ') = "' . $safe . '"';

        if (is_int($value)) return '`' . $column . '`' . $operator . $value;

        return $prefix . '`' . $column . '`' . $operator . '"' . $safe . '"';
    }

    /**
     * Converts filters the application itself wrote into query parts.
     *
     * Only entries explicitly marked type=sql or type=custom are handled and
     * their values are NOT escaped, so this must never be called with data
     * coming from a request - getFiltersQueryArray() is the path for those.
     *
     *   { "type": "sql",    "value": "CONCAT(...)" }
     *   { "type": "custom", "join": "||", "value": ["...", "..."] }
     *   { "field": "other", "value": { "type": "sql", "value": "..." } }
     *
     * @return array<int, string>
     */
    public function getFiltersRawQueryArray(array $model, mixed $filters): array
    {
        if (!is_array($filters)) return [];

        $result = [];

        foreach ($filters as $key => $value) {
            $fieldKey = $key;

            if (is_array($value) && isset($value['field'], $value['value']) && is_array($value['value'])) {
                $fieldKey = $value['field'];
                $value    = $value['value'];
            }

            if (!is_array($value) || !isset($value['type'], $value['value'])) continue;

            if ($value['type'] === 'custom') {
                $join = in_array($value['join'] ?? '', self::RAW_JOINS, true) ? $value['join'] : '&&';

                if (is_string($value['value'])) $result[] = '(' . $value['value'] . ')';
                elseif (is_array($value['value'])) $result[] = '(' . implode(' ' . $join . ' ', $value['value']) . ')';
                continue;
            }

            // the value is raw by contract, the identifier never is: a backquote
            // in the key would step straight out of the quoting below
            if (!is_string($fieldKey) || !preg_match(_uho_orm2_sql_guard::COLUMN_PATTERN, $fieldKey)) continue;

            if ($value['type'] === 'sql' && (is_string($value['value']) || is_numeric($value['value'])))
                $result[] = '`' . $this->guard->resolveLangColumn($fieldKey) . '`=' . $value['value'];
        }

        return $result;
    }
}
