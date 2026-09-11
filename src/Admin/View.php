<?php

declare(strict_types=1);

namespace AiTalents\Admin;

use AiTalents\App;
use AiTalents\Lang;

/**
 * The admin panel's template engine: plain PHP files, one layout, no compilation.
 *
 * A page is rendered in two steps: the view template is captured into a string,
 * then that string is handed to the layout as `$content`. Nothing is escaped
 * here on purpose — templates print through the global `e()` helper, which keeps
 * the escaping decision visible at the place where the value is written.
 *
 * Inside every template:
 *  - `$this` is this View instance, so `$this->partial('flash')`,
 *    `$this->url(['p' => 'users'])` and `$this->active('users')` all work;
 *  - `$view` is the same object (handy inside closures and nested includes);
 *  - `$app` is the application container;
 *  - every key of the data array — and of the shared bag — is its own variable.
 *
 * Template names are relative to the view directory and may not contain a dot,
 * so a crafted `?p=` value can never escape into another directory.
 */
final class View
{
    /** Front controller every panel URL points at. */
    public const ENTRY = 'index.php';

    /** Page used when `?p=` is missing or unusable. */
    public const DEFAULT_PAGE = 'dashboard';

    /** CSS class {@see self::active()} returns for the current page. */
    public const ACTIVE_CLASS = 'is-active';

    /** Directory holding the shared snippets used by {@see self::partial()}. */
    public const PARTIAL_DIR = 'partials';

    /** Guard against a template that includes itself forever. */
    private const MAX_DEPTH = 12;

    /**
     * Pages that belong to another page's navigation entry: the registration
     * detail view keeps "Arizalar" highlighted in the sidebar.
     */
    private const NAV_ALIASES = ['registration' => 'registrations'];

    /**
     * Variables handed to every template rendered by this instance.
     *
     * @var array<string,mixed>
     */
    private array $shared = [];

    /** Page key of the current request; drives {@see self::active()}. */
    private ?string $page = null;

    /** Current include depth (see MAX_DEPTH). */
    private int $depth = 0;

    /**
     * @param string $viewDir absolute path of the directory holding the templates
     */
    public function __construct(private App $app, private string $viewDir)
    {
        $this->viewDir = rtrim(str_replace('\\', '/', $viewDir), '/');
    }

    /* --------------------------------------------------------------------
     | Rendering
     */

    /**
     * Render a view inside the layout and write it to the output buffer.
     *
     * Passing an empty layout name — or a layout file that does not exist —
     * prints the bare view, which is what the panel's fragment responses use.
     *
     * @param array<string,mixed> $data
     *
     * @throws \RuntimeException when the view template cannot be found
     */
    public function render(string $view, array $data = [], string $layout = 'layout'): void
    {
        $content = $this->fetch($view, $data);

        if ($layout === '' || $this->resolve($layout) === null) {
            echo $content;

            return;
        }

        echo $this->fetch($layout, $this->layoutData($view, $data, $content));
    }

    /**
     * Render a template and return it as a string instead of printing it.
     *
     * @param array<string,mixed> $data
     *
     * @throws \RuntimeException when the template cannot be found
     */
    public function fetch(string $view, array $data = []): string
    {
        $file = $this->resolve($view);

        if ($file === null) {
            throw new \RuntimeException('Admin view "' . $view . '" was not found in ' . $this->viewDir . '.');
        }

        return $this->renderFile($file, $data);
    }

    /**
     * Print a shared snippet.
     *
     * Names are looked up in `views/partials/` first and in the view directory
     * itself afterwards, so both `partial('flash')` and `partial('partials/flash')`
     * resolve to the same file. A missing partial is silently skipped: a cosmetic
     * snippet must never take a working page down.
     *
     * @param array<string,mixed> $data
     */
    public function partial(string $name, array $data = []): void
    {
        $file = $this->resolve(self::PARTIAL_DIR . '/' . $name) ?? $this->resolve($name);

        if ($file === null) {
            return;
        }

        echo $this->renderFile($file, $data);
    }

    /** True when a template exists (used before rendering an optional page). */
    public function exists(string $view): bool
    {
        return $this->resolve($view) !== null;
    }

    /* --------------------------------------------------------------------
     | URLs and navigation
     */

    /**
     * Build a panel URL: `index.php?p=users&status=pending`.
     *
     * Nothing is preserved from the current request — every parameter that must
     * survive a link has to be passed in explicitly, which keeps filter links
     * predictable. Values are URL encoded, never HTML encoded: templates run the
     * result through `e()` when they print it.
     *
     * @param array<string,mixed> $params
     */
    public function url(array $params = []): string
    {
        return self::link($params);
    }

    /**
     * The static twin of {@see self::url()}, for code that has no View at hand
     * (Auth redirects to the login page with it).
     *
     * @param array<string,mixed> $params
     */
    public static function link(array $params = []): string
    {
        $query = [];

        foreach ($params as $key => $value) {
            if (!is_string($key) || $key === '' || $value === null) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $query[] = rawurlencode($key) . '[]=' . rawurlencode(self::stringify($item));
                    }
                }

