# Moje fakturace

Soukromá webová fakturační aplikace pro dva fyzicky oddělené podnikatelské
subjekty. Aktuálně je dokončena Etapa 2, bezpečnostní základ Etapy 3 a základní
business moduly: centrální databáze, přihlášení, oprávnění, audit, bezpečný
přepínač aktivního subjektu, fail-closed business modely a nastavení
fakturačního subjektu včetně bankovních účtů a klientů.

Je implementovaný revizní návrh vydané faktury, přesné výpočty, atomické
vystavení s číslem z tenant-local číselné řady, soukromé PDF s QR Platbou a
synchronní odeslání vystavené faktury e-mailem a immutable ledger ručních plateb
včetně částečných úhrad, přeplatků a storen. Pravidelné fakturace, automatické
notifikace, bankovní import a exporty zatím nejsou implementované.

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

Potřeba jsou minimálně Ctype, cURL, DOM, Fileinfo, Filter, Hash, Iconv, Mbstring,
OpenSSL, PCRE, PDO, PDO MySQL, Session, Tokenizer, XML/XMLWriter a Sodium. PDF
renderuje `dompdf/dompdf`; QR Platbu vytváří `bacon/bacon-qr-code` jako SVG, takže
není vyžadováno GD ani Imagick. Budoucí XLSX a ZIP mohou vyžadovat další
rozšíření.

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

Společné business migrace vytvářejí `company_settings`, `bank_accounts`,
`bank_account_defaults`, `clients`, `document_sequences`,
`document_sequence_defaults`, `document_number_allocations` a `audit_logs`
spolu s `vat_rates` a `vat_rate_defaults` shodně v obou business databázích.
Datový základ faktur doplňuje `invoices`, `invoice_items` a čtyři explicitní
snapshotové tabulky dodavatele, odběratele, bankovního účtu a sazeb DPH.
Tyto tabulky nikdy nevznikají v `central`.

Po architektonické revizi sdílejí veřejné business modely úzký trait pro
serverové UUID a formulářové requesty používají jednu technickou normalizaci
boolean checkboxů. Doménové služby, tenant-safe načítání UUID, transakce a
archivace zůstávají explicitní v jednotlivých modulech.

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

## Bankovní účty

Po zvolení aktivního subjektu je modul dostupný na:

```text
/nastaveni/bankovni-ucty
```

Každý subjekt má účty fyzicky jen ve své business databázi. Administrátor může
účet vytvořit, upravit, aktivovat, deaktivovat, nastavit jako výchozí pro jeho
měnu a archivovat. Role `viewer` má pouze čtení. Veřejné URL používají serverem
generované UUID, nikoliv interní ID.

Podporované měny jsou aktuálně `CZK` a `EUR`. Tuzemské části účtu jsou řetězce,
takže zachovávají úvodní nuly. IBAN a BIC se před validací normalizují; IBAN
prochází kontrolou formátu a MOD-97 checksumu. Účet musí mít alespoň tuzemské
číslo účtu, nebo IBAN.

Tabulka `bank_account_defaults` a složený cizí klíč garantují nejvýše jeden
výchozí účet pro každou měnu a shodu měny účtu. Přepnutí výchozího účtu běží v
transakci se zámkem a databázovou unikátností. Neaktivní ani archivovaný účet
nemůže být výchozí; deaktivace a archivace případné výchozí přiřazení odstraní.
Měnu právě výchozího účtu nelze změnit, dokud administrátor nezvolí jiný
výchozí účet.

Fyzické mazání není podporováno. Archivace je v této etapě jednosměrná a
historický řádek zůstává uložený. Obnova z archivu není zatím implementována.
Vytvoření, změny, stav a výchozí vazby účtu zapisují sanitizovaný business audit.
Celé číslo účtu, IBAN, BIC ani poznámka se do auditu neukládají.

Napojení na bankovní API, import výpisů, párování plateb a QR Platba nejsou v
této etapě implementované.

## Klienti a odběratelé

