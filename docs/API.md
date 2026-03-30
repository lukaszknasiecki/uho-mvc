# API Layer Documentation

This document covers the two predefined classes for building REST APIs:
`_uho_controller_api` and `_uho_model_api`.

---

## Table of Contents

1. [Overview](#overview)
2. [_uho_controller_api](#_uho_controller_api)
3. [_uho_model_api](#_uho_model_api)
4. [Routing Configuration](#routing-configuration)
5. [API Sub-models](#api-sub-models)
6. [Authentication](#authentication)
7. [CAPTCHA Protection](#captcha-protection)
8. [Usage Example](#usage-example)

---

## Overview

The API layer provides a structured way to expose REST endpoints. Requests are dispatched by `_uho_controller_api` to `_uho_model_api`, which resolves the action path and delegates to a dedicated sub-model class (`model_app_api_*.php`).

Typical route configuration:

```json
{
    "controllers": {
        "api": "api"
    }
}
```

All requests under `/api/...` are handled by this layer and return JSON.

---

## _uho_controller_api

**File:** `src/_uho_controller_api.php`
**Extends:** `_uho_controller`
**Namespace:** `Huncwot\UhoFramework`

Handles HTTP request parsing and dispatches to the model. Always outputs JSON.

### Request flow

1. Strips the leading `/api` segment from the URL path.
2. Determines the HTTP method (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`).
3. Collects input data:
   - `GET` → query string parameters only
   - `POST` → POST body + optional JSON/form body from `php://input`
   - `PUT`, `PATCH`, `DELETE` → query string + body from `php://input`
4. Calls `$this->model->request($method, $action, $data, $this->cfg)`.
5. Runs the result through `$this->route->updatePaths()` for URL resolution.
6. Sets `$this->outputType = 'json'`.

### Body parsing

The controller reads `php://input` and parses it as:
- **JSON** — when `Content-Type: application/json`
- **Form-encoded** — otherwise (using `parse_str`)

Parsed body data is merged with GET/POST parameters. Body values take precedence over query string values on `PUT`/`PATCH`/`DELETE`. On `POST`, body values take precedence over POST superglobal values.

### Extending the controller

For most cases no extension is needed. To add pre-processing:

```php
<?php
class controller_app_api extends \Huncwot\UhoFramework\_uho_controller_api
{
    public function actionBefore($post, $get): void
    {
        parent::actionBefore($post, $get);
        // Custom pre-processing
    }
}
```

---

## _uho_model_api

**File:** `src/_uho_model_api.php`
**Extends:** `_uho_model`
**Namespace:** `Huncwot\UhoFramework`

Handles routing, authentication, CAPTCHA validation, and sub-model dispatch.

### Setup methods

#### `setRoutingNoAuth($items): void`

Registers route definitions accessible **without** authentication.

```php
$this->setRoutingNoAuth([
    'contact'      => 'contact',
    'GET.products' => 'products',
]);
```

#### `setRoutingAuth($items): void`

Registers route definitions accessible **only with** a valid bearer token.

```php
$this->setRoutingAuth([
    'profile'      => 'profile',
    'POST.orders'  => 'orders',
]);
```

#### `setCaptchaNoAuth($items): void`

Marks specific (unauthenticated) route classes as requiring a CAPTCHA token.

```php
$this->setCaptchaNoAuth(['contact']);
```

#### `setCaptchaAuth($items): void`

Marks specific (authenticated) route classes as requiring a CAPTCHA token.

#### `setPathModels($path): void`

Sets the directory path where `model_app_api_*.php` files are located.

```php
$this->setPathModels(__DIR__ . '/api/');
```

### Core method

#### `request($method, $action, $data, $cfg): array`

Main dispatch method called by the controller.

**Parameters:**

| Name      | Type   | Description                                |
|-----------|--------|--------------------------------------------|
| `$method` | string | HTTP method (`GET`, `POST`, etc.)          |
| `$action` | string | URL path after `/api/` (e.g. `products/42`) |
| `$data`   | array  | Merged request data (query + body)         |
| `$cfg`    | array  | Application config                         |

**Returns:** Array with result data. Includes a `header` key (HTTP status code) that is consumed to set the HTTP response code before being removed from the returned array.

**Process:**

1. Handles `OPTIONS` requests immediately (CORS preflight).
2. Checks for a bearer token; if valid, resolves `$user_id`.
3. Matches `$action` (and `$method.$action`) against registered routes.
4. Loads the sub-model class and checks CAPTCHA requirements.
5. Calls the method named after the HTTP method on the sub-model instance.
6. Returns 404 if no route or no result is found.

### Helper methods

#### `validateUserToken($token = null): array`

Validates a bearer token against the `client_tokens` table.

Accepts:
- `'test'` — resolves to session token for user 1 (development only)
- `'user_{id}'` — shortcut that returns user ID directly (development only)
- Any valid token string — checked against `client_tokens` with expiration validation

**Returns:**

```php
// Success
['header' => 200, 'result' => true, 'message' => '...', 'user' => 42]

// Failure
['header' => 401, 'error' => 'Authorization not valid']
```

#### `cacheApiKill($dir = 'cache'): void`

Deletes all `.cache` files from the specified directory (relative to `DOCUMENT_ROOT`). Useful to call after write operations that should invalidate cached responses.

```php
$this->cacheApiKill('cache/api');
```

#### `allowOptionsHeader(): void`

Responds to `OPTIONS` requests with `200 OK` and exits. Call at the start of `request()` to support CORS preflight. Called automatically inside `request()`.

---

## Routing Configuration

Routes are registered as key→value pairs. The key is the action path (optionally prefixed with `METHOD.`), the value is the sub-model class suffix.

| Key format            | Matches                                  |
|-----------------------|------------------------------------------|
| `'products'`          | Any HTTP method to `/api/products`       |
| `'GET.products'`      | Only `GET /api/products`                 |
| `'products/%'`        | `/api/products/{anything}`               |
| `'GET.products/%/%'`  | `GET /api/products/{x}/{y}`              |

Wildcard segments (`%`) are captured and passed to the sub-model as `$params`.

**Example routing setup in `model_app_api.php`:**

```php
<?php
class model_app_api extends \Huncwot\UhoFramework\_uho_model_api
{
    public function __construct($sql, $cfg)
    {
        parent::__construct($sql, $cfg);

        $this->setPathModels(__DIR__ . '/api/');

        $this->setRoutingNoAuth([
            'contact'           => 'contact',
            'GET.products'      => 'products',
            'GET.products/%'    => 'products',
        ]);

        $this->setRoutingAuth([
            'profile'           => 'profile',
            'POST.orders'       => 'orders',
        ]);

        $this->setCaptchaNoAuth(['contact']);
    }
}
```

---

## API Sub-models

Each route maps to final endpoint class file: `model_app_api_{class}.php` which
should extend `_uho_model_api_endpoint` class.

The class must implement methods named after HTTP verbs: `get`, `post`, `put`, `patch`, `delete`.

### Input validation

Input sent via GET/POST or url can be validated and sanitized automatically using static variables:

```php
    protected static $GET_ALLOWED_FIELDS = [
        'id' => ['integer', 'string']
    ];

    protected static $GET_REQUIRED_FIELDS = [
        'id'
    ];

    protected static $POST_ALLOWED_FIELDS = [
        'title' => 'string',
        'author' => 'string'
    ];

    protected static $POST_REQUIRED_FIELDS = [
        'title'
    ];
```

### Method signature

For non-authorized requests use:

```php
public function get($data, $cfg): array
```

For authorized requests use:

```php
public function get($user_id, $data, $cfg): array
```

| Parameter  | Type         | Description                                              |
|------------|--------------|----------------------------------------------------------|
| `$user_id` | int\|null    | Authenticated user ID, or `null` for unauthenticated requests |
| `$data`    | array        | Merged request data (query string + body)                |
| `$cfg`     | array        | Application config                                       |

**Returns:** Array that must include a `header` key with the HTTP status code:

```php
return ['header' => 200, 'result' => true, 'items' => $items];
return ['header' => 400, 'result' => false, 'error' => 'Invalid input'];
```

### Example sub-model

```php
<?php
// application/models/api/model_app_api_products.php

class model_app_api_products extends \Huncwot\UhoFramework\_uho_model
{
    public function get($user_id, $data, $cfg): array
    {
        if (!empty($params[0])) {
            // GET /api/products/{id}
            $item = $this->get('products', ['id' => $params[0]], true);
            if (!$item) return ['header' => 404, 'result' => false, 'error' => 'Not found'];
            return ['header' => 200, 'result' => true, 'item' => $item];
        }

        $items = $this->get('products', ['active' => 1]);
        return ['header' => 200, 'result' => true, 'items' => $items];
    }

    public function post($user_id, $data, $cfg): array
    {
        if (!$user_id) return ['header' => 401, 'result' => false, 'error' => 'Unauthorized'];

        $this->post('products', [
            'title'  => $data['title'] ?? '',
            'active' => 1,
        ]);

        return ['header' => 201, 'result' => true, 'id' => $this->getInsertId()];
    }
}
```

---

## Authentication

The API uses Bearer token authentication. The token is read from the `Authorization: Bearer {token}` header.

Tokens are stored in the `client_tokens` table with fields:

| Field        | Description                           |
|--------------|---------------------------------------|
| `value`      | Token string                          |
| `user`       | Associated user ID                    |
| `expiration` | Token expiry datetime                 |
| `type`       | Token type (e.g. `session`)           |

### Authenticated vs unauthenticated routes

- Routes in `setRoutingNoAuth` are always accessible.
- Routes in `setRoutingAuth` require a valid bearer token; unauthenticated requests receive a `401` response.
- If a bearer token is present and valid, authenticated routes take priority over unauthenticated ones with the same path.

### Accessing user ID in sub-model

```php
public function get($user_id, $params, $data, $cfg): array
{
    if (!$user_id) return ['header' => 401, 'result' => false, 'error' => 'Login required'];

    $profile = $this->get('users', ['id' => $user_id], true);
    return ['header' => 200, 'result' => true, 'profile' => $profile];
}
```

---

## CAPTCHA Protection

CAPTCHA can be required per route, either via the legacy list-based approach or per-method using a PHP attribute.

### Attribute-based (recommended)

```php
<?php
use Huncwot\UhoFramework\Attributes\RequiresCaptcha;

class model_app_api_contact extends \Huncwot\UhoFramework\_uho_model
{
    #[RequiresCaptcha]
    public function post($user_id, $params, $data, $cfg): array
    {
        // Only reached after successful CAPTCHA verification
        // ...
        return ['header' => 200, 'result' => true];
    }
}
```

### List-based

Pass the sub-model class name to `setCaptchaNoAuth()` or `setCaptchaAuth()`:

```php
$this->setCaptchaNoAuth(['contact']);
```

All HTTP methods on `model_app_api_contact` will then require CAPTCHA.

The frontend must pass the reCAPTCHA v2 one-time token in the `captcha` field of the request body. See the [Google reCAPTCHA section in DOCUMENTATION.md](DOCUMENTATION.md#google-recaptcha) for configuration details.

---

## Usage Example

### Route config (`application/routes/route_app.json`)

```json
{
    "controllers": {
        "api": "api"
    }
}
```

### Model (`application/models/model_app_api.php`)

```php
<?php
class model_app_api extends \Huncwot\UhoFramework\_uho_model_api
{
    public function __construct($sql, $cfg)
    {
        parent::__construct($sql, $cfg);

        $this->setPathModels(__DIR__ . '/api/');

        $this->setRoutingNoAuth([
            'GET.products'   => 'products',
            'GET.products/%' => 'products',
            'contact'        => 'contact',
        ]);

        $this->setRoutingAuth([
            'profile' => 'profile',
        ]);

        $this->setCaptchaNoAuth(['contact']);
    }
}
```

### Controller (`application/controllers/controller_app_api.php`)

```php
<?php
class controller_app_api extends \Huncwot\UhoFramework\_uho_controller_api
{
    // No override needed for standard usage
}
```

### Sub-model (`application/models/api/model_app_api_products.php`)

```php
<?php
class model_app_api_products extends \Huncwot\UhoFramework\_uho_model
{
    public function get($user_id, $params, $data, $cfg): array
    {
        $items = $this->get('products', ['active' => 1]);
        return ['header' => 200, 'result' => true, 'items' => $items];
    }
}
```

### Request / response

```
GET /api/products
Authorization: Bearer my-token

HTTP/1.1 200 OK
Content-Type: application/json

{"result": true, "items": [...]}
```
