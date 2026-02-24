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
* `timestamp`: Timestamp (DATETIME) in UTC

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

* `settings.field_output (string)`: swaps the field's name in output to a new one
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
          "model_fields": ["title", "description"],
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

* `boolean`:
  * `settings.default` (`boolean` | `integer`)
* `date`:
  * `settings.default` (`string`)
* `datetime`:
  * `settings.default` (`string`)
  * `settings.format` (`string`): converts value to ISO8601 format in UTC timezone, accepts `ISO8601` or `UTC`
* `checkboxes`:
  * `settings.output` (`string`)
* `elements`:
  * `settings.length` (`integer`)
  * `settings.multiple_filters` (`string`): can be set to `&&` or `||` (default) to join filter values on GET
  * `settings.output` (`string`)
* `file`:
  * `settings.filename` (`string`)
  * `settings.folder` (`string`)
  * `settings.extension` (`string`)
  * `settings.hashable` (`boolean`)
* `html`:
  * `settings.long` (`boolean`)
  * `settings.media` (`string`)
  * `settings.media_field` (`string`)
* `image`:
  * `settings.filename` (`string`): filename pattern, default `%uid%.jpg`
  * `settings.folder` (`string`): required, base folder for image storage, i.e. `/public/upload`
  * `settings.extensions` (`array`): array of available image extensions, if not defined only `jpg` is being used
  * `settings.extension_field` (`string`): points to an external field with image extension, if not specified `jpg` is being used, if value is blank, first extension from `settings.extensions` is taken
  * `settings.field_exists` (`string`): points to boolean field which marks if image exists and will be returned (true) or not (false)
  * `settings.sizes` (`string`): points to JSON field storing all image sizes (for every folder), to use this option you need to initialize it with `orm.setImageSizes(true)`
  * `settings.webp` (`boolean`)
  * `images` (`array`): required, array with image sizes
  * `images.filename` (`string`): filename pattern, default is `{{uid}}.jpg`
  * `images[].folder` (`string`): required, folder to store the image, relative to `settings.folder`, i.e. `desktop`
  * `images[].retina` (`boolean`): if true, image is returning additionally its retina versions, in `_x2` folders
  * `images[].size` (`boolean`): if true, image is returning not only src, but width and height, read live from the server file with `getimagesize`
* `media`: Several media attachments
  * `captions` (`array`)
  * `source` (`array`)
* `model`: Get external model
  * `settings.schema` (`string`): name of model's schema
  * `settings.filters` (`array`): array of filters
  * `settings.order` (`string`): order of results
* `select`:
  * `settings.default` (`integer|string`)
  * `settings.null` (`boolean`)
* `string`:
  * `settings.length` (`integer`): sets string field length
  * `settings.case` (`boolean`)
  * `settings.hash` (`string`)
* `text`:
  * `settings.long` (`integer`)
  * `settings.hash` (`string`)
  * `settings.hashable` (`boolean`)
* `table`:
  * `settings.fields` (`boolean`): if true returns table values as an array, where each row key is taken from `settings.header` array
  * `settings.header` (`array`)
  * `settings.format` (`string`): returns table values as an object when set to `object`
* `text`:
  * `settings.function` (`string`): performs PHP's `nl2br` function on field value when set to `nl2br`
* `video`:
  * `settings.filename` (`string`): filename pattern, default `%uid%.mp4`
  * `settings.field_cover` (`string`)
  * `settings.folder` (`string`): required, base folder for video storage, i.e. `/public/upload`
  
## Media Types

### `image`

Uploaded image - creates virtual object (no SQL field).
By default `uid` field is used to create unique filenames and all images are JPGs and WEBP (optional).

```json
{
    "field": "image",
    "type": "image",
    "cms": {
        "list":
        {
            "folder": "thumb"          // preview folder to be used in CMS for thumbnails
        }
    },
    "settings": {
        "folder": "/public/upload/images",  // root folder for uploads
        "filename": "%uid%",                // filename template
        "filename_field": "filename",       // field to store original filename
        "sizes": "image_sizes",             // json field to store JSON with all image sizes
        "webp": true                        // add webp format
    },
    "images": [                             // list of sizes/folders
        {
            "folder": "original",           // first folder - to store original image
            "label": "Original image"
        },
        {
            "folder": "desktop",            // subfolder name
            "label": "Desktop",             // cms label
            "width": 1200,                  // max width
            "height": 800,                  // max height
            "crop": true,               // use to force fixed ratio
            "retina": true              // create retina (x2) images, in desktop_x2 folder
        },
        {
            "folder": "mobile",
            "label": "Mobile",
            "width": 640,
            "height": 480,
            "crop": true
        }
    ]
}
```

