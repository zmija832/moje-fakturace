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
| Stav business schématu | Implementovány `company_settings`, bankovní účty, klienti, číselné řady a společný business audit |

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

Společné migrace v `database/migrations/business` vytvářejí
`company_settings`, `bank_accounts`, `bank_account_defaults`, `clients`,
`document_sequences`, `document_sequence_defaults`,
`document_number_allocations`, `vat_rates`, `vat_rate_defaults` a `audit_logs`
shodně v `business_1` i `business_2`.

Tabulka je autoritativním zdrojem údajů vystavovatele a je navržena jako
singleton. Databáze vynucuje:

- unikátní `singleton_key`;
- konstantní hodnotu `singleton_key = '1'` pomocí `CHECK` constraintu;
- nejvýše jeden řádek v každé fyzické business databázi.

GET formuláře nevytváří data. Při neexistujícím řádku služba vrátí neuložený
výchozí model a první řádek vytvoří až autorizovaný PUT v transakci.

### Schéma `bank_accounts`

| Sloupec | Typ | Význam |
|---|---|---|
| `id` | unsigned bigint, PK | Interní identifikátor |
| `uuid` | UUID, unique | Serverově generovaný veřejný identifikátor |
| `name` | varchar(255) | Povinný název účtu |
| `domestic_prefix` | varchar(10), nullable | Tuzemský prefix jako řetězec |
| `domestic_account_number` | varchar(32), nullable | Tuzemské číslo se zachováním nul |
| `bank_code` | varchar(16), nullable | Kód banky; aplikace pro český formát vyžaduje přesně čtyři číslice |
| `iban` | varchar(34), nullable | Normalizovaný IBAN |
| `bic` | varchar(11), nullable | Normalizovaný BIC/SWIFT |
| `currency` | char(3) | Podporovaný ISO 4217 kód |
| `is_active` | boolean, default true | Dočasná dostupnost účtu |
| `sort_order` | unsigned smallint, default 0 | Uživatelské pořadí |
| `note` | text, nullable | Interní poznámka |
| `archived_at` | timestamp, nullable | Jednosměrná historická archivace |
| `created_at`, `updated_at` | timestamps | Časová metadata |

Databázový `CHECK` vyžaduje neprázdné `domestic_account_number` nebo `iban`.
Index nad stavem, měnou a pořadím podporuje seznam. Kombinace `(id, currency)`
je unikátní kvůli složenému cizímu klíči výchozího účtu.

### Schéma `bank_account_defaults`

| Sloupec | Typ | Význam |
|---|---|---|
| `currency` | char(3), PK | Právě jedna výchozí vazba pro měnu |
| `bank_account_id` | unsigned bigint, unique | Výchozí účet; jeden účet patří jen své měně |
| `created_at`, `updated_at` | timestamps | Časová metadata |

Složený FK `(bank_account_id, currency)` odkazuje na
`bank_accounts(id, currency)` uvnitř téže business databáze. Tím databáze
vynucuje existenci účtu a shodu měny. FK používá `RESTRICT` pro update i delete;
běžné fyzické mazání účtů neexistuje. Aktivní a nearchivovaný stav vynucuje
`BankAccountService` uvnitř explicitní transakce.

### Schéma `clients`

| Oblast | Sloupce a typy |
|---|---|
| Identita | `id` unsigned bigint PK, `uuid` UUID unique, `type` varchar(16), `display_name` varchar(255) |
| Firma nebo osoba | `company_name` varchar(255), `first_name` a `last_name` varchar(128), vše nullable podle typu |
| Identifikátory | `registration_number`, `tax_id`, `vat_id` varchar(32), nullable |
| Kontakty | `email` a `website` varchar(255), `phone` varchar(64), `contact_person` varchar(255), nullable |
| Fakturační adresa | `street` varchar(255), `house_number` a `orientation_number` varchar(32), `city` varchar(128), `postal_code` varchar(16), `country_code` char(2) |
| Dodací adresa | odpovídající `delivery_*` sloupce, všechny nullable |
| Výchozí fakturace | `default_currency` char(3), `default_due_days` unsigned smallint, `default_payment_method` varchar(32), `language` varchar(10), nullable |
| Stav | `note` text nullable, `is_active` boolean default true, `archived_at` timestamp nullable, timestamps |