Modul je dostupný na `/klienti`. Klienti jsou fyzicky uloženi pouze v databázi
aktivního subjektu. Podporuje firmy a fyzické osoby, fakturační adresu, jednu
volitelnou dodací adresu, jednu kontaktní osobu, české a slovenské země,
kontakty a výchozí fakturační nastavení.

Administrátor může klienta vytvořit, upravit, deaktivovat, znovu aktivovat a
jednosměrně archivovat. Člen s rolí `viewer` má pouze čtení. Archivovaný klient
zůstává dostupný v archivním filtru a detailu, ale nelze ho upravit ani měnit
jeho aktivní stav. Klienti se fyzicky nemažou.

`display_name` slouží seznamům, výběrům a budoucím fakturám. Pokud zůstane při
uložení prázdný, služba ho odvodí z názvu firmy nebo jména a příjmení. Neprázdný
ručně uložený název se při pozdější změně právního názvu nebo jména automaticky
nepřepisuje.

Seznam nabízí parametrizované hledání podle názvů, jmen, IČO, e-mailu a města,
filtr typu a stavu, bezpečné řazení z whitelistu a stránkování. Znaky `%` a `_`
se při hledání považují za běžné znaky, nikoliv uživatelské SQL wildcardy.

Klient je pouze zdroj aktuálních údajů. Budoucí vystavená faktura musí převzít
údaje odběratele do vlastního historického snapshotu; změna ani archivace
klienta nesmí později změnit existující fakturu. Faktury a snapshot logika v
této etapě nevznikly.

ARES, VIES, registr plátců DPH, import klientů, slučování duplicit, více adres,
více kontaktních osob nejsou implementované. Změny klienta jsou auditované bez
celých adres, kontaktů, daňových identifikátorů a poznámek.

## Číselné řady dokladů

Modul je dostupný na `/nastaveni/ciselne-rady`. Každá fyzická business databáze
obsahuje samostatnou konfiguraci řad, nejvýše jednu výchozí aktivní řadu pro
každý typ dokladu a neměnnou evidenci skutečně přidělených čísel. Podporované
typy jsou vydaná faktura, zálohová faktura, dobropis a příjmový doklad; samotné
doklady ještě nejsou implementované.

Výsledné číslo vzniká přesně jako `prefix + rok + nulami doplněné pořadové
číslo + suffix`. Aplikace nepřidává skryté oddělovače, proto musí být součástí
prefixu nebo suffixu. Rok lze vynechat nebo zapsat jako `YY` či `YYYY`.
Čítač se buď nikdy neresetuje, nebo používá čtyřmístnou periodu odvozenou z
výslovně předaného data dokladu. Náhled nic nezapisuje.

Skutečnou alokaci provádí pouze `DocumentNumberAllocator`; veřejná HTTP route
pro ni neexistuje. Resolver zvolí aktivní business connection, transakce zamkne
řadu pomocí `lockForUpdate()`, vytvoří neměnný allocation záznam a až poté
posune čítač. Unikátní databázové indexy chrání pořadové i formátované číslo.
Correlation UUID zajišťuje idempotenci opakovaného workflow.

Přidělené číslo se nikdy nevrací, nemaže ani nepřečíslovává. Jakmile byla řada
použita, nelze změnit její typ, formát, počáteční číslo ani způsob resetu; pro
nový formát vzniká nová řada. Deaktivace a jednosměrná archivace transakčně
odstraní případnou výchozí vazbu, allocations však zachovají.

Konfigurace, stav, defaulty a každá skutečná allocation jsou zapisované do
business auditu ve stejné transakci. Idempotentní opakování allocation druhý
audit nevytvoří. Tyto údaje se nekopírují do centrálního auditu.

## Sazby DPH a základní daňová nastavení

Modul je dostupný na `/nastaveni/sazby-dph` a ukládá konfiguraci výhradně do
`vat_rates` a `vat_rate_defaults` aktivní business databáze. Nevytváří žádné
legislativní sazby automaticky a nemění plátcovství uložené v
`company_settings`.

