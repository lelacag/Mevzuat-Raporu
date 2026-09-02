<?php
namespace App;

/**
 * Lightweight PHP router.
 *
 * Supports static routes, parameterized patterns, regex patterns,
 * 301 redirects, and a catch-all fallback.
 *
 * The resolve() method returns a match array instead of directly
 * requiring the file, so the caller can include the file at the
 * correct scope (global scope in dispatch.php).
 */
class Router
{
    /** @var array Static path → handler (fastest lookup) */
    private array $statics = [];

    /** @var array Regex pattern → [handler, paramNames] */
    private array $patterns = [];

    /** @var array Static path → redirect target */
    private array $redirects = [];

    /** @var callable|null */
    private $fallback = null;

    /**
     * Register a static route.
     */
    public function map(string $path, string $handler): self
    {
        $this->statics[$path] = $handler;
        return $this;
    }

    /**
     * Register a parameterized route.
     */
    public function pattern(string $regex, string $handler, array $paramMap = []): self
    {
        $this->patterns[] = [$regex, $handler, $paramMap];
        return $this;
    }

    /**
     * Register a 301 redirect.
     */
    public function redirect(string $from, string $to): self
    {
        $this->redirects[$from] = $to;
        return $this;
    }

    /**
     * Set the fallback handler (404 / catch-all).
     */
    public function fallback(callable $handler): self
    {
        $this->fallback = $handler;
        return $this;
    }

    /**
     * Resolve the current request to a route match.
     *
     * Returns an array with:
     *   'file'   => string|null   absolute path to the PHP handler
     *   'params' => array         $_GET params to inject
     *   'action' => string        'require'|'redirect'|'callback'|'not-found'
     *   'target' => string|null   redirect target URL (for action=redirect)
     *   'callback' => callable|null  fallback callback
     *   'callbackArgs' => array   arguments for the fallback callback
     *
     * @param string $basePath Root directory of the application
     */
    public function resolve(string $basePath): array
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rawurldecode($uri);
        $path = rtrim($uri, '/');
        if ($path === '') {
            $path = '/';
        }

        // 1. Static redirects (301)
        if (isset($this->redirects[$path])) {
            return [
                'action' => 'redirect',
                'target' => $this->redirects[$path],
                'file' => null,
                'params' => [],
            ];
        }

        // 2. Static route match (fastest)
        if (isset($this->statics[$path])) {
            $file = $basePath . '/' . $this->statics[$path];
            if (file_exists($file)) {
                return [
                    'action' => 'require',
                    'file' => $file,
                    'params' => [],
                ];
            }
        }

        // 3. Pattern match
        foreach ($this->patterns as [$regex, $handler, $paramMap]) {
            if (preg_match($regex, $path, $m)) {
                $params = [];
                foreach ($paramMap as $capture => $getKey) {
                    if (isset($m[$capture])) {
                        $params[$getKey] = $m[$capture];
                    }
                }
                $file = $basePath . '/' . $handler;
                if (file_exists($file)) {
                    return [
                        'action' => 'require',
                        'file' => $file,
                        'params' => $params,
                    ];
                }
            }
        }

        // 4. Legacy .php passthrough — serve existing top-level .php files
        //    e.g. /delete_post_confirm.php → delete_post_confirm.php
        if (preg_match('#^/([a-zA-Z0-9_-]+\.php)$#', $path, $m)) {
            $file = $basePath . '/' . $m[1];
            if (file_exists($file)) {
                return [
                    'action' => 'require',
                    'file' => $file,
                    'params' => [],
                ];
            }
        }

        // 5. Fallback
        if ($this->fallback) {
            return [
                'action' => 'callback',
                'callback' => $this->fallback,
                'callbackArgs' => [$path, $basePath],
                'file' => null,
                'params' => [],
            ];
        }

        return ['action' => 'not-found', 'file' => null, 'params' => []];
    }
}