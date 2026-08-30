# lepsibrno.cz

Web kampaně Zelení Brno 2026 — dárcovské formuláře (měsíční předplatné +
jednorázové dary na jednotlivé městské části), administrace transakcí a
napojení na platební a CRM systémy.

Tenhle dokument je provozní/technický — popisuje moduly a věci, na které si
dát pozor při deployi a změnách. Neobsahuje žádné přístupové údaje ani
citlivé provozní detaily nad rámec toho, co je stejně vidět v kódu (repo je
veřejné).

## Přehled modulů

### Frontend (veřejné stránky)

| Soubor | Účel |
|---|---|
| `index.html` | Homepage — jednorázový dar na vybranou městskou část, platba převodem (QR) nebo kartou |
| `predplatne.html` | Měsíční předplatné (původní `index.html`, přejmenováno) |
| `dekujeme.html` | Děkovná stránka po Stripe platbě předplatného |

### Platby a zápis dárců

| Soubor | Účel |
|---|---|
| `create-checkout.php` | Stripe Checkout, měsíční předplatné (`mode: subscription`) |
| `create-district-checkout.php` | Stripe Checkout, jednorázový dar na čtvrť (`mode: payment`) |
| `stripe-webhook.php` | Zpracuje potvrzenou platbu z obou flow. Idempotentní podle `stripe_session_id` (viz níže) |
| `log-donor.php` | Zápis dárce při platbě převodem — předplatné |
| `log-district-donation.php` | Zápis dárce při platbě převodem — dar na čtvrť |
| `mc-store.php` | Datová vrstva pro dary na čtvrť: `mc.json` (config) + `dary.jsonl` (log) → `stav.json` (veřejný souhrn) |
| `db.php` | SQLite vrstva (`donors.db`). Tabulky: `donors`, `onetime_synced`, `recurring_synced`, `district_donations` |

### Sync a administrace

| Soubor | Účel |
|---|---|
| `sync-onetime.php` | Stahuje dary fondu z API dary.zeleni.cz, posílá je do Action Networku. Spouští se cronem přímo na hostingu (mimo tenhle repo), denně ~6:00 |
| `transakce.php` | Admin přehled všech transakcí. **Obsahuje osobní údaje dárců (GDPR) — heslem chráněné, nikdy nesdílet výstup mimo interní použití** |
| `rebuild-stav.php`, `create-an-tags.php` | Jednorázové/manuální admin skripty, spouští se přes `?token=` (heslo administrace) přímo z prohlížeče |

### Konfigurace

- `config.template.php` — šablona v gitu, placeholdery (`__NĚCO__`) se při deployi nahradí hodnotami z GitHub Secrets
- `config.php` — **není v gitu**, žije jen na serveru, obsahuje ostré hodnoty (Stripe klíče, admin heslo, přístup k dary.zeleni.cz, Action Network token)

### Data mimo webroot (`../data/`)

- `mc.json` — ruční konfigurace městských částí (cíle, počty schránek, AN tagy)
- `dary.jsonl` — append-only log darů na čtvrť, zdroj pravdy pro `stav.json`

`stav.json` (uvnitř webrootu) je z těchto dvou dopočítaný veřejný přehled pro
frontend — necachuje se dlouho (`Cache-Control: max-age=30`, viz `.htaccess`).

### GitHub Actions workflows (`.github/workflows/`)

| Workflow | Spouští se | Účel |
|---|---|---|
| `deploy.yml` | automaticky při push na `main` | hlavní deploy přes `FTP-Deploy-Action` |
| `ftp-upload.yml` | ručně | záložní deploy přes `lftp`, když hlavní deploy padá |
| `deploy-config.yml` | ručně | přerenderuje a nahraje `config.php` po změně nějakého secretu |
| `seed-district-data.yml` | ručně, jen jednou | prvotní nahrání `mc.json` + `dary.jsonl` na server |
| `admin-bootstrap.yml` | ručně | aktuálně nefunkční, viz níže |

## Na co si dát pozor

### 1. `config.php` se přepisuje CELÝ, ne po částech

O `config.php` se stará výhradně `deploy-config.yml` (ruční spuštění).
Vždy vyrenderuje **celý** soubor znovu ze všech aktuálně uložených GitHub
Secrets a tímhle celým souborem přepíše ten na serveru — neexistuje
částečná/cílená aktualizace jedné položky.