`type` používá stabilní hodnoty `company` a `person`, které navíc chrání
databázový `CHECK`. UUID je unikátní pouze uvnitř fyzické business databáze.
IČO, e-mail, název, DIČ ani IČ DPH nejsou unikátní; legitimní duplicity se
neblokují ani automaticky neslučují. Stavový index podporuje výchozí seznam,
typ, archiv a řazení podle zobrazovaného názvu.

### Schéma `document_sequences`

| Sloupec | Typ | Význam |
|---|---|---|
| `id` | unsigned bigint, PK | Interní identifikátor řady |
| `uuid` | UUID, unique | Serverově generovaný route identifikátor |
| `document_type` | varchar(32) | `issued_invoice`, `advance_invoice`, `credit_note` nebo `cash_receipt` |
| `name` | varchar(255) | Uživatelský název; není unikátní |
| `prefix`, `suffix` | varchar(64) | Části formátu včetně všech požadovaných oddělovačů |
| `year_format` | varchar(8) | `none`, `yy` nebo `yyyy` |
| `sequence_digits` | unsigned tinyint | Šířka pořadí 1 až 12 číslic |
| `start_number` | unsigned bigint | První číslo nové periody, 1 až 999999999999 |
| `next_number` | unsigned bigint | Technický čítač mimo mass assignment a formulář |
| `reset_period` | varchar(16) | `never` nebo `yearly` |
| `current_period` | char(4), nullable | Perioda reprezentovaná čítačem; pro `never` vždy `NULL` |
| `is_active` | boolean | Dostupnost pro default a alokaci |
| `sort_order` | unsigned smallint | Uživatelské pořadí |
| `archived_at` | timestamp, nullable | Jednosměrná archivace |
| `created_at`, `updated_at` | timestamps | Časová metadata |

Databázový `CHECK` chrání enumové hodnoty, rozsah číslic, čítače a vztah resetu
k `current_period`. Složená unikátnost typu, prefixu, suffixu, formátu roku,
šířky a resetu odmítá dvě nerozlišitelné konfigurace. Název unikátní není.
Kombinace `(id, document_type)` je unikátní pro složené cizí klíče.

### Schéma `document_sequence_defaults`

| Sloupec | Typ | Význam |
|---|---|---|
| `document_type` | varchar(32), PK | Nejvýše jeden default pro každý typ dokladu |
| `document_sequence_id` | unsigned bigint, unique | Odkaz na právě jednu řadu |
| `created_at`, `updated_at` | timestamps | Časová metadata |

Složený FK `(document_sequence_id, document_type)` odkazuje uvnitř stejné
business databáze na `document_sequences(id, document_type)`. Databáze tím
vynucuje existenci řady i shodu typu. Aktivní a nearchivovaný stav kontroluje
`DocumentSequenceService` v transakci. Deaktivace i archivace default odstraní
ve stejné transakci.

### Schéma `document_number_allocations`

| Sloupec | Typ | Význam |
|---|---|---|
| `id` | unsigned bigint, PK | Interní identifikátor allocation |
| `correlation_uuid` | UUID, unique | Serverový idempotency klíč workflow |
| `document_sequence_id` | unsigned bigint | Řada, která číslo přidělila |
| `document_type` | varchar(32) | Historicky uložený typ se složeným FK na řadu |
| `period` | varchar(16) | Čtyřmístný rok nebo explicitní `never` |
| `sequence_number` | unsigned bigint | Přidělené pořadové číslo |
| `formatted_number` | varchar(255) | Neměnná výsledná reprezentace |
| `allocated_at` | timestamp | Okamžik skutečné alokace |
| `document_uuid` | UUID, nullable | Budoucí vazba; doklady zatím neexistují |
| `created_at`, `updated_at` | timestamps | Časová metadata |

