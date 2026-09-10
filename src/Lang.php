<?php

declare(strict_types=1);

namespace AiTalents;

/**
 * Translation registry.
 *
 * Language files live in `lang/{locale}.php` and return a FLAT array whose keys
 * use dot notation ("reg.ask_phone"). Placeholders are written `:name` and are
 * replaced by {@see self::t()}.
 *
 * The class never throws: a missing file, a broken file or an unknown key all
 * degrade gracefully, because a translation problem must never take the bot down
 * in the middle of a user's registration.
 */
final class Lang
{
    /** Locale used when the requested one is unknown or a key is missing. */
    public const FALLBACK = 'uz';

    /** Locale code => the language's own name (as shown in the language picker). */
    private const AVAILABLE = [
        'uz' => 'O‘zbekcha 🇺🇿',
        'ru' => 'Русский 🇷🇺',
    ];

    /**
     * Loaded language files, keyed by locale.
     *
     * @var array<string,array<string,string>>
     */
    private static array $cache = [];

    /** Directory holding the language files; resolved lazily. */
    private static ?string $directory = null;

    /**
     * The locales the product ships with.
     *
     * @return array<string,string> ['uz' => 'O‘zbekcha 🇺🇿', 'ru' => 'Русский 🇷🇺']
     */
    public static function available(): array
    {
        return self::AVAILABLE;
    }

    /**
     * Reduce any locale-ish string to a supported locale code.
     *
     * "ru", "ru-RU", "RU_ru" => "ru"; anything unsupported => "uz".
     */
    public static function normalize(?string $code): string
    {
        if ($code === null || $code === '') {
            return self::FALLBACK;
        }

        $code = strtolower(trim($code));
        $code = (string) preg_replace('/[^a-z]/', '', substr($code, 0, 5));

        if ($code === '') {
            return self::FALLBACK;
        }

        if (isset(self::AVAILABLE[$code])) {
            return $code;
        }

        $short = substr($code, 0, 2);

        return isset(self::AVAILABLE[$short]) ? $short : self::FALLBACK;
    }

    /**
     * Translate a key.
     *
     * Resolution order: requested locale -> fallback locale -> the key itself.
     * The key is returned verbatim when nothing matches so a missing translation
     * is visible but harmless.
     *
     * @param array<string,mixed> $params Values for the `:name` placeholders.
     */
    public static function t(string $key, string $locale = self::FALLBACK, array $params = []): string
    {
        $locale  = self::normalize($locale);
        $strings = self::load($locale);

        $value = $strings[$key] ?? null;

        if ($value === null && $locale !== self::FALLBACK) {
            $fallback = self::load(self::FALLBACK);
            $value    = $fallback[$key] ?? null;
        }

        if ($value === null) {
            $value = $key;
        }

        return $params === [] ? $value : self::interpolate($value, $params);
    }

    /** True when the key exists in the given locale (no fallback lookup). */
    public static function has(string $key, string $locale): bool
    {
        $strings = self::load(self::normalize($locale));

        return array_key_exists($key, $strings);
    }

    /**
     * Every string of a locale, with the fallback locale filling any gap.
     *
     * @return array<string,string>
     */
    public static function all(string $locale): array
    {
        $locale  = self::normalize($locale);
        $strings = self::load($locale);

        if ($locale === self::FALLBACK) {
            return $strings;
        }

        return $strings + self::load(self::FALLBACK);
    }

    /**
     * Load (and cache) a language file.
     *
     * @return array<string,string>
     */
    public static function load(string $locale): array
    {
        $locale = self::normalize($locale);

        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $file    = self::directory() . '/' . $locale . '.php';
        $strings = [];

        if (is_file($file) && is_readable($file)) {
            /** @var mixed $loaded */
            $loaded = require $file;

            if (is_array($loaded)) {
                foreach ($loaded as $key => $value) {
                    // Only flat string entries are usable as translations.
                    if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                        $strings[$key] = (string) $value;
                    }
                }
            }
        }

        self::$cache[$locale] = $strings;

        return $strings;
    }

    /** Directory that holds the language files. */
    public static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        self::$directory = defined('AITALENTS_ROOT')
            ? AITALENTS_ROOT . '/lang'
            : dirname(__DIR__) . '/lang';

        return self::$directory;
    }

    /**
     * Point the loader at another directory (used by the test harness).
     * Passing null restores the default location.
     */
    public static function setDirectory(?string $directory): void
    {
        self::$directory = $directory === null ? null : rtrim($directory, "/\\");
        self::$cache     = [];
    }

    /** Drop the in-memory cache (after editing a language file at runtime). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Replace `:name` placeholders.
     *
     * `strtr()` with an array always prefers the longest match, so `:id` inside
     * a string that also uses `:id_card` cannot corrupt it.
     *
     * @param array<string,mixed> $params
     */
    private static function interpolate(string $value, array $params): string
    {
        $replacements = [];

        foreach ($params as $name => $replacement) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            if ($replacement === null) {
                $replacements[':' . $name] = '';
                continue;
            }

            if (is_bool($replacement)) {
                $replacements[':' . $name] = $replacement ? '1' : '0';
                continue;
            }

            if (is_scalar($replacement)) {
                $replacements[':' . $name] = (string) $replacement;
                continue;
            }

            if ($replacement instanceof \Stringable) {
                $replacements[':' . $name] = (string) $replacement;
            }
        }

        return $replacements === [] ? $value : strtr($value, $replacements);
    }
}
