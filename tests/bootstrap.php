<?php

declare(strict_types=1);

// Unit tests cover the plugin's Shopware-free classes (settings, tags, ESI
// decision, response policy, delivery) against the shared library only. The
// Shopware glue is tested live by tests/shopware6-e2e.
spl_autoload_register(static function (string $class): void {
    foreach (['Qoliber\\TridentShopware\\Tests\\' => __DIR__ . '/', 'Qoliber\\TridentShopware\\' => dirname(__DIR__) . '/src/', 'Qoliber\\Trident\\' => dirname(__DIR__, 4) . '/php-library/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
