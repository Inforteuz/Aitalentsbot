# 🚀 cPanel’ga o‘rnatish — to‘liq qo‘llanma

> **Andijon AI Talents** — ro‘yxatdan o‘tish boti va boshqaruv paneli.
> _“Bugun iqtidorni kashf et, ertaga kelajakni yarat!”_
>
> Ushbu qo‘llanma **dasturlashni bilmaydigan odam** uchun yozilgan. Hamma ish oddiy hosting
> paneli (cPanel) va brauzer orqali bajariladi — SSH, terminal yoki Composer **kerak emas**.
> Har bir qadamda “nima bosiladi”, “nima yoziladi” va “natija qanday ko‘rinadi” aytib o‘tilgan.
>
> ⏱ **Taxminiy vaqt:** 30–45 daqiqa (birinchi marta).

---

## 📑 Mundarija

| № | Bosqich | Qayerda bajariladi |
|---|---|---|
| [0](#0) | Tayyorgarlik ro‘yxati | — |
| [1](#1) | Botni yaratish va tokenni olish | Telegram → @BotFather |
| [2](#2) | Domen yoki subdomen tanlash | cPanel → Domains |
| [3](#3) | SSL sertifikatini yoqish (AutoSSL) | cPanel → SSL/TLS Status |
| [4](#4) | PHP versiyasini 8.1+ ga qo‘yish | cPanel → MultiPHP Manager |
| [5](#5) | Fayllarni yuklash va ochish | cPanel → File Manager |
| [6](#6) | MySQL bazasini yaratish | cPanel → MySQL Database Wizard |
| [7](#7) | phpMyAdmin orqali tekshirish | cPanel → phpMyAdmin |
| [8](#8) | `config.php` faylini tayyorlash | File Manager → Edit |
| [9](#9) | Papka ruxsatlari (755 / 644 / 775) | File Manager → Permissions |
| [10](#10) | `setup.php` o‘rnatuvchisi | Brauzer |
| [11](#11) | Panelga kirish va sinov | Brauzer + Telegram |
| [12](#12) | Cron (avtomatik vazifalar) | cPanel → Cron Jobs |
| [13](#13) | `setup.php` ni o‘chirish | File Manager |
| [14](#14) | ✅ Yakuniy tekshiruv ro‘yxati | Brauzer |
| [15](#15) | Zaxira nusxa va yangilash | cPanel → Backup |
| [16](#16) | Nimadir ishlamasa | — |

---

<a id="0"></a>

## 0. 📝 Tayyorgarlik ro‘yxati

Boshlashdan oldin quyidagilar qo‘lingizda bo‘lsin. **Hammasini bitta faylga yozib qo‘ying** —
o‘rnatish davomida ularning har biri kerak bo‘ladi.

| ✅ | Nima kerak | Qayerdan olinadi |
|---|---|---|
| ☐ | **cPanel logini va paroli** | Hosting provayderidan (elektron pochtaga yuborilgan) |
| ☐ | **Domen yoki subdomen** | Masalan `domen.uz` yoki `bot.domen.uz` |
| ☐ | **Loyiha arxivi** (`Aitalentsbot.zip`) | Loyiha fayllari (`index.php`, `src/`, `admin/` …) |
| ☐ | **Bot tokeni** | Telegram → [@BotFather](https://t.me/BotFather) → `/newbot` |
| ☐ | **Bot username’i** | O‘sha yerda, masalan `andijon_ai_talents_bot` |
| ☐ | **O‘zingizning Telegram ID’ingiz** | Telegram → [@userinfobot](https://t.me/userinfobot) → `/start` |
| ☐ | **Ikkita tasodifiy kalit** | Quyidagi maslahatga qarang |
| ☐ | **Panel uchun kuchli parol** | Kamida 12 belgi, katta-kichik harf, raqam, belgi |

> 🔑 **Tasodifiy kalitni qanday olish kerak?**
> Ikkita kalit kerak: `webhook_secret` va `setup_key`. Ular shunchaki uzun, taxmin qilib
> bo‘lmaydigan satrlar. Eng oson yo‘l — klaviaturadan 32+ ta tasodifiy harf-raqam terish,
> masalan:
> ```
> Kx7pQm2vTz9LrWb4NcYh6JdFs3GuEa8Z
> Vn5tRj1qHw8XyBk3MpLc7ZdSf2GmTa9E
> ```
> Bu kalitlar hech kimga ko‘rsatilmaydi va hech qayerga yozilmaydi — faqat `config.php` da turadi.

---

<a id="1"></a>

## 1. 🤖 Botni yaratish va tokenni olish

1. Telegram’da [@BotFather](https://t.me/BotFather) ni oching va **Start** ni bosing.
2. `/newbot` deb yozing.
3. Botning **ko‘rinadigan nomi**ni yozing, masalan: `Andijon AI Talents`.
4. Botning **username**’ini yozing — u `bot` bilan tugashi shart, masalan:
   `andijon_ai_talents_bot`.
5. BotFather sizga **token** beradi. U shunday ko‘rinadi:

   ```
   7123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw
   ```

6. 🔒 **Tokenni nusxalab, xavfsiz joyga saqlang.** Token — bu botning paroli. Uni hech kimga
   bermang, skrinshot qilib guruhga tashlamang. Agar token oshkor bo‘lsa, BotFather’da
   `/revoke` orqali darhol yangisini oling.

> 📖 Bot rasmini, tavsifini va buyruqlar menyusini sozlash —
> [`BOTFATHER.uz.md`](BOTFATHER.uz.md) faylida batafsil yozilgan. Uni **hozir emas**,
> o‘rnatish tugagandan keyin bajarsangiz ham bo‘ladi.

---

<a id="2"></a>

## 2. 🌐 Domen yoki subdomen tanlash

Botni joylashtirishning ikki yo‘li bor. **Ikkalasi ham to‘g‘ri** — o‘zingizga qulayini tanlang.

| Variant | Manzil | Fayllar qayerda turadi | Kimga mos |
|---|---|---|---|
| **A. Papka** (eng oddiy) | `https://domen.uz/bot/` | `public_html/bot/` | Domen allaqachon ishlayotgan bo‘lsa |
| **B. Subdomen** (chiroyliroq) | `https://bot.domen.uz/` | `public_html/bot.domen.uz/` | Bot uchun alohida manzil kerak bo‘lsa |

### Variant B ni tanlagan bo‘lsangiz — subdomen yaratish

1. cPanel → **Domains** (yoki eski versiyalarda **Subdomains**).
2. **Create A New Domain** tugmasini bosing.
3. **Domain** maydoniga: `bot.domen.uz`.
4. **Document Root** avtomatik to‘ldiriladi (`public_html/bot.domen.uz`) — **o‘zgartirmang**.
5. **Submit** / **Create**.

> ⏳ Yangi subdomen internetda tarqalishi (DNS) uchun 5–30 daqiqa ketishi mumkin.
> Shu vaqt ichida `https://bot.domen.uz` ochilmasligi normal holat.

📌 **Tanlagan manzilingizni yozib qo‘ying** — u keyin `config.php` da `base_url` bo‘ladi:

```
https://domen.uz/bot          ← Variant A (oxirida "/" YO'Q!)
https://bot.domen.uz          ← Variant B
```

---

<a id="3"></a>

## 3. 🔐 SSL sertifikatini yoqish (AutoSSL)

> ⚠️ **Bu qadamni o‘tkazib yubormang.** Telegram bot bilan **faqat HTTPS** orqali gaplashadi.
> Sertifikatsiz webhook ishlamaydi va bot hech qanday xabarga javob bermaydi.

1. cPanel → **SSL/TLS Status** (yoki **Security → SSL/TLS Status**).
2. Ro‘yxatdan domeningizni (yoki subdomeningizni) belgilang.
3. **Run AutoSSL** tugmasini bosing.
4. 2–10 daqiqa kuting va sahifani yangilang.
5. Domen yonida yashil qulf 🔒 va **“AutoSSL Domain Validated”** yozuvi paydo bo‘lishi kerak.

### Tekshirish

Brauzerda oching: `https://domen.uz` (yoki `https://bot.domen.uz`).

| Ko‘rinish | Ma’nosi |
|---|---|
| 🔒 Manzil qatorida qulf belgisi, ogohlantirishsiz | ✅ Hammasi joyida, davom eting |
| ⚠️ “Your connection is not private” | ❌ Sertifikat hali tayyor emas — biroz kuting va AutoSSL’ni qayta ishga tushiring |
| ⚠️ Qulf bor, lekin “Not fully secure” | ❌ Sertifikat boshqa domenga tegishli — hosting texnik yordamiga yozing |

> 💡 AutoSSL topilmasa yoki ishlamasa, hosting texnik yordamiga shunday yozing:
> **“Iltimos, `domen.uz` uchun bepul Let’s Encrypt sertifikatini yoqib bering.”**

---

<a id="4"></a>

## 4. 🐘 PHP versiyasini 8.1 yoki undan yuqoriga qo‘yish

> Bot **PHP 8.1+** talab qiladi. Eski versiyada u umuman ishga tushmaydi
> (oq sahifa yoki “Parse error” chiqadi).

1. cPanel → **MultiPHP Manager** (yoki **Select PHP Version**).
2. Ro‘yxatdan domeningizni belgilang.
3. **PHP Version** ro‘yxatidan **8.1** (yoki 8.2 / 8.3) ni tanlang.
4. **Apply** tugmasini bosing.

### Kengaytmalarni tekshirish

cPanel → **Select PHP Version** → **Extensions** yorlig‘i. Quyidagilar **belgilangan** bo‘lsin:

| Kengaytma | Kerakmi | Nima uchun |
|---|---|---|
| `pdo` | ✅ **Majburiy** | Ma’lumotlar bazasi bilan ishlash |
| `pdo_mysql` | ✅ **Majburiy** (MySQL ishlatsangiz) | MySQL ulanishi |
| `pdo_sqlite` | ✅ **Majburiy** (SQLite ishlatsangiz) | SQLite ulanishi |
| `mbstring` | ✅ **Majburiy** | O‘zbek va rus harflari to‘g‘ri ishlashi uchun |
| `json` | ✅ **Majburiy** | Odatda PHP 8 bilan birga keladi va o‘chirilmaydi |
| `curl` | 🟡 Juda tavsiya etiladi | Telegram bilan tez aloqa. Bo‘lmasa sekinroq zaxira yo‘l ishlaydi |
| `zip` | 🟡 Tavsiya etiladi | Excel (XLSX) fayllarini yig‘ish. Bo‘lmasa ham ishlaydi |

O‘zgartirgach **Save** tugmasini bosing.

> ℹ️ Bu ro‘yxatni keyinroq `setup.php` ning **1-bo‘limi** ham avtomatik tekshirib beradi —
> hozir aniq bilmasangiz, davom eting.

---

<a id="5"></a>

## 5. 📂 Fayllarni yuklash va ochish

1. cPanel → **File Manager**.
2. Chap tomondagi daraxtdan **`public_html`** papkasini oching.
3. **Variant A** (papka): yuqoridagi **+ Folder** tugmasi bilan `bot` nomli papka yarating va
   uni oching.
   **Variant B** (subdomen): `bot.domen.uz` papkasi allaqachon yaratilgan — uni oching.
4. Yuqoridagi **Upload** tugmasini bosing.
5. `Aitalentsbot.zip` arxivini tanlang va yuklanishini kuting (pastda 100% bo‘lguncha).
6. **“Go Back to …”** havolasi bilan papkaga qayting va **F5** bosing — arxiv ko‘rinadi.
7. Arxiv ustiga **o‘ng tugma** → **Extract** → **Extract Files**.
8. Ochilgandan so‘ng `.zip` faylni **o‘chiring** (o‘ng tugma → Delete).

### ⚠️ Eng ko‘p uchraydigan xato: “papka ichida papka”

Arxiv ochilgach papkada faqat bitta `Aitalentsbot` papkasi turgan bo‘lsa, fayllar **bir daraja
chuqurroq** tushib qolgan. Buni tuzatish shart:

1. `Aitalentsbot` papkasini oching.
2. **Select All** (yuqorida) — barcha fayllar belgilanadi.
3. **Move** tugmasini bosing.
4. Yo‘ldan oxirgi `/Aitalentsbot` qismini **o‘chiring** (masalan `/public_html/bot/Aitalentsbot`
   → `/public_html/bot`).
5. **Move Files**.
6. Bo‘shab qolgan `Aitalentsbot` papkasini o‘chiring.

### ✅ To‘g‘ri natija qanday ko‘rinadi

`public_html/bot/` papkasini ochganingizda **shu fayllar yonma-yon** turishi kerak:

```
📄 index.php          📄 bootstrap.php     📄 cli.php
📄 setup.php          📄 config.example.php
📁 admin/   📁 src/   📁 lang/   📁 data/   📁 docs/   📁 tests/   📁 tools/
```

> 👀 `.htaccess` fayli ko‘rinmayaptimi? Bu normal — u yashirin fayl.
> Ko‘rish uchun: File Manager → o‘ng yuqorida **Settings** → **Show Hidden Files (dotfiles)**
> → **Save**. `.htaccess` fayli **albatta joyida turishi kerak** — u himoya uchun javob beradi.

---

<a id="6"></a>

## 6. 🗄 MySQL bazasini yaratish

> 💡 **Baza umuman kerak emasmi?** Kichik loyihalar uchun **SQLite** ham yetadi: unda hech narsa
> yaratish shart emas, 6- va 7-bo‘limlarni o‘tkazib yuboring va 8-bo‘limda `driver` ni
> `'sqlite'` holida qoldiring. Lekin **ko‘p arizali, uzoq muddatli loyiha uchun MySQL tavsiya
> etiladi** — u ishonchliroq va zaxira nusxa olish osonroq.

Eng oson yo‘l — sehrgardan (wizard) foydalanish.

1. cPanel → **MySQL® Database Wizard**.
2. **Step 1 — Create A Database**
   - **New Database:** `aitalents`
   - **Next Step**
   - cPanel unga o‘z prefiksini qo‘shadi. Ekranda paydo bo‘lgan **to‘liq nomni yozib oling**,
     masalan: `hokimlik_aitalents`
3. **Step 2 — Create Database Users**
   - **Username:** `aiuser` → to‘liq nomi `hokimlik_aiuser` bo‘ladi
   - **Password:** **Password Generator** tugmasini bosing → parolni **nusxalang va saqlang**
   - ⚠️ Parolda `'` (bir tirnoq), `"` va `\` belgilari **bo‘lmasin** — ular `config.php` da
     muammo tug‘diradi. Generator bunday parol bergan bo‘lsa, yangisini oling.
   - **Create User**
4. **Step 3 — Add User to the Database**
   - **ALL PRIVILEGES** katagini belgilang (barcha huquqlar birdaniga belgilanadi)
   - **Next Step**
5. Tayyor. Ekranda “User was added to the database” yozuvi chiqadi.

### 📌 Uchta qiymatni yozib qo‘ying

```
Baza nomi:        hokimlik_aitalents
Foydalanuvchi:    hokimlik_aiuser
Parol:            <generator bergan parol>
Host:             localhost
```

> ⚠️ **Prefiksni unutmang.** cPanel’da baza nomi hech qachon shunchaki `aitalents` bo‘lmaydi —
> u har doim `hisob_aitalents` ko‘rinishida bo‘ladi. Bu eng ko‘p uchraydigan xatolardan biri.

---

<a id="7"></a>

## 7. 🔍 phpMyAdmin orqali tekshirish

Baza haqiqatan yaratilganiga ishonch hosil qilamiz.

1. cPanel → **phpMyAdmin**.
2. Chap tomonda bazalar ro‘yxati ko‘rinadi.
3. `hokimlik_aitalents` bazasi ro‘yxatda bo‘lishi kerak.
4. Uning ustiga bosing.

| Ko‘rinish | Ma’nosi |
|---|---|
| **“No tables found in database”** | ✅ **To‘g‘ri!** Baza bo‘sh — jadvallarni bot 10-bosqichda o‘zi yaratadi |
| Baza ro‘yxatda yo‘q | ❌ 6-bosqichni qaytadan bajaring |
| Jadvallar allaqachon bor | ⚠️ Bu baza boshqa loyiha tomonidan ishlatilyapti — **yangi baza yarating** |

> 🔁 Keyinchalik (10-bosqichdan so‘ng) shu yerga qaytib kelganingizda 9 ta jadval ko‘rinadi:
> `users`, `registrations`, `settings`, `broadcasts`, `broadcast_targets`, `rate_limits`,
> `audit_log`, `login_attempts`, `migrations`.

---

<a id="8"></a>

## 8. ⚙️ `config.php` faylini tayyorlash

Bu **eng muhim** qadam. Diqqat bilan bajaring.

### 8.1. Nusxa yaratish

1. File Manager → bot papkasini oching.
2. `config.example.php` fayli ustiga **o‘ng tugma** → **Copy**.
3. Ochilgan oynada yo‘lning oxirini `config.example.php` dan **`config.php`** ga o‘zgartiring:
   ```
   /public_html/bot/config.php
   ```
4. **Copy File** tugmasini bosing.
5. Endi papkada ikkita fayl bor: `config.example.php` (namuna, tegmang) va `config.php` (ishchi).

### 8.2. Tahrirlash

`config.php` ustiga **o‘ng tugma** → **Edit** → (ogohlantirish chiqsa) **Edit**.

Fayl ichida uzun izohlar bor — ular sizga yordam beradi, **o‘chirmang**. Faqat qiymatlarni
o‘zgartiring. Quyidagi **8 ta joyni** toping va to‘ldiring:

#### 1️⃣ Bot tokeni

```php
'token'           => '7123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw',
```

#### 2️⃣ Bot username’i (`@` belgisisiz!)

```php
'bot_username'    => 'andijon_ai_talents_bot',
```

#### 3️⃣ Webhook maxfiy so‘zi (tayyorlagan birinchi tasodifiy kalitingiz)

```php
'webhook_secret'  => 'Kx7pQm2vTz9LrWb4NcYh6JdFs3GuEa8Z',
```

#### 4️⃣ Administratorlar Telegram ID’lari

```php
'admin_ids'       => [123456789],
// Bir nechta bo'lsa vergul bilan: [123456789, 987654321]
```

#### 5️⃣ Yangi arizalar tushadigan guruh (ixtiyoriy)

```php
'admin_chat_id'   => null,          // guruh bo'lmasa null qoldiring
// yoki: 'admin_chat_id' => -1001234567890,
```

#### 6️⃣ Ma’lumotlar bazasi

**MySQL uchun:**

```php
'database' => [
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'hokimlik_aitalents',
    'username' => 'hokimlik_aiuser',
    'password' => 'BAZA_PAROLI_SHU_YERGA',
    'charset'  => 'utf8mb4',
    'prefix'   => '',
    'path'     => __DIR__ . '/data/aitalents.sqlite',
],
```

**SQLite uchun** faqat bitta qatorni o‘zgartirish yetarli:

```php
'driver'   => 'sqlite',
```

#### 7️⃣ Saytning to‘liq manzili

```php
'base_url'          => 'https://domen.uz/bot',      // oxirida "/" YO'Q!
```

#### 8️⃣ O‘rnatuvchi kaliti (ikkinchi tasodifiy kalitingiz)

```php
'setup_key'  => 'Vn5tRj1qHw8XyBk3MpLc7ZdSf2GmTa9E',
```

Yuqori o‘ng burchakdagi **Save Changes** tugmasini bosing.

### ⚠️ Uchta oltin qoida

| # | Qoida | Nima uchun |
|---|---|---|
| 1 | Qiymatlar **bir tirnoq** ichida (`'shunday'`) | Tokendagi va parollardagi maxsus belgilar buzilmaydi |
| 2 | Har bir qatordan keyin **vergul** `,` turadi | Vergul unutilsa, butun sayt “oq sahifa” bo‘lib qoladi |
| 3 | Fayl oxirida **bo‘sh qator qoldirmang** | Ortiqcha bo‘shliq webhook javobini buzadi |

> ❗ **Parolda `$` belgisi bormi?** Bir tirnoq ichida bo‘lsa muammo yo‘q. Lekin qo‘sh tirnoq
> (`"..."`) ishlatmang — PHP `$` dan keyingi qismni o‘zgaruvchi deb o‘qishga urinadi.

---

<a id="9"></a>

## 9. 🔓 Papka ruxsatlari

Bot `data/` papkasiga jurnal (log) va eksport fayllarini yozadi. Agar u yerga yozish taqiqlangan
bo‘lsa, bot ishlamaydi.

### Standart holat (odatda o‘zgartirish shart emas)

| Obyekt | Ruxsat | Ma’nosi |
|---|---|---|
| Barcha **papkalar** | `755` | Egasi yozadi, qolganlar faqat o‘qiydi |
| Barcha **fayllar** | `644` | Egasi yozadi, qolganlar faqat o‘qiydi |
| `data/` | **`775`** | ✍️ Yozuvga ruxsat kerak |
| `data/logs/` | **`775`** | ✍️ Jurnal fayllari shu yerda |
| `data/exports/` | **`775`** | ✍️ Excel fayllari shu yerda |
| `data/aitalents.sqlite` | `664` | Faqat SQLite ishlatilsa |
| `config.php` | `600` yoki `644` | Iloji bo‘lsa `600` — faqat egasi o‘qiy oladi |

### File Manager orqali o‘zgartirish

1. `data` papkasi ustiga **o‘ng tugma** → **Change Permissions**.
2. Katakchalarni shunday belgilang:

   |  | Read | Write | Execute |
   |---|:---:|:---:|:---:|
   | **User** | ✅ | ✅ | ✅ |
   | **Group** | ✅ | ✅ | ✅ |
   | **World** | ✅ | ☐ | ✅ |

   Pastda **`0775`** raqami chiqishi kerak.
3. **Change Permissions** tugmasini bosing.
4. Xuddi shu amalni `data/logs` va `data/exports` papkalari uchun ham takrorlang.

> 🛟 `775` yordam bermasa (ba’zi eski hostinglarda shunday bo‘ladi), **faqat `data/` uchun**
> `777` qo‘yib ko‘ring. Boshqa hech qanday papkaga `777` qo‘ymang!

---

<a id="10"></a>

## 10. 🧰 `setup.php` o‘rnatuvchisini ishga tushirish

Endi eng qiziq qismi. Brauzerda quyidagi manzilni oching (kalitni o‘zingiznikiga almashtiring):

```
https://domen.uz/bot/setup.php?key=Vn5tRj1qHw8XyBk3MpLc7ZdSf2GmTa9E
```

Ochilgan sahifada **8 ta bo‘lim** bor va har birida ✅ yoki ❌ ko‘rsatkichi turadi.

> ❌ **“Kalit noto‘g‘ri” yoki oq sahifa chiqdimi?** Manzildagi kalit `config.php` dagi
> `setup_key` bilan **aynan bir xil** bo‘lishi kerak (katta-kichik harflar ham). Nusxalashda
> ortiqcha bo‘shliq tushib qolmaganiga ishonch hosil qiling.

### 🔹 1-bo‘lim — PHP va kengaytmalar

Barcha qatorlar yashil ✅ bo‘lishi kerak.

| Muammo | Yechim |
|---|---|
| PHP 8.1 dan past | [4-bosqich](#4) ga qayting |
| `pdo_mysql` yo‘q | Select PHP Version → Extensions → belgilang → Save |
| `mbstring` yo‘q | Xuddi shunday |
| `curl` yo‘q (sariq) | Ishlashga xalaqit bermaydi, lekin yoqilgani yaxshi |

### 🔹 2-bo‘lim — Papka ruxsatlari

`data/`, `data/logs/`, `data/exports/` — hammasi yashil bo‘lishi kerak.
Qizil bo‘lsa → [9-bosqich](#9) ga qayting.

### 🔹 3-bo‘lim — Konfiguratsiya

Token, baza ma’lumotlari, kalitlar to‘ldirilganini tekshiradi.
Qizil bo‘lsa → [8-bosqich](#8) ga qayting va o‘sha maydonni to‘ldiring.

### 🔹 4-bo‘lim — Ma’lumotlar bazasi

1. Avval **ulanish** tekshiriladi. Yashil bo‘lsa — davom eting.
2. **«Migratsiyalarni ishga tushirish»** tugmasini bosing.
3. Ekranda qatorlar chiqadi:
   ```
   ✅ applied 001_create_users
   ✅ applied 002_create_registrations
   ...
   ✅ 9 migration(s) applied.
   ```
4. Tugma yana bosilsa: `Schema is already up to date` — bu ham normal (takroran ishga tushirish xavfsiz).

| Xato | Sabab | Yechim |
|---|---|---|
| `Access denied for user` | Login yoki parol xato | `config.php` → `database.username` / `password` |
| `Unknown database` | Baza nomi prefiksiz yozilgan | `hokimlik_aitalents` shaklida yozing |
| `Connection refused` | `host` noto‘g‘ri | `localhost` deb yozing |
| `Specified key was too long` | MySQL juda eski | cPanel → **Select MySQL Version** → 5.7+; yoki `driver` ni `sqlite` qiling |

### 🔹 5-bo‘lim — Webhook

Bu Telegram’ga “xabarlarni shu manzilga yubor” deb aytish.

1. Maydonda manzil avtomatik to‘ldirilgan bo‘ladi:
   ```
   https://domen.uz/bot/index.php
   ```
   (`base_url` to‘g‘ri yozilgan bo‘lsa.)
2. **«Webhook o‘rnatish»** tugmasini bosing.
3. Natija:
   ```
   ✅ Webhook o'rnatildi.
   URL: https://domen.uz/bot/index.php
   Pending updates: 0
   Last error: —
   ```

| Xato | Sabab va yechim |
|---|---|
| `SSL error` / `certificate` | Sertifikat tayyor emas → [3-bosqich](#3) |
| `Failed to resolve host` | Domen hali ishlamayapti (DNS) → 30 daqiqa kuting |
| `Bad webhook: HTTPS url must be provided` | Manzil `http://` bilan boshlangan → `https://` qiling |
| `401 Unauthorized` | Token noto‘g‘ri → `config.php` → `telegram.token` |
| `Wrong response from the webhook: 404` | Manzilda papka nomi xato → `base_url` ni tekshiring |

### 🔹 6-bo‘lim — Bot bilan aloqa (`getMe`)

Yashil bo‘lsa, ekranda bot nomi va username’i ko‘rinadi:

```
✅ Bot ishlayapti: @andijon_ai_talents_bot
```

Qizil bo‘lsa — token noto‘g‘ri yoki BotFather’da `/revoke` qilingan.

### 🔹 7-bo‘lim — Panel parolini yaratish

1. Maydonga **o‘zingiz tanlagan parolni** yozing (kamida 12 belgi).
2. **«Xesh yaratish»** tugmasini bosing.
3. Pastda uzun satr chiqadi:
   ```
   $2y$10$N9qo8uLOickgx2ZMRZoMy.MH/rBQpNSJfLC1cVv5hLD8yZ7pGSMPC
   ```
4. Uni **to‘liq nusxalang** (`$` belgisidan oxirgi harfgacha).
5. File Manager → `config.php` → **Edit** → toping va to‘ldiring:

   ```php
   'admin_panel' => [
       'enabled'          => true,
       'username'         => 'admin',
       'password_hash'    => '$2y$10$N9qo8uLOickgx2ZMRZoMy.MH/rBQpNSJfLC1cVv5hLD8yZ7pGSMPC',
       'session_lifetime' => 7200,
       'max_attempts'     => 5,
       'lockout_seconds'  => 900,
   ],
   ```

6. **Save Changes**.

> ⚠️ **Ochiq parolni emas, aynan XESHNI** yozing. Xesh har doim `$2y$` bilan boshlanadi va
> ~60 belgidan iborat bo‘ladi. Uni **bir tirnoq** ichiga oling.
>
> 🔁 Parolni keyinchalik almashtirmoqchi bo‘lsangiz, `setup.php` allaqachon o‘chirilgan bo‘ladi —
> unda vaqtincha uni qayta yuklang, xesh oling va yana o‘chiring. Yoki SSH bo‘lsa:
> `php cli.php admin:hash 'YangiParol'`.

### 🔹 8-bo‘lim — Keyingi qadamlar

Bu yerda yakuniy ko‘rsatmalar va `setup.php` ni o‘chirish haqidagi ogohlantirish turadi.

---

<a id="11"></a>

## 11. 🎉 Panelga kirish va sinovdan o‘tkazish

### 11.1. Botni sinash

1. Telegram’da botingizni oching (`https://t.me/andijon_ai_talents_bot`).
2. **Start** tugmasini bosing.
3. Til tanlash oynasi chiqadi → **🇺🇿 O‘zbekcha**.
4. Salomlashuv matni va **«📝 Ro‘yxatdan o‘tish»** tugmasi ko‘rinadi.
5. Anketani to‘liq to‘ldirib chiqing:

   | Qadam | Nima so‘raladi | Qanday javob berasiz |
   |---|---|---|
   | 1/6 | Ism-familiya | `Aziz Rahimov` (kamida 2 ta so‘z) |
   | 2/6 | Telefon | **«📱 Raqamni yuborish»** tugmasi yoki qo‘lda `901234567` |
   | 3/6 | Tug‘ilgan yil | `2005` |
   | 4/6 | Shahar/tuman | Ro‘yxatdan tanlang |
   | 5/6 | Yo‘nalishlar | Bir nechtasini belgilang → **«✅ Tayyor»** |
   | 6/6 | Portfolio | Matn yozing yoki **«⏭ O‘tkazib yuborish»** |
   | ✅ | Tasdiqlash | Ma’lumotlarni ko‘rib chiqing → **«✅ Tasdiqlash»** |

6. Natija: “Arizangiz qabul qilindi” xabari va ariza raqami (`#1`).
7. Agar `admin_ids` da o‘zingizni yozgan bo‘lsangiz, o‘sha zahoti **ariza kartochkasi**
   «✅ Tasdiqlash / ❌ Rad etish» tugmalari bilan keladi.

### 11.2. Panelga kirish

Brauzerda oching:

```
https://domen.uz/bot/admin/
```

1. **Login:** `admin` (yoki `config.php` da yozganingiz)
2. **Parol:** 7-bo‘limda xesh yaratganingizdagi **ochiq parol**
3. **Kirish**

**Bosh sahifa** ochilishi kerak: kartalar, grafiklar va **so‘nggi arizalar** ro‘yxatida hozirgina
topshirgan test arizangiz.

| Muammo | Yechim |
|---|---|
| “Login yoki parol noto‘g‘ri” | Xesh to‘liq nusxalanmagan yoki qo‘sh tirnoq ishlatilgan — 7-bo‘limni takrorlang |
| “Admin panel o‘chirilgan” | `config.php` → `admin_panel.enabled` → `true` |
| Oq sahifa | `config.php` da sintaksis xatosi — vergullarni tekshiring |
| 403 Forbidden | `.htaccess` fayli buzilgan yoki `admin/` papkasi yuklanmagan |

### 11.3. Test arizasini o‘chirish

Panel → **Arizalar** → test arizangiz qatoridagi **🗑** belgisi → tasdiqlang.
Endi baza toza va haqiqiy arizalarni kutish mumkin.

---

<a id="12"></a>

## 12. ⏱ Cron (avtomatik vazifalar)

Ommaviy xabar (broadcast) yuborilganda tizim uni **navbatga** qo‘yadi va kichik paketlarga
bo‘lib yuboradi (Telegram’ning tezlik chegarasi shuni talab qiladi). Navbatni bo‘shatib turadigan
narsa — **cron**.

> ⚠️ Cron sozlanmasa, panelda tayyorlangan xabarlar **navbatda turib qoladi va yuborilmaydi**.

### 12.1. To‘liq yo‘llarni aniqlash

**Papka yo‘li:** File Manager → bot papkasini oching → yuqorida **“Current Path”** yozuvi.
Odatda shunday bo‘ladi:

```
/home/hokimlik/public_html/bot
```

**PHP yo‘li:** cPanel → **Terminal** (bo‘lsa) → `which php`. Terminal yo‘q bo‘lsa, quyidagi
variantlardan birini ishlating — ular cPanel’da eng ko‘p uchraydi:

```
/usr/local/bin/php
/usr/local/bin/ea-php81
/opt/cpanel/ea-php81/root/usr/bin/php
```

### 12.2. Cron qo‘shish

1. cPanel → **Cron Jobs**.
2. Pastda **Add New Cron Job** bo‘limi.
3. **Common Settings** ro‘yxatidan **“Once Per Minute (\* \* \* \* \*)”** ni tanlang.
4. **Command** maydoniga quyidagini yozing (yo‘llarni o‘zingiznikiga almashtiring):

   ```
   cd /home/hokimlik/public_html/bot && /usr/local/bin/php cli.php broadcast:run --batch=25 --no-color >/dev/null 2>&1
   ```

5. **Add New Cron Job**.

### 12.3. Ikkinchi cron — tozalash (tavsiya etiladi)

Yana bitta vazifa qo‘shing. **Common Settings** dan **“Once Per Day”** ni tanlang yoki
maydonlarni qo‘lda to‘ldiring: `Minute: 15`, `Hour: 3`, qolganlari `*`.

```
cd /home/hokimlik/public_html/bot && /usr/local/bin/php cli.php cleanup --days=90 --no-color >/dev/null 2>&1
```

Bu har kuni kechasi eski jurnal fayllarini, 90 kundan eski audit yozuvlarini va keraksiz
rate-limit yozuvlarini tozalaydi.

### 12.4. Cron jadvali — nima nimani anglatadi

```
*     *     *     *     *
│     │     │     │     │
│     │     │     │     └── hafta kuni (0–7, 0 va 7 = yakshanba)
│     │     │     └──────── oy (1–12)
│     │     └────────────── oyning kuni (1–31)
│     └──────────────────── soat (0–23)
└────────────────────────── daqiqa (0–59)
```

### 12.5. Cron ishlayotganini tekshirish

1. 2–3 daqiqa kuting.
2. Panel → **Loglar** → bugungi sanani tanlang.
3. Yozuvlar paydo bo‘lsa — cron ishlayapti.

| Muammo | Yechim |
|---|---|
| Elektron pochtaga har daqiqada xat keladi | Buyruq oxirida `>/dev/null 2>&1` borligiga ishonch hosil qiling |
| `php: command not found` | To‘liq yo‘l yozilmagan — 12.1 ga qayting |
| `No such file or directory` | `cd` dan keyingi papka yo‘li noto‘g‘ri |
| `This script must be run from the command line` | Buyruq `index.php` ga emas, **`cli.php`** ga ishora qilishi kerak |

---

<a id="13"></a>

## 13. 🔥 `setup.php` ni o‘chirish

> **Bu qadam majburiy.** O‘rnatuvchi kalitsiz hech narsa qilmaydi, lekin uni serverda
> qoldirishning hech qanday sababi yo‘q.

1. File Manager → bot papkasi.
2. `setup.php` fayli ustiga **o‘ng tugma** → **Delete**.
3. **“Skip the trash”** katagini belgilang → **Confirm**.

Tekshirish: `https://domen.uz/bot/setup.php` → **404 Not Found** chiqishi kerak.

### 🔑 Kalitni ham almashtiring

Qo‘shimcha xavfsizlik uchun `config.php` → `security.setup_key` qiymatini yangi tasodifiy
satrga almashtiring. Keyinchalik o‘rnatuvchi kerak bo‘lsa, faylni qayta yuklab, yangi kalit
bilan ochasiz.

---

<a id="14"></a>

## 14. ✅ Yakuniy tekshiruv ro‘yxati

Quyidagi manzillarni **birma-bir** oching va natijani solishtiring.
`domen.uz/bot` o‘rniga o‘z manzilingizni yozing.

### 🌐 Veb-manzillar

| # | Manzil | Sog‘lom javob qanday ko‘rinadi |
|---|---|---|
| 1 | `https://domen.uz/bot/index.php` | Oq fonda bitta qator oddiy matn:<br>`Andijon AI Talents (@bot_username) — webhook is active` |
| 2 | `https://domen.uz/bot/admin/` | Ko‘k gradientli **kirish sahifasi**: “Panelga kirish”, Login va Parol maydonlari |
| 3 | `https://domen.uz/bot/setup.php` | **404 Not Found** (fayl o‘chirilgan) |
| 4 | `https://domen.uz/bot/config.php` | **403 Forbidden** ❗ Agar bo‘sh oq sahifa chiqsa ham xavfsiz, lekin 403 bo‘lgani yaxshiroq. **Token matni ko‘rinsa — bu jiddiy muammo**, `.htaccess` faylini tiklang |
| 5 | `https://domen.uz/bot/src/App.php` | **403 Forbidden** |
| 6 | `https://domen.uz/bot/data/` | **403 Forbidden** |
| 7 | `https://domen.uz/bot/lang/uz.php` | **403 Forbidden** |
| 8 | `https://domen.uz/bot/data/logs/` | **403 Forbidden** |

> ✅ 1-qatordagi javob **aynan shunday oddiy matn** bo‘lishi kerak: sarlavhasiz, dizaynsiz.
> Agar u yerda PHP xatosi, “Warning”, “Fatal error” yoki HTML kodi ko‘rinsa — webhook ishlamaydi.

### 🤖 Telegram tomonidan tekshirish

Brauzerda oching (`<TOKEN>` o‘rniga o‘z tokeningizni qo‘ying):

```
https://api.telegram.org/bot<TOKEN>/getWebhookInfo
```

Sog‘lom javob shunday ko‘rinadi:

```json
{
  "ok": true,
  "result": {
    "url": "https://domen.uz/bot/index.php",
    "has_custom_certificate": false,
    "pending_update_count": 0,
    "max_connections": 40
  }
}
```

| Nimaga qarash kerak | Sog‘lom qiymat | Muammo belgisi |
|---|---|---|
| `"ok"` | `true` | `false` → token noto‘g‘ri |
| `"url"` | To‘liq manzilingiz | Bo‘sh → webhook o‘rnatilmagan |
| `"pending_update_count"` | `0` yoki 1–2 | Doim o‘sib borsa → `index.php` xato qaytaryapti |
| `"last_error_message"` | **Umuman yo‘q** | Bo‘lsa → o‘sha matnni [16-bo‘lim](#16) bo‘yicha tahlil qiling |

> ⚠️ Bu manzilni **hech kimga yubormang va tarixda qoldirmang** — unda tokeningiz bor.
> Tekshirgach brauzer tarixidan o‘chiring.

### 💬 Bot va panel bo‘yicha

| ✅ | Nima tekshiriladi | Kutilgan natija |
|---|---|---|
| ☐ | Botga `/start` yuborish | Til tanlash yoki salomlashuv matni keladi |
| ☐ | To‘liq anketani to‘ldirish | “Arizangiz qabul qilindi” + ariza raqami |
| ☐ | `/profil` buyrug‘i | Topshirgan ma’lumotlaringiz ko‘rinadi |
| ☐ | `/til` buyrug‘i | Til almashadi, xabarlar ruschaga o‘tadi |
| ☐ | `/yordam` buyrug‘i | Yordam matni va buyruqlar ro‘yxati |
| ☐ | Adminga bildirishnoma | Yangi ariza kartochkasi tugmalari bilan keladi |
| ☐ | Panel → Bosh sahifa | Kartalar va grafiklar ko‘rinadi (bo‘sh bazada nollar) |
| ☐ | Panel → Arizalar | Test arizasi ro‘yxatda turibdi |
| ☐ | Panel → Eksport | `.xlsx` fayl yuklanadi va Excel’da to‘g‘ri ochiladi |
| ☐ | Panel → Loglar | Bugungi sana bo‘yicha yozuvlar bor |
| ☐ | Panel → Sozlamalar | Webhook holati yashil, `getMe` yashil |
| ☐ | Cron | 2 daqiqadan keyin loglarda yangi yozuv paydo bo‘ladi |

### 🛡 Xavfsizlik bo‘yicha

| ✅ | Nima |
|---|---|
| ☐ | `setup.php` **o‘chirilgan** |
| ☐ | `setup_key` yangi tasodifiy satrga almashtirilgan |
| ☐ | `webhook_secret` to‘ldirilgan va webhook shu sekret bilan o‘rnatilgan |
| ☐ | Panel paroli kamida 12 belgi va hech qayerda ochiq saqlanmagan |
| ☐ | `config.php` ruxsati `600` yoki `644` |
| ☐ | Butun sayt HTTPS orqali ochiladi |
| ☐ | Bazadan zaxira nusxa olindi |
| ☐ | Token hech qanday guruh yoki chatga yuborilmagan |

---

<a id="15"></a>

## 15. 💾 Zaxira nusxa va yangilash

### 15.1. Zaxira nusxa olish (har hafta tavsiya etiladi)

**Bazani saqlash:**

1. cPanel → **phpMyAdmin** → bazangizni tanlang.
2. Yuqoridagi **Export** yorlig‘i.
3. **Quick** → **Format: SQL** → **Go**.
4. Yuklab olingan `.sql` faylni xavfsiz joyga saqlang.

**Fayllarni saqlash:**

1. cPanel → **File Manager** → bot papkasi ustiga o‘ng tugma → **Compress** → **Zip Archive**.
2. Yaratilgan `.zip` faylni **Download** qiling.

> 📌 `config.php` ham arxivga tushadi — unda **token va parollar** bor.
> Bu arxivni ochiq joyda saqlamang.

**Avtomatik zaxira:** cPanel → **Backup** → **Download a Full Account Backup** —
hosting o‘zi to‘liq nusxa tayyorlab beradi.

### 15.2. Yangi versiyaga o‘tish

1. **Avval zaxira nusxa oling** (15.1).
2. Yangi arxivni yuklang va oching — fayllar eskilarining ustiga yoziladi.
3. ⚠️ `config.php` va `data/` papkasiga **tegmang** — ular sizniki, almashtirilmaydi.
4. `setup.php` ni qayta yuklab, `?key=…` bilan oching → 4-bo‘lim →
   **«Migratsiyalarni ishga tushirish»** (yangi jadvallar bo‘lsa qo‘shiladi).
5. `setup.php` ni yana **o‘chiring**.
6. [14-bo‘limdagi](#14) tekshiruv ro‘yxatini qayta bajaring.

---

<a id="16"></a>

## 16. 🆘 Nimadir ishlamasa

### Birinchi navbatda qaraladigan joy — loglar

Panel → **Loglar** → bugungi sana. Yoki File Manager orqali:
`data/logs/bot-YYYY-MM-DD.log` faylini **View** qiling.

### Eng ko‘p uchraydigan holatlar

| Belgisi | Ehtimoliy sabab | Nima qilish kerak |
|---|---|---|
| Bot **umuman javob bermayapti** | Webhook o‘rnatilmagan | `getWebhookInfo` ni tekshiring ([14-bo‘lim](#14)) |
| Bot javob bermayapti, `pending_update_count` o‘syapti | `index.php` xato qaytaryapti | Loglarni o‘qing; `config.php` ni `php -l` bilan tekshiring |
| **Oq sahifa** (butun sayt) | `config.php` da sintaksis xatosi | Vergullar va tirnoqlarni tekshiring; zaxiradan tiklang |
| **500 Internal Server Error** | `.htaccess` ni hosting qabul qilmayapti | `.htaccess` ni vaqtincha `.htaccess-off` deb nomlang; xato yo‘qolsa, ichidagi bloklarni birma-bir yoqing |
| **403 Forbidden** — panelda | `admin/` papkasi to‘liq yuklanmagan | Fayllarni qayta yuklang |
| Webhook’da **401** | `webhook_secret` o‘zgargan, webhook eski | `setup.php` → 5-bo‘lim → qayta o‘rnating |
| Webhook’da **409 Conflict** | Boshqa joyda `poll` ishlayapti yoki ikkinchi nusxa bor | Test nusxasini to‘xtating; test uchun **alohida bot** yarating |
| **SSL error** | Sertifikat yo‘q yoki yaroqsiz | [3-bosqich](#3) |
| Loglar yozilmayapti | `data/logs/` yozuvga yopiq | [9-bosqich](#9) → `775` |
| “Ro‘yxatdan o‘tish yopiq” | Qabul o‘chirilgan | Panel → **Sozlamalar** → qabulni yoqing |
| Ommaviy xabarlar yuborilmayapti | Cron sozlanmagan | [12-bosqich](#12) |
| Excel fayl ochilmayapti | Yuklanish uzilib qolgan | Qayta yuklab oling; hajmi 0 bo‘lmasin |

### Batafsil savol-javoblar

📖 [`FAQ.uz.md`](FAQ.uz.md) — 60+ savol va ularning yechimi.
📖 [`ADMIN.uz.md`](ADMIN.uz.md) — panel bilan kundalik ish.
📖 [`BOTFATHER.uz.md`](BOTFATHER.uz.md) — bot menyusi, rasmi, admin guruhi.

### Yordam so‘rashdan oldin tayyorlab qo‘ying

Texnik mutaxassisga murojaat qilsangiz, quyidagilarni birga yuboring — javob bir necha barobar
tez keladi:

1. Muammoning **aniq matni** (skrinshot yoki ko‘chirilgan matn).
2. `data/logs/bot-YYYY-MM-DD.log` faylining oxirgi 30–50 qatori.
3. `getWebhookInfo` javobi — **`bot<TOKEN>` qismini yulduzchalar bilan yashiring**.
4. PHP versiyasi (cPanel → MultiPHP Manager).
5. Baza turi: MySQL yoki SQLite.
6. Muammo qachondan beri va qaysi amaldan keyin boshlangani.

> 🔒 **Hech qachon** tokeningizni, baza parolingizni yoki panel parolingizni to‘liq holda
> yubormang — hatto texnik yordamga ham. Kerak bo‘lsa, ularni yuborishdan oldin almashtiring.

---

<div align="center">

## 🎊 Tabriklaymiz!

Andijon AI Talents boti ishga tushdi.

✨ _“Bugun iqtidorni kashf et, ertaga kelajakni yarat!”_ ✨

🏛 **Andijon viloyati hokimligi**

</div>
