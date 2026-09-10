<?php

declare(strict_types=1);

namespace AiTalents;

/**
 * String helpers shared by the bot and by the admin panel.
 *
 * Everything here is multibyte safe (UTF-8) and never throws: helpers are used
 * while rendering Telegram messages, where a fatal error would silently drop a
 * user's answer.
 */
final class Text
{
    /** Character appended by {@see self::truncate()} when text is cut. */
    public const ELLIPSIS = "\u{2026}";

    /**
     * Characters that must never reach Telegram or a log file: C0/C1 controls
     * (tab and newline excluded), zero width and bidirectional overrides.
     */
    private const CONTROL_PATTERN =
        '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{0080}-\x{009F}'
        . '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

    /** Whitespace-ish code points that PCRE's \s does not cover on its own. */
    private const EXTRA_SPACES = [
        "\u{00A0}", // no-break space
        "\u{1680}",
        "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}", "\u{2005}",
        "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}",
        "\u{202F}", "\u{205F}", "\u{3000}",
    ];

    /** Transliteration table used by {@see self::slug()} (Cyrillic + Uzbek Latin). */
    private const TRANSLITERATION = [
        // Uzbek Latin specials — the apostrophe carriers simply drop the mark.
        "o\u{2018}" => 'o', "O\u{2018}" => 'O', "g\u{2018}" => 'g', "G\u{2018}" => 'G',
        "o\u{02BB}" => 'o', "O\u{02BB}" => 'O', "g\u{02BB}" => 'g', "G\u{02BB}" => 'G',
        "\u{2019}" => '', "\u{02BC}" => '', "\u{2018}" => '', "\u{02BB}" => '',
        // Russian / Uzbek Cyrillic.
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
        'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'x', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '',
        'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h', 'ў' => 'o',
        'А' => 'a', 'Б' => 'b', 'В' => 'v', 'Г' => 'g', 'Д' => 'd', 'Е' => 'e', 'Ё' => 'yo',
        'Ж' => 'j', 'З' => 'z', 'И' => 'i', 'Й' => 'y', 'К' => 'k', 'Л' => 'l', 'М' => 'm',
        'Н' => 'n', 'О' => 'o', 'П' => 'p', 'Р' => 'r', 'С' => 's', 'Т' => 't', 'У' => 'u',
        'Ф' => 'f', 'Х' => 'x', 'Ц' => 'ts', 'Ч' => 'ch', 'Ш' => 'sh', 'Щ' => 'sh',
        'Ы' => 'i', 'Э' => 'e', 'Ю' => 'yu', 'Я' => 'ya',
        'Қ' => 'q', 'Ғ' => 'g', 'Ҳ' => 'h', 'Ў' => 'o',
    ];

    /** Units used by {@see self::bytes()}. */
    private const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * Escape a value for Telegram HTML (and for any other HTML sink).
     *
     * `ENT_SUBSTITUTE` keeps broken UTF-8 from turning the whole message into an
     * empty string, which is exactly what happens with the default flags.
     * The HTML 4.01 doctype is deliberate: it encodes the apostrophe as the
     * numeric `&#039;` instead of `&apos;`, which Telegram does not document.
     */
    public static function esc(?string $s): string
    {
        if ($s === null || $s === '') {
            return '';
        }

        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Cut a string to `$limit` characters, appending `$end` when something was removed.
     *
     * The suffix is included in the budget, so the result is never longer than `$limit`.
     */
    public static function truncate(string $s, int $limit, string $end = self::ELLIPSIS): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (mb_strlen($s, 'UTF-8') <= $limit) {
            return $s;
        }

        $endLength = mb_strlen($end, 'UTF-8');

        // The suffix alone does not fit: fall back to a hard cut.
        if ($endLength >= $limit) {
            return mb_substr($s, 0, $limit, 'UTF-8');
        }

        return rtrim(mb_substr($s, 0, $limit - $endLength, 'UTF-8')) . $end;
    }

    /**
     * Normalise arbitrary user input into something safe to store and to show.
     *
     * Line breaks survive (portfolio texts need them) but runs of horizontal
     * whitespace collapse and more than one blank line is squashed into one.
     */
    public static function clean(string $s, int $max = 4000): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = str_replace(self::EXTRA_SPACES, ' ', $s);
        $s = (string) preg_replace(self::CONTROL_PATTERN, '', $s);
        $s = (string) preg_replace('/[ \t]+/u', ' ', $s);
        $s = (string) preg_replace('/ *\n */u', "\n", $s);
        $s = (string) preg_replace('/\n{3,}/u', "\n\n", $s);
        $s = trim($s);

