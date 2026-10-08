<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/DistributionPackage.php';

try {
    if ($argc !== 1) {
        throw new RuntimeException('Use composer package without filename or environment arguments.');
    }
    $packager = new MtUniCredit\Build\DistributionPackage(dirname(__DIR__));
    $path = $packager->build();
    $count = $packager->verify($path);
    fwrite(STDOUT, "Installer: $path\nFiles: $count\nSize: " . filesize($path) . " bytes\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Package build failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
