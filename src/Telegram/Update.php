<?php

declare(strict_types=1);

namespace AiTalents\Telegram;

/**
 * Read-only accessor around a raw Telegram update array.
 *
 * Every getter tolerates a completely empty / unexpected payload: Telegram keeps
 * adding update kinds and the bot must never fatal on one it does not know.
 */
final class Update
{
    /** @param array<string,mixed> $raw */
    public function __construct(private array $raw)
    {
    }

    /** Build an update from a raw webhook body; null when the body is not a JSON object. */
    public static function fromJson(string $json): ?self
    {
        $json = trim($json);

        if ($json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return null;
        }

        /** @var array<string,mixed> $decoded */
        return new self($decoded);
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->raw;
    }

    public function id(): int
    {
        return $this->intOrZero($this->raw['update_id'] ?? null);
    }

    /** message|edited_message|callback_query|my_chat_member|other */
    public function type(): string
    {
        foreach (['message', 'edited_message', 'callback_query', 'my_chat_member'] as $key) {
            if (isset($this->raw[$key]) && is_array($this->raw[$key])) {
                return $key;
            }
        }

        return 'other';
    }

    /**
     * The message this update is about: the message itself, the edited message or
     * the message a callback button is attached to.
     *
     * @return array<string,mixed>|null
     */
    public function message(): ?array
    {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            $value = $this->raw[$key] ?? null;
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        $callback = $this->callbackQuery();
        $attached = $callback['message'] ?? null;

        return is_array($attached) && $attached !== [] ? $attached : null;
    }

    /** @return array<string,mixed>|null */
    public function callbackQuery(): ?array
    {
        $value = $this->raw['callback_query'] ?? null;

        return is_array($value) && $value !== [] ? $value : null;
    }

    /** @return array<string,mixed>|null */
    public function myChatMember(): ?array
    {
        $value = $this->raw['my_chat_member'] ?? null;

        return is_array($value) && $value !== [] ? $value : null;
    }

    /**
     * The Telegram `User` that triggered this update.
     *
     * @return array<string,mixed>|null
     */
    public function from(): ?array
    {
        $candidates = [
            $this->callbackQuery()['from'] ?? null,
            $this->myChatMember()['from'] ?? null,
            $this->raw['message']['from'] ?? null,
            $this->raw['edited_message']['from'] ?? null,
            $this->message()['from'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return null;
    }

    public function userId(): ?int
    {
        $id = $this->from()['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function chatId(): ?int
    {
        $chat = $this->chat();
        $id = $chat['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function chatType(): ?string
    {
        $type = $this->chat()['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    public function isPrivate(): bool
    {
        return $this->chatType() === 'private';
    }

    public function messageId(): ?int
    {
        $id = $this->message()['message_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** Message text, falling back to the caption of a media message. */
    public function text(): ?string
    {
        $message = $this->message();

        if ($message === null) {
            return null;
        }

        foreach (['text', 'caption'] as $key) {
            $value = $message[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** True when the message text starts with a slash command. */
    public function isCommand(): bool
    {
        return $this->command() !== null;
    }

    /** `/start@MyBot ref_123` => `start` */
    public function command(): ?string
    {
        $text = $this->text();

        if ($text === null) {
            return null;
        }

        $text = ltrim($text);

        if ($text === '' || $text[0] !== '/') {
            return null;
        }

        // First whitespace separated token without the leading slash.
        $token = preg_split('/\s+/u', substr($text, 1), 2);
        $command = is_array($token) && isset($token[0]) ? $token[0] : '';

        // Drop the "@botusername" suffix Telegram appends in groups.
        $at = strpos($command, '@');
        if ($at !== false) {
            $command = substr($command, 0, $at);
        }

        $command = mb_strtolower($command, 'UTF-8');

        return preg_match('/^[a-z0-9_]{1,32}$/', $command) === 1 ? $command : null;
    }

    /** Everything after the command token (`/start ref_x` => `ref_x`). */
    public function commandArgs(): string
    {
        $text = $this->text();

        if ($text === null || $this->command() === null) {
            return '';
        }

        $parts = preg_split('/\s+/u', ltrim($text), 2);

        return is_array($parts) && isset($parts[1]) ? trim($parts[1]) : '';
    }

    /** @return array<string,mixed>|null */
    public function contact(): ?array
    {
        $contact = $this->message()['contact'] ?? null;

        return is_array($contact) && $contact !== [] ? $contact : null;
    }

    public function contactPhone(): ?string
    {
        $phone = $this->contact()['phone_number'] ?? null;

        if (is_numeric($phone)) {
            $phone = (string) $phone;
        }

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /** Owner of the shared contact — compare with {@see userId()} to detect foreign numbers. */
    public function contactUserId(): ?int
    {
        $id = $this->contact()['user_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function callbackData(): ?string
    {
        $data = $this->callbackQuery()['data'] ?? null;

        return is_string($data) && $data !== '' ? $data : null;
    }

    public function callbackId(): ?string
    {
        $id = $this->callbackQuery()['id'] ?? null;

        if (is_numeric($id)) {
            $id = (string) $id;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** `new_chat_member.status` of a my_chat_member update (kicked|left|member|administrator|...). */
    public function myChatMemberStatus(): ?string
    {
        $member = $this->myChatMember()['new_chat_member'] ?? null;

        if (!is_array($member)) {
            return null;
        }

        $status = $member['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /** True when the user blocked the bot (private chat + status "kicked"). */
    public function isBotBlocked(): bool
    {
        if ($this->myChatMember() === null) {
            return false;
        }

        $chat = $this->myChatMember()['chat'] ?? null;
        $chatType = is_array($chat) && isset($chat['type']) && is_string($chat['type']) ? $chat['type'] : null;

        return $chatType === 'private' && $this->myChatMemberStatus() === 'kicked';
    }

    /** True when the user unblocked / restarted the bot. */
    public function isBotUnblocked(): bool
    {
        if ($this->myChatMember() === null) {
            return false;
        }

        $chat = $this->myChatMember()['chat'] ?? null;
        $chatType = is_array($chat) && isset($chat['type']) && is_string($chat['type']) ? $chat['type'] : null;

        return $chatType === 'private' && $this->myChatMemberStatus() === 'member';
    }

    /**
     * The chat this update happened in.
     *
     * @return array<string,mixed>|null
     */
    private function chat(): ?array
    {
        $message = $this->message();
        $chat = $message['chat'] ?? null;

        if (is_array($chat) && $chat !== []) {
            return $chat;
        }

        $chat = $this->myChatMember()['chat'] ?? null;

        if (is_array($chat) && $chat !== []) {
            return $chat;
        }

        // Fall back to the sender: in private chats chat.id equals user.id.
        $from = $this->from();

        if (is_array($from) && isset($from['id']) && is_numeric($from['id'])) {
            return ['id' => (int) $from['id'], 'type' => 'private'];
        }

        return null;
    }

    private function intOrZero(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
