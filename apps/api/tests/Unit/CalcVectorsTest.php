<?php

namespace Tests\Unit;

use App\Calc\Calc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the 102 calculation-specification vectors against App\Calc\Calc.
 * A failing vector means a formula drifted from the approved specification.
 */
class CalcVectorsTest extends TestCase
{
    public static function cases(): array
    {
        $out = [];
        foreach (require __DIR__.'/../vectors/calc_cases.php' as $c) {
            $out[$c[0]] = $c;
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function test_vector(string $id, string $section, string $what, \Closure $fn, mixed $expected, string $source): void
    {
        $actual = $fn();
        $this->assertSame(
            json_encode($expected, JSON_PRESERVE_ZERO_FRACTION),
            json_encode($actual, JSON_PRESERVE_ZERO_FRACTION),
            "{$id} {$section}: {$what} (source: {$source})"
        );
    }

    /** Regression: the range message used "$lo–$hi", which PHP parsed as a variable named "$lo–" and crashed. */
    public function test_out_of_range_lab_is_rejected_with_a_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HBA1C 25 % outside plausible range 3.5–18');
        Calc::convertLab('HBA1C', 25, '%');
    }

    public function test_all_102_vectors_present(): void
    {
        $this->assertCount(102, self::cases());
        $this->assertSame('1.0.0-draft', Calc::VERSION);
    }
}
