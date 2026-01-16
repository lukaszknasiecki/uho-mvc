# UHO-MVC Framework Documentation

## Table of Contents


 1. [Overview](#overview)
 2. [System Requirements](#system-requirements)
 3. [Installation](#installation)
 4. [Architecture](#architecture)
 5. [Configuration](#configuration)
 6. [Routing](#routing)
 7. [Models and ORM](#models-and-orm)
 8. [Controllers](#controllers)
 9. [Views](#views)
10. [Database](#database)
11. [Security](#security)
12. [Features](#features)
13. [API Reference](#api-reference)
14. [Examples](#examples)


---

## Overview

UHO-MVC is a PHP Model-View-Controller (MVC) framework designed for building web applications. It features:

* **MVC Architecture**: Clean separation of concerns
* **Twig Templating**: Modern template engine for views
* **Custom ORM**: JSON-based model definitions with automatic database operations
* **Multi-language Support**: Built-in internationalization
* **REST API Support**: Helper classes for building RESTful APIs
* **Security Features**: CSRF protection, SQL injection prevention
* **Cloud Integration**: AWS S3 support, Auth0 authentication
* **Image Processing**: Automatic thumbnail generation and caching


---

## System Requirements

* **PHP**: 8.1 or higher
* **MySQL**: 5.7 or higher
* **Composer**: For dependency management
* **Extensions**:
  * mysqli
  * curl
  * openssl
  * gd (for image processing)


---

## Installation

### 1. Install via Composer

```bash
composer install
```

### 2. Project Structure

A typical UHO-MVC application follows this structure:

```
project/
├── application/
│   ├── config/
│   │   ├── config.php
│   │   ├── config_additional.json
│   │   └── hosts.php
│   ├── controllers/
│   │   └── controller_app_*.php
│   ├── models/
│   │   ├── model_app_*.php
│   │   └── json/
│   │       └── *.json (model definitions)
│   ├── views/
│   │   ├── view_app_*.html
│   │   └── svg/
│   ├── routes/
│   │   └── route_app.json
│   └── Twig/
│       ├── Filter/
│       ├── Function/
│       └── Global/
├── public/
├── vendor/
└── index.php
```

### 3. Bootstrap File (index.php)

```php
<?php
require_once 'vendor/autoload.php';

use Huncwot\UhoFramework\_uho_application;

$root_path = __DIR__ . '/';
$development = true; // Set to false in production
$config_folder = 'application_config';

$app = new _uho_application($root_path, $development, $config_folder);
$output = $app->getOutput();

header('Content-Type: text/html; charset=utf-8');
echo $output['output'];
```


---

## Architecture

### MVC Pattern

The framework follows the Model-View-Controller pattern:

* **Model** (`_uho_model`): Handles data logic, database operations via ORM
* **View** (`_uho_view`): Renders templates using Twig
* **Controller** (`_uho_controller`): Processes requests, coordinates Model and View

### Application Flow


1. Request arrives at `index.php`
2. `_uho_application` initializes routing
3. Route determines controller, model, and view classes
4. Controller processes request and calls model methods
5. Model retrieves/updates data via ORM
6. Controller passes data to view
7. View renders Twig template
8. HTML/JSON output returned to client


---

## Configuration

### Configuration File Structure

Create `application_config/config.php`:

```php
<?php
$cfg = [
    // Application
    'application_title' => 'My Application',
    'application_class' => 'app',
    'application_domain' => 'example.com',
    
    // Database
    'sql_host' => 'localhost',
    'sql_user' => 'username',
    'sql_pass' => 'password',
    'sql_base' => 'database_name',
    'nosql' => false, // Set true to disable database
    
    // Languages
    'application_languages' => ['en', 'pl'],
    'application_languages_url' => true, // Language in URL
    'application_languages_detect' => ['en' => 'en', 'pl' => 'pl'],
    'application_languages_empty' => null, // Default language if no prefix
    
    // URL
    'application_url_prefix' => '', // Optional URL prefix
    
    // Upload
    'upload_server' => null, // Remote upload server URL
    
    // S3 Configuration (optional)
    's3' => [
        'host' => 'https://s3.amazonaws.com/bucket',
        'folder' => 'uploads',
        'cache' => true,
        'compress' => true
    ],
    
    // SMTP (optional)
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'user@example.com',
        'password' => 'password'
    ],
    
    // API Keys
    'api_keys' => [
        'google' => 'your-google-api-key',
        'facebook' => 'your-facebook-api-key'
    ],
    
    // Encryption Keys
    'keys' => [
        'encryption_key_1' => 'value1',
        'encryption_key_2' => 'value2'
    ],
    
    // Clients (OAuth, etc.)
    'clients' => [
        'auth0' => [
            'domain' => 'your-domain.auth0.com',
            'client_id' => 'your-client-id',
            'client_secret' => 'your-client-secret'
        ]
    ],
    
    // Additional parameters
    'params' => [
        'custom_param' => 'value'
    ]
];
```

### Hosts Configuration

Create `application_config/hosts.php`:

```php
<?php
$cfg_domains = [
    'example.com' => [
        'application_domain' => 'example.com',
        // Override any config values per domain
    ],
    '*.example.com' => [
        // Configuration for subdomains
    ]
];
```

### Environment Variables

Create in your Apache config or create `.env` file in `application_config/`:

```
SQL_HOST=localhost
SQL_USER=username
SQL_PASS=password
SQL_BASE=database_name
```

The framework automatically loads `.env` files using `_uho_load_env`.
Make sure to `chmod 400 .env`


---

## Routing

### Route Configuration

Create `application/routes/route_app.json`:

```json
{
    "controllers": {
        "": "home",
        "about": "about",
        "contact": "contact"
    },
    "headers": {
        "api": "api"
    },
    "paths": {
        "home": "/",
        "about": "/about",
        "contact": "/contact",
        "news": {
            "type": "twig",
            "input": [
                "slug"
            ],
            "value": "news{% if slug %}/{{slug}}/{% endif %}"
        },
    }
}
```

### Route Structure

* **controllers**: Maps URL segments to controller classes
* **headers**: Custom headers for specific routes
* **paths**: URL path definitions

Controllers match URLs with custom controller classes. Additionally if custom header is present (`headers`) additional layer of matching with controllers is added.

Paths is a routing object used by `_uho_route` class to build final URLs based on models, for example, every model can have it's own `url` property, i.e:

```json
{
    "url": {
        "type": "news",
        "slug": "{{slug}}"
    }
}
```

Which is automatically rebuilt using `route_app.json` and returning URL string, i.e. `/news/my-news/`. The Paths object simply gets allowed input variables defined in `input` array and parses it using the TWIG `value` template.

### Language Routing

If `application_languages_url` is enabled, URLs include language prefix:

* `/en/home` → English
* `/es/home` → Spanish

### Accessing Route Information

In controllers:

```php
$route_class = $this->route->getRouteClass();
$current_lang = $this->route->getLang();
$url_array = $this->route->getUrlArray();
$url_element = $this->route->e(1);
```


---

## Models and ORM

### Model Class

Create `application/models/model_app_home.php`:

```php
<?php
class model_app_home extends \Huncwot\UhoFramework\_uho_model
{
    public function getData()
    {
        // Get data using ORM
        $this->data['items'] = $this->get('items', ['active' => 1]);
        
        // Or use raw SQL
        $this->data['users'] = $this->query("SELECT * FROM items WHERE active=1 LIMIT 10");
    }
}
```

### JSON Schema Definitions

Create `application/models/json/items.json`:

```json
{
    "table": "items",
    "filters": {
        "active": 1
    },
    "order": "created_at DESC",
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

### Schema-Level Keys

In addition to `table`, `fields`, and `order`, you can use these keys at the schema level:

* `filters` (object/array): Default filters applied to all queries for this model

  ```json
  "filters": {
      "active": 1,
      "published": true
  }
  ```

  Filters can use dynamic values with Twig syntax: `"category_id": "{{id}}"`
* `order` (string/object): Default ordering for queries

  ```json
  "order": "created_at DESC"
  ```

  Or use object format:

  ```json
  "order": {
      "field": "created_at",
      "sort": "DESC"
  }
  ```

  Prefix field with `!` for DESC: `"!created_at"` = `"created_at DESC"`

### Field-Level Keys

In addition to `type` and `field` keys, you can use:

* `source` (object): Populate options from another model or table

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
  * `model`: Name of JSON model to use
  * `table`: Direct table name (alternative to model)
  * `fields`: Array of fields to select from table
  * `filters`: Filters to apply when fetching options
  * `order`: Ordering for options
  * `settings`: Twig template for option label (default: `"{{label}}"`)
* `filters` (object/array): Field-specific filters (used in source)
* `order` (string): Field-specific ordering (used in source)

### ORM Methods

#### Get Models

```php
// Get all items
$items = $this->get('items');

// Get single item
$item = $this->get('items', ['id' => 1], true);

// Get with filters
$items = $this->get('items', ['active' => 1], false, 'created_at DESC', '10');

// Get with deep relations
$items = $this->get('items', ['id' => 1], true, [
    'relations' => ['category', 'tags']
]);
```

#### Create Models

```php
$data = [
    'title' => 'New Item',
    'description' => 'Description',
    'active' => 1
];

$this->post('items', $data);
$new_id = $this->getInsertId();
```

#### Update Models

```php
$data = ['title' => 'Updated Title'];
$filters = ['id' => 1];

$this->put('items', $data, $filters);
```

#### Delete Models

```php
$this->delete('items', ['id' => 1]);
```

### Raw SQL Queries

```php
// Read query
$results = $this->query("SELECT * FROM users WHERE active = 1");

// Single result
$user = $this->query("SELECT * FROM users WHERE id = 1", true);

// Write query
$this->queryOut("UPDATE users SET last_login = NOW() WHERE id = 1");

// Multiple queries
$this->multiQueryOut("INSERT INTO ...; UPDATE ...;");
```

### Field Types

#### Basic Types

* `boolean`: Boolean (TINYINT)
* `date`: Date (DATE)
* `datetime`: Date and time (DATETIME)
* `float`: Decimal/Float (DECIMAL/FLOAT)
* `integer`: Integer (INT)
* `json`: JSON data (JSON/TEXT)
* `string`: String (VARCHAR)
* `text`: Text (TEXT)

#### Selection Types

* `checkboxes`: Multiple selection checkboxes
* `elements`: Enum-like single selection field
* `select`: Single selection dropdown

#### File Types

* `audio`: Audio file upload
* `file`: File upload with path configuration
* `image`: Image upload with automatic thumbnail generation
* `media`: Media file upload (generic)
* `video`: Video file upload

#### Special Types

* `html`: HTML content (TEXT)
* `order`: Ordering/sorting field
* `table`: Table/structured data field
* `uid`: Unique identifier field (auto-generated)


---

## Controllers

### Controller Class

Create `application/controllers/controller_app_home.php`:

```php
<?php
class controller_app_home extends \Huncwot\UhoFramework\_uho_controller
{
    public function getData()
    {
        // Get data from model
        $this->data['content'] = $this->model->getData();
        
        // Set view
        $this->data['view'] = 'home';
        
        // Set output type
        $this->outputType = 'html'; // or 'json', 'rss'
    }
    
    public function actionBefore($post, $get)
    {
        parent::actionBefore($post, $get);
        
        // Handle POST requests
        if (!empty($post['action'])) {
            switch ($post['action']) {
                case 'submit':
                    $this->handleSubmit($post);
                    break;
            }
        }
    }
    
    private function handleSubmit($post)
    {
        // Validate CSRF token
        if (!$this->model->csrf_token_verify($post['csrf_token'])) {
            // Handle error
            return;
        }
        
        // Process form
        $data = [
            'title' => $post['title'],
            'description' => $post['description']
        ];
        
        $this->model->post('items', $data);
    }
}
```

### Output Types

* **html**: Standard HTML output (default)
* **json**: JSON response
* **json_raw**: JSON without pretty printing
* **rss**: RSS feed output
* **404**: 404 error page

### Accessing Request Data

```php
// GET parameters
$id = $this->get['id'] ?? null;

// POST data
$title = $this->post['title'] ?? null;

// Route information
$route_class = $this->route->getRouteClass();
```


---

## Views

### Twig Templates

Create `application/views/view_app_home.html`:

```twig
{% extends "view_app_base.html" %}

{% block content %}
    <h1>{{ content.title }}</h1>
    <p>{{ content.description }}</p>
    
    {% for item in content.items %}
        <div class="item">
            <h2>{{ item.title }}</h2>
            <p>{{ item.description }}</p>
        </div>
    {% endfor %}
{% endblock %}
```

### Base Template

Create `application/views/view_app_base.html`:

```twig
<!DOCTYPE html>
<html>
<head>
    <title>{{ content.title }} - {{ application_title }}</title>
    {% if head %}
        {{ head|raw }}
    {% endif %}
</head>
<body>
    {% block content %}{% endblock %}
</body>
</html>
```

### Available Twig Filters

The framework includes custom Twig filters:

* `base64_encode`: Base64 encoding
* `brackets2tag`: Convert `[text]` to HTML tags
* `date_PL`: Format date as DD.MM.YYYY
* `duration`: Format duration (seconds to HH:MM:SS)
* `declination`: Polish declination (1 obiekt, 2 obiekty, 5 obiektów)
* `dozeruj`: Zero-pad numbers
* `filesize`: Format file size
* `nospaces`: Replace spaces with  
* `shuffle`: Shuffle array
* `szewce`: Polish typography (non-breaking spaces)
* `time`: Extract time from datetime

### Custom Twig Extensions

Add custom filters in `application/Twig/Filter/`:

```php
<?php
namespace App\Twig\Filter;

class CustomFilter
{
    public function getName(): string
    {
        return 'custom_filter';
    }
    
    public function filter($value)
    {
        // Process $value
        return $processed_value;
    }
}
```

Add functions in `application/Twig/Function/` and globals in `application/Twig/Global/`.

### SVG and Sprite Support

```twig
<!-- SVG -->
[[svg::icon-name]]

<!-- Sprite -->
[[sprite::icon-name]]
```


---

## Database

### Database Connection

The framework uses `_uho_mysqli` for database connections. Configure in `config.php`:

```php
'sql_host' => 'localhost',
'sql_user' => 'username',
'sql_pass' => 'password',
'sql_base' => 'database_name',
```

### Direct Database Access

```php
// In model
$sql = $this->sql; // mysqli instance

// Execute query
$result = $this->sql->query("SELECT * FROM table");
```

### SQL Safety

Always use ORM methods or parameterized queries. The framework provides:

```php
$safe_string = $this->sqlSafe($user_input);
```


---

## Security

### CSRF Protection

#### Generate Token

```php
// In controller constructor (automatic)
$this->model->csrf_token_create($domain);
```

#### Use in Forms

```html
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $model->csrf_token_value(); ?>">
    <!-- form fields -->
</form>
```

#### Verify Token

```php
if ($this->model->csrf_token_verify($_POST['csrf_token'])) {
    // Process form
} else {
    // Invalid token
}
```

### SQL Injection Prevention

* Use ORM methods (automatically escaped)
* Use `sqlSafe()` for raw queries
* Never concatenate user input into SQL

### XSS Prevention

* Twig automatically escapes output
* Use `|raw` filter only when necessary
* Sanitize user input before storing


---

## Features

### Multi-language Support

```php
// In model
$this->lang; // Current language code
$this->lang_add; // Language suffix (_EN, _PL)

// In controller
$this->data['lang'] = $this->model->lang;
```

### Image Processing

```php
// Get image with thumbnail
$image = $this->model->orm->getImageThumb('/public/upload/image.jpg', 200, 200);

// Check if image exists
if ($this->model->file_exists('/public/upload/image.jpg')) {
    // Image exists
}
```

### Caching

```php
// Use cache class
$cache = new \SimplePHPCache\cache();
$cache->set('key', $data, 3600); // Cache for 1 hour
$data = $cache->get('key');
```

### Mailer

```php
$mailer = new \Huncwot\UhoFramework\_uho_mailer($this->model->smtp);
$mailer->send('to@example.com', 'Subject', 'Message');
```

### REST API Helpers

```php
use Huncwot\UhoFramework\_uho_rest;

// Set HTTP status
_uho_rest::setHttpStatusHeader(200);

// Validate request method
if (!_uho_rest::validateHttpRequestMethod('POST', ['POST', 'PUT'])) {
    _uho_rest::setHttpStatusHeader(405);
    exit;
}
```

### AWS S3 Integration

Configure in `config.php`:

```php
's3' => [
    'host' => 'https://s3.amazonaws.com/bucket',
    'folder' => 'uploads',
    'cache' => true,
    'compress' => true
]
```

Files uploaded via ORM are automatically synced to S3.

### Auth0 Integration

```php
$auth0 = new \Huncwot\UhoFramework\_uho_client_auth0($config);
$user = $auth0->getUser();
```

### Social Media Integration

```php
$social = new \Huncwot\UhoFramework\_uho_social($config);
// Facebook, Google, etc.
```


---

## API Reference

### _uho_application

Main application class.

**Constructor:**

```php
new _uho_application($root_path, $development, $config_folder, $force_ssl)
```

**Methods:**

* `getOutput($type)`: Get application output

### _uho_model

Base model class.

**Methods:**

* `get($name, $filters, $single, $order, $limit, $params)`: Get model data
* `post($model, $data, $multiple)`: Create model
* `put($model, $data, $filters, $multiple)`: Update model
* `delete($model, $filters, $multiple)`: Delete model
* `query($query, $single, $stripslashes, $key, $do_field_only)`: Raw SQL read
* `queryOut($query)`: Raw SQL write
* `csrf_token_create($uid, $force)`: Create CSRF token
* `csrf_token_verify($token)`: Verify CSRF token
* `getApiKeys($section)`: Get API keys from config

### _uho_controller

Base controller class.

**Properties:**

* `$data`: Data array passed to view
* `$model`: Model instance
* `$view`: View instance
* `$outputType`: Output type (html, json, etc.)
* `$get`: GET parameters
* `$post`: POST parameters

**Methods:**

* `getData()`: Override to set data
* `getOutput($type)`: Get output
* `get404($url)`: Handle 404

### _uho_view

View class using Twig.

**Methods:**

* `getHtml($data)`: Render HTML
* `getContentHtml($data, $view)`: Render content only
* `renderSprite($html)`: Process sprite syntax
* `renderSVG($html)`: Process SVG syntax

### _uho_route

Routing class.

**Methods:**

* `getRouteClass()`: Get current route class
* `getLang()`: Get current language
* `getUrlArray()`: Get URL segments
* `setCookieLang($lang)`: Set language cookie

### _uho_fx

Utility functions class.

**Methods:**

* `isAjax()`: Check if request is AJAX
* `sqlNow()`: Get current MySQL datetime
* `sqlToday()`: Get current MySQL date
* `getGet($param, $default)`: Get GET parameter
* `getPost($param, $default)`: Get POST parameter
* `getGetArray()`: Get all GET parameters


---

## Examples

### Complete Example: Blog Post

**Route** (`application/routes/route_app.json`):

```json
{
    "controllers": {
        "post": "post"
    }
}
```

**Model** (`application/models/model_app_post.php`):

```php
<?php
class model_app_post extends \Huncwot\UhoFramework\_uho_model
{
    public function getData()
    {
        $slug = $this->route->getUrlArray()[1] ?? null;
        
        if ($slug) {
            $this->data['post'] = $this->get('posts', ['slug' => $slug], true);
            if (!$this->data['post']) {
                $this->data['404'] = true;
            }
        } else {
            $this->data['posts'] = $this->get('posts', ['published' => 1], false, 'created_at DESC');
        }
    }
}
```

**Controller** (`application/controllers/controller_app_post.php`):

```php
<?php
class controller_app_post extends \Huncwot\UhoFramework\_uho_controller
{
    public function getData()
    {
        parent::getData();
        
        if (isset($this->data['404'])) {
            $this->data = $this->get404();
            return;
        }
        
        $this->data['view'] = isset($this->data['post']) ? 'post_single' : 'post_list';
        $this->data['content'] = $this->model->data;
    }
}
```

**View** (`application/views/view_app_post_single.html`):

```twig
{% extends "view_app_base.html" %}

{% block content %}
    <article>
        <h1>{{ content.post.title }}</h1>
        <div class="meta">
            <span>{{ content.post.created_at|date_PL }}</span>
        </div>
        <div class="content">
            {{ content.post.body|raw }}
        </div>
    </article>
{% endblock %}
```

### REST API Example

**Controller**:

```php
<?php
class controller_app_api extends \Huncwot\UhoFramework\_uho_controller
{
    public function getData()
    {
        $this->outputType = 'json';
        
        if (!_uho_rest::validateHttpRequestMethod('GET', ['GET'])) {
            _uho_rest::setHttpStatusHeader(405);
            $this->data['content'] = ['error' => 'Method not allowed'];
            return;
        }
        
        $items = $this->model->get('items');
        $this->data['content'] = ['items' => $items];
    }
}
```


---

## Schema Validation

The framework includes a schema validation tool:

```bash
chmod +x bin/schema-validate
./bin/schema-validate /path/to/application/models/json
```

This validates all JSON model definitions in the specified directory.


---

## Best Practices


 1. **Always use ORM methods** instead of raw SQL when possible
 2. **Validate user input** before processing
 3. **Use CSRF tokens** for all forms
 4. **Set** `development` to false in production
 5. **Use environment variables** for sensitive data
 6. **Follow naming conventions**: `controller_app_*`, `model_app_*`, `view_app_*`
 7. **Keep JSON models** in `application/models/json/`
 8. **Use Twig filters** for formatting instead of PHP in templates
 9. **Cache expensive operations** when appropriate
10. **Test with schema validation** before deploying


---

## Troubleshooting

### Common Issues

**"No ROUTING config found"**

* Ensure `application/routes/route_app.json` exists
* Check `application_class` in config matches route filename

**Database connection error**

* Verify database credentials in config
* Check MySQL server is running
* Ensure `nosql` is not set to `true`

**Template not found**

* Check view file exists in `application/views/`
* Verify naming: `view_app_{viewname}.html`
* Check `template_prefix` matches application class

**CSRF token mismatch**

* Ensure session is started
* Check token is included in form
* Verify domain matches token creation domain


---

## License

MIT License - See LICENSE file for details.


---

## Support

For issues and questions:

* Email: lukasz@knasiecki.com


---

*Last updated: 2025-12*