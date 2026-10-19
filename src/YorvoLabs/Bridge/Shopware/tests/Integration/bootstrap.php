<?php

declare(strict_types=1);

use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;

$autoloadPaths = [
    '/var/www/html/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$classLoader = null;

foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        $classLoader = require $autoloadPath;
        break;
    }
}

if ($classLoader === null) {
    throw new RuntimeException('Could not locate Composer autoload.php for integration tests.');
}

KernelLifecycleManager::prepare($classLoader);
