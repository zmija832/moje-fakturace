# Požadavky na sdílený multihosting

Stav: částečně ověřeno; parametry potřebné pro Etapu 2 jsou potvrzené

Datum kontroly: neuvedeno

Hosting a tarif: sdílený multihosting; přesný tarif neuveden

Tento dokument je rozhodovací dotazník před zahájením Etapy 2. U každého bodu
uveďte konkrétní hodnotu, odkaz do dokumentace hostingu nebo výsledek testu.
Hodnota „ano“ bez verze či limitu nemusí být dostatečná.

## 1. PHP a webový server

| Kontrola | Požadavek | Zjištěná hodnota | Stav |
|---|---|---|---|
| Výchozí PHP | 8.3 minimum; 8.4/8.5 preferováno | webové PHP 8.3.x | ✓ |
| Dostupné verze PHP | seznam verzí a možnost přepnutí | nezjištěno | ☐ |
| PHP pro web | stejná podporovaná verze jako CLI | 8.3.x | ✓ |
| PHP pro cron/CLI | cesta k binárnímu souboru a verze | PHP 8.3.30; spouštění příkazem `php` | ✓ |
| Typ web serveru | Apache/LiteSpeed/Nginx a verze | nezjištěno | ☐ |
| Document root | lze nastavit přímo na `/public` | `/www/fakturace/public/` | ✓ |
| `.htaccess` | povolený a respektovaný, pokud je potřeba | nezjištěno | ☐ |
| HTTPS | certifikát a automatická obnova | dostupné na `https://fakturace.milanzitek.cz`; automatická obnova neověřena | ◐ |
| Vynucení HTTPS | přesměrování a správné proxy hlavičky | nezjištěno | ☐ |

Laravel 13 vyžaduje PHP 8.3 nebo novější. Pokud hosting podporuje pouze PHP 8.2,
je možný Laravel 12, ale bezpečnostní podpora PHP 8.2 končí 31. 12. 2026.

## 2. PHP rozšíření

Ověřit, zda jsou dostupná a povolená:

| Rozšíření | Účel | Stav / verze |
|---|---|---|
| Ctype | Laravel | dostupné | ✓ |
| cURL | SMTP/API a provozní kontroly | dostupné | ✓ |
| DOM | Laravel/PDF | dostupné | ✓ |
| Fileinfo | bezpečná kontrola uploadů | dostupné | ✓ |
| Filter | Laravel | dostupné | ✓ |
| Hash | Laravel | dostupné | ✓ |
| Mbstring | Unicode a čeština | dostupné | ✓ |
| OpenSSL | šifrování a TLS | dostupné | ✓ |
| PCRE | Laravel | dostupné | ✓ |
| PDO | databáze | dostupné | ✓ |
| PDO MySQL | MySQL/MariaDB | dostupné | ✓ |
| Session | přihlášení | dostupné | ✓ |
| Tokenizer | Laravel | dostupné | ✓ |
| XML / SimpleXML / XMLWriter | XLSX a knihovny | dostupné | ✓ |
| Intl | locale, normalizace a formátování | ☐ |
| GD nebo Imagick | logo/QR/obrázky; ověřit podle knihoven | ☐ |
| Zip | XLSX a ZIP export | ☐ |
| Sodium | bezpečná kryptografie | ☐ |
| BCMath | přesné pomocné výpočty, pokud jej zvolí implementace | ☐ |

Konečný seznam se potvrdí po volbě PDF, QR a XLSX knihovny.

## 3. PHP limity

| Limit | Doporučené minimum pro první návrh | Zjištěná hodnota | Stav |
|---|---|---|---|
| `memory_limit` | alespoň 256 MB; ověřit prototypem PDF/ZIP | 512 MB | ✓ |
| `max_execution_time` web | alespoň 60 s | 30 s; vyžaduje krátké webové operace | ◐ |
| max. čas CLI/cron | alespoň 60 s | nezjištěno | ☐ |
| `upload_max_filesize` | alespoň 10 MB | nezjištěno | ☐ |
| `post_max_size` | větší než upload limit | nezjištěno | ☐ |
| `max_input_vars` | alespoň 2000; ověřit delší fakturu | nezjištěno | ☐ |
| `opcache` | zapnutý | nezjištěno | ☐ |
| časové pásmo | aplikace smí použít Europe/Prague | nezjištěno | ☐ |

Limity nejsou absolutní garance. V Etapě 5 a 7 se změří nejhorší rozumná faktura
a dávkový export.

## 4. MySQL / MariaDB

