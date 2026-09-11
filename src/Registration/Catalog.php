<?php

declare(strict_types=1);

namespace AiTalents\Registration;

/**
 * The fixed reference data of the programme: the talent directions a candidate
 * can pick and the cities/districts of Andijon region.
 *
 * Keys are stable identifiers stored in the database (`registrations.directions`
 * as a JSON array and `registrations.district` as a single value); the labels
 * live here rather than in the language files because they are data, not UI
 * copy, and both locales must always describe the very same list.
 */
final class Catalog
{
    /** Key of the 'anything else' option, present in both lists. */
    public const OTHER = 'other';

    /**
     * Talent directions.
     *
     * @var array<string,array{uz:string,ru:string,emoji:string}>
     */
    private const DIRECTIONS = [
        'programming' => [
            'uz'    => 'Dasturlash',
            'ru'    => 'Программирование',
            'emoji' => '💻',
        ],
        'ai_ml' => [
            'uz'    => 'Sun’iy intellekt va ML',
            'ru'    => 'Искусственный интеллект и ML',
            'emoji' => '🤖',
        ],
        'data_science' => [
            'uz'    => 'Ma’lumotlar tahlili (Data Science)',
            'ru'    => 'Анализ данных',
            'emoji' => '📊',
        ],
        'design' => [
            'uz'    => 'Dizayn (UI/UX, grafika)',
            'ru'    => 'Дизайн (UI/UX, графика)',
            'emoji' => '🎨',
        ],
        'web' => [
            'uz'    => 'Web texnologiyalar',
            'ru'    => 'Веб-технологии',
            'emoji' => '🌐',
        ],
        'mobile' => [
            'uz'    => 'Mobil ilovalar',
            'ru'    => 'Мобильные приложения',
            'emoji' => '📱',
        ],
        'robotics' => [
            'uz'    => 'Robototexnika va IoT',
            'ru'    => 'Робототехника и IoT',
            'emoji' => '⚙️',
        ],
        'cybersecurity' => [
            'uz'    => 'Kiberxavfsizlik',
            'ru'    => 'Кибербезопасность',
            'emoji' => '🔐',
        ],
        'game_3d' => [
            'uz'    => '3D, animatsiya va o‘yinlar',
            'ru'    => '3D, анимация и игры',
            'emoji' => '🎮',
        ],
        'content' => [
            'uz'    => 'Kontent, marketing va SMM',
            'ru'    => 'Контент, маркетинг и SMM',
            'emoji' => '✍️',
        ],
        self::OTHER => [
            'uz'    => 'Boshqa yo‘nalish',
            'ru'    => 'Другое направление',
            'emoji' => '➕',
        ],
    ];

    /**
     * Cities and districts of Andijon region — cities first, then districts in
     * alphabetical order, with "other" closing the list.
     *
     * @var array<string,array{uz:string,ru:string,type:string}>
     */
    private const DISTRICTS = [
        'andijon_city' => [
            'uz'   => 'Andijon shahri',
            'ru'   => 'город Андижан',
            'type' => 'city',
        ],
        'xonobod_city' => [
            'uz'   => 'Xonobod shahri',
            'ru'   => 'город Ханабад',
            'type' => 'city',
        ],
        'qorasuv_city' => [
            'uz'   => 'Qorasuv shahri',
            'ru'   => 'город Карасу',
            'type' => 'city',
        ],
        'andijon' => [
            'uz'   => 'Andijon tumani',
            'ru'   => 'Андижанский район',
            'type' => 'district',
        ],
        'asaka' => [
            'uz'   => 'Asaka tumani',
            'ru'   => 'Асакинский район',
            'type' => 'district',
        ],
        'baliqchi' => [
            'uz'   => 'Baliqchi tumani',
            'ru'   => 'Балыкчинский район',
            'type' => 'district',
        ],
        'boz' => [
            'uz'   => 'Bo‘z tumani',
            'ru'   => 'Бозский район',
            'type' => 'district',
        ],
        'buloqboshi' => [
            'uz'   => 'Buloqboshi tumani',
            'ru'   => 'Булакбашинский район',
            'type' => 'district',
        ],
        'izboskan' => [
            'uz'   => 'Izboskan tumani',
            'ru'   => 'Избасканский район',
            'type' => 'district',
        ],
        'jalaquduq' => [
            'uz'   => 'Jalaquduq tumani',
            'ru'   => 'Джалакудукский район',
            'type' => 'district',
        ],
        'xojaobod' => [
            'uz'   => 'Xo‘jaobod tumani',
            'ru'   => 'Ходжаабадский район',
            'type' => 'district',
        ],
        'qorgontepa' => [
            'uz'   => 'Qo‘rg‘ontepa tumani',
            'ru'   => 'Кургантепинский район',
            'type' => 'district',
        ],
        'marhamat' => [
            'uz'   => 'Marhamat tumani',
            'ru'   => 'Мархаматский район',
            'type' => 'district',
        ],
        'oltinkol' => [
            'uz'   => 'Oltinko‘l tumani',
            'ru'   => 'Алтынкульский район',
            'type' => 'district',
        ],
        'paxtaobod' => [
            'uz'   => 'Paxtaobod tumani',
            'ru'   => 'Пахтаабадский район',
            'type' => 'district',
        ],
        'shahrixon' => [
            'uz'   => 'Shahrixon tumani',
            'ru'   => 'Шахриханский район',
            'type' => 'district',
        ],
        'ulugnor' => [
            'uz'   => 'Ulug‘nor tumani',
            'ru'   => 'Улугнорский район',
            'type' => 'district',
        ],
        self::OTHER => [
            'uz'   => 'Boshqa hudud',
            'ru'   => 'Другой регион',
            'type' => 'district',
        ],
    ];

