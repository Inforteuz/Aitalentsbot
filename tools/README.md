# tools/ — demo ma'lumot generatori (seeder)

> **⚠️ OGOHLANTIRISH: `tools/seed.php` FAQAT DEVELOPMENT / DEMO UCHUN.**
> **Uni hech qachon real (ishlayotgan) bazada ishga tushirmang!** Skript bazaga yuzlab
> soxta foydalanuvchi, ariza, audit yozuvi va bitta soxta e'lon (broadcast) yozadi.
> Avval bazadan zaxira nusxa (backup) oling.

## Nima uchun kerak?

Yangi o'rnatilgan botda baza bo'sh bo'ladi: admin panel grafiklari tekis chiziq,
jadvallar bo'sh, filtrlarni sinab ko'rib bo'lmaydi. `seed.php` shu bo'shliqni
haqiqiyga o'xshash demo ma'lumot bilan to'ldiradi:

- o'zbekcha ism-familiyali (erkak/ayol) arizachilar;
- haqiqiy operator kodlari bilan `+998…` telefon raqamlari (90, 91, 93, 94, 95, 97, 98, 99, 88, 33);
- 14–30 yosh oralig'idagi tug'ilgan yillar;
- Andijon viloyati tuman/shaharlari (`Catalog::districtKeys()` dan olinadi);
- har bir arizachiga 1–3 ta yo'nalish (`Catalog::directionKeys()`);
- ba'zilarida o'zbekcha portfolio matni va GitHub / Telegram / Behance havolalari;
- holatlar taqsimoti: ~70% `pending`, ~22% `approved`, ~8% `rejected`;
- `created_at` sanalari oxirgi N kunga tabiiy egri chiziq bo'yicha tarqatiladi
  (ish kunlari ko'proq, dam olish kunlari kamroq, ikkita "spike" kuni bilan) —
  shuning uchun panel grafiklari jonli ko'rinadi;
- qo'shimcha: bir nechta `audit_log` yozuvi va bitta yakunlangan e'lon (broadcast)
  o'z maqsadlari (targets) bilan — bu sahifalar ham bo'sh qolmaydi.

## Ishga tushirish

Skript **faqat buyruqlar qatorida** ishlaydi (brauzerdan ochilsa, ishlashdan bosh tortadi).
Loyiha ildizidan turib:

```bash
php tools/seed.php                       # 120 ta ariza, oxirgi 45 kun
php tools/seed.php --count=400 --days=90 # ko'proq ma'lumot, kengroq oraliq
php tools/seed.php --fresh               # eski demo ma'lumotni almashtirish
php tools/seed.php --help                # yordam
```

Migratsiyalar avtomatik ishga tushadi, ya'ni toza o'rnatishda bitta buyruqning o'zi yetarli
(`config.php` fayli tayyor bo'lishi shart).

## Bayroqlar (flags)

| Bayroq | Ma'nosi | Standart |
| --- | --- | --- |
| `--count=N` | Nechta demo ariza yaratilsin (1…5000) | `120` |
| `--days=N` | `created_at` necha kunga tarqatilsin (1…365) | `45` |
| `--fresh` | Avval eski demo yozuvlarni o'chirish (faqat demo yozuvlar!) | o'chiq |
| `--seed=N` | Tasodifiylik urug'i — bir xil raqam = bir xil ma'lumot | `20240501` |
| `--help`, `-h` | Yordam matnini ko'rsatish | — |

## Xavfsizlik kafolatlari

Skript yaratgan **har bir yozuv belgilangan**, shuning uchun uni aniq qaytarib olish mumkin:

- `users.telegram_id` va `registrations.telegram_id` — `900000000 … 900999999` oralig'ida;
- `registrations.source` = `'seed'`;
- `audit_log.meta` va `broadcasts.filters` ichida `{"seed":true}` belgisi bor.

Shundan kelib chiqib:

1. Bazada **real** ma'lumot bo'lsa, skript ishlashdan **bosh tortadi** (`--fresh` berilmasa).
2. `--fresh` **faqat yuqoridagi belgiga ega qatorlarni** o'chiradi. Real qatorlarga tegmaydi.
3. Hech qachon jadval o'chirilmaydi (`DROP TABLE` yo'q), `TRUNCATE` ham qilinmaydi.
4. `tools/.htaccess` papkani veb orqali kirishdan yopadi; `seed.php` esa `PHP_SAPI !== 'cli'`
   bo'lsa darhol `exit(1)` qiladi.

Shunga qaramay: **bu himoyalar real bazada ishlatish uchun ruxsat emas.**

## Demo ma'lumotni o'chirish

```bash
php tools/seed.php --fresh --count=1
```

Bu eski demo yozuvlarni tozalaydi va o'rniga atigi 1 ta yozuv qoldiradi.
To'liq toza baza kerak bo'lsa (development'da), SQLite faylini o'chirib
(`data/aitalents.sqlite`), migratsiyani qayta ishga tushiring: `php cli.php migrate`.

## Muammolar

| Xato | Sababi / yechimi |
| --- | --- |
| `config.php was not found` | `config.example.php` dan `config.php` nusxasini yarating. |
| `The database already contains REAL data` | Bu real baza. To'xtang. Development nusxasi bo'lsa `--fresh` qo'shing. |
| `Demo data is already present` | Demo ma'lumot bor. `--fresh` bilan qayta yozing. |
| `SQLite connection failed` | `data/` papkasi yozish huquqiga ega emas (`chmod 775 data`). |
| `cannot be run over HTTP` | Skript brauzerdan ochilgan — terminaldan ishga tushiring. |

---

## English (short)

`tools/seed.php` fills the database with realistic Uzbek demo applicants so the admin panel
has something to show. **Development/demo only — never run it against a production database.**

```bash
php tools/seed.php [--count=120] [--days=45] [--fresh] [--seed=20240501] [--help]
```

It runs the migrations first, writes through `UserRepository`/`RegistrationRepository` when those
classes exist (falling back to direct SQL otherwise), backdates `created_at` over the last
`--days` days with a believable weekday/spike curve, and marks every row it creates
(telegram_id `900000000..900999999`, `registrations.source = 'seed'`, a `{"seed":true}` marker in
`audit_log.meta` and `broadcasts.filters`). `--fresh` deletes only those marked rows — real data is
never touched and no table is ever dropped. Output is deterministic for a given `--seed`.