| Kontrola | Požadavek | Zjištěná hodnota | Stav |
|---|---|---|---|
| Typ a přesná verze | verze podporovaná zvoleným Laravelem | MySQL; přesná verze nezjištěna | ◐ |
| Počet databází | minimálně 3; deklarováno neomezeně | neomezený | ✓ |
| InnoDB | povinné | nezjištěno | ☐ |
| Znaková sada | `utf8mb4` | nezjištěno | ☐ |
| Collation | Unicode, case-insensitive; otestovat češtinu | nezjištěno | ☐ |
| Transakce | povinné | nezjištěno | ☐ |
| Row locks | `SELECT ... FOR UPDATE` | nezjištěno | ☐ |
| Cizí klíče | povinné uvnitř jedné DB | nezjištěno | ☐ |
| DB uživatelé | samostatný pro central/business 1/business 2 | samostatní uživatelé dostupní | ✓ |
| Omezení uživatelů | každý pouze na svou DB | nezjištěno | ☐ |
| Připojení z jedné aplikace | současně ke všem 3 DB | dostupné | ✓ |
| Max. počet připojení | uvést limit | nezjištěno | ☐ |
| SQL mode | uvést hodnotu, preferovat strict | nezjištěno | ☐ |
| Časové pásmo DB | uvést podporu | nezjištěno | ☐ |
| Export DB | hostingový export nebo `mysqldump` | nezjištěno | ☐ |
| Samostatná obnova | každou DB lze obnovit zvlášť | nezjištěno | ☐ |

Otestovat, že stejné collation podporuje částečné vyhledávání s českou
diakritikou podle očekávání. Přesný způsob vyhledávání se zvolí až podle verze
databáze.

## 5. SSH, Composer a nasazení

| Kontrola | Otázka | Zjištěná hodnota | Stav |
|---|---|---|---|
| SSH | je dostupné a v jakém rozsahu? | dostupné | ✓ |
| Composer | verze a limit paměti | Composer 2.10.2 přes `/home/web/work/composer.phar`, PHP 8.3.30 | ✓ |
| CLI Artisan | lze spouštět bezpečně mimo web? | nezjištěno | ☐ |
| SFTP/FTPS | zabezpečené nahrávání | nezjištěno | ☐ |
| Atomické nasazení | symlink/release adresáře, pokud dostupné | nezjištěno | ☐ |
| Symbolické odkazy | jsou povolené? | nezjištěno | ☐ |
| Oprávnění souborů | lze zapisovat jen do nutných adresářů? | nezjištěno | ☐ |
| Node.js v produkci | není vyžadován; assety se nahrají hotové | N/A | ✓ |
| Velikost uploadu balíčku | unese projekt včetně `vendor` bez SSH? | nezjištěno | ☐ |

Preferovaná varianta je SSH + Composer. Bez SSH se lokálně vytvoří produkční
balíček včetně `vendor` a předkompilovaných assetů. Jednorázový instalační
mechanismus smí spouštět pouze pevně definované kroky a po použití se deaktivuje.

## 6. Cron a krátké úlohy

| Kontrola | Požadavek | Zjištěná hodnota | Stav |
|---|---|---|---|
| Dostupnost cronu | povinné | dostupné | ✓ |
| Nejkratší interval | ideálně 1 min, přijatelné 5 min | nezjištěno | ☐ |
| Přesný PHP příkaz | absolutní cesta k PHP CLI | příkaz `php`, verze 8.3.30; absolutní cesta nezjištěna | ◐ |
| Working directory | lze nastavit kořen aplikace | nezjištěno | ☐ |
| Časový limit | alespoň 60 s | nezjištěno | ☐ |
| Souběh | lze použít zámek / hosting nezdvojuje běhy | nezjištěno | ☐ |
| Výstup a chyby | kam se posílá stdout/stderr | nezjištěno | ☐ |
| Počet cron úloh | dostatek pro scheduler a případné kontroly | nezjištěno | ☐ |
| Zakázané funkce | seznam `disable_functions` | nezjištěno | ☐ |

Produkce nebude mít trvale běžící worker. Cron spouští omezený proces, který po
vyprázdnění dávky nebo časovém limitu skončí.

## 7. SMTP

