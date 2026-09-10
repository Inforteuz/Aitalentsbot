# Hissa qo'shish qo'llanmasi

Bu hujjat loyihaga kod yozadigan har bir dasturchi uchun. Uzun emas — bir marta o'qib chiqing,
keyin ishga kirishing.

## 1. PHP 8.1 — bu shart, tavsiya emas

Loyiha PHP 8.1 da ishlashi kerak. Sababi oddiy: deploy qilinadigan joy — oddiy shared hosting
(cPanel), u yerda PHP versiyasini biz tanlamaymiz va ko'p serverlarda hamon 8.1 turadi. Kod
8.2–8.4 da ham ogohlantirishsiz ishlashi kerak, lekin **8.1 o'qiy olmaydigan sintaksis
yozilmaydi**. Bemalol ishlating (8.0–8.1): `enum`, `readonly` xossalar, konstruktorda xossa
e'lon qilish, `match`, nomlangan argumentlar, `?->`, union tiplar, `never`, `array_is_list()`,
`$f(...)`, `final const`.

Ishlatib bo'lmaydi:

| Konstruksiya | Versiya | O'rniga |
| --- | --- | --- |
| `readonly class` | 8.2 | har bir xossani alohida `readonly` qiling |
| DNF tiplar `(A&B)\|null` | 8.2 | tipni soddalashtiring yoki interfeys ajrating |
| trait ichida `const` | 8.2 | konstantani klass yoki interfeysga chiqaring |
| `#[\Override]` | 8.3 | atributsiz yozing |
| `json_validate()` | 8.3 | `json_decode()` + `json_last_error()` |
| `mb_str_pad()` | 8.3 | `str_pad()` yoki qo'lda to'ldirish |
| `str_increment()` | 8.3 | `++$s` |
| `array_find()`, `array_any()`, `array_all()` | 8.4 | `foreach`, `array_filter()` |
| property hooks, asimmetrik ko'rinuvchanlik | 8.4 | oddiy getter/setter |

Yangi versiyalarda eskirgan (deprecated) narsalarni ham yozmang:

- yashirin nullable parametr: `string $x = null` emas, balki `?string $x = null`;
- e'lon qilinmagan (dinamik) xossalar — har bir xossa klass ichida yozilgan bo'lsin;
- `${}` ko'rinishidagi qator interpolyatsiyasi;
- `utf8_encode()` / `utf8_decode()`.

## 2. Composer ishlatilmaydi

`composer require` qilmang, `vendor/` papkasi bo'lmaydi. Faqat PHP ning o'zi va standart
kengaytmalar: `pdo`, `pdo_mysql`, `pdo_sqlite`, `curl`, `json`, `mbstring`. Avtoyuklash
`bootstrap.php` dagi kichkina `spl_autoload_register` orqali ishlaydi (`AiTalents\` → `src/`).
Biror kutubxona juda kerak bo'lib qolsa, avval issue oching — ko'p hollarda kerakli qismini
o'zimiz yuz qatorda yozamiz. Admin panelda ham tashqi CDN, shrift yoki JS kutubxona yo'q:
barcha CSS/JS/SVG loyihaning o'zida.

## 3. Kod uslubi

- Har bir fayl `<?php` va undan keyin `declare(strict_types=1);` bilan boshlanadi.
- Namespace `AiTalents\`, PSR-4 bo'yicha `src/` ga moslanadi:
  `src/Repository/UserRepository.php` → `AiTalents\Repository\UserRepository`.
- PSR-12 ga yaqin: 4 probel, klass va metodning `{` belgisi yangi qatorda, qator uzunligi
  120 belgigacha. `.editorconfig` buni muharriringizda o'zi sozlab beradi.
- Klasslar `final`, xossalar `private`; kengaytirish kerak bo'lganda ochamiz.
- Tiplar hamma joyda: parametr, qaytish qiymati va xossalarda.
- Foydalanuvchiga ko'rinadigan matn kodda yozilmaydi — u `lang/uz.php` va `lang/ru.php` da
  turadi, `Lang::t()` orqali olinadi.
- Xavfsizlik: Telegram HTML uchun `Text::esc()`, panel shablonlarida `e()`, SQL da faqat
  prepared statement, `ORDER BY` ga tushadigan ustun nomlari whitelist dan o'tadi.

## 4. Fayl tuzilmasi

```
bootstrap.php   avtoyuklash, config, App obyekti
index.php       Telegram webhook kirish nuqtasi
cli.php         konsol buyruqlari: migrate, webhook:set, poll, broadcast:run, export
setup.php       bir martalik o'rnatish sahifasi (maxfiy kalit bilan himoyalangan)
src/            asosiy kod — AiTalents\ (Telegram/, Repository/, Registration/,
                Service/, Admin/)
admin/          panel front-controller, shablonlar, assetlar
lang/           uz.php, ru.php
data/           baza va loglar (git ga tushmaydi)
tests/          run.php — bog'liqliksiz test harness
```

## 5. O'zgarish kiritishdan oldin

Har safar shu ikki buyruq:

```bash
# 1) Sintaksis — barcha PHP fayllar
find . -path ./.git -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

# 2) Testlar
php tests/run.php
```

Ikkalasi ham toza o'tishi shart. Test harness har bir tekshiruv uchun `PASS`/`FAIL` chiqaradi va
xato bo'lsa noldan farqli kod bilan tugaydi. U vaqtinchalik SQLite bazasi va `FakeTransport` bilan
ishlaydi: tarmoqqa chiqmaydi, haqiqiy botga ham tegmaydi. Yangi funksiya qo'shdingizmi —
`tests/run.php` ga unga mos tekshiruv ham qo'shing.

## 6. Commit xabarlari

Qisqa, buyruq shaklida, birinchi qator 72 belgidan oshmasin:

```
feat: ommaviy xabar paketlab yuboriladigan bo'ldi
fix: +998 siz kiritilgan telefon raqami normalizatsiya qilinmayotgan edi
refactor: StatsService dagi takroriy so'rovlar olib tashlandi
test: Validator::birthYear uchun chegara holatlari
docs: cPanel o'rnatish bo'limi yangilandi
```

Bitta commit — bitta mazmunli o'zgarish; formatlash bilan mantiqni aralashtirmang. Kerak bo'lsa
ikkinchi xatboshida **nega** shunday qilganingizni yozing: "nima qilingani" diff dan ko'rinadi,
"nega" esa ko'rinmaydi.

## 7. CI nimani tekshiradi

`.github/workflows/ci.yml` har `push` va har pull request da uchta ishni bajaradi:

1. **lint** — PHP 8.1, 8.2, 8.3 va 8.4 da loyihadagi barcha `.php` fayllar `php -l` dan
   o'tkaziladi. Birinchi xatoda to'xtaydi; `php -l` ning ogohlantirishi ham xato hisoblanadi.
2. **compat-8.1** — kod yuqoridagi jadvaldagi 8.2+ konstruksiyalari va yashirin nullable
   parametrlar uchun tekshiriladi. Topilsa, fayl nomi va qator raqami bilan ko'rsatiladi.
3. **tests** — `php tests/run.php` ishga tushadi.

CI qizil bo'lsa PR birlashtirilmaydi. Xatoni mahalliy takrorlash uchun 5-bo'limdagi ikki buyruq
kifoya.

Savol bo'lsa — issue oching yoki PR ga izoh qoldiring. O'rnatish va serverga chiqarish bo'yicha
qo'llanma: `README.md` va `docs/DEPLOY.uz.md`.
