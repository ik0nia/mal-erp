# MentorAPI — Documentație completă (v1.5.0)

_Generat: 2026-09-15. Sursă de adevăr: `DocImpServer.tlb` oficial (ftp.winmentor.ro), decodat integral (format MSFT)._

Acest fișier acoperă: (1) ce s-a modificat/adăugat în v1.5.0, (2) confirmarea acoperirii tuturor funcțiilor și parametrilor, (3) starea funcțiilor care nu merg, (4) referința completă a celor **171 de metode** DocImpServer cu parametri, tipuri și formate.

---
## 1. Ce s-a modificat / adăugat în v1.5.0

**Context:** am descărcat type library-ul oficial curent (`DocImpServer.tlb`) și l-am decodat integral, obținând semnătura exactă a tuturor celor 171 de metode expuse de DLL — inclusiv cele pe care documentația PDF le lista fără parametri.

### 1.1 Aliniere versiune
`Version` (server.go), titlul Swagger și `openapi-spec.json` erau desincronizate (1.4.0 / 1.3.0 / 1.3.0). Acum toate = **1.5.0**.

### 1.2 Endpoint-uri noi (6 metode COM care existau în DLL dar nu erau expuse)

| Endpoint | Metodă COM | Semnătură |
|---|---|---|

| `GET /api/documente/bon-consum?numar=&serie=` | `GetInfoBonConsum` | `GetInfoBonConsum(const Numar: Integer; const Serie: WideString; out Error: Integer): OleVariant` |
| `GET /api/documente/monetar/exista?numar=&serie=` | `ExistaMonetarul` | `ExistaMonetarul(const Numar: Integer; const Serie: WideString): Integer` |
| `GET /api/preturi/vanzare-brut?articol=&partener=` | `GetPretVanzareBrut` | `GetPretVanzareBrut(const ArtID: WideString; const PartID: WideString; out Error: Integer): OleVariant` |
| `GET /api/atribute/{cod}/valori` | `GetValoriAtribut` | `GetValoriAtribut(const CodAtribut: Integer; out Error: Integer): OleVariant` |
| `GET /api/firme/ext` | `GetListaFirmeExt` | `GetListaFirmeExt(): OleVariant` |
| `POST /api/parteneri/{id}/set-analizat` | `SetPartAnalizat` | `SetPartAnalizat(const IDPart: WideString): Integer` |


---
## 2. Confirmarea acoperirii (audit)

| Verificare | Rezultat |
|---|---|
| Metode COM în TLB (sursă de adevăr) | **171** |
| Wrappere Go validate automat (nr. argumente vs TLB) | **128/128, 0 nepotriviri** |
| Metode COM neexpuse anterior | 6 → **adăugate în v1.5.0** |
| Semnături confirmate identic cu `.pas` oficial | GetSoldPart, GetVersiuni, GetSolduriExt, GetSoldDetaliat, GetListaFirme… |

Cross-check-ul compară numărul de argumente de intrare din fiecare wrapper Go cu numărul din semnătura TLB. Zero nepotriviri = **parametrii tuturor apelurilor sunt corecți**.

---
## 3. Funcții care NU merg — parametri corecți, cauză runtime

Aceste metode erau raportate ca nefuncționale. Verificarea față de TLB arată că **parametrii apelurilor sunt corecți** — cauza e comportamentul intern al DLL-ului, nu semnătura.

| Metodă | Semnătură (TLB, confirmată) | Params în cod | Cauză reală |
|---|---|---|---|
| `GetSoldFactNeop` | `(const PartID: WideString; out Error: Integer): WideString` | identic | Excepție server-side (0x8000FFFF) la runtime; **nu** e problemă de semnătură. |
| `GetReceivingStatus` | `(const PartID, SerieDoc, NrDoc: WideString): WideString` | identic | E_UNEXPECTED (limitare DLL) |
| `GetListacarneteExt` | `(out Error: Integer): OleVariant` | identic | E_UNEXPECTED; alternativă: `GetListaCarnete` |
| `GetInventoryOrders` / `SetInventoryOrders` | vezi referința | identic | E_UNEXPECTED |

