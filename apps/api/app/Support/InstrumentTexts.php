<?php

namespace App\Support;

use App\Forms\Instruments\Instruments;
use App\Models\InstrumentText;

/**
 * Questionnaire wording lookup: text entered by the study team (instrument_texts)
 * wins; otherwise built-in English for public-domain instruments; otherwise null.
 */
class InstrumentTexts
{
    private static array $cache = [];

    public const LANGS = ['en' => 'English', 'ta' => 'தமிழ் (Tamil)'];

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function stored(string $instrument, string $lang): array
    {
        return self::$cache["{$instrument}.{$lang}"] ??= InstrumentText::where('instrument', $instrument)->where('lang', $lang)->pluck('text', 'key')->all();
    }

    public static function text(string $instrument, string $lang, string $key): ?string
    {
        $v = self::stored($instrument, $lang)[$key] ?? null;
        if ($v !== null && trim($v) !== '') {
            return $v;
        }
        $inst = Instruments::get($instrument);

        return $inst ? Instruments::builtin($inst, $lang, $key) : null;
    }

    /** Keys still missing text in this language. Empty = instrument usable in this language. */
    public static function missing(string $instrument, string $lang): array
    {
        $inst = Instruments::get($instrument);
        $optional = ['instructions'];

        return array_values(array_filter(Instruments::textKeys($inst),
            fn ($k) => ! in_array($k, $optional, true) && self::text($instrument, $lang, $k) === null));
    }

    public static function ready(string $instrument, string $lang): bool
    {
        return ! self::missing($instrument, $lang);
    }
}
