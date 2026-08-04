# Roadmap projektu „Moje fakturace“

Jednoduchý plán dalšího vývoje soukromé fakturační aplikace.

## Hotovo

- [x] Základ projektu v Laravelu 13
- [x] Přihlášení bez veřejné registrace
- [x] Centrální databáze uživatelů a oprávnění
- [x] Přepínání dvou podnikatelských subjektů
- [x] Fyzicky oddělená připojení `business_1` a `business_2`
- [x] Fail-closed `BusinessModel`
- [x] Automatické testy databázové izolace
- [x] Základní technická dokumentace
- [x] Architektonická revize prvních tří business modulů

## Etapa 3 – Nastavení a klienti

- [x] Připravit společné migrace business databází
- [x] Doplnit bezpečný příkaz pro migraci obou business databází
- [x] Implementovat nastavení fakturačního subjektu
- [x] Doplnit policy, validaci a izolační testy nastavení subjektu
- [x] Implementovat bankovní účty
- [x] Implementovat konfiguraci, defaulty a transakční alokaci číselných řad
- [x] Implementovat sazby DPH a základní daňová nastavení
- [x] Implementovat klienty, fakturační a jednu dodací adresu a kontaktní osobu
- [x] Doplnit transakční, sanitizovaný a read-only business audit
- [x] Ověřit izolaci aktuálně implementovaných modulů v obou databázích

## Etapa 4 – Faktury

- [x] Implementovat datový model návrhu faktury a položek bez výpočtů
- [x] Ukládat immutable snapshot dodavatele, odběratele, účtu a sazby DPH
- [x] Implementovat immutable revize návrhu a bezpečný převod draftů z části 1
- [x] Implementovat přesné výpočty položek, položkových i celkových slev, DPH, totals a VAT summaries
- [x] Doplnit optimistické zamykání a idempotentní editaci návrhu
- [x] Napojit vystavení faktury na transakční allocator číselných řad
- [x] Doplnit stavy draft/issued, idempotentní vystavení a neměnnost issued revize
- [x] Doplnit responzivní Blade seznam, hledání, filtry a stránkování faktur
- [x] Doplnit UI vytvoření a revizní editace návrhu s optimistic lockingem
- [x] Doplnit serverový read-only preview výpočtu a přístupný editor položek
- [x] Doplnit nevratné vystavení z UI, draft/issued detail a auditní historii
- [x] Doplnit policy pro admin/viewer a tenant-safe HTTP/UI testy
- [ ] Doplnit archivaci a další budoucí workflow stavy až s jejich doménovou logikou
- [ ] Přidat zálohové faktury a dobropisy

## Etapa 5 – PDF a e-mail

- [x] Generovat PDF pouze z immutable issued snapshotu
- [x] Přidat QR Platbu SPD 1.0 s bezpečným fallbackem
- [x] Bezpečně ukládat immutable dokumenty mimo veřejný webroot
- [x] Odesílat faktury synchronně e-mailem s přiloženým PDF
- [x] Evidovat tenant-local historii odeslání a sanitizovaný audit
- [ ] Navrhnout retenční pravidla a případné řízené mazání dokumentů
- [ ] Vyřešit provider-level delivery/webhooky a hranici exactly-once

## Etapa 6 – Platby a automatizace

- [ ] Evidovat úplné i částečné platby
- [ ] Automaticky vyhodnocovat stav úhrady
- [ ] Implementovat pravidelnou fakturaci
- [ ] Přidat upomínky po splatnosti
- [ ] Připravit bezpečné plánované úlohy

## Budoucí modul – Notifikace správci a klientovi

- [ ] Umožnit samostatně zapínat každý typ notifikace
- [ ] Připravit vlastní šablony a neměnnou historii odeslání
- [ ] Správci: faktura vystavena, zaplacena nebo částečně zaplacena
- [ ] Správci: faktura po splatnosti a blížící se splatnost
- [ ] Správci: chyba odeslání a selhání automatické úlohy
- [ ] Klientovi: faktura vystavena a odeslána
- [ ] Klientovi: připomenutí před splatností, první a další upomínka
- [ ] Klientovi: potvrzení o zaplacení

Notifikace vzniknou až po návrhu e-mailu, plateb a plánovaných úloh. Jednotlivé
typy budou mít vlastní zapnutí, šablonu, adresáty, audit a historii odeslání.

## Etapa 7 – Exporty a přehledy

- [ ] Přidat přehledy pro aktivní subjekt
- [ ] Implementovat účetní exporty
- [x] Přidat filtrování a vyhledávání vydaných faktur
- [ ] Doplnit základní fakturační statistiky

## Etapa 8 – Produkční nasazení

- [ ] Provést bezpečnostní revizi
- [ ] Ověřit produkční MySQL a PHP prostředí
- [ ] Nastavit databázové účty s minimálními právy
- [ ] Nastavit HTTPS, session cookies a zálohy
- [ ] Ověřit obnovu všech tří databází ze zálohy
- [ ] Spustit kompletní automatické a ruční testy
- [ ] Připravit postup nasazení a návratu předchozí verze

## Pravidla pro každou etapu

- Nejdříve analyzovat skutečný stav projektu.
- Dodržovat pravidla v `ARCHITECTURE.md`.
- Nemíchat data subjektů ani používat business data v `central`.
- Každý business modul otestovat pro `business_1` i `business_2`.
- Před dokončením spustit testy, Pint, frontend build a Git kontroly.
- Nevytvářet commit bez výslovného požadavku.