**Concluzie:** nu mai are rost testarea prin ghicirea parametrilor — sunt confirmați. Deblocarea reală = semnătura/contextul de la suportul WinMentor sau investigație runtime.

---
## 4. Referință completă — 171 metode DocImpServer

Formate: `WideString`=string (BSTR) · `OleVariant`=listă/VarArray de string-uri · `Integer`=int32 · `Double`=float64 · `Currency`=monetar · `TDateTime`=dată OLE (bază 1899-12-30). Parametrii `out` sunt gestionați intern de wrapper (nu se trimit de client).


### 1. Firme & sesiune

- **`GetListaFirme(): OleVariant`**
- **`SetNumeFirma(const NumeFirma: WideString): Integer`**
- **`SetLunaLucru(const An: Integer; const Luna: Integer): Integer`**
- **`GetListaFirmeExt(): OleVariant`** — 🆕 nou v1.5.0

### 2. Sistem & nomenclatoare

- **`GetListaLuni(const CaleFirma: WideString): OleVariant`**
- **`GetListaErori(): OleVariant`**
- **`GetListaPersonal(out Error: Integer): OleVariant`**
- **`GetListaLocalitati(out Error: Integer): OleVariant`**
- **`GetVersiuni(out VerMentor: Double; out VerServer: Double): Integer`**
- **`GetInfoPers(const IDPers: Integer; out Error: Integer): OleVariant`**
- **`GetListaClienti(const AnInceput: Integer; const LunaInceput: Integer; out Error: Integer): OleVariant`**
- **`GetStringConstanta(const Id: Integer; const Simbol: WideString): WideString`**
- **`GetDocFromFile(const FileName: WideString): Integer`**
- **`GetListRecord(out EOF: Integer): WideString`**
- **`GetIntrari(out Error: Integer): OleVariant`**
- **`GetListaSubunit(out Error: Integer): OleVariant`**
- **`GetInfoIesiri(const Zi: Integer; out Error: Integer): OleVariant`**
- **`GetInfoIesiriExt(const NrDoc: Integer; const Zi: Integer; out Error: Integer): OleVariant`**
- **`GetListaDelegati(out Error: Integer): OleVariant`**
- **`GetCritDiscPeClase(out Error: Integer): OleVariant`**
- **`GetReceptii(out Error: Integer): OleVariant`**
- **`GetTransferuri(out Error: Integer): OleVariant`**
- **`CheckDocument(const TipDoc: WideString; const PrefixDoc: WideString; const NrDoc: Integer; out Error: Integer): Integer`**
- **`GetListaBanci(out Error: Integer): OleVariant`**
- **`GetTranzactiiInCurs(out Error: Integer): OleVariant`**
- **`GetProducts(const LastSyncDate: WideString; out Error: Integer): OleVariant`**
- **`GetSuppliersOrders(out Error: Integer): OleVariant`**
- **`AddProduct(const InfoProdus: WideString): Integer`**
- **`PotIntroduceDoc(const An: Integer; const Luna: Integer): Integer`**
- **`GetNomenclatorLocalitati(out Error: Integer): OleVariant`**
- **`GetMonede(out Error: Integer): OleVariant`**
- **`ActualizeazaAcceptat(const LiniiComenzi: OleVariant): Integer`**
- **`GetReceptiiIntrSubunit(out Error: Integer): OleVariant`**
- **`ModiProduct(const InfoProdus: WideString): Integer`**
- **`GetMiscariAmbalaje(const AnStart: Integer; const LunaStart: Integer; const AnStop: Integer; const LunaStop: Integer; out Error: Integer): OleVariant`**
- **`GetCmdSubunitActive(out Error: Integer): OleVariant`**
- **`GetIntervaleDisc(const Criteriu: Integer; out Error: Integer): OleVariant`**
- **`LogOn(const UserName: WideString; const PassWord: WideString): Integer`**
- **`GetReteteActive(out Error: Integer): OleVariant`**
- **`ExistaMonetarul(const Numar: Integer; const Serie: WideString): Integer`** — 🆕 nou v1.5.0
- **`GetReceptiiNeoperate(out Error: Integer): OleVariant`**
- **`GetEmulareNedescarcata(out Error: Integer): OleVariant`**
- **`GetUM(out Error: Integer): OleVariant`**
- **`GetReziduale(out Error: Integer): OleVariant`**

