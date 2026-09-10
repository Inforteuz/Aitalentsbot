# 🖥 Admin panel qo‘llanmasi

> **Andijon AI Talents** — arizalarni qabul qilish va ko‘rib chiqish tizimi.
> _“Bugun iqtidorni kashf et, ertaga kelajakni yarat!”_
>
> Ushbu qo‘llanma **hokimlik operatori** uchun yozilgan. Dasturlash bilimi kerak emas: har bir
> sahifa, har bir tugma va nimaga ehtiyot bo‘lish kerakligi oddiy tilda tushuntirilgan.

---

## 1. 🔑 Kirish (Login)

Panel manzili: `https://sizning-domeningiz/bot/admin/`

1. **Login** va **Parol** ni kiriting (ularni tizimni o‘rnatgan mutaxassis beradi).
2. **Kirish** tugmasini bosing.

| Holat | Nima bo‘ladi |
|-------|--------------|
| Login yoki parol xato | “Login yoki parol noto‘g‘ri” chiqadi. Xavfsizlik uchun tizim qaysi maydon xato ekanini aytmaydi |
| 5 marta xato kiritish | O‘sha kompyuter (IP) **15 daqiqaga** bloklanadi — kutishdan boshqa yo‘l yo‘q |
| 2 soat harakatsizlik | Sessiya tugaydi, qaytadan kirish so‘raladi |

**Xavfsizlik qoidalari:**
- 🔒 Parolni hech kim bilan bo‘lishmang, brauzerning umumiy kompyuterdagi “parolni saqlash”
  taklifiga rozi bo‘lmang.
- 🚪 Ish tugagach yuqori o‘ng burchakdagi menyudan **Chiqish** ni bosing.
- 📵 Panel havolasini ochiq guruhlarga tashlamang.
- Parol unutilsa — [`FAQ.uz.md`](FAQ.uz.md) dagi “Panel parolini unutdim” savoliga qarang.

---

## 2. 🧭 Panel bo‘ylab harakatlanish

Chap tomonda doimiy menyu turadi. Telefonda ekran torayganda menyu **☰** tugmasi ostiga yig‘iladi.

| Menyu | Vazifasi |
|-------|----------|
| 📊 **Boshqaruv paneli** | Umumiy ko‘rsatkichlar va grafiklar |
| 🗂 **Arizalar** | Barcha arizalar, qidiruv va filtrlar |
| 👥 **Foydalanuvchilar** | Botga yozgan barcha odamlar |
| 📣 **Xabar yuborish** | Ommaviy xabar (broadcast) tayyorlash |
| 📜 **Tarqatishlar** | Yuborilgan xabarlar tarixi |
| ⚙️ **Sozlamalar** | Ro‘yxatni ochish/yopish va bot sozlamalari |
| 🧾 **Loglar** | Texnik yozuvlar (mutaxassis uchun) |
| 🕵️ **Audit** | Panelda kim nima qilgani |
| 📥 **Eksport** | Arizalarni CSV faylga chiqarish |

Har bir amaldan so‘ng ekranning yuqorisida rangli xabar (toast) chiqadi: yashil — bajarildi,
qizil — xatolik.

---

## 3. 📊 Boshqaruv paneli (Dashboard)

Kirgach birinchi ochiladigan sahifa. Yuqorida **6 ta ko‘rsatkich kartasi**:

| Karta | Nimani ko‘rsatadi |
|-------|-------------------|
| Jami foydalanuvchi | Botni ishga tushirgan barcha odamlar soni |
| Ro‘yxatdan o‘tganlar | Arizani **oxirigacha** to‘ldirganlar |
| Bugun | Bugun tushgan yangi arizalar |
| Bu hafta | So‘nggi 7 kundagi arizalar |
| ⏳ Kutilmoqda | Hali ko‘rib chiqilmagan arizalar — **kundalik ishingiz shu yerdan boshlanadi** |
| ✅ Tasdiqlangan | Ma’qullangan arizalar |

Pastda uchta grafik:

- **14 kunlik dinamika** — kunlar bo‘yicha ariza oqimi. Tashviqot (e’lon, targ‘ibot) qachon
  samara berganini shu grafikdan ko‘rasiz.
- **Yo‘nalishlar bo‘yicha** — dasturlash, dizayn, sun’iy intellekt va boshqalar bo‘yicha taqsimot.
- **Tumanlar bo‘yicha** — qaysi tuman/shahardan qancha ariza kelgani. Kam ariza kelgan hududlarda
  qo‘shimcha targ‘ibot qilish kerakligini shu yerdan bilib olasiz.

