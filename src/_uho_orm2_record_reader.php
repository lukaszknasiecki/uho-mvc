<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Turns the rows a SELECT returned into records shaped by the schema.
 *
 * Four passes, in this order, because each one depends on the previous:
 *  1. updateRecords()  - decryption, source lookups, type casting
 *  2. updateMedia()    - image/file/video paths, virtual and media fields
 *  3. updateBlocks()   - editor.js block decoding
 *  4. updateUrls()     - the url pattern declared by the schema
 *
 * Source lookups are aggregated by default: the ids of the whole result set
 * are collected first and resolved with one query, and the resolved set is
 * cached on the schema copy so the remaining records reuse it. The legacy
 * per-record lookups are still reachable through setSourceMethod().
 */
class _uho_orm2_record_reader
{
    public const METHOD_AGGREGATE = 'aggregate';
    public const METHOD_ITERATE   = 'iterate';

    private string $elementsMethod   = self::METHOD_AGGREGATE;
    private string $checkboxesMethod = self::METHOD_AGGREGATE;
    private string $selectMethod     = self::METHOD_AGGREGATE;

    /** elements_double: cast the table slug to int before looking it up */
    private bool $elementsDoubleFirstInteger = false;

    /** how deep 'model' fields may nest before the chain is refused */
    private const MAX_MODEL_DEPTH = 8;

    /** schemas currently being expanded, to catch a schema referring to itself */
    private array $modelChain = [];

    public function __construct(
        private _uho_orm2 $orm,
        private _uho_orm2_context $context,
        private _uho_orm2_query_runner $runner,
        private _uho_orm2_twig $twig,
        private _uho_orm2_files $files
    ) {}

    /**
     * @param string $kind elements|checkboxes|select
     */
    public function setSourceMethod(string $kind, string $method): void
    {
        match ($kind) {
            'elements'   => $this->elementsMethod   = $method,
            'checkboxes' => $this->checkboxesMethod = $method,
            'select'     => $this->selectMethod     = $method,
            default      => $this->context->halt('_uho_orm2::unknown source kind: ' . $kind),
        };
    }

    public function setElementsDoubleFirstInteger(bool $on): void
    {
        $this->elementsDoubleFirstInteger = $on;
    }

    // -------------------------------------------------------------------------
    // Pass 1 - values, sources, casting
    // -------------------------------------------------------------------------

    public function updateRecords(array $model, array $data): array
    {
        foreach ($data as $k => $record) {
            $this->decryptRecord($model, $data[$k]);
            if ($k === 0) $this->markHashedSourceFields($model);

            // $record is the running copy the source resolvers read from
            $record = $data[$k];

            foreach ($model['fields'] as $fieldKey => $field) {
                $this->resolveField($model, $data, $k, $record, $fieldKey, $field);

                $name = $this->bareFieldName($field['field'] ?? null);

                if ($name !== null && !empty($data[$k][$name]))
                    $data[$k][$name] = $this->fieldValue($field['type'] ?? '', $data[$k][$name], $field);
            }
        }

        return $this->castTypes($model, $data);
    }

    /**
     * Decrypts every field the schema marks with settings.hash.
     */
    private function decryptRecord(array $model, array &$record): void
    {
        foreach ($model['fields'] as $field) {
            $name = $field['field'] ?? null;
            $hash = $field['settings']['hash'] ?? null;

            if ($hash === null || $name === null || !isset($record[$name])) continue;

            $record[$name] = $hash[0] === '~'
                ? _uho_fx::decrypt($record[$name], $this->context->getKeys(), substr($hash, 1))
                : _uho_fx::decrypt($record[$name], $this->context->getKeys(), $hash);
        }
    }

    /**
     * source.fields entries ending with '#' name encrypted columns. The marker
     * is stripped and the column recorded in source.fields_hashed, so the
     * source rows can be decrypted once they are read.
     */
    private function markHashedSourceFields(array &$model): void
    {
        foreach ($model['fields'] as $k => $field) {
            if (!isset($field['source']['fields']) || !is_array($field['source']['fields'])) continue;

            foreach ($field['source']['fields'] as $k2 => $column)
                if ($column !== rtrim($column, '#')) {
                    $model['fields'][$k]['source']['fields'][$k2]  = rtrim($column, '#');
                    $model['fields'][$k]['source']['fields_hashed'][] = rtrim($column, '#');
                }

            if (!empty($model['fields'][$k]['source']['fields_hashed']))
                $model['fields'][$k]['source']['fields_hashed'] =
                    array_flip($model['fields'][$k]['source']['fields_hashed']);
        }
    }

