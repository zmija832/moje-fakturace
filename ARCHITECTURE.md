# Architektura projektu „Moje fakturace“

> Dlouhodobá technická dokumentace pro vývojáře, správce projektu a budoucí
> instance Codexu.

## Stav dokumentu

| Položka | Hodnota |
|---|---|
| Projekt | Moje fakturace |
| Platforma | Laravel 13, PHP 8.3 |
| Charakter aplikace | Soukromá webová aplikace |
| Počet podnikatelských subjektů | 2 |
| Databázová strategie | Jedna centrální a dvě fyzicky oddělené business databáze |
| Výchozí Laravel connection | `central` |
| Povolené business connections | `business_1`, `business_2` |
| Veřejná registrace | Zakázaná |
| Stav business schématu | Implementována první společná tabulka `company_settings` |

Tento dokument popisuje závazná architektonická pravidla. Pokud se implementace
a dokumentace rozcházejí, nesmí být rozdíl tiše ignorován. Nejdříve je nutné
ověřit skutečný stav projektu, určit správné cílové chování a ve stejné změně
aktualizovat implementaci nebo tento dokument.

# 1. Účel projektu

„Moje fakturace“ je soukromá webová fakturační aplikace určená pro správu
vlastních podnikatelských dokladů. Nejde o veřejnou SaaS platformu ani o
multitenant systém, do kterého se mohou samostatně registrovat cizí zákazníci.

Projekt má podporovat dva konkrétní podnikatelské subjekty. Každý z nich má:

- vlastní účetní a obchodní data;
- vlastní klienty, faktury, platby a nastavení;
- vlastní fyzickou MySQL databázi;
- samostatně zvolený aktivní business context;
- izolaci, která nespoléhá pouze na podmínku `WHERE business_id = ...`.

Centrální databáze slouží výhradně pro identitu, přístupy, bezpečnostní audit,
technickou konfiguraci a nalezení správného business připojení. Nikdy v ní
nesmějí být účetní data, klienti, faktury, položky faktur, platby, bankovní účty,
číselné řady nebo daňová nastavení.

Hlavní cíle architektury jsou:

1. zabránit úniku dat mezi oběma subjekty;
2. zabránit tichému zápisu business dat do centrální databáze;
3. udržet doménovou logiku přehlednou a testovatelnou;
4. zachovat účetní historii a auditovatelnost;
5. umožnit bezpečné rozšiřování aplikace po samostatných etapách;
6. zůstat provozně jednoduchou Laravel aplikací bez zbytečné infrastruktury.

# 2. Architektura databází

## 2.1 Přehled

Aplikace používá tři pojmenovaná Laravel databázová připojení.

| Connection | Účel | Obsahuje business data | Je Laravel default |
|---|---|---:|---:|
| `central` | Identita, přístupy, routing, bezpečnostní audit | Ne | Ano |
| `business_1` | Data prvního podnikatelského subjektu | Ano | Ne |
| `business_2` | Data druhého podnikatelského subjektu | Ano | Ne |

```text
                          ┌─────────────────────────┐
                          │      Webový klient      │
                          └────────────┬────────────┘
                                       │ session + přihlášený uživatel
                                       ▼
┌──────────────────────────────────────────────────────────────────────┐
│                         Laravel aplikace                             │
│                                                                      │
│  Autentizace ──► ActiveBusinessContext ──► ConnectionResolver       │
└──────────────┬───────────────────────┬───────────────────────┬───────┘
               │                       │                       │
               ▼                       ▼                       ▼
┌──────────────────────┐  ┌──────────────────────┐  ┌──────────────────────┐
│       central        │  │      business_1      │  │      business_2      │
│                      │  │                      │  │                      │
│ users                │  │ subjekt č. 1         │  │ subjekt č. 2         │
│ businesses           │  │ klienti              │  │ klienti              │
│ přístupy a role      │  │ faktury              │  │ faktury              │
│ session              │  │ platby               │  │ platby               │
│ bezpečnostní audit   │  │ nastavení            │  │ nastavení            │
│ connection routing   │  │ business audit       │  │ business audit       │
└──────────────────────┘  └──────────────────────┘  └──────────────────────┘
          │                         X                         │
          └─────────────────────────X─────────────────────────┘
                   žádné cross-database vazby ani joiny
```

## 2.2 Centrální databáze `central`

Centrální databáze představuje řídicí a bezpečnostní rovinu aplikace.

### Co v ní smí být

- uživatelé a jejich přihlašovací údaje;
- tokeny pro obnovu hesla;
- serverové session;
- evidence dostupných podnikatelských subjektů;
- mapování uživatele na subjekt a role uživatele;
- poslední zvolený subjekt uživatele;
- název Laravel connection pro každý subjekt;
- aktivní/neaktivní stav subjektu;
- minimální údaje potřebné pro centrální přepínač subjektů;
- audit přihlášení, odhlášení a neúspěšných přihlášení;
- audit úspěšného a odmítnutého přepnutí subjektu;
- technická nastavení celé aplikace.

### Co v ní nesmí být

- klienti;
- dodavatelské nebo odběratelské adresy;
- bankovní účty subjektu;
- faktury, zálohové faktury a dobropisy;
- položky dokladů;
- číselné řady a alokovaná čísla;
- platby a párování plateb;
- sazby DPH a daňová konfigurace subjektu;
- PDF dokumenty nebo jejich business metadata;
- pravidelné fakturace;
- obchodní poznámky a business audit;
- exportní účetní data.

Výchozí connection `central` je bezpečná pouze pro centrální modely. Není to
bezpečný fallback pro business modely.

