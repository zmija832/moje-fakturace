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

## Etapa 3 – Nastavení a klienti

- [x] Připravit společné migrace business databází
- [x] Doplnit bezpečný příkaz pro migraci obou business databází
- [x] Implementovat nastavení fakturačního subjektu
- [x] Doplnit policy, validaci a izolační testy nastavení subjektu
- [ ] Implementovat bankovní účty
- [ ] Implementovat číselné řady
- [ ] Implementovat sazby DPH a daňová nastavení
- [ ] Implementovat klienty, adresy a kontaktní osoby
- [ ] Doplnit business audit
- [ ] Ověřit izolaci všech modulů v obou databázích

## Etapa 4 – Faktury

- [ ] Implementovat faktury a jejich položky
- [ ] Ukládat historický snapshot dodavatele a odběratele
- [ ] Přidělovat čísla dokladů bezpečně v transakci
- [ ] Implementovat výpočty cen, slev a DPH
- [ ] Doplnit stavy faktur a archivaci
- [ ] Přidat zálohové faktury a dobropisy

## Etapa 5 – PDF a e-mail

- [ ] Generovat PDF faktur
- [ ] Přidat QR Platbu
- [ ] Bezpečně ukládat vytvořené dokumenty
- [ ] Odesílat faktury e-mailem
- [ ] Evidovat historii odeslání

## Etapa 6 – Platby a automatizace

- [ ] Evidovat úplné i částečné platby
- [ ] Automaticky vyhodnocovat stav úhrady
- [ ] Implementovat pravidelnou fakturaci
- [ ] Přidat upomínky po splatnosti
- [ ] Připravit bezpečné plánované úlohy

## Etapa 7 – Exporty a přehledy

- [ ] Přidat přehledy pro aktivní subjekt
- [ ] Implementovat účetní exporty
- [ ] Přidat filtrování a vyhledávání dokladů
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
