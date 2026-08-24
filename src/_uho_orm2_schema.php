<?php

declare(strict_types=1);

namespace Huncwot\UhoFramework;

/**
 * Loads a schema and normalises it into the shape the rest of the ORM expects.
 *
 * A schema on disk is written for a human: fields may omit their type, media
 * settings may sit at the top level of a field rather than under 'settings',
 * options may be a plain key => label map, and several files may be composed
 * into one model. getSchema() resolves all of that once, so every consumer
 * downstream sees the same canonical structure.
 */
class _uho_orm2_schema
{
    /** field types whose deprecated media properties migrate into settings */
    private const MEDIA_TYPES = ['file', 'audio', 'video', 'image'];

    /** deprecated field properties, moved verbatim under settings */
    private const MIGRATED_PROPERTIES = [
        'folder',
        'folder_audio',
        'folder_video',
        'extension',
        'extensions',
        'extension_field',
    ];

    public function __construct(
        private _uho_orm2 $orm,
        private _uho_orm2_schema_loader $loader
    ) {}

    // -------------------------------------------------------------------------
    // Loading
    // -------------------------------------------------------------------------

    /**
     * Loads a model schema.
     *
     * @param string|array $name  model name, an already-loaded schema, or a
     *                            list of names to compose into one model
     * @param bool $lang          expand ':lang' fields into one field per language
     * @param array $params       'return_error' => true reports a missing schema
     *                            as ['result' => false, 'message' => ...] instead
     *                            of halting
     */
    public function getSchema(string|array $name, bool $lang = false, array $params = []): ?array
    {
        if (!$name) $this->orm->halt('_uho_orm2_schema::getSchema::no model name specified');

        // already a schema
        if (is_array($name) && isset($name['table'])) return $name;

        $model = is_array($name)
            ? $this->loadComposed($name)
            : $this->loadSingle($name, $params);

        // a reported error, not a schema
        if (isset($model['result']) && $model['result'] === false) return $model;
        if (!$model) return $model;

        $model = $this->normalizeOrder($model);
        $model = $this->normalizeChildren($model);
        $model = $this->applyFieldDefaults($model);
        if ($lang) $model = $this->expandLanguageFields($model);
        $model = $this->applyFieldIncludes($model);
        $model = $this->normalizeFields($model);
        $model = $this->migrateDeprecatedProperties($model);

        return $this->reposition($model);
    }

    private function loadSingle(string $name, array $params): ?array
    {
        $filename = $name . '.json';
        $model    = $this->loader->loadJsonSchema($filename);

        if ($model && !isset($model['model_name'])) $model['model_name'] = $name;

        if (!$model && isset($params['return_error'])) {
            $message = '_uho_orm2::JSON schema not found: ' . $filename;

            if ($this->orm->isDebug())
                $message .= ' in ' . implode(', ', $this->loader->getRootPaths())
                    . ' ::: ' . $this->loader->getLastError();

            return ['result' => false, 'message' => $message];
        }

        return $model;
    }

    /**
     * Composes several schema files into one model. Later files override
     * fields of the same name; an entry may declare where its fields go with
     * ['model' => ..., 'position_after' => 'other_field'].
     *
     * Every field contributed by a file after the first records where it came
     * from in '_original_models', which the CMS uses to write back to the
     * right file.
     */
    private function loadComposed(array $names): ?array
    {
        $model = [];

        foreach ($names as $index => $entry) {
            if (!$entry) continue;

            $name          = is_array($entry) ? $entry['model'] : $entry;
            $positionAfter = is_array($entry) ? ($entry['position_after'] ?? null) : null;

            if (is_array($name)) $this->orm->halt('_uho_orm2_schema::getSchema::model as array');

            $loaded = $this->loader->loadJsonSchema($name);
            if (!$loaded) continue;

            if ($index > 0 && isset($loaded['fields']))
                foreach ($loaded['fields'] as $k => $_)
                    $loaded['fields'][$k]['_original_models'][] = $name;

            if (!$model) $model = $loaded;
            else $model = $this->mergeSchema($model, $loaded, $positionAfter);

            if ($model && !isset($model['model_name'])) $model['model_name'] = $name;
        }

        return $model ?: null;
    }

