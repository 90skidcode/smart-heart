<?php

declare(strict_types=1);

namespace SmartHeart\Http;

use SmartHeart\Infra\Uuid;
use Throwable;

/**
 * Turns a Request into a Response. Every response carries X-Request-Id; errors become
 * problem+json; unexpected exceptions are logged with the request id and shown only as a 500.
 */
final class Kernel
{
    /** @var callable(string): void */
    private $logError;

    public function __construct(
        private readonly Router $router,
        private readonly string $problemBaseUri,
        ?callable $logError = null,
    ) {
        $this->logError = $logError ?? static fn(string $line) => error_log($line);
    }

    public function handle(Request $request): Response
    {
        $requestId = Uuid::v4();
        $request = $request->withId($requestId);
        try {
            [$handler, $params] = $this->router->match($request->method, $request->path);
            $response = $handler($request, $params);
        } catch (HttpError $e) {
            $response = Response::problem($e, $this->problemBaseUri, $requestId);
        } catch (Throwable $e) {
            ($this->logError)(sprintf('[%s] %s %s → %s: %s at %s:%d', $requestId, $request->method, $request->path, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $response = Response::problem(
                new HttpError(500, 'internal', 'Internal error', 'Something went wrong. Quote the request id when reporting it.'),
                $this->problemBaseUri,
                $requestId,
            );
        }
        $response->headers += [
            'X-Request-Id' => $requestId,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        return $response;
    }
}
