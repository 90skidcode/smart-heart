<?php

declare(strict_types=1);

namespace SmartHeart\System;

use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Infra\Database;
use Throwable;

/** GET /health — liveness and readiness. 503 when the database cannot be reached. */
final readonly class HealthController
{
    public function __construct(private Database $db, private string $version)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $this->db->pdo()->query('SELECT 1')->fetchColumn();
            return Response::json(['status' => 'ok', 'db' => 'ok', 'version' => $this->version]);
        } catch (Throwable) {
            return Response::json(['status' => 'degraded', 'db' => 'unreachable', 'version' => $this->version], 503);
        }
    }
}