| Kontrola | Požadavek | Zjištěná hodnota | Stav |
|---|---|---|---|
| SMTP server | externí nebo hostingový, autentizovaný | dostupný; konkrétní server a limity nezjištěny | ◐ |
| TLS | povinné | nezjištěno | ☐ |
| Port a metoda šifrování | uvést | nezjištěno | ☐ |
| Denní/hodinový limit | uvést | nezjištěno | ☐ |
| Rate limit | uvést zprávy/minutu | nezjištěno | ☐ |
| Max. velikost zprávy | uvést | nezjištěno | ☐ |
| Max. velikost přílohy | uvést | nezjištěno | ☐ |
| Povolené From adresy | oba subjekty / obě domény | nezjištěno | ☐ |
| Reply-To | podporováno | nezjištěno | ☐ |
| DKIM | nastavit pro odesílací domény | nezjištěno | ☐ |
| SPF | nastavit pro odesílací domény | nezjištěno | ☐ |
| DMARC | doporučeno | nezjištěno | ☐ |
| Bounce handling | možnosti a limity | nezjištěno | ☐ |

Ověřit, zda mohou mít oba subjekty různé odesílatele a zda poskytovatel
nepřepisuje hlavičku `From`.

## 8. Disk, soubory a zálohy

| Kontrola | Požadavek | Zjištěná hodnota | Stav |
|---|---|---|---|
| Celkový prostor | uvést kvótu | nezjištěno | ☐ |
| Počet souborů/inodů | uvést kvótu | nezjištěno | ☐ |
| Privátní adresář | mimo veřejný document root | nezjištěno | ☐ |
| Dočasný adresář | zapisovatelný, známý limit | nezjištěno | ☐ |
| Max. velikost souboru | uvést | nezjištěno | ☐ |
| Automatické zálohy | frekvence a retence | nezjištěno | ☐ |
| Rozsah záloh | všechny 3 DB a privátní soubory | nezjištěno | ☐ |
| Vlastní export záloh | bezpečné stažení mimo hosting | nezjištěno | ☐ |
| Samostatná obnova DB | central / business 1 / business 2 | nezjištěno | ☐ |
| Samostatná obnova souborů | podle interního subjektu | nezjištěno | ☐ |
| Cena obnovy | uvést | nezjištěno | ☐ |
| Doba obnovy | uvést | nezjištěno | ☐ |
| Umístění dat | země / region | nezjištěno | ☐ |
| Šifrování záloh | v klidu a při přenosu | nezjištěno | ☐ |

Hostingová záloha není jediná záloha. Musí existovat pravidelná šifrovaná kopie
mimo účet hostingu a ověřený postup obnovy.

## 9. Bezpečnost a smluvní podmínky

| Kontrola | Otázka | Zjištěná hodnota | Stav |
|---|---|---|---|
| Izolace účtu | jak hosting odděluje zákazníky? | nezjištěno | ☐ |
| WAF / rate limiting | dostupné možnosti | nezjištěno | ☐ |
| Malware scanning | dostupnost a reakce | nezjištěno | ☐ |
| Logy přístupů | dostupnost a retence | nezjištěno | ☐ |
| Chybové logy | dostupnost a ochrana | nezjištěno | ☐ |
| 2FA hostingu | povinně zapnout | nezjištěno | ☐ |
| IP omezení administrace | dostupnost, volitelné | nezjištěno | ☐ |
| GDPR/DPA | smlouva o zpracování a subdodavatelé | nezjištěno | ☐ |
| Incidenty | oznamovací postup poskytovatele | nezjištěno | ☐ |
| SLA/podpora | reakční doba a kanály | nezjištěno | ☐ |

## 10. Výsledek rozhodovací brány

- [ ] PHP a framework jsou zvoleny.
- [ ] Tři databáze a samostatná oprávnění jsou ověřeny.
- [ ] Bezpečný document root je vyřešen.
- [ ] Cron a PHP CLI jsou ověřeny praktickým testem.
- [ ] SMTP pro oba odesílatele je ověřeno.
- [ ] Privátní úložiště a kvóty jsou dostatečné.
- [ ] Zálohování a samostatná obnova jsou doloženy.
- [ ] Smluvní/GDPR podmínky poskytovatele jsou přijatelné.

**Rozhodnutí:** ETAPA 2 ODBLOKOVÁNA; PRODUKČNÍ NASAZENÍ ZŮSTÁVÁ BLOKOVÁNO DO DOPLNĚNÍ ZBYLÝCH PARAMETRŮ

**Schválil:** neuvedeno

**Datum:** neuvedeno

Document root lze individuálně nastavit pro subdoménu. Pro
`fakturace.milanzitek.cz` je ověřená hodnota `/www/fakturace/public/`. Projekt
bude umístěn přibližně v `/home/web/www/fakturace`.