    /**
     * Merges one loaded file into the model being composed.
     */
    private function mergeSchema(array $model, array $loaded, ?string $positionAfter): array
    {
        foreach ($loaded as $section => $entries) {
            if (!isset($model[$section]) || !is_array($model[$section]) || !is_array($entries)) {
                $model[$section] = $entries;
                continue;
            }

            // drop the fields this file redefines, carrying their origin over
            foreach ($entries as $k => $entry) {
                if (empty($entry['field'])) continue;

                $exists = _uho_fx::array_filter($model[$section], 'field', $entry['field'], ['first' => true, 'keys' => true]);
                if ($exists === false || $exists === null) continue;

                if (isset($model[$section][$exists]['_original_models']))
                    $entries[$k]['_original_models'] = array_merge(
                        $entries[$k]['_original_models'] ?? [],
                        $model[$section][$exists]['_original_models']
                    );

                unset($model[$section][$exists]);
            }

            if ($section === 'fields' && $positionAfter) {
                $at = _uho_fx::array_filter($model[$section], 'field', $positionAfter, ['first' => true, 'keys' => true]);

                if ($at !== false && $at !== null) {
                    $model[$section] = array_merge(
                        array_slice($model[$section], 0, $at + 1),
                        $entries,
                        array_slice($model[$section], $at + 1)
                    );
                    continue;
                }
            }

            $model[$section] = array_merge($model[$section], $entries);
        }

        return $model;
    }

    // -------------------------------------------------------------------------
    // Normalisation
    // -------------------------------------------------------------------------

    /**
     * 'title', '!title' or 'title DESC' -> ['field' => 'title', 'sort' => ...]
     */
    private function normalizeOrder(array $model): array
    {
        if (!isset($model['order']) || !is_string($model['order'])) return $model;

        $order = trim($model['order']);
        if ($order === '') return $model;

        if (preg_match('/\s+(ASC|DESC)$/i', $order, $matches)) {
            $sort  = strtoupper($matches[1]);
            $field = trim((string) preg_replace('/\s+(ASC|DESC)$/i', '', $order));
        } elseif ($order[0] === '!') {
            $sort  = 'DESC';
            $field = substr($order, 1);
        } else {
            $sort  = 'ASC';
            $field = $order;
        }

        $model['order'] = ['field' => $field, 'sort' => $sort];

        return $model;
    }

    /**
     * A child needs a schema and a parent column; the joining column defaults
     * to 'id'. Incomplete declarations are dropped.
     */
    private function normalizeChildren(array $model): array
    {
        if (!isset($model['children'])) return $model;

        foreach ($model['children'] as $k => $child)
            if (isset($child['schema'], $child['parent'])) {
                if (empty($child['id'])) $model['children'][$k]['id'] = 'id';
            } else unset($model['children'][$k]);

        return $model;
    }

    /**
     * An untyped field is an integer when it is the id, a string otherwise;
     * strings get the default column length.
     */
    private function applyFieldDefaults(array $model): array
    {
        if (empty($model['fields']) || !is_array($model['fields'])) return $model;

        foreach ($model['fields'] as $k => $field) {
            if (!isset($field['type']))
                $model['fields'][$k]['type'] = ($field['field'] ?? null) === 'id' ? 'integer' : 'string';

            if ($model['fields'][$k]['type'] === 'string' && empty($field['settings']['length']))
                $model['fields'][$k]['settings']['length'] = 255;
        }

        return $model;
    }

