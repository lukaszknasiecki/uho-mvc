<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Model-based access to a SQL database, driven by JSON schemas.
 *
 * This is a rewrite of _uho_orm with the same public surface. What was one
 * 3300-line class is now a facade over collaborators that each own one
 * concern, and receive only what they need rather than the ORM itself:
 *
 *   _uho_orm2_context        runtime state - handle, keys, languages, errors
 *   _uho_orm2_query_runner   the only code that talks to the driver
 *   _uho_orm2_sql_guard      identifiers and clauses built from caller input
 *   _uho_orm2_schema         loading and normalising schemas
 *   _uho_orm2_schema_loader  finding and parsing the JSON files
 *   _uho_orm2_schema_sql     table creation and the WHERE clause
 *   _uho_orm2_record_reader  rows -> records (sources, media, blocks, urls)
 *   _uho_orm2_record_writer  records -> INSERT/UPDATE
 *   _uho_orm2_files          media paths, cache buster, S3 host
 *   _uho_orm2_twig           schema-driven string rendering
 *   _uho_orm2_upload         writing image variants
 *
 * Two behaviours differ from the legacy class on purpose:
 *  - a fatal condition throws _uho_orm2_exception instead of calling exit();
 *    setHaltMode('exit') restores the old behaviour
 *  - _uho_orm2_context::HALT_* and the collaborators are addressable, so the
 *    ORM can be driven in tests without a database
 */
class _uho_orm2
{
    /** how deep getDeep() may follow schema children */
    private const MAX_CHILD_DEPTH = 8;

    /** field types that have no column and are filled in after the SELECT */
    private const AUTO_TYPES = ['file', 'image', 'video', 'audio', 'media', 'virtual', 'plugin'];

    private _uho_orm2_context      $context;
    private _uho_orm2_query_runner $runner;
    private _uho_orm2_sql_guard    $guard;
    private _uho_orm2_twig         $twigRenderer;
    private _uho_orm2_files        $files;
    private _uho_orm2_record_reader $reader;
    private _uho_orm2_record_writer $writer;
    private _uho_orm2_schema_loader $schemaLoader;
    private _uho_orm2_schema_sql    $schemaSqlManager;
    private _uho_orm2_upload        $uploadManager;
    private _uho_orm2_s3             $s3Manager;

    /** kept public: the CMS reaches the schema manager through the ORM */
    public _uho_orm2_schema $schemaManager;

    private string $tempPublicFolder = '/temp';

    /**
     * @param object|null $sql  _uho_mysqli/_uho_pgsql instance, null in test mode
     * @param string|null $lang current language code
     * @param array $keys       encryption keys used by hashed fields
     * @param bool $test        skip sanitization when there is no SQL handle
     */
    public function __construct(?object $sql, ?string $lang, array $keys, bool $test = false)
    {
        $this->context = new _uho_orm2_context($sql, $keys, $test);
        $this->context->setLanguage($lang);

        $this->runner       = new _uho_orm2_query_runner($this->context);
        $this->guard        = new _uho_orm2_sql_guard($this->context, $this->runner);
        $this->twigRenderer = new _uho_orm2_twig();

        $this->s3Manager = new _uho_orm2_s3();
        $this->s3Manager->setTempPublicFolder($this->tempPublicFolder);

        $this->files  = new _uho_orm2_files($this->s3Manager);
        $this->reader = new _uho_orm2_record_reader($this, $this->context, $this->runner, $this->twigRenderer, $this->files);
        $this->writer = new _uho_orm2_record_writer($this->context, $this->runner);

        $this->schemaLoader = new _uho_orm2_schema_loader();
        $this->schemaLoader->addRootPath('/application/models/json/');

        $this->schemaManager    = new _uho_orm2_schema($this, $this->schemaLoader);
        $this->schemaSqlManager = new _uho_orm2_schema_sql($this, $this->context, $this->runner, $this->guard);
        $this->uploadManager    = new _uho_orm2_upload($this, $this->s3Manager);
    }

    // -------------------------------------------------------------------------
    // Global options
    // -------------------------------------------------------------------------

    public function setKeys(array $keys): void
    {
        $this->context->setKeys($keys);
    }

    public function getKeys(): array
    {
        return $this->context->getKeys();
    }

    public function setDebug(bool $debug): void
    {
        $this->context->setDebug($debug);
    }

    public function isDebug(): bool
    {
        return $this->context->isDebug();
    }

    /**
     * _uho_orm2_context::HALT_EXCEPTION (default) or HALT_EXIT for the legacy
     * behaviour of ending the request on a fatal condition.
     */
    public function setHaltMode(string $mode): void
    {
        $this->context->setHaltMode($mode);
    }

    /**
     * Narrows what sqlCreator() is allowed to do: 'info' alone makes every
     * schema check report-only.
     *
     * @param array<int, string> $actions subset of alert|auto|info
     */
    public function setAllowedCreateActions(array $actions): void
    {
        $this->schemaSqlManager->setAllowedCreateActions($actions);
    }

    /**
     * elements|checkboxes|select => aggregate (one query for the whole result
     * set) or iterate (one query per record, the legacy behaviour).
     */
    public function setSourceMethod(string $kind, string $method): void
    {
        $this->reader->setSourceMethod($kind, $method);
    }

    public function setElementsDoubleFirstInteger(bool $on): void
    {
        $this->reader->setElementsDoubleFirstInteger($on);
    }

    // -------------------------------------------------------------------------
    // Languages
    // -------------------------------------------------------------------------

    public function getLanguages(): array
    {
        return $this->context->getLanguages();
    }

    public function setLanguages($t): void
    {
        $this->context->setLanguages($t);
    }

