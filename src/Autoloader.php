<?php
namespace App;

class Autoloader
{
    private static string $baseDir;
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$baseDir = __DIR__ . DIRECTORY_SEPARATOR;
        spl_autoload_register([self::class, 'loadClass']);
        self::$registered = true;
    }

    public static function loadClass(string $class): void
    {
        $prefix = 'App\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relativeClass = substr($class, $len);
        $file = self::$baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
}
