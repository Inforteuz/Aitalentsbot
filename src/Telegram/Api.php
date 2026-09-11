<?php

declare(strict_types=1);

namespace AiTalents\Telegram;

use AiTalents\Logger;

/**
 * Thin, dependency-free client for the Telegram Bot API.
 *
 * Responsibilities: build the endpoint URL, normalise parameters, decode the
 * answer, turn `ok:false` into an {@see ApiException} and retry the two failures
 * that are worth retrying (a network hiccup and flood control).
 *
 * All text sent from this project is HTML, so {@see sendMessage()} defaults to
 * `parse_mode=HTML`; callers must escape dynamic values with `Text::esc()`.
 */
final class Api
{
    /** Maximum length of a single Telegram message. */
    public const MESSAGE_LIMIT = 4096;

    /** Maximum length of a media caption. */
    public const CAPTION_LIMIT = 1024;

    /** Maximum length of a callback-query answer. */
    public const CALLBACK_ANSWER_LIMIT = 200;

    /** Pause before the single automatic retry after a network failure (microseconds). */
    private const NETWORK_RETRY_DELAY_US = 400000;

    /** Never sleep longer than this when Telegram asks us to back off (seconds). */
    private const MAX_RETRY_AFTER = 30;

    public function __construct(
        private string $token,
        private ?Logger $logger = null,
        private int $timeout = 20,
        private ?Transport $transport = null,
        private string $apiBase = 'https://api.telegram.org'
    ) {
    }

    /** The transport in use; a {@see CurlTransport} is created on first use. */
    public function transport(): Transport
    {
        if ($this->transport === null) {
            $this->transport = new CurlTransport();
        }

        return $this->transport;
    }

    /** Replace the transport (tests, or a proxy-aware implementation). */
    public function setTransport(Transport $transport): void
    {
        $this->transport = $transport;
    }

    /** True when a bot token was configured at all. */
    public function hasToken(): bool
    {
        return trim($this->token) !== '';
    }

    /** Telegram's per-message character limit. */
    public function messageLimit(): int
    {
        return self::MESSAGE_LIMIT;
    }

    /**
     * Perform an API call and return the `result` field.
     *
     * Retries once on a transport failure and once on error 429 (honouring
     * `retry_after`, capped at 30 seconds).
     *
     * @param array<string,mixed> $params
     *
     * @throws ApiException
     */
    public function call(string $method, array $params = []): mixed
    {
        $method = trim($method);

        if ($method === '') {
            throw new ApiException('A Telegram API method name is required.');
        }

        if (!$this->hasToken()) {
            throw new ApiException('The Telegram bot token is not configured (telegram.token).');
        }

        $payload   = $this->normalizeParams($params);
        $url       = $this->endpoint($method);
        $timeout   = $this->timeoutFor($method, $payload);
        $canRetry  = true;   // one network retry
        $canBackoff = true;  // one flood-control retry

        while (true) {
            try {
                $raw     = $this->transport()->send($url, $payload, $timeout);
                $decoded = $this->decode($raw);
            } catch (ApiException $transportError) {
                if ($canRetry) {
                    $canRetry = false;
                    $this->logger?->warning('Telegram request failed, retrying once', [
                        'method' => $method,
                        'error'  => $transportError->getMessage(),
                    ]);
                    usleep(self::NETWORK_RETRY_DELAY_US);

                    continue;
                }

                $this->logger?->error('Telegram request failed', [
                    'method' => $method,
                    'error'  => $transportError->getMessage(),
                ]);

                throw $transportError;
            }

            if (($decoded['ok'] ?? false) === true) {
                return $decoded['result'] ?? true;
            }

            $error = $this->errorFrom($method, $decoded);

            if ($error->errorCode() === 429 && $canBackoff) {
                $canBackoff = false;
                $wait = min(self::MAX_RETRY_AFTER, max(1, $error->retryAfter() ?? 1));

                $this->logger?->warning('Telegram flood control, backing off', [
                    'method'      => $method,
                    'retry_after' => $wait,
                ]);
                sleep($wait);

                continue;
            }

            $this->logger?->error('Telegram API error', [
                'method'      => $method,
                'error_code'  => $error->errorCode(),
                'description' => $error->description(),
            ]);

            throw $error;
        }
    }