Podporované režimy jsou základní, snížená a nulová sazba, osvobozené plnění,
přenesená daňová povinnost a plnění mimo předmět DPH. Procento je
`DECIMAL(7,4)` reprezentované v PHP řetězcem. Základní, snížený a nulový režim
procento vyžadují; nulová sazba je přesně `0.0000`. Osvobozené, reverse-charge
a out-of-scope plnění ukládají `NULL`, čímž se odlišují od nulové sazby.

Platnost `valid_from` až `valid_to` je včetně obou dnů. `valid_to` může být
prázdné. Neaktivní nebo archivovaná sazba se pro nový doklad nevybere. Dvě
nearchivované verze stejného normalizovaného kódu nesmějí mít překrývající se
interval; služba používá transakci, řádkové zámky a MySQL advisory lock, který
chrání i souběžné vytvoření prvního řádku. Navazující období proto začíná až
den po konci předchozího období.

Kontext `sales` má nejvýše jednu výchozí sazbu. U neplátce může být výchozí
pouze `out_of_scope` nebo `exempt`; samotná evidence ostatních sazeb zůstává
dostupná pro budoucí přípravu. Deaktivace nebo jednosměrná archivace odstraní
výchozí vazbu ve stejné transakci. Fyzické mazání není podporováno.

Sazba v databázi je pouze aktuální konfigurační údaj. Budoucí faktura musí
vybrat sazbu podle data zdanitelného plnění a uložit vlastní neměnný snapshot
typu, procenta a daňového režimu. Pozdější změna konfigurace nesmí změnit
historickou fakturu. Draft živou sazbu nezamyká; teprve snapshot patřící přesně
do `issued_revision` vystavené faktury uzamkne historická pole živé sazby.

Všechny významné změny sazeb a výchozí vazby vznikají v business auditu ve
stejné transakci. Modul neposkytuje daňové poradenství, výpočty faktur,
legislativní aktualizace ani externí daňové API.

## Faktury – části 1 až 3: draft, revize, výpočty a vystavení

`InvoiceDraftService` vytváří návrh vydané faktury a jeho immutable revizi 1.
`invoices` je identita a workflow kořen; drží stav `draft`, `version` a odkaz
`current_revision_id`. Proměnlivá hlavička, přesné součty, položky, VAT summaries
a úplné snapshoty dodavatele, odběratele, zvoleného účtu a sazeb patří do
`invoice_revisions`. Návrh nemá číslo dokladu ani `issued_at`; ty vzniknou až
atomickým workflow vystavení.

`InvoiceDraftEditor` používá číselné optimistické zamykání. Request předává
očekávanou `version`; skutečná změna vytvoří novou immutable revizi a atomicky
posune pointer i verzi. Stará revize se nikdy nepřepisuje. Bezezměnové uložení
revizi ani audit nevytvoří. Validované `correlation_uuid` je tenant-local
idempotency klíč: opakování stejné operace vrátí stejnou revizi bez druhého
auditu a bez dalšího zvýšení verze.

Každá skutečná revize znovu snapshotuje zamčené živé zdroje z aktivní business
databáze. Historická revize nikdy nečte živé `company_settings`, `clients`,
`bank_accounts` ani `vat_rates`. Modely revizí, položek, summaries, operací a
snapshotů odmítají update/delete a totéž vynucují MySQL triggery. Neexistuje
`business_id`, cross-database FK ani vstupní parametr connection.

`InvoiceDecimal` provádí stringovou/celočíselnou aritmetiku bez PHP `float`, bez
BCMath a bez externího balíčku. Množství, jednotkové ceny, mezivýsledky a uložené
částky mají čtyři desetinná místa. Jednotková cena znamená cenu bez DPH. Položka
i celá faktura podporují slevu `none`, `percentage` nebo `fixed`; pevná sleva
nesmí překročit dostupný základ a procentní sleva je v rozsahu 0–100. Celková
sleva se po položkových slevách deterministicky poměrně rozdělí mezi položky,
včetně přesného rezidua na 0,0001, a teprve potom se pro každou sazbu počítá DPH.

