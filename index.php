<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  Andijon AI Talents — Telegram webhook endpoint
 * =============================================================================
 *
 *  This is the only file Telegram ever talks to:
 *
 *      https://example.uz/bot/index.php
 *
 *  Request handling, in order:
 *
 *   1. GET / HEAD  -> a one line plain-text status page (no secrets in it), so a
 *      human can check "is the bot deployed?" from a browser;
 *   2. POST        -> the webhook. The `X-Telegram-Bot-Api-Secret-Token` header
 *      is compared with `telegram.webhook_secret` in constant time; a mismatch
 *      is answered with 401 and nothing else happens;
 *   3. the raw body is read, the response `200 OK` is sent and the connection is
 *      handed back to the web server (`fastcgi_finish_request()` when the SAPI
 *      offers it, an explicit Content-Length + flush otherwise);
 *   4. only THEN is the update decoded and dispatched through {@see Router}.
 *
 *  Step 3 is what keeps Telegram happy: the Bot API waits for the HTTP response
 *  and retries the update when it does not arrive fast enough, so the answer
 *  must never wait for the database, the file system or an outgoing API call.
 *
 *  The body of a webhook response is always the two bytes "OK". Exceptions are
 *  written to data/logs/bot-Y-m-d.log and never reach the wire — a stack trace
 *  in a webhook response would leak the installation path and, worse, end up in
 *  `getWebhookInfo().last_error_message` where anyone with the token can read it.
 *
 *  PHP 8.1 compatible. No Composer, no external libraries.
 * =============================================================================
 */

use AiTalents\App;
use AiTalents\Router;
use AiTalents\Telegram\Update;

/* -------------------------------------------------------------------------
 | Bootstrap
 |--------------------------------------------------------------------------
 | bootstrap.php loads config.php, registers the autoloader and returns the
 | application. When config.php is missing it prints an installation hint and
 | stops — that is the only case in which this file answers with anything other
 | than "OK", and it can only happen before the bot is installed.
 */

/** @var App $app */
$app = require __DIR__ . '/bootstrap.php';

/* -------------------------------------------------------------------------
 | Small helpers (guarded so a double include can never redeclare them)
 */

if (!function_exists('aitalents_webhook_respond')) {
    /**
     * Send a short plain-text response.
     *
     * Nothing here depends on the configuration, so it also works while the
     * application is half broken.
     */
    function aitalents_webhook_respond(int $status, string $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        echo $body;
    }
}

if (!function_exists('aitalents_webhook_finish')) {
    /**
     * Flush the response and give the connection back to the web server.
     *
     * php-fpm and LiteSpeed can do this properly; on mod_php / CGI the best we
     * can do is announce the body length, ask for the connection to be closed
     * and flush every output buffer by hand.
     */
    function aitalents_webhook_finish(): void
    {
        // php-fpm and LiteSpeed can end the request properly; everything else
        // has to talk the browser into closing the connection by itself.
        $canFinish = function_exists('fastcgi_finish_request')
            || function_exists('litespeed_finish_request');

        if (!$canFinish && !headers_sent() && ob_get_level() === 1) {
            // A Content-Length would be a lie while zlib re-compresses the body.
            $compression = strtolower(trim((string) ini_get('zlib.output_compression')));
            $compressing = $compression !== '' && $compression !== '0' && $compression !== 'off';

            $length = ob_get_length();

            if (!$compressing && $length !== false) {
                header('Content-Length: ' . (string) $length);
                header('Connection: close');
            }
        }

        // Push our own buffers out before handing the request over.
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) {
                break; // a buffer that refuses to flush must not spin forever
            }
        }

        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }
}

