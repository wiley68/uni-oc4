<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Opencart\\System\\Library\\Extension\\MtUniCredit\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    if (str_ends_with($relative, 'Exception')) {
        $exceptionFile = dirname(__DIR__) . '/system/library/persistence_exception.php';
        if (is_file($exceptionFile)) {
            require_once $exceptionFile;
        }

        return;
    }

    $snake = strtolower((string) preg_replace('~([a-z])([A-Z]|[0-9])~', '$1_$2', $relative));
    $file = dirname(__DIR__) . '/system/library/' . str_replace('\\', '/', $snake) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