    /**
     * Turns each ':lang' field into one field per registered language.
     */
    private function expandLanguageFields(array $model): array
    {
        $langs = $this->orm->getLanguages();
        if (!$langs || empty($model['fields'])) return $model;

        $fields = [];

        foreach ($model['fields'] as $field)
            if (!empty($field['field']) && str_contains($field['field'], ':lang'))
                foreach ($langs as $lang) {
                    $translated          = $field;
                    $translated['field'] = str_replace(':lang', $lang['lang_add'], $field['field']);
                    $fields[]            = $translated;
                }
            else $fields[] = $field;

        $model['fields'] = $fields;

        return $model;
    }

    /**
     * A field may pull its definition from another JSON file.
     */
    private function applyFieldIncludes(array $model): array
    {
        if (empty($model['fields']) || !is_array($model['fields'])) return $model;

        foreach ($model['fields'] as $k => $field) {
            if (!isset($field['include'])) continue;

            $included = $this->loader->loadJsonSchema($field['include']);
            if (!$included) $this->orm->halt('_uho_orm2_schema::loadJsonSchema::' . $field['include']);

            $model['fields'][$k] = array_merge($field, $included);
            unset($model['fields'][$k]['include']);
        }

        return $model;
    }

    /**
     * Per-type normalisation: options maps, media variants, filename defaults.
     */
    private function normalizeFields(array $model): array
    {
        if (empty($model['fields']) || !is_array($model['fields'])) return $model;

        $needsUid = false;

        foreach ($model['fields'] as $k => $field) {
            $field = $this->normalizeOptions($field);
            $model['fields'][$k] = $field;

            switch ($field['type'] ?? '') {

                case 'checkboxes':
                    if (isset($field['settings']['output'], $field['options']) && $field['settings']['output'] === 'value')
                        foreach ($field['options'] as $k2 => $option)
                            $model['fields'][$k]['options'][$k2] = $option['value'];
                    break;

                case 'image_media':
                    $model['fields'][$k] = $this->normalizeImageMedia($field);
                    break;

                case 'image':
                    if (empty($field['filename']) && empty($field['images'][0]['filename'])) {
                        $model['fields'][$k]['filename'] = '%uid%';
                        $needsUid = true;
                    }

                    $model['fields'][$k] = $this->normalizeImage($model['fields'][$k]);
                    break;

                case 'video':
                    if (empty($field['filename'])) {
                        $model['fields'][$k]['filename'] = '%uid%';
                        $needsUid = true;
                    }

                    $model['fields'][$k]['extension'] = 'mp4';
                    $model['fields'][$k] = $this->normalizeVideo($model['fields'][$k]);
                    break;

                case 'audio':
                    if (!isset($field['filename'])) {
                        $model['fields'][$k]['filename'] = '%uid%';
                        $needsUid = true;
                    }

                    $model['fields'][$k]['extension'] = 'mp3';
                    break;
            }
        }

        // '%uid%' filenames need the column that fills them
        if ($needsUid && !_uho_fx::array_filter($model['fields'], 'field', 'uid'))
            $model['fields'][] = ['type' => 'uid', 'field' => 'uid', 'cms' => ['list' => 'read']];

        return $model;
    }

    /**
     * { "a": "Label A" } -> [ { "value": "a", "label": "Label A" } ]
     */
    private function normalizeOptions(array $field): array
    {
        if (empty($field['options']) || !is_array($field['options']) || !empty($field['options'][0])) return $field;

        foreach ($field['options'] as $value => $label) $field['options'][$value] = ['value' => $value, 'label' => $label];

        $field['options'] = array_values($field['options']);

        return $field;
    }

    /**
     * An image_media field borrows the variants of the image field declared by
     * its source model.
     */
    private function normalizeImageMedia(array $field): array
    {
        $sourceSchema = $this->getSchema($field['source']['model']);
        if (!$sourceSchema) return $field;

        $image = _uho_fx::array_filter($sourceSchema['fields'], 'type', 'image', ['first' => true]);
        if (!$image) return $field;

        $field['filename'] = str_replace('{{id}}', '{{' . $field['field'] . '}}', $image['filename'] ?? '');
        $field['folder']   = $image['folder'] ?? null;
        $field['images']   = $image['images'] ?? [];
        $field['settings']['field_exists'] = $field['field'];

        return $field;
    }

