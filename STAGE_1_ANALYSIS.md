# Etapa 1 – analýza a návrh architektury

Stav dokumentu: návrh pro kontrolu

Datum ověření zdrojů: 28. 7. 2026

Rozsah: pouze analýza; bez aplikačního kódu, balíčků a migrací

## 1. Současný stav repozitáře

Pracovní adresář byl prázdný a nebyl v něm inicializován Git repozitář.
Neobsahoval Laravel projekt, zdrojový kód, konfiguraci, testy ani dokumentaci.
Neexistují proto žádné starší technické volby, se kterými by bylo nutné zachovat
kompatibilitu.

Výchozí bod pro Etapu 2 bude nová aplikace. Před jejím založením musí být
vyplněn a vyhodnocen `HOSTING_REQUIREMENTS.md`, protože verze PHP, dostupnost
cronu a způsob nastavení document rootu ovlivní volbu frameworku a nasazení.

## 2. Rozhodnutí a předpoklady

### Doporučený technologický základ

- PHP 8.4 nebo 8.5 a Laravel 13, pokud je hosting podporuje.
- PHP 8.3 je technické minimum Laravelu 13, ale je už jen v režimu
  bezpečnostních oprav.
- Pokud hosting nabízí pouze PHP 8.2, lze dočasně zvolit Laravel 12. PHP 8.2 má
  bezpečnostní podporu pouze do 31. 12. 2026, proto je to krátkodobý kompromis.
- MySQL 8 nebo aktuální podporovaná MariaDB, InnoDB a `utf8mb4`.
- Blade, předkompilovaný Bootstrap nebo Tailwind CSS a Alpine.js pouze pro
  drobné interakce.
- Modulární monolit: jeden nasaditelný Laravel projekt, tři samostatné databáze
  a oddělené privátní soubory.
- PDF přes čistě PHP knihovnu. Konkrétní volbu mezi mPDF a Dompdf ověřit
  prototypem české faktury v Etapě 5.
- Fronta v databázi, krátce běžící worker spouštěný cronem.

Laravel 13 byl vydán 17. 3. 2026, vyžaduje PHP 8.3 a má bezpečnostní podporu do
17. 3. 2028. Aktuální podporované větve PHP a jejich životní cyklus je nutné
ověřit znovu při nasazení:

