<?php

declare(strict_types=1);

namespace AiTalents\Telegram;

/**
 * Thrown when the Telegram Bot API answers with `ok:false` or when the request
 * could not be delivered at all (network / TLS / empty body).
 *
 * The full decoded response is kept so callers can inspect `parameters`
 * (for example `retry_after` or `migrate_to_chat_id`).
 */
final class ApiException extends \RuntimeException
{
    /**
     * Description fragments that mean "this chat can never receive messages again".
     * Telegram is not consistent about the error code (403 or 400), so we match on text.
     *
     * @var string[]
     */
    private const PERMANENT_DELIVERY_FAILURES = [
        'bot was blocked by the user',
        'user is deactivated',
        'chat not found',
        "bot can't initiate conversation",
        'bot was kicked',
        'user_deactivated',
        'peer_id_invalid',
        'chat_write_forbidden',
    ];

    /**
     * @param array<string,mixed> $response Decoded Telegram response (or transport diagnostics).
     */
    public function __construct(
        string $message,
        private int $errorCode = 0,
        private array $response = []
    ) {
        parent::__construct($message, $errorCode);
    }

    /** Telegram `error_code` (0 for transport level failures). */
    public function errorCode(): int
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> The full decoded response body. */
    public function response(): array
    {
        return $this->response;
    }

    /** Telegram `description`, falling back to the exception message. */
    public function description(): string
    {
        $description = $this->response['description'] ?? null;

        return is_string($description) && $description !== '' ? $description : $this->getMessage();
    }

    /** @return array<string,mixed> The `parameters` object of the response. */
    public function parameters(): array
    {
        $parameters = $this->response['parameters'] ?? null;

        return is_array($parameters) ? $parameters : [];
    }

    /** Seconds to wait before retrying (flood control), when Telegram provided one. */
    public function retryAfter(): ?int
    {
        $parameters = $this->parameters();

        if (isset($parameters['retry_after']) && is_numeric($parameters['retry_after'])) {
            return max(0, (int) $parameters['retry_after']);
        }

        return null;
    }

    /** Chat id to migrate to, when Telegram reports a group -> supergroup upgrade. */
    public function migrateToChatId(): ?int
    {
        $parameters = $this->parameters();

        if (isset($parameters['migrate_to_chat_id']) && is_numeric($parameters['migrate_to_chat_id'])) {
            return (int) $parameters['migrate_to_chat_id'];
        }

        return null;
    }

    /** True when Telegram tells us the user blocked the bot / the chat is gone for good. */
    public function isBlockedByUser(): bool
    {
        $haystack = $this->normalize($this->description());

        if ($haystack === '') {
            return false;
        }

        foreach (self::PERMANENT_DELIVERY_FAILURES as $needle) {
            if (str_contains($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    /** True when the failure was a flood-control answer (HTTP 429). */
    public function isRateLimited(): bool
    {
        return $this->errorCode === 429 || $this->retryAfter() !== null;
    }

    /** Lowercase the text and fold the typographic apostrophes Telegram sometimes uses. */
    private function normalize(string $value): string
    {
        $value = str_replace(["\u{2019}", "\u{02BC}", "\u{2018}", '`'], "'", $value);

        return mb_strtolower(trim($value), 'UTF-8');
    }
}