## 2.3 Business databáze `business_1` a `business_2`

Každá business databáze je samostatná bezpečnostní hranice. Její schéma má být
stejné jako schéma druhé business databáze, ale její data jsou zcela oddělená.

### Co v nich smí být

- autoritativní identifikační a fakturační nastavení daného subjektu;
- bankovní účty daného subjektu;
- klienti, jejich adresy a kontakty;
- číselné řady a evidence přidělených čísel;
- faktury a další doklady;
- položky dokladů;
- sazby, daňová pravidla a nastavení plátcovství;
- platby;
- pravidelné fakturační předpisy;
- business audit změn;
- metadata PDF, e-mailů a exportů vztahující se k danému subjektu.

### Co v nich nesmí být

- autentizační hesla;
- globální seznam uživatelů;
- serverové session;
- centrální přístupová oprávnění;
- záznamy druhého subjektu;
- connection name přebíraný z požadavku;
- cizí klíče do tabulek v `central`;
- cizí klíče nebo vazby do druhé business databáze.

## 2.4 Autoritativní data a projekce

Autoritativní data jsou ta, podle kterých se vytvářejí doklady a vyhodnocuje
business logika. Budoucí úplné nastavení subjektu proto patří do jeho business
databáze.

Centrální tabulka `businesses` může obsahovat minimální projekci, například:

- zobrazovaný název pro přepínač;
- krátké označení;
- IČO jako orientační identifikátor;
- vizuální identifikátor;
- aktivní stav a pořadí.

Tato projekce nesmí být bez dalšího považována za autoritativní zdroj pro obsah
faktury. Jakmile vznikne business nastavení subjektu, musí být jednoznačně
zdokumentováno:

| Typ údaje | Autoritativní zdroj |
|---|---|
| Přihlašovací identita uživatele | `central` |
| Přístup uživatele k subjektu | `central` |
| Routing na `business_1` nebo `business_2` | `central` |
| Úplné údaje vystavovatele | Aktivní business databáze |
| Údaje na vystaveném dokladu | Snapshot uložený s dokladem |
| Název v centrálním přepínači | Projekce v `central` |
| Klienti, faktury, platby | Aktivní business databáze |

Synchronizace projekce nesmí předstírat atomickou transakci mezi fyzickými
databázemi. Případné částečné selhání musí být zjistitelné, auditované a
opakovatelné.

## 2.5 Identifikátory a vazby

Business tabulky běžně nepotřebují sloupec `business_id`, protože příslušnost k
subjektu určuje fyzická databáze. Výjimkou jsou centrální tabulky, kde je
`business_id` legitimní pro vztahy jako `user_business_access`.

Pro veřejné URL a route parametry se mají používat náhodné UUID. Interní
primární klíče mohou být číselné. UUID samo o sobě nenahrazuje autorizaci ani
správně nastavený business context.

## 2.6 Aktuální business schéma

První společná business migrace je v `database/migrations/business` a vytváří
tabulku `company_settings` shodně v `business_1` i `business_2`.

Tabulka je autoritativním zdrojem údajů vystavovatele a je navržena jako
singleton. Databáze vynucuje:

- unikátní `singleton_key`;
- konstantní hodnotu `singleton_key = '1'` pomocí `CHECK` constraintu;
- nejvýše jeden řádek v každé fyzické business databázi.

GET formuláře nevytváří data. Při neexistujícím řádku služba vrátí neuložený
výchozí model a první řádek vytvoří až autorizovaný PUT v transakci.

Business migrace se spouštějí výhradně příkazem:

```text
php artisan app:migrate-businesses
php artisan app:migrate-businesses --business=business_1
php artisan app:migrate-businesses --business=business_2
```

Wrapper přijímá pouze hodnoty z `BusinessConnection`, používá pouze adresář
business migrací, nepoužívá `migrate:fresh` a po každé migraci ověřuje návrat
Laravel default connection na `central`.

Centrální `businesses.display_name` a `businesses.registration_number` zatím
zůstávají projekcí pro přepínač. Synchronizace z autoritativního
`company_settings` není implementována.

# 3. Business Context

Business context určuje, se kterým podnikatelským subjektem právě pracuje
konkrétní HTTP požadavek. Nevzniká z query parametru ani formuláře. Je odvozen z
přihlášeného uživatele, serverové session, centrálních oprávnění a
allow-listu.

## 3.1 `BusinessConnection` enum

`App\Enums\BusinessConnection` je jediný typově bezpečný seznam business
connections:

```text
business_1
business_2
```

Enum:

- převádí povolenou hodnotu na Laravel connection name;
- odmítá neznámé hodnoty;
- poskytuje hodnoty pro `config/business.php`;
- brání vzniku několika nezávislých allow-listů.

Přidání dalšího subjektu není pouhé přidání stringu. Vyžadovalo by vědomou změnu
architektury, konfigurace, oprávnění, testů, nasazení a dokumentace.

## 3.2 `ActiveBusinessContext`

`App\Domain\BusinessContext\ActiveBusinessContext` drží centrální model právě
aktivního subjektu pro aktuální aplikační scope.

Jeho odpovědnosti jsou:

- uložit pouze subjekt s povoleným connection name;
- zpřístupnit jeho ID, UUID a zobrazované údaje;
- zpřístupnit connection name resolveru;
- umět context vyčistit;
- při povinném, ale chybějícím subjektu selhat.

Context není globální statická proměnná. Je registrován jako scoped služba, aby
se minimalizovalo riziko přenosu stavu mezi požadavky nebo úlohami.