Unikátní `(document_sequence_id, period, sequence_number)` brání opakování
pořadí v řadě a periodě. Unikátní `(document_type, formatted_number)` brání
stejnému viditelnému číslu v rámci jednoho typu dokladu, ale dovoluje shodný
formát různým typům. `correlation_uuid` je unikátní v jedné fyzické business
databázi. Allocation je ledger: model odmítá update/delete a neexistuje pro něj
HTTP update ani delete route. Fyzická izolace zabraňuje čtení correlation UUID
z druhého subjektu.

### Schéma `audit_logs`

| Sloupec | Typ | Význam |
|---|---|---|
| `id` | unsigned bigint, PK | Interní pořadí záznamu |
| `uuid` | UUID, unique | Serverový read-only route identifikátor |
| `event` | varchar(64), index | Stabilní kód z `BusinessAuditEvent` |
| `actor_user_uuid` | varchar(64), nullable, index | `central-user:<id>` bez cross-database FK |
| `actor_name`, `actor_email` | varchar(255), nullable | Snapshot identity centrálního uživatele |
| `auditable_type` | varchar(64) | Stabilní typ hlavní entity |
| `auditable_uuid` | UUID, nullable | Veřejný identifikátor hlavní entity |
| `subject_type`, `subject_uuid` | varchar(64) a UUID, nullable | Doplňující bezpečný kontext |
| `old_values`, `new_values` | JSON, nullable | Jen sanitizované whitelistované hodnoty |
| `changed_fields` | JSON, nullable | Skutečně změněná pole; citlivá pouze názvem |
| `metadata` | JSON, nullable | Omezený technický kontext události |
| `request_id` | varchar(64), nullable, index | Serverové UUID HTTP operace |
| `ip_address` | varchar(45), nullable | IPv4/IPv6, pokud je dostupná |
| `user_agent` | varchar(512), nullable | Délkově omezený User-Agent |
| `occurred_at` | timestamp(6), index | Přesný okamžik události |
| `created_at`, `updated_at` | timestamps(6) | Insert metadata; po vložení se nemění |

Indexy dále pokrývají `(auditable_type, auditable_uuid)` a
`(subject_type, subject_uuid)`. Tabulka nemá FK, `business_id` ani connection
name. Model odmítá update/delete a nemá mutační route ani cleanup scheduler.

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
| `app/Domain/` | Doménové a průřezové mechanismy, například business context a normalizace bankovních údajů |
| `app/Domain/BusinessContext/` | Aktivní subjekt, přepínač, resolver a související výjimky |
| `app/Enums/` | Stabilní backed enumy bez magic stringů |
| `app/Models/` | Centrální Eloquent modely a oddělený prostor business modelů |
| `app/Models/Business/` | `BusinessModel` a konkrétní modely business databází |
| `app/Http/Controllers/` | Tenká HTTP orchestrace bez složité business logiky |
| `app/Http/Middleware/` | Autentizace contextu a ochrana request pipeline |
| `app/Http/Requests/` | Autorizace, whitelist a validace konkrétních formulářů |
| `app/Services/` | Aplikační služby a transakční use-cases, pokud jsou potřeba |
| `app/Policies/` | Objektová autorizace business operací |
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
          ├── BankAccount
          ├── BankAccountDefault
          ├── Client
          └── budoucí Invoice, ...
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

Integrační testy bankovních účtů používají stejné reálné MySQL business
databáze a navíc ověřují:

