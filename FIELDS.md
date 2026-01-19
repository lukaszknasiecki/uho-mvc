# UHO-MVC Framework Documentation

## Table of Contents

* [Overview](#overview)
* [Field object structure](#field-object-structure)
* [Field Types](#field-types)
  * [Basic Types](#basic-types)
  * [Selection Types](#selection-types)
  * [File Types](#file-types)
  * [Special Types](#special-types)
* [Common field object properties](#common-field-object-properties)
* [Custom field object properties](#custom-field-object-properties)
* [Multi-language Support](#multi-language-support)
* [Schema Validation](#schema-validation)
* [License](#license)
* [Support](#support)

## Overview

UHO-MVC is a PHP Model-View-Controller (MVC) framework designed for building web applications.
This document describes available fields and their configuration.

Each model schema consists `fields` array which is a set of fields available in the model, i.e:

```json
{
    "table": "items",
    "order": "created_at DESC",
    "fields": [
        
    ]
}
```

## Field object structure

Each field should have at least 2 properties:

* `field (string)` - field name
* `type (string)` - field type

There are additional properties, some of them are common for all fields,
some work only with specified field types.

## Field Types

### Basic Types

* `boolean`: Boolean (TINYINT)
* `date`: Date (DATE)
* `datetime`: Date and time (DATETIME)
* `float`: Decimal/Float (DECIMAL/FLOAT)
* `integer`: Integer (INT)
* `json`: JSON data (JSON/TEXT)
* `string`: String (VARCHAR)
* `text`: Text (TEXT)

### Selection Types

* `checkboxes`: Multiple selection checkboxes
* `elements`: Enum-like single selection field
* `select`: Single selection dropdown

### File Types

* `audio`: Audio file upload
* `file`: File upload with path configuration
* `image`: Image upload with automatic thumbnail generation
* `media`: Media file upload (generic)
* `video`: Video file upload

### Special Types

* `html`: HTML content (TEXT)
* `model`: Get external model
* `order`: Ordering/sorting field
* `table`: Table/structured data field
* `uid`: Unique identifier field (auto-generated)

## Common field object properties

* `field_output (string)`: swaps the field's name in output to a new one
* `options (array)`: Populate options, works with types: `select`, `elements`, `checkboxes`, produces ENUM SQL field

  ```json
  {
      "field": "category",
      "type": "select",
      "options":
      [
          { "value": "book" },
          { "value": "record" }
          { "value": "movie" }
      ]      
  }
  ```
* `source (object)`: Populate options from another model or table, works with types: `select`, `elements`, `checkboxes`.

  ```json
  {
      "field": "category_id",
      "type": "select",
      "source": {
          "model": "categories",
          "filters": {
              "active": 1
          },
          "order": "title ASC",
          "label": "{{title}}"
      }
  }
  ```

## Custom field object properties

Here is a list of field types and properties which work with these types:

* `datetime`:
  * `settings.format=ISO8601|UTC` converts value to ISO8601 format in UTC timezone
* `elements`:
  * `settings.multiple_filters` can be set to `&&` or `||` (default) to join filter values on GET
* `image`:
  * `settings.folder` required, base folder for image storage, i.e. `/public/upload`
  * `settings.extensions` array of available image extensions, if not defined only `jpg` is being user
  * `settings.extension_field` points to an external field with image extension, if not specified `jpg` is being used, if value is blank, first extension from `setting.extensions` is taken
  * `settings.field_exists` points to boolean field which marks if image exists and will be returned (true) or not (false)
  * `settings.sizes` points to JSON field storing all image sizes (for every folder), to use this option you need to initialize it with `orm.setImageSizes(true)`
  * `settings.images` required, array with image sizes
  * `settings.images.filename` filename pattern, default is `{{uid}}.jpg`
  * `settings.images[].folder` required, folder to store the iamge, relatve to `settings.folder`, i.e. `desktop`
  * `settings.images[].retina` boolean, if true, image is returning additionally its retina versions, in `_x2` folders
  * `settings.images[].size` boolean, if true, image is returning not only src, but width and height, read live from the server file with `getimagesize`
* `model`: Get external model
  * `settings.schema` name of model's schema
  * `settings.filters` array of filters
  * `settings.order` order of results
* `string`:
  * `settings.length(INT)` sets string field length
* `table`:
  * `settings.fields(BOOL)` if TRUE returns table values as an array, where each row key is taken from `settings.header` array
  * `settings.format=object` returns table values as an object
* `text`:
  * `settings.function=nl2br` performs PHPs nl2br function on field value

## Multi-language Support

If you are using multi-language mode, please make sure that a field you would like to be separate
for each language, is marked with `:lang` suffix.

```json
{
    "field": "title:lang",
    "type": "string"
}
```

## Schema Validation

The framework includes a schema validation tool:

```bash
chmod +x bin/schema-validate
./bin/schema-validate /path/to/application/models/json
```

This validates all JSON model definitions in the specified directory.


## License

MIT License - See LICENSE file for details.


## Support

For issues and questions:

* Email: lukasz@knasiecki.com


*Last updated: 2026-01-19*