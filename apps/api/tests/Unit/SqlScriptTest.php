<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartHeart\Infra\SqlScript;

final class SqlScriptTest extends TestCase
{
    public function testSplitsOnSemicolonsOutsideStringsAndComments(): void
    {
        $sql = "-- a comment; not a split\nCREATE TABLE a (x VARCHAR(9) DEFAULT 'a;b'); # hash; comment\n"
            . "INSERT INTO a VALUES ('it''s; fine'), (\"q\\\";\"); /* block; */ SELECT `odd;name` FROM a;";

        self::assertSame([
            "CREATE TABLE a (x VARCHAR(9) DEFAULT 'a;b')",
            "INSERT INTO a VALUES ('it''s; fine'), (\"q\\\";\")",
            'SELECT `odd;name` FROM a',
        ], SqlScript::split($sql));
    }

    public function testDelimiterLinesForTriggers(): void
    {
        $sql = "CREATE TABLE t (id INT);\nDELIMITER $$\n"
            . "CREATE TRIGGER tr BEFORE UPDATE ON t FOR EACH ROW\nBEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'no'; END$$\n"
            . "DELIMITER ;\nDROP TABLE t;";

        self::assertSame([
            'CREATE TABLE t (id INT)',
            "CREATE TRIGGER tr BEFORE UPDATE ON t FOR EACH ROW\nBEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'no'; END",
            'DROP TABLE t',
        ], SqlScript::split($sql));
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        self::assertSame(['SELECT 5--2', 'SELECT 1'], SqlScript::split("SELECT 5--2;\nSELECT 1;"));
    }

    public function testBaselineMigrationSplitsIntoTheExpectedObjects(): void
    {
        $statements = SqlScript::split((string) file_get_contents(dirname(__DIR__, 4) . '/db/migrations/0001_baseline.sql'));
        $count = static fn(string $re) => count(preg_grep($re, $statements));

        self::assertSame(65, $count('/^CREATE TABLE /'));
        self::assertSame(2, $count('/^CREATE OR REPLACE VIEW /'));
        self::assertSame(2, $count('/^CREATE TRIGGER /'));
        self::assertSame([], preg_grep('/^(DELIMITER|USE |CREATE DATABASE)/i', $statements));
    }
}
