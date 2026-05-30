<?php

/*
    This class extends _uho_model with API methods
*/

namespace Huncwot\UhoFramework;

use Huncwot\UhoFramework\_uho_model;

class _uho_model_api_endpoint
{

    protected $parent;

    protected static $GET_ALLOWED_FIELDS;
    protected static $GET_REQUIRED_FIELDS;
    protected static $POST_ALLOWED_FIELDS;
    protected static $POST_REQUIRED_FIELDS;
    protected static $DELETE_ALLOWED_FIELDS;
    protected static $DELETE_REQUIRED_FIELDS;
    protected static $PUT_ALLOWED_FIELDS;
    protected static $PUT_REQUIRED_FIELDS;
    protected static $PATCH_ALLOWED_FIELDS;
    protected static $PATCH_REQUIRED_FIELDS;

    function __construct($parent, $settings)
    {
        $this->parent = $parent;
    }

    public function getSupported($method)
    {
        if ($method == 'GET') return static::$GET_ALLOWED_FIELDS;
        if ($method == 'POST') return static::$POST_ALLOWED_FIELDS;
        if ($method == 'PATCH') return static::$PATCH_ALLOWED_FIELDS;
        if ($method == 'PUT') return static::$PUT_ALLOWED_FIELDS;
        if ($method == 'DELETE') return static::$DELETE_ALLOWED_FIELDS;
        return [];
    }

    public function getRequired($method)
    {
        if ($method == 'GET') return static::$GET_REQUIRED_FIELDS;
        if ($method == 'POST') return static::$POST_REQUIRED_FIELDS;
        if ($method == 'PATCH') return static::$PATCH_REQUIRED_FIELDS;
        if ($method == 'PUT') return static::$PUT_REQUIRED_FIELDS;
        if ($method == 'DELETE') return static::$DELETE_REQUIRED_FIELDS;
        return [];
    }


}
