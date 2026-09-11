<div align="center">

# 🚀 Andijon AI Talents — ro‘yxatdan o‘tish boti

### ✨ _“Bugun iqtidorni kashf et, ertaga kelajakni yarat!”_

**Andijon viloyati hokimligi** tashabbusi bilan yo‘lga qo‘yilgan yoshlar dasturi uchun
Telegram bot va veb boshqaruv paneli.

`PHP 8.1+`  ·  `Composer kerak emas`  ·  `MySQL yoki SQLite`  ·  `MIT litsenziyasi`

</div>

---

## 📖 Loyiha haqida

**Andijon AI Talents** — Andijon viloyatining barcha shahar va tumanlaridan sun’iy intellekt,
IT, dizayn va robototexnika sohalariga qiziqadigan yoshlarni topish dasturi. Ushbu Telegram bot
dasturga ariza topshirishning yagona kanali bo‘lib xizmat qiladi: u foydalanuvchi bilan
o‘zbek yoki rus tilida suhbat quradi va ketma-ket **ism-familiya**, **telefon raqam**,
**tug‘ilgan yil**, **shahar/tuman**, **qiziqqan yo‘nalish(lar)i** va **portfolio (havolalar,
qisqacha tavsif)** ma’lumotlarini yig‘adi, ularni tekshiradi, foydalanuvchiga yakuniy
ko‘rinishni tasdiqlashga beradi va bazaga yozadi. Har bir yangi ariza shu zahoti
administratorlarga «Tasdiqlash / Rad etish» tugmalari bilan yuboriladi, hokimlik operatori esa
arizalarni veb-panelda ko‘rib chiqadi, filtrlaydi, Excel’ga chiqaradi va kerak bo‘lsa
ishtirokchilarga ommaviy xabar yuboradi. Ro‘yxatdan o‘tish 2–3 daqiqa vaqt oladi va
dasturlashni bilish talab qilinmaydi.

---

## 🧩 Imkoniyatlar

### 🤖 Telegram bot

| | Imkoniyat |
|---|---|
| 🌐 | **Ikki til** — o‘zbek (lotin) va rus tili; til istalgan vaqtda `/til` orqali almashtiriladi |
| 🧭 | **Bosqichma-bosqich anketa** (FSM) — har bir savolda `2/6` ko‘rinishidagi progress, «⬅️ Orqaga» va «❌ Bekor qilish» tugmalari |
| 📱 | **Telefon raqam** — «📱 Raqamni yuborish» tugmasi (kontakt) yoki qo‘lda kiritish; `+998901234567` shakliga keltiriladi |
| 🎯 | **Ko‘p tanlovli yo‘nalishlar** — 11 ta yo‘nalish, bir nechtasini birdaniga belgilash mumkin, «Boshqa» varianti erkin matn so‘raydi |
| 🏙 | **Andijon viloyati hududlari** — 3 shahar + 14 tuman + «Boshqa hudud» |
| ✅ | **Tasdiqlash ekrani** — foydalanuvchi yakunlashdan oldin har qanday maydonni tahrirlashi mumkin |
| 👤 | **Shaxsiy kabinet** — `/profil` orqali topshirilgan arizani ko‘rish va (ruxsat berilgan bo‘lsa) yangilash |
| 🔔 | **Adminlarga bildirishnoma** — yangi ariza kartochkasi «✅ Tasdiqlash / ❌ Rad etish» tugmalari bilan admin ID’lariga va admin guruhiga yuboriladi |
| 📢 | **Majburiy obuna** — ixtiyoriy: ro‘yxatdan o‘tishdan oldin loyiha kanaliga obuna tekshiriladi |
| 🛡 | **Flood himoyasi** — foydalanuvchi bo‘yicha so‘rovlar chegarasi (rate limit) |
| 🔒 | **Qabulni yopish** — bir tugma bilan yangi arizalar qabuli to‘xtatiladi |
| 🛠 | **Bot ichidagi admin menyu** — statistika, so‘nggi arizalar, qidiruv, XLSX eksport, ommaviy xabar |

### 🖥 Veb boshqaruv paneli (`/admin/`)

| Sahifa | Nima qiladi |
|---|---|
| 🔑 **Kirish** | Login + parol, CSRF himoyasi, IP bo‘yicha bloklash (5 xato urinishdan keyin 15 daqiqa), har bir urinish audit jurnaliga yoziladi |
| 📊 **Bosh sahifa** | 6 ta ko‘rsatkich kartasi (jami foydalanuvchi, ro‘yxatdan o‘tganlar, bugun, hafta, kutilmoqda, tasdiqlangan), 14 kunlik chiziqli grafik, yo‘nalishlar va hududlar kesimidagi ustunli diagrammalar (barchasi PHP ichida **inline SVG** — hech qanday tashqi kutubxona yo‘q), so‘nggi 10 ta ariza va tezkor qidiruv |
| 🗂 **Arizalar** | Server tomonidagi qidiruv (ism, telefon, username, ID), filtrlar (holat, hudud, yo‘nalish, sana oralig‘i), saralanadigan ustunlar, sahifalash (25/50/100), qatorlar bo‘yicha amallar, checkbox orqali guruh amallari (tasdiqlash / rad etish / o‘chirish) va «Joriy filtrni Excel’ga chiqarish» |
| 📄 **Ariza tafsiloti** | Barcha maydonlar, `tg://user?id=` va `https://t.me/…` havolalari, `tel:` raqami, portfolio havolalari (`rel="noopener noreferrer"`), izoh bilan holat o‘zgartirish formasi, «foydalanuvchiga xabar yuborish» oynasi, o‘chirish (tasdiqlash bilan) va shu ariza bo‘yicha audit tarixi |
| 👥 **Foydalanuvchilar** | Botga yozgan barcha odamlar, filtrlar (bloklangan, ro‘yxatdan o‘tgan, til), bloklash/blokdan chiqarish, bot-admin huquqini berish/olib qo‘yish, chatni ochish |
| 📣 **Xabar yuborish** | HTML ko‘rinish (preview) va belgilar hisoblagichi, auditoriya filtri (hammasi / ro‘yxatdan o‘tganlar / holat / hudud / yo‘nalish) va jonli qabul qiluvchilar soni, «o‘zimga sinov xabari», so‘ngra navbatli **paketli yuborish**: progress bar, pauza/davom ettirish va yakuniy hisobot |
| 📜 **Yuborilgan xabarlar** | Oldingi tarqatishlar tarixi va hisoblagichlari (jami / yetkazildi / xatolik) |
| ⚙️ **Sozlamalar** | Qabulni ochish-yopish, majburiy kanal, tilni so‘rash, salomlashuvga qo‘shimcha matn, bot adminlari (config’dan, faqat o‘qish uchun), webhook ma’lumoti va uni qayta o‘rnatish, `getMe` sog‘liq tekshiruvi, baza drayveri va hajmi, loglarni tozalash |
| 🧾 **Loglar** | Mavjud kunlar bo‘yicha tanlov, daraja bo‘yicha filtr, monospace ko‘rinishdagi tail va yuklab olish |
| 🕵️ **Audit** | Panelda va botda kim nima qilgani: aktyor, amal, obyekt, IP, vaqt — filtr va sahifalash bilan |
| 📥 **Eksport** | Arizalarni **XLSX** (Excel) faylga chiqarish — joriy filtrlar saqlanadi |

