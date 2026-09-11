unit DocImpServer_TLB;

// ************************************************************************ //
// WARNING                                                                  //
// -------                                                                  //
// The types declared in this file were generated from data read from a     //
// Type Library. If this type library is explicitly or indirectly (via      //
// another type library referring to this type library) re-imported, or the //
// 'Refresh' command of the Type Library Editor activated while editing the //
// Type Library, the contents of this file will be regenerated and all      //
// manual modifications will be lost.                                       //
// ************************************************************************ //

// PASTLWTR : $Revision:   1.11.1.75  $
// File generated on 09.06.2008 11:41:22 from Type Library described below.

// ************************************************************************ //
// Type Lib: D:\ComObjsProtejat\DocImpServer.tlb
// IID\LCID: {C609C841-53F5-46CF-9B4A-9BB9618451B0}\0
// Helpfile: 
// HelpString: Project1 Library
// Version:    1.0
// ************************************************************************ //

interface

uses Windows, ActiveX, Classes, Graphics, OleCtrls, StdVCL;

// *********************************************************************//
// GUIDS declared in the TypeLibrary. Following prefixes are used:      //
//   Type Libraries     : LIBID_xxxx                                    //
//   CoClasses          : CLASS_xxxx                                    //
//   DISPInterfaces     : DIID_xxxx                                     //
//   Non-DISP interfaces: IID_xxxx                                      //
// *********************************************************************//
const
  LIBID_DocImpServer: TGUID = '{C609C841-53F5-46CF-9B4A-9BB9618451B0}';
  IID_IDocImpObject: TGUID = '{5A107267-CB15-40BD-B967-7E6C714B96BA}';
  CLASS_DocImpObject: TGUID = '{9FCBA6AD-F7A3-440D-BDD1-E7CC6C427282}';
type

// *********************************************************************//
// Forward declaration of interfaces defined in Type Library            //
// *********************************************************************//
  IDocImpObject = interface;
  IDocImpObjectDisp = dispinterface;

// *********************************************************************//
// Declaration of CoClasses defined in Type Library                     //
// (NOTE: Here we map each CoClass to its Default Interface)            //
// *********************************************************************//
  DocImpObject = IDocImpObject;


