<?php

declare(strict_types=1);

namespace AiTalents;

/**
 * Input validation for the registration flow.
 *
 * Every validator returns the same shape:
 *
 *     ['ok' => bool, 'value' => mixed, 'error' => ?string]
 *
 * `value` holds the normalised value when `ok` is true, and `error` holds a
 * LANGUAGE KEY (never a ready-made sentence) so the caller can render it in the
 * user's own locale via {@see Lang::t()}.
 */
final class Validator
{
    /** Accepted length of a full name, in characters. */
    public const NAME_MIN = 3;
    public const NAME_MAX = 80;

    /** Accepted age span of the programme. */
    public const AGE_MIN = 7;
    public const AGE_MAX = 60;

    /** Maximum length of the free-form portfolio answer. */
    public const PORTFOLIO_MAX = 2000;

    /** International prefix of every stored phone number. */
    public const PHONE_PREFIX = '+998';

    /**
     * Letters, marks and the punctuation that legitimately appears in Uzbek and
     * Russian personal names: hyphen, dot, space and the apostrophe family
     * (ASCII ', typographic ’ ‘, modifier ʻ ʼ and the backtick people type instead).
     */
    private const NAME_PATTERN =
        "/^[\\p{L}\\p{M}\\-. '\x{2018}\x{2019}\x{02BB}\x{02BC}\x{00B4}\x{0060}]+$/u";

    /** URL matcher: explicit scheme, or a bare host with a known-looking TLD. */
    private const URL_PATTERN =
        '~\b(?:(?:https?://|ftp://)[^\s<>"\x{00AB}\x{00BB}]+|(?:www\.|t\.me/|telegram\.me/)[^\s<>"]+'
        . '|[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)+\.[a-z]{2,24}(?:/[^\s<>"]*)?)~iu';

    /** Telegram usernames: 5-32 chars, start with a letter, letters/digits/underscore. */
    private const USERNAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{4,31}$/';

    /**
     * Validate a person's full name.
     *
     * Requires at least two words (ism + familiya) and normalises the casing of
     * fully lowercase words so "ali valiyev" is stored as "Ali Valiyev".
     *
     * @return array{ok:bool,value:string,error:?string}
     */
    public static function fullName(string $input): array
    {
        $value = Text::normalizeSpaces($input);

        if ($value === '') {
            return self::fail('reg.err_full_name_short');
        }

        // Strip a leading/trailing punctuation frame before measuring.
        $value  = trim($value, " \t\n\r\0\x0B.-");
        $length = mb_strlen($value, 'UTF-8');

        if ($length < self::NAME_MIN) {
            return self::fail('reg.err_full_name_short');
        }

        if ($length > self::NAME_MAX) {
            return self::fail('reg.err_full_name_long');
        }

        if (preg_match(self::NAME_PATTERN, $value) !== 1) {
            return self::fail('reg.err_full_name_chars');
        }

        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? $words : [];

        // Count only words that actually carry a letter.
        $meaningful = 0;
        foreach ($words as $word) {
            if (preg_match('/\p{L}/u', $word) === 1) {
                $meaningful++;
            }
        }

        if ($meaningful < 2) {
            return self::fail('reg.err_full_name_words');
        }

        return self::ok(self::normalizeNameCase($words));
    }

    /**
     * Validate and normalise an Uzbek phone number.
     *
     * Accepted: `901234567`, `90 123 45 67`, `(90) 123-45-67`, `0901234567`,
     * `8 90 123 45 67`, `998901234567`, `+998 90 123 45 67`, `00998901234567`.
     * The result is always stored as `+998XXXXXXXXX`.
     *
     * @return array{ok:bool,value:string,error:?string}
     */
    public static function phone(string $input): array
    {
        $digits = (string) preg_replace('/\D+/', '', $input);

        if ($digits === '') {
            return self::fail('reg.err_phone');
        }

        // International dialling prefixes people paste from their contact list.
        if (str_starts_with($digits, '00998')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '810998')) {
            $digits = substr($digits, 3);
        }

        $length = strlen($digits);

        if ($length === 12 && str_starts_with($digits, '998')) {
            $national = substr($digits, 3);
        } elseif ($length === 9) {
            $national = $digits;
        } elseif ($length === 10 && ($digits[0] === '0' || $digits[0] === '8')) {
            // Old trunk prefixes: 8 90 ... / 0 90 ...
            $national = substr($digits, 1);
        } elseif ($length === 13 && str_starts_with($digits, '9998')) {
            // A stray extra 9 from "+9 998 ..." typos.
            $national = substr($digits, 4);
        } else {
            return self::fail('reg.err_phone');
        }