    public function setLanguage($lang): void
    {
        $this->context->setLanguage($lang);
    }

    // -------------------------------------------------------------------------
    // Twig
    // -------------------------------------------------------------------------

    public function getTwigFromHtml(?string $html, array $data): ?string
    {
        return $this->twigRenderer->fromHtml($html, $data);
    }

    public function getTwigFromModel(array $model, array $data): array
    {
        return $model ? $this->twigRenderer->fromModel($model, $data) : $model;
    }

    public function getTwigFromFile(string $folder, string $file, array $data): string
    {
        return $this->twigRenderer->fromFile($folder, $file, $data);
    }

    // -------------------------------------------------------------------------
    // Errors
    // -------------------------------------------------------------------------

    public function getLastError(): string
    {
        $error = $this->context->getLastErrorMessage();
        if ($error !== null) return '_uho_orm2:: ' . $error;

        $error = $this->schemaLoader->getLastError();
        if ($error !== null) return '_uho_orm2:: ' . $error;

        // the statement text is diagnostic, not something to hand back to a
        // caller that may be rendering it - it stays behind isDebug()
        if ($this->isDebug()) {
            $sql = $this->context->getSql();

            return 'No errors found, last query: ' . ($sql ? $sql->getLastQueryLog() : '-');
        }

        return 'No errors found';
    }

    /**
     * Aborts the current operation - throws, or exits under HALT_EXIT.
     */
    public function halt(string $message): never
    {
        $this->context->halt($message);
    }

    // -------------------------------------------------------------------------
    // Schema paths
    // -------------------------------------------------------------------------

    public function getRootPaths(bool $add_root = false): array
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

    public function loadJson(string $filename): ?array
    {
        return $this->schemaLoader->loadJsonSchema($filename);
    }

    // -------------------------------------------------------------------------
    // Schemas
    // -------------------------------------------------------------------------

    public function getSchema($name, $lang = false, $params = []): ?array
    {
        return $this->schemaManager->getSchema($name, (bool) $lang, $params);
    }

    public function getSchemaWithPageUpdate($name, $lang = false): ?array
    {
        return $this->schemaManager->getSchemaWithPageUpdate($name, (bool) $lang);
    }

    public function updateSchemaSources($schema, $record = null, $params = []): array
    {
        return $this->schemaManager->updateSchemaSources($schema, $record, $params);
    }

    public function validateSchema(array $schema, bool $strict = false): array
    {
        return $this->schemaManager->validateSchema($schema, $strict);
    }