Panel dizayni posterning ranglariga moslangan: chuqur ko‘k gradient (`#050b1a → #0a1b3d`) va
elektr-moviy urg‘u (`#22a7ff / #7cf3ff`), 380px kenglikkacha moslashuvchan, `prefers-color-scheme`
orqali yorug‘ rejim, klaviatura bilan ishlash uchun ko‘rinadigan fokus holatlari.
**Barcha CSS va JS lokal** (`admin/assets/app.css`, `admin/assets/app.js`) — CDN ishlatilmaydi,
shuning uchun qat‘iy `Content-Security-Policy` qo‘llash mumkin.

---

## 📋 Talablar

| Nima | Minimum | Izoh |
|---|---|---|
| **PHP** | `8.1` | cPanel → **MultiPHP Manager** orqali tanlanadi. 8.2 / 8.3 / 8.4 da ham ishlaydi |
| **Kengaytmalar (majburiy)** | `pdo`, `mbstring`, `json` | `json` PHP 8 da o‘rnatilgan holda keladi |
| **Kengaytmalar (drayverga qarab)** | `pdo_mysql` **yoki** `pdo_sqlite` | `config.php` dagi `database.driver` ga mos keladigani |
| **Kengaytmalar (tavsiya etiladi)** | `curl`, `zip` | `curl` bo‘lmasa `file_get_contents` zaxira yo‘li ishlatiladi (sekinroq); `zip` bo‘lmasa XLSX o‘zining sof-PHP ZIP yozuvchisi bilan yig‘iladi |
| **Ma’lumotlar bazasi** | MySQL `5.7+` / MariaDB `10.2+` **yoki** SQLite `3` | `utf8mb4` kodlash bilan |
| **Domen** | **HTTPS** (haqiqiy sertifikat) | Telegram webhook’ni faqat HTTPS orqali yuboradi; self-signed sertifikat qabul qilinmaydi |
| **Bot tokeni** | [@BotFather](https://t.me/BotFather) | `/newbot` → token → `config.php` |
| **Composer** | ❌ **kerak emas** | Loyihada tashqi kutubxona yo‘q |

> 💡 `curl` yo‘q hostinglarda `allow_url_fopen = On` bo‘lishi shart, aks holda bot Telegram bilan
> bog‘lana olmaydi.

---

## ⚡ Tezkor start (5 qadam)

```bash
# 1) Fayllarni serverga joylashtiring va loyiha ildiziga o'ting
cd /home/USER/public_html/bot

# 2) Konfiguratsiya nusxasini yarating va to'ldiring
cp config.example.php config.php
#    -> telegram.token, telegram.webhook_secret, telegram.admin_ids
#    -> database.*, app.base_url, security.setup_key

# 3) Bazani yarating (jadvallar avtomatik quriladi)
php cli.php migrate

# 4) Telegram'ni shu o'rnatmaga yo'naltiring
php cli.php webhook:set https://domen.uz/bot/index.php

# 5) Panel paroli uchun xesh oling va uni config.php ga yozing
php cli.php admin:hash 'JudaKuchliParol123!'
```

Tayyor. Telegram’da botni oching va `/start` yuboring, panelga esa
`https://domen.uz/bot/admin/` manzilidan kiring.

> 🖱 **SSH yo‘qmi?** Xuddi shu qadamlarni brauzer orqali `setup.php` bajaradi —
> pastdagi cPanel bo‘limiga qarang.

---

## 🏗 Batafsil o‘rnatish (shared hosting / cPanel)

> Har bir qadam brauzer va cPanel File Manager orqali bajariladi — SSH shart emas.
> To‘liq, ekran-ekran qo‘llanma: [`docs/DEPLOY.uz.md`](docs/DEPLOY.uz.md).

### 1️⃣ Fayllarni yuklash

1. cPanel → **File Manager** → `public_html` papkasini oching.
2. Bot uchun alohida papka yarating, masalan `bot` (yoki subdomen uchun `bot.domen.uz`).
3. Loyiha arxivini (`.zip`) shu papkaga yuklang → o‘ng tugma → **Extract**.
4. Arxiv ichida yana bitta papka paydo bo‘lsa, fayllarni bir daraja yuqoriga ko‘chiring:
   `index.php`, `bootstrap.php`, `config.example.php`, `admin/`, `src/`, `lang/` — hammasi
   **bitta** papkada, yonma-yon turishi kerak.

### 2️⃣ MySQL bazasi va foydalanuvchisini yaratish

1. cPanel → **MySQL® Databases**.
2. **Create New Database**: `aitalents` → cPanel unga prefiks qo‘shadi, masalan `login_aitalents`.
3. **Add New User**: foydalanuvchi va **kuchli** parol (parolni saqlab qo‘ying).
4. **Add User To Database** → **ALL PRIVILEGES** → **Make Changes**.
5. Uchta qiymatni yozib oling: **baza nomi**, **foydalanuvchi**, **parol** (ikkalasi ham prefiksli).

> 💾 MySQL o‘rniga **SQLite** ishlatmoqchimisiz? Unda hech narsa yaratish shart emas:
> `database.driver` ni `'sqlite'` qoldiring, `data/` papkasi yozuvga ruxsatli bo‘lsa (775) yetadi.

### 3️⃣ `config.php` faylini tayyorlash

File Manager’da `config.example.php` → o‘ng tugma → **Copy** → nomi `config.php`.
So‘ng `config.php` ni **Edit** qilib quyidagilarni to‘ldiring:

```php
'telegram' => [
    'token'          => '7123456789:AAH...',        // @BotFather bergan token
    'bot_username'   => 'andijon_ai_talents_bot',   // @ belgisisiz
    'webhook_secret' => 'tasodifiy-32-belgili-satr',
    'admin_ids'      => [123456789],                // @userinfobot beradi
    'admin_chat_id'  => -1001234567890,             // guruh bo'lmasa null
],
'database' => [
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'database' => 'login_aitalents',
    'username' => 'login_aiuser',
    'password' => 'BAZA_PAROLI',
],
'app' => [
    'base_url' => 'https://domen.uz/bot',           // oxirida "/" YO'Q
],
'security' => [
    'setup_key' => 'yana-bitta-tasodifiy-kalit',
],
```

> ⚠️ Faylni saqlashda **UTF-8 (BOM’siz)** kodlashiga e’tibor bering va `?>` dan keyin
> hech qanday bo‘sh qator qolmasin — aks holda webhook javobiga ortiqcha belgi qo‘shilib ketadi.

### 4️⃣ O‘rnatuvchini ochish

Brauzerda oching:

```
https://domen.uz/bot/setup.php?key=SIZNING_SETUP_KALITINGIZ
```

`setup.php` sakkizta bo‘limdan iborat va har birida ✅/❌ ko‘rsatkich bor:

| № | Bo‘lim | Nima qiladi |
|---|---|---|
| 1 | PHP va kengaytmalar | Versiya va kerakli kengaytmalarni tekshiradi |
| 2 | Papka ruxsatlari | `data/`, `data/logs/`, `data/exports/` yozuvga ochiqmi |
| 3 | Konfiguratsiya | `config.php` to‘g‘ri to‘ldirilganmi (token, baza, kalitlar) |
| 4 | Ma’lumotlar bazasi | Ulanishni sinaydi va **migratsiyalarni ishga tushirish** tugmasini beradi |
| 5 | Webhook | Manzilni o‘rnatadi / o‘chiradi / joriy holatini ko‘rsatadi |
| 6 | Bot bilan aloqa | `getMe` — token haqiqiy ekanini tasdiqlaydi |
| 7 | Parol xeshi | Panel paroli uchun `password_hash` generatori |
| 8 | Keyingi qadamlar | Yakuniy ko‘rsatmalar |

**Tartib:** 4-bo‘limdagi **«Migratsiyalarni ishga tushirish»** → 5-bo‘limdagi
**«Webhook o‘rnatish»** → 7-bo‘limdagi generator bilan parol xeshini oling.

### 5️⃣ Panel parolini o‘rnatish

7-bo‘lim bergan uzun satrni (`$2y$…` bilan boshlanadi) `config.php` ga ko‘chiring:

```php
'admin_panel' => [
    'enabled'       => true,
    'username'      => 'admin',
    'password_hash' => '$2y$10$xxxxxxxxxxxxxxxxxxxxxx...',
],
```

SSH bo‘lsa xuddi shu narsa: `php cli.php admin:hash 'ParolingizBuYerda'`.

### 6️⃣ Panelga kirish va tekshirish

`https://domen.uz/bot/admin/` → login + parol → **Bosh sahifa** ochilishi kerak.
Telegram’da botga `/start` yuboring va bir marta test arizasini to‘ldiring —
u panelning **Arizalar** bo‘limida darhol ko‘rinadi.

### 7️⃣ 🔥 `setup.php` ni O‘CHIRING

```
File Manager → setup.php → Delete
```

Bu **majburiy** qadam. `setup.php` kalitsiz hech narsa qilmaydi, lekin uni serverda
qoldirishning hech qanday sababi yo‘q.

---

## 💻 Lokal ishlab chiqish

Webhook uchun HTTPS kerak, shuning uchun kompyuterda bot **long polling** rejimida ishlatiladi:

```bash
# 1) SQLite bilan eng oson variant: config.php da driver 'sqlite' qoldiriladi
cp config.example.php config.php

# 2) Jadvallarni yarating
php cli.php migrate

# 3) Demo ma'lumot (ixtiyoriy — panel grafiklari bo'sh qolmasligi uchun)
php tools/seed.php --count=200 --days=60

# 4) Botni tinglash rejimida ishga tushiring (Ctrl+C bilan to'xtatiladi)
php cli.php poll
#    Webhook o'rnatilgan bo'lsa 409 Conflict chiqadi — uni avval o'chiring:
php cli.php poll --drop-webhook

# 5) Admin panelni PHP'ning ichki serveri bilan oching
php -S localhost:8000
#    -> http://localhost:8000/admin/
```

Testlar va sintaksis tekshiruvi:

```bash
php tests/run.php                                   # bog'liqliksiz test harness
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

> ℹ️ `php -S` bilan ishlaganda `.htaccess` **o‘qilmaydi** — ya’ni `src/`, `lang/`, `data/`
> papkalari ochiq qoladi. Bu faqat lokal muhit uchun qabul qilinadi; ishlab turgan serverda
> Apache/LiteSpeed himoyani qo‘llaydi.

---

## 💬 Bot buyruqlari

### 👤 Foydalanuvchilar uchun

| Buyruq | Sinonim | Vazifasi |
|---|---|---|
| `/start` | — | Botni ishga tushirish, salomlashuv va bosh menyu (deep-link: `/start ref_xyz`) |
| `/royxat` | `/register` | Ro‘yxatdan o‘tishni boshlash yoki ma’lumotlarni yangilash |
| `/profil` | `/profile` | Topshirilgan arizani ko‘rish va tahrirlash |
| `/til` | `/language` | Muloqot tilini o‘zbek ↔ rus qilib almashtirish |
| `/loyiha` | `/about` | Dastur haqida, bosqichlar va yo‘nalishlar |
| `/yordam` | `/help` | Yordam matni va buyruqlar ro‘yxati |
| `/bekor` | `/cancel` | Joriy amalni (anketa, qidiruv, xabar yozish) bekor qilish |

### 🛠 Administratorlar uchun

> Faqat `telegram.admin_ids` ro‘yxatidagi yoki `users.is_admin = 1` bo‘lgan foydalanuvchilarda ishlaydi.

| Buyruq | Vazifasi |
|---|---|
| `/admin` | Admin menyusi: statistika, so‘nggi arizalar, qidiruv, eksport, xabar yuborish, qabulni ochish/yopish |
| `/stats` | Qisqa statistika: foydalanuvchilar, arizalar, bugun/hafta/oy, holatlar kesimi |
| `/export` | Barcha arizalarni **XLSX** fayl sifatida chatga yuborish |
| `/broadcast` | Ommaviy xabar tayyorlash va yuborish |
| `/panel` | Veb-panel manzilini chiqarish (`app.base_url` sozlangan bo‘lsa) |

### ⌨️ Konsol buyruqlari (`php cli.php <buyruq>`)

| Buyruq | Vazifasi | Muhim opsiyalar |
|---|---|---|
| `migrate` | Jadvallarni yaratish/yangilash (qayta ishga tushirish xavfsiz) | — |
| `webhook:set [url]` | Telegram’ni shu o‘rnatmaga yo‘naltirish (url bo‘lmasa `app.base_url` dan yig‘iladi) | `--drop-pending` |
| `webhook:delete` | Webhook’ni o‘chirish | `--drop-pending` |
| `webhook:info` | Telegram bilgan webhook holati va oxirgi xato | — |
| `poll` | Long polling (faqat lokal ishlab chiqish uchun) | `--timeout=25 --limit=100 --once --drop-webhook` |
| `broadcast:run [id]` | Navbatdagi xabar paketlarini yuborish (**cron kirish nuqtasi**) | `--batch=20 --max-batches=0` |
| `export [path]` | Arizalarni `.xlsx` faylga yozish | `--status= --district= --direction= --from= --to= --locale=` |
| `admin:hash <parol>` | Panel paroli uchun `password_hash` | `--stdin` |
| `stats` | Statistikani terminalga chiqarish | — |
| `cleanup` | Eski loglar, audit yozuvlari va rate-limit oynalarini tozalash | `--days=90 --keep-logs` |
| `help` | Buyruqlar ro‘yxati | — |
| _global_ | Ranglarni o‘chirish | `--no-color` (yoki `NO_COLOR` muhit o‘zgaruvchisi) |

---

## ⏱ Cron sozlash

Ommaviy xabarlar **navbat** orqali yuboriladi: panel yoki bot faqat navbatni to‘ldiradi, uni
paketlab yuborish ishini `broadcast:run` bajaradi. Shuning uchun **kamida bitta cron shart**.

cPanel → **Cron Jobs** → *Add New Cron Job*:

```cron
# 📣 Har daqiqada navbatdagi xabarlarni yuborish (majburiy)
* * * * * cd /home/USER/public_html/bot && /usr/local/bin/php cli.php broadcast:run --batch=25 --no-color >/dev/null 2>&1

# 🧹 Har kuni 03:15 da eski loglar, audit yozuvlari va rate-limit oynalarini tozalash
15 3 * * * cd /home/USER/public_html/bot && /usr/local/bin/php cli.php cleanup --days=90 --no-color >/dev/null 2>&1

# 📊 (ixtiyoriy) Har dushanba 09:00 da haftalik statistikani log fayliga yozish
0 9 * * 1 cd /home/USER/public_html/bot && /usr/local/bin/php cli.php stats --no-color >> data/logs/stats.log 2>&1
```

| Nima | Qanday aniqlanadi |
|---|---|
| `/home/USER/public_html/bot` | cPanel → File Manager → papka ustida **“Current Path”** yozuvi |
| `/usr/local/bin/php` | cPanel → **Terminal**: `which php`. Ba’zi hostinglarda `/usr/local/bin/ea-php81` yoki `/opt/cpanel/ea-php81/root/usr/bin/php` |

> ⚠️ **Faqat `php` deb yozmang.** Cron muhitida `PATH` bo‘sh bo‘lishi mumkin, PHP versiyasi ham
> mos kelmasligi mumkin — har doim **to‘liq yo‘l** ko‘rsating.
>
> ⚠️ Cron **`cli.php` ga** murojaat qiladi, `index.php` ga emas. `cli.php` HTTP orqali
> chaqirilsa, ishlashdan bosh tortadi.
>
> 💡 Cron ishlayotganini tekshirish: `tail -f data/logs/bot-$(date +%F).log` yoki
> panel → **Loglar**.

---

## ⚙️ `config.php` sozlamalari

### `telegram`

| Kalit | Turi | Standart | Ma’nosi |
|---|---|---|---|
| `token` | `string` | `''` | @BotFather bergan bot tokeni. **Majburiy.** |
| `bot_username` | `string` | `''` | Bot username’i, `@` belgisisiz — havolalar yig‘ishda ishlatiladi |
| `webhook_secret` | `string` | `''` | `X-Telegram-Bot-Api-Secret-Token` sarlavhasi bilan solishtiriladi. Bo‘sh bo‘lsa tekshiruv o‘chadi — **ishlab turgan serverda bo‘sh qoldirmang** |
| `admin_ids` | `int[]` | `[]` | Bot ichidagi admin buyruqlariga ruxsati bor Telegram ID’lar |
| `admin_chat_id` | `?int` | `null` | Yangi ariza kartochkalari yuboriladigan guruh/kanal (masalan `-1001234567890`) |
| `timeout` | `int` | `20` | Bitta API so‘rovi uchun kutish vaqti (soniya) |
| `api_base` | `string` | `https://api.telegram.org` | Bot API manzili; o‘z Bot API serveringiz bo‘lsagina o‘zgartiring |

### `database`

| Kalit | Turi | Standart | Ma’nosi |
|---|---|---|---|
| `driver` | `string` | `sqlite` | `sqlite` yoki `mysql` |
| `host` | `string` | `localhost` | MySQL server (cPanel’da deyarli har doim `localhost`) |
| `port` | `int` | `3306` | MySQL porti |
| `database` | `string` | `aitalents` | Baza nomi (cPanel prefiksi bilan) |
| `username` | `string` | `root` | Baza foydalanuvchisi |
| `password` | `string` | `''` | Baza paroli |
| `charset` | `string` | `utf8mb4` | Ulanish kodlashi — o‘zbek/rus matni va emoji uchun `utf8mb4` shart |
| `prefix` | `string` | `''` | Jadval prefiksi (bitta bazani bir nechta ilova bo‘lishsa, masalan `ait_`) |
| `path` | `string` | `data/aitalents.sqlite` | SQLite fayli joylashuvi (faqat `driver = sqlite` da) |

### `app`

| Kalit | Turi | Standart | Ma’nosi |
|---|---|---|---|
| `name` | `string` | `Andijon AI Talents` | Xabarlarda va panel sarlavhasida ko‘rinadigan nom |
| `timezone` | `string` | `Asia/Tashkent` | Barcha sanalar shu mintaqada saqlanadi |
| `default_locale` | `string` | `uz` | Standart til |
| `locales` | `string[]` | `['uz','ru']` | Mavjud tillar (`lang/uz.php`, `lang/ru.php`) |
| `ask_language` | `bool` | `true` | `/start` da tilni so‘rash |
| `registration_open` | `bool` | `true` | `false` — yangi arizalar qabul qilinmaydi (paneldan ham boshqariladi) |
| `required_channel` | `?string` | `null` | Majburiy obuna kanali, masalan `@andijon_ai`. Bot o‘sha kanalda **admin** bo‘lishi shart |
| `allow_edit` | `bool` | `true` | Foydalanuvchi topshirgan arizasini keyin tahrirlay oladimi |
| `steps.district` | `bool` | `true` | «Shahar/tuman» qadamini so‘rash |
| `steps.birth_year` | `bool` | `true` | «Tug‘ilgan yil» qadamini so‘rash |
| `steps.portfolio` | `bool` | `true` | «Portfolio» qadamini so‘rash |
| `base_url` | `string` | `''` | Saytning to‘liq manzili, oxirida `/` **belgisiz**. `setup.php`, `/panel` va webhook manzilini yig‘ishda ishlatiladi |

> ℹ️ `full_name`, `phone` va `direction` qadamlari **har doim** so‘raladi va o‘chirilmaydi.

### `security`

| Kalit | Turi | Standart | Ma’nosi |
|---|---|---|---|
| `setup_key` | `string` | `''` | `setup.php?key=…` uchun kalit. Bo‘sh bo‘lsa o‘rnatuvchi umuman ishlamaydi |
| `rate_limit.enabled` | `bool` | `true` | Flood himoyasi yoqilganmi |
| `rate_limit.max` | `int` | `20` | Oynada ruxsat etilgan so‘rovlar soni |
| `rate_limit.per_seconds` | `int` | `60` | Oyna uzunligi (soniya) |
| `admin_panel.enabled` | `bool` | `true` | `false` — veb-panel butunlay o‘chadi |
| `admin_panel.username` | `string` | `admin` | Panel logini |
| `admin_panel.password_hash` | `string` | `''` | `password_hash()` natijasi. **Hech qachon ochiq parol emas** |
| `admin_panel.session_lifetime` | `int` | `7200` | Sessiya muddati (soniya) |
| `admin_panel.max_attempts` | `int` | `5` | IP bo‘yicha ruxsat etilgan xato urinishlar |
| `admin_panel.lockout_seconds` | `int` | `900` | Bloklash muddati (soniya) |

### `log`

| Kalit | Turi | Standart | Ma’nosi |
|---|---|---|---|
| `enabled` | `bool` | `true` | Jurnal yozuvi yoqilganmi |
| `level` | `string` | `info` | `debug` < `info` < `warning` < `error` |
| `dir` | `string` | `data/logs` | Kunlik fayllar papkasi (`bot-YYYY-MM-DD.log`) |
| `max_files` | `int` | `14` | Nechta kunlik fayl saqlansin (ortiqchasi `cleanup` da o‘chadi) |

---

## 🗂 Loyiha tuzilmasi

```
Aitalentsbot/
├── index.php                     # Telegram webhook kirish nuqtasi (Telegram faqat shu faylga murojaat qiladi)
├── setup.php                     # Brauzerdagi o'rnatuvchi — o'rnatib bo'lgach O'CHIRILADI
├── cli.php                       # Konsol vositasi: migrate, webhook, poll, export, cron
├── bootstrap.php                 # Autoloader, config yuklash, timezone, App singleton
├── config.example.php            # Konfiguratsiya namunasi (config.php shundan nusxalanadi)
├── .htaccess                     # Apache/LiteSpeed himoyasi va xavfsizlik sarlavhalari
│
├── src/                          # Ilova kodi — namespace AiTalents\ (veb orqali yopiq)
│   ├── App.php                   #   Servis konteyner / singleton
│   ├── Config.php                #   Nuqtali kalitlar bilan konfiguratsiya (app.timezone)
│   ├── Database.php              #   PDO qatlami: query/fetch/insert/update/transaction
│   ├── Migrator.php              #   Jadval sxemasi (MySQL + SQLite DDL)
│   ├── Logger.php                #   Kunlik fayl jurnali
│   ├── Lang.php                  #   Tarjimalar: Lang::t('kalit', 'uz', [...])
│   ├── Text.php                  #   HTML escape, qisqartirish, telefon formatlash
│   ├── Validator.php             #   Ism, telefon, tug'ilgan yil, portfolio tekshiruvi
│   ├── RateLimiter.php           #   Foydalanuvchi bo'yicha flood himoyasi
│   ├── Router.php                #   Update -> handler yo'naltiruvchi
│   ├── Enum/
│   │   ├── Step.php              #     Anketa qadamlari (FSM)
│   │   └── RegistrationStatus.php#     pending / approved / rejected
│   ├── Registration/
│   │   ├── Catalog.php           #     Yo'nalishlar va Andijon viloyati hududlari
│   │   └── Flow.php              #     Ro'yxatdan o'tish jarayoni (FSM yadrosi)
│   ├── Telegram/
│   │   ├── Api.php               #     Bot API klienti
│   │   ├── ApiException.php      #     Telegram xatolari (retry_after, blocked)
│   │   ├── Transport.php         #     Interfeys
│   │   ├── CurlTransport.php     #     cURL (yoki file_get_contents zaxirasi)
│   │   ├── FakeTransport.php     #     Testlar uchun soxta transport
│   │   ├── Keyboard.php          #     Inline va reply klaviaturalar
│   │   └── Update.php            #     Kelgan update'ni o'qish
│   ├── Repository/
│   │   ├── UserRepository.php    #     Foydalanuvchilar va ularning holati
│   │   ├── RegistrationRepository.php  # Arizalar: saqlash, filtr, sahifalash
│   │   ├── SettingRepository.php #     Bazadagi sozlamalar
│   │   ├── BroadcastRepository.php     # Xabar tarqatish navbati
│   │   └── AuditRepository.php   #     Audit jurnali
│   ├── Service/
│   │   ├── StatsService.php      #     Statistika va grafiklar uchun ma'lumot
│   │   └── BroadcastService.php  #     Paketli yuborish mantiqi
│   ├── Export/
│   │   ├── XlsxWriter.php        #     Mustaqil OOXML (.xlsx) yozuvchi
│   │   └── XlsxExporter.php      #     Arizalarni ustunlarga joylash
│   ├── Handler/
│   │   ├── CommandHandler.php    #     /start, /royxat, /profil, ...
│   │   ├── AdminBotHandler.php   #     Bot ichidagi admin menyusi
│   │   └── AdminNotifier.php     #     Yangi ariza kartochkasi va tugmalari
│   └── Admin/
│       ├── Auth.php              #     Panel sessiyasi va bloklash
│       ├── Csrf.php              #     CSRF tokeni
│       ├── Request.php           #     GET/POST/IP/redirect yordamchilari
│       ├── View.php              #     Shablon renderi
│       └── Controller/           #     Auth, Dashboard, Registration, User,
│                                 #     Broadcast, Settings, Log, Export
│
├── admin/                        # Veb boshqaruv paneli
│   ├── index.php                 #   Front controller: ?p=<sahifa>&a=<amal>
│   ├── bootstrap.php             #   Panel yordamchilari: e(), t(), url(), asset()
│   ├── assets/
│   │   ├── app.css               #     Butun dizayn (lokal, CDN yo'q)
│   │   └── app.js                #     Vanilla JS: menyu, bulk-select, progress
│   └── views/
│       ├── layout.php  login.php  dashboard.php  registrations.php
│       ├── registration_view.php  users.php  broadcast.php  broadcasts.php
│       ├── settings.php  logs.php  audit.php  error.php
│       └── partials/             #     nav, topbar, filters, flash, pagination,
│                                 #     chart_line, chart_bar (inline SVG)
│
├── lang/
│   ├── uz.php                    # O'zbekcha matnlar (asosiy)
│   └── ru.php                    # Ruscha matnlar (kalitlar to'liq mos bo'lishi shart)
│
├── data/                         # Ish vaqtidagi fayllar (veb orqali yopiq)
│   ├── logs/                     #   bot-YYYY-MM-DD.log
│   ├── exports/                  #   Yaratilgan .xlsx fayllar
│   └── aitalents.sqlite          #   SQLite bazasi (faqat sqlite drayverda)
│
├── docs/
│   ├── DEPLOY.uz.md              # cPanel'ga o'rnatishning to'liq qo'llanmasi
│   ├── ADMIN.uz.md               # Operator uchun panel qo'llanmasi
│   ├── BOTFATHER.uz.md           # Botni yaratish va sozlash
│   ├── FAQ.uz.md                 # Savol-javoblar va muammolar yechimi
│   └── CONTRIBUTING.uz.md        # Kodga hissa qo'shish qoidalari
│
├── tests/
│   └── run.php                   # Bog'liqliksiz test harness (php tests/run.php)
│
└── tools/
    ├── seed.php                  # Demo ma'lumot generatori (FAQAT development uchun!)
    └── README.md
```

---

## 🩺 Muammolarni bartaraf etish

<details>
<summary><b>❌ Webhook 401 qaytaryapti (<code>Wrong response from the webhook: 401 Unauthorized</code>)</b></summary>

Telegram yuborgan `X-Telegram-Bot-Api-Secret-Token` sarlavhasi `config.php` dagi
`telegram.webhook_secret` bilan mos kelmayapti. Odatda sekret o‘zgartirilgan, lekin webhook
qayta o‘rnatilmagan.

```bash
php cli.php webhook:set https://domen.uz/bot/index.php
php cli.php webhook:info      # "last_error_message" bo'sh bo'lishi kerak
```

Yoki `setup.php` → 5-bo‘lim → **Webhook o‘rnatish**. Sekret har doim webhook bilan birga
yangilanadi, shuning uchun ikkalasini alohida o‘zgartirmang.
</details>

<details>
<summary><b>⚔️ 409 Conflict — <code>terminated by other getUpdates request</code></b></summary>

Bitta bot bir vaqtda **yo webhook**, **yo long polling** ishlatishi mumkin — ikkalasi birga
ishlamaydi.

- Serverda webhook turgan bo‘lsa, lokalda `poll` ishlamaydi:
  ```bash
  php cli.php poll --drop-webhook     # webhook'ni o'chirib, polling'ni boshlaydi
  ```
- Lokal ish tugagach, serverda webhook’ni **albatta qayta tiklang**:
  ```bash
  php cli.php webhook:set https://domen.uz/bot/index.php
  ```
- Bir xil token bilan ikkita nusxa (test va production) ishlayotgan bo‘lsa — test uchun
  @BotFather’dan **alohida bot** yarating.
</details>

<details>
<summary><b>🔐 “SSL error”, <code>SSL_ERROR_SYSCALL</code>, <code>certificate verify failed</code></b></summary>

Telegram webhook’ni faqat **haqiqiy, to‘liq zanjirli** HTTPS sertifikati bilan yuboradi.

1. cPanel → **SSL/TLS Status** → domenni belgilab **Run AutoSSL**.
2. Sertifikat chiqqach tekshiring:
   ```bash
   curl -I https://domen.uz/bot/index.php
   # HTTP/2 200  bo'lishi kerak, sertifikat ogohlantirishisiz
   ```
3. Self-signed sertifikat, `Let's Encrypt` zanjirining yetishmasligi yoki muddati o‘tgan
   sertifikat — hammasi shu xatoni beradi.
4. Sayt Cloudflare ortida bo‘lsa, SSL rejimi **Full (strict)** bo‘lsin.
5. Chiquvchi so‘rovlarda xato bo‘lsa (bot Telegram’ga chiqa olmasa), serverdagi CA to‘plami
   eskirgan bo‘lishi mumkin — hosting texnik yordamiga murojaat qiling.
</details>

<details>
<summary><b>💥 Webhook 500 yoki 502 qaytaryapti</b></summary>

`php cli.php webhook:info` → `last_error_message` ni o‘qing, so‘ng:

| Sabab | Yechim |
|---|---|
| `config.php` da sintaksis xatosi | `php -l config.php` |
| PHP versiyasi 8.1 dan past | cPanel → MultiPHP Manager → 8.1+ |
| `data/` yozuvga yopiq | `chmod 775 data data/logs data/exports` |
| Baza ulanmayapti | `config.php` dagi `database.*` ni tekshiring, `php cli.php migrate` |
| `config.php` da BOM yoki ortiqcha bo‘sh qator | Faylni UTF-8 (BOM’siz) qilib qayta saqlang |
| 502 — PHP-FPM jarayoni o‘lgan | `data/logs/bot-*.log` va cPanel → **Errors** ni ko‘ring |

Batafsil xato matni **hech qachon** Telegram’ga qaytarilmaydi (u `getWebhookInfo` orqali
oshkor bo‘lib qolardi) — u faqat `data/logs/bot-YYYY-MM-DD.log` fayliga yoziladi.
</details>

<details>
<summary><b>📁 <code>data/</code> papkasi ruxsatlari (<code>Permission denied</code>, log yozilmayapti, SQLite “readonly database”)</b></summary>

```bash
chmod 755 .                       # loyiha ildizi
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

chmod 775 data data/logs data/exports        # yozuvga ruxsat kerak bo'lgan papkalar
chmod 664 data/aitalents.sqlite              # SQLite ishlatilsa
```

Ba’zi hostinglarda `775` o‘rniga `777` talab qilinadi, lekin buni **oxirgi chora** sifatida
va faqat `data/` uchun qiling. `data/` papkasi `.htaccess` bilan brauzerdan yopilgan.

cPanel’da: File Manager → papka → **Permissions** → `0775`.
</details>

<details>
<summary><b>🚫 “config.php not found” / “Andijon AI Talents is not configured yet”</b></summary>

Sabab: `config.php` yaratilmagan yoki noto‘g‘ri joyda.

1. `config.php` **`index.php` bilan bitta papkada** turishi kerak.
2. Nomiga e’tibor bering: `config.php`, `config.php.txt` emas
   (File Manager kengaytmani yashirgan bo‘lishi mumkin — **Settings → Show hidden files**).
3. Fayl massiv qaytarishi shart:
   ```php
   <?php
   declare(strict_types=1);
   return [ /* ... */ ];
   ```
4. Tekshiring: `php -l config.php` va `php cli.php stats`.
</details>

<details>
<summary><b>🔑 Panelga kira olmayapman</b></summary>

| Belgisi | Sabab va yechim |
|---|---|
| “Login yoki parol noto‘g‘ri” | `security.admin_panel.password_hash` bo‘sh yoki noto‘g‘ri. Yangi xesh oling: `php cli.php admin:hash 'YangiParol'` va **butun satrni** (`$2y$…`) ko‘chiring |
| “Juda ko‘p urinish. N daqiqadan so‘ng…” | IP bloklangan. Kuting yoki bazadagi `login_attempts` jadvalini tozalang |
| “Admin panel o‘chirilgan” | `security.admin_panel.enabled` ni `true` qiling |
| “Sessiya muddati tugadi” | Normal holat — qaytadan kiring. Muddatni `session_lifetime` bilan uzaytiring |
| “Xavfsizlik tokeni yaroqsiz” | Sahifa uzoq ochiq turgan yoki cookie bloklangan. `F5` bosing; brauzerda cookie yoqilganiga ishonch hosil qiling |
| Kirgandan keyin darhol qaytadan login so‘raydi | Sessiya papkasi yozuvga yopiq yoki HTTPS/HTTP aralashib ketgan. Panelni **faqat HTTPS** orqali oching |

⚠️ Parol xeshida `$` belgilari bor — uni **bir tirnoq** ichida yozing (`'$2y$10$...'`),
qo‘sh tirnoqda PHP uni o‘zgaruvchi deb o‘qishga urinadi.
</details>

<details>
<summary><b>📊 Excel faylni ochganda harflar buzilib ko‘rinyapti</b></summary>

Loyiha eksporti **CSV emas, XLSX (Excel 2007+)** formatida — ya’ni kodlash muammosi
bo‘lmasligi kerak, chunki `.xlsx` ichida matn har doim UTF-8 saqlanadi. Shunga qaramay
muammo ko‘rinsa:

| Belgisi | Sabab | Yechim |
|---|---|---|
| Harflar o‘rniga `Ð, Ñ, â€™` | Fayl **CSV** sifatida qayta saqlangan | XLSX faylning o‘zini oching; CSV kerak bo‘lsa, Excel’da: **Data → From Text/CSV → File origin: UTF-8** |
| Excel “format mos emas” deydi | Yuklab olish yarim uzilib qolgan (proxy/antivirus) | Faylni qayta yuklab oling; hajmini tekshiring |
| Telefon raqami `9,98901E+11` ko‘rinishida | Raqam ustun sifatida o‘qilgan | Bunday bo‘lmasligi kerak — eksporter telefonlarni **matn** sifatida yozadi. Agar CSV’ga o‘tkazgan bo‘lsangiz, import paytida ustunni **Text** deb belgilang |
| Fayl umuman ochilmayapti | Serverda `zip` kengaytmasi yo‘q va sof-PHP yo‘l ishlagan | Normal holat, fayl baribir yaroqli. Muammo davom etsa, `zip` kengaytmasini yoqishni so‘rang |

LibreOffice va Google Sheets XLSX faylni hech qanday sozlamasiz to‘g‘ri ochadi.
</details>

<details>
<summary><b>🗄 MySQL: <code>#1071 - Specified key was too long; max key length is 767 bytes</code></b></summary>

Eski MySQL (5.5/5.6) yoki `utf8mb4` + `InnoDB` da `innodb_large_prefix` o‘chirilgan serverlarda
uchraydi: `utf8mb4` da har bir belgi 4 baytgacha joy egallaydi, shuning uchun `VARCHAR(255)`
ustunidagi indeks 767 bayt chegarasidan oshib ketadi.

Yechimlar (tartib bo‘yicha):

1. **MySQL 5.7+ / MariaDB 10.2+ ga o‘ting** — cPanel → **Select MySQL Version**. Bu eng to‘g‘ri yo‘l.
2. Hosting texnik yordamidan quyidagilarni so‘rang:
   ```ini
   innodb_large_prefix = ON
   innodb_file_format  = Barracuda
   innodb_file_per_table = ON
   ```
3. Yoki **SQLite** ga o‘ting — bu chegara unda umuman yo‘q:
   ```php
   'database' => ['driver' => 'sqlite', 'path' => __DIR__ . '/data/aitalents.sqlite'],
   ```
   So‘ng `php cli.php migrate`.

> Sxema `utf8mb4` uchun mo‘ljallab yozilgan (indekslanadigan ustunlar qisqa), shuning uchun
> bu xato faqat juda eski serverlarda uchraydi.
</details>

<details>
<summary><b>🤫 Bot javob bermayapti (xato ham yo‘q)</b></summary>

Tartib bilan tekshiring:

```bash
php cli.php webhook:info      # url to'g'rimi, pending_update_count o'smoqdami
php cli.php stats             # baza ulanyaptimi
tail -50 data/logs/bot-$(date +%F).log
```

- `url` bo‘sh bo‘lsa → `php cli.php webhook:set …`
- `pending_update_count` o‘sib borsa → `index.php` xato qaytaryapti (yuqoridagi 500/502 bo‘limi)
- Loglar bo‘sh bo‘lsa → so‘rov umuman yetib kelmayapti: domen, `.htaccess` va papka nomini tekshiring
- Faqat bitta foydalanuvchiga javob bermasa → u panelda **bloklangan** bo‘lishi mumkin
- «Ro‘yxatdan o‘tish yopiq» chiqsa → panel → **Sozlamalar** → qabulni oching
</details>

> 📚 Yana ko‘proq savol-javob: [`docs/FAQ.uz.md`](docs/FAQ.uz.md).

---

## 🛡 Xavfsizlik ro‘yxati

- [ ] `setup.php` **o‘chirilgan** (o‘rnatish tugagach darhol).
- [ ] `security.setup_key` — tasodifiy 32+ belgi; o‘rnatishdan keyin yana bir bor almashtirilgan.
- [ ] `telegram.webhook_secret` to‘ldirilgan va webhook shu sekret bilan qayta o‘rnatilgan.
- [ ] `security.admin_panel.password_hash` haqiqiy `password_hash()` natijasi; parol
      kamida 12 belgi, hech qayerda oddiy matnda saqlanmagan.
- [ ] `config.php` git’ga tushmagan (`.gitignore` da) va `.htaccess` bilan yopilgan.
- [ ] Butun sayt **HTTPS** da; `.htaccess` dagi HTTPS yo‘naltirish bloki (yoki panel sozlamasi) yoqilgan.
- [ ] `data/`, `src/`, `lang/`, `tests/`, `docs/`, `tools/` papkalari brauzerdan **403** qaytaryapti.
      Tekshirish: `https://domen.uz/bot/data/` va `https://domen.uz/bot/src/App.php` ni oching.
- [ ] `https://domen.uz/bot/config.php` — **403**, bo‘sh sahifa emas.
- [ ] Panel `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`
      va qat’iy `Content-Security-Policy` sarlavhalarini yuboryapti.
- [ ] `telegram.admin_ids` da faqat haqiqiy mas’ul xodimlar ID’lari bor.
- [ ] Baza foydalanuvchisi **faqat o‘z bazasiga** huquqli (`root` emas).
- [ ] Bazadan muntazam **zaxira nusxa** olinadi (cPanel → Backup yoki `mysqldump` cron).
- [ ] `data/exports/` ichidagi eski XLSX fayllar tozalab turiladi — ularda shaxsiy ma’lumot bor.
- [ ] `log.level` ishlab turgan serverda `info` yoki `warning` (hech qachon `debug` emas).
- [ ] `tools/seed.php` ishlab turgan bazada **hech qachon** ishga tushirilmagan.
- [ ] Panel havolasi ochiq guruh va kanallarga tashlanmagan.

---

## 🚦 Ishga tushirishdan oldin nimani o‘zgartirish kerak

| # | Nima | Qayerda |
|---|---|---|
| 1 | Bot tokeni | `config.php` → `telegram.token` |
| 2 | Bot username’i | `config.php` → `telegram.bot_username` |
| 3 | Webhook sekreti (tasodifiy 32+ belgi) | `config.php` → `telegram.webhook_secret` |
| 4 | Administratorlar Telegram ID’lari | `config.php` → `telegram.admin_ids` |
| 5 | Yangi arizalar guruhi | `config.php` → `telegram.admin_chat_id` |
| 6 | Baza drayveri va ma’lumotlari | `config.php` → `database.*` |
| 7 | Saytning to‘liq manzili | `config.php` → `app.base_url` |
| 8 | `setup_key` (o‘rnatishdan keyin qayta almashtiring) | `config.php` → `security.setup_key` |
| 9 | Panel logini va parol xeshi | `config.php` → `security.admin_panel.*` |
| 10 | Majburiy obuna kanali (kerak bo‘lsa) | `config.php` → `app.required_channel` |
| 11 | Buyruqlar menyusi (`/setcommands`) | @BotFather — [`docs/BOTFATHER.uz.md`](docs/BOTFATHER.uz.md) |
| 12 | Bot rasmi, tavsifi va «about» matni | @BotFather |
| 13 | Cron: `broadcast:run` va `cleanup` | cPanel → Cron Jobs |
| 14 | `setup.php` faylini **o‘chirish** | File Manager |
| 15 | Demo ma’lumotni tozalash (agar seed ishlatilgan bo‘lsa) | `php tools/seed.php --fresh` yoki bazani tozalash |

---

## 📚 Qo‘shimcha qo‘llanmalar

| Fayl | Kim uchun |
|---|---|
| [`docs/DEPLOY.uz.md`](docs/DEPLOY.uz.md) | **cPanel’ga o‘rnatishning to‘liq, qadamba-qadam qo‘llanmasi** |
| [`docs/ADMIN.uz.md`](docs/ADMIN.uz.md) | Hokimlik operatori: panelda kundalik ish |
| [`docs/BOTFATHER.uz.md`](docs/BOTFATHER.uz.md) | Botni yaratish, token, buyruqlar menyusi, admin guruhi |
| [`docs/FAQ.uz.md`](docs/FAQ.uz.md) | Savol-javoblar va muammolar yechimi |
| [`docs/CONTRIBUTING.uz.md`](docs/CONTRIBUTING.uz.md) | Dasturchilar: kod uslubi, PHP 8.1 qoidalari, testlar |
| [`tools/README.md`](tools/README.md) | Demo ma’lumot generatori (faqat development) |

> ℹ️ `docs/` papkasi `.htaccess` bilan brauzerdan yopilgan — bu fayllar repozitoriy va
> File Manager orqali o‘qiladi.

---

## 📄 Litsenziya

Loyiha **MIT** litsenziyasi ostida tarqatiladi: foydalanish, o‘zgartirish va tarqatish erkin,
lekin dastur «qanday bo‘lsa shundayligicha», hech qanday kafolatsiz taqdim etiladi.
Mualliflik haqidagi qayd nusxalarda saqlanib qolishi kerak.

## 🏛 Minnatdorchilik

Loyiha **Andijon viloyati hokimligi** tashabbusi va ko‘magida
**Andijon AI Talents** dasturi doirasida yaratilgan.

<div align="center">

✨ _“Bugun iqtidorni kashf et, ertaga kelajakni yarat!”_ ✨

</div>

---

<a id="english"></a>

## 🇬🇧 English summary

**Andijon AI Talents** is a Telegram registration bot plus a web admin panel, built for the youth
AI-talent scouting programme run by the **Andijan Region Administration** (Andijon viloyati
hokimligi). The bot walks an applicant through a short bilingual (Uzbek / Russian) form and
collects **full name, phone number, birth year, city or district, areas of interest and an
optional portfolio**, validates every answer, shows a confirmation summary and stores the
application. Administrators are notified instantly with approve/reject buttons, and the web panel
provides dashboards, search, filtering, bulk moderation, XLSX export and batched broadcasts.

**Stack:** plain PHP 8.1, **no Composer, no external libraries, no CDN assets**. Only core
extensions are used (`pdo`, `pdo_mysql` / `pdo_sqlite`, `mbstring`, `json`, optionally `curl`
and `zip`). It is designed to run on ordinary cPanel shared hosting.

### Requirements

* PHP **8.1+** with `pdo`, `mbstring`, `json` and the matching PDO driver
  (`pdo_mysql` or `pdo_sqlite`); `curl` and `zip` are recommended but optional
* MySQL **5.7+** / MariaDB **10.2+** with `utf8mb4`, or SQLite 3
* A domain served over **HTTPS** with a valid certificate (Telegram requires it)
* A bot token from [@BotFather](https://t.me/BotFather)

### Install

```bash
cp config.example.php config.php     # fill in token, database, secrets, base_url
php cli.php migrate                  # create the schema
php cli.php webhook:set https://example.uz/bot/index.php
php cli.php admin:hash 'YourStrongPassword'   # paste the hash into config.php
# then open https://example.uz/bot/admin/ and DELETE setup.php
```

No shell access? Open `setup.php?key=<security.setup_key>` in a browser: it checks the PHP
version and extensions, verifies database connectivity, runs the migrations, sets the webhook,
performs a `getMe` health check and generates the panel password hash. **Delete `setup.php`
when you are done.**

### Local development

```bash
php cli.php poll        # long polling instead of a webhook (add --drop-webhook if one is set)
php -S localhost:8000   # then open http://localhost:8000/admin/
php tests/run.php       # dependency-free test harness
```

### Cron

```cron
* * * * *  cd /home/USER/public_html/bot && /usr/local/bin/php cli.php broadcast:run --batch=25 >/dev/null 2>&1
15 3 * * *  cd /home/USER/public_html/bot && /usr/local/bin/php cli.php cleanup --days=90 >/dev/null 2>&1
```

The first entry is **required** for broadcasts: the panel only queues messages, the cron job
drains the queue in rate-limit-friendly batches.

### Documentation

All operator documentation is written in Uzbek and lives in `docs/`:
`DEPLOY.uz.md` (full cPanel walkthrough), `ADMIN.uz.md` (panel guide), `BOTFATHER.uz.md`
(bot setup), `FAQ.uz.md` (troubleshooting) and `CONTRIBUTING.uz.md` (coding rules).

### Licence

MIT. Created for the **Andijon AI Talents** programme of the Andijan Region Administration.
_“Discover talent today, build the future tomorrow.”_
