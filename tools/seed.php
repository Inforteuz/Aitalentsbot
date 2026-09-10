<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  Andijon AI Talents — demo ma'lumot generatori / demo data seeder
 * ============================================================================
 *
 *  !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 *  !!  FOR DEVELOPMENT / DEMO USE ONLY — NEVER RUN THIS ON A PRODUCTION   !!
 *  !!  DATABASE. It writes hundreds of fake users, registrations, audit   !!
 *  !!  entries and a fake broadcast into the configured database.         !!
 *  !!                                                                     !!
 *  !!  FAQAT DEVELOPMENT / DEMO UCHUN — HECH QACHON ISHLAYOTGAN (REAL)    !!
 *  !!  BAZADA ISHGA TUSHIRMANG! Skript bazaga soxta foydalanuvchilar va   !!
 *  !!  arizalar yozadi.                                                   !!
 *  !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 *
 *  Usage:
 *      php tools/seed.php [--count=120] [--days=45] [--fresh] [--seed=20240501]
 *      php tools/seed.php --help
 *
 *  Safety design:
 *   - CLI only (refuses to run over HTTP);
 *   - every generated row is marked: users/registrations get a telegram_id in
 *     the reserved 900000000..900999999 range, registrations.source = 'seed',
 *     audit_log.meta and broadcasts.filters carry a {"seed":true} marker;
 *   - --fresh deletes ONLY those marked rows. It never drops a table and never
 *     touches a row it cannot positively identify as seeded;
 *   - when real (non seeded) rows are found the script refuses to run unless
 *     --fresh was passed explicitly, and even then it leaves them alone.
 *
 *  PHP 8.1 compatible. No Composer, no external libraries.
 */

/* -------------------------------------------------------------------------
 | 1. CLI guard — this file must never be reachable over the web.
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo "tools/seed.php is a command line tool and cannot be run over HTTP.\n"
        . "Run it from a shell instead:  php tools/seed.php --help\n\n"
        . "tools/seed.php faqat buyruqlar qatoridan ishlaydi.\n";

    exit(1);
}

/* -------------------------------------------------------------------------
 | 2. Tiny output helpers (declared before anything can fail).
 */

/**
 * Write one line to STDOUT.
 */
function seed_out(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

/**
 * Write one line to STDERR.
 */
function seed_err(string $line = ''): void
{
    fwrite(STDERR, $line . PHP_EOL);
}

/**
 * Usage / help text.
 */
function seed_usage(): string
{
    return <<<TXT
    Andijon AI Talents — demo data seeder (DEVELOPMENT / DEMO ONLY)

    Usage:
      php tools/seed.php [options]

    Options:
      --count=N     How many demo applicants to generate. Default 120 (1..5000).
      --days=N      Spread created_at over the last N days. Default 45 (1..365).
      --fresh       Delete previously seeded rows first (seeded rows ONLY —
                    real data is never touched, tables are never dropped).
      --seed=N      Random seed for reproducible output. Default 20240501.
      --help, -h    Show this text.

    Examples:
      php tools/seed.php
      php tools/seed.php --count=400 --days=90 --fresh
      php tools/seed.php --count=50 --seed=7 --fresh

    WARNING: never run this against a live/production database.
    OGOHLANTIRISH: bu skriptni real ishlayotgan bazada ishlatmang!
    TXT;
}

/**
 * Parse the command line.
 *
 * @param array<int,string> $argv
 * @return array<string,mixed>
 * @throws InvalidArgumentException on an unknown or malformed option
 */
function seed_parse_args(array $argv): array
{
    $options = [
        'count' => 120,
        'days'  => 45,
        'fresh' => false,
        'seed'  => 20240501,
        'help'  => false,
    ];

    $count = count($argv);

    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--fresh') {
            $options['fresh'] = true;
            continue;
        }

        if (preg_match('/^--(count|days|seed)(?:=(.*))?$/', $arg, $m) === 1) {
            $name  = $m[1];
            $value = $m[2] ?? null;

            // Allow "--count 120" as well as "--count=120".
            if ($value === null || $value === '') {
                $value = $argv[$i + 1] ?? '';
                $i++;
            }

            if (preg_match('/^-?\d+$/', (string) $value) !== 1) {
                throw new InvalidArgumentException('Option --' . $name . ' expects an integer, got "' . $value . '".');
            }

            $options[$name] = (int) $value;
            continue;
        }

        throw new InvalidArgumentException('Unknown option "' . $arg . '". Try --help.');
    }

    if ($options['count'] < 1 || $options['count'] > 5000) {
        throw new InvalidArgumentException('--count must be between 1 and 5000.');
    }

    if ($options['days'] < 1 || $options['days'] > 365) {
        throw new InvalidArgumentException('--days must be between 1 and 365.');
    }

    return $options;
}

/* -------------------------------------------------------------------------
 | 3. The seeder itself.
 */

/**
 * A controlled stop: the seeder refuses to touch this database.
 *
 * Thrown for expected situations (existing data, missing --fresh) so that the
 * error handler can print a short message instead of a stack-trace style dump.
 */
final class SeedAbort extends RuntimeException
{
}

/**
 * Generates a believable demo dataset for the admin panel and the bot.
 *
 * Everything it writes is identifiable so that --fresh can remove exactly the
 * rows it created and nothing else.
 */
final class AiTalentsSeeder
{
    /** Reserved fake telegram_id range (inclusive). */
    public const TG_MIN = 900000000;
    public const TG_MAX = 900999999;

    /** Applicants start here; 900000000..900000100 stay free for fake staff. */
    public const TG_FIRST = 900000101;

    /** Fake reviewer used when config.telegram.admin_ids is empty. */
    public const TG_REVIEWER = 900000001;

    /** registrations.source value used for every generated row. */
    public const SOURCE = 'seed';

    /** Substring that marks JSON payloads written by this tool. */
    public const MARKER = '"seed":true';

    private \AiTalents\App $app;

    private \AiTalents\Database $db;

    /** @var array<string,mixed> */
    private array $options;

    private bool $useUserRepo = false;

    private bool $useRegRepo = false;

    private bool $useBroadcastRepo = false;

    /** @var array<string,bool> usernames already handed out */
    private array $usedUsernames = [];

    /** @var array<string,bool> phone numbers already handed out */
    private array $usedPhones = [];

    /** @var string[] */
    private array $districtKeys = [];

    /** @var string[] */
    private array $directionKeys = [];

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(\AiTalents\App $app, array $options)
    {
        $this->app     = $app;
        $this->db      = $app->db();
        $this->options = $options;
    }

    /* ====================================================================
     | Entry point
     */

    public function run(): int
    {
        $started = microtime(true);

        $this->banner();
        $this->migrate();

        $counts = $this->inspect();
        $this->guard($counts);

        if ($this->options['fresh'] === true) {
            $this->purge();
        }

        mt_srand((int) $this->options['seed'], MT_RAND_MT19937);

        $this->districtKeys  = $this->districtKeys();
        $this->directionKeys = $this->directionKeys();

        seed_out('');
        seed_out('-- Generating -------------------------------------------------');
        seed_out(sprintf(
            '   applicants: %d   window: last %d day(s)   random seed: %d',
            (int) $this->options['count'],
            (int) $this->options['days'],
            (int) $this->options['seed']
        ));

        $applicants = $this->buildApplicants((int) $this->options['count'], (int) $this->options['days']);

        seed_out('');
        seed_out('-- Writing ----------------------------------------------------');

        $written = $this->write($applicants);
        $audit   = $this->seedAudit($applicants);
        $cast    = $this->seedBroadcast($applicants);

        $this->report($applicants, $written, $audit, $cast, microtime(true) - $started);

        return 0;
    }