## 3.3 `BusinessConnectionResolver`

`App\Domain\BusinessContext\BusinessConnectionResolver` je jediná služba, která
převádí aktivní business context na použitelné databázové připojení.

Resolver:

1. načte connection name ze stávajícího `ActiveBusinessContext`;
2. při chybějícím contextu vyhodí `MissingBusinessContext`;
3. převede hodnotu přes `BusinessConnection`;
4. ověří hodnotu proti centrálnímu allow-listu;
5. při neplatné hodnotě vyhodí `InvalidBusinessConnection`;
6. vrátí pouze `business_1` nebo `business_2`.

Resolver nikdy nečte `Request`, query string, formulář, URL ani hlavičku.

## 3.4 `BusinessModel`

`App\Models\Business\BusinessModel` je abstraktní rodič všech budoucích Eloquent
modelů business dat. Podrobnosti jsou v kapitole 6.

Z hlediska contextu je důležité, že model při skutečném použití získá connection
výhradně přes `BusinessConnectionResolver`. Nenastavuje ji jen jednou v
konstruktoru a nikdy nepoužije Laravel default jako náhradní hodnotu.

## 3.5 `BusinessSwitcher`

`App\Domain\BusinessContext\BusinessSwitcher` řídí výběr aktivního subjektu.

Jeho odpovědnosti:

- načíst pouze aktivní subjekty přiřazené uživateli;
- filtrovat je podle serverového allow-listu;
- obnovit platný subjekt ze session;
- případně použít poslední platný subjekt uživatele;
- případně vybrat první povolený subjekt;
- vyčistit context, pokud uživatel nemá žádný povolený subjekt;
- odmítnout přepnutí na cizí nebo neplatný subjekt;
- zapsat výsledek přepnutí do centrálního auditu.

UUID požadovaného subjektu může pocházet z URL, ale databázové connection name
nikoliv. Connection se vždy načte ze serverového centrálního záznamu a znovu
ověří.

## 3.6 Middleware

| Middleware | Účel |
|---|---|
| `auth` | Vyžaduje přihlášeného uživatele |
| `business.context` | Obnoví povolený aktivní subjekt |
| `business.required` | Zablokuje business route bez aktivního subjektu |

Pořadí je bezpečnostně významné:

```text
auth
  └── business.context
        └── business.required
              └── načtení business modelu
                    └── controller / služba / odpověď
```

Business model nesmí být načten před inicializací contextu. Při budoucím použití
implicitního route model bindingu je nutné ověřit pořadí vůči middleware
`SubstituteBindings`. Bezpečnou alternativou je explicitní načtení UUID ve
službě až po proběhnutí middleware.

## 3.7 Tok HTTP požadavku

```text
HTTP request
    │
    ▼
Session middleware
    │
    ▼
auth ── nepřihlášen ──► redirect / 401
    │
    ▼
ResolveActiveBusiness
    │
    ├── načte uživatelovy povolené subjekty z central
    ├── ověří session UUID
    ├── ověří aktivitu a oprávnění
    └── nastaví ActiveBusinessContext
    │
    ▼
RequireActiveBusiness ── context chybí ──► 403
    │
    ▼
Policy / autorizace operace
    │
    ▼
BusinessModel
    │
    ▼
BusinessConnectionResolver
    │
    ├── business_1 ──► SQL pouze do databáze subjektu 1
    ├── business_2 ──► SQL pouze do databáze subjektu 2
    └── jiná hodnota / nic ──► výjimka před SQL
```

# 4. Bezpečnostní pravidla

Následující pravidla jsou závazná pro celý projekt.

| Pravidlo | Vysvětlení |
|---|---|
| Business model nikdy nesmí použít default connection | Default je `central`; fallback by mohl uložit účetní data do centrální databáze. |
| Nepoužívat `DB::setDefaultConnection()` | Globální změna může ovlivnit nesouvisející modely a dlouho běžící procesy. |
| Neměnit za běhu `database.default` | Centrální modely musejí zůstat pevně na `central`; business modely mají vlastní resolver. |
| Connection nikdy nepřebírat z requestu | Query parametr, JSON, formulář, URL ani hlavička nejsou důvěryhodný zdroj routingu. |
| Používat pouze `BusinessConnection` enum | Magic stringy obcházejí typovou a konfigurační kontrolu. |
| Nezavádět druhý business context | Dva contexty mohou nesouhlasit a směrovat jednu operaci do různých databází. |
| Nezavádět druhý connection resolver | Veškerý business routing musí mít jedno kontrolní místo. |
| Každý business model dědí z `BusinessModel` | Přímé dědění z Eloquent `Model` by umožnilo fallback na default connection. |
| Centrální model explicitně používá `central` | Centrální data se nesmějí řídit aktivním tenant contextem. |
| Business tabulky nepoužívají `business_id` | Fyzická databáze je hranicí subjektu; duplicita ID svádí k nebezpečnému logickému multitenancy. |
| `business_id` je povolen v centrálních vazbách | Například `user_business_access` musí spojit centrálního uživatele s centrální evidencí subjektu. |
| Business databáze se navzájem nespojují | Žádné cross-database joiny, uniony ani přímé porovnávání tenant dat v běžné doménové operaci. |
| Nevytvářet cross-database foreign keys | Nasazení, obnova záloh i integrita by byly svázané mezi bezpečnostními hranicemi. |
| Účetní tabulky nikdy nevytvářet v `central` | Platí i pro „dočasné“ zkratky nebo společné tabulky faktur. |
| Connection name není mass assignable z webu | Routing je interní bezpečnostní údaj, nikoliv uživatelská preference. |
| Autorizace probíhá i po autentizaci | Přihlášený uživatel nemusí mít právo k aktivnímu subjektu nebo konkrétní operaci. |
| UUID nenahrazuje autorizaci | Neuhádnutelnost identifikátoru není kontrola oprávnění. |
| Citlivé hodnoty se neobjevují ve výjimkách | Chyby nesmějí obsahovat hesla, DSN, connection stringy ani tajné údaje. |
| Hesla se nelogují ani neseedují | Výchozí seeder nesmí vytvořit účet se známým heslem. |
| Business změna a její audit mají být atomické | Pokud je to možné, patří do stejné transakce ve stejné business databázi. |
| Peněžní hodnoty nepoužívají `float` | Použije se přesný desetinný typ a jednotná pravidla zaokrouhlení. |
| Historické účetní údaje se fyzicky nemažou | Použije se stav, storno nebo archivace podle významu dat. |
| Každý požadavek pracuje nejvýše s jedním subjektem | Změna contextu uprostřed business transakce je zakázaná. |
| CLI a queue musejí explicitně inicializovat context | Nemají HTTP session; před dotazem musí bezpečně zvolit subjekt a po práci context vyčistit. |
| Testy nesmějí použít lokální ani produkční DB | Destruktivní operace se povolí až po bezpečnostní kontrole názvů a prostředí. |