    /**
     * Dispatches one field of one record to the resolver its shape asks for.
     * $model and $data are by reference: resolvers cache source data on the
     * schema copy and write straight into the record set.
     */
    private function resolveField(array &$model, array &$data, int|string $k, array &$record, int|string $fieldKey, array $field): void
    {
        $name = $field['field'] ?? null;
        $type = $field['type'] ?? null;

        // ':lang' columns were expanded into real ones by the SELECT already
        if ($name !== null && str_contains($name, ':lang')) {
            unset($data[$k][$name]);
            return;
        }

        if ($type === 'model') {
            $data[$k][$name] = $this->resolveModelField($field, $record);
            return;
        }

        if ($name === null || empty($record[$name])) return;

        $hasSourceModel = isset($field['source']['model']);

        if ($type === 'elements' && $hasSourceModel && $this->elementsMethod === self::METHOD_ITERATE) {
            $data[$k][$name] = $this->resolveElementsIterate($field, $record);
            return;
        }

        if ($type === 'checkboxes' && $hasSourceModel && $this->checkboxesMethod === self::METHOD_ITERATE) {
            $data[$k][$name] = $this->resolveCheckboxesIterate($field, $record);
            return;
        }

        if ($type === 'checkboxes' && isset($field['options'])) {
            $data[$k][$name] = $this->resolveCheckboxesOptions($field, $record[$name]);
            return;
        }

        if ($type === 'select' && $hasSourceModel && $this->selectMethod === self::METHOD_ITERATE) {
            $data[$k][$name] = $this->resolveSelectIterate($field, $record);
            return;
        }

        if (!empty($field['source']) && in_array($type, ['elements', 'select', 'checkboxes'], true)) {
            $source = $this->sourceData($model, $data, $fieldKey, $field);
            $value  = $this->resolveFromSource($field, $source, $record[$name]);
            if ($value !== null) $data[$k][$name] = $record[$name] = $value;
            return;
        }

        if (!empty($field['source_double']) && $type === 'elements_double') {
            $data[$k][$name] = $record[$name] = $this->resolveElementsDouble($field, $record[$name]);
            return;
        }

        if (!empty($field['source_double']) && $type === 'elements_pair')
            $data[$k][$name] = $record[$name] = $this->resolveElementsPair($field, $record[$name]);
    }

    /**
     * A 'model' field holds the result of another schema's query, its filters
     * filled in from the current record.
     */
    private function resolveModelField(array $field, array $record): mixed
    {
        $settings = $field['settings'] ?? [];
        $schema   = $settings['schema'] ?? null;

        /**
         * A schema whose 'model' field points back at itself - directly or
         * through a second schema - would recurse until the process runs out of
         * memory. The chain being expanded is tracked so the cycle ends with a
         * message naming it instead of an out-of-memory abort.
         */
        $key = is_string($schema) ? $schema : json_encode($schema);

        if (isset($this->modelChain[$key]) || count($this->modelChain) >= self::MAX_MODEL_DEPTH)
            $this->context->halt(
                '_uho_orm2::model field cycle or depth limit at [' . $key
                . '] (chain: ' . implode(' -> ', array_keys($this->modelChain)) . ')'
            );

        $filters = $settings['filters'] ?? null;

        if ($filters) $filters = $this->twig->fromModel(
            _uho_fx::arrayReplace($filters, $record, '%', '%'),
            $record
        );

        $this->modelChain[$key] = true;

        try {
            return $this->orm->get([
                'schema'  => $schema,
                'filters' => $filters,
                'order'   => $settings['order'] ?? null,
                'fields'  => $settings['fields'] ?? null,
            ]);
        } finally {
            unset($this->modelChain[$key]);
        }
    }

    /**
     * Legacy per-record lookup for 'elements', ordered by the stored id order.
     */
    private function resolveElementsIterate(array $field, array $record): mixed
    {
        $ids = $this->parseIdList($record[$field['field']], $this->isStringOutput($field));

        return $this->orm->get(
            $field['source']['model'],
            ['id' => $ids],
            false,
            ['type' => 'FIELD', 'field' => 'id', 'values' => $ids],
            null,
            $this->sourceFieldsParam($field)
        );
    }

    /**
     * Legacy per-record lookup for 'checkboxes'.
     */
    private function resolveCheckboxesIterate(array $field, array $record): mixed
    {
        return $this->orm->get(
            $field['source']['model'],
            ['id' => $this->parseIdList($record[$field['field']], $this->isStringOutput($field))],
            false,
            null,
            null,
            $this->sourceFieldsParam($field)
        );
    }

