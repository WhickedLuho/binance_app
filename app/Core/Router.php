<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function add(string $method, string $path, array $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method][$request->path] ?? null;
        if ($handler === null) {
            return Response::html('<h1>404</h1><p>A keresett oldal nem található.</p>', 404);
        }

        [$class, $method] = $handler;
        $controller = $this->container->get($class);

        return $controller->{$method}($request);
    }
}