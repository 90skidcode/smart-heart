<?php

declare(strict_types=1);

namespace SmartHeart\Http;

/**
 * Matches METHOD + path against patterns like /participants/{participantId}/visits/{visitCode}.
 * A path that exists under another method gives 405 with an Allow header; anything else 404.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable(Request, array<string, string>): Response}> */
    private array $routes = [];

    /** @param callable(Request, array<string, string>): Response $handler */
    public function add(string $method, string $pattern, callable $handler): void
    {
        // preg_quote turns {name} into \{name\}; swap each one for a named group.
        $regex = preg_replace('/\\\\\{([A-Za-z_]\w*)\\\\\}/', '(?P<$1>[^/]+)', preg_quote($pattern, '#'));
        $this->routes[] = ['method' => strtoupper($method), 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    /** @return array{0: callable(Request, array<string, string>): Response, 1: array<string, string>} */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] === $method || ($method === 'HEAD' && $route['method'] === 'GET')) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$route['handler'], array_map('rawurldecode', $params)];
            }
            $allowed[] = $route['method'];
        }
        if ($allowed !== []) {
            throw HttpError::methodNotAllowed(array_values(array_unique($allowed)));
        }
        throw HttpError::notFound();
    }
}