Výpočet položky je `quantity × unit_price`, položková sleva, podíl celkové slevy,
základ po slevách, DPH a součet s DPH. U každé položky se obě složky slevy
ukládají samostatně. DPH se počítá a half-up zaokrouhluje po položkách na čtyři
místa. `zero`, `exempt`, `reverse_charge` a `out_of_scope` mají DPH nula, ale
zůstávají oddělenými režimy. Serverové `invoice_vat_summaries` seskupují pouze
stejný typ a procento. Konečný `grand_total` se half-up zaokrouhlí na dvě místa;
u hotovostní úhrady v CZK na celé koruny. Rozdíl se uloží v
`rounding_adjustment` až po výpočtu základu a DPH, takže zaokrouhlovací rozdíl
nezmění základ daně. Před produkčním účetním použitím je stále nutné potvrdit
konkrétní účetní postup a případné zvláštní režimy dokladů.

Vytvoření či editace, nové snapshoty, položky, summaries, idempotency záznam,
pointer a sanitizovaný business audit commitnou nebo rollbacknou v jedné
business transakci. Draftový snapshot sazby DPH je neměnný, ale samotná
existence draftu živou sazbu trvale neuzamyká. Vystavení uzamkne konkrétní
`issued_revision` a její VAT snapshoty se stanou historickým použitím sazeb.

`InvoiceIssuer` tenant-safe zamkne draft a ověří optimistic-lock `version`,
úplnost snapshotů, položek a VAT summaries a znovu serverově přepočítá všechny
částky bez `float`. Pro `bank_transfer` je povinný bankovní snapshot. Potom ve
stejné fyzické business transakci použije explicitní nebo výchozí řadu typu
`issued_invoice`, vytvoří idempotentní allocation svázanou s UUID faktury,
uloží číslo, `issued_revision_id`, `issued_at`, zvýší verzi právě jednou a zapíše
audity `document_number.allocated` a `invoice.issued`. Selhání kteréhokoli kroku
vrátí fakturu, allocation, čítač i audit.

Stavový automat v této etapě obsahuje pouze `draft` (Návrh) a `issued`
(Vystavená). Vystavená faktura čte výhradně `issuedRevision`; Eloquent ochrana,
lokální složené FK, `CHECK` a MySQL triggery blokují další revizi, změnu čísla,
allocation, času, obou revision pointerů, návrat do draftu i fyzické smazání.
Stejné issue correlation UUID je tenant-local idempotency klíč a nevytvoří druhé
číslo ani audit.

## Faktury – část 4: uživatelské rozhraní

České responzivní Blade UI je dostupné na `/faktury`. Nabízí tenant-local seznam
s vyhledáváním, whitelist řazením, stavovými, klientskými, měnovými a datumovými
filtry a stránkováním. Admin může vytvořit návrh, upravit jeho aktuální revizi a
vystavit jej; viewer smí seznam, oba typy detailu a sanitizovanou auditní historii
pouze číst. Vystavená faktura nemá editaci ani jinou mutační akci.

Editor položek používá Alpine.js jen pro prezentační přidání, odebrání a pořadí
řádků. Read-only `POST /faktury/nahled-vypoctu` volá serverový
`InvoiceCalculator`, nic nezapisuje ani neaudituje a jeho výsledek je pouze
orientační. Autoritativní totals, VAT summaries a snapshoty request nikdy
nepřijímá; při uložení je vždy znovu vytvoří existující doménové služby.
Základní jednopoložkový formulář a potvrzení vystavení fungují i bez JavaScriptu.

Editace odesílá serverem vytvořené correlation UUID a očekávanou verzi. Konflikt
neprovádí overwrite ani merge, ale vrátí českou zprávu a vyžádá nové načtení.
Vystavení má explicitní nevratné potvrzení a dovoluje výchozí nebo konkrétní
aktivní řadu `issued_invoice`; controller nikdy nevolá allocator přímo.

Štítek „Po splatnosti“ je odvozen pouze pro vystavený doklad s minulou splatností
a kladným zbývajícím zůstatkem podle platebního ledgeru. Úplnou úhradou nebo
přeplatkem zmizí; nikdy nemění `InvoiceStatus`. Notifikace a kopírování faktury
zůstávají otevřené.