Eng pastda **oxirgi 10 ta ariza** jadvali (bevosita bosib ochish mumkin) va **tezkor qidiruv**
oynasi — ism yoki telefon raqamini yozib, darhol topish uchun.

> 💡 “Jami foydalanuvchi” va “Ro‘yxatdan o‘tganlar” orasidagi farq — arizani boshlab, tugatmagan
> odamlar. Bu farq katta bo‘lsa, savollar ketma-ketligi murakkab kelayotgan bo‘lishi mumkin.

---

## 4. 🗂 Arizalar ro‘yxati

Asosiy ish maydoni. Yuqorida qidiruv va filtrlar paneli joylashgan.

### 4.1. Qidiruv va filtrlar

| Vosita | Izoh |
|--------|------|
| 🔎 **Qidiruv** | Ism-familiya, telefon, Telegram username yoki ariza raqami (`#id`) bo‘yicha |
| **Holat** | ⏳ Kutilmoqda / ✅ Tasdiqlangan / ❌ Rad etilgan |
| **Tuman/shahar** | Andijon viloyatining 17 hududi + “Boshqa hudud” |
| **Yo‘nalish** | Dasturlash, dizayn, AI/ML, robototexnika va h.k. |
| **Sana oralig‘i** | “dan” va “gacha” — masalan, bir oylik hisobot uchun |

Filtrni bekor qilish uchun **“Tozalash”** tugmasini bosing.

### 4.2. Saralash va sahifalash

- Ustun sarlavhasini (ID, Sana, Ism, Tuman, Holat) bosib, o‘sish/kamayish tartibida saralang.
- Pastda sahifalar raqami va **25 / 50 / 100** — bir sahifada nechta yozuv ko‘rinishini tanlash.

### 4.3. Qator amallari

Har bir qator oxirida tugmalar bor:

| Tugma | Amal |
|-------|------|
| 👁 **Ko‘rish** | Ariza tafsilotini ochadi |
| ✅ **Tasdiqlash** | Holatni “Tasdiqlangan” ga o‘zgartiradi |
| ❌ **Rad etish** | Holatni “Rad etilgan” ga o‘zgartiradi (yozuv saqlanib qoladi) |
| 🗑 **O‘chirish** | Arizani bazadan butunlay o‘chiradi — **qaytarib bo‘lmaydi** |

### 4.4. Ommaviy amallar

Chap tomondagi katakchalar orqali bir nechta arizani belgilab, ular ustida bir vaqtda
**tasdiqlash / rad etish / o‘chirish** amalini bajarish mumkin. Sarlavhadagi katakcha butun
sahifani belgilaydi.

> ⚠️ **Ehtiyot bo‘ling:** ommaviy o‘chirish tasdiqlash oynasini bir marta so‘raydi va shundan
> keyin yozuvlar butunlay yo‘qoladi. Shubha bo‘lsa, o‘chirish o‘rniga **rad etish** ni tanlang —
> statistika ham, tarix ham saqlanib qoladi.

### 4.5. CSV eksport

**“Joriy filtr bo‘yicha eksport”** tugmasi ekranda ko‘rinib turgan aynan o‘sha ro‘yxatni
CSV faylga chiqaradi. Masalan: “Asaka tumani + tasdiqlangan + sentabr oyi” — filtrni qo‘ying va
eksport qiling. Fayl Excel uchun tayyorlangan (`;` ajratkich, UTF-8).

---

## 5. 📄 Ariza tafsiloti

Bitta arizaning to‘liq kartochkasi: ism-familiya, telefon, tug‘ilgan yil, tuman/shahar, tanlangan
yo‘nalishlar, portfolio matni va havolalari, ariza raqami hamda kelib tushgan sana.

**Foydali havolalar:**
- 📞 Telefon raqami — bosilsa telefonda darhol qo‘ng‘iroq qilinadi.
- 💬 **Telegramda ochish** — nomzod bilan bevosita yozishish uchun.
- 🔗 Portfolio havolalari — yangi oynada ochiladi.

**Amallar:**

| Element | Vazifasi |
|---------|----------|
| **Holatni o‘zgartirish** | ⏳ / ✅ / ❌ ni tanlab, **Saqlash** ni bosing |
| **Izoh (admin note)** | Ichki qayd: “telefon o‘chiq”, “hujjatlari to‘liq emas” va h.k. Nomzodga ko‘rinmaydi |
| **Foydalanuvchiga xabar yuborish** | Matn yozib yuborsangiz, u **bot orqali** nomzodning Telegramiga boradi |
| **O‘chirish** | Arizani butunlay o‘chiradi (tasdiqlash so‘raladi) |
| **Audit izi** | Shu ariza bilan kim, qachon, nima qilgani ro‘yxati |