- shodné schéma `bank_accounts` a `bank_account_defaults` v obou databázích;
- nepřítomnost obou tabulek v `central`;
- `CHECK` požadující tuzemské číslo účtu nebo IBAN;
- složený cizí klíč zajišťující shodu měny účtu a výchozího přiřazení;
- jediný výchozí účet pro každou měnu;
- normalizaci identifikátorů a MOD-97 validaci IBAN;
- serverové UUID a zákaz mass assignmentu technických polí;
- role `admin` a read-only `viewer`;
- neviditelnost UUID existujícího pouze ve druhé business databázi;
- deaktivaci a jednosměrnou archivaci bez fyzického mazání;
- skutečný souběh dvou procesů při změně výchozího účtu.

Integrační a HTTP testy klientů nad stejnou infrastrukturou ověřují shodné
schéma `clients`, serverové a databázově unikátní UUID, stejná UUID v obou
fyzických databázích, fail-closed model, normalizaci, podmíněnou validaci firmy,
osoby a dodací adresy, tenant-safe CRUD, jednosměrnou archivaci, role,
vyhledávání, filtry, bezpečný sort a stránkování.

Testy číselných řad ověřují všechny tři tabulky v obou business databázích a
jejich nepřítomnost v `central`, složené FK pouze uvnitř stejné fyzické DB,
unikátní default pro typ, unikátní allocations, fail-closed modely, serverové
UUID, nemožnost podstrčit connection a technické čítače, roční i trvalé periody,
náhled bez zápisu, rollback čítače při selhání, idempotenci, neměnnost ledgeru,
uzamčení použitého formátu a jednosměrnou archivaci. Povinný concurrency test
spouští nejméně dva nezávislé PHP procesy nad skutečným MySQL, opakuje souběh a
ověřuje rozdílná čísla, přesný posun `next_number` i konzistentní periodu.

Testy business auditu ověřují shodné `audit_logs` bez FK v obou business DB,
nepřítomnost v `central`, fail-closed a neměnný model, jednotný sanitizer,
maskování, actor a request ID, každou podporovanou událost, read-only UI a
tenant-safe filtry. Vynucené selhání auditního insertu musí rollbacknout Company
Settings, bankovní účet, klienta, číselnou řadu i allocation včetně čítače.
Víceprocesový test požaduje právě jeden audit na každou skutečnou allocation.

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

## 8.9 Sdílené infrastrukturní vzory

Po revizi prvních tří business modulů jsou vědomě sdílené pouze malé technické
části:

| Vzor | Odpovědnost | Bezpečnostní hranice |
|---|---|---|
| `BusinessModel` | Fail-closed výběr aktivního business connection | Nikdy nepřijímá connection zvenčí a nefallbackuje na `central` |
| `BusinessPolicy` | Členství a role u aktivního subjektu | Čtení vyžaduje členství, mutace roli `admin` |
| `HasServerGeneratedUuid` | Serverové UUID a route key `uuid` | UUID není mass assignable a nenahrazuje autorizaci |
| `NormalizesBooleanInput` | Jednotný převod HTML checkboxů ve FormRequestech | Neznámou hodnotu zachová pro následné odmítnutí validací |
| `CompanySettingOptions` a enumy | Stabilní země, měny, jazyky, role a typy | Nevznikají paralelní seznamy magic stringů |
| Sdílený aplikační layout | Jedna sada desktopových a mobilních navigačních položek | Business odkazy se zobrazí pouze s aktivním subjektem |

Nová sdílená abstrakce smí vzniknout jen při nejméně dvou skutečných použitích,
nesmí přijímat connection a musí zmenšit kód bez skrytí doménového významu.

## 8.10 Co zůstává explicitně doménové

Nevytváří se společný CRUD service ani univerzální repository. Následující
operace zůstávají ve své konkrétní službě:

- singleton uložení `CompanySetting`;
- výchozí bankovní účet, složený FK a odstranění výchozí vazby;
- typ klienta, generování `display_name`, dodací adresa a klientské hledání;
- aktivace, deaktivace a archivace s důsledky specifickými pro modul;
- budoucí alokace čísla dokladu a vystavení faktury.

