<?php

/*
    UHO-MVC Worker

    Calls an application route from the command line. The script is
    location-independent: every path it needs is passed in, so it can live
    anywhere and be run from any working directory.

    See: sh worker --help
*/

use Huncwot\UhoFramework\_uho_load_env;

if (PHP_SAPI !== 'cli')
{
    http_response_code(403);
    exit("This script must be run from the command line (sh request).\n");
}

/*
    Console output helpers

    Everything this script prints goes to STDERR, so STDOUT stays a clean
    JSON stream that can be piped or redirected. Colors are dropped when
    STDERR is not a terminal (cron, log files).
*/

$colors = function_exists('stream_isatty') && @stream_isatty(STDERR);

$RED   = $colors ? "\033[0;31m" : '';
$GREEN = $colors ? "\033[0;32m" : '';
$NC    = $colors ? "\033[0m"    : '';

function worker_out(string $text): void
{
    fwrite(STDERR, $text);
}

function worker_fail(string $message): void
{
    global $RED, $NC;
    worker_out("{$RED}Error:{$NC} $message\n");
    exit(1);
}

/*
    Arguments

    --base, --env, --config and --path are all required, and the three paths
    must be absolute: this script is run from cron and from shells whose
    working directory is arbitrary, so guessing any of them would resolve
    differently depending on the caller.
*/

function worker_usage(): string
{
    global $methods;

    return "\nUsage: sh worker --base=PATH --env=PATH --config=PATH --path=ROUTE\n\n"
        . "Required:\n"
        . "  -b, --base=PATH      project folder holding index.php and vendor/, absolute path\n"
        . "  -e, --env=PATH       ENV file, absolute path\n"
        . "  -c, --config=PATH    application config folder, absolute path\n"
        . "  -p, --path=ROUTE     route to call, e.g. api/worker\n\n"
        . "Optional:\n"
        . "  -m, --method=VERB    HTTP method (default: GET), one of " . implode(', ', $methods) . "\n"
        . "  -a, --auth=USER:PASS HTTP Basic credentials, when the app is behind ENV.APP_PASSWORD\n"
        . "  -h, --help           show this help\n\n"
        . "--base, --env and --config must be absolute paths, so the command behaves\n"
        . "the same from any working directory and this script can live outside the\n"
        . "project it runs.\n\n"
        . "Example:\n"
        . "  sh request --base=/var/www/html \\\n"
        . "            --env=/var/www/html/application_config/.env \\\n"
        . "            --config=/var/www/html/application_config \\\n"
        . "            --path=api/worker \\\n"
        . "            --auth=user:password\n\n";
}

$methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

$options = [
    'base'   => null,
    'env'    => null,
    'config' => null,
    'path'   => null,
    'method' => 'GET',
    'auth'   => null
];

$argv_rest = array_slice($argv, 1);

while ($argv_rest)
{
    $arg = array_shift($argv_rest);

    if ($arg === '--help' || $arg === '-h')
    {
        worker_out("\n{$GREEN}UHO-MVC Request{$NC}\n" . worker_usage());
        exit(0);
    }

    if (preg_match('/^--(base|env|config|path|method|auth)=(.*)$/', $arg, $m))
    {
        $options[$m[1]] = $m[2];
        continue;
    }

    $short = ['-b' => 'base', '-e' => 'env', '-c' => 'config', '-p' => 'path', '-m' => 'method', '-a' => 'auth'];
    $long  = ['--base' => 'base', '--env' => 'env', '--config' => 'config', '--path' => 'path', '--method' => 'method', '--auth' => 'auth'];
    $key   = $short[$arg] ?? $long[$arg] ?? null;

    if ($key)
    {
        if (!$argv_rest) worker_fail("Missing value for $arg");
        $options[$key] = array_shift($argv_rest);
        continue;
    }

    worker_fail("Unknown argument: $arg (try --help)");
}

$missing = [];
if (!$options['base'])   $missing[] = '--base';
if (!$options['env'])    $missing[] = '--env';
if (!$options['config']) $missing[] = '--config';
if (!$options['path'])   $missing[] = '--path';

if ($missing)
{
    worker_out("\n{$RED}Error:{$NC} missing required parameter" . (count($missing) > 1 ? 's' : '')
        . ': ' . implode(', ', $missing) . "\n" . worker_usage());
    exit(1);
}

$options['method'] = strtoupper(trim($options['method']));

if (!in_array($options['method'], $methods, true))
{
    worker_fail("Unsupported method: {$options['method']} (expected one of " . implode(', ', $methods) . ')');
}

/*
    Credentials

    --auth mirrors what a browser sends when the app is protected by
    ENV.APP_PASSWORD: the framework reads PHP_AUTH_USER/PHP_AUTH_PW, so the
    pair is injected here rather than being passed to the route.
*/

$auth_user = null;
$auth_pass = null;

