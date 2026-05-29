package winmentor

// Partner represents a business partner (customer/supplier) from WinMENTOR.
// PDF documents 38 fields but the DLL actually returns 49.
// The field layout diverges from the PDF starting at index [17].
type Partner struct {
	ID               string // [0]
	Denumire         string // [1]
	CodFiscal        string // [2]
	Localitate       string // [3]
	Adresa           string // [4]
	Telefon          string // [5]
	PersContact      string // [6]
	SimbolClasa      string // [7]
	DenClasa         string // [8]
	SimbolCatPret    string // [9]
	DenCatPret       string // [10]
	MarcaAgent       string // [11]
	NumeAgent        string // [12]
	PrenumeAgent     string // [13]
	Scadenta         string // [14]
	Discount         string // [15]
	DenCritDiscount  string // [16]
	CodExtern        string // [17] PDF says SediiPartener here, but actual data = CodExtern
	PartnerBlocat    string // [18] DA/NU
	CreditVanzare    string // [19]
	NrRegCom         string // [20] trade register number (e.g. "J08/10/2003"), undocumented in PDF
	ContBanca        string // [21]
	LocalitatiSedii  string // [22] "~" separated
	Judet            string // [23] county code (e.g. "BV"), undocumented in PDF
	MarcaAgentiSedii string // [24] "~" separated
	Observatii       string // [25]
	FlagSediuSocial  string // [26] "~" separated D flags
	CodPostalSedii   string // [27] "~" separated
	EmailSedii       string // [28] "~" separated
	TelPersContact   string // [29]
	PFsauPJ          string // [30] PF or PJ
	MonedaImplicita  string // [31]
	DataAdaugarii    string // [32]
	Trasee           string // [33]
	PuncteAcumulate  string // [34]
	CodFiscalSedii   string // [35] "~" separated
	InfoTipSediu     string // [36] "~" separated
	FlagPlataCard      string // [37] DA/NU
	FlagNonUE          string // [38] NU
	DenumiriSediiExt   string // [39] ~ separated
	SerieBuletinSedii  string // [40] e.g. XH, ZH
	NumarBuletinSedii  string // [41] e.g. 062753
	Tara               string // [42] country code (e.g. "RO", "BE")
	TelefonSediiExt    string // [43] ~ separated
	FlagRezilient      string // [44] NU
	LimitaCredit       string // [45] 0
	SoldCurent         string // [46] 0
	PuncteBonus        string // [47] 0
	Unknown48          string // [48] always empty in observed data
}

// PartnerInput represents the data structure for adding/modifying a partner.
type PartnerInput struct {
	ID                    string
	Denumire              string
	CodFiscal             string
	SediulInLocalitatea   string
	AdresaSediu           string
	TelefonSediu          string
	PersoaneContact       string // "~" separated
	SimbolClasa           string
	SimbolCategoriePret   string
	IDAgentImplicit       string
	NrRegistrulComertului string
	Observatii            string
	SimbolBanca           string // "~" separated
	NumeBanca             string // "~" separated
	LocalitateBanca       string // "~" separated
	ContBanca             string // "~" separated
	ZiImplicitaPlata      string
	NumeSediuSecundar     string // "~" separated
	AdresaSediuSecundar   string // "~" separated
	TelefonSediuSecundar  string // "~" separated
	LocalitateSediuSec    string // "~" separated
	IDAgentSediuSec       string // "~" separated
	CodExtern             string
	SimbolAutoJudetLivr   string
	SimbolAutoJudetSediu  string
	FlagPF                string
	ScadentaImplicita     string
	SimbolTipContabil     string
	FlagProducator        string // P
	EmailSediuSocial      string
	EmailSediiLivrare     string // "~" separated
	TVAIncasare           string // D
	SerAI                 string
	NrAI                  string
	SimbolAutoTaraSediu   string
}

