<?php

namespace Huncwot\UhoFramework;

/**
 * Front-controller bootstrap for UHO MVC applications.
 *
 * Encapsulates environment setup, error handling, caching and output
 * that would otherwise live in the application's index.php.
 *
 * Usage in index.php:
 *
 *   require_once __DIR__ . '/vendor/autoload.php';
 *   (new \Huncwot\UhoFramework\_uho_mvc([
 *       'config_folder' => 'application_config',
 *       'timezone'      => 'Europe/Berlin'
 *   ]))->run();
 *
 * Supported config keys:
 *   root_path      – absolute path to the project root (default: dirname of SCRIPT_FILENAME)
 *   timezone       – PHP timezone string (default: 'Europe/Berlin')
 *   cache_enabled  – bool, enable HTML output caching (default: false)
 *   cache_minutes  – cache TTL in minutes (default: 1440)
 *   config_folder  – application config folder name or path (default: 'application_config')
 *   development    – bool override; when omitted, auto-detected from HTTP_HOST (.lh / localhost)
 */
class _uho_mvc
{
    private float $timeStart;
    private bool $development;
    private string $rootPath;
    private string $cacheSalt;
    private bool $cacheEnabled;
    private int $cacheMinutes;
    private string $timezone;
    private string $configFolder;
    private int $orm_version;
    private int $sql_debug;

    public function __construct(array $config = [])
    {

        $this->timeStart = $this->microtimeFloat();
        $this->configFolder = $config['config_folder'] ?? 'application_config';

        if (file_exists($this->configFolder . '/.env'))
        {
            require_once('_uho_load_env.php');
            $env_loader = new _uho_load_env($this->configFolder . '/.env');
            $env_loader->load();
        }

        $this->timezone = getenv('APP_TIMEZONE') ?: 'Europe/Berlin';        

        $this->cacheEnabled = getenv('APP_CACHE') ?: 0;
        $this->cacheSalt = getenv('APP_CACHE_SALT') ?: 'uho';
        $this->cacheMinutes = getenv('APP_CACHE_MINUTES') ?: 60 * 24;

        $this->rootPath = getenv('APP_ROOT_PATH') ?: dirname($_SERVER['SCRIPT_FILENAME']) . '/';

        $this->development = getenv('APP_DEV_MODE') ?: 0;
        $this->sql_debug = getenv('APP_SQL_DEBUG') ?: 0;
        $this->orm_version = getenv('APP_UHO_ORM') ?: 1;

    }

    /**
     * Run the full bootstrap: configure environment, handle cache, run app, send output.
     */
    public function run(): void
    {
        date_default_timezone_set($this->timezone);
        
        if (!$this->development) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', 1);
        }
        
        if (!is_dir($this->rootPath . 'reports')) {
            mkdir($this->rootPath . 'reports');
            file_put_contents($this->rootPath . 'reports/.htaccess', 'Deny from all');
        }

        $this->setupErrorHandling();

        [$output, $header, $cached] = $this->resolveOutput();

        $this->sendHeader($header);
        echo $output;
        $this->echoTimer($header, $cached);
    }

    // -------------------------------------------------------------------------

    private function setupErrorHandling(): void
    {
        if (!defined('debug')) define('debug', $this->development);

        $logFile = sprintf('%sreports/php-errors-%s.txt', $this->rootPath, date('Ymd'));

        ini_set('log_errors', 1);
        ini_set('error_log', $logFile);
        ini_set('error_reporting', E_ALL ^ E_NOTICE);

        if ($this->development) {
            ini_set('display_errors', 1);
        } else {
            ini_set('display_errors', 0);
        }
    }

    /**
     * Returns [output, header, cached].
     */
    private function resolveOutput(): array
    {
        if ($this->cacheEnabled)
        {
            $cache = new _uho_cache($this->cacheSalt,true);
            $cache->eraseExpired();

            if ($cache->checkCache()) {
                $result = $cache->getCache();
                return [$result['output'], $result['header'], true];
            }
        }

        $app = new _uho_application(
            $this->rootPath,
            $this->development,
            $this->configFolder,
            false,
            [
                'orm_version' => $this->orm_version,
                'sql_debug' => $this->sql_debug
            ]
            );
        $type = _uho_fx::getGet('output') ?: null;
        $result = $app->getOutput($type);

        if ($this->cacheEnabled)
        {
            $cache->store(
                $cache->getKey(),
                ['output' => $result['output'], 'header' => $result['header']],
                $this->cacheMinutes * 60
            );
        }

        return [$result['output'], $result['header'], false];
    }

    private function sendHeader(string $header): void
    {
        switch ($header) {
            case 'json':
                header('Content-Type: application/json');
                break;
            case '404':
                header('HTTP/1.0 404 Not Found');
                break;
        }
    }

    private function echoTimer(string $header, bool $cached): void
    {
        if ($header === 'json' || $header === 'rss') return;

        $elapsed = $this->microtimeFloat() - $this->timeStart;
        echo $cached
            ? '<!-- cached in ' . $elapsed . ' -->'
            : '<!-- rendered in ' . $elapsed . ' -->';
    }

    private function microtimeFloat(): float
    {
        [$usec, $sec] = explode(' ', microtime());
        return (float)$usec + (float)$sec;
    }
}
