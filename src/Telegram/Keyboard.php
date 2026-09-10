<?php

declare(strict_types=1);

namespace AiTalents\Telegram;

/**
 * Builders for Telegram reply markup.
 *
 * Every method returns data ready to be passed to the Bot API: button helpers
 * return arrays, markup helpers return the JSON string expected by `reply_markup`.
 */
final class Keyboard
{
    /** Telegram hard limit for `callback_data` (bytes, not characters). */
    public const CALLBACK_DATA_LIMIT = 64;

    /** JSON flags used for every markup payload. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Build an inline keyboard payload.
     *
     * @param array<int,array<int,array<string,mixed>>> $rows Rows of button arrays.
     */
    public static function inline(array $rows): string
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $buttons = [];

            foreach ($row as $button) {
                if (!is_array($button) || $button === []) {
                    continue;
                }

                if (isset($button['callback_data']) && is_string($button['callback_data'])) {
                    self::guardCallbackData($button['callback_data']);
                }

                $buttons[] = $button;
            }

            if ($buttons !== []) {
                $clean[] = array_values($buttons);
            }
        }

        return self::encode(['inline_keyboard' => array_values($clean)]);
    }

    /**
     * Build a reply (custom) keyboard payload.
     *
     * Buttons may be plain strings or arrays such as {@see self::contact()}.
     *
     * @param array<int,array<int,array<string,mixed>|string>> $rows
     */
    public static function reply(
        array $rows,
        bool $resize = true,
        bool $oneTime = false,
        ?string $placeholder = null
    ): string {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $buttons = [];

            foreach ($row as $button) {
                if (is_string($button)) {
                    if ($button === '') {
                        continue;
                    }
                    $buttons[] = ['text' => $button];
                    continue;
                }

                if (is_array($button) && $button !== []) {
                    $buttons[] = $button;
                }
            }

            if ($buttons !== []) {
                $clean[] = array_values($buttons);
            }
        }

        $markup = [
            'keyboard'          => array_values($clean),
            'resize_keyboard'   => $resize,
            'one_time_keyboard' => $oneTime,
            'is_persistent'     => false,
        ];

        if ($placeholder !== null && $placeholder !== '') {
            // Telegram truncates the placeholder at 64 characters.
            $markup['input_field_placeholder'] = mb_substr($placeholder, 0, 64, 'UTF-8');
        }

        return self::encode($markup);
    }

    /** Remove a previously shown reply keyboard. */
    public static function remove(): string
    {
        return self::encode(['remove_keyboard' => true]);
    }

    /** Force the client to show the reply box (useful after a "type your answer" prompt). */
    public static function forceReply(?string $placeholder = null): string
    {
        $markup = ['force_reply' => true];

        if ($placeholder !== null && $placeholder !== '') {
            $markup['input_field_placeholder'] = mb_substr($placeholder, 0, 64, 'UTF-8');
        }

        return self::encode($markup);
    }

    /**
     * An inline button carrying callback data.
     *
     * @return array{text:string,callback_data:string}
     */
    public static function btn(string $text, string $data): array
    {
        self::guardCallbackData($data);

        return ['text' => $text, 'callback_data' => $data];
    }

    /**
     * An inline button opening a URL.
     *
     * @return array{text:string,url:string}
     */
    public static function url(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }

    /**
     * A reply-keyboard button asking the user to share their phone number.
     *
     * @return array{text:string,request_contact:bool}
     */
    public static function contact(string $text): array
    {
        return ['text' => $text, 'request_contact' => true];
    }

    /**
     * Chunk a flat list of buttons into rows.
     *
     * @param array<int,array<string,mixed>> $buttons
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function rows(array $buttons, int $perRow = 2): array
    {
        $perRow = max(1, $perRow);

        $flat = [];
        foreach ($buttons as $button) {
            if (is_array($button) && $button !== []) {
                $flat[] = $button;
            }
        }

        if ($flat === []) {
            return [];
        }

        return array_chunk($flat, $perRow);
    }

    /**
     * The standard navigation row: "back" and (optionally) "cancel".
     *
     * @return array<int,array<string,mixed>> A single keyboard row.
     */
    public static function backRow(
        string $backText,
        string $backData,
        ?string $cancelText = null,
        ?string $cancelData = null
    ): array {
        $row = [];

        if ($backText !== '' && $backData !== '') {
            $row[] = self::btn($backText, $backData);
        }

        if ($cancelText !== null && $cancelText !== '' && $cancelData !== null && $cancelData !== '') {
            $row[] = self::btn($cancelText, $cancelData);
        }

        return $row;
    }

    /** True when the callback data fits into Telegram's 64 byte budget. */
    public static function callbackDataFits(string $data): bool
    {
        $length = strlen($data);

        return $length > 0 && $length <= self::CALLBACK_DATA_LIMIT;
    }

    /**
     * Assert that callback data is usable, then return it (handy for inline use).
     *
     * @throws \InvalidArgumentException When the payload is empty or longer than 64 bytes.
     */
    public static function guardCallbackData(string $data): string
    {
        if ($data === '') {
            throw new \InvalidArgumentException('callback_data must not be empty.');
        }

        $length = strlen($data);

        if ($length > self::CALLBACK_DATA_LIMIT) {
            throw new \InvalidArgumentException(sprintf(
                'callback_data "%s" is %d bytes, Telegram allows %d.',
                $data,
                $length,
                self::CALLBACK_DATA_LIMIT
            ));
        }

        return $data;
    }

    /**
     * JSON-encode a markup payload.
     *
     * @param array<string,mixed> $markup
     */
    private static function encode(array $markup): string
    {
        $json = json_encode($markup, self::JSON_FLAGS);

        // Only unencodable data (invalid UTF-8) can fail here; an empty markup is safer than a crash.
        return $json === false ? '{}' : $json;
    }
}
