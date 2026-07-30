# Moje fakturace

Soukromá webová fakturační aplikace pro dva fyzicky oddělené podnikatelské
subjekty. Aktuálně je dokončena Etapa 2, bezpečnostní základ Etapy 3 a první
business modul: centrální databáze, přihlášení, oprávnění, audit, bezpečný
přepínač aktivního subjektu, fail-closed business modely a nastavení
fakturačního subjektu.

Klienti, faktury, platby, PDF, e-mail, pravidelné fakturace a exporty zatím
nejsou implementované.

## Technický základ

- PHP 8.3 nebo novější;
- Laravel 13;
- Composer 2.10+;
- MySQL 8 / kompatibilní podporovaná MariaDB;
- Node.js LTS a npm pouze pro lokální sestavení assetů;
- Blade, Tailwind CSS a Alpine.js;
- klasická session autentizace bez SPA;
- tři samostatná databázová připojení: `central`, `business_1`, `business_2`.

Produkční server nepotřebuje Node.js, Docker, Redis ani trvale běžící proces.
Document root musí směřovat pouze na adresář `public`.

## Požadovaná PHP rozšíření

Pro Etapu 2 jsou potřeba minimálně Ctype, cURL, DOM, Fileinfo, Filter, Hash,
Mbstring, OpenSSL, PCRE, PDO, PDO MySQL, Session, Tokenizer, XML/XMLWriter a
Sodium. Další rozšíření pro PDF, obrázky, XLSX a ZIP budou potvrzena v
odpovídajících etapách.

## Lokální instalace

1. Nainstalujte PHP 8.3+, Composer 2, MySQL 8 a Node.js LTS.
2. Naklonujte nebo otevřete projekt.
3. Nainstalujte PHP závislosti:

   ```bash
   composer install
   ```

4. Vytvořte lokální konfiguraci:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Ve Windows PowerShell lze místo `cp` použít:

   ```powershell
   Copy-Item .env.example .env
   php artisan key:generate
   ```

5. Vytvořte šest lokálních databází: tři pro běžný vývoj a tři samostatné pro
   automatické testy. Doporučené názvy:

   ```text
   fakturace_local_central
   fakturace_local_business_1
   fakturace_local_business_2

   fakturace_test_central
   fakturace_test_business_1
   fakturace_test_business_2
   ```

   Všechny databáze použijí InnoDB, `utf8mb4` a například collation
   `utf8mb4_unicode_ci`. Pro běžný vývoj nepoužívejte testovací databáze:
   PHPUnit centrální testovací databázi obnovuje příkazem `migrate:fresh`.

6. Vyplňte v lokálním `.env` všechna tři připojení:

   ```dotenv
   DB_CONNECTION=central

   DB_CENTRAL_HOST=127.0.0.1
   DB_CENTRAL_PORT=3306
   DB_CENTRAL_DATABASE=fakturace_local_central
   DB_CENTRAL_USERNAME=
   DB_CENTRAL_PASSWORD=

   DB_BUSINESS_1_HOST=127.0.0.1
   DB_BUSINESS_1_PORT=3306
   DB_BUSINESS_1_DATABASE=fakturace_local_business_1
   DB_BUSINESS_1_USERNAME=
   DB_BUSINESS_1_PASSWORD=

   DB_BUSINESS_2_HOST=127.0.0.1
   DB_BUSINESS_2_PORT=3306
   DB_BUSINESS_2_DATABASE=fakturace_local_business_2
   DB_BUSINESS_2_USERNAME=
   DB_BUSINESS_2_PASSWORD=
   ```

   `.env` je ignorovaný Gitem. Nikdy do něj nekopírujte produkční údaje v
   prostředí, kde by mohl být zveřejněn nebo sdílen.

7. Spusťte centrální migrace:

   ```bash
   php artisan migrate --database=central --path=database/migrations/central
   ```

8. Spusťte společné business migrace bezpečným aplikačním příkazem:

   ```bash
   php artisan app:migrate-businesses
   ```

   Příkaz používá pouze migrace z `database/migrations/business`, postupně je
   spustí nad `business_1` a `business_2` a nepřijme jiné connection name.
   Jednu databázi lze migrovat explicitně:

   ```bash
   php artisan app:migrate-businesses --business=business_1
   ```

   V produkci příkaz vyžaduje interaktivní potvrzení nebo `--force`.

9. Nainstalujte a sestavte frontend:

   ```bash
   npm install
   npm run build
   ```

10. Spusťte lokální server:

   ```bash
   php artisan serve
   ```

## Vytvoření správce

Správce se vytváří pouze interaktivně. Heslo se nezobrazuje ani nepředává jako
argument příkazové řádky:

```bash
php artisan app:create-admin
```

Příkaz vyžaduje heslo o délce alespoň 12 znaků s písmeny, číslicemi a symbolem.
Veřejná registrační stránka ani registrační route neexistuje.