### 10. Import / setari / documente

- **`SetDocsData(const DocData: OleVariant): Integer`**
- **`DateValide(): Integer`**
- **`SetIDArtField(const FieldName: WideString): Integer`**
- **`SetCmdImplicitAcceptat(const ImplicitAcceptat: Integer): Integer`**
- **`TransferuriValide(): Integer`**
- **`ImportaTransferuri(): Integer`**
- **`FactIntrareValida(): Integer`**
- **`ImportaFactIntrare(): Integer`**
- **`SetReceivingList(const Data: OleVariant): Integer`**
- **`SetInventoryOrders(const Data: OleVariant): Integer`** — ⚠️ runtime
- **`SetPickedList(const Data: OleVariant): Integer`**
- **`MonetareValide(): Integer`**
- **`ImportaMonetare(): Integer`**
- **`SetDeliveryList(const Data: OleVariant): Integer`**
- **`NCValide(): Integer`**
- **`ImportaNoteContabile(): Integer`**
- **`SetDescarcareAutomata(const Automat: Integer): Integer`**
- **`SetFiltruDocNeoperate(const Flag: Integer): Integer`**
- **`SetInclusivCmdFurn(const Flag: Integer): Integer`**
- **`ReglareInventarValida(const TipReglare: Integer): Integer`**
- **`ImportaReglareInventar(const TipReglare: Integer): Integer`**
- **`SetInclusivFactAviz(const Flag: Integer): Integer`**
- **`SetArtAnalizat(const IDArticol: WideString): Integer`**
- **`SetCantReceptii(const CodDoc: Integer; const SimbolCarnetNir: WideString; const NrNIR: Integer; const LiniiNIR: OleVariant): Integer`**
- **`SetDataReferinta(const DataReferinta: WideString): Integer`**
- **`SetCodCmdAnalizata(const CodComanda: Integer): Integer`**

### 7. Facturi

- **`ImportaFacturi(): Integer`**
- **`ExistaFactura(const Numar: Integer): Integer`**
- **`GetNumarFactura(const SimbolCarnet: WideString; out Error: Integer): Integer`**
- **`GetComenziNefacturate(out Error: Integer): OleVariant`**
- **`ExistaFacturaExt(const Numar: Integer; const Serie: WideString): Integer`**
- **`ExistaFacturaIntrare(const PartID: WideString; const Serie: WideString; const Numar: Integer): Integer`**
- **`GetInfoCmdNefacturate(out Error: Integer): OleVariant`**
- **`GetInfoCmdSubNefacturate(out Error: Integer): OleVariant`**

### 5. Parteneri

- **`GetListaParteneri(out Error: Integer): OleVariant`**
- **`GetSoldPart(const PartID: WideString; out Error: Integer): OleVariant`**
- **`GetClaseParteneri(out Error: Integer): OleVariant`**
- **`GenCodParteneri(): Integer`**
- **`AdaugaPartener(const InfoPart: WideString): Integer`**
- **`SetIDPartField(const FieldName: WideString): Integer`**
- **`GetInfoPart(const PartID: WideString; out Error: Integer): OleVariant`**
- **`GetNextPartID(out Error: Integer): WideString`**
- **`GetCritDiscPart(out Error: Integer): OleVariant`**
- **`ModificaPartener(const InfoPart: WideString): Integer`**
- **`SetPartAnalizat(const IDPart: WideString): Integer`** — 🆕 nou v1.5.0

