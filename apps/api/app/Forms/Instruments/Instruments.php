<?php

namespace App\Forms\Instruments;

/**
 * Patient-reported instruments. Each defines item codes, response values and which
 * calculator scores it. Built-in English text is included ONLY for public-domain
 * wording (PHQ-9, GAD-7: Pfizer, free to reproduce; DASI: Hlatky 1989 publication).
 * Licensed instruments (EQ-5D-5L, MARS-5) and every Tamil version have no built-in
 * text: the exact licensed / validated wording is entered by the study team in
 * Administration → Questionnaire texts, and the instrument stays unavailable in a
 * language until every text for it has been entered.
 */
class Instruments
{
    public const FREQ = [
        ['value' => 0, 'key' => 'not_at_all', 'en' => 'Not at all'],
        ['value' => 1, 'key' => 'several_days', 'en' => 'Several days'],
        ['value' => 2, 'key' => 'more_than_half', 'en' => 'More than half the days'],
        ['value' => 3, 'key' => 'nearly_every_day', 'en' => 'Nearly every day'],
    ];

    public const DIFFICULTY = [
        ['value' => 0, 'key' => 'not_difficult', 'en' => 'Not difficult at all'],
        ['value' => 1, 'key' => 'somewhat', 'en' => 'Somewhat difficult'],
        ['value' => 2, 'key' => 'very', 'en' => 'Very difficult'],
        ['value' => 3, 'key' => 'extremely', 'en' => 'Extremely difficult'],
    ];

    public static function all(): array
    {
        $diffQ = 'If you checked off any problems, how difficult have these problems made it for you to do your work, take care of things at home, or get along with other people?';

        return [
            'PHQ9' => [
                'form' => 'PRO-PHQ9', 'title' => 'PHQ-9 · Patient Health Questionnaire', 'licensed' => false,
                'source' => 'Kroenke, Spitzer & Williams 2001 · Pfizer: no permission required to reproduce',
                'calculator' => \App\Forms\Calculators\Phq9Calculator::class,
                'instructions' => 'Over the last 2 weeks, how often have you been bothered by any of the following problems?',
                'items' => [
                    ['code' => 'PHQ9_Q1', 'en' => 'Little interest or pleasure in doing things', 'options' => self::FREQ],
                    ['code' => 'PHQ9_Q2', 'en' => 'Feeling down, depressed, or hopeless', 'options' => self::FREQ],
                    ['code' => 'PHQ9_Q3', 'en' => 'Trouble falling or staying asleep, or sleeping too much', 'options' => self::FREQ],
                    ['code' => 'PHQ9_Q4', 'en' => 'Feeling tired or having little energy', 'options' => self::FREQ],
                    ['code' => 'PHQ9_Q5', 'en' => 'Poor appetite or overeating', 'options' => self::FREQ],
                    ['code' => 'PHQ9_Q6', 'en' => 'Feeling bad about yourself — or that you are a failure or have let yourself or your family down', 'options' => self::FREQ],
                    ['code' => 'PHQ9_Q7', 'en' => 'Trouble concentrating on things, such as reading the newspaper or watching television', 'options' => self::FREQ],
                    ['code' => 'PHQ9_Q8', 'en' => 'Moving or speaking so slowly that other people could have noticed? Or the opposite — being so fidgety or restless that you have been moving around a lot more than usual', 'options' => self::FREQ],
                    ['code' => 'PHQ9_Q9', 'en' => 'Thoughts that you would be better off dead or of hurting yourself in some way', 'options' => self::FREQ],
                    ['code' => 'PHQ9_FUNCTION', 'en' => $diffQ, 'options' => self::DIFFICULTY, 'required' => false],
                ],
            ],
            'GAD7' => [
                'form' => 'PRO-GAD7', 'title' => 'GAD-7 · Generalised Anxiety Disorder scale', 'licensed' => false,
                'source' => 'Spitzer et al. 2006 · Pfizer: no permission required to reproduce',
                'calculator' => \App\Forms\Calculators\Gad7Calculator::class,
                'instructions' => 'Over the last 2 weeks, how often have you been bothered by the following problems?',
                'items' => [
                    ['code' => 'GAD7_Q1', 'en' => 'Feeling nervous, anxious or on edge', 'options' => self::FREQ],
                    ['code' => 'GAD7_Q2', 'en' => 'Not being able to stop or control worrying', 'options' => self::FREQ],
                    ['code' => 'GAD7_Q3', 'en' => 'Worrying too much about different things', 'options' => self::FREQ],
                    ['code' => 'GAD7_Q4', 'en' => 'Trouble relaxing', 'options' => self::FREQ],
                    ['code' => 'GAD7_Q5', 'en' => 'Being so restless that it is hard to sit still', 'options' => self::FREQ],
                    ['code' => 'GAD7_Q6', 'en' => 'Becoming easily annoyed or irritable', 'options' => self::FREQ],
                    ['code' => 'GAD7_Q7', 'en' => 'Feeling afraid as if something awful might happen', 'options' => self::FREQ],
                    ['code' => 'GAD7_FUNCTION', 'en' => $diffQ, 'options' => self::DIFFICULTY, 'required' => false],
                ],
            ],
            'DASI' => [
                'form' => 'PRO-DASI', 'title' => 'DASI · Duke Activity Status Index', 'licensed' => false,
                'source' => 'Hlatky et al., Am J Cardiol 1989',
                'calculator' => \App\Forms\Calculators\DasiCalculator::class,
                'instructions' => 'Can you do the following activities?',
                'items' => array_map(fn ($i, $t) => ['code' => 'DASI_Q'.($i + 1), 'en' => $t, 'options' => [
                    ['value' => 1, 'key' => 'yes', 'en' => 'Yes'], ['value' => 0, 'key' => 'no', 'en' => 'No'],
                ]], array_keys(self::DASI_ITEMS), self::DASI_ITEMS),
            ],
            'EQ5D' => [
                'form' => 'PRO-EQ5D', 'title' => 'EQ-5D-5L', 'licensed' => true,
                'source' => 'EuroQol Research Foundation — licensed. Enter the official wording; do not paraphrase.',
                'calculator' => \App\Forms\Calculators\Eq5dCalculator::class,
                'instructions' => null,
                'items' => array_merge(array_map(fn ($d) => ['code' => "EQ5D_{$d}", 'en' => null, 'options' => array_map(
                    fn ($l) => ['value' => $l, 'key' => "l{$l}", 'en' => null], range(1, 5))], ['MO', 'SC', 'UA', 'PD', 'AD']),
                    [['code' => 'EQ5D_VAS', 'en' => null, 'scale' => [0, 100]]]),
            ],
            'MARS5' => [
                'form' => 'PRO-MARS5', 'title' => 'MARS-5 · Medication Adherence Report Scale', 'licensed' => true,
                'source' => 'Horne & Weinman — licensed. Enter the licensed wording.',
                'calculator' => \App\Forms\Calculators\Mars5Calculator::class,
                'instructions' => null,
                'items' => array_map(fn ($i) => ['code' => "MARS5_Q{$i}", 'en' => null, 'options' => array_map(
                    fn ($v) => ['value' => $v, 'key' => "v{$v}", 'en' => null], [1, 2, 3, 4, 5])], range(1, 5)),
            ],
        ];
    }

