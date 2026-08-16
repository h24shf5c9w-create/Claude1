<?php

declare(strict_types=1);

namespace RoyalSpin\Http;

use RoyalSpin\Support\Session;

/**
 * Tiny regex router with per-route middleware flags.
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, handler:callable, auth:bool, csrf:bool}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler, bool $auth = false): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $pattern, 'handler' => $handler, 'auth' => $auth, 'csrf' => false];
    }

    public function post(string $pattern, callable $handler, bool $auth = false, bool $csrf = true): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $pattern, 'handler' => $handler, 'auth' => $auth, 'csrf' => $csrf];
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }

            if ($route['csrf'] && !Session::verifyCsrf($request->csrfToken())) {
                if ($request->wantsJson) {
                    Response::error('Your session expired. Please reload the page.', 419);
                }
                Session::flash('error', 'Your session expired. Please try again.');
                Response::redirect('/');
            }

            if ($route['auth'] && Session::userId() === null) {
                if ($request->wantsJson) {
                    Response::error('You need to be signed in.', 401);
                }
                Response::redirect('/login');
            }

            ($route['handler'])($request, ...array_values($params));
            return;
        }

        if ($request->wantsJson) {
            Response::error('Not found.', 404);
        }
        Response::view('errors/404', ['title' => 'Page not found'], 404);
    }

    /**
     * Supports `{name}` placeholders, matching one path segment each.
     *
     * @return array<string,string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        if (!str_contains($pattern, '{')) {
            return $pattern === $path ? [] : null;
        }

        $names = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_]+)\}/',
            static function (array $matches) use (&$names): string {
                $names[] = $matches[1];
                return '([^/]+)';
            },
            $pattern
        ) ?? '';

        if (preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        array_shift($matches);
        $params = [];
        foreach ($names as $index => $name) {
            $params[$name] = $matches[$index] ?? '';
        }
        return $params;
    }
}