    /* ====================================================================
     | Phases
     */

    private function banner(): void
    {
        seed_out('');
        seed_out('===============================================================');
        seed_out('  Andijon AI Talents — DEMO DATA SEEDER');
        seed_out('  !! FOR DEVELOPMENT / DEMO ONLY — NEVER RUN ON PRODUCTION !!');
        seed_out('  !! FAQAT DEMO UCHUN — REAL BAZADA ISHGA TUSHIRMANG !!');
        seed_out('===============================================================');
        seed_out(sprintf(
            '  driver: %s   database: %s',
            $this->db->driver(),
            $this->databaseLabel()
        ));
    }

    private function migrate(): void
    {
        seed_out('');
        seed_out('-- Migrations -------------------------------------------------');

        $migrator = new \AiTalents\Migrator($this->db);
        $log      = $migrator->run();

        $applied = 0;

        foreach ($log as $line) {
            if (strncmp($line, 'skipped', 7) !== 0) {
                seed_out('   ' . $line);
                $applied++;
            }
        }

        if ($applied === 0) {
            seed_out('   schema already up to date');
        }
    }

    /**
     * Count what is already in the database, split into "real" and "seeded".
     *
     * @return array<string,int>
     */
    private function inspect(): array
    {
        $users = $this->db->quoteIdent($this->db->table('users'));
        $regs  = $this->db->quoteIdent($this->db->table('registrations'));

        $seedUsers = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM ' . $users . ' WHERE telegram_id BETWEEN ? AND ?',
            [self::TG_MIN, self::TG_MAX]
        );