        if ($max > 0 && mb_strlen($s, 'UTF-8') > $max) {
            $s = mb_substr($s, 0, $max, 'UTF-8');
        }

        return $s;
    }

    /** Collapse every whitespace character (including NBSP) into single spaces. */
    public static function normalizeSpaces(string $s): string
    {
        $s = str_replace(self::EXTRA_SPACES, ' ', $s);
        $s = (string) preg_replace(self::CONTROL_PATTERN, '', $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return trim($s);
    }

    /**
     * URL/file friendly ASCII slug ("Bo‘z tumani" => "boz-tumani").
     *
     * Returns an empty string when nothing transliterable is left.
     */
    public static function slug(string $s): string
    {
        $s = self::normalizeSpaces($s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, self::TRANSLITERATION);
        $s = (string) preg_replace('/[^a-z0-9]+/u', '-', $s);

        return trim($s, '-');
    }

    /**
     * Up to two uppercase initials of a person's name ("Ali Valiyev" => "AV").
     */
    public static function initials(string $fullName): string
    {
        $parts = preg_split('/\s+/u', self::normalizeSpaces($fullName), -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts) || $parts === []) {
            return '';
        }

        $initials = '';

        foreach ($parts as $part) {
            $first = mb_substr($part, 0, 1, 'UTF-8');

            // Skip leading punctuation so "(Ali)" still yields "A".
            if ($first === '' || preg_match('/\p{L}/u', $first) !== 1) {
                continue;
            }

            $initials .= mb_strtoupper($first, 'UTF-8');

            if (mb_strlen($initials, 'UTF-8') >= 2) {
                break;
            }
        }

        return $initials;
    }

    /**
     * Human readable phone number: `+998901234567` => `+998 90 123 45 67`.
     *
     * Anything that is not an Uzbek number is returned trimmed but untouched.
     */
    public static function phoneDisplay(string $e164): string
    {
        $national = self::nationalDigits($e164);

        if ($national === null) {
            return trim($e164);
        }

        return '+998 '
            . substr($national, 0, 2) . ' '
            . substr($national, 2, 3) . ' '
            . substr($national, 5, 2) . ' '
            . substr($national, 7, 2);
    }

    /**
     * Partially hidden phone number for screens that do not need the full value:
     * `+998901234567` => `+998 90 *** ** 67`.
     */
    public static function maskPhone(string $e164): string
    {
        $national = self::nationalDigits($e164);

        if ($national === null) {
            $digits = (string) preg_replace('/\D+/', '', $e164);

            if ($digits === '') {
                return '';
            }

            // Unknown format: keep the last two digits only.
            return str_repeat('*', max(0, strlen($digits) - 2)) . substr($digits, -2);
        }

        return '+998 ' . substr($national, 0, 2) . ' *** ** ' . substr($national, 7, 2);
    }

    /** Format a byte count for the admin panel ("1.4 MB"). */
    public static function bytes(int $n): string
    {
        if ($n < 0) {
            return '0 B';
        }

        $unit  = 0;
        $value = (float) $n;
        $last  = count(self::BYTE_UNITS) - 1;

        while ($value >= 1024.0 && $unit < $last) {
            $value /= 1024.0;
            $unit++;
        }

        $decimals = ($unit === 0 || $value >= 100.0) ? 0 : 1;

        return number_format($value, $decimals, '.', ' ') . ' ' . self::BYTE_UNITS[$unit];
    }

    /**
     * Escape a multi-line value for a Telegram HTML message.
     *
     * Line breaks are preserved (Telegram renders `\n` as a new line) and the
     * text is trimmed to `$max` characters so a single field can never blow the
     * 4096 character message budget.
     */
    public static function multiline(?string $s, int $max = 900): string
    {
        if ($s === null) {
            return '';
        }

        $s = self::clean($s, 0);

        if ($s === '') {
            return '';
        }

        if ($max > 0) {
            $s = self::truncate($s, $max);
        }

        $lines = explode("\n", $s);

        foreach ($lines as $index => $line) {
            $lines[$index] = self::esc($line);
        }

        return implode("\n", $lines);
    }

    /**
     * Extract the 9 national digits of an Uzbek number, or null when the input
     * is not a recognisable Uzbek phone number.
     */
    private static function nationalDigits(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '998')) {
            $digits = substr($digits, 3);
        }

        return strlen($digits) === 9 ? $digits : null;
    }
}