                continue;
            }

            if (!is_scalar($value) && !($value instanceof \Stringable)) {
                continue;
            }

            $query[] = rawurlencode($key) . '=' . rawurlencode(self::stringify($value));
        }

        return $query === [] ? self::ENTRY : self::ENTRY . '?' . implode('&', $query);
    }

    /**
     * The CSS class marking the sidebar entry of the page being displayed.
     */
    public function active(string $page, string $class = self::ACTIVE_CLASS): string
    {
        return $this->is($page) ? $class : '';
    }

    /** True when the given page key is the one currently rendered. */
    public function is(string $page): bool
    {
        $page = self::normalizePage($page);
        $current = $this->currentPage();

        if ($page !== '' && $page === $current) {
            return true;
        }

        // A detail page keeps its list entry highlighted.
        return isset(self::NAV_ALIASES[$current]) && self::NAV_ALIASES[$current] === $page;
    }

    /** The page key of the request being rendered. */
    public function currentPage(): string
    {
        if ($this->page !== null) {
            return $this->page;
        }

        $pageRaw = $_GET['p'] ?? '';
        $page    = self::normalizePage(is_string($pageRaw) ? $pageRaw : '');

        return $page === '' ? self::DEFAULT_PAGE : $page;
    }

    /** Tell the view which page the front controller resolved. */
    public function setPage(string $page): void
    {
        $page = self::normalizePage($page);
        $this->page = $page === '' ? self::DEFAULT_PAGE : $page;
    }

    /* --------------------------------------------------------------------
     | Shared data
     */

    /**
     * Add a variable available to every template rendered from now on.
     *
     * @param string|array<string,mixed> $key a name, or a whole name => value map
     */
    public function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            foreach ($key as $name => $item) {
                if (is_string($name) && $name !== '') {
                    $this->shared[$name] = $item;
                }
            }

            return;
        }

        if ($key !== '') {
            $this->shared[$key] = $value;
        }
    }

    /** @return array<string,mixed> */
    public function shared(): array
    {
        return $this->shared;
    }

    public function app(): App
    {
        return $this->app;
    }

    /** Absolute path of the template directory. */
    public function viewDir(): string
    {
        return $this->viewDir;
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * Turn a template name into an absolute, verified file path.
     *
     * Only letters, digits, `_`, `-` and `/` are accepted, so `..` and absolute
     * paths cannot appear; the resolved file is additionally checked to sit
     * inside the view directory.
     */
    private function resolve(string $view): ?string
    {
        $name = trim(str_replace('\\', '/', $view), " \t\n\r\0\x0B/");

        if ($name === '' || preg_match('#^[A-Za-z0-9_\-]+(?:/[A-Za-z0-9_\-]+)*$#', $name) !== 1) {
            return null;
        }

        $file = $this->viewDir . '/' . $name . '.php';

        if (!is_file($file)) {
            return null;
        }

        $real = realpath($file);
        $root = realpath($this->viewDir);

        if ($real === false || $root === false) {
            return null;
        }

        $real = str_replace('\\', '/', $real);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return str_starts_with($real, $root . '/') ? $real : null;
    }

    /**
     * Execute a template with its variables in scope and capture the output.
     *
     * The template runs inside a closure bound to this instance: it keeps `$this`
     * usable in templates while making sure a stray `extract()` key cannot
     * overwrite the engine's own state.
     *
     * @param array<string,mixed> $data
     */
    private function renderFile(string $file, array $data): string
    {
        if ($this->depth >= self::MAX_DEPTH) {
            throw new \RuntimeException('Admin views are nested too deeply (' . basename($file) . ').');
        }

        $variables = $this->shared;

        foreach ($data as $key => $value) {
            if (is_string($key) && $key !== '') {
                $variables[$key] = $value;
            }
        }

        // The engine always wins over caller supplied data for these two names.
        $variables['view'] = $this;
        $variables['app'] = $this->app;

        $render = function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            unset($__data);

            require $__file;
        };

        ++$this->depth;
        ob_start();

        try {
            $render($file, $variables);
        } catch (\Throwable $e) {
            ob_end_clean();
            --$this->depth;

            throw $e;
        }

        --$this->depth;

        return (string) ob_get_clean();
    }

    /**
     * The variables the layout receives on top of the page's own data.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function layoutData(string $view, array $data, string $content): array
    {
        $data['content'] = $content;
        $data['slot'] = $content;
        $data['view_name'] = $view;
        $data['page'] = $data['page'] ?? $this->currentPage();

        if (!isset($data['title']) || !is_string($data['title']) || $data['title'] === '') {
            $data['title'] = Lang::t('panel.title', $this->locale());
        }

        return $data;
    }

    /** Interface language of the panel (falls back to the app default). */
    private function locale(): string
    {
        if (function_exists('panel_locale')) {
            return panel_locale();
        }

        return $this->app->defaultLocale();
    }

    /** Reduce a page key to the safe `[a-z0-9_]` alphabet used by the router. */
    private static function normalizePage(string $page): string
    {
        $page = strtolower(trim($page));
        $page = (string) preg_replace('/[^a-z0-9_]/', '', $page);

        return substr($page, 0, 32);
    }

    /** Scalars (and Stringables) as query-string ready strings. */
    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
