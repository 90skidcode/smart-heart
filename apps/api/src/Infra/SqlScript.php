<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

/**
 * Splits a .sql file into single statements the way the mysql client does, so migrations can run
 * through PDO: honours `DELIMITER` lines (needed for triggers), quoted strings and identifiers,
 * and `--`, `#` and block comments. Comments are dropped from the output.
 */
final class SqlScript
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $delimiter = ';';
        $buffer = '';
        $len = strlen($sql);
        $i = 0;
        $atLineStart = true;

        while ($i < $len) {
            if ($atLineStart && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(?:\r?\n|$)/Ai', $sql, $m, 0, $i)) {
                self::flush($buffer, $statements);
                $delimiter = $m[1];
                $i += strlen($m[0]);
                continue;
            }
            $ch = $sql[$i];
            $atLineStart = false;

            if ($ch === "\n") {
                $buffer .= $ch;
                $i++;
                $atLineStart = true;
                continue;
            }
            if ($ch === '#' || ($ch === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $len || ctype_space($sql[$i + 2])))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                continue;
            }
            if ($ch === '/' && substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $buffer .= ' ';
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $start = $i++;
                while ($i < $len) {
                    if ($sql[$i] === '\\' && $ch !== '`') {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === $ch) {
                        if (($sql[$i + 1] ?? '') === $ch) { // doubled quote is an escaped quote
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $buffer .= substr($sql, $start, $i - $start);
                continue;
            }
            if (substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
                self::flush($buffer, $statements);
                $i += strlen($delimiter);
                continue;
            }
            $buffer .= $ch;
            $i++;
        }
        self::flush($buffer, $statements);
        return $statements;
    }

    /** @param list<string> $statements */
    private static function flush(string &$buffer, array &$statements): void
    {
        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }
        $buffer = '';
    }
}
