<?php

declare(strict_types=1);

namespace AiTalents;

use AiTalents\Repository\AuditRepository;
use AiTalents\Repository\BroadcastRepository;
use AiTalents\Repository\RegistrationRepository;
use AiTalents\Repository\SettingRepository;
use AiTalents\Repository\UserRepository;
use AiTalents\Service\StatsService;
use AiTalents\Telegram\Api;

/**
 * Service container / composition root.
 *
 * Everything the bot and the admin panel need hangs off this object. Services
 * are created lazily so that a request which only needs the database never
 * builds a Telegram client, and vice versa.
 */
final class App
{
    /** The booted instance (see boot()/instance()). */
    private static ?self $instance = null;

    private Config $config;
    private ?Database $db = null;
    private ?Logger $logger = null;
    private ?Api $api = null;
    private ?UserRepository $users = null;
    private ?RegistrationRepository $registrations = null;
    private ?SettingRepository $settings = null;
    private ?BroadcastRepository $broadcasts = null;
    private ?AuditRepository $audit = null;
    private ?RateLimiter $rateLimiter = null;
    private ?StatsService $stats = null;

    /**
     * @param array<string,mixed> $config the whole config.php array
     */
    public function __construct(array $config)
    {
        $this->config = new Config($config);
    }

    /**
     * Build (or return) the shared instance.
     *
     * Passing a configuration array always creates a fresh instance and makes
     * it the shared one — that is what bootstrap.php and the test harness do.
     * Calling boot() without arguments returns the already booted app, or
     * loads config.php when nothing was booted yet.
     *
     * @param ?array<string,mixed> $config
     */
    public static function boot(?array $config = null): self
    {
        if ($config === null) {
            if (self::$instance instanceof self) {
                return self::$instance;
            }

            $config = self::loadConfigFile();
        }

        return self::$instance = new self($config);
    }

    /**
     * The booted application (boots from config.php when needed).
     */
    public static function instance(): self
    {
        return self::$instance instanceof self ? self::$instance : self::boot();
    }