You can also use PNG/GIF images without converting them to JPGs, which is convenient for transparent images (like PNG).

```json
{
    "field": "image",
    "type": "image",
    "settings": {
        "folder": "/public/upload/images",  // root folder for uploads
        "filename": "%uid%",                // filename template
        "filename_field": "filename",       // field to store original filename
        "extensions": [ "png"],             // allowed extensions
        "extension_field": "extension",     // field to store original extension
        "webp": true                        // add webp format
    },
    "images": [                             // list of sizes/folders
        {
            "folder": "original",           // first folder - to store original image
            "label": "Original image"
        },
        {
            "folder": "desktop",            // subfolder name
            "label": "Desktop",             // cms label
            "width": 1200,                  // max width
            "height": 800,                  // max height
            "crop": true,               // use to force fixed ratio
            "retina": true              // create retina (x2) images, in desktop_x2 folder
        },
        {
            "folder": "mobile",
            "label": "Mobile",
            "width": 640,
            "height": 480,
            "crop": true
        }
    ]
}
```

### `file`

Represents uploaded file - by default filename is created from `uid` field with added extension.

```json
{
    "field": "file",
    "type": "file",
    "settings": {
        "folder": "/public/upload/files",       // folder to upload files
        "size": "size",                          // field to store file size
        "hashable": false,                      // if you want to enable option to hash/dehash files
        "extensions": ["docx", "pdf"],          // list of supported extensions for multi-extension fields
        "extension_field": "ext"                // field to store file's extension,        
    }    
},
{
    "field":"ext",
    "type":"string"
}
```

You can also store files with their original filenames, you will need additional field to store that filename. Additionally you can specify just one extenstion, then you won’t need separate extension field.

```json
{
    "field": "file",
    "type": "file",
    "settings": {
        "folder": "/public/upload/files",       // folder to upload files
        "filename_original": "filename_org",        // field to store original filename
        "filename":"{{filename_org}}",              // pattern to create filename
        "extension": "docx"                    // extension for single-extension fields
     }
},
{
    "field":"filename_org",
    "type":"string"
}
```
### `video`

Uploaded video file (MP4).

```json
{
    "field": "video",
    "type": "video",
    "settings": {
        "folder": "/public/upload/videos",
        "field_cover": "image"                  // video's cover and save for this field
    }
}
```
Files are stored as: `{folder}/{uid}.mp4`

### `media`

Attach media from another model.

```json
{
    "field": "gallery",
    "type": "media",
    "source": {
        "model": "media",
        "types": [
            "image",
            "video",
            "audio",
            {
                "type": "file",
                "extensions": ["pdf", "doc", "docx"]
            },
            "vimeo",
            "youtube"
        ]
    },
    "captions": [
        {
            "label": "Caption",
            "field": "label"
        },
        {
            "label": "Description",
            "field": "description",
            "field_type": "text"            // youtube|vimeo|url|text
        }
    ]
}
```

**Media Types:**

* `image`: Image files
* `video`: Video files
* `audio`: Audio files
* `file`: Other files
* `youtube`/`vimeo`: Stores video's UIDs and thumbnails if available

For YouTube and Vimeo you need to specify access keys in ENVs to get covers
and/or sources (Vimeo):

VIMEO_CLIENT
VIMEO_SECRET
VIMEO_TOKEN
YOUTUBE_TOKEN

Fields used to store the data:
`youtube`
`vimeo`
`vimeo_sources`

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

This validates all JSON model definitions in the specified directory, example:

`vendor/lukaszknasiecki/uho-mvc/bin/schema-validate application/models/json/`



## License

MIT License - See LICENSE file for details.


## Support

For issues and questions:

* Email: lukasz@knasiecki.com


*Last updated: 2026-01-19*