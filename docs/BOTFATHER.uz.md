# 🤖 @BotFather orqali botni yaratish va sozlash

> **Andijon AI Talents** — Andijon viloyati hokimligining iqtidorli yoshlarni qidirish dasturi.
> _“Bugun iqtidorni kashf et, ertaga kelajakni yarat!”_
>
> Ushbu qo‘llanma dasturchi bo‘lmagan xodim uchun yozilgan. Bosqichlarni ketma-ket bajaring —
> boshidan oxirigacha taxminan **10–15 daqiqa** vaqt ketadi.

---

## 1. 🧰 Tayyorgarlik

Ishni boshlashdan oldin quyidagilar tayyor bo‘lsin:

- 📱 **Telegram akkaunt** (telefon raqami tasdiqlangan).
- 🌐 **HTTPS ishlaydigan domen** — masalan `https://aitalents.andijon.uz/bot`. Telegram webhook’ni
  faqat HTTPS manzilga yuboradi.
- 🗂 Hostingdagi loyiha papkasi (`config.php` shu yerda turadi).
- ✍️ Bot uchun **rasm** (kvadrat, kamida 512×512 px) va qisqa tavsif matni.

---

## 2. 🆕 `/newbot` — yangi bot yaratish

1. Telegram qidiruviga **@BotFather** deb yozing. To‘g‘ri hisob nomi yonida ✅ ko‘k belgi turadi.
2. `Start` ni bosing, so‘ng `/newbot` buyrug‘ini yuboring.
3. BotFather ketma-ket ikkita savol beradi:

### 2.1. Bot nomi (name)

Bu foydalanuvchi chat sarlavhasida ko‘radigan nom. Keyin ham `/setname` orqali o‘zgartirish mumkin.

| Qoida | Izoh |
|-------|------|
| Uzunligi | 64 belgigacha |
| Belgilar | O‘zbek harflari, bo‘sh joy va emoji ham mumkin |
| Tavsiya | `Andijon AI Talents` yoki `Andijon AI Talents 🤖` |

### 2.2. Bot username’i

Bu `@` bilan boshlanadigan, butun Telegram bo‘ylab **takrorlanmas** manzil. **Keyinchalik
o‘zgartirib bo‘lmaydi** — puxta o‘ylab tanlang.

| Qoida | Izoh |
|-------|------|
| Uzunligi | 5–32 belgi |
| Belgilar | Faqat lotin harflari, raqamlar va pastki chiziq `_` |
| Majburiy shart | Oxiri `bot` yoki `_bot` bilan tugashi kerak |
| Tavsiya | `AndijonAITalentsBot` |
| Zaxira variantlar | `AndijonAiTalents_bot`, `AITalentsAndijonBot`, `AndijonTalentsBot` |

> ⚠️ “Sorry, this username is already taken” degan javob kelsa — nom band. Zaxira variantni sinang.

Muvaffaqiyatli yakunlansa, BotFather sizga **tabrik xabari va token** yuboradi.

---

## 3. 🔑 Token (eng muhim qism)

Token quyidagi ko‘rinishda bo‘ladi:

```text
8123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw
```

**Token — bu botning paroli.** Uni bilgan odam bot nomidan istalgan xabar yubora oladi va barcha
arizalarni o‘qiy oladi.

### 3.1. Tokenni loyihaga yozish

Hostingdagi `config.php` faylini oching (agar hali yo‘q bo‘lsa, `config.example.php` dan nusxa
oling) va `telegram` bo‘limini to‘ldiring:

```php
'telegram' => [
    'token'          => '8123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw',
    'bot_username'   => 'AndijonAITalentsBot',   // @ belgisisiz!
    'webhook_secret' => 'uzun-tasodifiy-satr-32-belgi',
    'admin_ids'      => [123456789],             // 8-bo‘limga qarang
    'admin_chat_id'  => -1001234567890,          // 7-bo‘limga qarang
    'timeout'        => 20,
    'api_base'       => 'https://api.telegram.org',
],
```

Saqlagandan so‘ng tekshiring: brauzerda `https://sizning-domeningiz/bot/setup.php?key=SETUP_KEY`
manzilini oching va **“getMe” tekshiruvi** yashil bo‘lsa — token to‘g‘ri.
Terminal orqali ham tekshirsa bo‘ladi:

```bash
php cli.php webhook:info
```

### 3.2. ✅ Xavfsizlik qoidalari

