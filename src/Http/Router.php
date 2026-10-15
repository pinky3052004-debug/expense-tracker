<?php
declare(strict_types=1);
namespace App\Http;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $req): void
    {
        foreach ($this->routes as [$method, $regex, $handler]) {
            if ($method === $req->method && preg_match($regex, $req->path, $m)) {
                $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($req);
                return;
            }
        }
        Response::json(['error' => 'Not found'], 404);
    }
}