Business služba smí získat connection pouze přes existující
`BusinessConnectionResolver`. Každá mutace používá transakci na explicitním
business connection. `lockForUpdate()` se používá pro konkurenční invariant
nebo ochranu řádku během stavové mutace, ne automaticky pro každý read.

Tenant-safe načítání veřejného UUID probíhá ve službě až po middleware
`business.context` a `business.required`. Dotaz používá business model, a tedy
aktivní fyzickou databázi. Služba nepřijímá connection, nepoužívá implicitní
route model binding a UUID z druhé databáze skončí 404. Podmínky jako
`whereNull('archived_at')` zůstávají u konkrétní use-case metody, protože jsou
doménové, nikoliv obecné.

Jednosměrná archivace:

- probíhá v transakci;
- nastaví `is_active = false`;
- zachová fyzický řádek;
- odmítne opakovanou archivaci, aby se nepřepsal historický čas;
- zablokuje editaci a opětovnou aktivaci;
- u bankovního účtu atomicky odstraní výchozí vazbu.

FormRequest je nadále samostatný pro konkrétní modul. Sdílet lze jen technickou
normalizaci; whitelist polí, podmíněná pravidla, české názvy atributů a policy
autorizace zůstávají explicitní. Controller používá pouze `validated()`.

Blade komponenta nebo partial smí sdílet pouze stabilní vizuální prvek. Musí
zachovat `old()` hodnoty, konkrétní validační chybu, popisek, přístupnost a
fungování bez JavaScriptu. Doménové texty a potvrzení se do generické komponenty
nesmějí schovat. Desktop a mobil používají stejný zdroj navigačních položek.

Test každého nového business modulu musí prokázat fyzickou izolaci oběma směry,
stejné UUID v obou databázích, fail-closed chování bez contextu, nemožnost
podvržení connection, nepřítomnost tabulky v `central`, role, 403/404 a zachování
default connection `central`. Konkurenční invariant vyžaduje reálný MySQL test.

# 9. Moduly a další plán

Tabulka rozlišuje implementované části a další plán.

| Modul | Stav | Účel | Databázová oblast |
|---|---|---|---|
| Company Settings | Implementováno | Autoritativní údaje vystavovatele, měna, splatnost, daňový režim a texty dokladů | Business DB |
| Bankovní účty | Implementováno | Tuzemské účty, IBAN, BIC, měna, aktivita, archiv a výchozí účet | Business DB |
| Klienti | Implementováno | Firmy a osoby, fakturační a jedna dodací adresa, jeden kontakt, vyhledávání a výchozí nastavení | Business DB |
| Adresy klientů | Plán | Fakturační, doručovací a další adresy | Business DB |
| Kontaktní osoby | Plán | Více kontaktů u jednoho klienta | Business DB |
| Číselné řady | Implementováno | Konfigurace, default pro typ, neměnný ledger a bezpečná konkurenční alokace | Business DB |
| Sazby DPH | Implementováno | Sazby, daňové režimy, časová platnost a default pro prodej | Business DB |
| Faktury | Plán | Hlavička dokladu, stavy, termíny, měna a snapshot obchodních údajů | Business DB |
| Položky faktur | Plán | Množství, jednotka, cena, sazba, slevy a přesné součty | Business DB |
| Zálohové doklady | Plán | Zálohové faktury a jejich vazby na konečné vyúčtování | Business DB |
| Dobropisy | Plán | Opravné daňové a účetní doklady bez přepisování historie | Business DB |
| Platby | Plán | Přijaté platby, párování, částečné úhrady a přeplatky | Business DB |
| PDF | Plán | Neměnná vizuální reprezentace vydané verze dokladu | Business DB + neveřejné úložiště |
| QR Platba | Plán | Platební QR údaje odvozené z faktury a bankovního účtu | Business DB |
| E-mail | Plán | Odeslání dokladu, stav doručení a audit odeslání | Business DB |
| Pravidelná fakturace | Plán | Předpisy pro opakované vytváření návrhů faktur | Business DB |
| Upomínky | Plán | Pravidla a evidence upomínek po splatnosti | Business DB |
| Exporty | Plán | Účetní a datové exporty bez spojování subjektů | Business DB |
| Dashboard | Plán | Souhrny pouze pro právě aktivní subjekt | Business DB |
| Business audit | Implementováno | Atomická sanitizovaná historie změn s read-only UI | Business DB |
| Centrální bezpečnostní audit | Implementováno | Přihlášení, odhlášení, odmítnuté přístupy a přepnutí subjektu | `central` |