// ToRecord serializes a PartnerInput to a semicolon-separated string.
func (p *PartnerInput) ToRecord() string {
	fields := []string{
		p.ID, p.Denumire, p.CodFiscal, p.SediulInLocalitatea,
		p.AdresaSediu, p.TelefonSediu, p.PersoaneContact,
		p.SimbolClasa, p.SimbolCategoriePret, p.IDAgentImplicit,
		p.NrRegistrulComertului, p.Observatii,
		p.SimbolBanca, p.NumeBanca, p.LocalitateBanca, p.ContBanca,
		p.ZiImplicitaPlata,
		p.NumeSediuSecundar, p.AdresaSediuSecundar, p.TelefonSediuSecundar,
		p.LocalitateSediuSec, p.IDAgentSediuSec,
		p.CodExtern, p.SimbolAutoJudetLivr, p.SimbolAutoJudetSediu,
		p.FlagPF, p.ScadentaImplicita, p.SimbolTipContabil,
		p.FlagProducator, p.EmailSediuSocial, p.EmailSediiLivrare,
		p.TVAIncasare, p.SerAI, p.NrAI, p.SimbolAutoTaraSediu,
	}
	result := ""
	for i, f := range fields {
		if i > 0 {
			result += ";"
		}
		result += f
	}
	return result
}

// StockArticle represents an article in stock.
// PDF documents 17 fields but the DLL actually returns 21.
type StockArticle struct {
	CodExtern      string // [0]
	Denumire       string // [1]
	UM             string // [2]
	PretVanzare    string // [3]
	Stoc           string // [4]
	SimbolClasa    string // [5]
	DenClasa       string // [6]
	IDProducator   string // [7]
	DenProducator  string // [8]
	IDFurnizor     string // [9]
	DenFurnizor    string // [10]
	SimbolGestiune string // [11]
	DenGestiune    string // [12]
	CotaTVA        string // [13]
	FlagTVAInclus  string // [14] D/NU - whether VAT is included in sale price
	PretCuTVA      string // [15]
	StocRezervat     string // [16]
	StocMinim        string // [17] minimum stock / quantity per pallet (e.g. 16, 2, 20, 800)
	IDIntern         string // [18] internal WinMENTOR article ID
	ObservatiiProdus string // [19] text observations, e.g. "54SACI/PALET"
	Unknown20        string // [20] undocumented, always empty in observed data
}

// ArticleStock represents the stock info for a single article.
type ArticleStock struct {
	CodExtern   string
	Denumire    string
	UM          string
	PretVanzare string
	Stoc        string
}

// StocGestiune represents a stock entry per warehouse from GetStocuriPeGestiuni.
// Format: "DenGestiune;SimbolGestiune;Denumire;CodExtern;ContContabil;UM;Stoc;ValoareStoc;ValoareStocPrecisa;CotaTVA"
type StocGestiune struct {
	DenGestiune        string // [0]
	SimbolGestiune     string // [1]
	Denumire           string // [2]
	CodExtern          string // [3]
	ContContabil       string // [4]
	UM                 string // [5]
	Stoc               string // [6]
	ValoareStoc        string // [7] total stock value (rounded)
	ValoareStocPrecisa string // [8] total stock value (precise)
	CotaTVA            string // [9]
}

// SoldPartener represents a partner's balance.
type SoldPartener struct {
	CodExtern string
	Denumire  string
	Sold      string
}

// Sold represents a balance record from GetSolduri.
// Format: "IDPartener;TipDoc;NrDoc;DataDoc;RestDePlata;DataScadenta;Sediu;MarcaAgent;ValoareDoc;Moneda;Camp10"
type Sold struct {
	IDPartener   string // [0] ID partener
	TipDocument  string // [1] "Factura" sau "Avans"
	NrDocument   string // [2] Numar document
	DataDocument string // [3] Data document
	RestDePlata  string // [4] Rest de plata
	DataScadenta string // [5] Data scadenta (1899=nesetat)
	Sediu        string // [6] Index sediu partener
	MarcaAgent   string // [7] Marca agent
	ValoareDoc   string // [8] Valoare document
	Moneda       string // [9] Moneda (Lei/EUR)
	Camp10       string // [10]
}

// Employee represents a WinMENTOR employee record.
// Format: "Nume Prenume;Marca;CNP;EsteActiv;EsteAgent;SerieBuletin;NumarBuletin;CodPostal;Camp8"
// Note: Nume+Prenume are combined in field [0], there is no separate Prenume field.
type Employee struct {
	Nume         string // [0] Nume + Prenume combined
	Marca        string // [1] Employee ID
	CNP          string // [2] Personal ID number
	EsteActiv    string // [3] Da/Nu
	EsteAgent    string // [4] Da/Nu
	SerieBuletin string // [5] ID card series
	NumarBuletin string // [6] ID card number
	CodPostal    string // [7] Postal code
}