        if (strlen($national) !== 9 || preg_match('/^\d{9}$/', $national) !== 1) {
            return self::fail('reg.err_phone');
        }

        // Operator codes never start with 0 or 1 in Uzbekistan; everything else
        // is accepted so new codes keep working without a code change.
        if ($national[0] === '0' || $national[0] === '1') {
            return self::fail('reg.err_phone');
        }

        return self::ok(self::PHONE_PREFIX . $national);
    }

    /**
     * Validate a 4 digit year of birth and check it against the programme age span.
     *
     * @return array{ok:bool,value:int,error:?string}
     */
    public static function birthYear(string $input): array
    {
        $value = Text::normalizeSpaces($input);

        // Tolerate answers such as "2007 yil" or "2007 y.".
        if (preg_match('/(?<!\d)(\d{4})(?!\d)/u', $value, $matches) !== 1) {
            return self::fail('reg.err_birth_year', 0);
        }

        $year    = (int) $matches[1];
        $current = (int) date('Y');
        $age     = $current - $year;

        if ($year < 1900 || $year > $current) {
            return self::fail('reg.err_birth_year', 0);
        }

        if ($age < self::AGE_MIN || $age > self::AGE_MAX) {
            return self::fail('reg.err_birth_year_range', 0);
        }

        return self::ok($year);
    }

    /**
     * Validate the free-form portfolio answer.
     *
     * @return array{ok:bool,value:string,error:?string}
     */
    public static function portfolio(string $input): array
    {
        $value = Text::clean($input, 0);

        if ($value === '') {
            return self::fail('reg.err_portfolio_empty');
        }

        if (mb_strlen($value, 'UTF-8') > self::PORTFOLIO_MAX) {
            return self::fail('reg.err_portfolio_long');
        }

        return self::ok($value);
    }

    /**
     * True when the string is a usable http(s) link.
     *
     * A missing scheme is tolerated ("github.com/ali") because that is how links
     * are typed in a chat.
     */
    public static function isUrl(string $s): bool
    {
        $s = trim($s);

        if ($s === '' || preg_match('/\s/u', $s) === 1) {
            return false;
        }

        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $s) === 1) {
            // Only web schemes are considered links here.
            if (preg_match('~^https?://~i', $s) !== 1) {
                return false;
            }
        } else {
            $s = 'https://' . $s;
        }

        $url = filter_var($s, FILTER_VALIDATE_URL);

        if (!is_string($url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && str_contains($host, '.') && !str_ends_with($host, '.');
    }

    /**
     * Pull every link out of a free-form text.
     *
     * @return string[] Unique links, in the order they appear.
     */
    public static function extractUrls(string $s): array
    {
        $s = Text::clean($s, 0);

        if ($s === '' || preg_match_all(self::URL_PATTERN, $s, $matches) === false) {
            return [];
        }

        $found = [];

        foreach ($matches[0] as $candidate) {
            // Sentence punctuation glued to the end of a link.
            $candidate = rtrim($candidate, ".,;:!?)»\"'");

            if ($candidate === '' || !self::isUrl($candidate)) {
                continue;
            }

            $normalized = preg_match('~^https?://~i', $candidate) === 1
                ? $candidate
                : 'https://' . $candidate;

            if (!in_array($normalized, $found, true)) {
                $found[] = $normalized;
            }
        }

        return $found;
    }

    /** True when the string is a valid Telegram username (a leading @ is allowed). */
    public static function username(string $s): bool
    {
        $s = ltrim(trim($s), '@');

        if ($s === '' || str_ends_with($s, '_') || str_contains($s, '__')) {
            return false;
        }

        return preg_match(self::USERNAME_PATTERN, $s) === 1;
    }

    /**
     * Uppercase the first letter of every fully lowercase word, leaving names the
     * user deliberately typed otherwise ("O‘ktam", "ALI") untouched.
     *
     * @param string[] $words
     */
    private static function normalizeNameCase(array $words): string
    {
        $result = [];

        foreach ($words as $word) {
            if (mb_strtolower($word, 'UTF-8') === $word) {
                $word = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                    . mb_substr($word, 1, null, 'UTF-8');
            }

            $result[] = $word;
        }

        return implode(' ', $result);
    }

    /**
     * @return array{ok:true,value:mixed,error:null}
     */
    private static function ok(mixed $value): array
    {
        return ['ok' => true, 'value' => $value, 'error' => null];
    }

    /**
     * @return array{ok:false,value:mixed,error:string}
     */
    private static function fail(string $errorKey, mixed $value = ''): array
    {
        return ['ok' => false, 'value' => $value, 'error' => $errorKey];
    }
}