- [Laravel 13 – release notes](https://laravel.com/docs/13.x/releases)
- [Laravel 13 – server requirements](https://laravel.com/docs/13.x/deployment)
- [PHP – podporované verze](https://www.php.net/supported-versions.php)

### Hranice systému

Aplikace bude mít jedinou přihlašovací a aplikační vrstvu, ale účetní data budou
fyzicky oddělena. Centrální databáze je pouze řídicí. Každá OSVČ má vlastní
databázi se stejným schématem a vlastní privátní kořen úložiště.

```text
prohlížeč
    |
HTTPS + přihlášení + CSRF + rate limiting
    |
Laravel modulární monolit
    |
    +-- central ------ uživatelé, oprávnění, seznam subjektů, globální audit
    +-- business_1 --- účetní data OSVČ 1
    +-- business_2 --- účetní data OSVČ 2
    |
    +-- private storage/businesses/{opaque_business_key}/...
    |
    +-- SMTP poskytovatele
    +-- krátké cron běhy
```

Důvod: jedno nasazení je vhodné pro sdílený hosting, zatímco samostatné databáze
a úložiště dávají silnou hranici proti nechtěnému smíchání dat.

## 3. Moduly aplikace

Navržené aplikační moduly:

1. `Identity` – přihlášení, reset hesla, TOTP, relace a centrální audit.
2. `BusinessContext` – oprávnění, aktivní subjekt, přepínání a výběr připojení.
3. `CompanySettings` – údaje OSVČ, účty, číselné řady a šablony.
4. `Clients` – klienti, vyhledávání, duplicity a deaktivace.
5. `Invoicing` – koncepty, položky, vystavení, snapshoty, stavy a platby.
6. `Documents` – verzované PDF a autorizované stahování.
7. `Messaging` – e-mailové zprávy, šablony, fronta a upomínky.
8. `Recurring` – pravidelné fakturace a idempotentní běhy.
9. `Exports` – jednosubjektové CSV/XLSX/ZIP exporty.
10. `Audit` – neměnná obchodní auditní stopa.

Moduly budou oddělené jmenným prostorem a servisními rozhraními, nikoliv
samostatnými procesy. Kontrolery mají být tenké; kritické operace jako vystavení
faktury, přijetí platby a přepnutí subjektu mají probíhat v aplikačních službách
a transakcích.

## 4. Centrální databáze

Centrální připojení bude výchozí pro autentizaci, session a autorizaci. Nesmí
obsahovat obchodní data.

| Tabulka | Účel a důležité sloupce |
|---|---|
| `users` | účet správce, e-mail, hash hesla, stav, TOTP secret šifrovaně, poslední aktivita |
| `password_reset_tokens` | krátkodobé tokeny resetu hesla |
| `sessions` | databázové session; možnost ukončit ostatní relace |
| `businesses` | interní UUID/ULID, technický klíč připojení, zobrazovaný název, IČO pro přepínač, stav |
| `user_business_access` | vazba uživatele na povolené subjekty a role; unikátní dvojice |
| `user_preferences` | poslední povolený subjekt a technické preference |
| `login_audits` | úspěšná/neúspěšná přihlášení, čas, přiměřeně IP a user-agent |
| `business_switch_audits` | původní/cílový subjekt, výsledek, uživatel, request ID |
| `security_audits` | odhlášení, zamítnuté přístupy, změny oprávnění a 2FA |
| `application_settings` | pouze necitlivá globální technická konfigurace |
| `database_health` | poslední ověření připojení a verze schématu bez hesel |
| `migration_runs` | kdo, kdy a s jakým výsledkem migroval konkrétní databázi |
| `migrations` | standardní evidence centrálních migrací |

`businesses` nebude obsahovat účetní nastavení OSVČ. Pole `connection_key` bude
výhradně výčtová technická hodnota, například `business_1`; nikdy host, název
databáze ani heslo z požadavku uživatele. Přihlašovací údaje zůstanou v `.env`.

Centrální databáze nemá cizí klíče do podnikatelských databází. Audit může
obsahovat interní UUID subjektu, ne objektový cizí klíč do jeho databáze.

## 5. Podnikatelská databáze

Obě databáze používají totožné migrace. Každá tabulka existuje nezávisle v obou
databázích; číselné ID se nikdy nepřenáší mezi kontexty.

### Nastavení a adresář klientů

| Tabulka | Účel |
|---|---|
| `company_settings` | identita dodavatele, neplátce DPH, adresy, výchozí hodnoty |
| `bank_accounts` | CZK/EUR a další účty; nejvýše jeden výchozí pro měnu |
| `invoice_sequences` | formát, rok, další pořadové číslo, stav a počáteční hodnota |
| `clients` | typ, identifikace, kontakty, výchozí fakturační volby, aktivita |
| `email_templates` | šablony faktur a jednotlivých stupňů upomínek |
| `reminder_settings` | výchozí vypnutá automatika a bezpečné intervaly |

IČO klienta bude normalizováno na číslice. Unikátní omezení má zabránit dvěma
aktivním klientům se stejným neprázdným IČO; před přesnou podobou indexu je nutné
ověřit možnosti cílové verze MySQL/MariaDB. U fyzické osoby půjde o varování
podle normalizovaného jména a adresy, nikoliv tvrdý zákaz.

### Faktury, platby a dokumenty

| Tabulka | Účel |
|---|---|
| `invoices` | stav, číslo, VS, data, měna, součty, klient, účet, snapshoty |
| `invoice_items` | popis, přesné množství, jednotka, cena, sleva, součet, pořadí |
| `invoice_versions` | neměnné verze obchodních dat vystavené faktury |
| `invoice_pdfs` | verze PDF, privátní cesta, hash, velikost, autor a čas |
| `payments` | částka, měna, datum, metoda, účet, VS, poznámka |
| `invoice_status_history` | původní/nový stav, důvod, uživatel a čas |

Doporučení:

- částky `DECIMAL(15,2)`;
- množství a jednotková cena například `DECIMAL(15,4)`, výpočet a zaokrouhlování
  provádět deterministicky;
- měna jako tříznakový ISO kód s aplikačním allow-listem;
- snapshot dodavatele, klienta a bankovního účtu jako verzovaná struktura JSON
  plus samostatná často filtrovaná pole;
- unikátní index na neprázdné číslo faktury;
- fakturu s účetní historií nemažeme natvrdo;
- platba musí mít měnu faktury; v první verzi žádný kurzový přepočet.

### Automatizace, komunikace a audit

| Tabulka | Účel |
|---|---|
| `recurring_invoice_profiles` | šablona a harmonogram pravidelné fakturace |
| `recurring_invoice_items` | položky profilu |
| `recurring_invoice_runs` | idempotency key, plánované datum, výsledek, faktura |
| `email_messages` | adresáti, předmět, bezpečný snapshot textu, stav a chyba |
| `email_attachments` | povolené PDF verze patřící do stejné databáze |
| `reminders` | stupeň, faktura, režim přípravy/odeslání a idempotency key |
| `exports` | filtry, typ, stav, privátní cesta, hash, expirace |
| `audit_logs` | typ a ID objektu, událost, redigované změny, uživatel, request ID |
| `jobs`, `failed_jobs` | lokální databázová fronta daného subjektu |
| `migration_runs`, `migrations` | verze a výsledek změn schématu |

Audit nebude ukládat hesla, tajné klíče, obsah session ani kompletní citlivou
konfiguraci. Pole původních a nových hodnot projdou explicitním allow-listem a
redakcí.

## 6. Více databázových připojení

Konfigurace bude definovat `central`, `business_1` a `business_2`. Každé bude mít
vlastní sadu proměnných prostředí dle zadání a pokud možno vlastního DB uživatele
s oprávněním jen k jedné databázi.

Zásady:

- výchozí připojení `central`;
- obchodní model nesmí používat dynamický název připojení z requestu;
- `BusinessContext` mapuje centrální interní UUID pouze na serverový
  allow-list `business_1|business_2`;
- kontext je nastaven middlewarem před route model bindingem;
- obchodní modely získají připojení z jednoho kontextového resolveru;
- po dokončení requestu se kontext zahodí;
- příkazy cronu přijímají interní identifikátor z důvěryhodného serverového
  seznamu, nikoliv z veřejného URL;
- transakce nikdy nepřekračuje databáze.

Změna centrálního auditu a obchodní operace nemůže být jednou atomickou SQL
transakcí. Kritickou obchodní operaci dokončíme v podnikatelské databázi a její
audit uložíme tamtéž. Centrální audit je určen jen pro identitu a přepínání.

## 7. Bezpečné přepínání subjektu

1. Přihlášený uživatel odešle `POST` požadavek s centrálním UUID subjektu a CSRF
   tokenem.
2. Server načte subjekt z `central` a ověří aktivní záznam
   `user_business_access`.
3. Server mapuje subjekt na pevný `connection_key`; nepřijímá jméno databáze.
4. Aktivní centrální UUID uloží do serverové session a obnoví ID session.
5. Zapíše úspěšný či odmítnutý pokus do centrálního auditu.
6. Přesměruje na bezpečnou stránku bez návratové URL na jiný host.
7. Middleware v každém dalším účetním requestu znovu ověří, že subjekt existuje,
   je aktivní a uživatel k němu stále má oprávnění.
8. Teprve potom nastaví podnikatelské připojení a privátní kořen souborů.

Bez aktivního subjektu účetní routy vrátí bezpečnou výzvu k volbě subjektu.
Přepínač zobrazí název, IČO a ikonu; rozlišení nebude založené jen na barvě.

Vystavení faktury vyžaduje serverově vytvořenou potvrzovací stránku se jménem a
IČO aktuálního dodavatele. Při odeslání server znovu ověří kontext; skryté pole
není autoritou.

## 8. Ochrana proti smíchání dat

Ochrana je vícevrstvá:

- fyzicky samostatné databáze a DB účty s minimálními právy;
- jeden serverový `BusinessContext` jako jediný zdroj pravdy;
- middleware před bindingem modelů;
- obchodní služby vyžadují aktivní kontext;
- vazby klienta, účtu, šablony a PDF se načítají ze stejného aktuálního
  připojení, nikoliv podle hodnoty připojení z formuláře;
- nehádatelné veřejné identifikátory faktur a souborů; interní číslo faktury
  neslouží jako autorizační údaj;
- soubory pod samostatným privátním kořenem subjektu;
- download controller znovu autorizuje uživatele, subjekt, záznam PDF i
  kanonickou cestu;
- exportní služba neumožní seznam více subjektů;
- job nese centrální UUID a při spuštění je znovu mapován přes allow-list;
- testovací matice obsahuje stejné primární ID ve dvou databázích;
- logy a chybové stránky nesmějí odhalit přihlašovací údaje ani absolutní cesty.

Samotný globální scope podle `business_id` není dostatečný a v podnikatelských
tabulkách se ani nebude používat; hranicí je celé připojení.

## 9. Struktura adresářů

Navržená struktura pro budoucí Laravel projekt:

```text
app/
  Domain/
    Identity/
    BusinessContext/
    Clients/
    Invoicing/
    Payments/
    Documents/
    Messaging/
    Recurring/
    Exports/
    Audit/
  Http/
    Controllers/
    Middleware/
    Requests/
  Console/Commands/
  Support/
config/
  business.php
database/
  migrations/
    central/
    business/
  seeders/
resources/
  views/
    layouts/
    dashboard/
    clients/
    invoices/
    settings/
    pdf/
    mail/
routes/
  web.php
  console.php
storage/app/private/
  businesses/
    {opaque_business_key}/
      invoices/
      exports/
      logos/
      signatures/
      temporary/
tests/
  Feature/
    Central/
    BusinessIsolation/
    Clients/
    Invoices/
    Messaging/
    Recurring/
    Exports/
  Unit/
docs/
```

`opaque_business_key` bude náhodný interní identifikátor, nikoliv IČO či jméno.
Žádný privátní adresář nebude publikován symbolickým odkazem.

## 10. Stavový automat faktury

Kanonický stav má vyjadřovat obchodní životní cyklus. „Po splatnosti“ je lépe
počítaný příznak než ručně přepisovaný kanonický stav, jinak se plete s
„Odeslaná“ a „Částečně uhrazená“. Uživatelské rozhraní jej přesto zobrazí jako
požadovaný stav/štítek.

```text
Koncept
  -> Vystavená
  -> Stornovaná

Vystavená
  -> Odeslaná
  -> Částečně uhrazená
  -> Uhrazená
  -> Stornovaná
  -> Archivovaná

Odeslaná
  -> Částečně uhrazená
  -> Uhrazená
  -> Stornovaná
  -> Archivovaná

Částečně uhrazená
  -> Uhrazená
  -> Stornovaná
  -> Archivovaná
```

Pravidla:

- vystavení vytvoří číslo, snapshot a první neměnnou verzi;
- odeslání je doloženo zprávou, ne pouze ručním přepnutím;
- platební stav se dopočítá z plateb v transakci;
- přeplatek je částka nad celkem, stav zůstává `Uhrazená`;
- po splatnosti = splatnost v minulosti, zůstatek > 0 a faktura není koncept,
  stornovaná ani archivovaná;
- storno nemění historii a nesmí uvolnit použité číslo;
- archivace je prezentační zakončení, ne smazání;
- každá změna zapisuje historii s důvodem a uživatelem.

Přesná pravidla oprav chybně vystavených dokladů a případných opravných dokladů
musí před implementací potvrdit účetní/právník.

## 11. Bezpečné číslování faktur

Koncept může být bez čísla. Číslo se přiděluje pouze při vystavení uvnitř jedné
transakce podnikatelské databáze:

1. uzamknout řádek číselné řady pro daný rok pomocí `SELECT ... FOR UPDATE`;
2. načíst a zvýšit přesné celé pořadové číslo;
3. sestavit číslo pouze z validované šablony a povolených tokenů;
4. vložit fakturu s unikátním indexem na čísle;
5. zapsat snapshot, historii a audit;
6. potvrdit transakci.

Při konfliktu unikátního indexu se operace bezpečně opakuje v omezeném počtu.
Manuální číslo používá stejný unikátní index, nikdy neposune řadu bez explicitně
definovaného pravidla a po použití se neuvolní. Formát řady nebude libovolný
spustitelný výraz; povolí pouze prefix, rok a pořadí s omezenou délkou.

Variabilní symbol se odvodí odstraněním nenumerických znaků, zkrácení však nesmí
proběhnout potichu. Výsledek musí mít nejvýše 10 číslic, jinak systém vyžádá
ruční opravu. Kolize vyvolá varování v rámci aktuální databáze.

## 12. Pravidelné fakturace

Každý profil má `next_run_on`, časové pásmo Europe/Prague, frekvenci a bezpečný
výchozí režim `draft`. Cron iteruje přes serverový seznam obou subjektů
samostatně.

Pro každý plánovaný výskyt se vytvoří deterministický idempotency key, například
z UUID profilu a plánovaného data. Unikátní index v `recurring_invoice_runs`
zabrání duplicitě i při souběžném nebo opakovaném cronu. Profil a běh se zamknou
v transakci. `next_run_on` se posouvá od plánovaného data, ne od času skutečného
spuštění, aby nedocházelo k driftu.

Automatické vystavení a odeslání budou opt-in. Chyba u jednoho profilu či subjektu
se zaznamená a neukončí zpracování ostatních. Ukončení, pozastavení a změna
šablony nesmí zpětně měnit již vytvořené faktury.

## 13. E-mailová fronta přes cron

Každá podnikatelská databáze má vlastní `jobs` a `failed_jobs`. Dispatcher vždy
nejprve nastaví ověřený kontext subjektu. Cron spustí pro každý subjekt krátký,
ukončitelný worker, například s omezením počtu úloh a času.

Bezpečnostní návrh:

- jeden scheduler cron s `withoutOverlapping` a krátkým zámkem v centrální DB;
- uvnitř samostatné zpracování obou subjektů s `try/catch`;
- nejvýše malá dávka, například 20 zpráv / 50 sekund;
- timeout SMTP kratší než limit procesu;
- idempotency key zprávy a stavový přechod `pending -> sending -> sent|failed`;
- před odesláním znovu ověřit fakturu, PDF verzi, příjemce a subjekt;
- počet pokusů, exponenciální prodleva a konečný stav `failed`;
- logovat kategorii chyby, nikoliv SMTP heslo či celý citlivý payload;
- skutečné e-maily v testech vždy fake.

Ruční synchronní odeslání lze přidat až po změření odezvy SMTP; výchozí návrh
preferuje frontu, aby webový request nevypršel.

## 14. Migrace tří databází

Migrace budou ve dvou sadách:

- `database/migrations/central` pro centrální databázi;
- `database/migrations/business` spuštěné zvlášť proti `business_1` a
  `business_2`.

Nasazení:

1. vytvořit ověřenou zálohu každé databáze;
2. zapnout maintenance mode, pokud změna není zpětně kompatibilní;
3. migrovat `central`;
4. migrovat `business_1`;
5. otestovat verzi a základní čtení;
6. migrovat `business_2`;
7. zapsat výsledek do `migration_runs`;
8. provést health check a teprve potom zpřístupnit aplikaci.

Chyba podnikatelské databáze nesmí být skryta. Aplikace subjekt s nekompatibilní
verzí zablokuje pro zápis a zobrazí správci konkrétní bezpečnou chybu.

Bez SSH bude připraven jednorázový instalační mechanismus s náhodným dlouhým
tokenem, časovým omezením, přihlášením správce, allow-listem operací a fyzickým
disable souborem po úspěchu. Nesmí přijímat libovolný Artisan příkaz. Preferovaný
způsob je stále SSH/CLI; webový instalátor je nouzová varianta.

## 15. Zálohování a obnova

Zálohovací jednotky:

- centrální DB;
- business 1 DB a její privátní soubory;
- business 2 DB a její privátní soubory.

Každý manifest obsahuje interní UUID, typ jednotky, čas v UTC, verzi schématu,
počty souborů, velikost, SHA-256 a výsledek. Zálohy budou šifrované, přenesené
mimo hosting a chráněné oddělenými přístupovými údaji. Heslo zálohy nesmí být
uloženo vedle zálohy.

Preferovat hostingový export nebo `mysqldump`. Pokud není dostupný, použít
ověřený databázový export hostingu; aplikační PHP export je až poslední možnost,
musí stránkovat podle primárního klíče, zachovat pořadí, typy, schéma a cizí
klíče a být testován obnovou.

Obnova jedné OSVČ:

1. zastavit zápisy pouze pro cílový subjekt;
2. vytvořit bezpečnostní zálohu aktuálního stavu;
3. obnovit do nové prázdné databáze, nikoliv přes druhý subjekt;
4. ověřit checksum, verzi schématu, počty a vazby;
5. obnovit odpovídající privátní strom do dočasného umístění;
6. atomicky přepnout serverovou konfiguraci cílového subjektu;
7. provést smoke test a zapsat audit obnovy.

Minimálně čtvrtletně provést zkušební obnovu. Retence a požadované RPO/RTO se
stanoví až podle možností hostingu a požadavku uživatele.

## 16. Bezpečnostní rizika a opatření

| Riziko | Opatření / rozhodovací brána |
|---|---|
| Smíchání subjektů | fyzické DB, serverový kontext, middleware, izolované soubory a testy |
| IDOR změnou URL | autorizace objektu v aktuálním připojení, nehádatelné veřejné ID |
| Podvržení připojení | pevný serverový allow-list, nikdy DB identifikátor z requestu |
| Krádež relace | HTTPS, Secure/HttpOnly/SameSite cookies, rotace ID, timeout nečinnosti |
| Credential stuffing | rate limit, silné heslo, volitelně TOTP, audit neúspěchů |
| CSRF/XSS/SQL injection | Laravel CSRF, validace, automatické escapování Blade, parametrizované dotazy |
| Únik `.env` a zdrojů | document root pouze `public`, serverová pravidla a deployment check |
| Zveřejnění PDF | privátní storage, autorizovaný controller, kontrola kanonické cesty |
| Path traversal/upload | generované názvy, MIME a rozměrové limity, bez původní cesty |
| Race condition čísel | transakce, row lock, unikátní index |
| Dvojité pravidelné běhy/e-maily | idempotency keys a unikátní indexy |
| Ztráta dat | oddělené šifrované zálohy a pravidelné testy obnovy |
| Únik v auditu | allow-list polí, redakce tajemství, omezená retence IP |
| Sdílený hosting kompromitovaný sousedem | aktuální PHP, izolace účtu, správná práva, kvalitní poskytovatel |
| Supply-chain balíčků | lock soubory, audit závislostí, minimum balíčků, řízené aktualizace |
| Chybný webový instalátor | jednorázový token, úzké operace, časový limit a deaktivace |
| SMTP spoofing/doručitelnost | SPF, DKIM a DMARC ověřit u domén a poskytovatele |
| Neomezené exporty/DoS | stránkování, dávky, kvóty, časové a velikostní limity |

Před produkcí je nutný threat model, kontrola hlaviček (CSP, HSTS atd.),
produkční `APP_DEBUG=false`, kontrola oprávnění a penetrační test kritických
autorizačních cest.

## 17. Legislativní body k ověření

Tato část je technický podklad, nikoliv právní ani účetní stanovisko.

Ověřené obecné body:

- § 435 občanského zákoníku vyžaduje na obchodních listinách jméno a sídlo
  podnikatele, údaj o příslušném zápisu/evidenci a přidělený identifikující údaj.
  Zdroj: [e-Sbírka – zákon č. 89/2012 Sb., § 435](https://e-sbirka.gov.cz/sb/2012/89).
- Zákon o účetnictví upravuje náležitosti účetního dokladu a dobu uchování
  účetních záznamů; konkrétní aplikovatelnost na oba subjekty a jejich způsob
  evidence musí potvrdit účetní. Zdroj:
  [Ministerstvo financí – zákon č. 563/1991 Sb.](https://mf.gov.cz/cs/legislativa/legislativni-dokumenty/1991/zakon-c-5631991-sb-3339).
- GDPR vyžaduje právní titul, omezení účelu, minimalizaci, přesnost, omezení
  uložení a přiměřené zabezpečení. Zdroj:
  [ÚOOÚ – základní příručka](https://uoou.gov.cz/verejnost/zakladni-prirucka-k-ochrane-udaju).
- Poskytovatel hostingu, záloh nebo e-mailu může být zpracovatelem; je nutné
  posoudit smlouvy a případné další zpracovatele. Zdroj:
  [ÚOOÚ – zpracovatel](https://uoou.gov.cz/poradna/poradna-gdpr/zpracovatel).

Před Etapou 4/5 musí účetní nebo právník potvrdit:

1. zda každý z obou OSVČ vede účetnictví, daňovou evidenci nebo jinou evidenci a
   které náležitosti a retenční lhůty se proto skutečně použijí;
2. konečnou sadu povinných údajů faktury neplátce DPH a formulaci evidence
   podnikatele;
3. zda a kdy uvádět datum dodání/uskutečnění účetního případu;
4. text „Nejsem/Nejsme plátci DPH“ a případné DIČ neplátce;
5. pravidla storna, oprav čísel, dobropisů/opravných dokladů a zachování
   auditní stopy;
6. přesná pravidla zaokrouhlování položek, slev a celkové částky;
7. retenční lhůty pro faktury, účetní podklady, audit, e-maily, exporty a zálohy;
8. právní tituly a informační povinnost vůči klientům – fyzickým osobám;
9. postup pro žádost o přístup, opravu, omezení a výmaz při kolizi s povinnou
   archivací účetních dokumentů;
10. zda dvě OSVČ vystupují jako samostatní správci osobních údajů a jak
    dokumentovat sdílenou správu aplikace;
11. zpracovatelské smlouvy s hostingem, SMTP a zálohovací službou a umístění dat;
12. vhodnou dobu uchování IP adres a technických auditů;
13. aktuální standard QR Platba a licenční/implementační požadavky před Etapou 5.

Aplikace ani PDF nebudou označeny jako automaticky právně bezchybné.

## 18. Minimální požadavky na hosting

Rozhodovací minimum:

- PHP 8.3+; preferováno 8.4/8.5;
- kompatibilní MySQL/MariaDB s InnoDB, `utf8mb4`, transakcemi a row locks;
- tři oddělené databáze a ideálně tři DB uživatelé;
- HTTPS s automatickou obnovou certifikátu;
- document root nastavitelný na Laravel `public`, nebo ověřená bezpečná
  alternativa;
- cron s CLI PHP a možností krátkých běhů každou minutu nebo 5 minut;
- rozšíření požadovaná Laravelem a zvolenými PDF/XLSX/ZIP knihovnami;
- odesílání přes externí SMTP s dostatečným limitem a TLS;
- zápis do `storage` a `bootstrap/cache`;
- dostatek místa pro verzované PDF, exporty, logy a dočasné soubory;
- samostatné a obnovitelné zálohy všech tří DB a souborů;
- PHP limity dovolující PDF a ZIP v omezených dávkách;
- možnost nasadit `vendor` a předkompilované assety bez Node.js na serveru.

Úplný dotazník je v `HOSTING_REQUIREMENTS.md`.

## 19. Plán implementace po malých krocích

Každý krok končí testy, formátováním, statickou analýzou a aktualizací
`PROJECT_STATUS.md`.

### Etapa 2 – základ

1. Uzavřít hostingový dotazník a zvolit PHP/Laravel.
2. Založit čistý projekt bez veřejné registrace.
3. Přidat konfigurační kostru tří připojení a bezpečný `.env.example`.
4. Vytvořit oddělené migration commandy a health checky.
5. Implementovat centrální uživatele, přihlášení, reset a rate limit.
6. Implementovat podnikatelské subjekty, oprávnění a audit.
7. Implementovat `BusinessContext`, middleware a přepínač.
8. Doplnit izolované integrační testy se stejnými ID.

### Etapa 3 – nastavení a klienti

1. Nastavení identity obou subjektů a privátní upload loga/podpisu.
2. Bankovní účty s měnovými pravidly.
3. Číselné řady pouze jako konfigurace.
4. CRUD a deaktivace klienta.
5. Normalizované vyhledávání a kontrola duplicit.
6. Audit změn a export osobních údajů klienta.

### Etapa 4 – faktury a platby

1. Doménové typy peněz, měn, slev a stavů s unit testy.
2. Koncept a položky s deterministickými výpočty.
3. Transakční vystavení, číslo a snapshoty.
4. Stavový automat a historie.
5. Platby, částečná úhrada a přeplatek.
6. Filtry, stránkování, kopie, storno a archivace.
7. Souběžné a izolační testy.

### Etapa 5 – PDF a e-mail

1. Prototyp mPDF vs. Dompdf s českou diakritikou a vícestránkovou fakturou.
2. Verzování PDF, hash a autorizované stažení.
3. QR Platba po právním/standardizačním ověření.
4. Šablony a náhled e-mailu.
5. Databázová fronta, SMTP a historie.
6. Testy správného dodavatele, účtu, PDF a šablony.

### Etapa 6 – automatizace

1. Profily pravidelné fakturace.
2. Idempotentní generování konceptů.
3. Ruční a připravované upomínky.
4. Opt-in automatické vystavení/odeslání.
5. Scheduler, zámky, dávky a izolace chyb.

### Etapa 7 – export a dashboard

1. CSV po dávkách.
2. XLSX a ZIP s limity.
3. Autorizované privátní exporty a expirace.
4. Dashboard agregovaný pouze v aktivní DB a odděleně po měnách.

### Etapa 8 – zabezpečení a nasazení

1. Threat model, dependency audit, statická analýza a autorizační testy.
2. Produkční konfigurace pro SSH.
3. Omezený jednorázový instalátor pouze pokud SSH chybí.
4. Cron, fronta, monitoring chyb a provozní checklist.
5. Zálohy, dokumentovaná obnova a zkušební restore.
6. Finální ověření účetní/právníkem a akceptační test.

## 20. Rozdělení funkcí podle priority

### Nezbytné pro první použitelnou verzi

- bezpečné přihlášení, reset hesla, ukončení ostatních relací;
- tři databáze, aktivní subjekt, oprávnění a testovaná izolace;
- nastavení obou OSVČ a CZK/EUR bankovní účty;
- klienti, duplicity, hledání a deaktivace;
- koncept, položky, přesné výpočty, transakční číslo a snapshot;
- vystavení, PDF, autorizované stažení a verzování;
- základní e-mail s náhledem a auditovaným odesláním;
- platby, částečná úhrada, přeplatek a stav po splatnosti;
- základní CSV export jednoho subjektu;
- audit kritických operací;
- HTTPS, bezpečné session, zálohy a ověřená obnova.

Důvod: bez těchto funkcí nelze bezpečně vystavit, uchovat, poslat a dohledat
fakturu za správný subjekt.

### Vhodné pro druhou fázi

- TOTP 2FA;
- pravidelné fakturace v bezpečném režimu konceptu;
- ruční a připravované upomínky;
- databázová fronta přes cron;
- XLSX a ZIP exporty;
- dashboard a pokročilé filtry;
- QR Platba;
- import/našeptání z registru s potvrzením;
- samoobslužný export osobních údajů klienta.

Důvod: mají vysokou užitnou hodnotu, ale základní fakturační tok může fungovat
bez nich.

### Vhodné odložit

- plně automatické vystavení a odeslání pravidelných faktur;
- plně automatické upomínky;
- společný export obou subjektů;
- automatické bankovní párování a kurzové přepočty;
- veřejné API, zákaznický portál a mobilní aplikace;
- pokročilé účetní formáty nad rámec CSV/XLSX/PDF.

Důvod: zvyšují právní, provozní nebo autorizační riziko a nejsou nutné pro osobní
první verzi.

## Výstup Etapy 1

Etapa 1 je dokumentačně připravena, ale rozhodovací brána hostingu a
účetní/právní ověření zůstávají otevřené. Etapa 2 nesmí začít, dokud nejsou
potvrzeny alespoň PHP, databáze, cron, document root, SMTP a způsob záloh.
