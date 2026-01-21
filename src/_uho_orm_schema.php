<?php

namespace Huncwot\UhoFramework;

/**
 * UHO Schema Manager
 *
 * Handles schema loading, validation, and processing for UHO ORM
 *
 * Methods:
 * - getSchema($name, $lang = false, $params = [])
 * - getSchemaWithPageUpdate($name, $lang = false)
 * - updateSchemaSources($schema, $record = null, $params = null)
 * - updateSchemaLanguages($schema)
 * - validateSchema(array $schema, bool $strict = false): array
 * - validateSchemaField(array $field, bool $strict): array
 * - validateSchemaObject($object, $schema)
 */

class _uho_orm_schema
{
    private $orm;
    private $loader;

    public function __construct(_uho_orm $orm, _uho_orm_schema_loader $loader)
    {
        $this->orm = $orm;
        $this->loader = $loader;
    }

    /**
     * Loads model schema from JSON file
     * @param string|array $name Model name or array of model names
     * @param bool $lang Whether to process language fields
     * @param array $params Additional parameters
     */
    public function getSchema($name, $lang = false, $params = []): array|null
    {

        if (!$name) $this->orm->halt('_uho_orm_schema::getSchema::no model name specified');

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

                    if (is_array($name)) $this->orm->halt('_uho_orm::getSchema::model as array');

                    $m = $this->loader->loadJsonSchema($name);

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
        }
        // getting just one model
        else {

            $filename = $name . '.json';
            $model = $this->loader->loadJsonSchema($filename);
            if ($model && !isset($model['model_name'])) $model['model_name'] = $name;

            if (!$model && isset($params['return_error'])) {
                if ($this->orm->isDebug())
                {
                    $root_paths = $this->loader->getRootPaths();
                    $errors = $this->loader->getLastError();
                    $message = '_uho_orm::JSON schema not found: ' . $filename . ' in ' . implode(', ', $root_paths) . ' ::: ' . $errors;
                } else $message = '_uho_orm::JSON schema not foundd: ' . $filename;
                return ['result' => false, 'message' => $message];
            }
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

        // children object validation
        if (isset($model['children']))
        {
            foreach ($model['children'] as $k=>$v)
                if (isset($v['schema']) && isset($v['parent']))
                    {
                        if (empty($v['id'])) $model['children'][$k]['id']='id';
                    } else unset($model['children'][$k]);
        }

        // setting field defaults ------------------------------------------------------------
        if ($model && isset($model['fields']) && is_array($model['fields']))
            foreach ($model['fields'] as $k => $v)
                if (!isset($v['type'])) {
                    if ($v['field'] == 'id') $model['fields'][$k]['type'] = 'integer';
                    else $model['fields'][$k]['type'] = 'string';
                }

        // setting langs  ------------------------------------------------------------
        $langs = $this->orm->getLanguages();
        if ($lang && $model && $langs) {

            $f = [];
            foreach ($model['fields'] as $k => $v)
                if (isset($v['field']) && strpos($v['field'], ':lang'))
                    foreach ($langs as $v2) {
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
                    $v3 = $this->loader->loadJsonSchema($v2['include']);
                    if (!$v3) $this->orm->halt('_uho_orm::loadJsonSchema::' . $v2['include']);

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

                        $im_schema = $this->getSchema($v['source']['model']);
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
     * Loads model schema using PageUpdate
     * @param string $name
     * @param  $lang
     * @return array
     */

    public function getSchemaWithPageUpdate($name, $lang = false)
    {
        $d = $this->getSchema($name, $lang);

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
                            $new = $this->orm->getTwigFromHtml($pattern, $v2);
                            if ($new != $pattern) $models[] = $new;
                        }
                    }
            }
        }
        if (isset($models)) $d = $this->getSchema(array_merge([$name], $models), $lang);
        return $d;
    }

    /**
     * Updates schema language fields
     * @param array $schema
     * @return array
     */
    public function updateSchemaLanguages($schema)
    {
        $fields = [];
        $langs = $this->orm->getLanguages();
        foreach ($schema['fields'] as $field)
            if (!empty($field['field']) && strpos($field['field'], ':lang') !== false) {
                $field_name = explode(':lang', $field['field'])[0];
                foreach ($langs as $lang) {
                    $new_field = $field;
                    $new_field['field'] = $field_name . $lang['lang_add'];
                    $fields[] = $new_field;
                }
            } else $fields[] = $field;

        $schema['fields'] = $fields;
        return $schema;
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
                $model_schema = $this->getSchema($v['source']['model']);
                if (isset($model_schema['model']))
                    foreach ($model_schema['model'] as $k2 => $v2)
                        if (!isset($v['source'][$k2])) {
                            $schema['fields'][$k]['source'][$k2] = $v2;
                        }
            }

        // main rework

        foreach ($schema['fields'] as $k => $v)
            // source --> options
            if (@$v['source'] && !@$v['options'] && @$v['cms']['input'] != 'search') {
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
                        $filters[$k2] = $this->orm->getTwigFromHtml($v2, $record);
                        if ($params) foreach ($params as $k3 => $v3)
                            $filters[$k2] = str_replace($k3, $v3, $filters[$k2]);
                    }
                } //else $filters=[]; Filters might be static as well!
                if (isset($v['source']['order'])) $order = 'ORDER BY ' . $v['source']['order'];
                else $order = '';

                if ($v['source']['model']) {
                    if (!empty($v['source']['model_fields'])) $params0 = ['fields' => $v['source']['model_fields']];
                    else $params0 = [];

                    $t = $this->orm->get(
                        $v['source']['model'],
                        $filters,
                        false,
                        null,
                        null,
                        $params0
                    );
                } else {
                    $t = $this->orm->query('SELECT id AS value,' . implode(',', $v['source']['fields']) . ' FROM ' . $v['source']['table'] . ' ' . $order);
                }

                foreach ($t as $kk => $vv) {
                    if (!@$v['source']['label']) $v['source']['label'] = '{{label}}';
                    $label = $this->orm->getTwigFromHtml($v['source']['label'], $vv);
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
                $t = $this->orm->query($query, true);
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
     * Validates schema field structure
     *
     * @param array $field
     * @param bool $strict
     * @return array
     */
    private function validateSchemaField(array $field, bool $strict): array
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
                    'default' => ['type' => ['string','integer']],
                    'extension' => ['type' => 'string'],
                    'filename' => ['type' => 'string'],
                    "hash" => ['type' => 'string'],
                    'hashable' => ['type' => 'boolean'],
                    'folder' => ['type' => 'string'],
                    'folder_preview' => ['type' => 'string'],
                    'header' => ['type' => 'array'],
                    'layout' => ['type' => 'array'],
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
                    'counter' => ['type' => 'boolean'],
                    'default' => ['type' => 'string'],
                    'edit' => ['type' => 'boolean'],
                    'header' => ['type' => 'string'],
                    'help' => ['type' => ['string', 'array']],
                    'hidden' => ['type' => 'boolean'],
                    'hr' => ['type' => 'boolean'],
                    'input' => ['type' => ['string']],
                    'max' => ['type' => 'integer'],
                    'label' => ['type' => 'string'],
                    'label_PL' => ['type' => 'string'],
                    'label_EN' => ['type' => 'string'],
                    'list' => ['type' => ['string', 'array']],
                    'layout' => ['type' => 'array'],
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
                    "unique" => ['type' => 'boolean'],
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
     * Validates schema structure
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
     * Helper: Validates Schema Object
     */
    private function validateSchemaObject($object, $schema)
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
}