> ✉️ Xabar yuborishdan oldin matnni diqqat bilan o‘qing: yuborilgan xabarni **qaytarib olib
> bo‘lmaydi**. Rasmiy uslubda yozing va o‘zingizni tanishtiring.
>
> ℹ️ Nomzod botni bloklab qo‘ygan bo‘lsa, xabar bormaydi va sizga xatolik haqida bildirishnoma
> chiqadi — bunday holatda telefon orqali bog‘laning.

---

## 6. 👥 Foydalanuvchilar

Botga hech bo‘lmaganda bir marta yozgan barcha odamlar ro‘yxati (ariza to‘ldirmaganlar ham).

- **Filtrlar:** bloklangan / bloklanmagan, ro‘yxatdan o‘tgan / o‘tmagan, til (uz, ru).
- 🚫 **Bloklash** — foydalanuvchi botdan javob olmay qo‘yadi va ommaviy xabarlarga kirmaydi.
  Spam yoki haqoratli yozishmalarda ishlatiladi. Blokni istalgan vaqtda olib tashlash mumkin,
  ma’lumotlari yo‘qolmaydi.
- 🛡 **Admin qilish / adminlikni olib tashlash** — foydalanuvchiga botning admin buyruqlarini
  (`/admin`, `/stats`, `/export`) ochib beradi.
- 💬 **Chatni ochish** — nomzod bilan Telegramda yozishish.

> ⚠️ Adminlik huquqini faqat mas’ul xodimlarga bering: admin barcha arizalarni ko‘ra oladi va CSV
> yuklab olishi mumkin. `config.php` dagi bosh adminlarni bu sahifadan o‘chirib bo‘lmaydi.

---

## 7. 📣 Xabar yuborish (Broadcast)

Barcha nomzodlarga bir vaqtda xabar yuborish uchun (masalan: “Saralash bosqichi 20-oktabr kuni
bo‘lib o‘tadi”). Tartib:

1. **Matnni yozing.** Oynaning ostida belgilar hisoblagichi va ko‘rinish (preview) turadi.
   Qalin matn, havola va emoji ishlatish mumkin.
2. **Auditoriyani tanlang:**
   - barcha foydalanuvchilar,
   - faqat ro‘yxatdan o‘tganlar,
   - holat bo‘yicha (⏳ / ✅ / ❌),
   - tuman yoki yo‘nalish bo‘yicha.
   Tanlaganingizda **qabul qiluvchilar soni** darhol yangilanadi.
3. **“Menga test yuborish”** — xabar avval faqat sizga keladi. Matn, havola va tugmalarni
   tekshiring. **Bu qadamni hech qachon tashlab ketmang.**
4. **Yuborishni boshlash** — jarayon boshlanadi va progress paneli ko‘rinadi:
   `yuborildi / xato / qoldi` va foiz.
5. **Pauza / Davom ettirish** — jarayonni to‘xtatib turish yoki tiklash.
6. Yakunda **hisobot**: nechtasiga yetib bordi, nechtasi xato bilan qaytdi.

> ⚠️ **Muhim:**
> - Yuborilgan xabarni **orqaga qaytarib bo‘lmaydi**. Pauza faqat *qolgan* odamlarga yuborishni
>   to‘xtatadi; davom ettirilganda hech kim xabarni ikki marta olmaydi.
> - Jarayon davom etayotganda sahifani yopmang. Yopilib qolsa, **Tarqatishlar** sahifasidan
>   o‘shanisini ochib **Davom ettirish** ni bosing.
> - Yuborish sekin ketishi normal: Telegram cheklovi tufayli sekundiga ~20 ta xabar ketadi
>   (5 000 kishiga ~5 daqiqa).
> - Ba’zi xabarlar “xato” bo‘lishi tabiiy — odam botni bloklagan yoki akkauntini o‘chirgan.

**Tarqatishlar** sahifasida barcha eski xabarlar, ularning holati (`yuborilmoqda`, `to‘xtatilgan`,
`yakunlangan`) va hisoblagichlari saqlanadi.

---

## 8. ⚙️ Sozlamalar

