<?php

declare(strict_types=1);

namespace AiTalents\Enum;

/**
 * The steps of the registration finite state machine.
 *
 * The value of a case is also the suffix of the user state stored in
 * `users.state` ("reg:phone") and the suffix of every language key that belongs
 * to the step ("step.phone", "reg.ask_phone", "reg.hint_phone").
 */
enum Step: string
{
    case Language   = 'language';
    case FullName   = 'full_name';
    case Phone      = 'phone';
    case BirthYear  = 'birth_year';
    case District   = 'district';
    case Direction  = 'direction';
    case Portfolio  = 'portfolio';
    case Confirm    = 'confirm';
    case Done       = 'done';

    /** Prefix of every registration state stored in `users.state`. */
    public const STATE_PREFIX = 'reg:';

    /** The state string written into `users.state` for this step. */
    public function stateKey(): string
    {
        return self::STATE_PREFIX . $this->value;
    }

    /**
     * Resolve a stored state back into a step.
     *
     * Accepts both the full state ("reg:phone") and the bare value ("phone");
     * returns null for anything else (for example "idle" or "admin:broadcast").
     */
    public static function fromState(string $state): ?self
    {
        $state = trim($state);

        if ($state === '') {
            return null;
        }

        if (str_starts_with($state, self::STATE_PREFIX)) {
            $state = substr($state, strlen(self::STATE_PREFIX));
        }

        return self::tryFrom($state);
    }

    /** Language key of the step name, e.g. "step.phone". */
    public function labelKey(): string
    {
        return 'step.' . $this->value;
    }

    /** Language key of the question asked at this step, e.g. "reg.ask_phone". */
    public function promptKey(): string
    {
        return 'reg.ask_' . $this->value;
    }

    /** Language key of the small hint shown under the question. */
    public function hintKey(): string
    {
        return 'reg.hint_' . $this->value;
    }

    /** Language key of the field label used in the summary card, e.g. "reg.field_phone". */
    public function fieldKey(): string
    {
        return 'reg.field_' . $this->value;
    }

    /** Key under which the answer of this step is kept in `users.state_data`. */
    public function dataKey(): string
    {
        return $this->value;
    }

    /** True for the steps that may be turned off in `app.steps` / skipped by the user. */
    public function isOptional(): bool
    {
        return match ($this) {
            self::BirthYear, self::District, self::Portfolio => true,
            default => false,
        };
    }

    /** True for the steps that collect an answer (Language/Confirm/Done do not). */
    public function isQuestion(): bool
    {
        return match ($this) {
            self::FullName, self::Phone, self::BirthYear,
            self::District, self::Direction, self::Portfolio => true,
            default => false,
        };
    }

    /**
     * The steps that collect registration data, in the order they are asked.
     *
     * @return self[]
     */
    public static function questions(): array
    {
        return [
            self::FullName,
            self::Phone,
            self::BirthYear,
            self::District,
            self::Direction,
            self::Portfolio,
        ];
    }

    /** Translated step name. */
    public function label(string $locale = \AiTalents\Lang::FALLBACK): string
    {
        return \AiTalents\Lang::t($this->labelKey(), $locale);
    }
}