    /**
     * Checks the SQL table behind a schema and creates/updates it if allowed.
     */
    public function sqlCreator(array $schema, $options, $recursive = false, $update_languages = true): array
    {
        return $this->schemaSqlManager->creator(
            $schema,
            is_array($options) ? $options : null,
            (bool) $recursive,
            (bool) $update_languages
        );
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * Reads records of a model.
     *
     * $schema is either a model name, a full schema array, or - the modern
     * form - an array holding every option, in which case the positional
     * arguments are ignored:
     *
     *   $orm->get(['schema' => 'news', 'filters' => ['type' => 1], 'limit' => [1, 10]])
     *
     * Options: schema, filters, filters_custom, fields, first, limit, order,
     * key, count, groupBy, additionalParams, addLanguages, replace_values,
     * returnQuery, skipSchemaFilters, schema_update, use_cms_order.
     */
    public function get(string|array $schema, $filters = null, $single = false, $order = null, $limit = null, array $params = [])
    {
        $options = $this->resolveGetOptions($schema, $filters, $single, $order, $limit, $params);
        $name    = $options['name'];

        if (empty($name) && empty($options['predefined'])) $this->halt('get::no-schema-name');

        $this->checkRawFilters($options['filters']);
        $this->runner->checkConnection('get::' . (is_string($name) ? $name : '?'));

        $model = $this->resolveGetSchema($name, $options, $params);

        if (empty($model['table']))  $this->halt('_uho_orm2::get->.table not found in schema [' . $name . ']');
        if (empty($model['fields'])) $this->halt('_uho_orm2::get->.fields not found in schema [' . $name . ']');

        if (empty($model['order']) && !empty($model['cms']['order']) && !empty($params['use_cms_order']))
            $model['order'] = $model['cms']['order'];

        $fieldsToRead = $options['fields_to_read'];
        if ($fieldsToRead && !is_array($fieldsToRead))
            $fieldsToRead = $model['fields_to_read'][$fieldsToRead] ?? null;

        if ($options['skipSchemaFilters']) $model['filters'] = [];

        $model = $this->fillSchemaFilters($model, $options['additionalParams']);

        $where = $this->buildWhere($model, $options['filters'], $options['filters_custom']);

        $order = $options['order'] ?: ($model['order'] ?? null);

        [$fields, $fieldsModels, $fieldsAuto] = $this->collectFields($model, $fieldsToRead, $options['add_languages']);

        $allowedColumns = $this->guard->allowedColumns($model);

        $count = $options['count'] === true ? ['type' => 'quick'] : $options['count'];
        if (!empty($count['type'])) $fields = $this->countFields($count, $allowedColumns);

        $query = 'SELECT ' . implode(',', $this->runner->quoteFieldList($fields))
            . ' FROM ' . $model['table'] . ' ' . $where;

        if ($options['groupBy']) {
            $groupBy = $this->guard->groupBy($options['groupBy'], $allowedColumns);
            if ($groupBy === null) $this->halt('get::group-by::field-not-allowed::' . $options['groupBy']);
            $query .= ' GROUP BY ' . $groupBy;
        }

        $orderSql = $this->buildOrder($order, $allowedColumns);
        if ($orderSql !== '') $query .= ' ORDER BY ' . $orderSql;

        $limitSql = $this->buildLimit($options['limit'], $options['single']);
        if ($limitSql !== '') $query .= ' ' . $limitSql;

        if ($options['return_query']) return $query;

        $data = $this->runner->query($query) ?? [];

        if (!empty($count['type']))
            return match ($count['type']) {
                'quick'   => $data[0]['COUNT(*)'] ?? null,
                'average' => $data[0]['average'] ?? null,
                default   => null,
            };

        foreach ($options['replace_values'] as $column => $value)
            foreach ($data as $k => $record)
                if (isset($record[$column])) $data[$k][$column] = $value;

        // ORDER BY FIELD given as an array is applied to the result, not the query
        if (is_array($order) && ($order['type'] ?? null) === 'FIELD') $data = $this->reorderByField($data, $order);

        $data = $this->reader->updateRecords($model, $data);
        $data = $this->reader->updateMedia($model, $data, $fieldsAuto, $params);
        $data = $this->reader->updateBlocks($model, $data);
        $data = $this->resolveOutsideModels($data, $fieldsModels);

        if ($fieldsToRead && !empty($model['fields_to_read']))
            foreach ($data as $k => $record)
                foreach ($record as $column => $_)
                    if (!in_array($column, $fieldsToRead, true)) unset($data[$k][$column]);

        if ($data && isset($model['url']))
            $data = $this->reader->updateUrls((array) $model['url'], $data, $options['additionalParams']);

        if ($options['single'] === true) return $data[0] ?? [];

        if (!empty($options['returnByKey'])) {
            $keyed = [];
            foreach ($data as $record) $keyed[$record[$options['returnByKey']]] = $record;
            $data = $keyed;
        }

        return $options['count'] ? count($data) : $data;
    }

    /**
     * Reads a model together with the children its schema declares, one query
     * per child per record.
     */
    public function getDeep(array $params)
    {
        return $this->getDeepChain($params, []);
    }

    /**
     * A schema listing itself among its children - directly or through another
     * schema - would recurse until the process ran out of memory. The chain
     * expanded so far is carried down so the cycle ends with a message naming
     * it.
     *
     * @param array<string, true> $chain
     */
    private function getDeepChain(array $params, array $chain)
    {
        $result = $this->get($params);
        $schema = $this->getSchema($params['schema']);

        if (!$result || empty($schema['children']) || !empty($schema['first'])) return $result;

        $key = is_string($params['schema']) ? $params['schema'] : (string) json_encode($params['schema']);

        if (isset($chain[$key]) || count($chain) >= self::MAX_CHILD_DEPTH)
            $this->halt('_uho_orm2::getDeep::children cycle or depth limit at [' . $key
                . '] (chain: ' . implode(' -> ', array_keys($chain)) . ')');

        $chain[$key] = true;

        foreach ($result as $k => $record)
            foreach ($schema['children'] as $name => $child) {
                $filters = $child['filters'] ?? [];
                $filters[$child['parent']] = $record[$child['id']];

                $result[$k][$name] = $this->getDeepChain(
                    ['schema' => $child['schema'], 'filters' => $filters],
                    $chain
                );
            }

        return $result;
    }

    /**
     * The WHERE clause a model and a set of filters produce, as a string.
     */
    public function getFilters($name, $filters = null, $single = false, $order = null, $limit = null, $count = false, $dataOverwrite = null, $cache = false, $groupBy = null)
    {
        /**
         * $cache is accepted for signature compatibility and deliberately does
         * nothing. The legacy implementation read a prebuilt WHERE clause out
         * of $_SESSION - and never wrote one - which only created a path for
         * session contents to reach a query.
         */
        if (is_array($name)) $model = $name;
        else {
            $model = $this->loadJson($name . '.json');
            if (!$model) return [];
        }

        if (!$model) $this->halt('_uho_orm2::getFilters::model corrupted:' . $name);

        if (!isset($model['filters'])) $model['filters'] = [];

        if (is_array($filters)) {
            if ($filters) $model['filters'] = array_merge($model['filters'], $filters);

            $model['filters'] = $this->schemaSqlManager->getFiltersQueryArray($model);
            if ($model['filters']) $model['filters'] = 'WHERE ' . implode(' && ', $model['filters']);
        } elseif ($filters) {
            /**
             * A bare string used to be pasted into WHERE verbatim. There is one
             * sanctioned way to pass a condition the ORM will not escape, and
             * it says so at the call site.
             */
            $this->halt(
                '_uho_orm2::getFilters::a string filter is no longer interpolated - '
                . 'pass ["type" => "custom", "value" => $sql] (see filters_custom) or an array of filters'
            );
        }

        if (!$model['filters']) $model['filters'] = '';

        return $model['filters'];
    }

    /**
     * The WHERE parts of a model's filters, as an array.
     *
     * @param bool $raw treat the filters as application-provided SQL - see
     *                  _uho_orm2_schema_sql::getFiltersRawQueryArray()
     */
    public function getFiltersQueryArray($model, array $filters = [], $raw = false): array
    {
        if ($filters) $model['filters'] = $filters;

        return $raw
            ? $this->schemaSqlManager->getFiltersRawQueryArray($model, $model['filters'] ?? [])
            : $this->schemaSqlManager->getFiltersQueryArray($model);
    }

    // -------------------------------------------------------------------------
    // get() helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves the two calling conventions - positional arguments, or a single
     * options array - into one options set.
     */
    private function resolveGetOptions(string|array $schema, $filters, $single, $order, $limit, array &$params): array
    {
        $options = [
            'name'              => null,
            'predefined'        => null,
            'additionalParams'  => [],
            'add_languages'     => false,
            'count'             => false,
            'groupBy'           => '',
            'fields_to_read'    => [],
            'filters'           => [],
            'filters_custom'    => [],
            'single'            => false,
            'limit'             => null,
            'returnByKey'       => null,
            'order'             => null,
            'return_query'      => false,
            'replace_values'    => [],
            'skipSchemaFilters' => false,
        ];

        $positional = true;

        if (is_array($schema) && !empty($schema['model_name'])) {
            $options['predefined'] = $schema;
            $options['name']       = $schema['model_name'];
        } elseif (is_array($schema)) {
            $params     = $schema;
            $positional = false;
        }

        if ($positional) {
            $options['single'] = $single;
            if ($filters !== null) $options['filters'] = $filters;
            if ($order !== null)   $options['order']   = $order;
            if ($limit !== null)   $options['limit']   = $limit;
        }

        $map = [
            'schema'            => 'name',
            'additionalParams'  => 'additionalParams',
            'addLanguages'      => 'add_languages',
            'count'             => 'count',
            'groupBy'           => 'groupBy',
            'fields'            => 'fields_to_read',
            'filters'           => 'filters',
            'filters_custom'    => 'filters_custom',
            'first'             => 'single',
            'limit'             => 'limit',
            'key'               => 'returnByKey',
            'order'             => 'order',
            'returnQuery'       => 'return_query',
            'replace_values'    => 'replace_values',
            'skipSchemaFilters' => 'skipSchemaFilters',
        ];

        foreach ($map as $source => $target)
            if (isset($params[$source])) $options[$target] = $params[$source];

        if (is_string($schema)) $options['name'] = $schema;

        // default field set, when the query does not name one
        if (!$options['fields_to_read']) $options['fields_to_read'] = $options['single'] ? '_single' : '_multiple';

        return $options;
    }

    private function resolveGetSchema($name, array $options, array $params): array
    {
        if (!empty($options['predefined'])) return $options['predefined'];

        if (isset($params['schema_update'])) return (array) $this->getSchemaWithPageUpdate($name);

        $model = $this->getSchema($name, false, ['return_error' => true]);

        if (isset($model['result']) && !$model['result']) $this->halt($model['message']);

        return (array) $model;
    }

    /**
     * Fills '%param%' placeholders of the schema's own filters.
     */
    private function fillSchemaFilters(array $model, array $additionalParams): array
    {
        if (empty($model['filters']) || !$additionalParams) return $model;

        foreach ($model['filters'] as $k => $filter)
            foreach ($additionalParams as $key => $value)
                if (is_string($value) && is_string($filter))
                    $model['filters'][$k] = $filter = str_replace('%' . $key . '%', $value, $filter);

        return $model;
    }

    /**
     * Combines the schema's filters, the query's filters and the raw ones into
     * a single WHERE clause.
     */
    private function buildWhere(array $model, $filters, $filtersCustom): string
    {
        if (empty($filters) && empty($filtersCustom) && empty($model['filters'])) return '';

        if (empty($model['filters'])) $model['filters'] = [];
        if (!empty($filters)) $model['filters'] = array_merge($model['filters'], $filters);

        $parts = $this->schemaSqlManager->getFiltersQueryArray($model);

        if (!empty($filtersCustom)) {
            $custom = $this->schemaSqlManager->getFiltersRawQueryArray($model, $filtersCustom);
            if ($custom) $parts = array_merge($parts, $custom);
        }

        return $parts ? 'WHERE ' . implode(' && ', $parts) : '';
    }

    /**
     * Splits the schema fields into the ones the SELECT reads, the ones filled
     * in from another model, and the ones derived after the query.
     *
     * @return array{0: array<int, string>, 1: array<int, array>, 2: array<int, array>}
     */
    private function collectFields(array $model, $fieldsToRead, bool $addLanguages): array
    {
        $fields       = ['id'];   // every schema has one
        $fieldsModels = [];
        $fieldsAuto   = [];

        foreach ($model['fields'] as $field) {
            $name = $field['field'] ?? null;
            $type = $field['type'] ?? null;

            if ($name !== null && in_array($name, $fields, true)) {
                // already collected
            } elseif ($name !== null && $fieldsToRead && is_array($fieldsToRead) && !in_array($name, $fieldsToRead, true)) {
                // not requested
            } elseif (in_array($type, self::AUTO_TYPES, true)) {
                $fieldsAuto[] = $field;
            } elseif ($type === 'model') {
                $fieldsModels[] = $field;
            } elseif (isset($field['outside']['model'])) {
                $fieldsModels[] = ['model' => $field['outside']];
            } elseif ($name !== null) {
                $fields[] = empty($field['settings']['field_output'])
                    ? $name
                    : $name . '` AS `' . $field['settings']['field_output'];

                if ($type === 'image_media') $fieldsAuto[] = $field;
            }

            // read every language of a ':lang' field, not only the current one
            if (($addLanguages || isset($field['add_languages'])) && $name !== null && str_contains($name, ':lang')) {
                $bare = explode(':lang', $name)[0];
                foreach ($this->context->getLanguages() as $lang) $fields[] = $bare . $lang['lang_add'];
            }
        }

        return [$fields, $fieldsModels, $fieldsAuto];
    }

    /**
     * COUNT(*) or AVG(), replacing the field list.
     *
     * @return array<int, string>
     */
    private function countFields(array $count, array $allowedColumns): array
    {
        if ($count['type'] === 'quick') return ['COUNT(*)'];

        if ($count['type'] !== 'average') return ['COUNT(*)'];

        $column = $this->guard->fieldName($count['field'] ?? null, $allowedColumns);
        if (!$column) $this->halt('get::count-average::field-not-allowed::' . ($count['field'] ?? ''));

        if (empty($count['function'])) return ['AVG(' . $column . ') AS average'];

        $function = $this->guard->aggregateFunction($count['function']);
        if (!$function) $this->halt('get::count-average::function-not-allowed::' . $count['function']);

        return ['AVG(' . $function . '(' . $column . ')) AS average'];
    }

    /**
     * Order given as a string is sanitized; given as an array it is either a
     * FIELD() list or a field/sort pair.
     */
    private function buildOrder($order, array $allowedColumns): string
    {
        if (!$order) return '';

        if (is_array($order) && ($order['type'] ?? null) === 'field' && !empty($order['values'])) {
            $values = array_filter($order['values'], fn($v) => in_array($v, $allowedColumns, true));

            return $values ? 'FIELD (' . implode(',', $values) . ')' : '';
        }

        if (is_array($order) && !empty($order['field']) && !empty($order['sort'])) {
            $sort = strtoupper($order['sort']);

            return in_array($order['field'], $allowedColumns, true) && in_array($sort, ['ASC', 'DESC'], true)
                ? '`' . $order['field'] . '` ' . $sort
                : '';
        }

        if (!is_string($order)) return '';

        return $this->guard->orderBy($order, $allowedColumns, '');
    }

    /**
     * '0,10' or [page, per_page] -> the LIMIT clause.
     */
    private function buildLimit($limit, $single): string
    {
        if ($single) return 'LIMIT 0,1';

        if (is_array($limit) && count($limit) === 2) {
            $page    = intval($limit[0]);
            $perPage = intval($limit[1]);

            return $page > 0 && $perPage > 0 ? 'LIMIT ' . ($page - 1) * $perPage . ',' . $perPage : '';
        }

        if (is_array($limit) || !is_string($limit) || $limit === '') {
            return is_int($limit) && $limit > 0 ? 'LIMIT ' . $limit : '';
        }

        $parts = explode(',', $limit);

        if (count($parts) === 1) return 'LIMIT ' . intval($parts[0]);
        if (count($parts) === 2) return 'LIMIT ' . intval($parts[0]) . ',' . intval($parts[1]);

        return '';
    }

    /**
     * Reorders an already-read result set to match an explicit value order.
     */
    private function reorderByField(array $data, array $order): array
    {
        $result = [];

        foreach ($order['values'] as $value) {
            $record = _uho_fx::array_filter($data, $order['field'], $value, ['first' => true]);
            if ($record) $result[] = $record;
        }

        return $result;
    }

    /**
     * Fields declared with 'outside' hold the result of another model's query,
     * its filters filled in from the record.
     */
    private function resolveOutsideModels(array $data, array $fieldsModels): array
    {
        if (!$fieldsModels) return $data;

        foreach ($data as $k => $record)
            foreach ($fieldsModels as $field) {
                if (($field['type'] ?? null) === 'model' || !isset($field['field'])) continue;

                $model = _uho_fx::arrayReplace($field['model'], $record, '%', '%');

                $data[$k][$field['field']] = $this->get(
                    $model['model'],
                    $model['filters'] ?? null,
                    false,
                    $model['order'] ?? null
                );
            }

        return $data;
    }

    /**
     * Raw filters used to be accepted inline; they now have to be passed as
     * filters_custom, which is the only path that skips escaping.
     */
    private function checkRawFilters($filters): void
    {
        if (!$filters || !is_array($filters)) return;

        foreach ($filters as $filter)
            if (
                (isset($filter['type']) && in_array($filter['type'], ['custom', 'sql'], true))
                || (isset($filter['value']['type']) && in_array($filter['value']['type'], ['custom', 'sql'], true))
            )
                $this->halt('_uho_orm2::deprecated raw filter detected, use filters_custom instead');
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Inserts one record, or several when $multiple is set.
     *
     * @return int|false the new id, or false
     */
    public function post($model, $data, $multiple = false, $params = []): bool|int
    {
        $schema = $this->getSchema($model, true);
        if (!$schema) return false;

        if (!empty($params['fields'])) $schema = $this->limitWritableFields($schema, $params['fields']);

        if ($multiple) {
            if (!$data) return false;

            $query = 'INSERT INTO ' . $schema['table'] . ' ' . $this->writer->buildQueryMultiple($schema, $data);

            if (!$this->runner->queryOut($query)) {
                $this->context->addError('post:: ' . $query);
                return false;
            }

            $id = $this->getInsertId();

            // auto fields may depend on the whole table, so recalculate it
            $set = $this->writer->buildAutoSql($schema);
            if ($set) $this->runner->queryOut('UPDATE ' . $schema['table'] . ' SET ' . $set);

            return $id;
        }

        $set = $this->writer->buildQuery($schema, $data);
        if (!$set) return false;

        $query = 'INSERT INTO ' . $schema['table'] . ' SET ' . $set;

        if (!$this->runner->queryOut($query)) {
            $this->context->addError('post:: ' . $query);
            return false;
        }

        $id = $this->getInsertId();

        $auto = $this->writer->buildAutoSql($schema);
        if ($auto) $this->runner->queryOut('UPDATE ' . $schema['table'] . ' SET ' . $auto . ' WHERE id="' . $id . '"');

        return $id;
    }

    /**
     * Updates records, inserting the ones that do not exist yet.
     *
     * @param array $params 'uid' => columns identifying a record, 'version',
     *                      'skip_id', 'schema_update', 'skipSchemaFilters'
     * @return int|bool affected rows for a single update, true for a multiple
     *                  one, false on failure
     */
    public function put($model, $data, $filters = null, $multiple = false, $params = []): int|bool
    {
        $schema = isset($params['schema_update'])
            ? $this->getSchemaWithPageUpdate($model, true)
            : $this->getSchema($model, true);

        if (!$schema) {
            $this->context->addError('put:: model not found:: ' . (is_string($model) ? $model : '?'));
            return false;
        }

        if (isset($schema['filters'], $params['skipSchemaFilters'])) unset($schema['filters']);

        if (!empty($params['fields'])) $schema = $this->limitWritableFields($schema, $params['fields']);

        $uids = empty($params['uid']) ? null : (array) $params['uid'];

        $this->checkRawFilters($filters);

        if ($multiple && $filters)  return $this->putMatching($model, $schema, $data, $filters);
        if ($multiple && ($params['version'] ?? 'old') === 'new') return $this->putUpsert($schema, $data);
        if ($multiple)              return $this->putMerged($model, $schema, $data, $uids, !empty($params['skip_id']));

        if ($filters) {
            $where = $this->getFilters($schema, $filters);
        } else {
            $id = $data['id'] ?? null;

            if (!$id) {
                $this->context->addError('put:: ID not found:: ' . (is_string($model) ? $model : '?'));
                return false;
            }

            $where = 'WHERE id="' . $this->runner->sqlSafe($id) . '"';
        }

        // no such record yet - this is an insert
        if (!$this->runner->query('SELECT id FROM ' . $schema['table'] . ' ' . $where)) return $this->post($schema, $data);

        unset($data['id']);

        $set = $this->writer->buildQuery($schema, $data);

        if (!$set) {
            $this->context->addError('mysql error:: buildOutputQuery empty for table: ' . $schema['table']);
            return false;
        }

        if (!$this->runner->queryOut('UPDATE ' . $schema['table'] . ' SET ' . $set . ' ' . $where)) return false;

        $affected = $this->getAffectedRows();

        $auto = $this->writer->buildAutoSql($schema);
        if ($auto) $this->runner->queryOut('UPDATE ' . $schema['table'] . ' SET ' . $auto . ' ' . $where);

        return $affected;
    }

    /**
     * Updates every record the filters match, one by one.
     */
    private function putMatching($model, array $schema, array $data, $filters): bool
    {
        $where  = str_replace('WHERE ', '', $this->getFilters($schema, $filters));
        $exists = $this->runner->query('SELECT id FROM ' . $schema['table'] . ' WHERE (' . $where . ')') ?? [];

        foreach ($exists as $k => $record) $exists[$k] = $record['id'];

        foreach ($data as $record) {
            if (empty($record['id'])) {
                $this->context->addError('put error:: data record has no .id field');
                return false;
            }

            if (in_array($record['id'], $exists)) $this->put($model, $record);
        }

        return true;
    }

    /**
     * One INSERT ... ON DUPLICATE KEY UPDATE for the whole set.
     */
    private function putUpsert(array $schema, array $data): bool
    {
        $values = $this->writer->buildQueryMultiple($schema, $data);

        $update = [];
        foreach ($this->writableColumns($schema, array_keys($data[0])) as $column)
            if ($column !== 'id') $update[] = '`' . $column . '`=VALUES(`' . $column . '`)';

        $result = $this->runner->queryOut(
            'INSERT INTO ' . $schema['table'] . ' ' . $values . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $update)
        );

        $auto = $this->writer->buildAutoSql($schema);
        if ($auto) $this->runner->queryOut('UPDATE ' . $schema['table'] . ' SET ' . $auto);

        return (bool) $result;
    }

    /**
     * Splits the set into records that already exist - matched on the uid
     * columns, or on every column - and records that do not, then updates the
     * first group and inserts the second.
     *
     * @param array<int, string>|null $uids
     */
    private function putMerged($model, array $schema, array $data, ?array $uids, bool $skipId): bool
    {
        $conditions = [];

        foreach ($data as $record) {
            $subset = $uids === null ? $record : array_intersect_key($record, array_flip($uids));
            $conditions[] = str_replace('WHERE ', '', $this->getFilters($schema, $subset));
        }

        $columns = $this->writableColumns($schema, $uids ?? array_keys($data[0]));

        $exists = $this->runner->query(
            'SELECT id,' . implode(',', $columns) . ' FROM ' . $schema['table']
                . ' WHERE (' . implode(') || (', $conditions) . ')'
        ) ?? [];

        $insert = $data;
        $update = [];

        foreach ($insert as $k => $record) {
            $wanted = array_intersect_key($record, array_flip($columns));

            foreach ($exists as $existing) {
                $id = $existing['id'];
                unset($existing['id']);

                if ($wanted != array_intersect_key($existing, array_flip($columns))) continue;

                if (!$skipId) $insert[$k]['id'] = $id;

                $update[] = $insert[$k];
                unset($insert[$k]);
                break;
            }
        }

        $result = $insert ? $this->post($model, $insert, true) : true;
        if ($result === false) return false;

        foreach ($update as $record) {
            $where = [];

            foreach ($uids ?? ['id'] as $uid)
                if (isset($record[$uid])) {
                    $where[] = '`' . $uid . '`="' . $this->runner->sqlSafe($record[$uid]) . '"';
                    unset($record[$uid]);
                }

            if (!$where) return false;

            $set = $this->writer->buildQuery($schema, $record);
            if (!$set) continue;

            $result = $this->runner->queryOut(
                'UPDATE ' . $schema['table'] . ' SET ' . $set . ' WHERE ' . implode(' && ', $where)
            );

            $auto = $this->writer->buildAutoSql($schema);
            if ($auto) $this->runner->queryOut('UPDATE ' . $schema['table'] . ' SET ' . $auto);
        }

        return (bool) $result;
    }

    /**
     * Narrows the schema to the fields a write is allowed to touch.
     *
     * The read side has always had 'fields'; the write side had no equivalent,
     * so every schema column present in the payload was written. A controller
     * handing a decoded request body to put() could therefore set any column -
     * 'admin' included. Passing params['fields'] here states, at the call site,
     * what that request is allowed to change. Fields marked
     * settings.readonly in the schema are removed regardless.
     *
     * @param array<int, string> $fields
     */
    private function limitWritableFields(array $schema, array $fields): array
    {
        foreach ($schema['fields'] as $k => $field)
            if (!empty($field['field']) && !in_array($field['field'], $fields, true))
                unset($schema['fields'][$k]);

        $schema['fields'] = array_values($schema['fields']);

        return $schema;
    }

    /**
     * The subset of $columns the schema actually declares.
     *
     * The bulk write paths take their column list from the keys of the data
     * they are handed - or from params['uid'] - and those names go into the
     * text of a SELECT and of an ON DUPLICATE KEY UPDATE. Values are escaped by
     * the driver, but an identifier cannot be: a backquote in a key would step
     * straight out of the quoting. So the list is matched against the schema,
     * and a name that is not there stops the write rather than being dropped -
     * silently ignoring a uid column would change which records get matched.
     *
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function writableColumns(array $schema, array $columns): array
    {
        $allowed = $this->guard->allowedColumns($schema);

        foreach ($columns as $column)
            if (!is_string($column) || !in_array($column, $allowed, true))
                $this->halt('_uho_orm2::put::column not in schema [' . $schema['table'] . ']: '
                    . (is_string($column) ? $column : gettype($column)));

        return array_values($columns);
    }

    /**
     * REST alias of put().
     */
    public function patch($model, $data, $filters = null, $multiple = false, $params = []): int|bool
    {
        return $this->put($model, $data, $filters, $multiple, $params);
    }

    /**
     * @return int|false affected rows, or false
     */
    public function delete($model, $filters, bool $multiple = false): int|bool
    {
        if (!is_array($filters)) $filters = ['id' => $filters];

        if ($multiple) $filters = $this->getFilters($model, $filters);

        $this->checkRawFilters($filters);

        $schema = $this->getSchema($model, true);

        if (!$schema || empty($schema['table'])) {
            $this->context->addError('delete:: schema error:: ' . (is_string($model) ? $model : '?'));
            return false;
        }

        if (is_array($filters)) $filters = $this->writer->buildQuery($schema, $filters, ' && ');
        if (!$filters) return false;

        $query = str_replace(
            'WHERE WHERE',
            'WHERE',
            'DELETE FROM ' . $schema['table'] . ' WHERE ' . $filters
        );

        if (!$this->runner->queryOut($query)) {
            $this->context->addError('delete:: ' . $query);
            return false;
        }

        return $this->getAffectedRows();
    }

    public function truncate(string $model): bool
    {
        $schema = $this->getSchema($model, true);
        if (!$schema || empty($schema['table'])) return false;

        $this->runner->queryOut('TRUNCATE TABLE ' . $schema['table']);

        return true;
    }

    /**
     * Builds the SET part of a write query - exposed because the CMS composes
     * its own statements from it.
     */
    public function buildOutputQuery($model, $data, string $join = ','): array|string|null
    {
        return $this->writer->buildQuery($model, $data, $join);
    }

    protected function buildOutputQueryMultiple($model, $data, $output = 'query'): array|string
    {
        return $this->writer->buildQueryMultiple($model, $data, $output);
    }

    protected function buildPreparedData(array $schema, array $data): array
    {
        return $this->writer->buildPrepared($schema, $data);
    }

    // -------------------------------------------------------------------------
    // SQL
    // -------------------------------------------------------------------------

    public function query($query, $single = false, $stripslashes = true, $key = null, $do_field_only = null, $force_sql_cache = false)
    {
        return $this->runner->query($query, (bool) $single, (bool) $stripslashes, $key, $do_field_only, (bool) $force_sql_cache);
    }

    public function queryOut($query)
    {
        return $this->runner->queryOut($query);
    }

    public function queryUpdatePrepared(string $table, array $setParams, array $whereParams = [], string $whereClause = '')
    {
        return $this->runner->updatePrepared($table, $setParams, $whereParams, $whereClause);
    }

    public function multiQueryOut($query)
    {
        return $this->runner->multiQueryOut($query);
    }

    public function sqlSafe($s)
    {
        return $this->runner->sqlSafe($s);
    }

    public function getInsertId()
    {
        return $this->runner->getInsertId();
    }

    public function getAffectedRows()
    {
        return $this->runner->getAffectedRows();
    }

    protected function sqlCheckConnection(?string $message = null): void
    {
        $this->runner->checkConnection($message);
    }

    protected function processLangQuery(string $query): string
    {
        return $this->runner->processLangQuery($query);
    }

    protected function sqlSanitizeLangFields($fields)
    {
        return $this->runner->quoteFieldList($fields);
    }

    /**
     * Sanitizes an ORDER BY string against a list of allowed columns.
     */
    public function sanitizeOrderBy(string $input, array $allowedColumns, string $default = 'id ASC'): string
    {
        return $this->guard->orderBy($input, $allowedColumns, $default);
    }

    protected function sanitizeFieldName($input, array $allowedColumns): ?string
    {
        return $this->guard->fieldName($input, $allowedColumns);
    }

    protected function sanitizeGroupBy(string $input, array $allowedColumns): ?string
    {
        return $this->guard->groupBy($input, $allowedColumns);
    }

    // -------------------------------------------------------------------------
    // Files
    // -------------------------------------------------------------------------

    public function fileSetCacheBuster($q): void
    {
        $this->files->setCacheBuster($q);
    }

    public function fileAddCacheBuster(string &$f): void
    {
        $this->files->addCacheBuster($f);
    }

    public function fileRemoveCacheBuster(string $f): string
    {
        return $this->files->removeCacheBuster($f);
    }

    public function fileCacheBuster($f)
    {
        return is_string($f) ? $this->files->removeCacheBuster($f) : $f;
    }

    public function setFolderReplace($source, $destination, $s3 = null): void
    {
        $this->files->setFolderReplace($source, $destination, $s3);
    }

    public function setImageSizes($onOff): void
    {
        $this->files->setImageSizes($onOff);
    }

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------

    public function setTempPublicFolder($folder)
    {
        $this->tempPublicFolder = $folder;
        $this->s3Manager->setTempPublicFolder($folder);
        $this->uploadManager->setTempPublicFolder($folder);
    }

    public function uploadBase64Image($model_name, $record_id, $field_name, $image)
    {
        return $this->uploadManager->uploadBase64Image($model_name, $record_id, $field_name, $image);
    }

    public function uploadSrcImage($model_name, $record_id, $field_name, $image_src)
    {
        return $this->uploadManager->uploadSrcImage($model_name, $record_id, $field_name, $image_src);
    }

    /**
     * @param bool $return_array return the upload log next to the result
     */
    public function uploadImage($schema, $record, $field_name, $image, $temp_filename = null, $temp_folder = null, $return_array = false)
    {
        $result = $this->uploadManager->uploadImage($schema, $record, $field_name, $image, $temp_filename, $temp_folder);

        return $return_array
            ? ['result' => $result, 'errors' => $this->uploadManager->getLogs()]
            : $result;
    }

    public function removeImage($model_name, $record_id, $field_name)
    {
        return $this->uploadManager->removeImage($model_name, $record_id, $field_name);
    }

    // -------------------------------------------------------------------------
    // S3
    // -------------------------------------------------------------------------

    public function isS3(): bool
    {
        return $this->s3Manager->isS3();
    }

    public function getS3()
    {
        return $this->s3Manager->getS3();
    }

    public function setS3($object): void
    {
        $this->s3Manager->setS3($object);
    }

    public function setS3Compress($compress): bool
    {
        return $this->s3Manager->setS3Compress($compress);
    }

    public function s3setCache(string $cache_filename): void
    {
        $this->s3Manager->s3setCache($cache_filename);
    }

    public function s3getCacheFilename()
    {
        return $this->s3Manager->s3getCacheFilename();
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Applies filters to an array of records the same way the SQL WHERE clause
     * would, for data that is already in memory.
     *
     * @param bool $any keep a record matching any filter, instead of all
     */
    public function filterResults($schema, $data, $filters, $any = false)
    {
        if (!is_array($filters)) return array_values($data);

        foreach ($data as $k => $record) {
            if (!$record) continue;

            foreach ($filters as $column => $filter) {
                if (!is_array($filter)) $filter = ['operator' => '=', 'value' => $filter];

                $field   = _uho_fx::array_filter($schema['fields'], 'field', $column, ['first' => true]);
                $value   = $record[$column] ?? null;
                $matches = false;

                switch ($filter['operator']) {
                    case '%LIKE%':
                        if (!empty($field['options']) && $any) {
                            foreach ($field['options'] as $option)
                                if (strpos(strtolower(' ' . $option['label']), strtolower($filter['value']))
                                    && $option['value'] == $value) $matches = true;
                        } else {
                            $matches = (bool) strpos(strtolower(' ' . $value), strtolower($filter['value']));
                        }
                        break;

                    case '=':
                        $matches = ($value == $filter['value']);
                        break;
                }

                if (!$any && !$matches) unset($data[$k]);
            }
        }

        return array_values($data);
    }

    /**
     * Replaces the stored ids of source-driven fields with the records they
     * point at - the read-side shape, for a record that was not read here.
     */
    public function updateRecordSources($schema, $record)
    {
        foreach ($schema['fields'] as $field) {
            if (!isset($field['source']) || !isset($record[$field['field']])) continue;

            $value = $record[$field['field']];

            if (!empty($field['source']['model'])) {
                if (in_array($field['type'] ?? '', ['elements', 'checkboxes'], true)) {
                    if (!is_array($value)) $value = explode(',', (string) $value);
                    else foreach ($value as $k => $entry)
                        if (is_array($entry) && !empty($entry['id'])) $value[$k] = $entry['id'];

                    $value = $this->get($field['source']['model'], ['id' => $value]);
                } else {
                    if (is_array($value)) $value = $value['id'];
                    $value = $this->get($field['source']['model'], ['id' => $value], true);
                }
            }

            $record[$field['field']] = $value;
        }

        return $record;
    }

    // -------------------------------------------------------------------------
    // Record post-processing (kept reachable for subclasses)
    // -------------------------------------------------------------------------

    protected function getUpdateRecords($model, $data)
    {
        return $this->reader->updateRecords($model, $data);
    }

    protected function getUpdateRecordsMedia($model, $data, $fields_auto)
    {
        return $this->reader->updateMedia($model, $data, $fields_auto);
    }

    protected function getUpdateRecordsBlocks($model, $data)
    {
        return $this->reader->updateBlocks($model, $data);
    }

    protected function getUpdateUrls($url_schema, array $records, array $additionalParams)
    {
        return $this->reader->updateUrls((array) $url_schema, $records, $additionalParams);
    }
}