- ❌ Tokenni **hech kimga** yubormang: chatda ham, skrinshotda ham, e-mailda ham.
- ❌ Tokenni `git` ga qo‘shmang. `config.php` allaqachon `.gitignore` da — uni ro‘yxatdan olib
  tashlamang. Tekshirish: `git status` da `config.php` ko‘rinmasligi kerak.
- ✅ Loyiha ildizidagi `.htaccess` `config.php` ga tashqaridan kirishni bloklaydi. Uni o‘chirmang.
- ✅ `config.php` fayl huquqi `644` (yoki `640`) bo‘lsin, `777` emas.
- ✅ Token boshqa birovga ko‘rinib qolgan bo‘lsa — darhol [`/revoke`](#9--revoke--tokenni-almashtirish).

---

## 4. 🪪 Botning “pasporti”: tavsif, about va rasm

BotFather’da `/mybots` → botingizni tanlang → `Edit Bot` bo‘limidan hammasi bitta joyda turadi.

### 4.1. `/setdescription` — botni ochishdan oldin ko‘rinadigan matn

Foydalanuvchi botni birinchi marta ochganda, `Start` tugmasi ustida shu matn chiqadi
(512 belgigacha). Tayyor namuna:

```text
Andijon AI Talents — Andijon viloyati hokimligining iqtidorli yoshlarni qidirish dasturi.
Bot orqali 3 daqiqada ro‘yxatdan o‘ting: ism-familiya, telefon, tug‘ilgan yil, tuman/shahar,
yo‘nalish (dasturlash, dizayn, sun’iy intellekt va boshqalar) va portfolio.
Bugun iqtidorni kashf et, ertaga kelajakni yarat! 🚀
```

### 4.2. `/setabouttext` — bot profilidagi qisqa matn

120 belgigacha. Bot profilida va ulashilganda ko‘rinadi:

```text
Andijon AI Talents — iqtidorli yoshlar uchun rasmiy ro‘yxatdan o‘tish boti. 🚀
```

### 4.3. `/setuserpic` — bot rasmi (avatar)

`/setuserpic` yuboring, botni tanlang va rasmni **fayl emas, oddiy rasm sifatida** yuboring.

- Kvadrat format, kamida 512×512 px.
- Loyihaning ko‘k-havorang uslubidagi logotip mos keladi.
- Matn kam bo‘lsin: avatar kichkina ko‘rinadi.

---

## 5. ⌨️ `/setcommands` — buyruqlar menyusi

> 💡 **Tezroq yo‘l:** bu bo‘limni qo‘lda bajarish shart emas. `setup.php?key=...` sahifasidagi
> **«Buyruqlar ro‘yxatini o‘rnatish»** tugmasi o‘zbekcha va ruscha ro‘yxatni bir bosishda
> botga yozib qo‘yadi (`setMyCommands`). Quyidagi qo‘lda sozlash esa BotFather’ni afzal
> ko‘rsangiz yoki matnlarni o‘zgartirmoqchi bo‘lsangiz kerak bo‘ladi.

Bu sozlama chat oynasidagi **☰ Menu** tugmasini to‘ldiradi. `/setcommands` → botni tanlang →
quyidagi bloklardan birini **to‘liq nusxalab** yuboring.

### 5.1. Tavsiya etiladigan (asosiy) ro‘yxat

```text
start - Botni ishga tushirish va ro‘yxatdan o‘tish
royxat - Ro‘yxatdan o‘tishni boshlash
profil - Mening arizam va ma’lumotlarim
til - Tilni o‘zgartirish (o‘zbekcha / ruscha)
loyiha - Loyiha haqida ma’lumot
yordam - Yordam va ko‘rsatmalar
bekor - Joriy amalni bekor qilish
```

### 5.2. To‘liq ro‘yxat (inglizcha muqobillari bilan)

Bot `royxat`/`register`, `profil`/`profile`, `til`/`language`, `loyiha`/`about`,
`yordam`/`help`, `bekor`/`cancel` juftliklarini bir xil qabul qiladi. Menyuni to‘liq
ko‘rsatmoqchi bo‘lsangiz:

```text
start - Botni ishga tushirish va ro‘yxatdan o‘tish
royxat - Ro‘yxatdan o‘tishni boshlash
register - Start registration
profil - Mening arizam va ma’lumotlarim
profile - My application
til - Tilni o‘zgartirish (o‘zbekcha / ruscha)
language - Change language
loyiha - Loyiha haqida ma’lumot
about - About the project
yordam - Yordam va ko‘rsatmalar
help - Help
bekor - Joriy amalni bekor qilish
cancel - Cancel
```

### 5.3. Admin buyruqlari — ro‘yxatga QO‘SHMANG

Quyidagi buyruqlar faqat `telegram.admin_ids` ichidagi shaxslarda ishlaydi. Ular ommaviy
menyuda ko‘rinmasligi kerak (aks holda har bir foydalanuvchi ularni bosib ko‘radi):

| Buyruq | Vazifasi |
|--------|----------|
| `/admin` | Admin menyusi (statistika, qidiruv, Excel eksport, xabar yuborish, ro‘yxatni ochish/yopish) |
| `/stats` | Qisqa statistika |
| `/export` | Arizalarni Excel (XLSX) fayl ko‘rinishida olish |
| `/broadcast` | Ommaviy xabar yuborishni boshlash |
| `/panel` | Web admin panel havolasi |

> 💡 Ular menyuda ko‘rinmasa ham, adminlar buyruqni **qo‘lda yozib** yuborsa ishlayveradi.

### 5.4. Buyruq nomlariga qo‘yiladigan talablar

- Faqat **kichik lotin harflari, raqamlar va `_`**; 1–32 belgi.
- `/` belgisini yozmang — BotFather uni o‘zi qo‘shadi.
- Format qat’iy: `buyruq - tavsif` (chiziqcha atrofida bo‘sh joy shart).
- Buyruq nomida `‘` yoki `’` ishlatib bo‘lmaydi: shuning uchun `royxat` (`ro‘yxat` emas).

---

## 6. 🔒 `/setjoingroups` va `/setprivacy`

Bu bot **shaxsiy chatda** ishlaydi: guruhlarda u faqat yangi arizalar kartochkasini yuborish uchun
kerak. Shuning uchun:

| Buyruq | Tanlang | Nima uchun |
|--------|---------|------------|
| `/setjoingroups` | **Disable** | Begonalar botni o‘z guruhlariga qo‘sha olmaydi |
| `/setprivacy` | **Enable** | Bot guruhdagi barcha yozishmalarni o‘qimaydi, faqat unga qaratilgan buyruqlarni ko‘radi |

> ⚠️ Agar arizalar tushadigan guruh kerak bo‘lsa: avval `/setjoingroups` ni **Enable** qiling,
> botni guruhga qo‘shing, `admin_chat_id` ni aniqlang — **so‘ng yana Disable** ga qaytaring.
> Bot allaqachon guruh a’zosi bo‘lgani uchun u yerdan chiqib ketmaydi.

---

## 7. 👥 Yangi arizalar tushadigan guruh (`admin_chat_id`)

Har bir yangi ariza tanlangan guruhga chiroyli kartochka ko‘rinishida, **“✅ Tasdiqlash” va
“❌ Rad etish”** tugmalari bilan tushadi. Sozlash tartibi:

1. Telegram’da yangi **guruh** yarating, masalan “AI Talents — Arizalar”.
2. Hokimlik mas’ul xodimlarini qo‘shing.
3. Botingizni (`@AndijonAITalentsBot`) guruhga qo‘shing.
4. Guruh sozlamalarida botni **administrator** qiling. Kamida `Xabar yuborish` huquqi bo‘lsin.

### 7.1. `admin_chat_id` ni qanday bilish

**A-usul (eng oson):** guruhga vaqtincha `@getidsbot` yoki `@RawDataBot` ni qo‘shing. U darhol
`Chat ID: -1001234567890` deb javob beradi. ID ni yozib oling va yordamchi botni guruhdan chiqaring.

**B-usul (webhook hali o‘rnatilmagan bo‘lsa):** guruhga botingizdan istalgan xabar yozing, so‘ng
brauzerda oching:

```text
https://api.telegram.org/bot<TOKEN>/getUpdates
```

Javobdagi `"chat":{"id":-1001234567890,...}` — sizga kerakli raqam.
Webhook allaqachon o‘rnatilgan bo‘lsa, `getUpdates` bo‘sh qaytadi; avval `php cli.php webhook:delete`,
so‘ng tekshiruvdan keyin `php cli.php webhook:set` qiling.

### 7.2. `config.php` ga yozish

```php
'admin_chat_id' => -1001234567890,   // qo‘shtirnoqsiz, minus belgisi bilan
```

> ℹ️ Supergruh ID’lari doim `-100` bilan boshlanadi va manfiy bo‘ladi. Guruh kerak bo‘lmasa,
> `'admin_chat_id' => null` qoldiring — kartochkalar `admin_ids` dagi shaxslarga tushaveradi.

---

## 8. 🆔 Telegram user id ni qanday bilish (`admin_ids`)

`telegram.admin_ids` — bu **shaxsiy** Telegram ID raqamlari ro‘yxati (username emas!). Bot
statistikasi, Excel eksporti va tasdiqlash tugmalari faqat shu ro‘yxatdagilarga ochiladi.

**1-usul — @userinfobot.** Telegram’da `@userinfobot` ni oching, `Start` bosing. U darhol
`Id: 123456789` deb javob beradi. Boshqa odamning ID sini bilish uchun uning istalgan xabarini
shu botga **forward** qiling.

**2-usul — botning o‘z loglari.** Kerakli odam sizning botingizga `/start` yozsin, so‘ng hostingda
oching: `data/logs/bot-YYYY-MM-DD.log`. Yozuvlarda `telegram_id` maydoni ko‘rinadi.

**3-usul — admin panel.** Panelga kiring → **Foydalanuvchilar** sahifasi → ustunlar orasida
`telegram_id` turadi; ismi bo‘yicha qidirib topsangiz bo‘ladi.

Natijani `config.php` ga yozing:

```php
'admin_ids' => [123456789, 987654321],   // vergul bilan, qo‘shtirnoqsiz
```

> 💡 Panelning **Foydalanuvchilar** sahifasidagi “admin berish” tugmasi ham bor — u `users.is_admin`
> ustunini o‘zgartiradi va `config.php` ga tegmaydi. Asosiy (bosh) adminlarni baribir `admin_ids`
> ichida saqlang: baza tiklanmay qolgan holatda ham ular botni boshqara oladi.

---

## 9. ♻️ `/revoke` — tokenni almashtirish

Token sizib chiqqan bo‘lsa (skrinshotga tushdi, git’ga ketdi, xodim ishdan bo‘shadi) —
kechiktirmang.

1. BotFather’ga `/revoke` yuboring → botni tanlang.
2. BotFather **yangi token** beradi, eskisi shu zahoti ishlamay qoladi.
3. `config.php` dagi `telegram.token` ni yangisiga almashtiring.
4. Webhook’ni qayta o‘rnating — u eski tokenga bog‘langan edi:

```bash
php cli.php webhook:set https://sizning-domeningiz/bot/index.php
php cli.php webhook:info
```

5. Ayni paytda `telegram.webhook_secret` ni ham yangilang (yangi tasodifiy satr) va webhook’ni
   qaytadan o‘rnating.
6. Bot ishlayotganini tekshiring: o‘zingizga `/start` yozing.

> ⚠️ Token almashgach bot **hech qanday ma’lumotni yo‘qotmaydi** — barcha arizalar bazada qoladi.

---

## 10. 📋 BotFather buyruqlari (shpargalka)

| Buyruq | Vazifasi |
|--------|----------|
| `/newbot` | Yangi bot yaratish |
| `/mybots` | Botlar ro‘yxati va barcha sozlamalar menyusi |
| `/token` | Amaldagi tokenni qayta ko‘rsatish |
| `/revoke` | Tokenni bekor qilib, yangisini olish |
| `/setname` | Bot nomini o‘zgartirish |
| `/setdescription` | Kirish matni (512 belgi) |
| `/setabouttext` | Profil matni (120 belgi) |
| `/setuserpic` | Avatar rasmi |
| `/setcommands` | Buyruqlar menyusi |
| `/setjoingroups` | Botni guruhlarga qo‘shishga ruxsat / taqiq |
| `/setprivacy` | Guruhdagi xabarlarni o‘qish rejimi |
| `/deletebot` | Botni butunlay o‘chirish (qaytarib bo‘lmaydi ⚠️) |

---

## ✅ Yakuniy tekshiruv ro‘yxati

- [ ] Bot yaratildi, username yozib olindi
- [ ] Token `config.php` ga yozildi, git’ga tushmadi
- [ ] `bot_username` `@` belgisisiz yozildi
- [ ] Tavsif, about matni va avatar o‘rnatildi
- [ ] `/setcommands` bloki yuborildi, chatda ☰ menyu ko‘rinyapti
- [ ] `/setjoingroups` → Disable, `/setprivacy` → Enable
- [ ] Arizalar guruhi tayyor, bot unda admin, `admin_chat_id` yozildi
- [ ] `admin_ids` to‘ldirildi va `/stats` buyrug‘i ishlab turibdi
- [ ] `php cli.php webhook:info` da `url` to‘g‘ri, `last_error_message` bo‘sh
- [ ] Sinov ro‘yxatdan o‘tish bajarildi, ariza guruhga tushdi

> Muammo chiqsa — [`docs/FAQ.uz.md`](FAQ.uz.md) faylidagi savol-javoblarga qarang.