    /**
     * Prepends the untouched original to the variant list, gives every variant
     * an id, and moves filename/folder under settings.
     */
    private function normalizeImage(array $field): array
    {
        if (($field['settings']['original'] ?? null) !== false
            && (!empty($field['images'][0]['width']) || !empty($field['images'][0]['height'])))
            array_unshift($field['images'], ['folder' => 'original', 'label' => 'Original']);

        if (!empty($field['images_panorama'])
            && (!empty($field['images_panorama'][0]['width']) || !empty($field['images_panorama'][0]['height'])))
            array_unshift($field['images_panorama'], ['folder' => 'original', 'label' => 'Original']);

        foreach ($field['images'] ?? [] as $k => $variant)
            if (!isset($variant['id'])) $field['images'][$k]['id'] = $variant['folder'];

        return $this->moveToSettings($field, ['filename', 'folder']);
    }

    private function normalizeVideo(array $field): array
    {
        if (!empty($field['images'])
            && (!empty($field['images'][0]['width']) || !empty($field['images'][0]['height'])))
            array_unshift($field['images'], ['folder' => 'original', 'label' => 'Original']);

        return $this->moveToSettings($field, ['filename', 'folder', 'extension']);
    }

    /**
     * Moves top-level properties under settings, without overwriting a value
     * already set there.
     *
     * @param array<int, string> $properties
     */
    private function moveToSettings(array $field, array $properties): array
    {
        if (empty($field['settings'])) $field['settings'] = [];

        foreach ($properties as $property) {
            if (!isset($field[$property])) continue;

            if (!isset($field['settings'][$property])) $field['settings'][$property] = $field[$property];

            unset($field[$property]);
        }

        return $field;
    }

    /**
     * Remaining deprecated shapes: list = true, and media properties sitting
     * outside settings.
     */
    private function migrateDeprecatedProperties(array $model): array
    {
        if (empty($model['fields']) || !is_array($model['fields'])) return $model;

        foreach ($model['fields'] as $k => $field) {
            if (($field['list'] ?? null) === true) $model['fields'][$k]['list'] = 'show';

            if (in_array($field['type'] ?? '', self::MEDIA_TYPES, true))
                $model['fields'][$k] = $this->moveToSettings($model['fields'][$k], self::MIGRATED_PROPERTIES);
        }

        return $model;
    }

    /**
     * Applies field-level 'position_after' inside the final field list.
     */
    private function reposition(array $model): array
    {
        if (empty($model['fields']) || !is_array($model['fields'])) {
            $model['fields'] = $model['fields'] ?? [];
            return $model;
        }

        $fields = [];

        foreach ($model['fields'] as $field) {
            if (!isset($field['position_after'])) {
                $fields[] = $field;
                continue;
            }

            $at = _uho_fx::array_filter($fields, 'field', $field['position_after'], ['first' => true, 'keys' => true]);

            if ($at === false || $at === null) $fields[] = $field;
            else $fields = array_merge(array_slice($fields, 0, $at + 1), [$field], array_slice($fields, $at + 1));
        }

        $model['fields'] = $fields;

        return $model;
    }

    // -------------------------------------------------------------------------
    // Schema variants
    // -------------------------------------------------------------------------