## 9.1 Company Settings

Nastavení subjektu je první implementovaný business modul a autoritativní zdroj
údajů vystavovatele. Centrální zobrazovaný název je pouze projekce. Tabulka je
singleton v každé business databázi, nikoliv jedna společná tabulka s
`business_id`. Modul zatím neobsahuje logo, ARES, samostatnou správu sazeb DPH
ani synchronizaci centrální projekce.

## 9.2 Bankovní účty

Modul je implementovaný nad tabulkami `bank_accounts` a
`bank_account_defaults` v každé business databázi. Podporuje více účtů, měny
`CZK` a `EUR`, tuzemské části účtu uložené jako řetězce, IBAN, BIC, pořadí,
poznámku, aktivaci, deaktivaci a archivaci. UUID vzniká serverově a používá se
ve veřejných URL.

Nejvýše jeden výchozí aktivní a nearchivovaný účet pro měnu zajišťuje primární
klíč v `bank_account_defaults`, složený cizí klíč na ID a měnu, transakce a
zámek v `BankAccountService`. Deaktivace nebo archivace výchozí přiřazení
odstraní. Měnu výchozího účtu nelze změnit, dokud není vybrán jiný výchozí
účet. IBAN se normalizuje a validuje checksumem MOD-97.

Účty se fyzicky nemažou. Archivace je v aktuální etapě jednosměrná; obnova
archivovaného účtu není implementovaná. Vytvoření, změna, stav, archivace a
defaulty se auditují. Tuzemský účet a IBAN jsou pouze maskované na poslední
čtyři znaky; BIC, prefix a poznámka se ukládají jen názvem změněného pole.

Napojení bankovních API, import bankovních výpisů, párování plateb a QR Platba
zůstávají mimo tento modul a budou řešeny samostatně.

## 9.3 Klienti

Klient může být firma (`company`) nebo fyzická osoba (`person`). Má jednu
fakturační adresu, nejvýše jednu dodací adresu a nejvýše jednu kontaktní osobu.
`display_name` se při prázdném vstupu vytvoří z názvu firmy nebo jména osoby.
Ručně zadaná neprázdná hodnota se při změně ostatních jmenných polí automaticky
nepřepisuje.

Seznam je stránkovaný a vyhledává parametrizovaně pouze v aktivní business
databázi. Podporuje typ firmy/osoby a stavy aktivní, neaktivní, archivovaný a
všechny nearchivované. Výchozí filtr archivované klienty nezobrazuje. Řazení je
omezeno whitelistem a SQL wildcard znaky z uživatelského hledání jsou escapované.

Deaktivace je vratná. Archivace je jednosměrná, nastaví klienta jako neaktivního
a řádek fyzicky zachová. Archivovaný klient může být zobrazen, ale nelze ho
upravit, aktivovat ani deaktivovat.

Klient představuje zdroj aktuálních údajů. Budoucí vystavená faktura musí
zkopírovat identitu, adresy a kontaktní údaje do vlastních snapshot polí.
Pozdější změna nebo archivace klienta nesmí změnit historickou fakturu. Snapshot
logika ani faktury nejsou součástí tohoto modulu.

ARES, VIES, registr plátců DPH, import, automatické slučování duplicit, více
adres a více kontaktů zůstávají neimplementované. Audit neukládá celé adresy,
e-mail, telefon, kontaktní osobu, DIČ, IČ DPH ani poznámku.