    /**
     * Checkbox values against a static options list: either the raw values or
     * label/id pairs.
     */
    private function resolveCheckboxesOptions(array $field, mixed $value): array
    {
        $selected = explode(',', (string) $value);

        if (($field['settings']['output'] ?? null) === 'value') return $selected;

        $result = [];
        foreach ($field['options'] as $option)
            if (in_array($option['value'], $selected)) $result[] = ['label' => $option['label'], 'id' => $option['value']];

        return $result;
    }

    /**
     * Legacy per-record lookup for 'select'. With source.filters the whole
     * filtered set is returned, otherwise the single record the id points at.
     */
    private function resolveSelectIterate(array $field, array $record): mixed
    {
        if (isset($field['source']['filters'])) {
            $filters = _uho_fx::arrayReplace([$field['source']['filters']], $record, '%', '%')[0];

            return $this->orm->get($field['source']['model'], $filters, false, $field['source']['order'] ?? '');
        }

        return $this->orm->get($field['source']['model'], ['id' => $record[$field['field']]], true, '');
    }

    /**
     * Loads (once) every row the whole result set may point at, and caches it
     * on the schema copy as source.data.
     */
    private function sourceData(array &$model, array $data, int|string $fieldKey, array $field): array
    {
        if (!empty($field['source']['data'])) return $field['source']['data'];

        $name    = $field['field'];
        $idField = $field['source']['id'] ?? 'id';

        if (!empty($field['source']['model'])) {
            $ids = [];
            foreach ($data as $record) {
                if (!is_string($record[$name] ?? null)) continue;

                foreach (explode(',', $record[$name]) as $id) {
                    if (!$this->isStringOutput($field)) $id = intval($id);
                    if (!in_array($id, $ids, true)) $ids[] = $id;
                }
            }

            $rows = $this->orm->get(
                $field['source']['model'],
                [$idField => $ids],
                false,
                null,
                null,
                $this->sourceFieldsParam($field)
            );

            $source = [];
            foreach ($rows as $row) $source[$row[$idField]] = $row;

            if (isset($field['source']['field']))
                foreach ($source as $id => $row) $source[$id] = $row[$field['source']['field']];
        } else {
            if (empty($field['source']['fields']))
                $this->context->halt('_uho_orm2::no source fields for ' . $name);

            $source = $this->runner->query(
                'SELECT id,' . implode(',', $field['source']['fields']) . ' FROM ' . $field['source']['table'],
                false,
                false,
                'id'
            ) ?? [];
        }

        if (!empty($field['source']['fields_hashed']))
            foreach ($source as $id => $row)
                foreach ((array) $row as $column => $value)
                    if (isset($field['source']['fields_hashed'][$column]))
                        $source[$id][$column] = _uho_fx::decrypt(
                            $value,
                            $this->context->getKeys(),
                            $field['source']['fields_hashed'][$column]
                        );

        if (!empty($field['source']['url']))
            foreach ($source as $id => $row)
                $source[$id]['url'] = $this->twig->template($field['source']['url'], $row);

        $model['fields'][$fieldKey]['source']['data'] = $source;

        return $source;
    }

    /**
     * Maps a stored id (select) or id list (elements/checkboxes) onto the
     * resolved source rows. Returns null when there is nothing to map.
     */
    private function resolveFromSource(array $field, array $source, mixed $stored): mixed
    {
        if (($field['type'] ?? null) === 'select') {
            $id = is_numeric($stored) ? intval($stored) : $stored;

            return $source[$id] ?? null;
        }

        $isString = $this->isStringOutput($field);
        $elements = explode(',', (string) $stored);

        foreach ($elements as $k => $element) {
            if (!intval($element) && !($isString && $element !== '')) {
                unset($elements[$k]);
                continue;
            }

            $parts = explode(':', $element);
            $id    = $parts[1] ?? $parts[0];

            if ($isString) $elements[$k] = $source[$id] ?? null;
            elseif (isset($source[intval($id)])) $elements[$k] = $source[intval($id)];
            else unset($elements[$k]);
        }

        return $elements;
    }