    /**
     * Read configuration with dot notation; a null key returns the Config object.
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config->get($key, $default);
    }

    public function db(): Database
    {
        if ($this->db === null) {
            /** @var array<string,mixed> $dbConfig */
            $dbConfig = (array) $this->config->get('database', []);
            $this->db = new Database($dbConfig);
        }

        return $this->db;
    }

    public function logger(): Logger
    {
        if ($this->logger === null) {
            $root = defined('AITALENTS_ROOT') ? (string) AITALENTS_ROOT : dirname(__DIR__);

            $this->logger = new Logger(
                (string) $this->config->get('log.dir', $root . '/data/logs'),
                (string) $this->config->get('log.level', 'info'),
                (bool) $this->config->get('log.enabled', true),
                (int) $this->config->get('log.max_files', 14)
            );
        }

        return $this->logger;
    }

    public function api(): Api
    {
        if ($this->api === null) {
            $this->api = new Api(
                token: (string) $this->config->get('telegram.token', ''),
                logger: $this->logger(),
                timeout: (int) $this->config->get('telegram.timeout', 20),
                transport: null,
                apiBase: (string) $this->config->get('telegram.api_base', 'https://api.telegram.org')
            );
        }

        return $this->api;
    }

    /**
     * Replace the Telegram client (tests inject an Api built on FakeTransport).
     */
    public function setApi(Api $api): void
    {
        $this->api = $api;
    }

    public function users(): UserRepository
    {
        return $this->users ??= new UserRepository($this->db());
    }

    public function registrations(): RegistrationRepository
    {
        return $this->registrations ??= new RegistrationRepository($this->db());
    }

    public function settings(): SettingRepository
    {
        return $this->settings ??= new SettingRepository($this->db());
    }

    public function broadcasts(): BroadcastRepository
    {
        return $this->broadcasts ??= new BroadcastRepository($this->db());
    }

    public function audit(): AuditRepository
    {
        return $this->audit ??= new AuditRepository($this->db());
    }

    public function rateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            $this->rateLimiter = new RateLimiter(
                $this->db(),
                (bool) $this->config->get('security.rate_limit.enabled', true),
                (int) $this->config->get('security.rate_limit.max', 20),
                (int) $this->config->get('security.rate_limit.per_seconds', 60)
            );
        }

        return $this->rateLimiter;
    }

    public function stats(): StatsService
    {
        return $this->stats ??= new StatsService($this->db());
    }

    /**
     * Admin when listed in config telegram.admin_ids or flagged in the users table.
     */
    public function isAdmin(int $telegramId): bool
    {
        if ($telegramId <= 0) {
            return false;
        }

        if (in_array($telegramId, $this->configAdminIds(), true)) {
            return true;
        }

        try {
            $user = $this->users()->findByTelegramId($telegramId);
        } catch (\Throwable $e) {
            return false; // table missing / DB down — config admins still work
        }

        return $user !== null && (int) ($user['is_admin'] ?? 0) === 1;
    }

    /**
     * Every admin telegram id: config list plus users.is_admin = 1.
     *
     * @return int[]
     */
    public function adminIds(): array
    {
        $ids = $this->configAdminIds();

        try {
            foreach ($this->users()->adminTelegramIds() as $id) {
                $ids[] = (int) $id;
            }
        } catch (\Throwable $e) {
            // Ignore: fall back to the ids from config.php.
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Enabled interface locales.
     *
     * @return string[]
     */
    public function locales(): array
    {
        $locales = $this->config->get('app.locales', ['uz', 'ru']);
        $clean = [];

        if (is_array($locales)) {
            foreach ($locales as $locale) {
                $locale = strtolower(trim((string) $locale));

                if ($locale !== '') {
                    $clean[] = $locale;
                }
            }
        }

        return $clean === [] ? ['uz'] : array_values(array_unique($clean));
    }

    public function defaultLocale(): string
    {
        $locale = strtolower(trim((string) $this->config->get('app.default_locale', 'uz')));
        $locales = $this->locales();

        return in_array($locale, $locales, true) ? $locale : $locales[0];
    }

    /**
     * A runtime setting: the settings table wins, config.php is the fallback.
     *
     * Known names: registration_open, required_channel, ask_language, welcome_extra.
     * Tolerates a database without the settings table (fresh install).
     */
    public function setting(string $name, mixed $default = null): mixed
    {
        $fallback = $this->config->get('app.' . $name, $default);

        try {
            if (!$this->db()->tableExists('settings')) {
                return $fallback;
            }

            $value = $this->settings()->get($name, null);

            return $value === null ? $fallback : $value;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Current local timestamp in the storage format used everywhere.
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function version(): string
    {
        return defined('AITALENTS_VERSION') ? (string) AITALENTS_VERSION : '1.0.0';
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * Admin ids straight from config.php.
     *
     * @return int[]
     */
    private function configAdminIds(): array
    {
        $raw = $this->config->get('telegram.admin_ids', []);
        $ids = [];

        if (is_array($raw)) {
            foreach ($raw as $id) {
                if (is_int($id) || (is_string($id) && preg_match('/^-?\d+$/', trim($id)) === 1)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Load config.php for a boot() call without an explicit array.
     *
     * @return array<string,mixed>
     */
    private static function loadConfigFile(): array
    {
        if (defined('AITALENTS_CONFIG_FILE')) {
            $file = (string) AITALENTS_CONFIG_FILE;
        } else {
            $root = defined('AITALENTS_ROOT') ? (string) AITALENTS_ROOT : dirname(__DIR__);
            $file = $root . '/config.php';
        }

        if (!is_file($file)) {
            throw new \RuntimeException(
                'config.php was not found at ' . $file
                . ' — copy config.example.php to config.php and fill it in.'
            );
        }

        /** @var mixed $config */
        $config = require $file;

        if (!is_array($config)) {
            throw new \RuntimeException('config.php must return an array.');
        }

        return $config;
    }
}
