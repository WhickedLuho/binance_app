<?php

declare(strict_types=1);

use Throwable;

ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

$appConfigFile = __DIR__ . '/../config/app.php';
$appConfig = is_file($appConfigFile) ? require $appConfigFile : [];
$timezone = is_array($appConfig) ? (string) ($appConfig['timezone'] ?? 'UTC') : 'UTC';
date_default_timezone_set($timezone !== '' ? $timezone : 'UTC');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        $baseDir = __DIR__ . '/../app/';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });
}

try {
    $app = new App\Bootstrap\App(
        configPath: __DIR__ . '/../config',
        viewPath: __DIR__ . '/../app/Views',
        basePath: dirname(__DIR__)
    );

    $app->run();
} catch (Throwable $throwable) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>500</h1><p>Internal server error.</p>';
}