    /**
     * elements_double stores 'slug:id' pairs spread over several source models
     * or tables; every pair is resolved and tagged with where it came from.
     */
    private function resolveElementsDouble(array $field, mixed $stored): array
    {
        $elements = explode(',', (string) $stored);
        $wanted   = $this->groupIdsBySlug($elements);
        [$sources, $models] = $this->loadDoubleSources($field['source_double'], $wanted);

        $result = [];

        foreach ($elements as $element) {
            $parts = explode(':', $element);
            $slug  = $this->elementsDoubleFirstInteger ? intval($parts[0]) : $parts[0];
            $id    = intval($parts[1] ?? 0);

            $row = $sources[$parts[0]][$id] ?? null;
            if ($row === null) continue;

            $definition = _uho_fx::array_filter($field['source_double'], 'slug', $parts[0], ['first' => true]);

            if (isset($definition['label'])) $row['label'] = $this->twig->template($definition['label'], $row);
            if (isset($definition['image'])) $row['image'] = $this->twig->template($definition['image'], $row);

            // a definition without its own table falls back to the definition
            // itself, the way the legacy reader left it
            $row['_table'] = $definition['table'] ?? $definition;
            $row['_slug']  = $slug;
            $row['_model'] = $models[$slug] ?? null;

            $result[] = $row;
        }

        return $result;
    }

    /**
     * elements_pair is elements_double grouped into ';' separated sections.
     */
    private function resolveElementsPair(array $field, mixed $stored): array
    {
        $sections = explode(';', (string) $stored);

        $wanted = [];
        foreach ($sections as $section)
            $wanted = array_merge_recursive($wanted, $this->groupIdsBySlug(explode(',', $section)));

        [$sources] = $this->loadDoubleSources($field['source_double'], $wanted);

        $result = [];

        foreach ($sections as $k => $section) {
            $result[$k] = [];

            foreach (explode(',', $section) as $element) {
                $parts = explode(':', $element);
                $id    = intval($parts[1] ?? 0);

                $row = $sources[$parts[0]][$id] ?? null;
                if ($row === null) continue;

                $definition = _uho_fx::array_filter($field['source_double'], 'slug', $parts[0], ['first' => true]);

                if (!empty($definition['label'])) $row['label'] = $this->twig->template($definition['label'], $row);
                if (!empty($definition['image'])) $row['image'] = $this->twig->template($definition['image'], $row);

                $row['_table'] = $definition['table'] ?? null;
                $row['_slug']  = intval($parts[0]);

                $result[$k][] = $row;
            }
        }

        return $result;
    }

    /**
     * 'slug:id,slug:id' -> [slug => [id, id]]
     *
     * @param array<int, string> $elements
     * @return array<string, array<int, int>>
     */
    private function groupIdsBySlug(array $elements): array
    {
        $wanted = [];

        foreach ($elements as $element) {
            $parts = explode(':', $element);
            if (!isset($parts[1])) continue;

            $wanted[$parts[0]][] = intval($parts[1]);
        }

        return $wanted;
    }

    /**
     * Reads the rows every source_double definition contributes, keyed by id.
     *
     * @param array<string, array<int, int>> $wanted
     * @return array{0: array<string, array>, 1: array<string, string>}
     */
    private function loadDoubleSources(array $definitions, array $wanted): array
    {
        $sources = [];
        $models  = [];

        foreach ($definitions as $definition) {
            $slug = $definition['slug'];

            if (!empty($definition['model'])) {
                $models[$slug] = $definition['model'];

                $rows = empty($wanted[$slug])
                    ? []
                    : $this->orm->get($definition['model'], ['id' => $wanted[$slug]]);

                $sources[$slug] = [];
                foreach ($rows as $row) $sources[$slug][$row['id']] = $row;

                continue;
            }

            if (empty($wanted[$slug])) {
                $sources[$slug] = [];
                continue;
            }

            $sources[$slug] = $this->runner->query(
                'SELECT id,' . implode(',', $definition['fields']) . ' FROM ' . $definition['table']
                    . ' WHERE id=' . implode(' || id=', array_map('intval', $wanted[$slug])),
                false,
                false,
                'id'
            ) ?? [];
        }

        return [$sources, $models];
    }