Důsledek: pokud změníš jeden secret (např. `ADMIN_PASSWORD`) a spustíš
`deploy-config.yml`, potichu se přepíšou i všechny ostatní položky tou
hodnotou, co je aktuálně v Secrets — a pokud je některá zastaralá (typicky
proto, že se `config.php` na serveru někdy upravoval mimo tenhle
mechanismus), přepíše se zpátky na starou/špatnou hodnotu.

**Než spustíš `deploy-config.yml`, ověř, že všechny secrety v GitHubu
odpovídají aktuální realitě, ne jen ten, který zrovna měníš.**

`deploy.yml` (běžný automatický deploy při push) se `config.php` nedotýká
vůbec — dřív ho taky renderoval a nahrával při každém pushi (to původně
způsobilo incident popsaný v bodě 5), tenhle render-krok byl proto z
`deploy.yml` odstraněný.

### 2. Spojení GitHub Actions → hosting je nespolehlivé

- Hlavní deploy (`FTP-Deploy-Action`) často padá na `Timeout (control
  socket)`.
- Záložní `lftp` workflow umí selhat taky — někdy úplně (všechny soubory
  najednou), ne jen ojediněle jeden soubor.
- `lftp` ve výchozím nastavení pokračuje přes chyby jednotlivých příkazů —
  job pak může skončit jako „success", i když se ve skutečnosti nenahrálo
  vůbec nic. `ftp-upload.yml` má proti tomu opravu (`set cmd:fail-exit
  yes`), `deploy-config.yml` zatím ne.
- `admin-bootstrap.yml` je momentálně nepoužitelný — i běžné HTTPS (port
  443) z GitHub Actions na tenhle hosting timeoutuje.

**Po každém deployi ověřuj výsledek věcně** (např. `curl -sI
https://lepsibrno.cz/... | grep last-modified`, nebo obsahem stránky), ne
jen podle zeleného zaškrtnutí v Actions. Když GitHub Actions na hosting
nedosáhne vůbec, dá se soubor nahrát ručně přes `lftp` z vlastního počítače
(spojení z domácí/kancelářské sítě blokované nebývá).

### 3. `seed-district-data.yml` je jednorázový a nebezpečný

Přepisuje na serveru **oba** soubory — `mc.json` i `dary.jsonl`. Spuštění
po jakémkoli reálném daru na čtvrť by smazalo všechny mezitím přijaté dary
zpátky na počáteční zárodečná data. Vyžaduje ruční potvrzení textem „ano",
ale žádnou další pojistku nemá.

### 4. `stripe-webhook.php` je idempotentní podle `stripe_session_id`

Obě větve (dar na čtvrť i předplatné) před zápisem ověří, jestli už
existuje řádek se stejným `stripe_session_id` — chrání to proti duplicitám,
když Stripe stejnou webhook událost doručí vícekrát (retry, ruční resend).
Bez týhle kontroly (starší verze kódu) mohlo opakované doručení vytvořit
řádek s dnešním datem, ale daty ze staré platby.

### 5. Sync s dary.zeleni.cz (`sync-onetime.php`)

Cron běží přímo na hostingu (mimo tenhle repo a mimo GitHub Actions), denně
kolem 6:00, volá `sync-onetime.php` přes HTTP s `?token=`. Pokud přihlášení
k API dary.zeleni.cz začne padat na HTTP 401, skoro vždy jde o bod 1 výše —
zastaralé `DARY_USERNAME`/`DARY_PASSWORD` v GitHub Secrets přepsané do
`config.php` při nějakém redeployi.

## Deploy checklist

1. `git push` na `main` → spustí se `deploy.yml` automaticky (podle bodu 2
   může selhat, počítej s opakováním).
2. Pokud selže: ručně spustit `ftp-upload.yml` (`workflow_dispatch`) —
   klidně i víckrát, spojení bývá přerušované.
3. Pokud GitHub Actions na hosting nedosáhne vůbec: nahrát změněné soubory
   ručně přes `lftp` z vlastního počítače.
4. Vždy ověřit výsledek podle skutečného obsahu/hlaviček stránky, ne jen
   podle stavu v Actions.