## 4.1 Pravidlo nejmenšího oprávnění

V produkci má mít každé databázové připojení samostatný databázový účet s právy
jen k vlastní databázi. I při aplikační chybě tak účet pro `business_1` nemá mít
možnost číst `business_2` nebo zapisovat do `central`.

## 4.2 Transakční hranice

Jedna business operace má používat transakci na právě aktivním business
connection. Transakce nesmí předstírat atomicitu napříč fyzickými databázemi.

Operace vyžadující konzistenci, například:

- přidělení čísla faktury;
- změna výchozího bankovního účtu;
- uložení faktury a jejích položek;
- spárování platby;
- business změna a její audit;

musí být navrženy s odpovídajícím zamykáním, unikátními indexy a transakcí.

# 5. Struktura projektu

Struktura se má rozšiřovat podle doménových potřeb, nikoliv mechanickým
vytvořením všech možných vrstev.

| Cesta | Význam |
|---|---|
| `app/` | Produkční PHP kód aplikace |
| `app/Domain/` | Doménové a průřezové mechanismy, například business context |
| `app/Domain/BusinessContext/` | Aktivní subjekt, přepínač, resolver a související výjimky |
| `app/Enums/` | Stabilní backed enumy bez magic stringů |
| `app/Models/` | Centrální Eloquent modely a oddělený prostor business modelů |
| `app/Models/Business/` | `BusinessModel` a budoucí modely business databází |
| `app/Http/Controllers/` | Tenká HTTP orchestrace bez složité business logiky |
| `app/Http/Middleware/` | Autentizace contextu a ochrana request pipeline |
| `app/Http/Requests/` | Budoucí autorizace a validace konkrétních formulářů |
| `app/Services/` | Budoucí aplikační služby a transakční use-cases, pokud jsou potřeba |
| `app/Policies/` | Budoucí objektová autorizace business operací |
| `app/Console/Commands/` | Bezpečné interaktivní a provozní Artisan příkazy |
| `app/Providers/` | Registrace scoped služeb, listenerů a framework integrace |
| `app/Listeners/` | Reakce na framework nebo doménové události |
| `config/` | Serverová konfigurace a allow-listy; žádná business data |
| `database/migrations/central/` | Výhradně migrace centrálního schématu |
| `database/migrations/business/` | Společné migrace spouštěné shodně nad oběma business databázemi |
| `database/factories/` | Factory určené především pro testy |
| `database/seeders/` | Explicitní bezpečné seedery bez známých účtů |
| `resources/views/` | Blade šablony |
| `resources/css/` | Tailwind a vlastní styly |
| `resources/js/` | Alpine a omezená klientská interaktivita |
| `routes/web.php` | Webové routy a jejich middleware hranice |
| `routes/console.php` | Konzolové routy a plánované úlohy |
| `tests/Feature/` | Integrační a HTTP testy včetně MySQL izolace |
| `tests/Unit/` | Izolované testy bez zbytečného framework nebo DB bootu |
| `tests/Concerns/` | Testovací bezpečnostní a pomocné traity |
| `tests/Support/` | Test-only modely a další podpůrné objekty |
| `public/` | Jediný veřejný document root |
| `storage/` | Logy, cache a neveřejné generované soubory |

## 5.1 Centrální a business modely

Centrální modely dědí z `CentralModel`, který explicitně používá `central`.
Business modely dědí z `App\Models\Business\BusinessModel`.

Tyto dvě větve se nesmějí slučovat do univerzálního modelu:

```text
Illuminate\Database\Eloquent\Model
    │
    ├── CentralModel
    │     ├── User
    │     ├── Business
    │     └── centrální audity
    │
    └── BusinessModel
          ├── CompanySetting
          └── budoucí Client, Invoice, BankAccount, ...
```

## 5.2 Services a repositories

Služba má vzniknout, pokud zapouzdřuje use-case, transakci nebo invariant.
Nemá vzniknout pouze proto, aby jedním řádkem zavolala Eloquent model.

Repository je vhodný, pokud:

- skrývá složitější persistentní dotazy;
- poskytuje významnou doménovou abstrakci;
- umožňuje bezpečně sjednotit opakované dotazování;
- má jasnou testovací nebo architektonickou hodnotu.