// *********************************************************************//
// Interface: IDocImpObject
// Flags:     (4416) Dual OleAutomation Dispatchable
// GUID:      {5A107267-CB15-40BD-B967-7E6C714B96BA}
// *********************************************************************//
  IDocImpObject = interface(IDispatch)
    ['{5A107267-CB15-40BD-B967-7E6C714B96BA}']
    function GetListaFirme: OleVariant; safecall;
    function GetListaLuni(const CaleFirma: WideString): OleVariant; safecall;
    function SetNumeFirma(const NumeFirma: WideString): Integer; safecall;
    function SetLunaLucru(An: Integer; Luna: Integer): Integer; safecall;
    procedure SetDocsData(DocData: OleVariant); safecall;
    function DateValide: Integer; safecall;
    function GetListaErori: OleVariant; safecall;
    function ImportaFacturi: Integer; safecall;
    function GetListaParteneri(out Error: Integer): OleVariant; safecall;
    function GetStocArticole(out Error: Integer): OleVariant; safecall;
    function GetStocArticol(const ArticolID: WideString; const GestID: WideString; 
                            out Error: Integer): OleVariant; safecall;
    function GetSoldPart(const PartID: WideString; out Error: Integer): OleVariant; safecall;
    function GetListaPersonal(out Error: Integer): OleVariant; safecall;
    function GetSoldDetaliat(const PartID: WideString; out Error: Integer): OleVariant; safecall;
    function GetListaGestiuni(out Error: Integer): OleVariant; safecall;
    function GetClaseParteneri(out Error: Integer): OleVariant; safecall;
    function ExistaFactura(Numar: Integer): Integer; safecall;
    function GenCodArticole: Integer; safecall;
    function GenCodParteneri: Integer; safecall;
    function GetPretVanzare(const ArtID: WideString; const PartID: WideString; out Error: Integer): OleVariant; safecall;
    function GetClaseArticole(out Error: Integer): OleVariant; safecall;
    function GetListaCatPret(out Error: Integer): OleVariant; safecall;
    function GetListaArtCatPret(out Error: Integer): OleVariant; safecall;
    function GetListaLocalitati(out Error: Integer): OleVariant; safecall;
    function GetNomenclatorArticole(out Error: Integer): OleVariant; safecall;
    function GetSolduri(out Error: Integer): OleVariant; safecall;
    function GetListaCarnete(out Error: Integer): OleVariant; safecall;
    function GetNumarFactura(const SimbolCarnet: WideString; out Error: Integer): Integer; safecall;
    function GetIncasariClienti(An1: Integer; Luna1: Integer; An2: Integer; Luna2: Integer; 
                                const PartID: WideString; out Error: Integer): OleVariant; safecall;
    function AdaugaPartener(const InfoPart: WideString): Integer; safecall;
    function ImportaComenzi: Integer; safecall;
    function ComenziValide: Integer; safecall;
    procedure SetIndexNart(const aIndexName: WideString); safecall;
    function ComenziValideExt: Integer; safecall;
    function ImportaComenziExt: Integer; safecall;
    procedure SetIDPartField(const FieldName: WideString); safecall;
    procedure SetIDArtField(const FieldName: WideString); safecall;
    function GetVersiuni(out VerMentor: Double; out VerServer: Double): Integer; safecall;
    function GetInfoPers(IDPers: Integer; out Error: Integer): OleVariant; safecall;
    function GetInfoPart(const PartID: WideString; out Error: Integer): OleVariant; safecall;
    function IncasariValide: Integer; safecall;
    function ImportaIncasari: Integer; safecall;
    function GetListaClienti(AnInceput: Integer; LunaInceput: Integer; out Error: Integer): OleVariant; safecall;
    function GetStringConstanta(Id: Integer; const Simbol: WideString): WideString; safecall;
    function GetArticoleVandute(const PartID: WideString; MarcaAgent: Integer; AnInceput: Integer; 
                                LunaInceput: Integer; out Error: Integer): OleVariant; safecall;
    function GetUltimeleVanzari(const ArtID: WideString; const PartID: WideString; 
                                MarcaAgent: Integer; Cate: Integer; out Error: Integer): OleVariant; safecall;
    function GetDocFromFile(const FileName: WideString): Integer; safecall;
    function GetNextPartID(out Error: Integer): WideString; safecall;
    function GetIstoricVanzari(Marca: Integer; AnInceput: Integer; LunaInceput: Integer; 
                               out Error: Integer): Integer; safecall;
    function GetListRecord(out EOF: Integer): WideString; safecall;
    function GetComenziNefacturate(out Error: Integer): OleVariant; safecall;
    function GetInfoComenzi(out Error: Integer): OleVariant; safecall;
    function GetSolduriExt(out Error: Integer): OleVariant; safecall;
    function GetVanzariLuna(out Error: Integer): OleVariant; safecall;
    function GetVanzariExt(out Error: Integer): OleVariant; safecall;
    function GetIntrari(out Error: Integer): OleVariant; safecall;
    function GetListaSubunit(out Error: Integer): OleVariant; safecall;
    procedure SetClasaArt(const NumeClasa: WideString); safecall;
    function GetOferte(out Error: Integer): OleVariant; safecall;
    function GetInfoBon(Zi: Integer; out Error: Integer): OleVariant; safecall;
    function GetInfoBonExt(NrBon: Integer; Zi: Integer; out Error: Integer): OleVariant; safecall;
    function GetInfoIesiri(Zi: Integer; out Error: Integer): OleVariant; safecall;
    function GetInfoIesiriExt(NrDoc: Integer; Zi: Integer; out Error: Integer): OleVariant; safecall;
    function GetStocArtDetaliat(const ArtID: WideString; const GestID: WideString; 
                                out Error: Integer): OleVariant; safecall;
    function GetListaArtCatPretExt(out Error: Integer): OleVariant; safecall;
    function BonuriConsumValide: Integer; safecall;
    function ImportaBonuriConsum: Integer; safecall;
    function GetListaDelegati(out Error: Integer): OleVariant; safecall;
    procedure SetCmdImplicitAcceptat(ImplicitAcceptat: Integer); safecall;
    function GetOferteClienti(out Error: Integer): OleVariant; safecall;
    function GetCritDiscPeArticole(out Error: Integer): OleVariant; safecall;
    function GetCritDiscPeClase(out Error: Integer): OleVariant; safecall;
    function GetCritDiscPart(out Error: Integer): OleVariant; safecall;
    function GetStocuriPeGestiuni(out Error: Integer): OleVariant; safecall;
    function GetReceptii(out Error: Integer): OleVariant; safecall;
    function GetTransferuri(out Error: Integer): OleVariant; safecall;
    function CheckDocument(const TipDoc: WideString; const PrefixDoc: WideString; NrDoc: Integer; 
                           out Error: Integer): Integer; safecall;
    function GetListaBanci(out Error: Integer): OleVariant; safecall;
    function IncasariValideExt: Integer; safecall;
    function ImportaIncasariExt: Integer; safecall;
    function GetTranzactiiInCurs(out Error: Integer): OleVariant; safecall;
    function TransferuriValide: Integer; safecall;
    function ImportaTransferuri: Integer; safecall;
    function FactIntrareValida: Integer; safecall;
    function ImportaFactIntrare: Integer; safecall;
    function GetProducts(const LastSyncDate: WideString; out Error: Integer): OleVariant; safecall;
    function GetSuppliersOrders(out Error: Integer): OleVariant; safecall;
    function AddProduct(const InfoProdus: WideString): Integer; safecall;
    function SetReceivingList(Data: OleVariant): Integer; safecall;
  end;