    public const DASI_ITEMS = [
        'Take care of yourself, that is, eating, dressing, bathing, or using the toilet?',
        'Walk indoors, such as around your house?',
        'Walk a block or two on level ground?',
        'Climb a flight of stairs or walk up a hill?',
        'Run a short distance?',
        'Do light work around the house like dusting or washing dishes?',
        'Do moderate work around the house like vacuuming, sweeping floors, or carrying in groceries?',
        'Do heavy work around the house like scrubbing floors, or lifting or moving heavy furniture?',
        'Do yardwork like raking leaves, weeding, or pushing a power mower?',
        'Have sexual relations?',
        'Participate in moderate recreational activities like golf, bowling, dancing, doubles tennis, or throwing a ball?',
        'Participate in strenuous sports like swimming, singles tennis, football, basketball, or skiing?',
    ];

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function byForm(string $formCode): ?array
    {
        foreach (self::all() as $k => $i) {
            if ($i['form'] === $formCode) {
                return $i + ['key' => $k];
            }
        }

        return null;
    }

    /** Every text key an instrument needs in one language. */
    public static function textKeys(array $inst): array
    {
        $keys = ['instructions'];
        foreach ($inst['items'] as $it) {
            $keys[] = $it['code'];
            foreach ($it['options'] ?? [] as $o) {
                $keys[] = "{$it['code']}.{$o['key']}";
            }
            if (isset($it['scale'])) {
                $keys[] = "{$it['code']}.low";
                $keys[] = "{$it['code']}.high";
            }
        }

        return $keys;
    }

    /** Built-in text (English public-domain only). */
    public static function builtin(array $inst, string $lang, string $key): ?string
    {
        if ($lang !== 'en' || $inst['licensed']) {
            return null;
        }
        if ($key === 'instructions') {
            return $inst['instructions'];
        }
        foreach ($inst['items'] as $it) {
            if ($key === $it['code']) {
                return $it['en'];
            }
            foreach ($it['options'] ?? [] as $o) {
                if ($key === "{$it['code']}.{$o['key']}") {
                    return $o['en'];
                }
            }
        }

        return null;
    }
}