Repository není povinná vrstva pro každý model. Bezúčelná kombinace
controller–service–repository pro jednoduchý read-only dotaz pouze zvyšuje
složitost.

# 6. Business Model

## 6.1 Proč existuje

Laravel běžně použije výchozí databázové připojení, pokud model neurčí jiné.
V tomto projektu je výchozí connection `central`. Zapomenuté nastavení
connection na jediném budoucím modelu by proto mohlo vést k:

- pokusu číst business tabulku v `central`;
- vytvoření business tabulky v nesprávné databázi při chybném návrhu;
- zápisu účetních dat mimo bezpečnostní hranici;
- nekonzistentnímu nebo obtížně zjistitelnému úniku dat.

`BusinessModel` toto riziko centralizovaně odstraňuje.

## 6.2 Jak funguje

Při skutečném použití modelu:

1. model zavolá `BusinessConnectionResolver`;
2. resolver přečte `ActiveBusinessContext`;
3. connection name projde enumem a allow-listem;
4. model dostane pouze `business_1` nebo `business_2`;
5. Eloquent vytvoří dotaz nad zvoleným připojením.

Model také kontroluje běžný pokus o ruční `setConnection()`. Přijmout může jen
connection, které se shoduje s právě vyřešeným contextem. Jiná hodnota je
odmítnuta.

## 6.3 Fail-closed princip

Fail-closed znamená:

```text
context platný    → použij přesně povolenou business databázi
context chybí     → vyhoď výjimku před SQL
context neplatný  → vyhoď výjimku před SQL
ruční override    → vyhoď výjimku
```

Neexistuje větev „context chybí, použij default“. Výpadek nebo programátorská
chyba se projeví jednoznačně a bezpečně namísto tichého pokračování.

## 6.4 Výjimky

| Výjimka | Význam |
|---|---|
| `MissingBusinessContext` | Business model byl použit bez aktivního subjektu |
| `InvalidBusinessConnection` | Context obsahoval nepovolené nebo podvržené připojení |

Text výjimky nesmí vypisovat databázové heslo, DSN ani celý neověřený connection
string.

## 6.5 Pravidla pro nové modely

Každý nový business model:

- dědí z `BusinessModel`;
- má explicitní `$fillable` nebo atribut `Fillable`;
- nepoužívá `$guarded = []`;
- nepřijímá connection name v konstruktoru;
- nečte session ani request;
- neobsahuje `business_id` pouze kvůli filtrování tenanta;
- používá UUID pro veřejnou identifikaci, pokud bude vystaven v URL;
- má test dokazující správné připojení a izolaci.

# 7. Testovací architektura

## 7.1 Testovací databáze

Testy používají skutečný MySQL, nikoliv SQLite.

Doporučené názvy:

| Connection | Testovací databáze |
|---|---|
| `central` | `fakturace_test_central` |
| `business_1` | `fakturace_test_business_1` |
| `business_2` | `fakturace_test_business_2` |

Testy nesmějí používat vývojové databáze `fakturace_local_*` ani jakoukoliv
produkční databázi.

## 7.2 Ochrana před destruktivními operacemi

Trait `EnsuresSafeTestDatabases` je zapojen do základního `Tests\TestCase`.
Kontrola proběhne před `RefreshDatabase` a tedy před `migrate:fresh`.

Ověřuje:

- `APP_ENV=testing`;
- neprázdný název všech tří databází;
- jednoznačný token `test` oddělený podtržítkem nebo pomlčkou;
- vzájemnou rozdílnost všech tří názvů;
- nepřítomnost tokenů `local`, `prod` a `production`.

Pokud kontrola selže, nesmí následovat:

- `migrate:fresh`;
- `drop`;
- `truncate`;
- mazání tabulek;
- reset schématu;
- jiná destruktivní operace.

Bezpečnostní kontrola se nesmí oslabit jen kvůli pohodlnějšímu CI. CI má použít
jednoznačně označené a izolované testovací databáze.

## 7.3 Test izolace BusinessModelu

Testovací model je umístěn pouze v `tests/Support`. Produkční business model ani
produkční migrace kvůli základnímu testu nevznikají.

Test:

1. po bezpečnostní kontrole vytvoří dočasnou tabulku zvlášť v obou business
   testovacích databázích;
2. nastaví context `business_1`;
3. ověří zápis pouze do první databáze;
4. nastaví context `business_2`;
5. ověří zápis pouze do druhé databáze;
6. ověří obousměrnou neviditelnost záznamů;
7. ověří nepřítomnost tabulky v `central`;
8. po testu dočasné tabulky odstraní.

Integrační testy `company_settings` používají skutečnou business migraci.
Ověřují také:

- vytvoření tabulky v obou business databázích a její nepřítomnost v `central`;
- shodné sloupce obou schémat;
- odmítnutí `central` a neznámého connection migračním wrapperem;
- zachování existující sentinel tabulky jako důkaz, že wrapper nepoužívá
  `migrate:fresh`;
- databázovou singleton ochranu;
- role administrátora a read-only uživatele;
- validační pravidla formuláře;
- ignorování podvržených `connection`, `connection_name` a `singleton_key`;
- fyzickou izolaci nastavení obou subjektů.

## 7.4 Povinné typy budoucích testů

Každý business modul má podle rizika obsahovat:

- test bez aktivního contextu;
- test pro `business_1`;
- test pro `business_2`;
- test fyzické izolace;
- HTTP test podvrženého connection parametru;
- autorizační test nepovoleného uživatele nebo role;
- validační test;
- test databázových unikátních omezení;
- test transakčního rollbacku;
- test business auditu;
- test, že centrální databáze nebyla změněna.

