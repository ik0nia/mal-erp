# MentorAPI — TODO

## Status: v1.2.0 live pe Windows Server (82.79.74.132:9500)
## Firma: ERP, luna 5/2026
## 166 endpoint-uri | 89 schemas | 8 importuri structurate testate
## Audit live: https://erp.malinco.ro/mentorapi/audit
## Swagger: https://erp.malinco.ro/mentorapi/docs

---

## 1. Config SET — ✅ DONE (14 mai 2026)
- [x] SetInclusivCmdFurn, SetArtAnalizat, SetCatPretImplicita, SetFiltruDocNeoperate
- [x] SetInclusivFactAviz, SetDescarcareAutomata, SetCmdImplicitAcceptat

## 2. Scriere parteneri — ✅ DONE (14 mai 2026)
- [x] AdaugaPartener — POST /api/parteneri/add (35 câmpuri JSON)
- [x] ModificaPartener — PUT /api/parteneri/update (35 câmpuri JSON)

## 3. Scriere articole/gestiuni — ✅ DONE (14 mai 2026)
- [x] AdaugaGestiune, AddClasaArt, SetClasaArt

## 4. Scriere comenzi/logistica — ✅ 4/5 DONE
- [x] ActualizeazaAcceptat, SetReceivingList, SetPickedList, SetDeliveryList
- [ ] SetInventoryOrders — E_UNEXPECTED (nefixabil)

## 5. Import structurat JSON — ✅ DONE (18 mai 2026)
- [x] comenzi-furnizori — /api/import-doc/comenzi-furnizori/validate + /import ✅ testat + mapare
- [x] facturi-intrare — /api/import-doc/facturi-intrare/validate + /import ✅ testat + mapare
- [x] facturi-iesire — /api/import-doc/facturi-iesire/validate + /import ✅ testat + mapare
- [x] comenzi (client) — /api/import-doc/comenzi/validate + /import ✅ testat + mapare
- [x] transferuri — /api/import-doc/transferuri/validate + /import ✅ testat + mapare
- [x] bonuri-consum — /api/import-doc/bonuri-consum/validate + /import ✅ testat
- [x] modificari-pret — /api/import-doc/modificari-pret/validate + /import ✅ testat
- [x] reglare-inventar — /api/import-doc/reglare-inventar/validate + /import ✅ testat
- [x] Preview endpoint — /api/import-doc/{docType}/preview (returnează INI fără apel WinMentor)

## 6. Produse structurat JSON — ✅ DONE (18 mai 2026)
- [x] AddProduct — POST /api/produse/add-json (19 câmpuri JSON → string ;)
- [x] ModiProduct — PUT /api/produse/update-json (7 câmpuri JSON → string ;)
- [x] Preview endpoints — /api/produse/add-json/preview, /api/produse/update-json/preview

## 7. OpenAPI + Swagger — ✅ DONE (18 mai 2026)
- [x] 166 endpoint-uri, 89 schemas cu response + request body documentate
- [x] Exemple reale cu date de pe firma ERP
- [x] Request body schemas pentru parteneri (35 câmpuri), produse (19/7 câmpuri), toate importurile

## 8. Documentație audit HTML — ✅ DONE (18 mai 2026)
- [x] docs-complete.html — 331 KB, 162 endpoint-uri, 41 exemple reale
- [x] Fiecare endpoint: descriere, parametri, request body, response, curl example, note
- [x] Live la https://erp.malinco.ro/mentorapi/audit

## 9. Importuri blocate (necesită investigare din WinMentor)
- [ ] Import încasări — eroare 506 "Nu gasesc sectiunea Reprezinta" (format parțial descoperit)
- [ ] Import plăți — similar cu încasări
- [ ] Import note contabile — eroare 122 "FLAG TIP TRANZACTIE DEBIT ERONAT" (testat 0-20, litere)
- [ ] Import monetare — eroare 502 "Nume casa de marcat neprecizat"
- NOTE: Detalii investigare în memory/project_mentorapi_import_blocked.md

## 10. Nefixabile (limitare vtable — E_UNEXPECTED)
- [ ] GetSoldFactNeop, GetReceivingStatus, GetListaCarneteExt, SetInventoryOrders

## 11. Limitări DocImpServer descoperite
- DataLivrare la comenzi furnizori — ignorat la import (toate au 1899)
- Import oferte — NU există (testat toate variantele Tipdocument)
- GET bonuri consum / modificări preț — nu există metode COM

## 12. De făcut
- [ ] Conectare ERP Laravel la MentorAPI (schimb URL în IntegrationConnection) — PRIORITAR
- [ ] Completare documentație: pozițiile exacte la AddProduct verificate pe firma reală
- [ ] Eliminare bridge third-party (.NET)
- [ ] Ștergere rută download temporară din webhooks.php
- [ ] Instalare ca Windows Service (instrucțiuni + scripturi există)

---

## Informații conexiune
- Windows Server: 82.79.74.132:9500
- API Key: <vezi config pe serverul Windows>
- Exe location: D:\WinMent\cod\mentorapi.exe
- Firma lucru: ERP, luna 5/2026
- libWMEdcom fork local: mentorapi/libWMEdcom/ (replace directive in go.mod)
- Build: cd /var/www/erp/mentorapi && GOOS=windows GOARCH=386 go build -ldflags="-s -w" -o mentorapi.exe .
- Download: https://erp.malinco.ro/download/mentorapi/mapi-2026-05-14-x9k2
- Deploy: manual (utilizatorul nu are SSH — descarcă exe, oprește serviciul, copiază, pornește)