    /**
     * Casts values to the type the schema declares, once every source lookup
     * has run.
     */
    private function castTypes(array $model, array $data): array
    {
        $hasIdField = _uho_fx::array_filter($model['fields'], 'field', 'id', ['first' => true]);
        $hasIdType  = _uho_fx::array_filter($model['fields'], 'type', 'id', ['first' => true]);

        if (!$hasIdField && !$hasIdType) $model['fields'][] = ['type' => 'integer', 'field' => 'id'];

        foreach ($data as $k => $record)
            foreach ($model['fields'] as $field) {
                $name = $field['field'] ?? null;
                if ($name === null || !isset($record[$name])) continue;

                switch ($field['type'] ?? '') {
                    case 'elements':
                    case 'checkboxes':
                        if (empty($data[$k][$name])) $data[$k][$name] = [];
                        break;
                    case 'integer':
                    case 'order':
                        $data[$k][$name] = intval($data[$k][$name]);
                        break;
                    case 'float':
                        $data[$k][$name] = floatval($data[$k][$name]);
                        break;
                    case 'json':
                    case 'blocks':
                        $bare = $this->bareFieldName($name);
                        $data[$k][$bare] = json_decode((string) ($record[$bare] ?? ''), true);
                        break;
                }
            }

        return $data;
    }

