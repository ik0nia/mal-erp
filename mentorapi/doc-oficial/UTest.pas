unit UTest;

interface

uses
  Windows, Messages, SysUtils, Classes, Graphics, Controls, Forms, Dialogs, DocImpserver_TLB,
  StdCtrls, ExtCtrls;

type

  TTestDocImp = class(TForm)
    EFirma: TEdit;
    ELuna: TEdit;
    EAn: TEdit;
    Label1: TLabel;
    Label2: TLabel;
    Label3: TLabel;
    Button1: TButton;
    Bevel1: TBevel;
    Bevel2: TBevel;
    Label4: TLabel;
    Button2: TButton;
    Erori: TListBox;
    Label5: TLabel;
    LF: TListBox;
    Label6: TLabel;
    Bevel3: TBevel;
    Label7: TLabel;
    Button3: TButton;
    LG: TListBox;
    Bevel4: TBevel;
    Label8: TLabel;
    Button4: TButton;
    LBonCons: TListBox;
    procedure FormCreate(Sender: TObject);
    procedure Button2Click(Sender: TObject);
    procedure Button1Click(Sender: TObject);
    procedure EAnKeyPress(Sender: TObject; var Key: Char);
    procedure Button3Click(Sender: TObject);
    procedure Button4Click(Sender: TObject);
  private
    { Private declarations }
  public
    { Public declarations }
     WinMDocImp : IDocImpObject;
    procedure WndProc(var Message: TMessage); override;

  end;

var
  TestDocImp: TTestDocImp;

implementation

{$R *.DFM}



procedure TTestDocImp.WndProc(var Message: TMessage);
begin
  case Message.Msg of
   WM_COMMAND   : if  HiWord(Message.WParam )= CBN_SELCHANGE then
                  if TestDocImp.LF.ItemIndex <= TestDocImp.LF.Items.Count - 1 then TestDocImp.EFirma.Text := TestDocImp.LF.Items[TestDocImp.LF.ItemIndex];
  end;

  inherited WndProc (Message);
end;

procedure TTestDocImp.FormCreate(Sender: TObject);
begin
  WinMDocImp := IDocImpObject(CoDocImpObject.Create );
end;

procedure TTestDocImp.Button2Click(Sender: TObject);
var ListaFirme : OleVariant;
    i : integer;
begin
  LF.Clear;
  ListaFirme := WinMDocImp.GetListaFirme;
  if VarIsArray(ListaFirme) then
  for i := VarArrayLowBound(ListaFirme, 1) to VarArrayHighBound(ListaFirme, 1) do LF.Items.Add(ListaFirme[i]);

  if LF.Items.Count = 0 then ShowMessage('Nu am citit nici o firma din Baza de date WinMENTOR')
  else begin
    LF.ItemIndex := 0;
    EFirma.Text := LF.Items[LF.ItemIndex];
  end;

end;

procedure TTestDocImp.Button1Click(Sender: TObject);
var i : integer;
    ListaErori : OleVariant;
begin
  if EAn.Text = '' then begin
    ShowMessage('Nu ai introdus anul de lucru');
    Exit;
  end;

  if ELuna.Text = '' then begin
    ShowMessage('Nu ai introdus luna de lucru');
    Exit;
  end;

  if EFirma.Text = '' then begin
    ShowMessage('Nu ai introdus nume prescurtat firma');
    Exit;
  end;



// setare firma de lucru
  Erori.Clear;

  if WinMDocImp.SetNumeFirma(EFirma.Text) = 0 // 0 = setarea nu a reusit; <> 0 setarea a reusit
  then begin
    ListaErori := WinMDocImp.GetListaErori;
    if VarIsArray(ListaErori) then
    for i := VarArrayLowBound(ListaErori, 1) to VarArrayHighBound(ListaErori, 1) do Erori.Items.Add(ListaErori[i]);
  end else begin


   // setare luna de lucru
     if WinMDocImp.SetLunaLucru(StrToIntDef(EAn.Text, 0), StrToIntDef(ELuna.Text, 0)) = 0
     then begin
       ListaErori := WinMDocImp.GetListaErori;
       if VarIsArray(ListaErori) then
       for i := VarArrayLowBound(ListaErori, 1) to VarArrayHighBound(ListaErori, 1) do Erori.Items.Add(ListaErori[i]);
     end;

  end;
end;

procedure TTestDocImp.EAnKeyPress(Sender: TObject; var Key: Char);
begin
  if not (key in ['0'..'9', #8]) then Key := #0;
end;

procedure TTestDocImp.Button3Click(Sender: TObject);
var Eroare, i : integer;
    ListaGest, ListaErori : OleVariant;
begin
  Erori.Clear;
  ListaGest := WinMDocImp.GetListaGestiuni(Eroare);

  if Eroare = 0 then begin // eroare = 0 ;functia a reusit; erioare <> 0 functia bu a reusit
    if VarIsArray(ListaGest) then
    for i := VarArrayLowBound(ListaGest, 1) to VarArrayHighBound(ListaGest, 1) do LG.Items.Add(ListaGest[i]);
  end else begin
   ListaErori := WinMDocImp.GetListaErori;
   if VarIsArray(ListaErori) then
   for i := VarArrayLowBound(ListaErori, 1) to VarArrayHighBound(ListaErori, 1) do Erori.Items.Add(ListaErori[i]);
  end;
end;

procedure TTestDocImp.Button4Click(Sender: TObject);
var va : OleVAriant;
    i  : integer;
    ListaErori : OleVariant;

begin
 // transmite continutul unui pachet de date catre DocImpserver
  VA := VarArrayCreate([0, LBonCons.Items.Count -1 ], varOleStr);
  for i := 0 to LBonCons.Items.Count - 1 do VA[i] :=  LBonCons.Items[i];
  WinMDocImp.SetDocsData(VA);

  Erori.Clear;
  if WinMDocImp.FactIntrareValida = 0 then begin // am erori de validare
    ListaErori := WinMDocImp.GetListaErori;
    if VarIsArray(ListaErori) then
    for i := VarArrayLowBound(ListaErori, 1) to VarArrayHighBound(ListaErori, 1) do Erori.Items.Add(ListaErori[i]);
  end
  else ShowMessage('Au fost importate ' + Inttostr(WinMDocImp.ImportaFactIntrare {functia asta pe langa importul de comenzi returneaza numarul de comenzi importate}) + ' bonuri de consum ');

end;

end.