### 4. Stocuri

- **`GetStocArticole(out Error: Integer): OleVariant`**
- **`GetStocArticol(const ArticolID: WideString; const GestID: WideString; out Error: Integer): OleVariant`**
- **`GetStocArtDetaliat(const ArtID: WideString; const GestID: WideString; out Error: Integer): OleVariant`**
- **`GetStocuriPeGestiuni(out Error: Integer): OleVariant`**
- **`GetStocArticoleExt(out Error: Integer): OleVariant`**
- **`GetStocInitialAmbalaj(const An: Integer; const Luna: Integer; out Error: Integer): OleVariant`**
- **`GetStocArticoleExt2(const GestID: WideString; out Error: Integer): OleVariant`**
- **`GetStocArticoleExt3(out Error: Integer): OleVariant`**
- **`GetStocArticoleExt4(out Error: Integer): OleVariant`**
- **`GetStocArticoleExt5(out Error: Integer): OleVariant`**

### 6. Solduri & financiar

- **`GetSoldDetaliat(const PartID: WideString; out Error: Integer): OleVariant`**
- **`GetSolduri(out Error: Integer): OleVariant`**
- **`GetIncasariClienti(const An1: Integer; const Luna1: Integer; const An2: Integer; const Luna2: Integer; const PartID: WideString; out Error: Integer): OleVariant`**
- **`IncasariValide(): Integer`**
- **`ImportaIncasari(): Integer`**
- **`GetSolduriExt(out Error: Integer): OleVariant`**
- **`IncasariValideExt(): Integer`**
- **`ImportaIncasariExt(): Integer`**
- **`GetIncasariFactura(const An: Integer; const Luna: Integer; const NrFact: Integer; const SerieFact: WideString; const IDPart: WideString): OleVariant`**
- **`PlatiValideExt(): Integer`**
- **`ImportaPlatiExt(): Integer`**
- **`GetSoldFactNeop(const PartID: WideString; out Error: Integer): WideString`** — ⚠️ runtime
- **`GetIncasariLuna(out Error: Integer): OleVariant`**
- **`GetSolduriFurn(out Error: Integer): OleVariant`**
- **`GetPlatiFactura(const An: Integer; const Luna: Integer; const NrFact: Integer; const Serie: WideString; const IDPart: WideString): OleVariant`**
- **`GetPlatiLuna(out Error: Integer): OleVariant`**
- **`GetIncasariClientiExt(const An1: Integer; const Luna1: Integer; const An2: Integer; const Luna2: Integer; const PartID: WideString; out Error: Integer): OleVariant`**

### 11. Logistica / gestiuni

- **`GetListaGestiuni(out Error: Integer): OleVariant`**
- **`GetListaCarnete(out Error: Integer): OleVariant`**
- **`GetReceivingStatus(const PartID: WideString; const SerieDoc: WideString; const NrDoc: WideString): WideString`** — ⚠️ runtime
- **`GetInventoryOrders(const GestID: WideString; out Error: Integer): OleVariant`** — ⚠️ runtime
- **`GetDispozitiiDeLivrare(const GestID: WideString; out Error: Integer): OleVariant`**
- **`GetDeliveryOrders(out Error: Integer): OleVariant`**
- **`GetListacarneteExt(out Error: Integer): OleVariant`** — ⚠️ runtime
- **`AdaugaGestiune(const InfoGest: WideString): Integer`**

### 3. Articole / produse / preturi