U souběžných operací, jako je alokace čísla faktury, musí existovat skutečný
konkurenční integrační test, ne pouze sekvenční unit test.

## 7.5 Testovací data

- Factory a seedery smějí vytvářet testovací data pouze v prostředí `testing`.
- Výchozí `DatabaseSeeder` nesmí vytvořit známý uživatelský účet.
- Produkční správce vzniká explicitním interaktivním příkazem.
- Testovací hesla se nesmějí dostat do produkční konfigurace nebo dokumentace
  určené pro nasazení.

# 8. Coding standard

## 8.1 Obecná pravidla

- Používat aktuální idiomy Laravelu 13 a PHP 8.3.
- Upřednostnit čitelný, explicitní kód před skrytou „magií“.
- Nemít dvě služby se stejnou odpovědností.
- Nezavádět abstrakci bez konkrétního problému, který řeší.
- Zachovávat existující namespace a adresářovou strukturu.
- Používat dependency injection místo service locatoru tam, kde to framework
  rozumně umožňuje.
- Používat backed enumy pro stabilní množiny hodnot.
- Nepoužívat magic stringy pro connection names, role, stavy a typy dokladů.
- Nové názvy a kódy ukládané do DB musí být stabilní a nezávislé na českém
  textu zobrazeném v UI.

## 8.2 Controllery

Controller:

- přijme validovaný požadavek;
- zavolá policy;
- předá práci aplikační službě;
- vrátí redirect, view nebo odpověď;
- neobsahuje složitou business logiku;
- nevolí databázové připojení;
- neprovádí ruční tenant filtrování.

## 8.3 Validace

Pro netriviální vstupy používat `FormRequest`.

`FormRequest` má:

- whitelist povolených polí;
- `authorize()` navázaný na policy nebo gate;
- podmíněná validační pravidla podle typu entity;
- tenant-safe unikátní pravidla používající resolver, nikoliv request
  connection;
- normalizaci prováděnou vědomě a testovatelně.

Validace v UI je pouze pomocná. Autoritativní je vždy serverová validace.

## 8.4 Policies a oprávnění

Každá mutace business dat musí být autorizovaná. Policy ověřuje:

1. přihlášeného uživatele;
2. aktivní subjekt;
3. aktivní přístup uživatele k subjektu;
4. roli uživatele;
5. oprávnění ke konkrétní operaci.

Autorizace nesmí být založena pouze na tom, že route prošla middleware
`business.required`.

## 8.5 Eloquent a mass assignment

- Nepoužívat `$guarded = []`.
- Používat explicitní `$fillable` nebo atribut `Fillable`.
- Nezpřístupňovat interní stavy, UUID, počítadla a connection name běžnému mass
  assignmentu.
- Pro kritické změny používat aplikační službu namísto přímého `update($request->all())`.
- Nepoužívat neomezené `forceFill`, pokud nejde o úzký interní a zdokumentovaný
  případ.

## 8.6 Transakce a zamykání

Transakce je povinná, pokud jedna business operace mění více záznamů nebo
udržuje invariant.

Příklady:

- faktura a její položky;
- přidělení čísla dokladu;
- výchozí bankovní účet;
- faktura a audit změny;
- platba a změna stavu faktury.

Souběh se neřeší pouze kontrolou „nejdříve načti, potom ulož“. Použijí se:

- unikátní indexy;
- `SELECT ... FOR UPDATE`;
- atomické databázové operace;
- transakce na explicitním business connection;
- integrační test souběhu.

## 8.7 Peníze, sazby a čas

- Peníze ukládat jako přesný `DECIMAL` nebo jako celočíselné nejmenší jednotky,
  podle jednotně přijatého návrhu modulu.
- Nikdy nepoužívat `float` pro účetní výpočty.
- Měnu ukládat jako ISO 4217 kód.
- Sazby ukládat přesným desetinným typem.
- Pravidla zaokrouhlení centralizovat a testovat.
- Časové okamžiky ukládat konzistentně, zpravidla v UTC.
- Lokální kalendářní data dokladů vyhodnocovat v časové zóně subjektu.
- Historická hodnota na dokladu musí být snapshot; pozdější změna klienta nebo
  subjektu nesmí přepsat vystavený dokument.

## 8.8 Dokumentace a kvalita

Před dokončením změny se podle rozsahu spouští:

```text
php artisan test
vendor/bin/pint --test
npm run build
git diff --check
git status --short
```

Dokumentace se aktualizuje ve stejné etapě jako architektonická změna. Komentář
v kódu má vysvětlovat důvod nebo invariant, nikoliv pouze opakovat syntaxi.

# 9. Budoucí moduly

Následující tabulka je plán, nikoliv tvrzení, že jsou moduly již implementované.

