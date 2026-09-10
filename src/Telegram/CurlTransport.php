<?php

declare(strict_types=1);

namespace AiTalents\Telegram;

/**
 * Default transport.
 *
 * Uses cURL when the extension is available (required for file uploads) and
 * transparently falls back to `file_get_contents()` + a stream context on the
 * shared hosts where cURL is disabled.
 *
 * Encoding rules:
 *  - a request containing a \CURLFile is sent as `multipart/form-data`;
 *  - every other request is sent as a plain `application/json` POST, which the
 *    Bot API accepts and which keeps nested structures intact.
 */
final class CurlTransport implements Transport
{
    /** Sent so Telegram support logs show which client made the call. */
    private string $userAgent;

    /** Only ever disabled from tests against a local mock server. */
    private bool $verifySsl;

    public function __construct(?string $userAgent = null, bool $verifySsl = true)
    {
        $this->userAgent = $userAgent ?? 'AiTalentsBot/1.0 (+PHP ' . PHP_VERSION . ')';
        $this->verifySsl = $verifySsl;
    }

    /** True when this process can perform file uploads. */
    public static function supportsUploads(): bool
    {
        return function_exists('curl_init') && class_exists('CURLFile');
    }

    /**
     * @param array<string,mixed> $params
     *
     * @throws ApiException
     */
    public function send(string $url, array $params, int $timeout): string
    {
        $timeout = max(1, $timeout);
        $hasFile = $this->hasUpload($params);

        if (function_exists('curl_init')) {
            return $this->sendWithCurl($url, $params, $timeout, $hasFile);
        }

        if ($hasFile) {
            throw new ApiException(
                'File uploads to Telegram require the PHP "curl" extension, which is not installed.',
                0,
                ['transport' => 'stream', 'reason' => 'upload_without_curl']
            );
        }

        return $this->sendWithStream($url, $params, $timeout);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @throws ApiException
     */
    private function sendWithCurl(string $url, array $params, int $timeout, bool $hasFile): string
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new ApiException('Could not initialise a cURL handle.', 0, ['transport' => 'curl']);
        }

        $headers = ['Accept: application/json', 'Expect:'];

        if ($hasFile) {
            // curl builds the multipart body itself as soon as a CURLFile is present.
            $body = $this->flattenForMultipart($params);
        } else {
            $body = $this->encodeJson($params);
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            // Telegram never redirects; following one could leak the token to another host.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_FAILONERROR    => false, // 4xx bodies carry the Telegram error description.
            CURLOPT_HEADER         => false,
            CURLOPT_ENCODING       => '',
        ];

        // Restrict the allowed protocols where the constant exists (belt and braces after FOLLOWLOCATION=false).
        if (defined('CURLPROTO_HTTPS') && defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | (defined('CURLPROTO_HTTP') ? CURLPROTO_HTTP : 0);
        }

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $errno    = curl_errno($handle);
        $error    = curl_error($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($response === false || $errno !== 0) {
            throw new ApiException(
                sprintf('Telegram request failed (cURL #%d): %s', $errno, $error !== '' ? $error : 'unknown error'),
                0,
                ['transport' => 'curl', 'curl_errno' => $errno, 'http_code' => $status]
            );
        }

        $body = is_string($response) ? $response : '';

        if (trim($body) === '') {
            throw new ApiException(
                sprintf('Telegram returned an empty body (HTTP %d).', $status),
                0,
                ['transport' => 'curl', 'http_code' => $status]
            );
        }

        return $body;
    }

    /**
     * cURL-less fallback: a JSON POST through the HTTP stream wrapper.
     *
     * @param array<string,mixed> $params
     *
     * @throws ApiException
     */
    private function sendWithStream(string $url, array $params, int $timeout): string
    {
        if (!filter_var((string) ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new ApiException(
                'Neither the "curl" extension nor "allow_url_fopen" is available; the bot cannot reach Telegram.',
                0,
                ['transport' => 'stream', 'reason' => 'allow_url_fopen_off']
            );
        }

        $body = $this->encodeJson($params);

        $context = stream_context_create([
            'http' => [
                'method'           => 'POST',
                'header'           => implode("\r\n", [
                    'Content-Type: application/json; charset=utf-8',
                    'Accept: application/json',
                    'Content-Length: ' . strlen($body),
                    'Connection: close',
                ]),
                'content'          => $body,
                'timeout'          => $timeout,
                'ignore_errors'    => true, // keep 4xx bodies, they contain the description
                'follow_location'  => 0,
                'max_redirects'    => 0,
                'protocol_version' => 1.1,
                'user_agent'       => $this->userAgent,
            ],
            'ssl' => [
                'verify_peer'       => $this->verifySsl,
                'verify_peer_name'  => $this->verifySsl,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
            ],
        ]);

        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $last = error_get_last();
            $reason = is_array($last) && isset($last['message']) ? (string) $last['message'] : 'unknown error';

            throw new ApiException(
                'Telegram request failed (stream): ' . $this->stripToken($reason),
                0,
                ['transport' => 'stream']
            );
        }

        if (trim($response) === '') {
            throw new ApiException(
                'Telegram returned an empty body (stream transport, ' . $this->statusLine($http_response_header) . ').',
                0,
                ['transport' => 'stream']
            );
        }

        return $response;
    }

    /** @param array<string,mixed> $params */
    private function hasUpload(array $params): bool
    {
        foreach ($params as $value) {
            if ($value instanceof \CURLFile) {
                return true;
            }
        }

        return false;
    }

    /**
     * Multipart bodies only accept scalars and CURLFile values.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function flattenForMultipart(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($value instanceof \CURLFile) {
                $out[$key] = $value;
                continue;
            }

            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $out[$key] = $encoded === false ? '' : $encoded;
                continue;
            }

            $out[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $params
     *
     * @throws ApiException When the payload contains data that cannot be encoded (invalid UTF-8).
     */
    private function encodeJson(array $params): string
    {
        try {
            return json_encode(
                $params,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new ApiException(
                'Could not encode the Telegram request payload: ' . $e->getMessage(),
                0,
                ['transport' => 'json']
            );
        }
    }

    /** @param array<int,string> $headers */
    private function statusLine(array $headers): string
    {
        return isset($headers[0]) ? trim($headers[0]) : 'no status line';
    }

    /** Never let the bot token reach a log file or an error message. */
    private function stripToken(string $text): string
    {
        $clean = preg_replace('#/bot\d+:[A-Za-z0-9_\-]+#', '/bot***', $text);

        return is_string($clean) ? $clean : $text;
    }
}
