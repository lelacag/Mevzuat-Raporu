<?php
/**
 * Front Controller — single entry point for all HTTP requests.
 *
 * Apache routes all non-static requests here via .htaccess.
 * The Router resolves the URL and this file dispatches to the
 * correct PHP handler AT GLOBAL SCOPE (not inside a method).
 */

// Serve static files directly in PHP built-in server
if (PHP_SAPI === 'cli-server') {
    $staticFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($staticFile) && !str_ends_with($staticFile, '.php')) {
        return false;
    }
}

// Load the autoloader (idempotent — safe if loaded again by header.php)
require_once __DIR__ . '/src/Autoloader.php';
\App\Autoloader::register();

// Create and configure the router
$router = new \App\Router();
require __DIR__ . '/src/routes.php';

// Resolve the route
$__match = $router->resolve(__DIR__);
unset($router);

switch ($__match['action']) {
    case 'redirect':
        header('Location: ' . $__match['target'], true, 301);
        exit;

    case 'require':
        // Inject captured params into $_GET
        foreach ($__match['params'] as $__k => $__v) {
            $_GET[$__k] = $__v;
        }
        // Store file path, then clean up all dispatch vars
        $__dispatch_file = $__match['file'];
        unset($__match, $__k, $__v);
        // require at global scope — page has clean environment
        require $__dispatch_file;
        unset($__dispatch_file);
        break;

    case 'callback':
        ($__match['callback'])(...$__match['callbackArgs']);
        unset($__match);
        break;

    default:
        http_response_code(404);
        echo '404 Not Found';
}