    /**
     * Like {@see call()} but never throws.
     *
     * @param array<string,mixed> $params
     *
     * @return array{ok:bool,result?:mixed,error_code?:int,description?:string,parameters?:array<string,mixed>}
     */
    public function tryCall(string $method, array $params = []): ?array
    {
        try {
            return ['ok' => true, 'result' => $this->call($method, $params)];
        } catch (ApiException $e) {
            return [
                'ok'          => false,
                'error_code'  => $e->errorCode(),
                'description' => $e->description(),
                'parameters'  => $e->parameters(),
            ];
        } catch (\Throwable $e) {
            $this->logger?->error('Unexpected Telegram failure', [
                'method' => $method,
                'error'  => $e->getMessage(),
            ]);

            return ['ok' => false, 'error_code' => 0, 'description' => $e->getMessage(), 'parameters' => []];
        }
    }

    /**
     * Send a message, splitting anything longer than 4096 characters.
     *
     * Parts are sent in order; the returned array is the LAST message object.
     * `reply_markup` travels with the last part, `reply_to_message_id` with the first.
     *
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        $chunks = $this->splitText($text);

        if ($chunks === []) {
            $chunks = [$text];
        }

        $total = count($chunks);
        $last  = [];

        foreach ($chunks as $index => $chunk) {
            $params = $extra;
            $params['chat_id'] = $chatId;
            $params['text']    = $chunk;

            if (!array_key_exists('parse_mode', $extra)) {
                $params['parse_mode'] = 'HTML';
            }

            if (!array_key_exists('disable_web_page_preview', $extra) && !array_key_exists('link_preview_options', $extra)) {
                $params['disable_web_page_preview'] = true;
            }

            if ($total > 1) {
                if ($index !== $total - 1) {
                    unset($params['reply_markup']);
                }

                if ($index !== 0) {
                    unset($params['reply_to_message_id'], $params['reply_parameters']);
                }
            }

            $result = $this->call('sendMessage', $params);
            $last   = is_array($result) ? $result : [];
        }

        return $last;
    }

    /**
     * Edit the text of an existing message.
     *
     * A "message is not modified" answer is treated as success: re-rendering the
     * same screen must never break a conversation flow.
     *
     * @param array<string,mixed> $extra
     *
     * @throws ApiException
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): mixed
    {
        $params = $extra;
        $params['chat_id']    = $chatId;
        $params['message_id'] = $messageId;
        $params['text']       = $this->fit($text, self::MESSAGE_LIMIT);

        if (!array_key_exists('parse_mode', $extra)) {
            $params['parse_mode'] = 'HTML';
        }

        if (!array_key_exists('disable_web_page_preview', $extra) && !array_key_exists('link_preview_options', $extra)) {
            $params['disable_web_page_preview'] = true;
        }

        return $this->callTolerant('editMessageText', $params);
    }

    /**
     * Replace (or drop, when `$markup` is null) the inline keyboard of a message.
     *
     * @throws ApiException
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?string $markup = null): mixed
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId];

        if ($markup !== null && $markup !== '') {
            $params['reply_markup'] = $markup;
        }

        return $this->callTolerant('editMessageReplyMarkup', $params);
    }

    /** Delete a message; false when Telegram refuses (too old, already gone, ...). */
    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        return $this->okOf($this->tryCall('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]));
    }

    /** Acknowledge a callback query (never throws — the button must always stop spinning). */
    public function answerCallbackQuery(string $id, string $text = '', bool $showAlert = false): bool
    {
        $params = ['callback_query_id' => $id];

        if ($text !== '') {
            $params['text'] = $this->fit($text, self::CALLBACK_ANSWER_LIMIT);
        }

        if ($showAlert) {
            $params['show_alert'] = true;
        }

        return $this->okOf($this->tryCall('answerCallbackQuery', $params));
    }

    /** Show "typing..." in the chat. Failures are irrelevant, so they are swallowed. */
    public function sendChatAction(int|string $chatId, string $action = 'typing'): bool
    {
        return $this->okOf($this->tryCall('sendChatAction', [
            'chat_id' => $chatId,
            'action'  => $action !== '' ? $action : 'typing',
        ]));
    }

    /**
     * Upload a local file as a document (used by the XLSX export).
     *
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     *
     * @throws ApiException When the file is unreadable or uploads are impossible without cURL.
     */
    public function sendDocument(int|string $chatId, string $path, array $extra = []): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ApiException(sprintf('Document "%s" does not exist or is not readable.', basename($path)));
        }

        if (!CurlTransport::supportsUploads() && $this->transport() instanceof CurlTransport) {
            throw new ApiException(
                'Sending documents requires the PHP "curl" extension (CURLFile is unavailable).',
                0,
                ['reason' => 'upload_without_curl']
            );
        }

