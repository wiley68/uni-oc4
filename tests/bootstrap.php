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
        foreach (['persistence_exception.php', 'cp_exception.php', 'shop_snapshot_validation_exception.php', 'unavailable_scheme_exception.php', 'order_materialization_exception.php', 'product_financing_flow_exception.php'] as $exceptionFile) {
            $exceptionPath = dirname(__DIR__) . '/system/library/' . $exceptionFile;
            if (is_file($exceptionPath)) {
                require_once $exceptionPath;
            }
        }

        return;
    }

    $snake = strtolower((string) preg_replace('~([a-z])([A-Z]|[0-9])~', '$1_$2', $relative));
    $file = dirname(__DIR__) . '/system/library/' . str_replace('\\', '/', $snake) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