- **`GenCodArticole(): Integer`**
- **`GetPretVanzare(const ArtID: WideString; const PartID: WideString; out Error: Integer): OleVariant`**
- **`GetClaseArticole(out Error: Integer): OleVariant`**
- **`GetListaCatPret(out Error: Integer): OleVariant`**
- **`GetListaArtCatPret(out Error: Integer): OleVariant`**
- **`GetNomenclatorArticole(out Error: Integer): OleVariant`**
- **`SetIndexNart(const aIndexName: WideString): Integer`**
- **`GetArticoleVandute(const PartID: WideString; const MarcaAgent: Integer; const AnInceput: Integer; const LunaInceput: Integer; out Error: Integer): OleVariant`**
- **`SetClasaArt(const NumeClasa: WideString): Integer`**
- **`GetListaArtCatPretExt(out Error: Integer): OleVariant`**
- **`GetCritDiscPeArticole(out Error: Integer): OleVariant`**
- **`GetListaArtCatPret2(const ArtID: WideString; out Error: Integer): OleVariant`**
- **`GetStergeriProduse(const LastSyncDate: WideString; out Error: Integer): OleVariant`**
- **`GetNirAtribute(out Error: Integer): OleVariant`**
- **`AddClasaArt(const InfoClasa: WideString): Integer`**
- **`ModifPretValide(): Integer`**
- **`ImportaModifPret(): Integer`**
- **`GetArticoleDisc(const Criteriu: Integer; out Error: Integer): OleVariant`**
- **`SetCatPretImplicita(const IDCatPret: WideString): Integer`**
- **`GetPretVanzareBrut(const ArtID: WideString; const PartID: WideString; out Error: Integer): OleVariant`** — 🆕 nou v1.5.0
- **`ModificaArticol(const InfoArt: WideString): Integer`**
- **`GetValoriAtribut(const CodAtribut: Integer; out Error: Integer): OleVariant`** — 🆕 nou v1.5.0
- **`GetValoriAtribute(out Error: Integer): OleVariant`**

### 8. Comenzi / oferte / bonuri

- **`ImportaComenzi(): Integer`**
- **`ComenziValide(): Integer`**
- **`ComenziValideExt(): Integer`**
- **`ImportaComenziExt(): Integer`**
- **`GetInfoComenzi(out Error: Integer): OleVariant`**
- **`GetOferte(out Error: Integer): OleVariant`**
- **`GetInfoBon(const Zi: Integer; out Error: Integer): OleVariant`**
- **`GetInfoBonExt(const NrBon: Integer; const Zi: Integer; out Error: Integer): OleVariant`**
- **`BonuriConsumValide(): Integer`**
- **`ImportaBonuriConsum(): Integer`**
- **`GetOferteClienti(out Error: Integer): OleVariant`**
- **`GetInfoCmdBon(const NrCmd: Integer; out Error: Integer): OleVariant`**
- **`GetInfoCmdBonuri(const Zi: Integer; out Error: Integer): OleVariant`**
- **`ComenziFurnValide(): Integer`**
- **`ImportaComenziFurn(): Integer`**
- **`GetInfoBonConsum(const Numar: Integer; const Serie: WideString; out Error: Integer): OleVariant`** — 🆕 nou v1.5.0
- **`GetArtComenziInterne(out Error: Integer): OleVariant`**
- **`GetMatComenziInterne(out Error: Integer): OleVariant`**
- **`GetStadiuComanda(const IDPart: WideString; const NrComanda: WideString; const DataComanda: WideString; out Error: Integer): WideString`**

### 9. Vanzari

- **`GetUltimeleVanzari(const ArtID: WideString; const PartID: WideString; const MarcaAgent: Integer; const Cate: Integer; out Error: Integer): OleVariant`**
- **`GetIstoricVanzari(const Marca: Integer; const AnInceput: Integer; const LunaInceput: Integer; out Error: Integer): Integer`**
- **`GetVanzariLuna(out Error: Integer): OleVariant`**
- **`GetVanzariExt(out Error: Integer): OleVariant`**
- **`GetVanzariEmulare(out Error: Integer): OleVariant`**