// DetailedBalance represents a line in the detailed balance (invoice or advance).
// Raw format (11 fields): "Tip;NrDocument;DataDocument;Rest;DataScadenta;MarcaAgent;Moneda;Sediu;Field8;Field9;Field10"
type DetailedBalance struct {
	Type         string // [0] "Factura" or "Avans"
	NrDocument   string // [1]
	DataDocument string // [2]
	Rest         string // [3]
	DataScadenta string // [4]
	MarcaAgent   string // [5]
	Moneda       string // [6]
	Sediu        string // [7]
}

// Gestiune represents a warehouse/store.
type Gestiune struct {
	Simbol   string
	Denumire string
}

// ClasaParteneri represents a partner class.
type ClasaParteneri struct {
	Simbol   string
	Denumire string
}

// Product represents a product returned by GetProducts.
type Product struct {
	IDArticol             string
	Denumire              string
	DenUM                 string
	IDProducator          string
	DenumireProducator    string
	TipSerie              string
	DataAdaugarii         string
	DataUltimeiModificari string
	TipUM                 string
	CodInternWinMentor    string
	SimbolClasa           string
}

// DeletedProduct represents a deleted product entry.
type DeletedProduct struct {
	CodInternWinMentor string
	DataOraStergerii   string
}

// Bank represents a bank entry.
// Raw format: "Simbol;Denumire;Field2;Field3" (4 fields, last 2 always empty)
type Bank struct {
	Simbol   string
	Denumire string
}

// Oferta represents a price offer.
// Raw format: "PartID;ArtID;DataInceput;DataSfarsit;Pret;Cantitate;Discount;CantMinima;Field8;Moneda;Field10"
type Oferta struct {
	PartID      string
	ArtID       string
	DataInceput string
	DataSfarsit string
	Pret        string
	Cantitate   string
	Discount    string
	CantMinima  string
	Moneda      string
}

// ClasaArticole represents an article class.
type ClasaArticole struct {
	Simbol   string
	Denumire string
}

// NomenclatorArticol represents an article in the full nomenclature.
// PDF documents 24 fields but the DLL actually returns 40.
type NomenclatorArticol struct {
	CodExtern           string // [0]
	Denumire            string // [1]
	DenUM               string // [2]
	PretVanzare         string // [3]
	SimbolClasa         string // [4]
	DenClasa            string // [5]
	CodExternProducator string // [6]
	DenProducator       string // [7]
	GestImplicita       string // [8]
	CodExternUnic       string // [9]
	CotaTVA             string // [10]
	DenUMSecundara      string // [11]
	ParitateUMSecundara string // [12]
	Masa                string // [13]
	Serviciu            string // [14] Da/Nu
	CodVamal            string // [15]
	PretMinim           string // [16]
	CantImplicita       string // [17]
	PretValuta          string // [18]
	DataAdaug           string // [19]
	Masa2               string // [20]
	PretVCuTVA          string // [21]
	Locatie             string // [22]
	PretReferinta       string // [23]
	FlagActiv           string // [24] DA/NU
	Unknown25           string // [25]
	CodAlternativ       string // [26] alternate code, e.g. "CIP SAMSUNG"
	Unknown27           string // [27] always "0"
	CotaTVA2            string // [28] secondary VAT rate or weight
	FlagNU29            string // [29] always NU
	Unknown30           string // [30]
	Unknown31           string // [31]
	Unknown32           string // [32]
	Unknown33           string // [33]
	Unknown34           string // [34]
	Unknown35           string // [35]
	FlagNU36            string // [36] always NU
	Unknown37           string // [37]
	Unknown38           string // [38]
	Unknown39           string // [39]
}

// VanzareExt represents an extended sale record.
// PDF documents 18 fields but the DLL actually returns 23.
type VanzareExt struct {
	IDPartener    string // [0] always empty
	Zi            string // [1] day of month
	NrFactura     string // [2] invoice number
	CodArticol    string // [3] article code
	Cant          string // [4] quantity
	DenUM         string // [5] unit of measure
	Pret          string // [6] price
	DenGest       string // [7] warehouse name
	Unknown8      string // [8] always "0"
	LocatieClient string // [9] client location/branch
	MarcaAgent    string // [10] agent ID (verified via cross-check with VanzariLuna)
	CodFiscal     string // [11] customer tax ID
	Unknown12     string // [12] always "0"
	Adresa        string // [13] customer address
	Unknown14     string // [14] always "0"
	CodPostal     string // [15] postal code
	ClasaArticol   string // [16] article class
	TipDocument    string // [17] document type ("=", "S")
	PozitieDocument string // [18] document position, e.g. 159, 2, 20
	PrefixCarnet   string // [19] booklet prefix
	Moneda        string // [20] currency
	Unknown21     string // [21]
	Unknown22     string // [22]
}

