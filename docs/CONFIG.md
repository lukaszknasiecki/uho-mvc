# Configuration options

You can configure your application with a list of predefined ENVs or custom ones.
By default application is using two config files:

`src/configs/application.php`

Settings for all instances of the application, regardless of the host.
You can modify/overwrite those settings by creating your own `application_config/config.php`.

`src/configs/hosts.php`

Settings per each hosts, usually connected with secret ENVs, you can modify/enlarge
set of those params with additional configuration file, located by default in `application_config/hosts.php`.

---

## Application Config ENVs

Application settings, useful for debug, versioning and caching.

| ENV | Default | Description |
|-----|---------|-------------|
| `APP_TIMEZONE` | `Europe/Berlin` | Sets app's timezone. |
| `APP_CACHE` | `0` | Enables app's caching (based on URL and ajax header). |
| `APP_CACHE_MINUTES` | `24H` | Max time of cached items. |
| `APP_CACHE_SALT` | `'uho'` | Encryption key for caching. |
| `APP_DEV_MODE` | `0` | Development mode — shows errors. |
| `APP_PASSWORD` | `user:password_bcrypt_hash` | Asks for a password before app runs. |
| `APP_SQL_DEBUG` | `0` | SQL debug mode — shows SQL queries in comments. |
| `APP_UPLOAD_SERVER` | null | Http server to read file uploads |
| `APP_UHO_ORM` | `1` | ORM version (`1`/`2`). |

For password hash you can use https://hostingcanada.org/htpasswd-generator/, choose Bcrypt.

## HOST based ENVs/Secrets

Default set of secrets defined in `src/configs/hosts.php` file.
You can modify this list by creting your own `application_config/hosts.php` file.


| Key | Type | Description |
|-----|------|-------------|
| `SQL_HOST` | `string` | MySQL host (e.g. `'localhost'`). |
| `SQL_USER` | `string` | MySQL username. |
| `SQL_PASS` | `string` | MySQL password. |
| `SQL_BASE` | `string` | MySQL database name. |
| `S3_HOST` | `string` | Full S3 bucket URL, if defined S3 is enabled |
| `S3_BUCKET` | `string` | S3 Bucket name |
| `S3_KEY` | `string` | S3 Access Key |
| `S3_SECRET` | `string` | S3 Access Secret |
| `S3_REGION` | `string` | S3 Bucker Region |
| `S3_FOLDER` | `string` | S3 Bucket folder |
| `S3_CACHE_FILE` | `string` | S3 Bucket Cache file, default=/cache/s3.files |
| `MAILCHIMP_KEY` | `string` | Mailchimp API key. |
| `MAILCHIMP_LIST_ID` | `string` | Mailchimp audience/list ID. |
| `GOOGLE_RECAPTCHA_PUBLIC` | `string` | Google reCAPTCHA site key (public). |
| `GOOGLE_RECAPTCHA_PRIVATE` | `string` | Google reCAPTCHA secret key (private). |
| `GOOGLE_OAUTH_CLIENT_ID` | `string` | Google OAuth 2.0 client ID. |
| `GOOGLE_OAUTH_CLIENT_SECRET` | `string` | Google OAuth 2.0 client secret. |
| `GOOGLE_OAUTH_REDIRECT_URI` | `string` | Google OAuth 2.0 redirect URI after authentication. |
| `GOOGLE_ANALYTICS_TAG` | `string` | Google Analytics measurement ID (e.g. `G-XXXXXXXXXX`). |
| `FACEBOOK_OAUTH_CLIENT_ID` | `string` | Facebook OAuth app ID. |
| `FACEBOOK_OAUTH_CLIENT_SECRET` | `string` | Facebook OAuth app secret. |
| `FACEBOOK_OAUTH_REDIRECT_URI` | `string` | Facebook OAuth redirect URI after authentication. |
| `SMTP_SERVER` | `string` | SMTP server hostname. |
| `SMTP_PORT` | `string` | SMTP server port (e.g. `587` or `465`). |
| `SMTP_SECURE` | `string` | SMTP encryption protocol (`tls` or `ssl`). |
| `SMTP_LOGIN` | `string` | SMTP authentication username. |
| `SMTP_PASS` | `string` | SMTP authentication password. |
| `SMTP_LOGIN` | `string` | SMTP authentication username (alias). |
| `SMTP_NAME` | `string` | Display name used as the email sender name. |


## application_config.php file custom minimal example

```php
<?php
$cfg = [
		'application_languages' =>		   	 ['en'],
		'application_languages_url' =>	 	 false,
];
```

---

## application_config.php file specs

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `application_title` | `string` | `'app'` | Human-readable application name. Available in Twig as `{{ application_title }}`. |
| `application_domain` | `string` | — | Primary domain (e.g. `example.com`). Used for URL generation and cookie scope. Can be overridden per-domain in `hosts.php`. |
| `application_url_prefix` | `string` | `''` | Optional path prefix prepended to all URLs (e.g. `'/app'`). |
| `strict_url_parts` | `bool` | — | When `true`, enforces strict URL segment matching in routing. |
| `no_session` | `bool` | `false` | When `true`, skips `session_start()`. Useful for pure API contexts. |
| `nosql` | `bool` | `false` | When `true`, disables database connection entirely. |
| `files_decache` | `mixed` | — | Controls file cache invalidation behaviour for the ORM. |
| `upload_server` | `string\|null` | Remote server URL for uploaded files. When set, `/public/upload/` paths in output are rewritten to this URL.

---

### Language

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `application_languages` | `string[]` | — | List of supported language codes, e.g. `['en', 'pl']`. The first entry is the fallback. |
| `application_languages_url` | `bool` | — | When `true`, language code is included in the URL (e.g. `/en/page`). When `false`, a fixed language is used and `application_language` is set automatically to `application_languages[0]`. |
| `application_languages_detect` | `array` | — | Maps Accept-Language values to language codes, e.g. `['en' => 'en', 'pl' => 'pl']`. |
| `application_languages_empty` | `string\|null` | `null` | Default language when no language prefix is present in the URL. |

---

## API Keys

Optional. Store third-party API credentials.

```php
'api_keys' => [
    'google'  => 'your-google-api-key',
    'facebook' => 'your-facebook-api-key',
    'google_recaptcha' => [
        'public'  => getenv('GOOGLE_RECAPTCHA_PUBLIC'),
        'private' => getenv('GOOGLE_RECAPTCHA_PRIVATE'),
    ],
],
```

Accessed in models via `$this->getApiKeys('google_recaptcha')`.

> `api_keys` defined in `hosts.php` **override** those in `config.php` entirely (not merged).

---

## Encryption Keys

Optional. Arbitrary key/value store for encryption or signing secrets.

```php
'keys' => [
    'encryption_key_1' => 'value1',
    'encryption_key_2' => 'value2',
],
```

Injected into the model via `$this->cms->setKeys(...)`.

---

## Hosts file

`application_config/hosts.php` maps domains to config overrides. The matching entry is **merged** on top of `config.php`, letting you maintain one base config and adjust per-environment.

## application_config.php file custom minimal example

```php
<?php
$cfg_domains = [
	getenv('DOMAIN') =>
	[		
		'api_keys' => [
			'vendor' =>
			[
				'key' => getenv('VENDOR_API_KEY'),
				'private' => getenv('VENDOR_API_PRIVATE')
			]
		]
	]
];
```
---

## Additional config (`config_additional.json`)

Optional JSON file at `application_config/config_additional.json`. Keys are merged on top of `config.php` via `array_merge`.

---