| Sozlama | Izoh |
|---------|------|
| **Ro‘yxat ochiq / yopiq** | Eng ko‘p ishlatiladigan o‘tkich. Yopilganda yangi arizalar qabul qilinmaydi, mavjudlari saqlanadi |
| **Majburiy kanal** | Kanal manzili yozilsa, faqat obunachilar ro‘yxatdan o‘ta oladi (bot o‘sha kanalda admin bo‘lishi shart) |
| **Til so‘ralsinmi** | Bot ish boshida uz/ru tanlovini so‘raydimi |
| **Qo‘shimcha salomlashuv matni** | Botning birinchi xabariga qo‘shiladigan e’lon (masalan, muddat haqida) |
| **Bot adminlari** | `config.php` dan o‘qiladi, faqat ko‘rish uchun |
| **Webhook holati** | Bot Telegram bilan bog‘langanini ko‘rsatadi; **“Webhook’ni qayta o‘rnatish”** tugmasi bor |
| **Bot holati (getMe)** | Bot javob berayotganini tekshiradi — muammo bo‘lsa birinchi shu yerga qarang |
| **Baza ma’lumoti** | Baza turi va hajmi |
| **Loglarni tozalash** | Eski texnik yozuvlarni o‘chiradi |

---

## 9. 🧾 Loglar va 🕵️ Audit

- **Loglar** — botning texnik kundaligi. Sana tanlash, daraja (`info`, `warning`, `error`) bo‘yicha
  filtr va faylni yuklab olish tugmasi bor. Mutaxassisga murojaat qilganda shu faylning oxirgi
  qatorlarini yuboring.
- **Audit** — panelda **kim, qachon, nima** qilgani: kirish, tasdiqlash, o‘chirish, tarqatish.
  Ariza noto‘g‘ri o‘chirilgan yoki holat o‘zgargan bo‘lsa, javobgarni shu yerdan aniqlaysiz.
  Yozuvlarni tahrirlab yoki o‘chirib bo‘lmaydi.

---

## 10. ⛔ Qaytarib bo‘lmaydigan amallar

| Amal | Oqibati |
|------|---------|
| Arizani o‘chirish | Ma’lumot butunlay yo‘qoladi. Zaxira nusxadan tiklashdan boshqa yo‘l yo‘q |
| Ommaviy o‘chirish | Bir necha o‘nlab yozuv bir zumda yo‘qolishi mumkin |
| Ommaviy xabar yuborish | Yuborilgan xabar qaytarilmaydi |
| Foydalanuvchiga xabar yuborish | Xuddi shunday, qaytarilmaydi |
| Adminlik berish | Odam barcha shaxsiy ma’lumotlarni ko‘ra boshlaydi |

**Oltin qoida:** o‘chirish o‘rniga **rad etish** ni tanlang. Rad etilgan ariza statistikada qoladi
va kerak bo‘lsa qayta tasdiqlanadi.

---

## 11. 🗓 Kundalik ish tartibi (checklist)

### Har kuni (10–15 daqiqa)

- [ ] Panelga kiring, **⏳ Kutilmoqda** kartasidagi raqamga qarang
- [ ] **Arizalar** → Holat: `Kutilmoqda` filtrini qo‘ying
- [ ] Har bir arizani oching: ism, telefon, tuman va yo‘nalish to‘g‘ri to‘ldirilganini tekshiring
- [ ] ✅ Tasdiqlang yoki ❌ rad eting; rad etish sababini **izoh** maydoniga yozing
- [ ] Ma’lumoti chala arizalar bo‘yicha nomzodga xabar yuboring (aniqlashtirish so‘rovi)
- [ ] Kun oxirida **Kutilmoqda** raqami 0 ga yaqin bo‘lsin

### Har hafta

- [ ] **Eksport** → joriy hafta filtri bilan CSV yuklab oling va rasmiy papkaga saqlang
- [ ] Bazadan zaxira nusxa oling (yoki hosting avtomatik zaxirasini tekshiring)
- [ ] **Boshqaruv paneli** dagi tuman grafigiga qarang: ariza kam kelgan hududlarni belgilang
- [ ] **Foydalanuvchilar** → bloklanganlar ro‘yxatini ko‘rib chiqing
- [ ] Kerak bo‘lsa nomzodlarga eslatma xabari yuboring (test bilan!)

### Har oyda

- [ ] Yo‘nalishlar bo‘yicha hisobot tayyorlang (dashboard grafiklari + CSV)
- [ ] **Audit** sahifasini ko‘rib chiqing: begona amal yo‘qligiga ishonch hosil qiling
- [ ] Panel parolini yangilash zaruratini baholang
- [ ] Ro‘yxat muddati tugagan bo‘lsa — **Sozlamalar** dan ro‘yxatni yoping

---

## 12. 🆘 Yordam

- Texnik xatolar va ularning yechimi: [`FAQ.uz.md`](FAQ.uz.md)
- Botni Telegram tomonidan sozlash: [`BOTFATHER.uz.md`](BOTFATHER.uz.md)

Panel ochilmayapti yoki xato chiqayotgan bo‘lsa, mutaxassisga murojaat qilishdan oldin **xato
matnining skrinshotini** va **amal vaqtini** yozib qo‘ying — bu muammoni tez topishga yordam beradi.
