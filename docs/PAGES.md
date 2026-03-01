# Pages Layer Documentation

This document covers the two predefined classes for handling HTML page rendering:
`_uho_controller_pages` and `_uho_model_pages`.

These classes work together to serve website pages stored in the database, composed of typed modules.

---

## Table of Contents

1. [Overview](#overview)
2. [Database Structure](#database-structure)
3. [_uho_controller_pages](#_uho_controller_pages)
4. [_uho_model_pages](#_uho_model_pages)
5. [Page Modules](#page-modules)
6. [Open Graph / Head Meta](#open-graph--head-meta)
7. [Usage Example](#usage-example)

---

## Overview

The pages layer provides a database-driven, module-based page system. Pages are stored in a `pages` table, and each page is composed of one or more `pages_modules` records. Each module can have its own PHP class (`m_*.php`) that enriches the module's data before it reaches the view.

Route entry point is typically defined in `controllers` object of `/application/routes/route_app.json`:

```json
{
    "controllers": {
        "": "pages"
    }
}
```

This maps all unmatched URLs to the `pages` controller/model pair.

---

## Database Structure

The pages system expects two database tables driven by JSON schema definitions:

### `pages` table

| Field         | Description                                      |
|---------------|--------------------------------------------------|
| `id`          | Primary key                                      |
| `title`       | Page title (used for OG tags)                    |
| `description` | Page description (used for OG tags)              |
| `image`       | Page share image (used for OG tags)              |
| `path`        | URL pattern(s), semicolon-separated (e.g. `home;news/%`) |
| `active`      | Boolean — whether the page is active             |

### `pages_modules` table

| Field      | Description                                              |
|------------|----------------------------------------------------------|
| `id`       | Primary key                                              |
| `parent`   | Foreign key → `pages.id`                                |
| `type`     | Module type (relation to a type/slug definition)         |
| `active`   | Boolean — whether the module is active                   |
| `level`    | Sort order                                               |

---

## _uho_controller_pages

**File:** `src/_uho_controller_pages.php`
**Extends:** `_uho_controller`
**Namespace:** `Huncwot\UhoFramework`

This is built-in controller for page rendering. It orchestrates fetching page content, handling 404s, and passing data to the Twig view.

### Methods

#### `getData(): void`

Main entry point called by the framework. Fetches full page data with `getContentData()` and resolves URL paths with `$this->route->updatePaths($this->data)`.

#### `getContentData(): array`

Builds and returns the data array for the page. This is useful to call directly when you need page content in a custom controller (e.g. to embed page content in a different response format).

Returns an array with keys:

| Key       | Type    | Description                                           |
|-----------|---------|-------------------------------------------------------|
| `content` | array   | Full page data including modules                      |
| `ajax`    | bool    | Whether the request is an AJAX request                |
| `head`    | array   | Head/meta data: `og`, `http_domain`, `url_now`        |
| `view`    | string  | Twig view name — always `'article'`                   |

If the page is not found or `is404()` is true, the method falls back to `get404()` and sets `$this->outputType = '404'`.

#### `actionBefore($post, $get): void`

Called before page data is fetched. Stores `$post` and `$get` on the controller and delegates to `$this->model->actionBefore()`.

Override in your application model to handle form submissions or other pre-render logic.

### Extending the controller

```php
<?php
class controller_app_pages extends \Huncwot\UhoFramework\_uho_controller_pages
{
    public function actionBefore($post, $get): void
    {
        parent::actionBefore($post, $get);

        // Handle a form submission
        if (!empty($post['action']) && $post['action'] === 'contact') {
            $this->model->handleContactForm($post);
        }
    }
}
```

---

## _uho_model_pages

**File:** `src/_uho_model_pages.php`
**Extends:** `_uho_model`
**Namespace:** `Huncwot\UhoFramework`

The built-in model for the pages system. Handles page lookup by URL pattern, module loading, 404 fallback, and OG meta data.

### Properties

| Property       | Type   | Description                                        |
|----------------|--------|----------------------------------------------------|
| `$head`        | array  | Head meta data (title, description, image, app_title) |

### Methods

#### `getContentData($params = null): array`

Main method — resolves a URL to a page record, loads its modules, and returns the complete page array.

**Parameters:**

| Name             | Type   | Description                              |
|------------------|--------|------------------------------------------|
| `$params['url']` | string | Current URL path (e.g. `/news/my-post`)  |
| `$params['get']` | array  | GET parameters from the request          |

**Returns:** Full page array including `modules` key, or 404 page if not found.

If no matching page is found, falls back to the page with `path = '404'`. If that page also does not exist, the script exits with `'Page not found'`.

#### `setPathModules($path): void`

Sets the filesystem path where module class files (`m_*.php`) are looked up.

```php
$this->model->setPathModules(__DIR__ . '/models/modules/');
```

#### `setParentVar($key, $value): void`

Sets a shared variable accessible across all modules during a single request. Useful for passing global state from one module to another.

```php
$this->model->setParentVar('current_user_id', 42);
```

#### `getParentVar($key): mixed`

Returns a previously set shared variable.

```php
$userId = $this->model->getParentVar('current_user_id');
```

#### `set404(): void`

Forces the model to treat the current request as a 404, skipping regular page lookup.

```php
$this->model->set404();
```

#### `is404(): bool`

Returns `true` if `set404()` has been called.

#### `ogGet(): array`

Returns the assembled OG/head meta array. Used by `_uho_controller_pages` to populate `$data['head']['og']`.

**Returns:**

| Key           | Type          | Description                              |
|---------------|---------------|------------------------------------------|
| `title`       | string        | Full page title (with app title appended) |
| `description` | string        | Page description (max 250 chars)         |
| `image`       | array\|string | Image with optional `src`, `width`, `height` |
| `app_title`   | string        | Application title from config            |

#### `ogSet($title, $description = '', $image = null): void`

Sets the OG/head meta values. Call this from a module class to override the default page-level values.

```php
$this->parent->ogSet('Post title', 'Post excerpt', '/upload/post-image.jpg');
```

#### `actionBefore($action, $get): void`

Called before page data is assembled. Empty by default — override in your application model.

---

## Page Modules

Each record in `pages_modules` is processed by `_uho_model_pages_modules`. If a file `m_{type_slug}.php` exists in the modules path, it is loaded and its `updateModel($m, $url)` method is called to enrich the module data.

### Creating a module class

Place the file at `application/models/modules/m_news.php`:

```php
<?php
class model_app_pages_modules_news extends \Huncwot\UhoFramework\_uho_model_pages_modules_base
{
    public function updateModel($m, $url)
    {
        // $this->parent is the _uho_model_pages instance
        $m['items'] = $this->parent->get('news', ['active' => 1], false, 'date DESC', 10);
        return $m;
    }
}
```

Inside the module class you have access to:

| Property/method        | Description                                      |
|------------------------|--------------------------------------------------|
| `$this->parent`        | The `_uho_model_pages` instance                  |
| `$this->settings['url']` | Current URL array                              |
| `$this->settings['get']` | Current GET parameters                         |

---

## Open Graph / Head Meta

The `ogSet` / `ogGet` pair manages the `<head>` meta data used for SEO and social sharing.

`ogGet()` formats the title as `"{page title} - {app_title}"`, or just `{app_title}` for the home page.

For non-HTTP images (local files), `ogGet()` resolves their dimensions automatically.

To override OG data from within a module:

```php
$this->parent->ogSet('Custom title', 'Custom description', '/upload/custom-image.jpg');
```

---

## Usage Example

**Route** (`application/routes/route_app.json`):

```json
{
    "controllers": {
        "": "pages"
    }
}
```

**Model** (`application/models/model_app_pages.php`):

```php
<?php
class model_app_pages extends \Huncwot\UhoFramework\_uho_model_pages
{
    public function actionBefore($action, $get): void
    {
        // Set the modules path so module classes can be autoloaded
        $this->setPathModules(__DIR__ . '/modules/');
    }
}
```

**Controller** (`application/controllers/controller_app_pages.php`):

```php
<?php
class controller_app_pages extends \Huncwot\UhoFramework\_uho_controller_pages
{
    // No override needed for basic usage
}
```

**Module class** (`application/models/modules/m_hero.php`):

```php
<?php
class model_app_pages_modules_hero extends \Huncwot\UhoFramework\_uho_model_pages_modules_base
{
    public function updateModel($m, $url)
    {
        $m['slides'] = $this->parent->get('hero_slides', ['active' => 1], false, 'level');
        return $m;
    }
}
```

**Twig view** (`application/views/view_app_article.html`):

```twig
{% extends "view_app_base.html" %}

{% block content %}
    {% for module in content.modules %}
        {% include "modules/m_" ~ module.type.slug ~ ".html" with { module: module } %}
    {% endfor %}
{% endblock %}
```