## 9.4 Číselné řady

Modul odděluje editovatelnou konfiguraci `document_sequences`, jediný default
pro typ v `document_sequence_defaults` a neměnný allocation ledger. Podporuje
typy vydaná faktura, zálohová faktura, dobropis a příjmový doklad. Tyto typy
připravují budoucí workflow, ale tabulky dokladů v této etapě nevznikly.

Formát je deterministicky `prefix + rok + pořadí doplněné nulami + suffix`.
Nevkládá žádný implicitní oddělovač. Například prefix `FV-`, rok `yyyy`, pět
číslic a pořadí 12 vytvoří `FV-202600012`; chce-li uživatel jiný oddělovač,
musí jej vyjádřit konfigurací. Serverový náhled dostává datum dokladu a nic
nezapisuje; JavaScriptový náhled je pouze UX pomůcka.

Při `never` se používá jediný nepřerušovaný čítač a allocation perioda `never`.
Při `yearly` se perioda odvodí výhradně z předaného data dokladu. První číslo
dosud nepoužitého roku je `start_number`; při návratu do již použitého roku se
pokračuje za jeho nejvyšší allocation, takže ani zpětně datovaný doklad
nevytvoří duplicitu. `current_period` se mění pouze při skutečné alokaci.

`DocumentNumberAllocator` nejprve resolverem určí aktivní business connection a
na něm zahájí explicitní transakci. Řadu načte tenant-safe podle UUID se
zámkem `lockForUpdate()`, ověří aktivitu a archiv, vyhodnotí periodu a pořadí,
vytvoří `formatted_number`, vloží allocation a teprve poté aktualizuje
`next_number` a `current_period`. Selhání insertu vrátí celou transakci. DB
unikátnosti jsou poslední ochrana proti duplicitě; skutečný víceprocesový test
prokazuje serializaci souběhu.

Correlation UUID je serverový idempotency klíč. Opakování stejného klíče pro
stejnou řadu vrátí původní allocation bez posunu čítače. Stejný klíč s jinou
řadou nebo typem je odmítnut. Veřejný HTTP formulář ani alokační route
neexistují; allocator bude volat až autorizované workflow dokladu.

Po první allocation se uzamknou typ, prefix, suffix, formát roku, počet číslic,
počáteční číslo a reset. Změnit lze jen název, pořadí a aktivní stav. Číslo se
nikdy nevrací, nerecykluje, nemaže ani nepřečíslovává. Deaktivace je vratná,
archivace jednosměrná; obě transakčně odstraní default a archivované řadě
zakážou další alokaci. Historické allocations zůstávají nedotčené.

Konfigurace, stav, defaulty a nové allocations se auditují ve stejné transakci.
Idempotentní opakování correlation UUID nevytváří druhý audit. Do centrálního
auditu se tato data nekopírují.

## 9.5 Sazby DPH a základní daňová nastavení

Plátcovství, DIČ, IČ DPH a datum registrace zůstávají autoritativně v
`company_settings`. Jednotlivé sazby a režimy patří do `vat_rates`; jediný
default pro kontext prodeje patří do `vat_rate_defaults`. Obě tabulky vznikají
výhradně společnou business migrací v každé fyzické business databázi, bez
`business_id` a bez vazby do `central`.

`percentage` je nullable `DECIMAL(7,4)` a v PHP se zpracovává pouze jako přesný
normalizovaný string. Nikdy se nepřevádí na `float`. Hodnota 21 % je
`21.0000`, nikoliv `0.21`. Režimy `standard`, `reduced` a `zero` procento
vyžadují, přičemž `zero` musí být přesně nula. Režimy `exempt`,
`reverse_charge` a `out_of_scope` ukládají `NULL`; tím je nulová sazba
jednoznačně odlišena od plnění, kde se procentní sazba vůbec nepočítá.