    /**
     * Loads a schema that declares 'schema_update': the option values of its
     * fields name further schema files, which are composed on top of it.
     */
    public function getSchemaWithPageUpdate(string|array $name, bool $lang = false): ?array
    {
        $schema = $this->getSchema($name, $lang);
        if (!isset($schema['schema_update'])) return $schema;

        $update  = is_array($schema['schema_update']) ? $schema['schema_update'] : ['file' => $schema['schema_update']];
        $pattern = $update['file'];

        $models = [];
        $filled = $this->updateSchemaSources($schema);

        foreach ($filled['fields'] as $field) {
            if (!isset($field['options'])) continue;

            foreach ($field['options'] as $option) {
                if (!$option) continue;

                $option[$field['field']] = $option['values'] ?? null;

                $candidate = $this->orm->getTwigFromHtml($pattern, $option);
                if ($candidate !== $pattern) $models[] = $candidate;
            }
        }

        return $models ? $this->getSchema(array_merge([$name], $models), $lang) : $schema;
    }

    /**
     * Expands ':lang' fields into one field per language, for callers that
     * loaded the schema without $lang.
     */
    public function updateSchemaLanguages(array $schema): array
    {
        return $this->expandLanguageFields($schema);
    }

    // -------------------------------------------------------------------------
    // Sources
    // -------------------------------------------------------------------------

    /**
     * Fills every source-driven field with the options it can offer: rows of
     * the source model, an enum column's values, or a normalised static list.
     *
     * @param array|null $record  record used to fill dynamic source filters
     * @param array|null $params  extra literal replacements for those filters
     */
    public function updateSchemaSources(array $schema, ?array $record = null, ?array $params = null): array
    {
        $schema = $this->inheritSourceSettings($schema);

        foreach ($schema['fields'] as $k => $field) {

            if (!empty($field['source']) && empty($field['options']) && ($field['cms']['input'] ?? null) !== 'search') {
                $schema['fields'][$k]['options'] = $this->sourceOptions($field, $record, $params);
                continue;
            }

            if (in_array($field['type'] ?? '', ['select', 'checkboxes'], true)
                && empty($field['source']) && empty($field['options'])) {
                $enum = $this->enumOptions($schema['table'], $field['field']);
                if ($enum) $schema['fields'][$k]['options'] = $enum;
                continue;
            }

            if (!empty($field['options']))
                foreach ($field['options'] as $k2 => $option)
                    if (is_string($option))
                        $schema['fields'][$k]['options'][$k2] = ($field['settings']['output'] ?? null) === 'id'
                            ? $option
                            : ['value' => $option, 'label' => $option];
        }

        return $schema;
    }

    /**
     * A source model may publish its own output settings (label pattern, order,
     * id column); they fill in what the referring field does not state.
     */
    private function inheritSourceSettings(array $schema): array
    {
        foreach ($schema['fields'] as $k => $field) {
            if (!isset($field['source']['model'])) continue;

            $sourceSchema = $this->getSchema($field['source']['model']);
            if (!$sourceSchema) continue;

            // 'model' is the deprecated spelling of cms.output
            foreach (array_merge($sourceSchema['model'] ?? [], $sourceSchema['cms']['output'] ?? []) as $key => $value)
                if (!isset($field['source'][$key])) $schema['fields'][$k]['source'][$key] = $value;
        }

        return $schema;
    }

    /**
     * Reads the rows a source-driven field can offer and shapes them into
     * ['value' =>, 'label' =>, 'values' =>] options.
     */
    private function sourceOptions(array $field, ?array $record, ?array $params): array
    {
        $source  = $field['source'];
        $filters = $source['filters'] ?? null;

        // filters may be Twig patterns evaluated against the edited record
        if ($filters && $record)
            foreach ($filters as $k => $filter) {
                $filters[$k] = $this->orm->getTwigFromHtml($filter, $record);

                if ($params)
                    foreach ($params as $search => $replace) $filters[$k] = str_replace($search, $replace, $filters[$k]);
            }

        if (!empty($source['model'])) {
            $params0 = empty($source['model_fields']) ? [] : ['fields' => $source['model_fields']];
            $params0['use_cms_order'] = true;

            $rows = $this->orm->get($source['model'], $filters, false, $source['order'] ?? null, null, $params0);
        } else {
            $order = isset($source['order']) ? 'ORDER BY ' . $source['order'] : '';

            $rows = $this->orm->query(
                'SELECT id AS value,' . implode(',', $source['fields']) . ' FROM ' . $source['table'] . ' ' . $order
            ) ?? [];
        }

        $idColumn = $source['id'] ?? 'id';
        $label    = $source['label'] ?? '{{label}}';

        $options = [];

        foreach ($rows as $row) {
            if (!isset($row['value'])) $row['value'] = $row[$idColumn];

            $option = [
                'values' => $row,
                'value'  => $row['value'],
                'label'  => $this->orm->getTwigFromHtml($label, $row),
            ];

            // the second image variant is the thumbnail the CMS shows
            if (!empty($row['image']) && is_array($row['image'])) {
                $thumb = array_slice($row['image'], 1, 1);
                if ($thumb) $option['image'] = array_pop($thumb);
            }

            $options[] = $option;
        }

        return _uho_fx::array_multisort($options, $source['order'] ?? 'label');
    }

