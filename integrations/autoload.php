<?php

/** Loads the reusable PHP build helper without Composer, provider registration, or database dependencies. */

declare(strict_types=1);

spl_autoload_register(
    /**
     * Resolves the package's PSR-4 classes from its trusted installation directory.
     *
     * @param string $className
     * @return void
     */
    static function (string $className): void {
        $prefix = 'Ordinal\\';
        if (str_starts_with($className, $prefix)) {
            $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($className, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    },
);