    /**
     * @return array<string,array{uz:string,ru:string,emoji:string}>
     */
    public static function directions(): array
    {
        return self::DIRECTIONS;
    }

    /**
     * @return array<string,array{uz:string,ru:string,type:string}>
     */
    public static function districts(): array
    {
        return self::DISTRICTS;
    }

    /**
     * Label of a direction, optionally prefixed with its emoji.
     *
     * Unknown keys are returned untouched so a value that was renamed after a
     * registration was stored still renders something meaningful.
     */
    public static function directionLabel(string $key, string $locale = 'uz', bool $withEmoji = true): string
    {
        $item = self::DIRECTIONS[$key] ?? null;

        if ($item === null) {
            return $key;
        }

        $label = $locale === 'ru' ? $item['ru'] : $item['uz'];

        return $withEmoji ? $item['emoji'] . ' ' . $label : $label;
    }

    /** Label of a city/district. Unknown keys are returned untouched. */
    public static function districtLabel(string $key, string $locale = 'uz'): string
    {
        $item = self::DISTRICTS[$key] ?? null;

        if ($item === null) {
            return $key;
        }

        return $locale === 'ru' ? $item['ru'] : $item['uz'];
    }

    /**
     * Labels for a list of direction keys (used by the summary card and the XLSX export).
     *
     * @param string[] $keys
     *
     * @return string[]
     */
    public static function directionLabels(array $keys, string $locale = 'uz', bool $withEmoji = true): array
    {
        $labels = [];

        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $labels[] = self::directionLabel($key, $locale, $withEmoji);
            }
        }

        return $labels;
    }

    public static function hasDirection(string $key): bool
    {
        return isset(self::DIRECTIONS[$key]);
    }

    public static function hasDistrict(string $key): bool
    {
        return isset(self::DISTRICTS[$key]);
    }

    /** @return string[] */
    public static function directionKeys(): array
    {
        return array_keys(self::DIRECTIONS);
    }

    /** @return string[] */
    public static function districtKeys(): array
    {
        return array_keys(self::DISTRICTS);
    }

    /**
     * Only the district keys of a given type ('city' or 'district').
     *
     * @return string[]
     */
    public static function districtKeysOfType(string $type): array
    {
        $keys = [];

        foreach (self::DISTRICTS as $key => $item) {
            if ($item['type'] === $type) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Emoji of a direction ('' when the key is unknown). */
    public static function directionEmoji(string $key): string
    {
        return self::DIRECTIONS[$key]['emoji'] ?? '';
    }

    /**
     * Keep only the direction keys that really exist, without duplicates and in
     * catalogue order — the safety net between user input and the database.
     *
     * @param mixed $keys Usually the decoded `directions` JSON column.
     *
     * @return string[]
     */
    public static function filterDirections(mixed $keys): array
    {
        if (!is_array($keys)) {
            return [];
        }

        $valid = [];

        foreach (self::directionKeys() as $key) {
            if (in_array($key, $keys, true)) {
                $valid[] = $key;
            }
        }

        return $valid;
    }
}
