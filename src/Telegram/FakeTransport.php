<?php

declare(strict_types=1);

namespace AiTalents\Telegram;

/**
 * In-memory transport for the test-suite: nothing ever leaves the machine.
 *
 * Every call is recorded as `['method' => string, 'params' => array, 'url' => string]`
 * and answered with the next queued JSON response, defaulting to
 * `{"ok":true,"result":true}` when the queue has run dry.
 */
final class FakeTransport implements Transport
{
    /** @var array<int,array{method:string,params:array<string,mixed>,url:string}> */
    public array $calls = [];

    /** @var array<int,string> FIFO queue of raw JSON responses. */
    public array $queue = [];

    /**
     * Response returned when the queue is empty.
     */
    public string $defaultResponse = '{"ok":true,"result":true}';

    /**
     * When set, {@see send()} throws this message as a transport failure exactly once.
     * Used to exercise the "retry once on a network error" path.
     */
    public ?string $failNextWith = null;

    /**
     * @param array<int,array<string,mixed>|string> $responses Optional pre-seeded responses.
     */
    public function __construct(array $responses = [])
    {
        foreach ($responses as $response) {
            if (is_string($response)) {
                $this->pushRaw($response);
            } else {
                $this->push($response);
            }
        }
    }

    /**
     * Queue a response; the array is JSON encoded for you.
     *
     * @param array<string,mixed> $response
     */
    public function push(array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->queue[] = $json === false ? '{"ok":false,"description":"unencodable fake response"}' : $json;
    }

    /** Queue an already encoded body (malformed JSON included, to test error paths). */
    public function pushRaw(string $json): void
    {
        $this->queue[] = $json;
    }

    /**
     * Queue a successful response.
     *
     * @param mixed $result
     */
    public function pushOk(mixed $result = true): void
    {
        $this->push(['ok' => true, 'result' => $result]);
    }

    /**
     * Queue a Telegram error response.
     *
     * @param array<string,mixed> $parameters
     */
    public function pushError(int $errorCode, string $description, array $parameters = []): void
    {
        $response = ['ok' => false, 'error_code' => $errorCode, 'description' => $description];

        if ($parameters !== []) {
            $response['parameters'] = $parameters;
        }

        $this->push($response);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @throws ApiException When {@see $failNextWith} was armed.
     */
    public function send(string $url, array $params, int $timeout): string
    {
        $this->calls[] = [
            'method' => $this->methodFromUrl($url),
            'params' => $params,
            'url'    => $url,
        ];

        if ($this->failNextWith !== null) {
            $message = $this->failNextWith;
            $this->failNextWith = null;

            throw new ApiException($message, 0, ['transport' => 'fake']);
        }

        if ($this->queue !== []) {
            return (string) array_shift($this->queue);
        }

        return $this->defaultResponse;
    }

    /** How many calls were recorded (optionally for one method only). */
    public function count(?string $method = null): int
    {
        return $method === null ? count($this->calls) : count($this->callsOf($method));
    }

    /**
     * The most recent call, optionally the most recent call of one API method.
     *
     * @return array{method:string,params:array<string,mixed>,url:string}|null
     */
    public function lastCall(?string $method = null): ?array
    {
        if ($method === null) {
            $last = end($this->calls);
            reset($this->calls);

            return is_array($last) ? $last : null;
        }

        $matching = $this->callsOf($method);
        $last = end($matching);

        return is_array($last) ? $last : null;
    }

    /**
     * Every recorded call to one API method, in order.
     *
     * @return array<int,array{method:string,params:array<string,mixed>,url:string}>
     */
    public function callsOf(string $method): array
    {
        $needle = strtolower($method);
        $out = [];

        foreach ($this->calls as $call) {
            if (strtolower($call['method']) === $needle) {
                $out[] = $call;
            }
        }

        return $out;
    }

    /**
     * The parameters of the first call to a method (handy in assertions).
     *
     * @return array<string,mixed>|null
     */
    public function firstParams(string $method): ?array
    {
        $calls = $this->callsOf($method);

        return $calls === [] ? null : $calls[0]['params'];
    }

    /** Forget every recorded call and every queued response. */
    public function reset(): void
    {
        $this->calls = [];
        $this->queue = [];
        $this->failNextWith = null;
    }

    /** `https://api.telegram.org/bot123:ABC/sendMessage` => `sendMessage` */
    private function methodFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '') {
            $path = $url;
        }

        $slash = strrpos($path, '/');
        $segment = $slash === false ? $path : substr($path, $slash + 1);

        return $segment === '' ? $url : $segment;
    }
}