        $params = $extra;
        $params['chat_id'] = $chatId;

        if (class_exists('CURLFile')) {
            $params['document'] = new \CURLFile($path, $this->mimeOf($path), basename($path));
        } else {
            // A transport that does not use cURL (tests) still gets a usable value.
            $params['document'] = $path;
        }

        if (isset($params['caption']) && is_string($params['caption'])) {
            $params['caption'] = $this->fit($params['caption'], self::CAPTION_LIMIT);

            if (!array_key_exists('parse_mode', $extra)) {
                $params['parse_mode'] = 'HTML';
            }
        }

        $result = $this->call('sendDocument', $params);

        return is_array($result) ? $result : [];
    }

    /**
     * Membership of a user in a chat; null when Telegram cannot answer
     * (bot is not in the channel, chat not found, ...).
     *
     * @return array<string,mixed>|null
     */
    public function getChatMember(int|string $chatId, int $userId): ?array
    {
        $response = $this->tryCall('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);

        if (($response['ok'] ?? false) !== true) {
            return null;
        }

        $result = $response['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    public function getMe(): array
    {
        $result = $this->call('getMe');

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string,mixed> $extra `secret_token`, `allowed_updates`, `drop_pending_updates`, ...
     *
     * @throws ApiException
     */
    public function setWebhook(string $url, array $extra = []): mixed
    {
        $params = $extra;
        $params['url'] = $url;

        return $this->call('setWebhook', $params);
    }

    /** @throws ApiException */
    public function deleteWebhook(bool $dropPendingUpdates = false): mixed
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => $dropPendingUpdates]);
    }

    /**
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    public function getWebhookInfo(): array
    {
        $result = $this->call('getWebhookInfo');

        return is_array($result) ? $result : [];
    }

    /**
     * Long-polling fetch (local development through `cli.php poll`).
     *
     * @param array<string,mixed> $params `offset`, `limit`, `timeout`, `allowed_updates`
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws ApiException
     */
    public function getUpdates(array $params = []): array
    {
        $result = $this->call('getUpdates', $params);

        if (!is_array($result)) {
            return [];
        }

        $updates = [];

        foreach ($result as $update) {
            if (is_array($update)) {
                $updates[] = $update;
            }
        }

        return $updates;
    }

    /**
     * Publish the command list shown in the Telegram menu.
     *
     * @param array<int,array{command:string,description:string}> $commands
     * @param array<string,mixed>                                 $extra    `scope`, `language_code`
     */
    public function setMyCommands(array $commands, array $extra = []): bool
    {
        $params = $extra;
        $params['commands'] = array_values($commands);

        return $this->okOf($this->tryCall('setMyCommands', $params));
    }

    /**
     * Split a message into Telegram-sized parts.
     *
     * Splitting happens on newline boundaries; over-long single lines are cut on a
     * space and never inside an HTML tag.
     *
     * @return array<int,string>
     */
    public function splitText(string $text, ?int $limit = null): array
    {
        $limit = $limit !== null && $limit > 0 ? $limit : self::MESSAGE_LIMIT;

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return [$text];
        }

        $parts  = [];
        $buffer = '';

        foreach (explode("\n", $text) as $line) {
            $candidate = $buffer === '' ? $line : $buffer . "\n" . $line;

            if (mb_strlen($candidate, 'UTF-8') <= $limit) {
                $buffer = $candidate;

                continue;
            }

            if ($buffer !== '') {
                $parts[] = $buffer;
                $buffer  = '';
            }

            if (mb_strlen($line, 'UTF-8') <= $limit) {
                $buffer = $line;

                continue;
            }

            // A single line longer than the limit: cut it into safe pieces.
            $pieces = $this->hardSplit($line, $limit);
            $buffer = (string) array_pop($pieces);

            foreach ($pieces as $piece) {
                $parts[] = $piece;
            }
        }

        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /** Full endpoint URL for one API method (contains the bot token — never log it). */
    private function endpoint(string $method): string
    {
        return rtrim($this->apiBase, '/') . '/bot' . $this->token . '/' . $method;
    }

    /**
     * Perform a call that is allowed to fail with "message is not modified".
     *
     * @param array<string,mixed> $params
     *
     * @throws ApiException
     */
    private function callTolerant(string $method, array $params): mixed
    {
        try {
            return $this->call($method, $params);
        } catch (ApiException $e) {
            if (str_contains(mb_strtolower($e->description(), 'UTF-8'), 'message is not modified')) {
                return true;
            }

            throw $e;
        }
    }

    /**
     * Drop nulls and normalise values the transports understand.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function normalizeParams(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_object($value) && !$value instanceof \CURLFile) {
                if ($value instanceof \JsonSerializable || $value instanceof \stdClass) {
                    $out[(string) $key] = $value;

                    continue;
                }

                if ($value instanceof \Stringable) {
                    $out[(string) $key] = (string) $value;

                    continue;
                }

                continue; // unusable value, better dropped than fataling
            }

            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function timeoutFor(string $method, array $params): int
    {
        $timeout = max(1, $this->timeout);

        // Long polling must outlive the poll window itself.
        if (strtolower($method) === 'getupdates' && isset($params['timeout']) && is_numeric($params['timeout'])) {
            return max($timeout, (int) $params['timeout'] + 10);
        }

        return $timeout;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws ApiException
     */
    private function decode(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(
                'Telegram returned a malformed response: ' . $e->getMessage(),
                0,
                ['raw' => mb_substr($raw, 0, 300, 'UTF-8')]
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException(
                'Telegram returned an unexpected response type.',
                0,
                ['raw' => mb_substr($raw, 0, 300, 'UTF-8')]
            );
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /**
     * Build the exception for an `ok:false` answer.
     *
     * @param array<string,mixed> $decoded
     */
    private function errorFrom(string $method, array $decoded): ApiException
    {
        $code = isset($decoded['error_code']) && is_numeric($decoded['error_code'])
            ? (int) $decoded['error_code']
            : 0;

        $description = isset($decoded['description']) && is_string($decoded['description']) && $decoded['description'] !== ''
            ? $decoded['description']
            : 'Unknown Telegram error';

        return new ApiException(
            sprintf('Telegram %s failed: %s (error_code %d)', $method, $description, $code),
            $code,
            $decoded
        );
    }

    /**
     * @param array<string,mixed>|null $response
     */
    private function okOf(?array $response): bool
    {
        return ($response['ok'] ?? false) === true;
    }

    /** Trim a string to a hard Telegram limit without breaking multibyte characters. */
    private function fit(string $text, int $limit): string
    {
        return mb_strlen($text, 'UTF-8') <= $limit ? $text : mb_substr($text, 0, $limit, 'UTF-8');
    }

    /**
     * Cut one over-long line into pieces of at most `$limit` characters.
     *
     * @return array<int,string>
     */
    private function hardSplit(string $line, int $limit): array
    {
        $pieces = [];

        while (mb_strlen($line, 'UTF-8') > $limit) {
            $cut  = $this->safeCut($line, $limit);
            $head = mb_substr($line, 0, $cut, 'UTF-8');

            if ($head === '') {           // paranoia: never loop forever
                $head = mb_substr($line, 0, $limit, 'UTF-8');
                $cut  = $limit;
            }

            $pieces[] = $head;
            $line     = mb_substr($line, $cut, null, 'UTF-8');
        }

        if ($line !== '') {
            $pieces[] = $line;
        }

        return $pieces === [] ? [''] : $pieces;
    }

    /**
     * Where to cut a line: before an unterminated HTML tag, preferably on a space.
     */
    private function safeCut(string $line, int $limit): int
    {
        $head = mb_substr($line, 0, $limit, 'UTF-8');
        $cut  = $limit;

        // Never cut inside "<b ...>" — move the cut before the opening bracket.
        $open  = mb_strrpos($head, '<', 0, 'UTF-8');
        $close = mb_strrpos($head, '>', 0, 'UTF-8');

        if ($open !== false && ($close === false || $close < $open) && $open > 0) {
            $cut = $open;
        }

        // Prefer a word boundary when one is reasonably close to the end.
        $space = mb_strrpos(mb_substr($line, 0, $cut, 'UTF-8'), ' ', 0, 'UTF-8');

        if ($space !== false && $space > (int) ($cut * 0.6)) {
            $cut = $space + 1;
        }

        return max(1, min($cut, $limit));
    }

    /** Best-effort MIME detection for uploads (finfo is not guaranteed on shared hosting). */
    private function mimeOf(string $path): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        $known = [
            'csv'  => 'text/csv',
            'txt'  => 'text/plain',
            'json' => 'application/json',
            'log'  => 'text/plain',
            'pdf'  => 'application/pdf',
            'zip'  => 'application/zip',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ];

        if (isset($known[$extension])) {
            return $known[$extension];
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return 'application/octet-stream';
    }
}
