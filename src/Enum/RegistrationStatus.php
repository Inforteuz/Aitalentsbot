<?php

declare(strict_types=1);

namespace AiTalents\Enum;

/**
 * Moderation state of a registration.
 *
 * The value is stored verbatim in `registrations.status`.
 */
enum RegistrationStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Language key of the human readable name, e.g. 'status.pending'. */
    public function labelKey(): string
    {
        return 'status.' . $this->value;
    }

    /** Language key of the one line explanation shown to the applicant. */
    public function hintKey(): string
    {
        return 'status.' . $this->value . '_hint';
    }

    /** CSS modifier used by the admin panel badges: `badge--warn|ok|danger`. */
    public function badge(): string
    {
        return match ($this) {
            self::Pending  => 'warn',
            self::Approved => 'ok',
            self::Rejected => 'danger',
        };
    }

    /** Emoji used in Telegram cards and lists. */
    public function emoji(): string
    {
        return match ($this) {
            self::Pending  => '⏳',   // hourglass
            self::Approved => '✅',   // check mark button
            self::Rejected => '❌',   // cross mark
        };
    }

    /** Safe factory: unknown or null values become null instead of throwing. */
    public static function tryOrNull(?string $v): ?self
    {
        if ($v === null) {
            return null;
        }

        $v = strtolower(trim($v));

        return $v === '' ? null : self::tryFrom($v);
    }

    /** The status a fresh registration starts with. */
    public static function default(): self
    {
        return self::Pending;
    }

    /**
     * Every status value, handy for `<select>` options and filter whitelists.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return [self::Pending->value, self::Approved->value, self::Rejected->value];
    }

    /** Translated status name. */
    public function label(string $locale = \AiTalents\Lang::FALLBACK): string
    {
        return \AiTalents\Lang::t($this->labelKey(), $locale);
    }

    /** Emoji + translated status name, as used in Telegram messages. */
    public function display(string $locale = \AiTalents\Lang::FALLBACK): string
    {
        return $this->emoji() . ' ' . $this->label($locale);
    }
}