if ($options['auth'] !== null)
{
    if (strpos($options['auth'], ':') === false)
    {
        worker_fail('--auth must be given as USER:PASSWORD');
    }

    list($auth_user, $auth_pass) = explode(':', $options['auth'], 2);

    if ($auth_user === '') worker_fail('--auth is missing the user part (expected USER:PASSWORD)');
}

/*
    Paths
*/

function worker_is_absolute(string $path): bool
{
    return $path !== '' && ($path[0] === '/' || (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $path));
}

foreach (['base' => '--base', 'env' => '--env', 'config' => '--config'] as $key => $flag)
{
    if (!worker_is_absolute($options[$key]))
    {
        worker_fail("$flag must be an absolute path (got: {$options[$key]})");
    }
}

$base        = rtrim($options['base'], '/');
$env_path    = rtrim($options['env'], '/');
$config_path = rtrim($options['config'], '/');

if (!is_dir($base))                             worker_fail("Base folder not found: $base");
if (!file_exists($base . '/index.php'))         worker_fail("No index.php in base folder: $base");
if (!file_exists($base . '/vendor/autoload.php')) worker_fail("No vendor/autoload.php in base folder: $base (run composer install)");
if (!file_exists($env_path))                    worker_fail("ENV file not found: $env_path");
if (!is_dir($config_path))                      worker_fail("Config folder not found: $config_path");
if (!file_exists($config_path . '/config.php')) worker_fail("No config.php in config folder: $config_path");

/*
    Bootstrap

    The autoloader, DOCUMENT_ROOT and SCRIPT_FILENAME all come from --base
    rather than from this file's own location, so the framework resolves
    root_path exactly as it would when index.php is the entry point.
*/

require_once $base . '/vendor/autoload.php';

$_SERVER['DOCUMENT_ROOT']   = $base;
$_SERVER['SCRIPT_FILENAME'] = $base . '/index.php';

worker_out("\n{$GREEN}UHO-MVC Request{$NC}\n");
worker_out("  base:   $base\n");
worker_out("  env:    $env_path\n");
worker_out("  config: $config_path\n");
worker_out("  call:   {$options['method']} /" . trim($options['path'], '/') . "\n");
if ($auth_user !== null) worker_out("  auth:   $auth_user:" . str_repeat('*', max(strlen($auth_pass), 1)) . "\n");
worker_out("\n");

/*
    Env read
*/

$env_loader = new _uho_load_env($env_path);
$env_loader->load();

if (!getenv('DOMAIN')) worker_fail("No DOMAIN defined in $env_path");

// The router explodes BASH_REQUEST_URI on '/', so a leading or trailing
// slash would produce an empty segment and fail to match any route.
$_SERVER['HTTP_HOST']        = getenv('DOMAIN');
$_SERVER['BASH_REQUEST_URI'] = trim($options['path'], '/');
$_SERVER['REQUEST_METHOD']   = $options['method'];

// Basic auth, as mod_php would expose it; the Authorization header is set too
// for the CGI/FastCGI code paths that read it instead of PHP_AUTH_*.
if ($auth_user !== null)
{
    $_SERVER['PHP_AUTH_USER']      = $auth_user;
    $_SERVER['PHP_AUTH_PW']        = $auth_pass;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($auth_user . ':' . $auth_pass);
}

/*
    Run

    The framework echoes its JSON response, so the output is buffered and
    inspected here. Reporting runs from a shutdown handler too, in case the
    framework exits on its own.
*/

$reported = false;

function worker_report(): void
{
    global $reported, $RED, $GREEN, $NC;

    if ($reported) return;
    $reported = true;

    $output = '';
    while (ob_get_level() > 0) $output = ob_get_clean() . $output;

    $output = trim($output);

    if ($output === '')
    {
        worker_out("{$RED}Error:{$NC} no response returned by the worker.\n");
        exit(1);
    }

    // pass the raw response through, so the command stays pipeable
    fwrite(STDOUT, $output . "\n");

    $json = json_decode($output, true);

    if (!is_array($json))
    {
        worker_out("\n{$RED}Error:{$NC} response is not valid JSON (" . json_last_error_msg() . ").\n");
        exit(1);
    }

    // the API returns 'message' on the worker route, 'error' on router failures
    $message = (string)($json['message'] ?? $json['error'] ?? '');

    if (!empty($json['result']))
    {
        worker_out("\n{$GREEN}Success:{$NC} " . ($message !== '' ? $message : 'worker finished') . "\n\n");
        exit(0);
    }

    worker_out("\n{$RED}Error:{$NC} " . ($message !== '' ? $message : 'worker reported result=false') . "\n\n");
    exit(1);
}

register_shutdown_function('worker_report');

ob_start();

(new \Huncwot\UhoFramework\_uho_mvc([
    'config_folder' => $config_path
]))->run();

worker_report();
