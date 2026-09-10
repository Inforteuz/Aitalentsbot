<?php

declare(strict_types=1);

namespace AiTalents\Telegram;

/**
 * Low level HTTP transport used by {@see Api} to talk to the Telegram Bot API.
 *
 * Implementations are intentionally dumb: they receive a fully built endpoint URL
 * and a flat parameter list and give back the RAW response body. Decoding,
 * error handling and retrying is the job of {@see Api}.
 */
interface Transport
{
    /**
     * Perform a POST request and return the raw response body.
     *
     * @param string               $url     Full endpoint URL (already contains the bot token).
     * @param array<string,mixed>  $params  Request parameters. A \CURLFile value means "upload".
     * @param int                  $timeout Total timeout in seconds.
     *
     * @return string Raw response body (expected to be JSON).
     *
     * @throws ApiException When the request could not be performed at all
     *                      (DNS failure, timeout, TLS problem, empty body, ...).
     */
    public function send(string $url, array $params, int $timeout): string;
}