## Vytvoření dvou subjektů

Po vytvoření správce spusťte:

```bash
php artisan app:configure-businesses
```

Příkaz bezpečně vyžádá název, IČO, krátké označení a stav obou subjektů. První
subjekt je pevně mapován na `business_1`, druhý na `business_2`. Připojení není
přebíráno z webového formuláře. Správci se současně vytvoří oprávnění k oběma
subjektům.

## Přepínání aktivního subjektu

Po přihlášení middleware načte pouze aktivní subjekty přiřazené uživateli.
Aktivní UUID se ukládá do serverové session. Databázové připojení pochází z
centrálního záznamu a musí být na allow-listu `business_1|business_2`.

Přepnutí na cizí subjekt nebo subjekt s nepovoleným připojením skončí HTTP 403 a
zapíše se do centrálního auditu. Bez aktivního subjektu jsou účetní routy
zablokované.

## Business modely a databázová izolace

Budoucí modely podnikatelských dat musejí dědit z abstraktního
`App\Models\Business\BusinessModel`. Tento model získává připojení výhradně přes
stávající `ActiveBusinessContext` a `BusinessConnectionResolver`. Jedinými
povolenými hodnotami enumu `BusinessConnection` jsou `business_1` a
`business_2`; konfigurační allow-list je odvozen přímo z tohoto enumu.

Business model nesmí používat výchozí Laravel connection ani si nastavovat
connection podle HTTP požadavku. Výchozí connection aplikace zůstává `central`
pro uživatele, oprávnění a bezpečnostní audit. Pokud aktivní business context
chybí nebo obsahuje jiné připojení, model selže vlastní výjimkou ještě před SQL
operací. Pokus o ruční přesměrování modelu na jiné připojení je rovněž odmítnut.

První společná business migrace vytváří `company_settings` v obou business
databázích. Další business tabulky zatím nejsou implementované.

## Nastavení fakturačního subjektu

Po zvolení aktivního subjektu je stránka dostupná na:

```text
/nastaveni/subjekt
```

Údaje v `company_settings` jsou autoritativním zdrojem údajů vystavovatele pro
danou business databázi. Tabulka je singleton: unikátní `singleton_key` spolu s
databázovým `CHECK` omezením dovolí pouze jeden řádek s konstantní hodnotou
`1`.

Zobrazení formuláře databázi nemění. Pokud nastavení neexistuje, zobrazí se
bezpečný výchozí stav a první řádek vznikne až při uložení. Změny může ukládat
pouze uživatel s rolí `admin` u aktivního subjektu; ostatní členové mohou
stránku pouze zobrazit.

Centrální tabulka `businesses` zůstává minimální projekcí pro přepínač.
Automatická synchronizace názvu nebo IČO z `company_settings` do `central`
zatím není implementována.

## Testy

Testy jsou záměrně nastavené na skutečný MySQL, ne na SQLite. Výchozí lokální
konfigurace v `phpunit.xml` očekává MySQL na `127.0.0.1:3307` a testovací
databáze uvedené výše. Přizpůsobte pouze lokální testovací hodnoty; nikdy
nepoužívejte produkční databázi.

Před `migrate:fresh` nebo vytvořením dočasných business testovacích tabulek
testovací základ ověří prostředí `testing`, neprázdné a navzájem rozdílné názvy
všech tří databází a jednoznačný marker `test`. Názvy s markerem `local`,
`prod` nebo `production` jsou odmítnuty. Při selhání této kontroly se
destruktivní databázová operace nespustí.

Základní izolační testy používají dočasnou tabulku vytvořenou zvlášť v
testovacích databázích `business_1` a `business_2`; po testu ji odstraní.
Testy modulu nastavení navíc spouštějí skutečnou společnou business migraci a
ověřují shodu schémat, singleton, role i fyzickou izolaci dat.

```bash
php artisan test
```

Pokud lokální PHP nemá globální `php.ini`, je nutné potřebná rozšíření zapnout
pro CLI. V CI i na hostingu má být PHP nakonfigurované standardně.

## Formátování a build

```bash
vendor/bin/pint --test
npm run build
```

Statická analýza zatím není samostatně nakonfigurována. Typy a syntaxe jsou
kontrolovány PHPUnit, Laravel bootem a Pintem; nástroj PHPStan/Larastan je vhodné
přidat v některé z dalších etap.

## Produkční bezpečnostní minimum

- `APP_ENV=production`, `APP_DEBUG=false`;
- unikátní `APP_KEY`;
- HTTPS a `SESSION_SECURE_COOKIE=true`;
- tři samostatné databáze a ideálně tři omezené DB účty;
- document root pouze `/www/fakturace/public/`;
- hesla a SMTP údaje jen v nesledovaném `.env`;
- zápis pouze do `storage` a `bootstrap/cache`;
- před migrací ověřená samostatná záloha každé databáze.

Produkční nasazení není součástí aktuální etapy.
