package api

import (
	"net/http"
)

// SetupRoutes registers all API endpoints.
func (s *Server) SetupRoutes() http.Handler {
	mux := http.NewServeMux()

	// === Swagger UI (no auth) ===
	mux.HandleFunc("GET /", s.handleSwaggerUI)
	mux.HandleFunc("GET /docs", s.handleSwaggerUI)
	mux.HandleFunc("GET /api/docs", s.handleSwaggerUI)
	mux.HandleFunc("GET /api/openapi.json", s.handleOpenAPISpec)

	// === System (no auth on health/diagnostics) ===
	mux.HandleFunc("GET /api/health", s.handleHealth)
	mux.HandleFunc("GET /api/diagnostics", s.handleDiagnostics)
	mux.HandleFunc("GET /api/erori", s.handleGetErori)
	mux.HandleFunc("GET /api/versiuni", s.handleGetVersiuni)
	mux.HandleFunc("GET /api/constante", s.handleGetConstanta)
	mux.HandleFunc("GET /api/system/pot-introduce-doc", s.handlePotIntroduceDoc)
	mux.HandleFunc("GET /api/system/check-document", s.handleCheckDocument)

	// === Firme ===
	mux.HandleFunc("GET /api/firme", s.handleGetFirme)
	mux.HandleFunc("GET /api/firme/{name}/luni", s.handleGetLuni)
	mux.HandleFunc("POST /api/firme/select", s.handleSelectFirma)

	// === Articole & Produse ===
	mux.HandleFunc("GET /api/articole", s.handleGetArticole)
	mux.HandleFunc("GET /api/articole/clase", s.handleGetClaseArticole)
	mux.HandleFunc("POST /api/articole/gen-cod", s.handleGenCodArticole)
	mux.HandleFunc("POST /api/articole/set-clasa", s.handleSetClasaArt)
	mux.HandleFunc("POST /api/articole/add-clasa", s.handleAddClasaArt)
	mux.HandleFunc("GET /api/produse", s.handleGetProducts)
	mux.HandleFunc("GET /api/produse/stergeri", s.handleGetStergeriProduse)
	mux.HandleFunc("POST /api/produse/add", s.handleAddProduct)
	mux.HandleFunc("PUT /api/produse/update", s.handleModiProduct)

	// === Config ===
	mux.HandleFunc("POST /api/config/id-art-field", s.handleSetIDArtField)
	mux.HandleFunc("POST /api/config/id-part-field", s.handleSetIDPartField)
	mux.HandleFunc("POST /api/config/cod-cmd-analizata", s.handleSetCodCmdAnalizata)
	mux.HandleFunc("POST /api/config/data-referinta", s.handleSetDataReferinta)
	mux.HandleFunc("POST /api/config/index-nart", s.handleSetIndexNart)
	mux.HandleFunc("POST /api/config/cant-receptii", s.handleSetCantReceptii)

	// === Stocuri ===
	mux.HandleFunc("GET /api/stocuri", s.handleGetStocuri)
	mux.HandleFunc("GET /api/stocuri/ext", s.handleGetStocuriExt)
	mux.HandleFunc("GET /api/stocuri/ext2", s.handleGetStocuriExt2)
	mux.HandleFunc("GET /api/stocuri/ext3", s.handleGetStocuriExt3)
	mux.HandleFunc("GET /api/stocuri/ext4", s.handleGetStocuriExt4)
	mux.HandleFunc("GET /api/stocuri/ext5", s.handleGetStocuriExt5)
	mux.HandleFunc("GET /api/stocuri/pe-gestiuni", s.handleGetStocuriPeGestiuni)
	mux.HandleFunc("GET /api/stocuri/ambalaje/initial", s.handleGetStocInitialAmbalaj)
	mux.HandleFunc("GET /api/stocuri/ambalaje/miscari", s.handleGetMiscariAmbalaje)
	mux.HandleFunc("GET /api/stocuri/articol/{id}", s.handleGetStocArticol)
	mux.HandleFunc("GET /api/stocuri/articol/{id}/detaliat", s.handleGetStocArtDetaliat)

	// === Parteneri ===
	mux.HandleFunc("GET /api/parteneri", s.handleGetParteneri)
	mux.HandleFunc("GET /api/parteneri/clase", s.handleGetClaseParteneri)
	mux.HandleFunc("GET /api/parteneri/next-id", s.handleGetNextPartID)
	mux.HandleFunc("POST /api/parteneri/gen-cod", s.handleGenCodParteneri)
	mux.HandleFunc("POST /api/parteneri/add", s.handleAdaugaPartener)
	mux.HandleFunc("PUT /api/parteneri/update", s.handleModificaPartener)
	mux.HandleFunc("GET /api/parteneri/{id}/info", s.handleGetInfoPart)
	mux.HandleFunc("GET /api/clienti", s.handleGetClienti)
	mux.HandleFunc("GET /api/localitati", s.handleGetLocalitati)
	mux.HandleFunc("GET /api/nomenclator-localitati", s.handleGetNomenclatorLocalitati)

	// === Vanzari ===
	mux.HandleFunc("GET /api/vanzari/luna", s.handleGetVanzariLuna)
	mux.HandleFunc("GET /api/vanzari/ext", s.handleGetVanzariExt)
	mux.HandleFunc("GET /api/vanzari/articole-vandute", s.handleGetArticoleVandute)
	mux.HandleFunc("GET /api/vanzari/ultimele", s.handleGetUltimeleVanzari)
	mux.HandleFunc("GET /api/vanzari/emulare", s.handleGetVanzariEmulare)
	mux.HandleFunc("GET /api/vanzari/emulare/nedescarcata", s.handleGetEmulareNedescarcata)

	// === Facturi ===
	mux.HandleFunc("GET /api/receptii/neoperate", s.handleGetReceptiiNeoperate)
	mux.HandleFunc("GET /api/facturi/exists/{nr}", s.handleExistaFactura)
	mux.HandleFunc("GET /api/facturi/ext/{prefix}/{nr}/exists", s.handleExistaFacturaExt)
	mux.HandleFunc("GET /api/facturi/intrare/exists", s.handleExistaFacturaIntrare)
	mux.HandleFunc("GET /api/facturi/numar/{carnet}", s.handleGetNumarFactura)
	mux.HandleFunc("GET /api/carnete", s.handleGetCarnete)
	mux.HandleFunc("GET /api/carnete/ext", s.handleGetCarneteExt)
	mux.HandleFunc("GET /api/intrari", s.handleGetIntrari)
	mux.HandleFunc("GET /api/receptii", s.handleGetReceptii)
	mux.HandleFunc("GET /api/receptii/subunitati", s.handleGetReceptiiSubunitati)
	mux.HandleFunc("GET /api/nir/atribute", s.handleGetNirAtribute)

	// === Comenzi ===
	mux.HandleFunc("GET /api/comenzi/interne/articole", s.handleGetArtComenziInterne)
	mux.HandleFunc("GET /api/comenzi/interne/materiale", s.handleGetMatComenziInterne)
	mux.HandleFunc("GET /api/comenzi/stadiu", s.handleGetStadiuComanda)
	mux.HandleFunc("GET /api/comenzi/nefacturate", s.handleGetComenziNefacturate)
	mux.HandleFunc("GET /api/comenzi/nefacturate/info", s.handleGetInfoCmdNefacturate)
	mux.HandleFunc("GET /api/comenzi/sub-nefacturate/info", s.handleGetInfoCmdSubNefacturate)
	mux.HandleFunc("GET /api/comenzi/bon/info", s.handleGetInfoCmdBon)
	mux.HandleFunc("GET /api/comenzi/bonuri/info", s.handleGetInfoCmdBonuri)
	mux.HandleFunc("GET /api/comenzi/furnizori", s.handleGetComenziFurnizori)
	mux.HandleFunc("POST /api/comenzi/set-acceptat", s.handleSetCmdImplicitAcceptat)
	mux.HandleFunc("POST /api/comenzi/actualizeaza-acceptat", s.handleActualizeazaAcceptat)
	mux.HandleFunc("GET /api/transferuri", s.handleGetTransferuri)
	mux.HandleFunc("GET /api/bonuri/info", s.handleGetInfoBon)
	mux.HandleFunc("GET /api/bonuri/info-ext", s.handleGetInfoBonExt)

	// === Comenzi (noi) ===
	mux.HandleFunc("GET /api/comenzi/info", s.handleGetInfoComenzi)
	mux.HandleFunc("GET /api/comenzi/subunitati-active", s.handleGetCmdSubunitActive)
	mux.HandleFunc("POST /api/comenzi/set-inclusiv-furn", s.handleSetInclusivCmdFurn)

	// === Solduri & Financiar ===
	mux.HandleFunc("GET /api/plati/luna", s.handleGetPlatiLuna)
	mux.HandleFunc("GET /api/incasari/ext", s.handleGetIncasariClientiExt)
	mux.HandleFunc("GET /api/solduri", s.handleGetSolduri)
	mux.HandleFunc("GET /api/solduri/ext", s.handleGetSolduriExt)
	mux.HandleFunc("GET /api/solduri/furnizori", s.handleGetSolduriFurn)
	mux.HandleFunc("GET /api/solduri/facturi-neoperate", s.handleGetSoldFactNeop)
	mux.HandleFunc("GET /api/solduri/partener/{id}", s.handleGetSoldPart)
	mux.HandleFunc("GET /api/solduri/partener/{id}/detaliat", s.handleGetSoldDetaliat)
	mux.HandleFunc("GET /api/incasari", s.handleGetIncasari)
	mux.HandleFunc("GET /api/incasari/luna", s.handleGetIncasariLuna)
	mux.HandleFunc("GET /api/incasari/factura", s.handleGetIncasariFactura)
	mux.HandleFunc("GET /api/plati/factura", s.handleGetPlatiFactura)

	// === Preturi & Discount ===
	mux.HandleFunc("GET /api/preturi/vanzare", s.handleGetPretVanzare)
	mux.HandleFunc("GET /api/preturi/categorii", s.handleGetCatPret)
	mux.HandleFunc("GET /api/preturi/articole-categorii", s.handleGetArtCatPret)
	mux.HandleFunc("GET /api/preturi/articole-categorii/ext", s.handleGetArtCatPretExt)
	mux.HandleFunc("GET /api/discount/pe-articole", s.handleGetDiscArticole)
	mux.HandleFunc("GET /api/discount/pe-clase", s.handleGetDiscClase)
	mux.HandleFunc("GET /api/discount/pe-parteneri", s.handleGetDiscPart)
	mux.HandleFunc("GET /api/oferte", s.handleGetOferte)
	mux.HandleFunc("GET /api/oferte/clienti", s.handleGetOferteClienti)
	mux.HandleFunc("GET /api/preturi/articol-categorii2", s.handleGetArtCatPret2)
	mux.HandleFunc("GET /api/discount/intervale", s.handleGetIntervaleDisc)
	mux.HandleFunc("GET /api/discount/articole", s.handleGetArticoleDisc)
	mux.HandleFunc("POST /api/config/art-analizat", s.handleSetArtAnalizat)
	mux.HandleFunc("POST /api/config/cat-pret-implicita", s.handleSetCatPretImplicita)
	mux.HandleFunc("POST /api/config/filtru-doc-neoperate", s.handleSetFiltruDocNeoperate)
	mux.HandleFunc("POST /api/config/inclusiv-fact-aviz", s.handleSetInclusivFactAviz)

	// === Vanzari (noi) ===
	mux.HandleFunc("GET /api/vanzari/istoric", s.handleGetIstoricVanzari)
	mux.HandleFunc("GET /api/vanzari/istoric/all", s.handleGetIstoricVanzariAll)

	// === Nomenclatoare ===
	mux.HandleFunc("GET /api/unitati-masura", s.handleGetUM)
	mux.HandleFunc("GET /api/atribute/valori", s.handleGetValoriAtribute)
	mux.HandleFunc("GET /api/retete", s.handleGetReteteActive)
	mux.HandleFunc("GET /api/reziduale", s.handleGetReziduale)
	mux.HandleFunc("GET /api/gestiuni", s.handleGetGestiuni)
	mux.HandleFunc("POST /api/gestiuni/add", s.handleAdaugaGestiune)
	mux.HandleFunc("GET /api/personal", s.handleGetPersonal)
	mux.HandleFunc("GET /api/personal/{id}", s.handleGetInfoPers)
	mux.HandleFunc("GET /api/banci", s.handleGetBanci)
	mux.HandleFunc("GET /api/subunitati", s.handleGetSubunitati)
	mux.HandleFunc("GET /api/delegati", s.handleGetDelegati)
	mux.HandleFunc("GET /api/monede", s.handleGetMonede)

	// === Auth ===
	mux.HandleFunc("POST /api/auth/logon", s.handleLogOn)

	// === Articole (extended) ===
	mux.HandleFunc("PUT /api/articole/modifica", s.handleModificaArticol)

	// === Import Generic ===
	mux.HandleFunc("POST /api/import/{docType}", s.handleImportGeneric)

	// === Import Specific (validate + import) ===
	mux.HandleFunc("POST /api/import/facturi-iesire/validate", s.handleValidateFacturiIesire)
	mux.HandleFunc("POST /api/import/facturi-iesire/import", s.handleImportFacturiIesire)
	mux.HandleFunc("POST /api/import/facturi-intrare/validate", s.handleValidateFacturiIntrare)
	mux.HandleFunc("POST /api/import/facturi-intrare/import", s.handleImportFacturiIntrare)
	mux.HandleFunc("POST /api/import/comenzi/validate", s.handleValidateComenzi)
	mux.HandleFunc("POST /api/import/comenzi/import", s.handleImportComenzi)
	mux.HandleFunc("POST /api/import/comenzi-ext/validate", s.handleValidateComenziExt)
	mux.HandleFunc("POST /api/import/comenzi-ext/import", s.handleImportComenziExt)
	mux.HandleFunc("POST /api/import/comenzi-furnizori/validate", s.handleValidateComenziFurn)
	mux.HandleFunc("POST /api/import/comenzi-furnizori/import", s.handleImportComenziFurn)
	mux.HandleFunc("POST /api/import/bonuri-consum/validate", s.handleValidateBonuriConsum)
	mux.HandleFunc("POST /api/import/bonuri-consum/import", s.handleImportBonuriConsum)
	mux.HandleFunc("POST /api/import/transferuri/validate", s.handleValidateTransferuri)
	mux.HandleFunc("POST /api/import/transferuri/import", s.handleImportTransferuri)
	mux.HandleFunc("POST /api/import/monetare/validate", s.handleValidateMonetare)
	mux.HandleFunc("POST /api/import/monetare/import", s.handleImportMonetare)
	mux.HandleFunc("POST /api/import/incasari/validate", s.handleValidateIncasari)
	mux.HandleFunc("POST /api/import/incasari/import", s.handleImportIncasari)
	mux.HandleFunc("POST /api/import/plati/validate", s.handleValidatePlati)
	mux.HandleFunc("POST /api/import/plati/import", s.handleImportPlati)
	mux.HandleFunc("POST /api/import/note-contabile/validate", s.handleValidateNoteContabile)
	mux.HandleFunc("POST /api/import/note-contabile/import", s.handleImportNoteContabile)
	mux.HandleFunc("POST /api/import/modificari-pret/validate", s.handleValidateModificariPret)
	mux.HandleFunc("POST /api/import/modificari-pret/import", s.handleImportModificariPret)
	mux.HandleFunc("POST /api/import/incasari-ext/validate", s.handleValidateIncasariExt)
	mux.HandleFunc("POST /api/import/incasari-ext/import", s.handleImportIncasariExt)
	mux.HandleFunc("POST /api/import/plati-ext/validate", s.handleValidatePlatiExt)
	mux.HandleFunc("POST /api/import/plati-ext/import", s.handleImportPlatiExt)
	mux.HandleFunc("POST /api/import/reglare-inventar/validate", s.handleValidateReglareInventar)
	mux.HandleFunc("POST /api/import/reglare-inventar/import", s.handleImportReglareInventar)

	// === Produse Structurat (JSON → string ; automat) ===
	mux.HandleFunc("POST /api/produse/add-json", s.handleStructuredAddProduct)
	mux.HandleFunc("PUT /api/produse/update-json", s.handleStructuredModiProduct)
	mux.HandleFunc("POST /api/produse/add-json/preview", s.handlePreviewAddProduct)
	mux.HandleFunc("POST /api/produse/update-json/preview", s.handlePreviewModiProduct)

	// === Import Structurat (JSON → INI automat) ===
	mux.HandleFunc("POST /api/import-doc/comenzi-furnizori/validate", s.handleStructuredValidateComenziFurn)
	mux.HandleFunc("POST /api/import-doc/comenzi-furnizori/import", s.handleStructuredImportComenziFurn)
	mux.HandleFunc("POST /api/import-doc/facturi-intrare/validate", s.handleStructuredValidateFacturiIntrare)
	mux.HandleFunc("POST /api/import-doc/facturi-intrare/import", s.handleStructuredImportFacturiIntrare)
	mux.HandleFunc("POST /api/import-doc/facturi-iesire/validate", s.handleStructuredValidateFacturiIesire)
	mux.HandleFunc("POST /api/import-doc/facturi-iesire/import", s.handleStructuredImportFacturiIesire)
	mux.HandleFunc("POST /api/import-doc/comenzi/validate", s.handleStructuredValidateComenzi)
	mux.HandleFunc("POST /api/import-doc/comenzi/import", s.handleStructuredImportComenzi)
	mux.HandleFunc("POST /api/import-doc/transferuri/validate", s.handleStructuredValidateTransferuri)
	mux.HandleFunc("POST /api/import-doc/transferuri/import", s.handleStructuredImportTransferuri)
	mux.HandleFunc("POST /api/import-doc/bonuri-consum/validate", s.handleStructuredValidateBonuriConsum)
	mux.HandleFunc("POST /api/import-doc/bonuri-consum/import", s.handleStructuredImportBonuriConsum)
	mux.HandleFunc("POST /api/import-doc/modificari-pret/validate", s.handleStructuredValidateModificariPret)
	mux.HandleFunc("POST /api/import-doc/modificari-pret/import", s.handleStructuredImportModificariPret)
	mux.HandleFunc("POST /api/import-doc/reglare-inventar/validate", s.handleStructuredValidateReglareInventar)
	mux.HandleFunc("POST /api/import-doc/reglare-inventar/import", s.handleStructuredImportReglareInventar)
	mux.HandleFunc("POST /api/import-doc/{docType}/preview", s.handlePreviewLines)

	// === Logistics / Warehouse ===
	mux.HandleFunc("GET /api/iesiri/info", s.handleGetInfoIesiri)
	mux.HandleFunc("GET /api/iesiri/info-ext", s.handleGetInfoIesiriExt)
	mux.HandleFunc("POST /api/receiving/set-list", s.handleSetReceivingList)
	mux.HandleFunc("GET /api/receiving/status", s.handleGetReceivingStatus)
	mux.HandleFunc("GET /api/inventory/orders", s.handleGetInventoryOrders)
	mux.HandleFunc("POST /api/inventory/set-orders", s.handleSetInventoryOrders)
	mux.HandleFunc("GET /api/livrari/dispozitii", s.handleGetDispozitiiDeLivrare)
	mux.HandleFunc("POST /api/livrari/set-picked", s.handleSetPickedList)
	mux.HandleFunc("GET /api/livrari/orders", s.handleGetDeliveryOrders)
	mux.HandleFunc("POST /api/livrari/set-delivery", s.handleSetDeliveryList)
	mux.HandleFunc("POST /api/system/descarcare-automata", s.handleSetDescarcareAutomata)
	mux.HandleFunc("GET /api/system/tranzactii-in-curs", s.handleGetTranzactiiInCurs)
	mux.HandleFunc("POST /api/system/doc-from-file", s.handleGetDocFromFile)

	// === COM Generic Call (Discovery & Testing) ===
	mux.HandleFunc("GET /api/com/methods", s.handleComMethods)
	mux.HandleFunc("POST /api/com/call", s.handleComCall)

	// Apply middleware chain (outermost first)
	var handler http.Handler = mux
	handler = AuthMiddleware(s.cfg.ApiKey, handler)
	handler = BodyLimitMiddleware(handler)
	handler = LoggingMiddleware(handler)
	handler = CORSMiddleware(handler)
	handler = RecoveryMiddleware(handler)

	return handler
}