// *********************************************************************//
// DispIntf:  IDocImpObjectDisp
// Flags:     (4416) Dual OleAutomation Dispatchable
// GUID:      {5A107267-CB15-40BD-B967-7E6C714B96BA}
// *********************************************************************//
  IDocImpObjectDisp = dispinterface
    ['{5A107267-CB15-40BD-B967-7E6C714B96BA}']
    function GetListaFirme: OleVariant; dispid 1;
    function GetListaLuni(const CaleFirma: WideString): OleVariant; dispid 2;
    function SetNumeFirma(const NumeFirma: WideString): Integer; dispid 3;
    function SetLunaLucru(An: Integer; Luna: Integer): Integer; dispid 4;
    procedure SetDocsData(DocData: OleVariant); dispid 5;
    function DateValide: Integer; dispid 6;
    function GetListaErori: OleVariant; dispid 8;
    function ImportaFacturi: Integer; dispid 10;
    function GetListaParteneri(out Error: Integer): OleVariant; dispid 7;
    function GetStocArticole(out Error: Integer): OleVariant; dispid 9;
    function GetStocArticol(const ArticolID: WideString; const GestID: WideString; 
                            out Error: Integer): OleVariant; dispid 11;
    function GetSoldPart(const PartID: WideString; out Error: Integer): OleVariant; dispid 12;
    function GetListaPersonal(out Error: Integer): OleVariant; dispid 13;
    function GetSoldDetaliat(const PartID: WideString; out Error: Integer): OleVariant; dispid 14;
    function GetListaGestiuni(out Error: Integer): OleVariant; dispid 15;
    function GetClaseParteneri(out Error: Integer): OleVariant; dispid 16;
    function ExistaFactura(Numar: Integer): Integer; dispid 17;
    function GenCodArticole: Integer; dispid 18;
    function GenCodParteneri: Integer; dispid 19;
    function GetPretVanzare(const ArtID: WideString; const PartID: WideString; out Error: Integer): OleVariant; dispid 20;
    function GetClaseArticole(out Error: Integer): OleVariant; dispid 21;
    function GetListaCatPret(out Error: Integer): OleVariant; dispid 22;
    function GetListaArtCatPret(out Error: Integer): OleVariant; dispid 23;
    function GetListaLocalitati(out Error: Integer): OleVariant; dispid 24;
    function GetNomenclatorArticole(out Error: Integer): OleVariant; dispid 25;
    function GetSolduri(out Error: Integer): OleVariant; dispid 26;
    function GetListaCarnete(out Error: Integer): OleVariant; dispid 27;
    function GetNumarFactura(const SimbolCarnet: WideString; out Error: Integer): Integer; dispid 28;
    function GetIncasariClienti(An1: Integer; Luna1: Integer; An2: Integer; Luna2: Integer; 
                                const PartID: WideString; out Error: Integer): OleVariant; dispid 29;
    function AdaugaPartener(const InfoPart: WideString): Integer; dispid 30;
    function ImportaComenzi: Integer; dispid 31;
    function ComenziValide: Integer; dispid 32;
    procedure SetIndexNart(const aIndexName: WideString); dispid 33;
    function ComenziValideExt: Integer; dispid 34;
    function ImportaComenziExt: Integer; dispid 35;
    procedure SetIDPartField(const FieldName: WideString); dispid 36;
    procedure SetIDArtField(const FieldName: WideString); dispid 37;
    function GetVersiuni(out VerMentor: Double; out VerServer: Double): Integer; dispid 38;
    function GetInfoPers(IDPers: Integer; out Error: Integer): OleVariant; dispid 39;
    function GetInfoPart(const PartID: WideString; out Error: Integer): OleVariant; dispid 40;
    function IncasariValide: Integer; dispid 41;
    function ImportaIncasari: Integer; dispid 42;
    function GetListaClienti(AnInceput: Integer; LunaInceput: Integer; out Error: Integer): OleVariant; dispid 43;
    function GetStringConstanta(Id: Integer; const Simbol: WideString): WideString; dispid 44;
    function GetArticoleVandute(const PartID: WideString; MarcaAgent: Integer; AnInceput: Integer; 
                                LunaInceput: Integer; out Error: Integer): OleVariant; dispid 45;
    function GetUltimeleVanzari(const ArtID: WideString; const PartID: WideString; 
                                MarcaAgent: Integer; Cate: Integer; out Error: Integer): OleVariant; dispid 46;
    function GetDocFromFile(const FileName: WideString): Integer; dispid 47;
    function GetNextPartID(out Error: Integer): WideString; dispid 48;
    function GetIstoricVanzari(Marca: Integer; AnInceput: Integer; LunaInceput: Integer; 
                               out Error: Integer): Integer; dispid 49;
    function GetListRecord(out EOF: Integer): WideString; dispid 50;
    function GetComenziNefacturate(out Error: Integer): OleVariant; dispid 51;
    function GetInfoComenzi(out Error: Integer): OleVariant; dispid 52;
    function GetSolduriExt(out Error: Integer): OleVariant; dispid 53;
    function GetVanzariLuna(out Error: Integer): OleVariant; dispid 54;
    function GetVanzariExt(out Error: Integer): OleVariant; dispid 55;
    function GetIntrari(out Error: Integer): OleVariant; dispid 56;
    function GetListaSubunit(out Error: Integer): OleVariant; dispid 57;
    procedure SetClasaArt(const NumeClasa: WideString); dispid 58;
    function GetOferte(out Error: Integer): OleVariant; dispid 59;
    function GetInfoBon(Zi: Integer; out Error: Integer): OleVariant; dispid 60;
    function GetInfoBonExt(NrBon: Integer; Zi: Integer; out Error: Integer): OleVariant; dispid 61;
    function GetInfoIesiri(Zi: Integer; out Error: Integer): OleVariant; dispid 62;
    function GetInfoIesiriExt(NrDoc: Integer; Zi: Integer; out Error: Integer): OleVariant; dispid 63;
    function GetStocArtDetaliat(const ArtID: WideString; const GestID: WideString; 
                                out Error: Integer): OleVariant; dispid 64;
    function GetListaArtCatPretExt(out Error: Integer): OleVariant; dispid 65;
    function BonuriConsumValide: Integer; dispid 66;
    function ImportaBonuriConsum: Integer; dispid 67;
    function GetListaDelegati(out Error: Integer): OleVariant; dispid 68;
    procedure SetCmdImplicitAcceptat(ImplicitAcceptat: Integer); dispid 69;
    function GetOferteClienti(out Error: Integer): OleVariant; dispid 70;
    function GetCritDiscPeArticole(out Error: Integer): OleVariant; dispid 71;
    function GetCritDiscPeClase(out Error: Integer): OleVariant; dispid 72;
    function GetCritDiscPart(out Error: Integer): OleVariant; dispid 73;
    function GetStocuriPeGestiuni(out Error: Integer): OleVariant; dispid 74;
    function GetReceptii(out Error: Integer): OleVariant; dispid 75;
    function GetTransferuri(out Error: Integer): OleVariant; dispid 76;
    function CheckDocument(const TipDoc: WideString; const PrefixDoc: WideString; NrDoc: Integer; 
                           out Error: Integer): Integer; dispid 77;
    function GetListaBanci(out Error: Integer): OleVariant; dispid 78;
    function IncasariValideExt: Integer; dispid 79;
    function ImportaIncasariExt: Integer; dispid 80;
    function GetTranzactiiInCurs(out Error: Integer): OleVariant; dispid 81;
    function TransferuriValide: Integer; dispid 82;
    function ImportaTransferuri: Integer; dispid 83;
    function FactIntrareValida: Integer; dispid 84;
    function ImportaFactIntrare: Integer; dispid 85;
    function GetProducts(const LastSyncDate: WideString; out Error: Integer): OleVariant; dispid 86;
    function GetSuppliersOrders(out Error: Integer): OleVariant; dispid 87;
    function AddProduct(const InfoProdus: WideString): Integer; dispid 88;
    function SetReceivingList(Data: OleVariant): Integer; dispid 89;
  end;

  CoDocImpObject = class
    class function Create: IDocImpObject;
    class function CreateRemote(const MachineName: string): IDocImpObject;
  end;

implementation

uses ComObj;

class function CoDocImpObject.Create: IDocImpObject;
begin
  Result := CreateComObject(CLASS_DocImpObject) as IDocImpObject;
end;

class function CoDocImpObject.CreateRemote(const MachineName: string): IDocImpObject;
begin
  Result := CreateRemoteComObject(MachineName, CLASS_DocImpObject) as IDocImpObject;
end;

end.
