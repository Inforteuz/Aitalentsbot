# ❓ Savol-javoblar va muammolarni bartaraf etish (FAQ)

> **Andijon AI Talents** boti — tez-tez uchraydigan savollar, xatolar va ularning yechimi.
> Har bir javob aniq fayl nomi, buyruq yoki sozlama kaliti bilan berilgan.
>
> Qo‘shimcha: [`BOTFATHER.uz.md`](BOTFATHER.uz.md) — botni yaratish, [`ADMIN.uz.md`](ADMIN.uz.md) — panel qo‘llanmasi.

**Bo‘limlar:** [O‘rnatish](#-ornatish) · [Webhook](#-webhook) · [Bot ishlashi](#-bot-ishlashi) ·
[Admin panel](#-admin-panel) · [Ma’lumotlar](#-malumotlar) · [Xabar yuborish](#-xabar-yuborish-broadcast) ·
[Xavfsizlik](#-xavfsizlik)

---

## 🛠 O‘rnatish

### 1. Sahifani ochsam “config.php topilmadi” degan matn chiqyapti

`bootstrap.php` loyiha ildizidagi `config.php` faylini talab qiladi va u yo‘q bo‘lsa ishlashdan
to‘xtaydi. Yechim:

```bash
cp config.example.php config.php
```

cPanel’da SSH bo‘lmasa: **File Manager** → `config.example.php` → `Copy` → nomini `config.php` qiling.
So‘ng faylni tahrirlab, kamida `telegram.token`, `database.*` va `security.setup_key` qiymatlarini
to‘ldiring. Fayl `<?php return [ ... ];` ko‘rinishida bo‘lishi shart — oxirgi `];` ni o‘chirib
qo‘ymang.

### 2. “SQLSTATE[HY000] [1045] Access denied” yoki “Connection refused”

Bu MySQL ma’lumotlari mos kelmayotganini bildiradi. `config.php` dagi `database` bo‘limini tekshiring:

| Kalit | cPanel’dagi qiymat |
|-------|--------------------|
| `driver` | `mysql` |
| `host` | Odatda `localhost` |
| `database` | To‘liq nom, prefiks bilan: `hokimlik_aitalents` |
| `username` | To‘liq nom: `hokimlik_bot` |
| `password` | MySQL Databases bo‘limida qo‘yilgan parol |

Eng ko‘p uchraydigan xato — cPanel foydalanuvchi prefiksini tushirib qoldirish. Bazaga foydalanuvchi
biriktirilganini (**MySQL Databases → Add User To Database → ALL PRIVILEGES**) ham tekshiring.
Tekshirish uchun: `setup.php?key=...` sahifasidagi **“Ma’lumotlar bazasi”** paneli.

### 3. SQLite tanladim, lekin “unable to open database file” chiqyapti

Bu ruxsatlar muammosi. `data/` papkasi yozuvga ochiq bo‘lishi kerak:

```bash
chmod 755 data data/logs
chmod 664 data/aitalents.sqlite   # fayl allaqachon mavjud bo‘lsa
```

`database.path` mutlaq yo‘l ekaniga ishonch hosil qiling (standart: `__DIR__ . '/data/aitalents.sqlite'`).
SQLite WAL rejimida ishlaganligi uchun papkaning o‘zi ham yoziladigan bo‘lishi shart — faqat faylga
ruxsat bersangiz yetmaydi.

### 4. Qaysi fayl va papkalarga qanday ruxsat (permissions) kerak?

| Yo‘l | Ruxsat | Izoh |
|------|--------|------|
| Papkalar (umumiy) | `755` | |
| Fayllar (umumiy) | `644` | |
| `config.php` | `644` yoki `640` | Tashqaridan `.htaccess` bloklaydi |
| `data/`, `data/logs/` | `755` (kerak bo‘lsa `775`) | Bot bu yerga yozadi |
| `data/aitalents.sqlite` | `664` | Faqat SQLite uchun |

`777` **hech qachon** qo‘ymang — bu hostingda xavfsizlik teshigi. Ruxsatlarni
`setup.php?key=...` sahifasidagi **“Papka ruxsatlari”** paneli ham tekshirib beradi.

### 5. Hostingdagi PHP 8.1 dan past — nima qilaman?

Bot **PHP 8.1 yoki undan yuqori** versiyani talab qiladi. cPanel’da:
**Software → MultiPHP Manager** → domenni belgilang → `PHP 8.1` (yoki 8.2/8.3) → **Apply**.

CLI buyruqlarida versiya boshqacha bo‘lishi mumkin. Tekshiring:

```bash
php -v
php81 -v      # ba’zi hostinglarda alohida nom bilan turadi
/usr/local/bin/ea-php81 cli.php migrate
```

PHP versiyasini o‘zgartirgach `setup.php?key=...` sahifasini ochib, **“PHP va kengaytmalar”**
panelidagi barcha bandlar yashil ekanini tekshiring (`pdo`, `pdo_mysql` yoki `pdo_sqlite`,
`curl`, `json`, `mbstring`).

### 6. `setup.php` ochilmayapti yoki bo‘sh sahifa chiqyapti

`setup.php` faqat to‘g‘ri kalit bilan ishlaydi. `config.php` dagi `security.setup_key` bo‘sh bo‘lsa
sahifa **umuman** ochilmaydi (bu ataylab shunday qilingan). To‘ldiring:

```php
'security' => [ 'setup_key' => 'uzun-tasodifiy-kalit-32-belgi', ... ],
```

So‘ng oching: `https://domen.uz/bot/setup.php?key=uzun-tasodifiy-kalit-32-belgi`.

### 7. Jadvallarni (migratsiyalarni) qanday yarataman?

Ikki yo‘l bor, ikkalasi ham bir xil natija beradi:

1. `setup.php?key=...` sahifasidagi **“Migratsiyalarni ishga tushirish”** tugmasi.
2. Terminal orqali: `php cli.php migrate`

Buyruqni qayta ishga tushirish xavfsiz: allaqachon bajarilgan migratsiyalar `migrations`
jadvalida qayd etilgan va takroran bajarilmaydi.

---

## 🔗 Webhook

### 8. Webhook’ni qanday o‘rnataman?

```bash
php cli.php webhook:set https://domen.uz/bot/index.php
php cli.php webhook:info
```

Yoki `setup.php?key=...` sahifasidagi **“Webhook”** panelidan `Set` tugmasini bosing (manzil
`app.base_url` asosida o‘zi tuziladi). `app.base_url` oxirida `/` bo‘lmasligi kerak.

### 9. Telegram “401 Unauthorized” qaytaryapti

Ikki xil holat bor:

- **Telegram API 401 beryapti** (`webhook:set` paytida) — `telegram.token` xato yoki `/revoke`
  qilingan. Tokenni BotFather’dan qayta oling.
- **Sizning `index.php` 401 qaytaryapti** (`getWebhookInfo` da `last_error_message` da ko‘rinadi) —
  `telegram.webhook_secret` bilan Telegram yuborayotgan `X-Telegram-Bot-Api-Secret-Token` sarlavhasi
  mos emas. `config.php` da secret’ni o‘zgartirgan bo‘lsangiz, webhook’ni **qaytadan** o‘rnating:
  `php cli.php webhook:set https://domen.uz/bot/index.php`.

### 10. “409 Conflict: terminated by other getUpdates request”

Bitta bot uchun bir vaqtda **webhook** ham, **long-polling** ham ishlayotganini bildiradi.
Odatda kimdir `php cli.php poll` ni ishga tushirib, to‘xtatmagan bo‘ladi.

```bash
# poll jarayonini to‘xtating (Ctrl+C), so‘ng:
php cli.php webhook:set https://domen.uz/bot/index.php
```

Aksincha, lokal test uchun `poll` kerak bo‘lsa: avval `php cli.php webhook:delete`.
Bir tokenni ikki serverda (test va ishlab chiqarish) parallel ishlatmang — **har biriga alohida bot**
yarating.

### 11. “SSL error”, “certificate verify failed”, “wrong version number”

Telegram faqat haqiqiy, ishonchli (`Let’s Encrypt` ham bo‘ladi) sertifikatli HTTPS manzil bilan
ishlaydi. Tekshiruvlar:

- Brauzerda `https://domen.uz/bot/index.php` qulf belgisi bilan ochiladimi?
- Sertifikat **to‘liq zanjiri** (chain) o‘rnatilganmi? cPanel → **SSL/TLS Status** → `Run AutoSSL`.
- Manzil `https://` bilan boshlanadimi (`http://` qabul qilinmaydi)?
- Domenda self-signed sertifikat bo‘lmasin — Telegram uni rad etadi.

Sertifikat yangilangach webhook’ni qayta o‘rnating.

### 12. Webhook o‘rnatildi, lekin bot javob bermayapti

Tartib bilan tekshiring:

1. `php cli.php webhook:info` → `url` to‘g‘rimi, `last_error_message` bo‘shmi?
2. Brauzerda `https://domen.uz/bot/index.php` ochilsin — oq sahifa yoki `OK` chiqishi normal;
   PHP xatosi chiqsa, xato matnini o‘qing.
3. `data/logs/bot-YYYY-MM-DD.log` faylini oching — Router har bir istisnoni shu yerga yozadi.
4. `config.php` dagi `webhook_secret` webhook o‘rnatilgandagi qiymat bilan bir xilmi?
5. Botni bloklab qo‘ymaganingizni tekshiring (Telegram’da chatni oching → `Restart bot`).

### 13. `getWebhookInfo` dagi `last_error_message` nimani bildiradi?

| Xabar | Ma’nosi | Yechim |
|-------|---------|--------|
| `Wrong response from the webhook: 401 Unauthorized` | Secret mos emas | «Telegram 401 Unauthorized» savoliga qarang |
| `Wrong response from the webhook: 404 Not Found` | Manzil xato | `webhook:set` da to‘g‘ri yo‘lni bering |
| `Wrong response from the webhook: 500 Internal Server Error` | PHP xatosi | `data/logs/` va hosting error_log |
| `SSL error ...` | Sertifikat muammosi | «SSL error» savoli |
| `Connection timed out` | Server sekin javob qaytaryapti | `index.php` darhol 200 qaytaradi; hosting yuklamasini tekshiring |
| `Bad webhook: HTTPS url must be provided` | `http://` berilgan | HTTPS manzil kiriting |

`last_error_date` — xato oxirgi marta qachon bo‘lgani. Muammo tuzatilgach, bu maydonlar yangi
xabar kelganda o‘z-o‘zidan tozalanadi.

### 14. `pending_update_count` juda katta (masalan 500+)

Demak bot xabarlarni qabul qila olmayapti va ular Telegram serverida to‘planib qolgan.
Avval sababini bartaraf eting (webhook bo‘limidagi tekshiruv ro‘yxati va `last_error_message` jadvali), so‘ng navbatni tozalang:

```bash
php cli.php webhook:delete            # eski navbat bilan
php cli.php webhook:set https://domen.uz/bot/index.php
```

> ⚠️ Navbatni tozalash foydalanuvchilarning to‘planib qolgan xabarlarini yo‘qotadi — ro‘yxatdan
> o‘tayotgan odam qadamni qaytadan yozishi kerak bo‘lishi mumkin. Arizalar bazasiga ta’sir qilmaydi.

---

## 💬 Bot ishlashi

### 15. Bot butunlay javob bermayapti — nimadan boshlayman?

Qisqa tekshiruv ro‘yxati:

- [ ] `php cli.php webhook:info` — `url` bor, `last_error_message` bo‘sh
- [ ] `setup.php?key=...` da `getMe` yashil
- [ ] `data/logs/bot-*.log` da yangi xatolar yo‘q
- [ ] Bazaga ulanish ishlayapti, migratsiyalar bajarilgan
- [ ] Siz o‘zingiz `users` jadvalida `is_blocked = 1` emassiz
- [ ] Juda ko‘p xabar yubormadingizmi — `security.rate_limit` standart holatda **60 soniyada 20 ta**
      xabarga ruxsat beradi; limitdan oshsa bot jimgina javob bermaydi. Bir daqiqa kuting.

### 16. Telefon raqami qabul qilinmayapti

Bot raqamni `+998901234567` ko‘rinishiga keltiradi va quyidagi yozuvlarni tushunadi:
`901234567`, `90 123 45 67`, `+998901234567`, `998901234567`.

Eng ishonchli yo‘l — **“📱 Raqamni yuborish”** tugmasini bosish. Muhim jihat: agar foydalanuvchi
**boshqa odamning kontaktini** ulashsa, bot uni qabul qilmaydi va raqamni qaytadan so‘raydi
(bu qasddan qo‘yilgan cheklov). Shuningdek, `+7`, `+1` kabi xorijiy raqamlar formatga tushmasligi
mumkin — bunday holatda raqamni qo‘lda yozib yuborish kerak.

### 17. Foydalanuvchi qayta ro‘yxatdan o‘tmoqchi / ma’lumotini o‘zgartirmoqchi

`app.allow_edit` yoqilgan bo‘lsa (standart holat), foydalanuvchi `/profil` yozadi va
**“Ma’lumotlarni yangilash”** tugmasini bosadi — ariza raqami (`#id`) o‘zgarmaydi, maydonlar
yangilanadi. Tasdiqlash bosqichidagi **“✏️ Tahrirlash”** tugmasi ham xohlagan bitta maydonni
qayta so‘raydi.

Butunlay noldan boshlash kerak bo‘lsa, admin panelda **Arizalar → ariza tafsiloti → O‘chirish**
qiling: shundan so‘ng foydalanuvchi `/start` bilan yangidan to‘ldiradi.

### 18. Ro‘yxatni vaqtincha yopmoqchiman

Uch xil yo‘l bor (barchasi bir xil sozlamani boshqaradi):

1. **Admin panel → Sozlamalar → “Ro‘yxat ochiq/yopiq”** o‘tkichi (eng qulayi).
2. **Botda** `/admin` → **🔒 Ro‘yxatni yopish**.
3. `config.php` da `'registration_open' => false` (faqat bazadagi sozlama bo‘lmaganda ishlaydi).

Yopiq holatda mavjud arizalar saqlanadi, yangi foydalanuvchi esa muloyim ogohlantirish oladi.

### 19. Faqat kanalga obuna bo‘lganlar ro‘yxatdan o‘tsin desam?

`app.required_channel` ga kanal manzilini yozing (masalan `'@andijon_ai_talents'`) yoki panelning
**Sozlamalar** sahifasidan kiriting. Shart: **bot o‘sha kanalda administrator** bo‘lishi kerak,
aks holda obunani tekshira olmaydi. O‘chirish uchun qiymatni bo‘sh qoldiring (`null`).

### 20. Sozlamani `config.php` da o‘zgartirdim, lekin bot eskicha ishlayapti

`registration_open`, `required_channel`, `ask_language` va `welcome_extra` sozlamalari avval
**bazadagi `settings` jadvalidan** o‘qiladi va faqat u yerda qiymat bo‘lmasa `config.php` ga
murojaat qilinadi. Ya’ni panel yoki bot orqali bir marta o‘zgartirilgan sozlama config’dan
ustun turadi. Yechim: qiymatni **admin panel → Sozlamalar** sahifasidan o‘zgartiring.

---

## 🔐 Admin panel

### 21. Panel parolini unutdim — qanday tiklayman?

Parol bazada emas, `config.php` ichida **xesh** ko‘rinishida saqlanadi, shuning uchun uni “eslab”
bo‘lmaydi — yangisini yaratasiz:

```bash
php cli.php admin:hash 'YangiKuchliParol123!'
```

Buyruq `$2y$...` bilan boshlanadigan satr chiqaradi. Uni `config.php` ga ko‘chiring:

```php
'admin_panel' => [
    'username'      => 'admin',
    'password_hash' => '$2y$10$....',
],
```

Terminal bo‘lmasa, xuddi shu ishni `setup.php?key=...` sahifasidagi **“Parol xeshini yaratish”**
paneli bajaradi.

### 22. “Login yoki parol noto‘g‘ri” — hammasi to‘g‘ri yozilgan bo‘lsa ham

- `password_hash` **to‘liq** ko‘chirilganmi? Satr uzun, oxiri kesilib qolmasin.
- Xesh **bitta tirnoq** ichida bo‘lsin: `'password_hash'  => '$2y$12$...'`, ortiqcha bo‘sh
  joysiz. Qo‘shtirnoq ichida yozmang — PHP `$2y`, `$12` kabi bo‘laklarni o‘zgaruvchi deb
  o‘qiydi va xeshning boshini jimgina yeb qo‘yadi, natijada to‘g‘ri parol ham qabul
  qilinmaydi.
- Parolda `$` belgisi bo‘lsa, `admin:hash` buyrug‘ida parolni **bitta qo‘shtirnoq** ichida yozing.
- `security.admin_panel.enabled` qiymati `true` ekanini tekshiring.
- Xabar ataylab umumiy: qaysi maydon xato ekanini ko‘rsatmaydi (xavfsizlik talabi).

### 23. “Juda ko‘p urinish. N daqiqadan so‘ng qayta urinib ko‘ring”

Bu himoya chorasi: bitta IP’dan **5 marta** noto‘g‘ri kirishdan so‘ng **15 daqiqaga** bloklanadi
(`security.admin_panel.max_attempts` va `lockout_seconds`). Kutish yoki blokni bekor qilish:
bazadagi `login_attempts` jadvalidan o‘sha IP yozuvlarini o‘chiring, yoki `config.php` da
`max_attempts` ni vaqtincha oshiring.

### 24. Panel juda tez “chiqib ketyapti” (sessiya tugaydi)

Standart sessiya muddati **7200 soniya (2 soat)**. Uzaytirish:

```php
'admin_panel' => [ 'session_lifetime' => 28800, ... ],   // 8 soat
```

Sessiya har 15 daqiqada yangilanadi (bu xavfsizlik uchun, sizga sezilmaydi). Umumiy kompyuterda
ishlayotgan bo‘lsangiz muddatni oshirmang — ish tugagach **Chiqish** tugmasini bosing.

### 25. Formani yuborsam “419” yoki “Sessiya eskirgan” chiqyapti

Bu CSRF himoyasi: sahifa uzoq vaqt ochiq turgan yoki brauzer cookie’ni o‘chirgan. Sahifani
yangilang (F5) va amalni qayta bajaring. Doimiy takrorlansa: brauzerda cookie ruxsat etilganini
va serverda sessiya papkasi yozuvga ochiqligini tekshiring.

---

## 🗄 Ma’lumotlar

### 26. Eksport fayli ochilmayapti yoki g‘alati ko‘rinyapti

Eksport **CSV emas, XLSX (Excel 2007+)** formatida. `.xlsx` ichida matn doim UTF-8 saqlanadi,
shuning uchun kodlash muammosi bo‘lmasligi kerak — fayl Excel, LibreOffice Calc va Google
Sheets’da hech qanday import sozlamasisiz ochiladi.

| Belgisi | Sabab | Yechim |
|---|---|---|
| Excel “format mos emas” yoki “faylni tiklaymizmi?” deydi | Yuklab olish yarim uzilgan (proxy, antivirus, sekin internet) | Faylni qayta yuklab oling va hajmini tekshiring |
| Harflar o‘rniga `Ð, Ñ, â€™` | Faylni CSV sifatida qayta saqlagansiz | XLSX faylning o‘zini oching |
| Telefon raqami `9,98901E+11` ko‘rinishida | Raqam **son** sifatida o‘qilgan | Bunday bo‘lmasligi kerak: eksporter telefon va Telegram ID ni **matn** sifatida yozadi. CSV’ga o‘tkazgan bo‘lsangiz, import paytida ustunni **Text** deb belgilang |
| Serverda `zip` kengaytmasi yo‘q | Sof-PHP ZIP yo‘li ishlagan | Normal holat — fayl baribir yaroqli |

Fayl haqiqatan buzuqmi degan shubha bo‘lsa, uni oddiy arxiv dasturi bilan ochib ko‘ring:
`.xlsx` — bu ZIP arxiv, ichida `xl/worksheets/sheet1.xml` bo‘lishi kerak.

### 27. SQLite’dan MySQL’ga qanday o‘taman?

1. **Zaxira oling:** `data/aitalents.sqlite` faylini va XLSX eksportni saqlab qo‘ying
   (`php cli.php export data/backup.xlsx`).
2. cPanel’da yangi MySQL bazasi va foydalanuvchi yarating, `ALL PRIVILEGES` bering.
3. `config.php` da `database.driver` ni `mysql` ga o‘zgartirib, `host/database/username/password`
   ni to‘ldiring.
4. Jadvallarni yarating: `php cli.php migrate` (yoki `setup.php` dagi tugma).
5. Eski ma’lumotlarni ko‘chirish kerak bo‘lsa, SQLite’dan `phpLiteAdmin`/`DB Browser for SQLite`
   orqali `INSERT` eksport qilib, phpMyAdmin’da import qiling. Jadval nomlari bir xil bo‘ladi.
6. Botga `/start` yozib tekshiring, panelda arizalar ko‘rinishini tasdiqlang.

> ℹ️ Ko‘chirish paytida ro‘yxatni **yopib** turing («Ro‘yxatni vaqtincha yopmoqchiman» savoli) — shunda ma’lumot yo‘qolmaydi.

### 28. Zaxira nusxa (backup) qanday olinadi?

| Nima | Qanday |
|------|--------|
| Arizalar (tez, kundalik) | Panel → **Eksport** yoki `php cli.php export data/backup-2026-01-31.xlsx` |
| To‘liq baza (SQLite) | `data/aitalents.sqlite` faylini yuklab olish |
| To‘liq baza (MySQL) | cPanel → **phpMyAdmin → Export → SQL**, yoki **Backup Wizard** |
| Sozlamalar | `config.php` faylining nusxasi (xavfsiz joyda saqlang — ichida token bor!) |

Tavsiya: haftada bir marta XLSX eksport, oyda bir marta to‘liq baza dump’i. Zaxirani serverning o‘zida
emas, alohida joyda saqlang.

### 29. Arizani xato o‘chirib yubordim, tiklash mumkinmi?

Yo‘q. **O‘chirish qaytarilmaydi** — yozuv bazadan butunlay olib tashlanadi. Faqat ikki imkoniyat
qoladi: oxirgi zaxira nusxadan tiklash yoki foydalanuvchidan `/start` orqali qaytadan ro‘yxatdan
o‘tishni so‘rash. Shu sababli “rad etish” (status: `rejected`) odatda o‘chirishdan afzal — yozuv
saqlanib qoladi. Kim o‘chirganini **Audit** sahifasidan ko‘rish mumkin.

---

## 📣 Xabar yuborish (broadcast)

### 30. Ommaviy xabar juda sekin ketyapti

Bu normal. Telegram cheklovi tufayli bot sekundiga ~20 ta xabar yuboradi (har yuborish orasida
qisqa pauza bor). 5 000 kishiga ~5 daqiqa ketadi. Tezlashtirishga urinish `429 Too Many Requests`
va vaqtinchalik blokga olib keladi — sozlamani o‘zgartirmang.

Panelda broadcast **fon rejimida, bo‘lak-bo‘lak** yuboriladi va progress paneli yangilanib turadi.
Sahifani yopib qo‘ysangiz jarayon to‘xtaydi — davom ettirish uchun uni qaytadan ochib
**“Davom ettirish”** ni bosing yoki cron’ni yoqing (quyidagi cron savoli).

### 31. Ba’zi foydalanuvchilarga xabar bormadi (`failed`)

Bu odatiy hol. Asosiy sabablar:

| Sabab | Izoh |
|-------|------|
| Foydalanuvchi botni bloklagan | Yozuv `is_blocked = 1` deb belgilanadi va keyingi tarqatishlarga kirmaydi |
| Akkaunt o‘chirilgan | “user is deactivated” |
| Chat topilmadi | Foydalanuvchi hech qachon `/start` bosmagan |
| HTML xatosi | Matndagi teg noto‘g‘ri yopilgan — matnni soddalashtiring va qayta yuboring |

Yakuniy hisobotda `sent` va `failed` sonlari ko‘rsatiladi; xato sababi har bir qabul qiluvchi uchun
alohida saqlanadi. Yuborishdan oldin **“Menga test yuborish”** tugmasidan foydalaning.

### 32. Broadcast uchun cron qanday sozlanadi?

Katta tarqatishlarni brauzerga bog‘lamaslik uchun cron ishlating. cPanel → **Cron Jobs** →
har 5 daqiqada:

```bash
cd /home/USER/public_html/bot && /usr/local/bin/php cli.php broadcast:run >/dev/null 2>&1
```

`broadcast:run` navbatdagi `running` holatdagi tarqatishni topib, keyingi bo‘lakni yuboradi.
Aniq bittasini yuborish kerak bo‘lsa: `php cli.php broadcast:run 12`.
Loglarni tozalash uchun sutkada bir marta: `php cli.php cleanup`.

### 33. Broadcast’ni to‘xtatsam nima bo‘ladi?

To‘xtatilganda (`paused`) yuborilgan xabarlar **qaytarib olinmaydi** — Telegram’da o‘chirish
imkoni yo‘q. Qolgan qabul qiluvchilar navbatda `pending` holatida turadi va davom ettirilganda
xuddi o‘sha joydan yuborish tiklanadi. Ya’ni hech kim xabarni **ikki marta** olmaydi.
Bekor qilingan tarqatish esa `broadcasts` ro‘yxatida hisoblagichlari bilan saqlanib qoladi.

---

## 🛡 Xavfsizlik

### 34. `setup.php` ni o‘chirish kerakmi?

**Ha, o‘rnatish tugagach o‘chiring.** U kalitsiz hech narsa qilmasa ham, serverda ortiqcha
kirish nuqtasi qoldirmagan ma’qul. Keyinchalik kerak bo‘lsa faylni qayta yuklaysiz.

O‘chira olmasangiz, hech bo‘lmaganda `security.setup_key` ni uzun tasodifiy satrga almashtiring
va uni hech kimga bermang.

### 35. Token yoki panel paroli sizib chiqqan bo‘lsa nima qilaman?

1. BotFather’da `/revoke` → yangi token → `config.php` ga yozing.
2. `telegram.webhook_secret` ni yangilang.
3. Webhook’ni qayta o‘rnating: `php cli.php webhook:set https://domen.uz/bot/index.php`.
4. Panel uchun yangi parol xeshi yarating: `php cli.php admin:hash '...'`.
5. `security.setup_key` ni almashtiring.
6. **Audit** sahifasidan begona amallar bo‘lmaganini tekshiring.

### 36. `.htaccess` ishlamayapti — `config.php` brauzerda ochilyapti

Darhol harakat qiling:

- Hosting Apache’da ishlayotganiga ishonch hosil qiling. Nginx’da `.htaccess` **umuman
  o‘qilmaydi** — himoyani nginx konfiguratsiyasida yozish kerak (hosting egasiga murojaat qiling).
- Apache’da `AllowOverride All` yoqilganmi — support’dan so‘rang.
- Vaqtinchalik chora sifatida `config.php` ni web papkadan **tashqariga** ko‘chirib, `bootstrap.php`
  dagi yo‘lni o‘zgartirish mumkin (dasturchi yordami bilan).
- Test: `https://domen.uz/bot/config.php` va `https://domen.uz/bot/data/aitalents.sqlite` manzillari
  `403 Forbidden` qaytarishi kerak. Agar kod matni ko‘rinsa — bu jiddiy xavf, tokenni darhol
  almashtiring («Token yoki panel paroli sizib chiqqan bo‘lsa» savoli).

### 37. Loglarda shaxsiy ma’lumot bormi, ular qancha saqlanadi?

Loglar `data/logs/bot-YYYY-MM-DD.log` ko‘rinishida kunlik yoziladi va standart holatda oxirgi
**14 kunlik** fayl saqlanadi (`log.max_files`). Ular texnik ma’lumot (telegram id, xato matni)
saqlaydi, shuning uchun `data/` papkasi `.htaccess` bilan yopilgan. Kerak bo‘lmasa
`log.level` ni `warning` ga qo‘ying yoki `log.enabled` ni `false` qiling. Eski fayllarni
`php cli.php cleanup` yoki panelning **Sozlamalar → “Loglarni tozalash”** tugmasi o‘chiradi.

---

## 🆘 Yordam so‘rashdan oldin tayyorlab qo‘ying

Muammo hal bo‘lmasa, texnik mutaxassisga murojaat qilishda quyidagilarni yig‘ing:

1. `php cli.php webhook:info` natijasi (**tokensiz!**).
2. `data/logs/bot-YYYY-MM-DD.log` faylining oxirgi 50 qatori.
3. PHP versiyasi (`php -v`) va baza turi (`sqlite` / `mysql`).
4. Xato aynan qaysi sahifada yoki qaysi qadamda chiqishi va skrinshot.

> 🔐 Skrinshot va loglarni yuborishdan oldin **token, parol va `setup_key`** ni albatta yashiring.