// VanzareLuna represents a monthly sale line.
// PDF documents 10 fields but the DLL actually returns 26.
type VanzareLuna struct {
	IDPartener        string // [0] always empty
	Zi                string // [1] day of month
	NrFactura         string // [2] invoice number
	CodArticol        string // [3] article code
	NumarComanda      string // [4] order number
	Cant              string // [5] quantity
	DenUM             string // [6] unit of measure
	Pret              string // [7] price
	MarcaAgent        string // [8] agent code
	ValoareFactura    string // [9] invoice value with VAT
	DataScadenta      string // [10] due date
	TVAInclus         string // [11] VAT included flag ("NU")
	CotaTVA           string // [12] VAT rate
	TipDocument       string // [13] document type ("F")
	PrefixCarnet      string // [14] booklet prefix
	SerieDocument     string // [15] full document series
	DenArticol        string // [16] article description
	Discount          string // [17] discount value, e.g. -6, -5.87
	Unknown18         string // [18]
	DataEmitere       string // [19] issue date
	SediuClient       string // [20] client branch
	AdresaClient      string // [21] client address
	LocalitateClient  string // [22] client town
	ObservatiiFactura string // [23] invoice notes
	Observatii2       string // [24] additional notes
	Unknown25         string // [25]
}

// Intrare represents an incoming entry (purchase) line.
// DLL returns 11 fields.
type Intrare struct {
	IDPartener string // [0] always empty
	Data       string // [1] date
	NrDoc      string // [2] document number
	CodArticol string // [3] article code
	Cant       string // [4] quantity
	DenUM      string // [5] unit of measure
	Pret       string // [6] price/value
	DenGest    string // [7] warehouse name
	Unknown8   string // [8] always "0"
	Flag       string // [9] "DA" or empty
	Unknown10  string // [10]
}

// SoldExt represents an extended balance line.
// Format real: 13 câmpuri (nu 10 cum zice documentația)
type SoldExt struct {
	IDPartener        string // [0]
	Tip               string // [1] "Factura" or "Avans"
	NrFactura         string // [2]
	DataFactura       string // [3]
	RestDePlata       string // [4]
	TermenDePlata     string // [5]
	LocatiePartener   string // [6]
	MarcaAgent        string // [7]
	ValoareFactura    string // [8]
	ObservatiiFactura string // [9]
	Camp10            string // [10]
	CotaTVA           string // [11]
	TipDocument       string // [12] BF., F.MAL etc.
}

// ComandaNefacturata represents an uninvoiced order line.
// Raw format: "CodArticol;NrComanda;Cantitate;DenUM;DataComanda;IDPartener;MarcaAgent;Pret;
//   CantComanda;DenUM2;CodExtern2;SerieDocument;NrDocument;Observatii;SediuPartener;
//   Field15;DataLivrare;Field17;Field18;Field19;Pozitie;NumePartener;Flag;Field23;Moneda;Field25"
type ComandaNefacturata struct {
	CodArticol     string
	NrComanda      string
	Cantitate      string
	DenUM          string
	DataComanda    string
	IDPartener     string
	MarcaAgent     string
	Pret           string
	CantComanda    string
	CodExternAlt   string
	SerieDocument  string
	NrDocument     string
	Observatii     string
	SediuPartener  string
	DataLivrare    string
	Pozitie        string
	NumePartener   string
	Moneda         string
}

// CategoriePret represents a price category.
type CategoriePret struct {
	Simbol   string
	Denumire string
	Flag     string
}

// Carnet represents a document book.
// Format: "Simbol;TipDocument;Denumire;Camp3"
type Carnet struct {
	Simbol       string // [0] Simbol carnet (MAL, NT, etc.)
	TipDocument  string // [1] Tip document (FACT, etc.)
	Denumire     string // [2] Denumire (Factura fiscala, etc.)
}

// ClientInfo represents a client from GetListaClienti.
type ClientInfo struct {
	CodIntern      string
	CodExtern      string
	Denumire       string
	CodFiscal      string
	Localitate     string
	Judet          string
	Adresa         string
	Telefon        string
	MarcaAgent     string
	DataFact       string
	SediiPart      string
	SimbolClasa    string
	DenumireClasa  string
	LocalitSedii   string
}