| Modul | Účel | Databázová oblast |
|---|---|---|
| Company Settings | Autoritativní údaje vystavovatele, měna, splatnost, daňový režim a texty dokladů; základ implementován | Business DB |
| Bankovní účty | Tuzemské účty, IBAN, BIC, měna, aktivita a výchozí účet | Business DB |
| Klienti | Firmy a osoby, IČO, DIČ, kontakty, výchozí obchodní nastavení | Business DB |
| Adresy klientů | Fakturační, doručovací a další adresy | Business DB |
| Kontaktní osoby | Více kontaktů u jednoho klienta | Business DB |
| Číselné řady | Formát, období, další číslo a bezpečná konkurenční alokace | Business DB |
| Sazby DPH | Sazby, typ zdanění a interval platnosti | Business DB |
| Faktury | Hlavička dokladu, stavy, termíny, měna a snapshot obchodních údajů | Business DB |
| Položky faktur | Množství, jednotka, cena, sazba, slevy a přesné součty | Business DB |
| Zálohové doklady | Zálohové faktury a jejich vazby na konečné vyúčtování | Business DB |
| Dobropisy | Opravné daňové a účetní doklady bez přepisování historie | Business DB |
| Platby | Přijaté platby, párování, částečné úhrady a přeplatky | Business DB |
| PDF | Neměnná vizuální reprezentace vydané verze dokladu | Business DB + neveřejné úložiště |
| QR Platba | Platební QR údaje odvozené z faktury a bankovního účtu | Business DB |
| E-mail | Odeslání dokladu, stav doručení a audit odeslání | Business DB |
| Pravidelná fakturace | Předpisy pro opakované vytváření návrhů faktur | Business DB |
| Upomínky | Pravidla a evidence upomínek po splatnosti | Business DB |
| Exporty | Účetní a datové exporty bez spojování subjektů | Business DB |
| Dashboard | Souhrny pouze pro právě aktivní subjekt | Business DB |
| Business audit | Atomická historie změn nastavení a obchodních dat | Business DB |
| Centrální bezpečnostní audit | Přihlášení, odhlášení, odmítnuté přístupy a přepnutí subjektu | `central` |

## 9.1 Company Settings

Nastavení subjektu je první implementovaný business modul a autoritativní zdroj
údajů vystavovatele. Centrální zobrazovaný název je pouze projekce. Tabulka je
singleton v každé business databázi, nikoliv jedna společná tabulka s
`business_id`. Modul zatím neobsahuje logo, ARES, samostatnou správu sazeb DPH
ani synchronizaci centrální projekce.

## 9.2 Bankovní účty

Modul musí podporovat více měn a databázově bezpečně zaručit nejvýše jeden
výchozí účet pro danou měnu. IBAN se normalizuje a validuje checksumem. Citlivé
hodnoty se v auditu maskují.

## 9.3 Klienti

Klient může být firma nebo osoba a může mít více adres a kontaktních osob.
Historické faktury nesmějí číst živá data klienta; při vystavení získají
snapshot.

## 9.4 Číselné řady

Číslo dokladu se alokuje transakčně se zámkem. Unikátní index je poslední
databázová ochrana proti duplicitě. Použitá čísla a vydané doklady se
nepřečíslovávají.

## 9.5 Faktury a položky

Faktura je účetní historický dokument. Musí obsahovat snapshot vystavovatele,
odběratele, platebních údajů a relevantních daňových hodnot. Součty se počítají
jednou definovanou službou s přesným zaokrouhlením.

## 9.6 PDF a e-mail

PDF nesmí být veřejně dostupné z předvídatelné URL. Odeslání e-mailem se
auditovatelně váže ke konkrétní verzi dokladu. Tajné SMTP údaje patří pouze do
environment konfigurace.

## 9.7 Audit

Centrální a business audit mají odlišné bezpečnostní hranice. Business audit
patří do stejné business databáze jako měněná data a pokud možno do stejné
transakce.

# 10. Nikdy nedělej

Tato kapitola obsahuje konkrétní zakázané vzory.

## 10.1 Databázový routing

Nikdy:

```php
DB::setDefaultConnection($request->input('connection'));
```

Nikdy:

```php
config(['database.default' => 'business_1']);
```

Nikdy:

```php
$model->setConnection($request->query('connection'));
```

Nikdy nevytvářej vlastní resolver vedle
`App\Domain\BusinessContext\BusinessConnectionResolver`.

Nikdy neopravuj chybějící context fallbackem:

```php
return $context->connectionName() ?? 'central';
```

## 10.2 Tenant izolace

Nevytvářej jednu společnou tabulku:

```text
central.invoices
    id
    business_id
    ...
```

Nevytvářej účetní tabulku v `central` ani tehdy, když by obsahovala jen málo
záznamů.

Nevytvářej join mezi:

```text
business_1.clients
business_2.clients
```

Nevytvářej cross-database FK z business tabulky na `central.users`. ID
centrálního uživatele může být v business auditu uloženo jako skalární údaj bez
FK.

## 10.3 HTTP a autorizace

- Nepovažuj UUID za oprávnění.
- Nečti connection name z requestu.
- Neprováděj business query před middleware, který nastaví context.
- Neobcházej policy jen proto, že je uživatel přihlášen.
- Nevytvářej veřejnou produkční route pouze pro usnadnění testu.
- Nevystavuj interní databázové názvy ve formuláři nebo veřejném API.

## 10.4 Modely a služby

- Nevytvářej business model přímo z `Illuminate\Database\Eloquent\Model`.
- Nepoužívej `$guarded = []`.
- Nevolej `create($request->all())`.
- Nevkládej složitou fakturační logiku do controlleru nebo Blade šablony.
- Nevytvářej globální helper pro změnu business connection.
- Nevytvářej statický mutable business context.
- Nevytvářej duplicitní service pouze s jiným názvem.
- Nepřidávej repository mechanicky ke každému modelu.

## 10.5 Účetní integrita

- Nepoužívej `float` pro cenu, daň nebo součet.
- Nealokuj číslo faktury bez transakce a databázové unikátnosti.
- Neměň historickou fakturu tím, že se změnil živý klient.
- Nemaž fyzicky vystavené faktury, alokovaná čísla nebo auditní historii.
- Nepřepisuj stav platby bez evidence příslušné operace.
- Nepoužívej aktuální sazbu DPH pro historický doklad bez ohledu na datum
  plnění.
