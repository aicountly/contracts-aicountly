<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Path → controller@action routing.
 *
 * Same shape as Drive's router so the two products read alike, with one
 * addition: a matched route records its own pattern, which the rate limiter
 * uses as a bucket key so `/contracts/1` and `/contracts/2` share one budget
 * instead of each getting a fresh one.
 */
final class Router
{
    private static ?self $instance = null;

    /** @var list<array{method: string, pattern: string, route: string, params: list<string>, handler: string}> */
    private array $routes = [];

    private string $prefix = '';

    private static string $matchedRoute = '';

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** @internal tests only */
    public static function reset(): void
    {
        self::$instance     = null;
        self::$matchedRoute = '';
    }

    /** The route template that matched, e.g. `/api/contracts/{id}`. Empty before dispatch. */
    public static function matchedRoute(): string
    {
        return self::$matchedRoute;
    }

    public function group(string $prefix, callable $callback): void
    {
        $previous     = $this->prefix;
        $this->prefix = $this->join($this->prefix, $prefix);
        $callback($this);
        $this->prefix = $previous;
    }

    public function get(string $path, string $handler): void
    {
        $this->add(['GET'], $path, $handler);
    }

    public function post(string $path, string $handler): void
    {
        $this->add(['POST'], $path, $handler);
    }

    public function put(string $path, string $handler): void
    {
        $this->add(['PUT'], $path, $handler);
    }

    public function patch(string $path, string $handler): void
    {
        $this->add(['PATCH'], $path, $handler);
    }

    public function delete(string $path, string $handler): void
    {
        $this->add(['DELETE'], $path, $handler);
    }

    /** @param list<string> $methods */
    public function match(array $methods, string $path, string $handler): void
    {
        $this->add(array_map('strtoupper', $methods), $path, $handler);
    }

    /** @param list<string> $methods */
    private function add(array $methods, string $path, string $handler): void
    {
        $route  = $this->join($this->prefix, $path);
        $names  = [];
        $regex  = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_-]*)\}/',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];
                // {path} is the one greedy parameter — it stands for a whole
                // sub-path in proxy routes; everything else stops at a slash.
                return '(?P<' . $m[1] . '>' . ($m[1] === 'path' ? '.+' : '[^/]+') . ')';
            },
            $route
        );

        foreach ($methods as $method) {
            $this->routes[] = [
                'method'  => strtoupper($method),
                'pattern' => '#^' . $regex . '$#',
                'route'   => $route,
                'params'  => $names,
                'handler' => $handler,
            ];
        }
    }

    private function join(string $a, string $b): string
    {
        $a = '/' . trim($a, '/');
        $b = trim($b, '/');
        if ($b === '') {
            return $a === '//' ? '/' : $a;
        }

        return ($a === '/' ? '' : $a) . '/' . $b;
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        // CORS preflight never reaches a controller: it carries no credentials
        // and answering it from the router keeps every action free of the case.
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $allowedForPath = [];

        foreach ($this->candidates($path) as $candidate) {
            foreach ($this->routes as $route) {
                if (preg_match($route['pattern'], $candidate, $matches) !== 1) {
                    continue;
                }
                if ($route['method'] !== $method) {
                    $allowedForPath[$route['method']] = true;
                    continue;
                }

                $params = [];
                foreach ($route['params'] as $name) {
                    $params[$name] = $matches[$name] ?? null;
                }

                self::$matchedRoute = $route['route'];
                $this->invoke($route['handler'], $params);

                return;
            }
        }

        // A 405 with Allow is the difference between "you used the wrong verb"
        // and "this endpoint does not exist" — worth the extra pass for anyone
        // debugging an integration.
        if ($allowedForPath !== []) {
            header('Allow: ' . implode(', ', array_keys($allowedForPath)));
            Response::error('METHOD_NOT_ALLOWED', 'This endpoint does not accept ' . $method . '.', 405);
        }

        Response::error('ROUTE_NOT_FOUND', 'Route not found.', 404);
    }

    /**
     * The forms a path may arrive in.
     *
     * The API is deployed at `<docroot>/api`, so Apache may hand us the path
     * with or without the `/api` prefix depending on how the rewrite fired.
     * Trying both is what lets the same file serve a sub-folder mount and a
     * dedicated vhost without a config switch.
     *
     * @return list<string>
     */
    private function candidates(string $path): array
    {
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        $out = [$path];
        if ($path !== '/' && ! str_starts_with($path, '/api')) {
            $out[] = '/api' . $path;
        }
        if (str_starts_with($path, '/api/')) {
            $out[] = substr($path, 4) ?: '/';
        }

        return array_values(array_unique($out));
    }

    public static function requestPath(): string
    {
        $uri    = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        // Strip the directory the front controller is mounted under, so the
        // same file serves `<docroot>/api` and a dedicated vhost without a
        // config switch.
        if ($script !== '' && $script !== '/index.php') {
            $base = str_replace('\\', '/', dirname($script));
            if ($base !== '/' && $base !== '.' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base)) ?: '/';
            }
        }

        // `/index.php/health` reaches here when mod_rewrite is unavailable and
        // the caller addresses the script directly, and under PHP's built-in
        // server. Without this the path never matches a route and the endpoint
        // looks missing rather than mis-mounted.
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, strlen('/index.php')) ?: '/';
        }

        return $uri === '' ? '/' : $uri;
    }

    /** @param array<string,string|null> $params */
    private function invoke(string $handler, array $params): void
    {
        if (! str_contains($handler, '@')) {
            Response::error('ROUTE_MISCONFIGURED', 'Invalid route handler.', 500);
        }

        [$controllerPath, $action] = explode('@', $handler, 2);
        $class = 'App\\Controllers\\' . str_replace('/', '\\', $controllerPath);

        if (! class_exists($class)) {
            Response::error('ROUTE_MISCONFIGURED', 'Controller not found: ' . $class, 500);
        }

        $controller = new $class();
        if (! method_exists($controller, $action)) {
            Response::error('ROUTE_MISCONFIGURED', 'Action not found: ' . $class . '@' . $action, 500);
        }

        $reflection = new \ReflectionMethod($class, $action);
        $args       = [];
        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        $controller->{$action}(...$args);
    }
}
