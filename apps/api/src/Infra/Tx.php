<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

use PDO;
use Throwable;

final class Tx
{
    /**
     * Runs $work in a transaction, committing on return and rolling back on any exception.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(PDO $db, callable $work): mixed
    {
        $db->beginTransaction();
        try {
            $result = $work();
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