if (!function_exists('aitalents_webhook_header')) {
    /**
     * Read a request header case-insensitively.
     *
     * Most SAPIs expose headers through $_SERVER; a few (CGI setups that strip
     * unknown headers) only fill getallheaders().
     */
    function aitalents_webhook_header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                foreach ($headers as $header => $value) {
                    if (is_string($value) && strcasecmp((string) $header, $name) === 0) {
                        return $value;
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('aitalents_webhook_log')) {
    /**
     * Log without ever bubbling an exception back into the request.
     *
     * @param array<string,mixed> $context
     */
    function aitalents_webhook_log(App $app, string $level, string $message, array $context = []): void
    {
        try {
            $app->logger()->log($level, $message, $context);
        } catch (\Throwable $e) {
            // A broken log directory must not break the bot.
        }
    }
}

/* -------------------------------------------------------------------------
 | 1. Status page — GET / HEAD
 |--------------------------------------------------------------------------
 | Deliberately boring: the project name, the bot username and a fixed sentence.
 | No token, no webhook secret, no configuration state, no version banner.
 */

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'GET' || $requestMethod === 'HEAD') {
    $botName = trim((string) $app->config('app.name', 'Andijon AI Talents'));

    if ($botName === '') {
        $botName = 'Andijon AI Talents';
    }

    $botUsername = ltrim(trim((string) $app->config('telegram.bot_username', '')), '@');

    $statusLine = $botName
        . ($botUsername !== '' ? ' (@' . $botUsername . ')' : '')
        . ' — webhook is active';

    aitalents_webhook_respond(200, $statusLine . "\n");
    exit;
}

/* -------------------------------------------------------------------------
 | 2. Only POST carries updates
 */

if ($requestMethod !== 'POST') {
    if (!headers_sent()) {
        header('Allow: GET, HEAD, POST');
    }

    aitalents_webhook_respond(405, "Method Not Allowed\n");
    exit;
}

/* -------------------------------------------------------------------------
 | 3. Secret token check
 |--------------------------------------------------------------------------
 | Telegram echoes `secret_token` (given to setWebhook) in every request. An
 | update without the correct header is not from Telegram and is refused before
 | a single byte of the body is read.
 |
 | This check fails CLOSED: with no secret configured, anyone who guesses the
 | webhook URL could forge updates and impersonate an administrator, so an
 | unconfigured bot refuses every update rather than trusting the internet.
 | Set telegram.webhook_secret (setup.php generates one) before going live.
 | The escape hatch exists only for local development against a tunnel.
 */

$webhookSecret = trim((string) $app->config('telegram.webhook_secret', ''));

if ($webhookSecret === '' && $app->config('telegram.allow_insecure_webhook', false) !== true) {
    aitalents_webhook_log($app, 'error', 'Webhook rejected: telegram.webhook_secret is not configured', [
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);

    aitalents_webhook_respond(401, "Unauthorized\n");
    exit;
}

if ($webhookSecret !== '') {
    $providedSecret = aitalents_webhook_header('X-Telegram-Bot-Api-Secret-Token');

    if ($providedSecret === '' || !hash_equals($webhookSecret, $providedSecret)) {
        aitalents_webhook_log($app, 'warning', 'Webhook rejected: bad secret token', [
            'ip'      => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'present' => $providedSecret !== '',
        ]);

        aitalents_webhook_respond(401, "Unauthorized\n");
        exit;
    }
}

/* -------------------------------------------------------------------------
 | 4. Read the update, answer immediately, work afterwards
 */

$rawUpdate = (string) file_get_contents('php://input');

// Keep processing even if the client (Telegram) hangs up after the response.
ignore_user_abort(true);

if (ob_get_level() === 0) {
    ob_start();
}

aitalents_webhook_respond(200, 'OK');
aitalents_webhook_finish();

// The response is out; a slow database or a chatty API call can no longer make
// Telegram time out and re-deliver this update.
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

/* -------------------------------------------------------------------------
 | 5. Dispatch
 |--------------------------------------------------------------------------
 | Router::dispatch() already swallows its own errors and answers the user with
 | error.generic; the try/catch here is the last line of defence for everything
 | that can go wrong before or around it (malformed JSON, a dead database, an
 | out-of-memory condition while building the router).
 */

try {
    if (trim($rawUpdate) === '') {
        aitalents_webhook_log($app, 'debug', 'Webhook called with an empty body');
    } else {
        $update = Update::fromJson($rawUpdate);

        if ($update === null) {
            aitalents_webhook_log($app, 'warning', 'Webhook body is not a JSON object', [
                'bytes' => strlen($rawUpdate),
            ]);
        } else {
            $router = new Router($app);
            $router->dispatch($update);
        }
    }
} catch (\Throwable $e) {
    aitalents_webhook_log($app, 'error', 'Webhook dispatch failed', [
        'exception' => get_class($e),
        'message'   => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
    ]);
}