## Faktury – část 5: PDF, QR Platba a e-mail

PDF lze vytvořit pouze z neměnné `issuedRevision`. Samostatný view-model obsahuje
výhradně explicitně povolené snapshoty dodavatele, odběratele, bankovního účtu,
položek, souhrnů DPH a totals; změna živého klienta nebo nastavení proto historický
dokument neovlivní. Dompdf používá vložený font DejaVu Sans, vypnuté vzdálené
zdroje i PHP v šabloně a samostatnou tiskovou Blade šablonu bez navigace.
Composer lock používá `dompdf/dompdf` 3.1.6 (LGPL-2.1) a
`bacon/bacon-qr-code` 3.1.1 (BSD-2-Clause). Dompdf je záměrně použit pro
kontrolovanou jednoduchou CSS 2.1 šablonu; není obecným prohlížečem a vzdálený
HTML/CSS/obrázky nejsou podporovaným vstupem.

Soubory leží na neveřejném disku `invoice_documents` (výchozí kořen
`storage/app/private/invoices`, volitelně `INVOICE_DOCUMENTS_ROOT`). Webový server
musí mít do kořene právo zápisu, ale kořen nesmí být symlinkován do `public`.
Metadata obsahují UUID, bezpečný název, MIME, velikost, SHA-256, verzi šablony a
čas generování. Cesta je interní a nevychází ze jména klienta. Generování používá
dočasný soubor, business transakci, přesun na finální cestu a kompenzační úklid;
záznamy i existující PDF jsou neměnné a regenerace vytváří nový dokument.

QR Platba používá SPD 1.0, CZK, IBAN ze snapshotu, přesnou desetinnou částku,
splatnost a případný nejvýše desetimístný variabilní symbol. Chybějící či neplatný
IBAN, nepodporovaná měna/metoda nebo nekladná částka zobrazí bezpečný fallback a
nezabrání vzniku PDF.

Administrátor může PDF vygenerovat a fakturu synchronně odeslat; viewer smí pouze
náhled, stažení existujícího dokumentu a historii. Stažení vždy prochází policy,
aktivním Business Contextem a tenant-local metadaty, nikdy veřejnou URL. E-mail
má výchozího adresáta ze snapshotu odběratele, dovoluje vědomý admin override,
přikládá konkrétní PDF a ukládá snapshot adresáta, předmětu i těla se stavem
`pending`, `sent` nebo `failed`. Opakování stejného correlation UUID neposílá
druhou zprávu. Audit neobsahuje tělo e-mailu, interní cestu ani SMTP chybu/secrety.

SMTP se nastavuje standardními `MAIL_*` proměnnými; `MAIL_TIMEOUT` je výchozích
15 sekund. Odeslání je záměrně synchronní běžná funkce, nikoli obecný notifikační
systém. SMTP neposkytuje transakční exactly-once hranici společnou s MySQL:
výpadek po přijetí zprávy serverem a před zápisem `sent` může vyžadovat ruční
ověření. Retence a mazání dokumentů nejsou rozhodnuty a aplikace nenabízí delete
operaci.

SMTP se automatickými testy nikdy nekontaktuje (používají `array`/Mail fake).
Ruční ověření proveďte jen v bezpečném staging prostředí: nastavte `MAIL_MAILER`,
host, port, šifrování, uživatele, heslo a odesílatele v nesledovaném `.env`,
odešlete jednu zkušební issued fakturu do kontrolované schránky a ověřte přílohu
i stav historie. Heslo nevkládejte do CLI argumentu, logu ani databáze.

## Faktury – část 6: platby

`invoice_payments` je samostatný tenant-local immutable ledger pouze v obou
business databázích. Záznam typu `payment` přičítá kladnou částku, záznam typu
`reversal` ji odečítá a odkazuje složeným lokálním cizím klíčem na původní platbu
téže faktury. Původní záznam se neupravuje ani nemaže; Eloquent ochrana a MySQL
triggery blokují `UPDATE` i `DELETE`. Částečná storna jsou povolena nejvýše do
dosud nereverzované částky původní platby.