Interval platnosti je uzavřený: `valid_from <= datum <= valid_to`; chybějící
`valid_to` znamená otevřený konec. Historická verze se neukončuje přepsáním
procenta, nýbrž nastavením `valid_to` a vytvořením nové navazující verze.
Nejbližší nové období smí začít následující den. Překryv nearchivovaných verzí
stejného kódu blokuje `VatRateService` v transakci. `lockForUpdate()` nad
existujícími kandidáty doplňuje MySQL advisory lock odvozený z fyzické databáze
a normalizovaného kódu, takže je chráněn i souběh v okamžiku, kdy ještě žádný
řádek neexistuje.

`vat_rate_defaults.context` je primární klíč a v této etapě přijímá pouze
`sales`. Neaktivní nebo archivovaná sazba nemůže být výchozí. Pokud je subjekt
neplátce, může být výchozí pouze `out_of_scope` nebo `exempt`; evidence jiných
sazeb je dovolena jako budoucí příprava a plátcovství se tím nemění. Deaktivace
a jednosměrná archivace odstraní default ve stejné transakci.

Výběr pro budoucí doklad musí vždy dostat explicitní datum zdanitelného plnění.
Konfigurační sazba není historickým záznamem faktury. Vystavená faktura musí
uložit vlastní neměnný snapshot typu, procenta a režimu. Jakmile budou faktury
existovat, historická pole použité sazby se musí uzamknout a změna konfigurace
nesmí zpětně změnit žádný doklad.

České legislativní sazby se nesmějí hardcodovat do domény, migrací ani skrytých
produkčních seederů. Administrátor je zadává vědomě; aplikace neposkytuje
daňové poradenství ani automatickou legislativní aktualizaci. Vytvoření,
změna, stavy, archivace a defaulty jsou auditovány explicitně a atomicky.

## 9.6 Faktury a položky

Faktura je účetní historický dokument. Musí obsahovat snapshot vystavovatele,
odběratele, platebních údajů a relevantních daňových hodnot. Součty se počítají
jednou definovanou službou s přesným zaokrouhlením.

## 9.7 PDF a e-mail

PDF nesmí být veřejně dostupné z předvídatelné URL. Odeslání e-mailem se
auditovatelně váže ke konkrétní verzi dokladu. Tajné SMTP údaje patří pouze do
environment konfigurace.

## 9.8 Audit

Centrální a business audit mají odlišné bezpečnostní hranice. `login_audits` a
`business_switch_audits` v `central` evidují pouze bezpečnostní události.
Obchodní změny patří do `audit_logs` stejné fyzické business databáze jako data.

Doménové služby zapisují významové události explicitně přes
`BusinessAuditWriter`; model observer není zdrojem auditu. Writer nepřijímá
connection a odmítne zápis mimo již otevřenou doménovou transakci. Auditní
insert proto commitne nebo rollbackne společně s business změnou.

`BusinessAuditSanitizer` má whitelist pro každý podporovaný typ. Bankovní a
daňové identifikátory se maskují na poslední čtyři znaky. Adresy, kontakty,
poznámky a texty faktur nejsou ve snapshotu; změna se projeví jen názvem v
`changed_fields`. Hesla, tokeny, session, connection name a secrets jsou
zakázané v hodnotách i metadata.

Middleware vytvoří pro jednu HTTP operaci jedno serverové UUID request ID,
zpřístupní je writeru a vrátí v `X-Request-ID`. Nejde o autorizační údaj. CLI a
budoucí queue mohou mít request ID prázdné. Centrální uživatel UUID nemá, proto
se používá `central-user:<id>` se snapshotem jména/e-mailu a bez FK.

Read-only seznam a detail na `/nastaveni/audit` jsou dostupné členům aktivního
subjektu. Filtry a řazení používají whitelist a stránkování zachovává query.
Create/update/delete/archive/restore API neexistuje. Retenční politika zatím
není rozhodnuta a automatické mazání auditní historie je zakázané.

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
