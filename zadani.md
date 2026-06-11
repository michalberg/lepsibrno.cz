# BRNOFLIX — Zadání pro Claude Code

## Přehled

Jednoduchá jednostránková webová aplikace pro volební předplatné kampaně Lepší Brno (Strana zelených, komunální volby říjen 2026). Uživatel si vybere výši měsíčního příspěvku, vyplní osobní údaje, a po odeslání obdrží platební instrukce pro nastavení trvalého příkazu v bance.

---

## Technické požadavky

- Čistý HTML/CSS/JS, nebo React — dle preferencí vývojáře
- Plně responzivní (mobile first)
- Barvy a styl konzistentní s Lepší Brno / Strana zelených (zelená, bílá)
- Hosting: vlastní — doména/subdoména bude upřesněna
- Po odeslání formuláře se volá externí API, které vrátí číslo účtu a variabilní symbol (endpoint bude doplněn — zatím mock)

---

## Struktura stránky

### 1. HERO

**Headline (h1):**
> Přes léto stejně Netflix moc nesleduješ.
> Předplať si místo něj Lepší Brno.

**Subheadline:**
> Nastav si trvalý příkaz do říjnových voleb. Zbývá [X měsíců]. *(počítá se dynamicky: počet celých měsíců od dnešního data do října 2026)*

---

### 2. MATCHING BANNER

Výrazný barevný blok (červená nebo kontrastní zelená), hned pod hero.

Text:
> **Pouze v červnu:** Jeden z velkých podporovatelů kampaně vložil 100 000 Kč. Každá první platba se zdvojnásobí — dokud fond nevyschne.

Progress bar pod textem:
> Zbývá k rozdělení: [████████░░] XX XXX Kč z 100 000 Kč

**Poznámka:** Hodnota progress baru bude zatím statická (hardcoded), aktualizace manuálně nebo přes proměnnou — upřesnit při nasazení.

---

### 3. TIER SELECTOR

Tři volby zobrazené jako karty / tlačítka. **Defaultně předvybráno: Netflix (299 Kč).**

#### Tier 1 — Voyo
- Částka: **149 Kč / měsíc**
- Celkem za kampaň: dynamicky vypočítáno (149 × počet zbývajících měsíců)
- Tangibility: **= 250 výtisků volebních novin do brněnských schránek**

#### Tier 2 — Netflix
- Částka: **299 Kč / měsíc**
- Celkem za kampaň: dynamicky vypočítáno (299 × počet zbývajících měsíců)
- Tangibility: **= 17 plakátů vylepených po měsíc v ulicích Brna**

#### Tier 3 — Vlastní částka
- Volné textové pole pro zadání libovolné částky v Kč
- Bez tangibility textu

**Výpočet počtu měsíců:**
- Aktuální datum → počet celých měsíců do konce října 2026
- Příklad: spuštění 1. června 2026 = 5 měsíců

---

### 4. FORMULÁŘ

Nadpis nad formulářem:
> Vyplň své údaje — platební instrukce obdržíš hned po odeslání.

**Pole formuláře (všechna povinná):**
- Jméno
- Příjmení
- Datum narození (formát DD.MM.RRRR)
- Telefon
- E-mail
- Ulice a číslo popisné
- Město
- PSČ

**Tlačítko odeslání:**
> Předplatit [vybraná částka] Kč →
*(částka se mění dynamicky podle vybraného tieru)*

**GDPR text pod tlačítkem** (přesné znění, beze změn):

> Strana zelených (IČ 00409740) zpracovává osobní údaje dárců na základě zákonné povinnosti evidovat dárce politických stran v souladu se zákonem č. 424/1991 Sb. Prosím, berte na vědomí, že osobní údaje v rozsahu jméno, příjmení, datum narození a obec trvalého bydliště budou zveřejněny na webových stránkách Strany zelených a Úřadu pro dohled nad hospodařením politických stran a hnutí v rámci výroční zprávy nebo zprávy o volební kampani. E-mailovou adresu a číslo telefonu zpracovává Strana zelených na základě svého oprávněného zájmu za účelem přímého marketingu a rozvoje vztahu s dárci. Proti takovému zpracování je možné vznést námitku na soukromi@zeleni.cz. Více o ochraně osobních údajů ve Straně zelených najdete na www.zeleni.cz/soukromi.

---

### 5. PO ODESLÁNÍ FORMULÁŘE — platební instrukce

Po odeslání formuláře stránka:
1. Zavolá API endpoint (POST, parametry: jméno, příjmení, email, částka — endpoint bude doplněn, zatím mock)
2. API vrátí: číslo účtu, variabilní symbol
3. Formulář se skryje a zobrazí se sekce s platebními instrukcemi

**Zobrazená sekce:**

---

Díky! Tady jsou tvoje platební údaje:

**Číslo účtu**
[hodnota z API]
[tlačítko: Zkopírovat do schránky]

**Variabilní symbol**
[hodnota z API]
[tlačítko: Zkopírovat do schránky]

**Částka**
[vybraná částka] Kč
[tlačítko: Zkopírovat do schránky]

---

Nadpis:
> Teď jdi do svého internetového bankovnictví a nastav trvalý příkaz.

Instrukce (odrážky):
- Zadej číslo účtu a variabilní symbol uvedené výše
- Nastav opakování: **měsíčně**
- Nastav první platbu: **co nejdříve**
- Nastav datum poslední platby: **říjen 2026** *(konkrétní datum bude doplněno)*

Poznámka pod instrukcemi:
> 💡 Trvalý příkaz zrušíš nebo upravíš kdykoliv přímo ve svém internetovém bankovnictví.

**Chybový stav API:** Pokud API selže, zobrazit chybovou hlášku: „Něco se pokazilo. Zkuste to prosím znovu, nebo nás kontaktujte na [email bude doplněn]."

---

### 6. PATIČKA — odkaz na jednorázový dar

Na konci stránky, pod formulářem / po odeslání:

> Jednorázový dar můžete darovat zde.

Jako tlačítko / výrazný odkaz na: **https://dary.zeleni.cz/brno**

---

## Otevřené položky k doplnění před nasazením

- [ ] API endpoint URL a přesná specifikace parametrů požadavku a odpovědi
- [ ] Přesné datum poslední platby trvalého příkazu (říjen 2026 — konkrétní den)
- [ ] Aktuální hodnota progress baru matchingu (kolik Kč zbývá)
- [ ] Doména / subdoména kde stránka poběží
- [ ] Kontaktní e-mail pro chybovou hlášku
- [ ] Logo / grafické podklady Lepší Brno

