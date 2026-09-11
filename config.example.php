<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  Andijon AI Talents — namuna konfiguratsiya / example configuration
 * =============================================================================
 *
 *  UZ:  Bu fayl NAMUNA. Uni to'g'ridan-to'g'ri tahrirlamang!
 *       1) Shu faylning nusxasini `config.php` nomi bilan yarating:
 *              cPanel File Manager: config.example.php -> Copy -> config.php
 *              SSH / terminal:      cp config.example.php config.php
 *       2) `config.php` faylini oching va quyidagi maydonlarni to'ldiring:
 *              telegram.token, telegram.webhook_secret, telegram.admin_ids,
 *              database.*, security.setup_key, security.admin_panel.password_hash
 *       3) Parol xeshini `setup.php` sahifasidagi generator yoki quyidagi buyruq
 *          orqali oling:   php cli.php admin:hash "sizning-parolingiz"
 *       4) `config.php` hech qachon git'ga tushmaydi (.gitignore ichida) va
 *          `.htaccess` orqali brauzerdan ochilmaydi — shunday qolsin.
 *
 *  EN:  This file is a TEMPLATE. Do not edit it directly.
 *       1) Copy it to `config.php` (cPanel: Copy; shell: cp config.example.php config.php).
 *       2) Fill in the token, the database credentials and the admin panel secrets.
 *       3) Generate the panel password hash with `php cli.php admin:hash "<password>"`
 *          or with the generator built into `setup.php`.
 *       4) `config.php` is git-ignored and blocked by `.htaccess`; keep it that way.
 *
 *  Eslatma / Note: bu fayl faqat massiv qaytaradi — hech qanday chiqish (echo,
 *  bo'sh qator, BOM) bo'lmasligi kerak, aks holda Telegram webhook buziladi.
 *  This file must only return an array — any output (echo, blank line, BOM)
 *  breaks the Telegram webhook response.
 *
 *  PHP 8.1+ / MySQL 5.7+ yoki SQLite 3 / HTTPS talab qilinadi.
 * =============================================================================
 */

return [

    /*
     |-------------------------------------------------------------------------
     | TELEGRAM
     |-------------------------------------------------------------------------
     | UZ: Botning Telegram bilan bog'lanish sozlamalari.
     | EN: How the bot talks to the Telegram Bot API.
     */
    'telegram' => [
        // UZ: @BotFather bergan bot tokeni. EN: Bot token issued by @BotFather.
        'token'           => '',        // 123456:ABC...

        // UZ: Bot foydalanuvchi nomi, @ belgisisiz. EN: Bot username, without the leading @.
        'bot_username'    => '',        // without @

        // UZ: Webhook maxfiy so'zi — Telegram uni har so'rovda sarlavhada yuboradi.
        //     BU MAJBURIY: bo'sh bo'lsa bot hech qanday yangilanishni qabul qilmaydi
        //     (aks holda URL'ni topgan har kim o'zini admin qilib ko'rsata oladi).
        //     `setup.php` sahifasi tasodifiy qiymat yaratib beradi, yoki o'zingiz:
        //     php -r "echo bin2hex(random_bytes(24));"
        // EN: Secret compared against the X-Telegram-Bot-Api-Secret-Token header.
        //     REQUIRED: while this is empty the webhook refuses every update, because
        //     anyone who guessed the URL could otherwise forge an admin. setup.php
        //     generates one, or: php -r "echo bin2hex(random_bytes(24));"
        'webhook_secret'  => '',        // X-Telegram-Bot-Api-Secret-Token value

        // UZ: Faqat lokal ishlab chiqish uchun — maxfiy so'zsiz webhook'ga ruxsat beradi.
        //     Ishlab turgan serverda hech qachon true qilmang.
        // EN: Local development escape hatch: accept updates without a secret.
        //     Never set this to true on a live server.
        'allow_insecure_webhook' => false,

        // UZ: Bot administratorlarining Telegram ID raqamlari (@userinfobot beradi).
        // EN: Telegram user ids allowed to use the in-bot admin commands.
        'admin_ids'       => [],        // int[] telegram user ids

        // UZ: Yangi arizalar kartochkasi yuboriladigan guruh/kanal ID (masalan -1001234567890).
        // EN: Group/channel that receives a card for every new registration; null disables it.
        'admin_chat_id'   => null,      // ?int group/channel that receives new-registration cards

        // UZ: Bitta API so'rovi uchun kutish vaqti (soniya). EN: Per-request timeout in seconds.
        'timeout'         => 20,

        // UZ: Bot API manzili — odatda o'zgartirilmaydi (o'z serveringiz bo'lsa o'zgartiring).
        // EN: Bot API base URL; change it only when you run a local Bot API server.
        'api_base'        => 'https://api.telegram.org',
    ],

    /*
     |-------------------------------------------------------------------------
     | DATABASE / MA'LUMOTLAR BAZASI
     |-------------------------------------------------------------------------
     | UZ: `sqlite` — hech narsa sozlamasdan ishlaydi (kichik loyihalar uchun),
     |     `mysql`  — cPanel'da "MySQL Databases" bo'limida baza va foydalanuvchi
     |     yaratib, ularni quyida yozing (cPanel odatda `login_aitalents` kabi
     |     prefiksli nom beradi).
     | EN: Use `sqlite` for a zero-setup install or `mysql` for shared hosting;
     |     cPanel usually prefixes both the database and the user name.
     */
    'database' => [
        // UZ: 'sqlite' yoki 'mysql'. EN: 'sqlite' or 'mysql'.
        'driver'   => 'sqlite',         // 'sqlite' | 'mysql'

        // UZ: MySQL server manzili — cPanel'da deyarli har doim 'localhost'.
        // EN: MySQL host; on cPanel this is almost always 'localhost'.
        'host'     => 'localhost',

        // UZ: MySQL porti. EN: MySQL port.
        'port'     => 3306,

        // UZ: Baza nomi. EN: Database name.
        'database' => 'aitalents',

        // UZ: Baza foydalanuvchisi. EN: Database user.
        'username' => 'root',

        // UZ: Baza paroli. EN: Database password.
        'password' => '',

        // UZ: Kodlash — o'zbek va rus harflari uchun utf8mb4 shart.
        // EN: Connection charset; utf8mb4 is required for Uzbek/Russian text and emoji.
        'charset'  => 'utf8mb4',

        // UZ: Jadval prefiksi (bitta bazani bo'lishayotgan bo'lsangiz, masalan 'ait_').
        // EN: Table name prefix, useful when several apps share one database.
        'prefix'   => '',

        // UZ: SQLite fayli joylashuvi — faqat driver 'sqlite' bo'lganda ishlatiladi.
        //     Papka yozuvga ruxsatli bo'lsin (chmod 755, fayl 644).
        // EN: SQLite file location; only used when driver is 'sqlite'. The data/
        //     directory must be writable by PHP.
        // UZ: XAVFSIZLIK — bu fayl hujjat ildizida turadi va .htaccess ishlamaydigan
        //     hostingda yuklab olinishi mumkin (ichida hamma telefon raqami bor!).
        //     Ikki tavsiya: (1) nomga tasodifiy qism qo'shing, masalan
        //     '/data/aitalents-7f3a91c4.sqlite'; (2) imkon bo'lsa faylni umuman
        //     hujjat ildizidan tashqariga oling:
        //     dirname(__DIR__) . '/aitalents-data/aitalents.sqlite'
        // EN: SECURITY — this file sits inside the document root and is
        //     downloadable on any host that ignores .htaccess, exposing every
        //     applicant's phone number. Either add a random suffix to the name,
        //     or better, move it outside the document root as shown above.
        'path'     => __DIR__ . '/data/aitalents.sqlite',
    ],

    /*
     |-------------------------------------------------------------------------
     | APP / ILOVA
     |-------------------------------------------------------------------------
     | UZ: Ro'yxatdan o'tish jarayoni va umumiy ilova sozlamalari.
     | EN: Registration flow and general application behaviour.
     */
    'app' => [
        // UZ: Loyiha nomi — xabarlarda va admin panel sarlavhasida ko'rinadi.
        // EN: Project name shown in bot messages and in the admin panel header.
        'name'              => 'Andijon AI Talents',

        // UZ: Vaqt mintaqasi — barcha sanalar shu bo'yicha saqlanadi.
        // EN: Timezone used for every stored timestamp.
        'timezone'          => 'Asia/Tashkent',

        // UZ: Standart til. EN: Default locale.
        'default_locale'    => 'uz',

        // UZ: Mavjud tillar (lang/uz.php, lang/ru.php). EN: Available locales.
        'locales'           => ['uz', 'ru'],

        // UZ: true bo'lsa, bot boshida tilni so'raydi. EN: Ask the user for a language on /start.
        'ask_language'      => true,

        // UZ: false bo'lsa, qabul yopiladi va bot "qabul yopiq" xabarini beradi.
        // EN: Set to false to close the intake; the bot then answers with a closed notice.
        'registration_open' => true,

        // UZ: Majburiy obuna kanali, masalan '@andijon_ai'. null — tekshiruv o'chiq.
        //     Bot kanalda administrator bo'lishi shart, aks holda tekshira olmaydi.
        // EN: Forced-subscription channel ('@name'); null disables the gate. The bot must
        //     be an administrator of that channel for the check to work.
        'required_channel'  => null,    // ?string '@channel' — subscription gate, null disables

        // UZ: Foydalanuvchi o'z arizasini keyin tahrirlay olsinmi.
        // EN: Allow a user to edit their own submitted registration.
        'allow_edit'        => true,    // user may edit own registration

        // UZ: Ixtiyoriy qadamlar. F.I.Sh., telefon va yo'nalish har doim so'raladi.
        // EN: Optional steps; full name, phone and direction are always asked.
        'steps'             => [        // optional steps toggles (full_name/phone/direction are always on)
            'district'   => true,       // UZ: tuman/shahar        EN: district step
            'birth_year' => true,       // UZ: tug'ilgan yil       EN: birth year step
            'portfolio'  => true,       // UZ: portfolio/havolalar EN: portfolio step
        ],

        // UZ: Saytning to'liq manzili, oxirida "/" belgisiz. setup.php va webhook
        //     manzilini yig'ishda ishlatiladi. Masalan: https://example.uz/bot
        // EN: Public base URL without a trailing slash; used by setup.php to build
        //     the webhook URL.
        'base_url'          => '',      // https://example.com/bot  (no trailing slash) — used by setup.php
    ],

    /*
     |-------------------------------------------------------------------------
     | SECURITY / XAVFSIZLIK
     |-------------------------------------------------------------------------
     */
    'security' => [
        // UZ: setup.php ni ochish uchun kalit: setup.php?key=... . Bo'sh bo'lsa,
        //     o'rnatuvchi umuman ishlamaydi. O'rnatib bo'lgach, setup.php ni o'chiring.
        // EN: Query key that unlocks setup.php; when empty the installer refuses to run.
        //     Delete setup.php once the installation is finished.
        'setup_key'  => '',             // required query param for setup.php

        // UZ: Spam himoyasi — bitta foydalanuvchi `per_seconds` soniyada `max` tadan
        //     ortiq so'rov yuborsa, ortiqchasi jimgina tashlab yuboriladi.
        // EN: Per-user flood protection; extra updates are dropped silently.
        'rate_limit' => ['enabled' => true, 'max' => 20, 'per_seconds' => 60],

        // UZ: Veb admin panel (/admin) sozlamalari.
        // EN: Web admin panel (/admin) settings.
        'admin_panel' => [
            // UZ: Panelni butunlay o'chirish uchun false. EN: Set false to disable the panel.
            'enabled'          => true,

            // UZ: Panelga kirish logini. EN: Panel login name.
            'username'         => 'admin',

            // UZ: Parol XESHI (ochiq parol emas!). Oling:
            //         php cli.php admin:hash "sizning-parolingiz"
            //     yoki setup.php sahifasidagi generator orqali.
            // EN: password_hash() output, never the plain password. Generate it with
            //     `php cli.php admin:hash "<password>"` or inside setup.php.
            'password_hash'    => '',   // password_hash('...', PASSWORD_DEFAULT)

            // UZ: Sessiya amal qilish muddati, soniyada (7200 = 2 soat).
            // EN: Session lifetime in seconds (7200 = 2 hours).
            'session_lifetime' => 7200,

            // UZ: Noto'g'ri urinishlar soni — undan keyin IP vaqtincha bloklanadi.
            // EN: Failed login attempts allowed per IP before a lockout starts.
            'max_attempts'     => 5,

            // UZ: Bloklash muddati, soniyada (900 = 15 daqiqa).
            // EN: Lockout duration in seconds (900 = 15 minutes).
            'lockout_seconds'  => 900,
        ],
    ],

    /*
     |-------------------------------------------------------------------------
     | LOG / JURNAL
     |-------------------------------------------------------------------------
     | UZ: `level`: debug < info < warning < error. `max_files` — nechta kunlik
     |     jurnal fayli saqlansin (eskilari avtomatik o'chiriladi). `dir` papkasi
     |     yozuvga ruxsatli (chmod 755) bo'lishi kerak.
     | EN: Daily log files kept in `dir`; older files above `max_files` are pruned.
     |     The directory must be writable by PHP.
     */
    'log' => ['enabled' => true, 'level' => 'info', 'dir' => __DIR__ . '/data/logs', 'max_files' => 14],
];