Ledger je zdroj pravdy. `paid_total` vzniká jako součet plateb minus součet
reversalů, `remaining_total = grand_total - paid_total`; všechny operace používají
`InvoiceDecimal` a přesné `DECIMAL(19,4)`, nikdy `float`. Stav úhrady se neukládá
do faktury ani do issued revize, ale odvozuje se jako `unpaid`, `partially_paid`,
`paid` nebo `overpaid`. Přeplatek je záporný remaining total a samostatně se
zobrazuje jeho absolutní hodnota.

Platbu lze přidat pouze k `issued` faktuře a ve stejné měně. Služba resolverem
vybere aktivní business connection, v explicitní transakci zamkne fakturu,
ověří correlation UUID, vloží ledger a atomicky zapíše sanitizovaný audit.
Correlation UUID je unikátní uvnitř fyzické business DB; opakování stejné
operace nevytvoří druhý záznam ani audit. Reversal má vlastní UUID, datum, důvod
a correlation UUID. Selhání auditu rollbackne celý insert.

Admin může na detailu vystavené faktury přidat platbu nebo vytvořit částečné či
úplné storno. Viewer vidí souhrn a historii pouze pro čtení. Seznam faktur nabízí
platební filtry a přesné agregované projekce bez N+1; dashboard ukazuje tenant-local
souhrn po měnách. Request nesmí poslat autoritativní součty, stav, invoice ID,
business ID, connection, external ID ani reversal vazbu. Automatický bankovní
import a párování zůstávají budoucí; enum `future_bank_import` a unikátní dvojice
source/external ID jsou pouze schématická příprava.

Po commitu vzniká explicitní immutable `InvoicePaymentChanged` snapshot s
notifikačními záměry pro správce a klienta. Nemá žádný listener, neposílá e-mail,
není persistentní outbox a neslibuje exactly-once doručení. Budoucí notifikační
modul musí před zapojením SMTP doplnit tenant-local outbox, retry pravidla,
preference adresátů a vlastní immutable historii doručení.

## Business audit změn

Read-only modul je dostupný na `/nastaveni/audit`. `central` nadále obsahuje jen
bezpečnostní události přihlášení a přepínání subjektů. Obchodní změny se ukládají
do `audit_logs` výhradně ve fyzické databázi aktivního subjektu.

Audit pokrývá nastavení subjektu, bankovní účty a jejich defaulty, klienty,
číselné řady a jejich defaulty, alokace čísel a vytvoření či skutečnou editaci
fakturačního draftu, vystavení, dokumenty, doručení i platební ledger. Doménové služby volají
`BusinessAuditWriter` explicitně uvnitř své transakce. Writer odmítne samostatný
zápis mimo transakci; selhání auditu proto rollbackne i obchodní změnu.

`BusinessAuditSanitizer` používá whitelist pro každý typ entity. Citlivá pole se
buď maskují na poslední čtyři znaky, nebo se ukládá pouze jejich název v
`changed_fields`. Nikdy se neukládají hesla, tokeny, session, connection name,
celá bankovní čísla, celé adresy, klientské kontakty ani poznámky.

Každá webová operace dostane serverově generovaný request UUID vrácený v
hlavičce `X-Request-ID`. Centrální uživatel zatím nemá UUID, proto audit používá
bezpečný textový identifikátor `central-user:<id>` bez cross-database FK a
snapshot jména a e-mailu.

Auditní záznam je aplikačně neměnný a nemá update/delete/restore route ani
cleanup scheduler. Retenční politika musí být rozhodnuta před produkčním
nasazením; automatické mazání se nyní neprovádí.

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
Testy business modulů navíc spouštějí skutečné společné migrace a ověřují shodu
schémat, singleton nastavení subjektu, cizí klíče bankovních účtů, role,
validaci, souběžnou změnu výchozího účtu, klientské vyhledávání a fyzickou
izolaci dat. Číselné řady navíc mají skutečný víceprocesový test transakční
alokace nad MySQL; dvě nezávislá PHP workflow nesmějí získat stejné číslo.

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
