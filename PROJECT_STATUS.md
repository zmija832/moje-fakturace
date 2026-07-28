# Stav projektu

Aktualizováno: 28. 7. 2026

Aktuální etapa: Etapa 2 – základ aplikace

Celkový stav: Etapa 2 implementována a automaticky ověřena

## Souhrn

Vznikla lokálně funkční aplikace na Laravelu 13.23.0 a PHP 8.3. Centrální
databáze obsahuje pouze identitu, subjekty, oprávnění, session, audit a technické
nastavení. Připojení `business_1` a `business_2` jsou připravená, ale jejich
databáze v této etapě neobsahují žádné účetní tabulky.

Aplikace má session přihlášení bez veřejné registrace, obnovu a změnu hesla,
rate limiting, český dashboard, audit autentizace a bezpečný přepínač aktivního
subjektu. Připojení se vybírá pouze ze serverového allow-listu.

## Dokončeno v Etapě 2

- [x] Git repozitář inicializován bez commitu;
- [x] Laravel 13 projekt přímo v kořeni pracovního adresáře;
- [x] Blade, Tailwind CSS a Alpine.js bez SPA;
- [x] konfigurace `central`, `business_1` a `business_2` pouze přes environment;
- [x] oddělená sada migrací `database/migrations/central`;
- [x] centrální tabulky `users`, `password_reset_tokens`, `sessions`,
  `businesses`, `user_business_access`, `login_audits`,
  `business_switch_audits`, `application_settings` a `migrations`;
- [x] všechny centrální modely explicitně používají připojení `central`;
- [x] přihlášení, odhlášení, reset a změna hesla;
- [x] zákaz veřejné registrace a rate limiting přihlášení;
- [x] audit úspěšného/neúspěšného přihlášení a odhlášení;
- [x] `ActiveBusinessContext`, `BusinessSwitcher` a příslušné middleware;
- [x] serverové ověření oprávnění a allow-list `business_1|business_2`;
- [x] audit úspěšného i odmítnutého přepnutí;
- [x] automatická volba posledního platného nebo prvního povoleného subjektu;
- [x] blokace účetních rout bez aktivního subjektu;
- [x] responzivní česká navigace, dashboard a stránky „Připravuje se“;
- [x] bezpečné interaktivní příkazy `app:create-admin` a
  `app:configure-businesses`;
- [x] oddělené lokální a testovací MySQL databáze;
- [x] aktualizace README a hostingového dotazníku.

## Rozsah, který záměrně nevznikl

- klienti a podnikatelská nastavení;
- faktury, položky, číselné řady a platby;
- PDF a QR Platba;
- SMTP odesílání a fronta;
- pravidelné fakturace a upomínky;
- exporty a fakturační statistiky;
- jakékoliv podnikatelské databázové tabulky;
- produkční nasazení.

## Automatické kontroly

| Kontrola | Výsledek |
|---|---|
| PHPUnit proti MySQL 8.4 | 11 testů, 11 úspěšných, 31 assertions |
| Laravel Pint | úspěšný |
| Produkční `npm run build` | úspěšný |
| npm audit | 0 zranitelností při instalaci |
| Composer validate | úspěšný |
| Composer audit | žádné známé bezpečnostní advisory |
| Laravel route boot | úspěšný, 16 aplikačních rout |
| Veřejná registrace | route ani stránka neexistuje |
| Statická analýza | samostatný PHPStan/Larastan zatím není nakonfigurován |

## Lokální vývojové prostředí

- PHP 8.3.32;
- Composer 2.10.2;
- Node.js 24.18.0 a npm 11.16.0;
- MySQL 8.4.9 na lokálním portu 3307;
- lokální databáze `fakturace_local_*`;
- samostatné testovací databáze `fakturace_test_*`;
- lokální `.env` je ignorován Gitem a neobsahuje produkční údaje.

## Otevřené body před produkčním nasazením

- [ ] zjistit přesnou produkční verzi MySQL;
- [ ] ověřit automatickou obnovu HTTPS certifikátu;
- [ ] ověřit absolutní cestu PHP pro cron a jeho časový limit;
- [ ] doplnit SMTP server, TLS, limity a povolené odesílatele;
- [ ] ověřit diskovou kvótu, počet souborů a velikost příloh;
- [ ] potvrdit samostatné zálohy a obnovu tří databází;
- [ ] potvrdit retenční a právní body z Etapy 1;
- [ ] nastavit produkční databázové účty s minimálními právy;
- [ ] spustit interaktivní vytvoření skutečného správce a obou subjektů až v
  bezpečném cílovém prostředí.

## Doporučený další krok

Před Etapou 3 ručně projít přihlášení a přepínání subjektu v prohlížeči,
doplnit otevřené parametry hostingu relevantní pro upload loga a poté navrhnout
podnikatelské migrace pro nastavení, bankovní účty a klienty.

## Historie etap

| Etapa | Stav | Datum | Poznámka |
|---|---|---|---|
| 1 – analýza | dokončena | 28. 7. 2026 | architektura a hostingový dotazník |
| 2 – základ aplikace | dokončena | 28. 7. 2026 | 11 MySQL integračních testů |
| 3 – nastavení a klienti | nezahájena | – | – |
| 4 – faktury | nezahájena | – | – |
| 5 – PDF a e-mail | nezahájena | – | – |
| 6 – pravidelné fakturace a upomínky | nezahájena | – | – |
| 7 – export a dashboard | nezahájena | – | – |
| 8 – zabezpečení a nasazení | nezahájena | – | – |
