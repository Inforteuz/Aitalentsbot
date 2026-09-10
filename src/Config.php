<?php

declare(strict_types=1);

namespace AiTalents;

/**
 * Immutable-ish configuration container with dot notation access.
 *
 *     $config->get('telegram.token');
 *     $config->get('app.steps.district', true);
 *     $config['database.driver'];
 *
 * @implements \ArrayAccess<string,mixed>
 */
final class Config implements \ArrayAccess
{
    /**
     * @param array<string,mixed> $items
     */
    public function __construct(private array $items)
    {
    }

    /**
     * Read a value using dot notation. Returns $default when the key is absent.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        // Fast path: a literal top level key (also allows keys containing dots).
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Write a value using dot notation, creating intermediate arrays as needed.
     */
    public function set(string $key, mixed $value): void
    {
        if ($key === '') {
            return;
        }

        $segments = explode('.', $key);
        $cursor = &$this->items;

        while (count($segments) > 1) {
            $segment = array_shift($segments);

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[array_shift($segments)] = $value;
        unset($cursor);
    }

    /**
     * True when the key exists (even when its value is null).
     */
    public function has(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        if (array_key_exists($key, $this->items)) {
            return true;
        }

        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Remove a key using dot notation.
     */
    public function forget(string $key): void
    {
        if ($key === '') {
            return;
        }

        if (array_key_exists($key, $this->items)) {
            unset($this->items[$key]);

            return;
        }

        $segments = explode('.', $key);
        $cursor = &$this->items;

        while (count($segments) > 1) {
            $segment = array_shift($segments);

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                unset($cursor);

                return;
            }

            $cursor = &$cursor[$segment];
        }

        unset($cursor[array_shift($segments)]);
        unset($cursor);
    }

    /**
     * The whole configuration tree.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /* --------------------------------------------------------------------
     | ArrayAccess — proxies to has()/get()/set()/forget()
     */

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            return; // $config[] = x makes no sense for a keyed tree
        }

        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->forget((string) $offset);
    }
}