    /**
     * Applies the per-type value conversions a field can ask for: nl2br
     * splitting, timezone conversion, JSON tables.
     */
    public function fieldValue(string $type, mixed $value, ?array $field = null): mixed
    {
        if (!$value) return $value;

        switch ($type) {

            case 'text':
                if (($field['settings']['function'] ?? null) === 'nl2br')
                    $value = explode(chr(13) . chr(10), (string) $value);
                break;

            case 'timestamp':
                $value = (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
                break;

            case 'datetime':
                $value = $this->formatDatetime($value, $field['settings']['format'] ?? null);
                break;

            case 'table':
                if (is_string($value)) $value = $this->decodeTable($value, $field);
                break;
        }

        return $value;
    }

    private function formatDatetime(mixed $value, ?string $format): mixed
    {
        switch ($format) {
            case 'USER':
                return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');

            case 'ISO8601':
            case 'UTC':
                try {
                    return (new \DateTime((string) $value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                } catch (\Exception) {
                    return null;
                }
        }

        return $value;
    }

    /**
     * A 'table' column holds JSON: either a list of rows to be keyed by the
     * declared header, or an object to be turned into [key, value] pairs.
     */
    private function decodeTable(string $value, ?array $field): mixed
    {
        $decoded = json_decode($value, true);
        if (!$decoded) return $decoded;

        if (!empty($field['settings']['fields']) && !empty($field['settings']['header'])) {
            $rows = [];
            foreach ($decoded as $row) {
                $named = [];
                foreach ($field['settings']['header'] as $index => $column) $named[$column['field']] = $row[$index];
                $rows[] = $named;
            }
            $decoded = $rows;
        }

        if (($field['settings']['format'] ?? null) === 'object') {
            $pairs = [];
            foreach ($decoded as $key => $value2) $pairs[] = [$key, $value2];
            $decoded = $pairs;
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Pass 2 - media
    // -------------------------------------------------------------------------

    /**
     * Fills in the fields that have no column of their own: the media paths
     * derived from the schema, virtual (Twig) values, and attached media rows.
     *
     * @param array $fieldsAuto the file/image/video/audio/media/virtual/plugin
     *                          fields collected while building the SELECT
     */
    public function updateMedia(array $model, array $data, array $fieldsAuto, array $params = []): array
    {
        foreach ($data as $k => $record)
            foreach ($fieldsAuto as $field)
                switch ($field['type'] ?? '') {
                    case 'file':
                    case 'audio':
                    case 'video':
                        $data[$k][$field['field']] = $this->buildFile($field, $record);
                        break;

                    case 'image_media':
                    case 'image':
                        $data[$k][$field['field']] = $this->buildImages($field, $record, $data[$k]);
                        break;

                    case 'virtual':
                        if (!empty($field['value']) && isset($field['field']))
                            $data[$k][$field['field']] = $this->twig->fromHtml($field['value'], $data[$k]);
                        break;

                    case 'media':
                        $data[$k][$field['field']] = $this->buildMedia($model, $field, $record, $params);
                        break;
                }

        // a video may take its poster from another field of the same record
        foreach ($data as $k => $record)
            foreach ($model['fields'] as $field)
                if (($field['type'] ?? null) === 'video' && !empty($field['poster']) && !empty($record[$field['poster']]))
                    $data[$k][$field['field']]['poster'] = $record[$field['poster']];

        return $data;
    }

    /**
     * ['src' => ...] for a single file, plus a poster when the field declares
     * image variants.
     */
    private function buildFile(array $field, array $record): ?array
    {
        $settings = $field['settings'] ?? [];

        if (!empty($settings['field_exists']) && empty($record[$settings['field_exists']])) return null;

        // {"type":"original","field":"filename"} - name taken from a column
        if (is_array($settings['filename'] ?? null) && ($settings['filename']['type'] ?? null) === 'original')
            $settings['filename'] = $record[$settings['filename']['field']];

        if (empty($settings['filename'])) $settings['filename'] = '%uid%.%extension%';
        elseif (!strpos($settings['filename'], '.') && !empty($settings['extension']) && !is_array($settings['extension']))
            $settings['filename'] .= '.' . $settings['extension'];

        // Twig first, on the pattern as the schema wrote it, then the
        // %placeholder% pass - so a record value carrying '{{ ... }}' is
        // inserted into the finished name instead of being compiled
        $settings['filename'] = $this->twig->fromHtml($settings['filename'], $record);
        $settings['folder']   = $this->twig->fromHtml($settings['folder'] ?? '', $record);

        foreach ($record as $column => $value)
            if (is_string($value)) $settings['filename'] = str_replace('%' . $column . '%', $value, $settings['filename']);

        if (isset($settings['extension_field'])) $settings['extension'] = $record[$settings['extension_field']];

        if (!empty($settings['extension']) && !is_array($settings['extension']))
            $settings['filename'] = str_replace('%extension%', $settings['extension'], $settings['filename']);

        $src = $settings['folder'] . '/' . $settings['filename'];
        $this->files->addCacheBuster($src);

        $result = ['src' => $src];

        if (!empty($field['images'][1]['folder'])) {
            $bare   = explode('.', (string) $settings['filename']);
            array_pop($bare);
            $poster = $settings['folder'] . '/' . $field['images'][1]['folder'] . '/' . implode('.', $bare) . '.jpg';

            $this->files->addCacheBuster($poster);
            if ($poster !== '') $result['poster'] = $poster;
        }

        return $result;
    }

    /**
     * One entry per declared image variant (and per retina and webp variant),
     * keyed by images[].id or the variant folder.
     */
    private function buildImages(array $field, array $record, array $mapped): ?array
    {
        $settings = $field['settings'] ?? [];

        if (!empty($settings['field_exists']) && empty($record[$settings['field_exists']])) return null;

        $variants  = $this->expandRetina($field['images'] ?? []);
        $extension = $this->imageExtension($settings, $record);
        $folder    = $this->twig->template($settings['folder'] ?? '', $record, true);

        $sizes = [];
        if ($this->files->imageSizesEnabled() && !empty($settings['sizes'])) {
            $sizes = $mapped[$settings['sizes']] ?? [];
            if (is_string($sizes)) $sizes = json_decode($sizes, true) ?? [];
        }

        $result = [];

        foreach ($variants as $variant) {
            $filename = isset($variant['filename'])
                ? $this->twig->template($variant['filename'], $record, true)
                : (isset($settings['filename'])
                    ? $this->twig->template($settings['filename'], $record, true)
                    : $this->twig->template('%uid%', $record));

            $id  = $variant['id'] ?? $variant['folder'];
            $src = str_replace('//', '/', $folder . '/' . $variant['folder'] . '/' . $filename . '.' . $extension);

            $result[$id] = $this->decorateImage($src, $variant, $field, $settings, $sizes, $id);

            if (!empty($settings['webp']) && $id !== 'original') {
                $webp = $folder . '/' . $variant['folder'] . '/' . $filename . '.webp';
                $result[$id . '_webp'] = $this->decorateImage($webp, $variant, $field, $settings, $sizes, $id, true);
            }
        }

        return $result;
    }

    /**
     * Adds the cache buster, the measured size or the external server to one
     * image path, and folds in a stored size when there is one.
     */
    private function decorateImage(
        string $src,
        array $variant,
        array $field,
        array $settings,
        array $sizes,
        string $id,
        bool $webp = false
    ): string|array {
        $measure = isset($variant['size']) || (!$webp && ($settings['sizes'] ?? null) === true);

        if ($measure) {
            $result = $src;
            $this->files->addImageSize($result);
        } elseif (!$webp && isset($field['server'])) {
            $result = $src;
            $this->files->addServer($result, $field['server']);
        } else {
            $result = $src;
            $this->files->addCacheBuster($result);
        }

        if (!$sizes) return $result;

        $result = ['src' => is_array($result) ? ($result['src'] ?? '') : $result];

        if (!empty($sizes[$id])) {
            $result['width']  = $sizes[$id][0];
            $result['height'] = $sizes[$id][1];
        }

        return $result;
    }

    /**
     * images[].retina turns into extra variants at twice the size, living in
     * a '_x2' folder.
     */
    private function expandRetina(array $variants): array
    {
        foreach ($variants as $k => $variant)
            if (($variant['retina'] ?? null) === true)
                $variants[$k]['retina'] = [[
                    'count'  => 2,
                    'label'  => ($variant['label'] ?? '') . '_x2',
                    'folder' => $variant['folder'] . '_x2',
                ]];

        foreach ($variants as $k => $variant)
            if (!empty($variant['retina'])) {
                foreach ($variant['retina'] as $retina) $variants[] = $retina;
                unset($variants[$k]['retina']);
            }

        return $variants;
    }

    private function imageExtension(array $settings, array $record): string
    {
        if (!empty($settings['extension_field'])) return (string) $record[$settings['extension_field']];
        if (!empty($settings['extensions']) && count($settings['extensions']) === 1) return (string) $settings['extensions'][0];

        return 'jpg';
    }

    /**
     * A 'media' field holds the rows of a separate media schema pointing back
     * at this record.
     */
    private function buildMedia(array $model, array $field, array $record, array $params): array
    {
        $mediaModel = $field['source']['model'] ?? null;
        if (!$mediaModel)
            $this->context->halt('_uho_orm2::no source model defined for: '
                . ($model['model_name'] ?? $model['table'] ?? '?') . '::' . $field['field']);

        $modelName = $model['model_name'] ?? null;

        if (!empty($params['media_model_name'])) {
            $override = _uho_fx::array_filter($params['media_model_name'], 'field', $field['field'], ['first' => true]);
            if ($override) $modelName = $override['model_name'];
        }

        $filters = [
            'model'    => $modelName . ($field['media']['suffix'] ?? ''),
            'model_id' => $record['id'],
        ];

        if (isset($field['source']['filters'])) $filters = array_merge($filters, $field['source']['filters']);

        $media = $this->orm->get($mediaModel, $filters, false, 'model_id_order');

        foreach ($media as $k => $item) {
            unset($media[$k]['date'], $media[$k]['model'], $media[$k]['model_id'], $media[$k]['model_id_order']);

            if (($item['type'] ?? null) === 'file' && empty($media[$k]['extension'])) {
                $extension = explode('?', (string) ($item['file']['src'] ?? ''))[0];
                $extension = explode('.', $extension);
                $media[$k]['extension'] = array_pop($extension);
            }
        }

        return $media;
    }

    // -------------------------------------------------------------------------
    // Pass 3 - blocks
    // -------------------------------------------------------------------------

    /**
     * Decodes 'blocks' fields that ask for it in settings.decode.
     */
    public function updateBlocks(array $model, array $data): array
    {
        foreach ($data as $k => $record)
            foreach ($model['fields'] as $field) {
                if (($field['type'] ?? null) !== 'blocks' || empty($field['settings']['decode'])) continue;

                $name  = $field['field'] ?? null;
                $media = empty($field['settings']['media']) ? null : ($record[$field['settings']['media']] ?? null);

                $data[$k][$name] = $this->decodeBlocks($data[$k][$name] ?? [], $media);
            }

        return $data;
    }

    /**
     * Only editor.js payloads are recognised; anything else passes through.
     */
    private function decodeBlocks(mixed $source, mixed $media): mixed
    {
        if (empty($source)) return $source;
        if (!isset($source['time']) || !is_array($source['blocks'] ?? null)) return $source;

        return $this->decodeEditorJs($source['blocks'], $media);
    }

    /**
     * Renders the text blocks to HTML, joins consecutive HTML blocks into one,
     * and swaps editor.js file references for the record's media entries.
     */
    private function decodeEditorJs(array $blocks, mixed $media): array
    {
        foreach ($blocks as $k => $block) {
            $html = $this->blockToHtml($block);

            if ($html !== null)
                $blocks[$k] = ['id' => $block['id'] ?? null, 'type' => 'html', 'data' => ['html' => $html]];
        }

        foreach ($blocks as $k => $block)
            if ($k > 0 && $block['type'] === 'html' && ($blocks[$k - 1]['type'] ?? null) === 'html') {
                $blocks[$k]['data']['html'] = $blocks[$k - 1]['data']['html'] . $blocks[$k]['data']['html'];
                unset($blocks[$k - 1]);
            }

        $blocks = array_values($blocks);

        foreach ($blocks as $k => $block)
            switch ($block['type']) {
                case 'image':
                    $item = $this->findMedia($media, $block['data']['file']['url'] ?? '');

                    if ($item) $blocks[$k] = [
                        'type' => 'image',
                        'data' => ['caption' => $block['data']['caption'] ?? '', 'image' => $item['image']],
                    ];
                    else unset($blocks[$k]);

                    break;

                case 'carousel':
                    $items = [];

                    foreach ($block['data'] as $entry) {
                        $item = $this->findMedia($media, $entry['url'] ?? '');
                        if ($item) $items[] = ['type' => 'media', 'image' => $item['image'], 'caption' => $entry['caption'] ?? ''];
                    }

                    if ($items) $blocks[$k] = ['type' => 'gallery', 'data' => ['items' => $items]];
                    else unset($blocks[$k]);

                    break;
            }

        return array_values($blocks);
    }

    private function blockToHtml(array $block): ?string
    {
        switch ($block['type'] ?? '') {
            case 'paragraph':
                return '<p>' . $block['data']['text'] . '</p>';

            case 'header':
                $level = intval($block['data']['level']);
                return '<h' . $level . '>' . $block['data']['text'] . '</h' . $level . '>';

            case 'list':
                $tag  = ($block['data']['style'] ?? null) === 'ordered' ? 'ol' : 'ul';
                $html = '<' . $tag . '>';
                foreach ($block['data']['items'] as $item) $html .= '<li>' . $item . '</li>';

                return $html . '</' . $tag . '>';
        }

        return null;
    }

    /**
     * editor.js stores an uploaded file by url; the uid in its basename is
     * what links it to a media record.
     */
    private function findMedia(mixed $media, string $url): mixed
    {
        if (!$media || $url === '') return null;

        $uid = explode('.', basename($url))[0];

        return _uho_fx::array_filter($media, 'uid', $uid, ['first' => true]);
    }

    // -------------------------------------------------------------------------
    // Pass 4 - urls
    // -------------------------------------------------------------------------

    /**
     * Fills the schema's url pattern for every record, so the Router can turn
     * it into a final address. Both '%field%' and Twig markers are supported;
     * url.twig = false switches the Twig pass off.
     */
    public function updateUrls(array $urlSchema, array $records, array $additionalParams): array
    {
        $twigEnabled = ($urlSchema['twig'] ?? null) !== false;

        foreach ($records as $k => $record) {
            $values = $additionalParams ? $record + $additionalParams : $record;

            $records[$k]['url'] = $urlSchema;

            foreach ($urlSchema as $part => $pattern) {
                if (!is_string($pattern)) continue;

                // the schema pattern is compiled first, with the record as
                // context; only then are '%field%' placeholders filled in, so
                // record text never becomes part of the template
                if ($twigEnabled) $pattern = $this->twig->fromHtml($pattern, $values) ?? '';

                $records[$k]['url'][$part] = $this->fillUrlPattern($pattern, $values);
            }
        }

        return $records;
    }

    /**
     * Replaces '%field%' and '%field.sub%' with values from the record.
     */
    private function fillUrlPattern(string $pattern, array $values): string
    {
        while (($i = strpos($pattern, '%')) !== false) {
            $j = strpos($pattern, '%', $i + 1);
            if ($j === false) $j = strlen($pattern) - 1;

            $path  = explode('.', substr($pattern, $i + 1, $j - $i - 1));
            $value = $values;

            foreach ($path as $step) $value = is_array($value) ? ($value[$step] ?? null) : null;

            $pattern = substr($pattern, 0, $i) . (is_scalar($value) ? $value : '') . substr($pattern, $j + 1);
        }

        return $pattern;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * 'title:lang' -> 'title'; the language column was aliased back to the
     * bare name by the SELECT.
     */
    private function bareFieldName(?string $field): ?string
    {
        if ($field === null) return null;

        return str_contains($field, ':lang') ? explode(':', $field)[0] : $field;
    }

    private function isStringOutput(array $field): bool
    {
        return ($field['settings']['output'] ?? null) === 'string';
    }

    /**
     * 'a:1,b:2' or '1,2' -> the id list, ints unless the field stores strings.
     *
     * @return array<int, int|string>
     */
    private function parseIdList(mixed $stored, bool $asString): array
    {
        $ids = [];

        foreach (explode(',', (string) $stored) as $k => $element) {
            $parts = explode(':', $element);
            $id    = $parts[1] ?? $parts[0];

            $ids[$k] = $asString ? $id : intval($id);
        }

        return $ids;
    }

    /**
     * source.model_fields limits which columns the source query reads.
     */
    private function sourceFieldsParam(array $field): array
    {
        return empty($field['source']['model_fields']) ? [] : ['fields' => $field['source']['model_fields']];
    }
}