        $realUsers = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM ' . $users . ' WHERE telegram_id < ? OR telegram_id > ?',
            [self::TG_MIN, self::TG_MAX]
        );

        $seedRegs = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM ' . $regs . ' WHERE source = ? OR (telegram_id BETWEEN ? AND ?)',
            [self::SOURCE, self::TG_MIN, self::TG_MAX]
        );

        $realRegs = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM ' . $regs . ' WHERE source <> ? AND (telegram_id < ? OR telegram_id > ?)',
            [self::SOURCE, self::TG_MIN, self::TG_MAX]
        );

        return [
            'seed_users' => $seedUsers,
            'real_users' => $realUsers,
            'seed_regs'  => $seedRegs,
            'real_regs'  => $realRegs,
        ];
    }

    /**
     * Refuse to run on a database that already holds data, unless --fresh.
     *
     * @param array<string,int> $counts
     */
    private function guard(array $counts): void
    {
        $real  = $counts['real_users'] + $counts['real_regs'];
        $seeded = $counts['seed_users'] + $counts['seed_regs'];
        $fresh = $this->options['fresh'] === true;

        seed_out('');
        seed_out('-- Existing data ----------------------------------------------');
        seed_out(sprintf(
            '   real:   %d user(s), %d registration(s)',
            $counts['real_users'],
            $counts['real_regs']
        ));
        seed_out(sprintf(
            '   seeded: %d user(s), %d registration(s)',
            $counts['seed_users'],
            $counts['seed_regs']
        ));

        if ($real > 0 && !$fresh) {
            throw new SeedAbort(
                "The database already contains REAL data ("
                . $counts['real_users'] . " user(s), " . $counts['real_regs'] . " registration(s)).\n"
                . "  Refusing to touch it.\n\n"
                . "  If this really is a development copy, re-run with --fresh:\n"
                . "      php tools/seed.php --fresh\n"
                . "  --fresh only removes rows this seeder created (telegram_id "
                . self::TG_MIN . '..' . self::TG_MAX . ", source='" . self::SOURCE . "').\n"
                . "  Real rows stay untouched and no table is ever dropped."
            );
        }

        if ($seeded > 0 && !$fresh) {
            throw new SeedAbort(
                "Demo data is already present (" . $counts['seed_users'] . " user(s), "
                . $counts['seed_regs'] . " registration(s)).\n"
                . "  Re-run with --fresh to replace it:\n"
                . "      php tools/seed.php --fresh"
            );
        }

        if ($real > 0 && $fresh) {
            seed_out('');
            seed_out('   ***********************************************************');
            seed_out('   *  WARNING: this database holds REAL rows.                *');
            seed_out('   *  They will NOT be modified, but if this is a live        *');
            seed_out('   *  installation you should stop now (Ctrl+C).              *');
            seed_out('   *  DIQQAT: bazada real yozuvlar bor. Ular o\'zgarmaydi,     *');
            seed_out('   *  lekin bu real server bo\'lsa — hoziroq to\'xtating!       *');
            seed_out('   ***********************************************************');
        }
    }

    /**
     * Delete previously seeded rows — and only those.
     */
    private function purge(): void
    {
        seed_out('');
        seed_out('-- Purging previous demo data (seeded rows only) --------------');

        $removed = [];

        // Broadcasts written by this tool are recognised by their filters JSON.
        $broadcastIds = [];

        if ($this->db->tableExists('broadcasts')) {
            $rows = $this->db->fetchAll(
                'SELECT id FROM ' . $this->db->quoteIdent($this->db->table('broadcasts'))
                . ' WHERE filters LIKE ?',
                ['%' . self::MARKER . '%']
            );

            foreach ($rows as $row) {
                $broadcastIds[] = (int) $row['id'];
            }
        }

        if ($this->db->tableExists('broadcast_targets')) {
            $deleted = 0;

            foreach (array_chunk($broadcastIds, 200) as $chunk) {
                $deleted += $this->db->delete('broadcast_targets', ['broadcast_id' => $chunk]);
            }

            $deleted += (int) $this->db->query(
                'DELETE FROM ' . $this->db->quoteIdent($this->db->table('broadcast_targets'))
                . ' WHERE telegram_id BETWEEN ? AND ?',
                [self::TG_MIN, self::TG_MAX]
            )->rowCount();

            $removed['broadcast_targets'] = $deleted;
        }

        if ($broadcastIds !== [] && $this->db->tableExists('broadcasts')) {
            $deleted = 0;

            foreach (array_chunk($broadcastIds, 200) as $chunk) {
                $deleted += $this->db->delete('broadcasts', ['id' => $chunk]);
            }

            $removed['broadcasts'] = $deleted;
        }

        if ($this->db->tableExists('registrations')) {
            $removed['registrations'] = (int) $this->db->query(
                'DELETE FROM ' . $this->db->quoteIdent($this->db->table('registrations'))
                . ' WHERE source = ? OR (telegram_id BETWEEN ? AND ?)',
                [self::SOURCE, self::TG_MIN, self::TG_MAX]
            )->rowCount();
        }

        if ($this->db->tableExists('rate_limits')) {
            $removed['rate_limits'] = (int) $this->db->query(
                'DELETE FROM ' . $this->db->quoteIdent($this->db->table('rate_limits'))
                . ' WHERE telegram_id BETWEEN ? AND ?',
                [self::TG_MIN, self::TG_MAX]
            )->rowCount();
        }

        if ($this->db->tableExists('audit_log')) {
            $removed['audit_log'] = (int) $this->db->query(
                'DELETE FROM ' . $this->db->quoteIdent($this->db->table('audit_log'))
                . ' WHERE meta LIKE ?',
                ['%' . self::MARKER . '%']
            )->rowCount();
        }

        if ($this->db->tableExists('users')) {
            $removed['users'] = (int) $this->db->query(
                'DELETE FROM ' . $this->db->quoteIdent($this->db->table('users'))
                . ' WHERE telegram_id BETWEEN ? AND ?',
                [self::TG_MIN, self::TG_MAX]
            )->rowCount();
        }

        foreach ($removed as $table => $n) {
            seed_out(sprintf('   removed %5d row(s) from %s', $n, $this->db->table($table)));
        }

        if ($removed === []) {
            seed_out('   nothing to remove');
        }
    }

    /**
     * Build the whole dataset in memory (nothing is written yet).
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildApplicants(int $count, int $days): array
    {
        $perDay = $this->dailyCurve($count, $days);
        $now    = time();
        $today  = (int) strtotime('today 00:00:00');

        $applicants = [];
        $index      = 0;

        foreach ($perDay as $offset => $amount) {
            $dayStart = $today - (($days - 1 - $offset) * 86400);

            for ($i = 0; $i < $amount; $i++) {
                $createdAt = $dayStart + ($this->hourOfDay() * 3600) + mt_rand(0, 3599);

                if ($createdAt > $now) {
                    $createdAt = $now - mt_rand(60, 3600);
                }

                $applicants[] = $this->makeApplicant($index, $createdAt, $now);
                $index++;
            }
        }

        usort($applicants, static function (array $a, array $b): int {
            return $a['created_ts'] <=> $b['created_ts'];
        });

        // Re-assign telegram ids so that ids grow with time — exactly what a
        // real installation looks like.
        foreach ($applicants as $position => $applicant) {
            $applicants[$position]['telegram_id'] = self::TG_FIRST + $position;
        }

        return $this->moderate($applicants, $now);
    }

    /**
     * Turn roughly 22% of the applications into "approved" and 8% into
     * "rejected", leaving the rest pending.
     *
     * Quotas instead of per-row dice: the totals then really match the
     * documented distribution no matter how small the batch is. Only
     * applications older than two days can be reviewed — brand new ones stay
     * pending, exactly like a real moderation queue.
     *
     * @param array<int,array<string,mixed>> $applicants
     * @return array<int,array<string,mixed>>
     */
    private function moderate(array $applicants, int $now): array
    {
        $total = count($applicants);

        if ($total === 0) {
            return $applicants;
        }

        $eligible = [];

        foreach ($applicants as $position => $applicant) {
            if ((int) $applicant['created_ts'] <= $now - (2 * 86400)) {
                $eligible[] = $position;
            }
        }

        shuffle($eligible);

        $approved = (int) round($total * 0.22);
        $rejected = (int) round($total * 0.08);
        $capacity = count($eligible);

        if ($approved + $rejected > $capacity) {
            $ratio    = $capacity / max(1, $approved + $rejected);
            $approved = (int) floor($approved * $ratio);
            $rejected = (int) floor($rejected * $ratio);
        }

        $handled = 0;

        foreach ($eligible as $position) {
            if ($handled >= $approved + $rejected) {
                break;
            }

            $applicants[$position] = $this->review(
                $applicants[$position],
                $handled < $approved ? 'approved' : 'rejected',
                $now
            );

            $handled++;
        }

        return $applicants;
    }

    /**
     * Stamp one application as reviewed by an admin.
     *
     * @param array<string,mixed> $applicant
     * @return array<string,mixed>
     */
    private function review(array $applicant, string $status, int $now): array
    {
        $createdTs  = (int) $applicant['created_ts'];
        $reviewedTs = min($now, $createdTs + mt_rand(2 * 3600, 5 * 86400));
        $notes      = $status === 'approved' ? $this->approvedNotes() : $this->rejectedNotes();

        $applicant['status']      = $status;
        $applicant['reviewed_by'] = $this->reviewerId();
        $applicant['reviewed_at'] = date('Y-m-d H:i:s', $reviewedTs);
        $applicant['updated_at']  = date('Y-m-d H:i:s', $reviewedTs);
        $applicant['admin_note']  = mt_rand(1, 100) <= ($status === 'approved' ? 45 : 85)
            ? $this->pick($notes)
            : null;

        return $applicant;
    }

    /**
     * One demo applicant (user + registration payload).
     *
     * @return array<string,mixed>
     */
    private function makeApplicant(int $index, int $createdTs, int $now): array
    {
        $female = mt_rand(1, 100) <= 48;
        $first  = $this->pick($female ? $this->femaleNames() : $this->maleNames());

        $surnames = $this->surnames();
        $surname  = $surnames[array_rand($surnames)];
        $last     = $female ? $surname[1] : $surname[0];

        $fullName = $this->composeFullName($first, $last, $female);

        $age       = (int) $this->weighted($this->ageWeights());
        $birthYear = (int) date('Y', $createdTs) - $age;

        $directions = $this->pickDirections();
        $district   = (string) $this->weighted($this->districtWeights());

        [$portfolio, $links] = $this->portfolio($first, $last, $directions);

        $createdAt  = date('Y-m-d H:i:s', $createdTs);
        $lastSeenTs = min($now, $createdTs + mt_rand(0, max(3600, $now - $createdTs)));

        return [
            'index'           => $index,
            'telegram_id'     => self::TG_FIRST + $index,
            'username'        => mt_rand(1, 100) <= 68 ? $this->username($first, $last) : null,
            'first_name'      => $first,
            'last_name'       => mt_rand(1, 100) <= 78 ? $last : null,
            'locale'          => mt_rand(1, 100) <= 85 ? 'uz' : 'ru',
            'is_blocked'      => mt_rand(1, 100) <= 3 ? 1 : 0,
            'full_name'       => $fullName,
            'phone'           => $this->phone(),
            'birth_year'      => $birthYear,
            'district'        => $district,
            'directions'      => $directions,
            'direction_other' => in_array('other', $directions, true) ? $this->pick($this->otherDirections()) : null,
            'portfolio'       => $portfolio,
            'portfolio_links' => $links,
            'status'          => 'pending',
            'admin_note'      => null,
            'reviewed_by'     => null,
            'reviewed_at'     => null,
            'created_ts'      => $createdTs,
            'created_at'      => $createdAt,
            'updated_at'      => $createdAt,
            'last_seen_at'    => date('Y-m-d H:i:s', $lastSeenTs),
        ];
    }

    /**
     * Persist users + registrations.
     *
     * @param array<int,array<string,mixed>> $applicants
     * @return array<string,int>
     */
    private function write(array $applicants): array
    {
        $this->useUserRepo = class_exists('AiTalents\\Repository\\UserRepository');
        $this->useRegRepo  = class_exists('AiTalents\\Repository\\RegistrationRepository');

        seed_out(sprintf(
            '   users:         %s',
            $this->useUserRepo ? 'via UserRepository' : 'via direct SQL (repository not on disk)'
        ));
        seed_out(sprintf(
            '   registrations: %s',
            $this->useRegRepo ? 'via RegistrationRepository' : 'via direct SQL (repository not on disk)'
        ));

        $total = count($applicants);
        $step  = max(1, (int) floor($total / 10));
        $done  = ['users' => 0, 'registrations' => 0];

        $this->db->transaction(function () use ($applicants, $total, $step, &$done): void {
            foreach ($applicants as $position => $applicant) {
                $userId = $this->writeUser($applicant);
                $done['users']++;

                if ($userId > 0) {
                    $this->writeRegistration($userId, $applicant);
                    $done['registrations']++;
                }

                $n = $position + 1;

                if ($n % $step === 0 || $n === $total) {
                    seed_out(sprintf('   .. %d/%d applicants', $n, $total));
                }
            }
        });

        return $done;
    }

    /**
     * Insert (or refresh) the users row and return its id.
     *
     * @param array<string,mixed> $applicant
     */
    private function writeUser(array $applicant): int
    {
        $telegramId = (int) $applicant['telegram_id'];

        if ($this->useUserRepo) {
            try {
                $this->app->users()->touch([
                    'id'            => $telegramId,
                    'is_bot'        => false,
                    'first_name'    => $applicant['first_name'],
                    'last_name'     => $applicant['last_name'],
                    'username'      => $applicant['username'],
                    'language_code' => $applicant['locale'],
                ], 'private');
            } catch (\Throwable $e) {
                $this->useUserRepo = false;
                seed_out('   !! UserRepository::touch() failed (' . $e->getMessage() . ') — falling back to direct SQL');
            }
        }

        if (!$this->useUserRepo && !$this->db->exists('users', ['telegram_id' => $telegramId])) {
            $this->db->insert('users', [
                'telegram_id'  => $telegramId,
                'username'     => $applicant['username'],
                'first_name'   => $applicant['first_name'],
                'last_name'    => $applicant['last_name'],
                'locale'       => $applicant['locale'],
                'state'        => 'idle',
                'state_data'   => null,
                'is_admin'     => 0,
                'is_blocked'   => (int) $applicant['is_blocked'],
                'last_seen_at' => $applicant['last_seen_at'],
                'created_at'   => $applicant['created_at'],
                'updated_at'   => $applicant['updated_at'],
            ]);
        }

        // Backdate + apply the fields the repository does not manage.
        $this->db->update(
            'users',
            [
                'username'     => $applicant['username'],
                'first_name'   => $applicant['first_name'],
                'last_name'    => $applicant['last_name'],
                'locale'       => $applicant['locale'],
                'state'        => 'idle',
                'state_data'   => null,
                'is_blocked'   => (int) $applicant['is_blocked'],
                'last_seen_at' => $applicant['last_seen_at'],
                'created_at'   => $applicant['created_at'],
                'updated_at'   => $applicant['updated_at'],
            ],
            ['telegram_id' => $telegramId]
        );

        return (int) $this->db->fetchColumn(
            'SELECT id FROM ' . $this->db->quoteIdent($this->db->table('users'))
            . ' WHERE telegram_id = ?',
            [$telegramId]
        );
    }

    /**
     * Insert (or refresh) the registrations row.
     *
     * @param array<string,mixed> $applicant
     */
    private function writeRegistration(int $userId, array $applicant): void
    {
        $telegramId = (int) $applicant['telegram_id'];
        $saved      = false;

        if ($this->useRegRepo) {
            try {
                $this->app->registrations()->save($userId, $telegramId, [
                    'full_name'       => $applicant['full_name'],
                    'phone'           => $applicant['phone'],
                    'birth_year'      => $applicant['birth_year'],
                    'district'        => $applicant['district'],
                    'directions'      => $applicant['directions'],
                    'direction_other' => $applicant['direction_other'],
                    'portfolio'       => $applicant['portfolio'],
                    'portfolio_links' => $applicant['portfolio_links'],
                    'status'          => $applicant['status'],
                    'source'          => self::SOURCE,
                ]);

                $saved = true;
            } catch (\Throwable $e) {
                $this->useRegRepo = false;
                seed_out('   !! RegistrationRepository::save() failed (' . $e->getMessage() . ') — falling back to direct SQL');
            }
        }

        if (!$saved && !$this->db->exists('registrations', ['telegram_id' => $telegramId])) {
            $this->db->insert('registrations', [
                'user_id'         => $userId,
                'telegram_id'     => $telegramId,
                'full_name'       => $applicant['full_name'],
                'phone'           => $applicant['phone'],
                'birth_year'      => $applicant['birth_year'],
                'district'        => $applicant['district'],
                'directions'      => $this->json($applicant['directions']),
                'direction_other' => $applicant['direction_other'],
                'portfolio'       => $applicant['portfolio'],
                'portfolio_links' => $this->json($applicant['portfolio_links']),
                'status'          => $applicant['status'],
                'admin_note'      => $applicant['admin_note'],
                'reviewed_by'     => $applicant['reviewed_by'],
                'reviewed_at'     => $applicant['reviewed_at'],
                'source'          => self::SOURCE,
                'created_at'      => $applicant['created_at'],
                'updated_at'      => $applicant['updated_at'],
            ]);
        }

        // created_at must be backdated and the review fields are not part of the
        // repository contract — force them here in both modes.
        $this->db->update(
            'registrations',
            [
                'user_id'     => $userId,
                'status'      => $applicant['status'],
                'admin_note'  => $applicant['admin_note'],
                'reviewed_by' => $applicant['reviewed_by'],
                'reviewed_at' => $applicant['reviewed_at'],
                'source'      => self::SOURCE,
                'created_at'  => $applicant['created_at'],
                'updated_at'  => $applicant['updated_at'],
            ],
            ['telegram_id' => $telegramId]
        );
    }

    /**
     * A handful of audit_log rows so the audit page is not empty.
     *
     * Written with direct SQL on purpose: AuditRepository::log() stamps
     * created_at with "now" and returns no id, so backdating is impossible
     * through it.
     *
     * @param array<int,array<string,mixed>> $applicants
     */
    private function seedAudit(array $applicants): int
    {
        if ($applicants === []) {
            return 0;
        }

        $now     = time();
        $rows    = 0;
        $reviewer = 'panel:' . (string) $this->app->config('security.admin_panel.username', 'admin');
        $ips     = ['185.213.229.14', '84.54.83.201', '213.230.110.7', '127.0.0.1'];

        $entries = [];

        // Login events across the window.
        for ($i = 0; $i < 6; $i++) {
            $ts = $now - mt_rand(3600, (int) $this->options['days'] * 86400);

            $entries[] = [
                'actor'      => $reviewer,
                'action'     => 'auth.login',
                'target'     => null,
                'meta'       => ['seed' => true, 'result' => 'ok'],
                'ip'         => $this->pick($ips),
                'created_at' => date('Y-m-d H:i:s', $ts),
            ];
        }

        // Moderation events tied to real seeded registrations.
        $reviewed = [];

        foreach ($applicants as $applicant) {
            if ($applicant['status'] !== 'pending') {
                $reviewed[] = $applicant;
            }
        }

        shuffle($reviewed);

        foreach (array_slice($reviewed, 0, 12) as $applicant) {
            $entries[] = [
                'actor'      => $reviewer,
                'action'     => $applicant['status'] === 'approved'
                    ? 'registration.approve'
                    : 'registration.reject',
                'target'     => 'registration:' . $applicant['telegram_id'],
                'meta'       => [
                    'seed'   => true,
                    'status' => $applicant['status'],
                    'name'   => $applicant['full_name'],
                ],
                'ip'         => $this->pick($ips),
                'created_at' => (string) ($applicant['reviewed_at'] ?? $applicant['created_at']),
            ];
        }

        // A few housekeeping events.
        $extra = [
            ['settings.update', null, ['seed' => true, 'field' => 'registration_open', 'value' => true]],
            ['settings.update', null, ['seed' => true, 'field' => 'required_channel', 'value' => '@aitalents_andijon']],
            ['export.csv', 'registrations', ['seed' => true, 'rows' => count($applicants)]],
            ['broadcast.create', 'broadcast:demo', ['seed' => true, 'audience' => 'approved']],
            ['user.block', 'user:' . (self::TG_FIRST + 3), ['seed' => true, 'reason' => 'spam']],
            ['logs.clear', null, ['seed' => true, 'files' => 3]],
        ];

        foreach ($extra as $item) {
            $entries[] = [
                'actor'      => $reviewer,
                'action'     => $item[0],
                'target'     => $item[1],
                'meta'       => $item[2],
                'ip'         => $this->pick($ips),
                'created_at' => date('Y-m-d H:i:s', $now - mt_rand(3600, 20 * 86400)),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return strcmp((string) $a['created_at'], (string) $b['created_at']);
        });

        $this->db->transaction(function () use ($entries, &$rows): void {
            foreach ($entries as $entry) {
                $this->db->insert('audit_log', [
                    'actor'      => $entry['actor'],
                    'action'     => $entry['action'],
                    'target'     => $entry['target'],
                    'meta'       => $this->json($entry['meta']),
                    'ip'         => $entry['ip'],
                    'created_at' => $entry['created_at'],
                ]);

                $rows++;
            }
        });

        seed_out(sprintf('   .. %d audit_log row(s)', $rows));

        return $rows;
    }

    /**
     * One finished broadcast plus its targets.
     *
     * @param array<int,array<string,mixed>> $applicants
     * @return array<string,int>
     */
    private function seedBroadcast(array $applicants): array
    {
        if ($applicants === []) {
            return ['targets' => 0, 'sent' => 0, 'failed' => 0];
        }

        $this->useBroadcastRepo = class_exists('AiTalents\\Repository\\BroadcastRepository');

        $text = "<b>Andijon AI Talents</b>\n\n"
            . "Assalomu alaykum! Tanlovning birinchi bosqichi yakunlandi.\n"
            . "Saralangan ishtirokchilar bilan <b>shanba kuni soat 10:00</b> da onlayn suhbat o'tkaziladi.\n\n"
            . "Havola bot orqali yuboriladi. Omad tilaymiz!";

        $filters   = ['seed' => true, 'audience' => 'registered', 'status' => 'approved'];
        $createdTs = time() - mt_rand(3 * 86400, 6 * 86400);
        $createdAt = date('Y-m-d H:i:s', $createdTs);

        $targets = [];

        foreach ($applicants as $applicant) {
            if ((int) $applicant['is_blocked'] === 1) {
                continue;
            }

            if ($applicant['status'] === 'rejected') {
                continue;
            }

            $targets[] = (int) $applicant['telegram_id'];
        }

        shuffle($targets);
        $targets = array_slice($targets, 0, min(count($targets), 80));
        sort($targets);

        if ($targets === []) {
            return ['targets' => 0, 'sent' => 0, 'failed' => 0];
        }

        $broadcastId = 0;

        if ($this->useBroadcastRepo) {
            try {
                $broadcastId = $this->app->broadcasts()->create($this->reviewerId(), $text, $filters, 'HTML');
                $this->app->broadcasts()->addTargets($broadcastId, $targets);
            } catch (\Throwable $e) {
                $this->useBroadcastRepo = false;
                $broadcastId = 0;
                seed_out('   !! BroadcastRepository failed (' . $e->getMessage() . ') — falling back to direct SQL');
            }
        }

        if ($broadcastId === 0) {
            $broadcastId = $this->db->insert('broadcasts', [
                'admin_id'    => $this->reviewerId(),
                'text'        => $text,
                'parse_mode'  => 'HTML',
                'filters'     => $this->json($filters),
                'status'      => 'draft',
                'total'       => 0,
                'sent'        => 0,
                'failed'      => 0,
                'created_at'  => $createdAt,
                'updated_at'  => $createdAt,
                'finished_at' => null,
            ]);

            $this->db->transaction(function () use ($broadcastId, $targets): void {
                foreach ($targets as $telegramId) {
                    $this->db->insert('broadcast_targets', [
                        'broadcast_id' => $broadcastId,
                        'telegram_id'  => $telegramId,
                        'status'       => 'pending',
                        'error'        => null,
                        'sent_at'      => null,
                    ]);
                }
            });
        }

        // Mark the targets as delivered (a few failures for realism).
        $ids = $this->db->fetchAll(
            'SELECT id FROM ' . $this->db->quoteIdent($this->db->table('broadcast_targets'))
            . ' WHERE broadcast_id = ? ORDER BY id ASC',
            [$broadcastId]
        );

        $sentIds   = [];
        $failedIds = [];
        $cursor    = $createdTs;

        foreach ($ids as $row) {
            if (mt_rand(1, 100) <= 6) {
                $failedIds[] = (int) $row['id'];
                continue;
            }

            $sentIds[] = (int) $row['id'];
        }

        $this->db->transaction(function () use ($sentIds, $failedIds, $cursor): void {
            $stamp = $cursor;

            foreach (array_chunk($sentIds, 100) as $chunk) {
                $stamp += mt_rand(5, 40);

                $this->db->update(
                    'broadcast_targets',
                    ['status' => 'sent', 'error' => null, 'sent_at' => date('Y-m-d H:i:s', $stamp)],
                    ['id' => $chunk]
                );
            }

            foreach (array_chunk($failedIds, 100) as $chunk) {
                $stamp += mt_rand(5, 40);

                $this->db->update(
                    'broadcast_targets',
                    [
                        'status'  => 'failed',
                        'error'   => 'Forbidden: bot was blocked by the user',
                        'sent_at' => date('Y-m-d H:i:s', $stamp),
                    ],
                    ['id' => $chunk]
                );
            }
        });

        $total  = count($sentIds) + count($failedIds);
        $finish = date('Y-m-d H:i:s', $createdTs + 60 + ($total * 2));

        $this->db->update(
            'broadcasts',
            [
                'status'      => 'done',
                'total'       => $total,
                'sent'        => count($sentIds),
                'failed'      => count($failedIds),
                'filters'     => $this->json($filters),
                'created_at'  => $createdAt,
                'updated_at'  => $finish,
                'finished_at' => $finish,
            ],
            ['id' => $broadcastId]
        );

        seed_out(sprintf(
            '   .. 1 broadcast (#%d) with %d target(s): %d sent, %d failed',
            $broadcastId,
            $total,
            count($sentIds),
            count($failedIds)
        ));

        return ['targets' => $total, 'sent' => count($sentIds), 'failed' => count($failedIds)];
    }

    /**
     * Final summary.
     *
     * @param array<int,array<string,mixed>> $applicants
     * @param array<string,int>              $written
     * @param array<string,int>              $cast
     */
    private function report(array $applicants, array $written, int $auditRows, array $cast, float $seconds): void
    {
        $byStatus   = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        $byDistrict = [];
        $first      = null;
        $last       = null;

        foreach ($applicants as $applicant) {
            $status = (string) $applicant['status'];
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $district = (string) $applicant['district'];
            $byDistrict[$district] = ($byDistrict[$district] ?? 0) + 1;

            $ts = (int) $applicant['created_ts'];
            $first = $first === null ? $ts : min($first, $ts);
            $last  = $last === null ? $ts : max($last, $ts);
        }

        arsort($byDistrict);

        $total = max(1, count($applicants));

        seed_out('');
        seed_out('-- Summary ----------------------------------------------------');
        seed_out(sprintf('   users written:         %d', $written['users'] ?? 0));
        seed_out(sprintf('   registrations written: %d', $written['registrations'] ?? 0));
        seed_out(sprintf('   audit_log rows:        %d', $auditRows));
        seed_out(sprintf(
            '   broadcast targets:     %d (%d sent, %d failed)',
            $cast['targets'] ?? 0,
            $cast['sent'] ?? 0,
            $cast['failed'] ?? 0
        ));
        seed_out('');
        seed_out('   Status breakdown:');

        foreach ($byStatus as $status => $n) {
            seed_out(sprintf('     %-10s %5d  %5.1f%%', $status, $n, ($n / $total) * 100));
        }

        seed_out('');
        seed_out('   Top districts:');

        $shown = 0;

        foreach ($byDistrict as $district => $n) {
            seed_out(sprintf('     %-16s %5d  %5.1f%%', $district, $n, ($n / $total) * 100));

            if (++$shown >= 5) {
                break;
            }
        }

        if ($first !== null && $last !== null) {
            seed_out('');
            seed_out(sprintf(
                '   Date range: %s .. %s (%d day(s))',
                date('Y-m-d H:i', $first),
                date('Y-m-d H:i', $last),
                (int) $this->options['days']
            ));
        }

        seed_out('');
        seed_out(sprintf('   Done in %.2f s. Random seed: %d (same seed => same data).', $seconds, (int) $this->options['seed']));
        seed_out('');
        seed_out('   REMINDER: this data is FAKE. Development/demo only —');
        seed_out('   ESLATMA: bu ma\'lumotlar soxta. Faqat demo uchun!');
        seed_out('   Remove it any time with:  php tools/seed.php --fresh --count=1');
        seed_out('===============================================================');
        seed_out('');
    }

    /* ====================================================================
     | Data generators
     */

    /**
     * Distribute $count registrations over $days with a believable curve:
     * weekdays busier than weekends, slow growth over time, two spike days.
     *
     * @return array<int,int> day offset (0 = oldest) => amount
     */
    private function dailyCurve(int $count, int $days): array
    {
        $weekday = [1 => 1.18, 2 => 1.22, 3 => 1.16, 4 => 1.10, 5 => 0.92, 6 => 0.58, 7 => 0.50];

        $spikeA = (int) floor($days * 0.32);
        $spikeB = (int) floor($days * 0.78);

        $weights = [];
        $sum     = 0.0;
        $today   = (int) strtotime('today 00:00:00');

        for ($i = 0; $i < $days; $i++) {
            $dayTs = $today - (($days - 1 - $i) * 86400);
            $dow   = (int) date('N', $dayTs);

            $w = $weekday[$dow] ?? 1.0;

            // Interest slowly grows as the deadline approaches.
            $w *= $days > 1 ? (0.55 + (0.9 * ($i / ($days - 1)))) : 1.0;

            // Two announcement spikes.
            if ($i === $spikeA) {
                $w *= 3.4;
            }

            if ($i === $spikeB) {
                $w *= 2.7;
            }

            if ($i === $spikeA + 1 || $i === $spikeB + 1) {
                $w *= 1.6;
            }

            // A little noise so the chart is not too smooth.
            $w *= 0.82 + (mt_rand(0, 40) / 100);

            $weights[$i] = $w;
            $sum += $w;
        }

        if ($sum <= 0.0) {
            $sum = 1.0;
        }

        // Largest remainder method so the totals add up exactly to $count.
        $result     = [];
        $remainders = [];
        $assigned   = 0;

        foreach ($weights as $i => $w) {
            $exact  = ($w / $sum) * $count;
            $floor  = (int) floor($exact);
            $result[$i]     = $floor;
            $remainders[$i] = $exact - $floor;
            $assigned      += $floor;
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $i) {
            if ($assigned >= $count) {
                break;
            }

            $result[$i]++;
            $assigned++;
        }

        ksort($result);

        return $result;
    }

    /**
     * A realistic hour of the day (people register after school/work).
     */
    private function hourOfDay(): int
    {
        return (int) $this->weighted([
            0 => 2, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1,
            6 => 2, 7 => 5, 8 => 10, 9 => 17, 10 => 23, 11 => 25,
            12 => 20, 13 => 17, 14 => 18, 15 => 20, 16 => 22, 17 => 24,
            18 => 26, 19 => 30, 20 => 31, 21 => 26, 22 => 16, 23 => 7,
        ]);
    }

    /**
     * "Ism Familiya", "Familiya Ism" or a full official form.
     */
    private function composeFullName(string $first, string $last, bool $female): string
    {
        $roll = mt_rand(1, 100);

        if ($roll <= 58) {
            return $first . ' ' . $last;
        }

        if ($roll <= 82) {
            return $last . ' ' . $first;
        }

        $father = $this->pick($this->maleNames());

        return $last . ' ' . $first . ' ' . $father . ($female ? ' qizi' : ' o‘g‘li');
    }

    /**
     * A valid Uzbek mobile number in +998XXXXXXXXX form.
     */
    private function phone(): string
    {
        $codes = ['90' => 20, '91' => 16, '93' => 13, '94' => 12, '97' => 11,
                  '88' => 8, '99' => 6, '33' => 6, '95' => 4, '98' => 4];

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $phone = '+998' . (string) $this->weighted($codes) . (string) mt_rand(1000000, 9999999);

            if (!isset($this->usedPhones[$phone])) {
                $this->usedPhones[$phone] = true;

                return $phone;
            }
        }

        return '+998' . (string) $this->weighted($codes) . (string) mt_rand(1000000, 9999999);
    }

    /**
     * 1..3 distinct direction keys, popular ones first.
     *
     * @return string[]
     */
    private function pickDirections(): array
    {
        $weights = [];

        foreach ($this->directionKeys as $key) {
            $weights[$key] = $this->directionWeights()[$key] ?? 5;
        }

        $howMany = (int) $this->weighted([1 => 45, 2 => 38, 3 => 17]);
        $howMany = min($howMany, count($weights));

        $picked = [];

        for ($i = 0; $i < $howMany; $i++) {
            if ($weights === []) {
                break;
            }

            $key = (string) $this->weighted($weights);
            $picked[] = $key;
            unset($weights[$key]);
        }

        return $picked;
    }

    /**
     * Portfolio text + the links extracted from it.
     *
     * @param string[] $directions
     * @return array{0:?string,1:array<int,string>}
     */
    private function portfolio(string $first, string $last, array $directions): array
    {
        if (mt_rand(1, 100) > 62) {
            return [null, []];
        }

        $handle = $this->slug($first) . $this->slug($last);
        $handle = substr($handle, 0, 18);

        $templates = [
            'Maktabda informatika to‘garagiga qatnayman. Python‘da kichik Telegram botlar yozganman: https://github.com/' . $handle,
            'Freelance orqali 12 ta logotip va 3 ta brend identifikatsiyasi tayyorlaganman. Ishlarim: https://www.behance.net/' . $handle,
            'IT Park Andijon filialida 6 oylik frontend kursini tugatganman. Portfolio: https://' . $handle . '.github.io',
            'Robototexnika bo‘yicha viloyat bosqichida 2-o‘rinni egallaganman. Arduino va ESP32 bilan ishlayman.',
            'AI haqida Telegram kanal yuritaman: https://t.me/' . $handle . '_ai — haftasiga 3 ta post chiqaraman.',
            'Hozircha katta tajribam yo‘q, lekin har kuni 2 soat mustaqil o‘rganaman. HTML, CSS va Scratch asoslarini bilaman.',
            'Universitetda ma’lumotlar tahlili fanidan kurs ishi qildim: Pandas, NumPy va Matplotlib bilan ishlaganman.',
            'Kichik do‘kon uchun Telegram bot va admin panel yasadim (PHP + MySQL). Kod: https://github.com/' . $handle . '/shopbot',
            'Figma’da 20 dan ortiq mobil ilova ekranlarini chizganman. Havola: https://www.figma.com/@' . $handle,
            '3D modellashtirish bilan shug‘ullanaman (Blender). Qisqa animatsiyalarim bor.',
            'Maktab olimpiadasida dasturlash bo‘yicha tuman bosqichida 1-o‘rin. C++ va Pythonni bilaman.',
            'Kiberxavfsizlikka qiziqaman, TryHackMe’da 40 dan ortiq xonani yechganman.',
            'Mobil ilovalar yozaman (Flutter). Play Marketda 1 ta chop etilgan ilovam bor: https://t.me/' . $handle,
            'SMM bo‘yicha 2 yil tajriba: kichik biznes sahifalarini yuritaman, kontent-reja tuzaman.',
            'Data Science kursini onlayn tugatdim, Kaggle’da 3 ta yakuniy loyiha qildim.',
        ];

        $text = $this->pick($templates);

        // A quarter of the answers are longer — people do write essays.
        if (mt_rand(1, 100) <= 25) {
            $text .= ' ' . $this->pick([
                'Jamoada ishlashni yaxshi ko‘raman va yangi bilimlarni tez o‘zlashtiraman.',
                'Ingliz tilini o‘rta darajada bilaman (B1), rus tilida erkin gaplasha olaman.',
                'Kelajakda o‘z startapimni ochishni rejalashtirganman.',
                'Hafta davomida kuniga 3-4 soat vaqt ajrata olaman.',
            ]);
        }

        $links = [];

        if (preg_match_all('~https?://[^\s,;]+~u', $text, $matches) === false) {
            $matches = [[]];
        }

        foreach ($matches[0] as $url) {
            $url = rtrim((string) $url, '.,);');

            if (!in_array($url, $links, true)) {
                $links[] = $url;
            }
        }

        // Some people paste extra links without describing them.
        if ($links !== [] && mt_rand(1, 100) <= 30) {
            $extra = 'https://t.me/' . $handle;

            if (!in_array($extra, $links, true)) {
                $links[] = $extra;
            }
        }

        return [$text, $links];
    }

    /**
     * A Telegram-style username derived from the person's name.
     */
    private function username(string $first, string $last): string
    {
        $base = $this->slug($first);
        $tail = $this->slug($last);

        $variants = [
            $base . '_' . $tail,
            $base . $tail,
            $base . '_' . substr($tail, 0, 1),
            $base . (string) mt_rand(2004, 2011),
            $base . '_' . (string) mt_rand(1, 99),
            $tail . '_' . $base,
        ];

        $username = $variants[array_rand($variants)];
        $username = substr($username, 0, 28);

        if (isset($this->usedUsernames[$username])) {
            $suffix   = 1;
            $original = $username;

            while (isset($this->usedUsernames[$username])) {
                $username = substr($original, 0, 24) . (string) (++$suffix);
            }
        }

        $this->usedUsernames[$username] = true;

        return $username;
    }

    /**
     * Latin-only, lowercase, apostrophe-free version of a name.
     */
    private function slug(string $value): string
    {
        $value = str_replace(['‘', '’', 'ʻ', '\'', '`'], '', $value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

        $map = [
            'о' => 'o', 'а' => 'a', 'е' => 'e', 'ў' => 'o', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h',
        ];

        $value = strtr($value, $map);
        $value = (string) preg_replace('/[^a-z0-9]/', '', $value);

        return $value === '' ? 'user' : $value;
    }

    /**
     * The telegram id credited with approving/rejecting applications.
     */
    private function reviewerId(): int
    {
        $ids = $this->app->adminIds();

        foreach ($ids as $id) {
            if ((int) $id > 0) {
                return (int) $id;
            }
        }

        return self::TG_REVIEWER;
    }

    /* ====================================================================
     | Catalogs (prefer the real Catalog class, fall back to the spec list)
     */

    /**
     * @return string[]
     */
    private function districtKeys(): array
    {
        if (class_exists('AiTalents\\Registration\\Catalog')) {
            try {
                $keys = \AiTalents\Registration\Catalog::districtKeys();

                if (is_array($keys) && $keys !== []) {
                    return array_values(array_map('strval', $keys));
                }
            } catch (\Throwable $e) {
                // fall through to the built-in list
            }
        }

        return [
            'andijon_city', 'xonobod_city', 'qorasuv_city', 'andijon', 'asaka', 'baliqchi',
            'boz', 'buloqboshi', 'izboskan', 'jalaquduq', 'xojaobod', 'qorgontepa',
            'marhamat', 'oltinkol', 'paxtaobod', 'shahrixon', 'ulugnor', 'other',
        ];
    }

    /**
     * @return string[]
     */
    private function directionKeys(): array
    {
        if (class_exists('AiTalents\\Registration\\Catalog')) {
            try {
                $keys = \AiTalents\Registration\Catalog::directionKeys();

                if (is_array($keys) && $keys !== []) {
                    return array_values(array_map('strval', $keys));
                }
            } catch (\Throwable $e) {
                // fall through to the built-in list
            }
        }

        return [
            'programming', 'ai_ml', 'data_science', 'design', 'web', 'mobile',
            'robotics', 'cybersecurity', 'game_3d', 'content', 'other',
        ];
    }

    /**
     * Popularity per district — unknown keys get a default weight.
     *
     * @return array<string,int>
     */
    private function districtWeights(): array
    {
        $known = [
            'andijon_city' => 26, 'asaka' => 10, 'shahrixon' => 8, 'andijon' => 9,
            'qorgontepa' => 6, 'xonobod_city' => 6, 'qorasuv_city' => 5, 'izboskan' => 5,
            'jalaquduq' => 5, 'marhamat' => 5, 'paxtaobod' => 5, 'baliqchi' => 4,
            'boz' => 4, 'buloqboshi' => 4, 'oltinkol' => 4, 'xojaobod' => 4,
            'ulugnor' => 3, 'other' => 2,
        ];

        $weights = [];

        foreach ($this->districtKeys as $key) {
            $weights[$key] = $known[$key] ?? 4;
        }

        return $weights;
    }

    /**
     * @return array<string,int>
     */
    private function directionWeights(): array
    {
        return [
            'programming' => 26, 'ai_ml' => 20, 'design' => 14, 'web' => 13,
            'mobile' => 10, 'data_science' => 9, 'content' => 8, 'robotics' => 7,
            'cybersecurity' => 6, 'game_3d' => 6, 'other' => 3,
        ];
    }

    /**
     * @return array<int,int>
     */
    private function ageWeights(): array
    {
        return [
            14 => 5, 15 => 7, 16 => 10, 17 => 12, 18 => 12, 19 => 11, 20 => 10,
            21 => 8, 22 => 7, 23 => 5, 24 => 4, 25 => 3, 26 => 2, 27 => 2,
            28 => 1, 29 => 1, 30 => 1,
        ];
    }

    /**
     * @return string[]
     */
    private function maleNames(): array
    {
        return [
            'Abdulaziz', 'Jasurbek', 'Diyorbek', 'Shohruh', 'Islombek', 'Bekzod', 'Sardor',
            'Otabek', 'Doston', 'Javohir', 'Ulug‘bek', 'Sanjar', 'Nodirbek', 'Temurbek',
            'Xurshid', 'Rustam', 'Behruz', 'Muhammadali', 'Sherzod', 'Elyor', 'Anvar',
            'Farrux', 'Oybek', 'Akmal', 'Shahzod', 'Ibrohim', 'Amirbek', 'Asadbek',
            'Xusan', 'Azizbek', 'Ravshan', 'Sardorbek', 'Ulug‘murod', 'Ozodbek', 'Nurbek',
            'Mirjalol', 'Sanjarbek', 'Behzod', 'Sherali', 'Qahramon',
        ];
    }

    /**
     * @return string[]
     */
    private function femaleNames(): array
    {
        return [
            'Sarvinoz', 'Nilufar', 'Malika', 'Zilola', 'Mohira', 'Gulnoza', 'Dilnoza',
            'Shahnoza', 'Madina', 'Zuhra', 'Nozima', 'Sevara', 'Kamola', 'Feruza',
            'Xurshida', 'Robiya', 'Sitora', 'Maftuna', 'Muslima', 'Zarina', 'Nafisa',
            'Oygul', 'Dilafruz', 'Iroda', 'Shahzoda', 'Aziza', 'Munisa', 'Nigora',
            'Ozoda', 'Gulbahor', 'Dilbar', 'Mahliyo', 'Ruxshona', 'Shohsanam', 'Gulruh',
            'Zebo', 'Nasiba', 'Umida', 'Charos', 'Dildora',
        ];
    }

    /**
     * Surnames as [male form, female form].
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function surnames(): array
    {
        return [
            ['Rahmonov', 'Rahmonova'],
            ['Yo‘ldoshev', 'Yo‘ldosheva'],
            ['To‘xtasinov', 'To‘xtasinova'],
            ['Nazarov', 'Nazarova'],
            ['Qodirov', 'Qodirova'],
            ['Ergashev', 'Ergasheva'],
            ['Mamatqulov', 'Mamatqulova'],
            ['Sultonov', 'Sultonova'],
            ['Abdullayev', 'Abdullayeva'],
            ['Karimov', 'Karimova'],
            ['Yusupov', 'Yusupova'],
            ['Tursunov', 'Tursunova'],
            ['Xolmatov', 'Xolmatova'],
            ['Sobirov', 'Sobirova'],
            ['Umarov', 'Umarova'],
            ['Islomov', 'Islomova'],
            ['Rasulov', 'Rasulova'],
            ['Hamdamov', 'Hamdamova'],
            ['Jo‘rayev', 'Jo‘rayeva'],
            ['O‘rinboyev', 'O‘rinboyeva'],
            ['Sattorov', 'Sattorova'],
            ['Xasanov', 'Xasanova'],
            ['Mirzayev', 'Mirzayeva'],
            ['Normatov', 'Normatova'],
            ['Bekmurodov', 'Bekmurodova'],
            ['Toshpo‘latov', 'Toshpo‘latova'],
            ['G‘aniyev', 'G‘aniyeva'],
            ['Ochilov', 'Ochilova'],
            ['Qurbonov', 'Qurbonova'],
            ['Saidov', 'Saidova'],
            ['Eshonqulov', 'Eshonqulova'],
            ['Ismoilov', 'Ismoilova'],
            ['Hakimov', 'Hakimova'],
            ['Muhammadiyev', 'Muhammadiyeva'],
            ['Abdurahmonov', 'Abdurahmonova'],
            ['Nurmatov', 'Nurmatova'],
            ['Alimov', 'Alimova'],
            ['Sharipov', 'Sharipova'],
            ['Xudoyberdiyev', 'Xudoyberdiyeva'],
            ['Nematov', 'Nematova'],
        ];
    }

    /**
     * Free-text answers for the "other direction" step.
     *
     * @return string[]
     */
    private function otherDirections(): array
    {
        return [
            'Kvant hisoblash',
            'Sun’iy intellekt va tibbiyot',
            'Blokcheyn texnologiyalari',
            'Video montaj va motion dizayn',
            'Musiqa texnologiyalari',
            'Agrotexnologiya va dronlar',
            'Texnik yozuvchilik (documentation)',
        ];
    }

    /**
     * @return string[]
     */
    private function approvedNotes(): array
    {
        return [
            'Hujjatlar to‘liq, suhbatga taklif qilindi.',
            'Portfolio kuchli — 1-guruhga tavsiya etildi.',
            'Telefon raqami tasdiqlandi, aloqa o‘rnatildi.',
            'Olimpiada natijalari mavjud, darhol qabul qilindi.',
        ];
    }

    /**
     * @return string[]
     */
    private function rejectedNotes(): array
    {
        return [
            'Yosh talabga mos emas.',
            'Telefon raqami noto‘g‘ri, bog‘lanib bo‘lmadi.',
            'Ariza takrorlangan (dublikat).',
            'Tanlangan yo‘nalish bo‘yicha joylar to‘ldi.',
            'Ma’lumotlar to‘liq emas, qayta ariza berish tavsiya qilindi.',
        ];
    }

    /* ====================================================================
     | Small utilities
     */

    /**
     * @param array<int,mixed> $list
     */
    private function pick(array $list): mixed
    {
        if ($list === []) {
            return null;
        }

        return $list[array_rand($list)];
    }

    /**
     * Weighted random key.
     *
     * @param array<array-key,int> $weights
     */
    private function weighted(array $weights): string|int
    {
        $total = 0;

        foreach ($weights as $weight) {
            $total += max(0, (int) $weight);
        }

        if ($total <= 0) {
            $keys = array_keys($weights);

            return $keys === [] ? '' : $keys[0];
        }

        $roll = mt_rand(1, $total);

        foreach ($weights as $key => $weight) {
            $roll -= max(0, (int) $weight);

            if ($roll <= 0) {
                return $key;
            }
        }

        $keys = array_keys($weights);

        return $keys[count($keys) - 1];
    }

    /**
     * JSON for a TEXT column.
     */
    private function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * Human readable database target for the banner.
     */
    private function databaseLabel(): string
    {
        if ($this->db->driver() === 'sqlite') {
            $path = $this->db->sqlitePath();

            return $path === null ? 'sqlite' : $path;
        }

        return (string) $this->app->config('database.username', '')
            . '@' . (string) $this->app->config('database.host', 'localhost')
            . '/' . (string) $this->app->config('database.database', '');
    }
}