    /**
     * Options taken from an enum column, when the schema declares none.
     */
    private function enumOptions(string $table, string $column): array
    {
        $description = $this->orm->query('SHOW FIELDS FROM ' . $table . ' LIKE "' . $column . '"', true);

        if (!$description || empty($description['Type']) || substr($description['Type'], 0, 4) !== 'enum') return [];

        $values  = explode(',', substr($description['Type'], 5, strlen($description['Type']) - 6));
        $options = [];

        foreach ($values as $value) {
            $value     = trim($value, "'");
            $options[] = ['value' => $value, 'label' => $value];
        }

        return $options;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /** top-level properties a schema may declare */
    private const PROPERTIES = [
        'cms'            => ['type' => ['array']],
        'children'       => ['type' => ['array']],
        'data'           => ['type' => ['array']],
        'fields'         => ['type' => ['array']],
        'fields_to_read' => ['type' => ['array']],
        'model_name'     => ['type' => ['string']],
        'order'          => ['type' => ['array']],
        'include'        => ['type' => ['string']],
        'schema_update'  => ['type' => ['string', 'array']],
        'table'          => ['type' => ['string']],
        'url'            => ['type' => ['string', 'array']],
    ];

    /** properties the 'cms' section may declare */
    private const PROPERTIES_CMS = [
        'buttons_edit'  => ['type' => ['array']],
        'buttons_page'  => ['type' => ['array']],
        'access'        => ['type' => ['string']],
        'disable'       => ['type' => ['array']],
        'filters'       => ['type' => ['array']],
        'help'          => ['type' => ['string']],
        'helper_models' => ['type' => ['array']],
        'label'         => ['type' => ['string', 'array']],
        'layout'        => ['type' => ['array']],
        'nav'           => ['type' => ['array']],
        'order'         => ['type' => ['array', 'string']],
        'output'        => ['type' => ['array']],
        'search'        => ['type' => ['array']],
        'shortcuts'     => ['type' => ['array']],
        'sortable'      => ['type' => ['array']],
        'langs'         => ['type' => ['array']],      // added by the cms
        'structure'     => ['type' => ['array']],      // added by the cms
    ];

    /**
     * Checks a schema against the property lists above and every field against
     * schemas/_uho_orm_fields.json.
     *
     * @return array{errors: array<int, string>}
     */
    public function validateSchema(array $schema, bool $strict = false, string $method = 'uho_orm'): array
    {
        if (empty($schema)) return ['errors' => ['Schema is empty']];

        $errors = [];

        if (empty($schema['table']) && empty($schema['include']))
            $errors[] = 'Missing required property [table] or [include].';

        $errors = array_merge($errors, $this->validateProperties($schema, self::PROPERTIES, ''));
        $errors = array_merge($errors, $this->validateProperties($schema['cms'] ?? [], self::PROPERTIES_CMS, 'cms.'));

        foreach ($schema['fields'] ?? [] as $k => $field) {
            $name     = $field['field'] ?? 'nr ' . ($k + 1);
            $response = $this->validateSchemaField($field, $method);

            if ($response['errors'])
                $errors[] = 'Schema field [' . $name . '] of type [' . ($field['type'] ?? '?') . '] is invalid --> '
                    . implode(', ', $response['errors']);
        }

        return ['errors' => $errors];
    }

    /**
     * @param array<string, array{type: string|array, required?: bool}> $allowed
     * @return array<int, string>
     */
    private function validateProperties(array $section, array $allowed, string $prefix): array
    {
        $errors = [];

        foreach ($allowed as $property => $rules) {
            if (!empty($rules['required']) && !isset($section[$property])) {
                $errors[] = 'Missing required property [' . $prefix . $property . '].';
                continue;
            }

            if (!isset($section[$property])) continue;

            $expected = (array) $rules['type'];
            $actual   = gettype($section[$property]);

            if (!in_array($actual, $expected, true))
                $errors[] = 'Property [' . $prefix . $property . '] type invalid: expected '
                    . implode(' || ', $expected) . ', found ' . $actual . '.';
        }

        foreach ($section as $property => $_)
            if (!isset($allowed[$property]))
                $errors[] = ($prefix ? 'CMS Property [' . $property . ']' : 'Property [' . $property . ']') . ' unknown.';

        return $errors;
    }

    /**
     * @return array{errors: array<int, string>}
     */
    private function validateSchemaField(array $field, string $method = 'uho_orm'): array
    {
        if ($method !== 'uho_orm') return ['errors' => ['Validation method invalid']];

        $definitions = $this->fieldDefinitions();
        $type        = $field['type'] ?? null;

        if (!isset($definitions[$type]))
            return ['errors' => ['Field of type [' . $type . '] not found in schemas/_uho_orm_fields.json']];

        $result = $this->validateFieldAgainstSchema($type, $definitions[$type], $definitions['_all'], $field);

        return ['errors' => $result['result'] ? [] : [implode(', ', $result['errors'])]];
    }

    private function fieldDefinitions(): array
    {
        $raw = @file_get_contents(__DIR__ . '/../schemas/_uho_orm_fields.json');
        $definitions = $raw ? json_decode($raw, true) : null;

        if (!$definitions) $this->orm->halt('schemas/_uho_orm_fields.json not found');

        return $definitions;
    }

    /**
     * Validates one field against its type definition merged with the common
     * one.
     *
     * @return array{result: bool, errors: array<int, string>}
     */
    public function validateFieldAgainstSchema(string $fieldType, array $typeSchema, array $commonSchema, array $field): array
    {
        unset($field['field'], $field['type'], $field['cms_field'], $field['_original_models']);

        if (empty($typeSchema['allowed'])) $typeSchema['allowed'] = [];
        if (empty($typeSchema['allowed']['cms'])) $typeSchema['allowed']['cms'] = [];

        $typeSchema = $this->deepMergeProps($typeSchema, $commonSchema);

        $errors = [];

        if (isset($typeSchema['required']))
            $errors = array_merge($errors, $this->validateRequiredProperties($field, $typeSchema['required'], ''));

        if (isset($typeSchema['allowed']))
            $errors = array_merge($errors, $this->validateAllowedProperties($field, $typeSchema['allowed'], ''));

        return ['result' => empty($errors), 'errors' => $errors];
    }

    /**
     * @return array<int, string>
     */
    private function validateRequiredProperties(array $value, array $required, string $path): array
    {
        $errors = [];

        foreach ($required as $property => $rule) {
            $currentPath = $path ? $path . '.' . $property : $property;

            if (!isset($value[$property])) {
                $errors[] = 'Missing required property [' . $currentPath . ']';
                continue;
            }

            if (!is_array($rule) || !$rule) continue;

            // ["folder", "filename"] - a list of required sub-properties
            if (array_keys($rule) === range(0, count($rule) - 1) && !isset($rule['fields'])) {
                foreach ($rule as $required2)
                    if (!isset($value[$property][$required2]))
                        $errors[] = 'Missing required property [' . $currentPath . '.' . $required2 . ']';

                continue;
            }

            // { "fields": [...], "minItems": n } - requirements on array items
            if (isset($rule['fields'])) {
                if (!is_array($value[$property])) {
                    $errors[] = 'Property [' . $currentPath . '] must be an array';
                    continue;
                }

                if (isset($rule['minItems']) && count($value[$property]) < $rule['minItems'])
                    $errors[] = 'Property [' . $currentPath . '] requires at least ' . $rule['minItems'] . ' item(s)';

                foreach ($value[$property] as $index => $item)
                    foreach ($rule['fields'] as $required2)
                        if (!isset($item[$required2]))
                            $errors[] = 'Missing required property [' . $currentPath . '[' . $index . '].' . $required2 . ']';

                continue;
            }

            // a nested object
            if (!is_array($value[$property])) {
                $errors[] = 'Property [' . $currentPath . '] must be an object';
                continue;
            }

            $errors = array_merge($errors, $this->validateRequiredProperties($value[$property], $rule, $currentPath));
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function validateAllowedProperties(array $value, array $allowed, string $path): array
    {
        $errors = [];

        foreach ($value as $property => $propValue) {
            $currentPath = $path ? $path . '.' . $property : $property;

            if (!isset($allowed[$property])) {
                $errors[] = 'Property [' . $currentPath . '] is not allowed for this field type';
                continue;
            }

            $expected = $allowed[$property];

            // [ { ... } ] - an array of objects, i.e. image variants
            if (is_array($expected) && isset($expected[0]) && is_array($expected[0])) {
                if (!is_array($propValue)) {
                    $errors[] = 'Property [' . $currentPath . '] must be an array';
                    continue;
                }

                foreach ($propValue as $index => $item) {
                    if (!is_array($item)) {
                        $errors[] = 'Property [' . $currentPath . '[' . $index . ']] must be an object';
                        continue;
                    }

                    foreach ($item as $itemProperty => $itemValue) {
                        $itemPath = $currentPath . '[' . $index . '].' . $itemProperty;

                        if (!isset($expected[0][$itemProperty])) {
                            $errors[] = 'Property [' . $itemPath . '] is not allowed';
                            continue;
                        }

                        $error = $this->validatePropertyType($itemValue, $expected[0][$itemProperty], $itemPath);
                        if ($error) $errors[] = $error;
                    }
                }

                continue;
            }

            // a nested object
            if (is_array($expected) && $expected) {
                if (!is_array($propValue)) {
                    $errors[] = 'Property [' . $currentPath . '] must be an object';
                    continue;
                }

                $errors = array_merge($errors, $this->validateAllowedProperties($propValue, $expected, $currentPath));
                continue;
            }

            $error = $this->validatePropertyType($propValue, (string) $expected, $currentPath);
            if ($error) $errors[] = $error;
        }

        return $errors;
    }

    private function validatePropertyType(mixed $value, string $expectedType, string $path): ?string
    {
        $known = ['string', 'integer', 'boolean', 'array', 'double'];

        if (!in_array($expectedType, $known, true)) return null;

        $actual = gettype($value);

        return $actual === $expectedType
            ? null
            : 'Property [' . $path . '] must be ' . $expectedType . ', got ' . $actual;
    }

    /**
     * Merges the common field definition into a type definition: nested maps
     * recurse, numbers add up, anything else the common definition wins.
     */
    private function deepMergeProps(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (!array_key_exists($key, $left)) {
                $left[$key] = $value;
                continue;
            }

            if (is_array($left[$key]) && is_array($value)) {
                $left[$key] = $this->deepMergeProps($left[$key], $value);
                continue;
            }

            if (is_numeric($left[$key]) && is_numeric($value)) {
                $left[$key] = $left[$key] + $value;
                continue;
            }

            $left[$key] = $value;
        }

        return $left;
    }
}
