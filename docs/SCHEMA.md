# UHO-MVC Schema (Model Definition) Documentation

## Table of Contents

* [Overview](#overview)
* [JSON Schema Structure](#json-schema-structure)
* [Schema-Level Keys](#schema-level-keys)
* [Field-Level Keys](#field-level-keys)
* [Field Types](#field-types)
* [Routing](#routing)
* [Support](#support)


## Overview

Model schemas are JSON files stored in `application/models/json/`. Each file describes a database table — its fields, default filters, ordering, and relations. The ORM reads these definitions to handle all CRUD operations automatically.


## JSON Schema Structure

Create `application/models/json/items.json`:

```json
{
    "table": "items",
    "filters": {
        "active": 1
    },
    "url": {
        "type":"item",
        "slug": "{{slug}}"
    },
    "order": "created_at DESC",
    "search":
    [
        "id",
        "title"
    ],
    "fields": [
        {
            "field": "id",
            "type": "integer"
        },
        {
            "field": "title",
            "type": "string",
            "required": true
        },
        {
            "field": "description",
            "type": "text"
        },
        {
            "field": "image",
            "type": "file",
            "settings": {
                "extensions": ["pdf"],
                "folder": "/public/upload/items/"
            }
        },
        {
            "field": "active",
            "type": "boolean",
            "default": true
        },
        {
            "field": "created_at",
            "type": "datetime",
            "default": "NOW()"
        }
    ]
}
```


## Schema-Level Keys

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `table` | string | yes | Database table name this schema maps to |
| `children` | object | no | Nested schemas fetched via `orm.getDeep` |
| `filters` | object/array | no | Default filters applied to all queries |
| `fields_to_read` | object | no | Named sets of fields returned by `orm.get` |
| `fields` | array | yes | Array of field definitions |
| `order` | string/object | no | Default ordering for queries |
| `search` | array | no | Fields to be included into page search (filter button) |
| `url` | object | no | URL pattern for each record, resolved by the router |

### `table` (string)

The database table name this schema maps to.

### `url` (object)

Pre-defines the URL pattern for each record, resolved to a final string by the router:

```json
"url": {
    "type": "news",
    "slug": "{{slug}}"
}
```

Use `"twig": false` with `%variable%` placeholders to skip Twig rendering (faster for large result sets):

```json
"url": {
    "type": "news",
    "twig": false,
    "slug": "%slug%"
}
```

### `order` (string/object)

Default ordering for queries. Several formats are accepted:

```json
"order": "created_at DESC"
```

```json
"order": {"type": "field", "values": ["title", "date"]}
```

```json
"order": {"field": "date", "sort": ["DESC"]}
```

```json
"order": {
    "field": "created_at",
    "sort": "DESC"
}
```

Prefix a field name with `!` as shorthand for DESC: `"!created_at"` equals `"created_at DESC"`.

### `filters` (object/array)

Default filters applied to all queries for this model:

```json
"filters": {
    "active": 1,
    "published": true
}
```

### `search` (array)

List of fields to be used if "filters" buttons is clicked, to search/filter through
the records.

```json
"search": {
    "title",
    "name"
}
```

You can also mark those fields with adding `cms.search=true` to any field.
Please, note that this field needs to be listed in the page view, with `cms.list` property.

```json
{
    "field": "title",
    "cms": {
        "label":"Title"
        "list":"show",
        "search":true
    }
}
```

### `children` (object)

Defines nested schemas fetched via `orm.getDeep`:

```json
"children": {
    "subitems": {
        "id": "id",
        "schema": "submenu",
        "parent": "parent",
        "filters": {"active": 1}
    }
}
```

* `id`: source ID field
* `schema`: child schema name
* `parent`: child field matched against the source ID
* `filters`: additional filters for children

### `fields_to_read` (object)

Defines named sets of fields returned by `orm.get`. Useful for reading only a subset of fields:

```json
"fields_to_read": {
    "list": ["title", "author"],
    "single": ["title", "author", "theme", "description"]
}
```

You can also set defaults applied automatically to every single- or multi-record `get` call (when no `fields` param is passed):

```json
"fields_to_read": {
    "_single": ["title", "author", "theme", "description"],
    "_multiple": ["title", "author"]
}
```

### `fields` (array)

Array of field definitions. See [Field-Level Keys](#field-level-keys) and [FIELDS.md](FIELDS.md) for details.


## Field-Level Keys

Every field object requires at minimum:

* `field` (string): column name
* `type` (string): field type

### `source` (object)

Populates select/checkbox options from another model or table:

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

| Key | Description |
|-----|-------------|
| `model` | Name of the JSON model to source options from |
| `table` | Direct table name (alternative to `model`) |
| `fields` | Array of fields to select |
| `filters` | Filters applied when fetching options |
| `order` | Ordering for fetched options |
| `label` | Twig template for the option label (default: `"{{label}}"`) |

### `filters` (object/array)

Field-specific filters (used inside `source`).

### `order` (string)

Field-specific ordering (used inside `source`).


## Field Types

For the full list of available field types and their configuration options, refer to [SCHEMA_FIELDS.md](SCHEMA_FIELDS.md).


## Routing

Route definitions live in `application/routes/route_app.json`. The file has three top-level keys: `controllers`, `headers`, and `paths`. First two are handling general application routing (refer to [DOCUMENTATION.md](DOCUMENTATION.md)), while `paths` object contains objects which correspond with model's `url` property.

### File structure

```json
{
    "controllers": {},
    "headers": {},
    "paths": {
        "home": "/",
        "about": "/about",
        "news": {
            "type": "twig",
            "input": ["slug"],
            "value": "news{% if slug %}/{{slug}}/{% endif %}"
        }
    }
}
```

### `paths` (object)

Defines how model `url` objects are resolved into actual URL strings. Every key maps to a `"type"` value used in a model's `url` definition (see [`url`](#url-object) above).

**Simple static path:**

```json
"paths": {
    "home": "/",
    "about": "/about"
}
```

**Dynamic path with Twig:**

```json
"paths": {
    "news": {
        "type": "twig",
        "input": ["slug"],
        "value": "news/{{slug}}/"
    },
    "product": {
        "type": "twig",
        "input": ["category", "slug"],
        "value": "{{category}}/{{slug}}/"
    }
}
```

* `input` — list of variable names pulled from the model record
* `value` — Twig template rendered with those variables

**Dynamic path without Twig** (faster for large datasets):

```json
"paths": {
    "news": {
        "type": "twig",
        "twig": false,
        "input": ["slug"],
        "value": "news/%slug%/"
    }
}
```

Set `"twig": false` and use `%variable%` placeholders to skip Twig rendering.

**Special built-in path types:**

| Type | Description |
|------|-------------|
| `home` | Returns the root URL |
| `url_now` | Current URL with optional modifications |
| `url_now_http` | Current URL with full domain |
| `mailto` | `mailto:` link |
| `facebook` | Facebook share URL |
| `twitter` | Twitter share URL |
| `linkedin` | LinkedIn share URL |
| `pinterest` | Pinterest share URL |
| `email` | Email share URL |


### Connecting model `url` to `paths`

A model's `url` key (see [Schema-Level Keys](#schema-level-keys)) declares `"type"` and any field values. The router looks up that type in `paths`, substitutes the field values, and returns the final URL string.

```json
// In the model schema (e.g. items.json):
"url": {
    "type": "news",
    "slug": "{{slug}}"
}

// In route_app.json:
"paths": {
    "news": {
        "type": "twig",
        "input": ["slug"],
        "value": "news/{{slug}}/"
    }
}
// Result: /news/my-article/
```

## Support

For issues and questions:

* Email: lukasz@knasiecki.com


*Last updated: 2026-03*