/* -------------------------------------------------------------------------
 | 4. Main
 */
try {
    $options = seed_parse_args(is_array($argv ?? null) ? $argv : []);

    if ($options['help'] === true) {
        seed_out(seed_usage());
        exit(0);
    }

    /** @var \AiTalents\App $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';

    if (!$app instanceof \AiTalents\App) {
        throw new RuntimeException('bootstrap.php did not return an \AiTalents\App instance.');
    }

    $seeder = new AiTalentsSeeder($app, $options);

    exit($seeder->run());
} catch (SeedAbort $e) {
    seed_err('');
    seed_err('-- Aborted ----------------------------------------------------');
    seed_err('  ' . $e->getMessage());
    seed_err('');

    exit(1);
} catch (InvalidArgumentException $e) {
    seed_err('');
    seed_err('Argument error: ' . $e->getMessage());
    seed_err('');
    seed_err(seed_usage());
    exit(1);
} catch (\Throwable $e) {
    seed_err('');
    seed_err('===============================================================');
    seed_err('  SEEDING FAILED');
    seed_err('===============================================================');
    seed_err('  ' . $e->getMessage());
    seed_err('');
    seed_err('  ' . get_class($e) . ' in ' . $e->getFile() . ':' . $e->getLine());
    seed_err('');
    seed_err('  Checklist:');
    seed_err('   - does config.php exist (copy it from config.example.php)?');
    seed_err('   - are the database credentials correct and is the server up?');
    seed_err('   - is data/ writable when using SQLite?');
    seed_err('   - did you mean to pass --fresh?');
    seed_err('  Tekshiring: config.php bormi, baza sozlamalari to‘g‘rimi, data/ papkasi yoziladimi?');
    seed_err('');

    exit(1);
}