- Nevytvářej automatické české daňové sazby jako skrytý a nadčasový předpoklad.

## 10.6 Testy a provoz

- Nespouštěj `migrate:fresh` bez předchozí ochrany testovacích DB.
- Nepoužívej SQLite jako náhradu integračních MySQL testů.
- Neměň v testu `.env` na lokální nebo produkční databázi.
- Neoslabuj bezpečnostní marker testovací databáze kvůli CI.
- Nevytvářej známý účet nebo heslo ve výchozím seederu.
- Neukládej tajné údaje do repozitáře, dokumentace, logu nebo výjimky.
- Nespouštěj business migrace přes libovolný connection name z CLI argumentu
  bez allow-listu.

# 11. Pokyny pro budoucí implementace

Tato kapitola je závazný pracovní postup pro budoucí Codex i lidské vývojáře.

## 11.1 Před zahájením změny

1. Přečti aktuální zadání celé.
2. Přečti tento dokument, README a stavovou dokumentaci projektu.
3. Zkontroluj skutečný Git stav a zachovej cizí necommitnuté změny.
4. Prohlédni existující implementaci dotčené oblasti.
5. Vyhledej již existující služby, enumy, policies a testovací pomocníky.
6. Ověř, zda požadovaná změna patří do `central`, nebo do business databází.
7. Urči bezpečnostní a transakční invarianty.
8. Teprve potom navrhni nebo implementuj změnu.

## 11.2 Pravidla při implementaci

- Nenarušuj fyzickou tenant izolaci.
- Nepřepisuj fungující části bez prokázané potřeby.
- Nezaváděj nový `ActiveBusinessContext`.
- Nezaváděj druhý `BusinessConnectionResolver`.
- Preferuj rozšíření existující architektury.
- Každý nový business model musí dědit z `BusinessModel`.
- Každý nový business modul musí fungovat samostatně v `business_1` i
  `business_2`.
- Nové účetní a obchodní tabulky patří do společné sady business migrací.
- Do `central` patří pouze identita, oprávnění, routing, technické nastavení a
  centrální bezpečnost.
- Nevytvářej `business_id` v business tabulkách.
- Nepřijímej connection name z HTTP ani z neověřeného CLI vstupu.
- Používej existující enum a allow-list.
- Autorizuj každou mutaci.
- Používej transakce a databázové constraints pro invarianty.
- Vytvářej audit společně s kritickou business změnou.
- Vystavené doklady ukládají snapshot historických údajů.
- Neimplementuj moduly mimo výslovný rozsah aktuální etapy.

## 11.3 Pravidla pro migrace

Před vytvořením migrace:

1. potvrď cílovou databázovou oblast;
2. zkontroluj současné schéma;
3. navrhni indexy, unikátní omezení a cizí klíče;
4. promysli rollback a produkční data;
5. ověř kompatibilitu s produkční verzí MySQL;
6. připrav test obou business databází.

Business migrace musí být jedna společná sada spuštěná samostatně nad
`business_1` a `business_2`. Nesmí existovat odlišná schémata jen proto, že
subjekty mají odlišná data.

## 11.4 Pravidla pro testy

Každá změna tenant dat musí prokázat:

- správné připojení;
- selhání bez contextu;
- izolaci oběma směry;
- odmítnutí podvrženého connection vstupu;
- autorizaci;
- databázovou integritu;
- nepřítomnost zápisu do `central`.

Před destruktivní testovací operací vždy použij existující ochranu testovacích
databází. Nikdy ji neobcházej ručním SQL.

## 11.5 Pravidla pro předání výsledku

Před dokončením:

1. spusť relevantní testy a následně celou testovací sadu;
2. spusť Pint;
3. při změně frontendových assetů nebo šablon spusť produkční build;
4. spusť `git diff --check`;
5. zkontroluj `git status --short`;
6. ověř, že nevznikly soubory nebo změny mimo rozsah;
7. popiš změněné databáze a migrace pravdivě;
8. nevytvářej commit, pokud to uživatel výslovně nepožaduje.

## 11.6 Rozhodovací otázky pro každý nový modul

Budoucí Codex si před implementací musí odpovědět:

| Otázka | Požadovaný výsledek |
|---|---|
| Jde o centrální, nebo business data? | Jednoznačně určená databázová oblast |
| Jak je nastaven business context? | Pouze existující middleware a resolver |
| Co se stane bez contextu? | Fail-closed před SQL |
| Lze operaci podvrhnout requestem? | Connection není součástí vstupu |
| Jaká policy operaci chrání? | Explicitní autorizace |
| Jaké invarianty garantuje DB? | Indexy, FK, constraints a transakce |
| Co je historický snapshot? | Neměnná data dokladu |
| Co se audituje? | Kritické změny bez citlivých hodnot |
| Jak se prokáže izolace? | MySQL test pro oba subjekty |
| Co se stane při souběhu? | Definované zamykání a konkurenční test |
| Je změna v rozsahu etapy? | Mimo rozsah se neimplementuje |

## 11.7 Závěrečný architektonický princip

Nejdůležitější invariant celého projektu je:

```text
Business data lze číst nebo měnit pouze tehdy,
když existuje autorizovaný aktivní business context,
který se bezpečně přeloží právě na business_1 nebo business_2.

Jakákoliv jiná situace musí skončit před SQL operací.
```

Tento invariant má přednost před pohodlím implementace, zkrácením kódu i
dočasným workaroundem.
