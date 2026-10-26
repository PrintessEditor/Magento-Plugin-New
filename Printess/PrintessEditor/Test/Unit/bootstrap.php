<?php

declare(strict_types=1);

/**
 * Bootstrap for the module's pure unit tests: a minimal PSR-4 autoloader for Printess\PrintessEditor\ mapped to the
 * module root, so the PURE classes can be tested without a Magento installation. Classes that depend on Magento are
 * intentionally not loadable here.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Printess\\PrintessEditor\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__, 2) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
