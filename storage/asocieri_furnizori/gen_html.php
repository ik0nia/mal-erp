<?php
require '/var/www/erp/vendor/autoload.php';
$app = require '/var/www/erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$buyerIds = [4, 5, 6, 7, 10, 11, 12];
$buyers = DB::table('users')->whereIn('id', $buyerIds)->orderBy('name')->get(['id', 'name']);

$buyerData = [];
foreach ($buyers as $u) {
    $supplierIds   = DB::table('supplier_buyers')->where('user_id', $u->id)->pluck('supplier_id')->all();
    $supplierNames = DB::table('suppliers')->whereIn('id', $supplierIds)->orderBy('name')->pluck('name')->all();
    $productIds    = DB::table('product_suppliers')->whereIn('supplier_id', $supplierIds)->pluck('woo_product_id')->unique()->values()->all();

    $catNames = [];
    foreach (array_chunk($productIds, 300) as $chunk) {
        $c = DB::table('woo_product_category as wpc')
            ->join('woo_categories as wc', 'wc.id', '=', 'wpc.woo_category_id')
            ->whereIn('wpc.woo_product_id', $chunk)
            ->whereNotNull('wc.parent_id')
            ->where('wc.parent_id', '!=', '')
            ->distinct()
            ->pluck('wc.name')
            ->all();
        $catNames = array_merge($catNames, $c);
    }
    $catNames = array_values(array_unique($catNames));
    sort($catNames);

    $buyerData[] = [
        'name'          => $u->name,
        'suppliers'     => $supplierNames,
        'product_count' => count($productIds),
        'categories'    => $catNames,
    ];
}

// Asocieri tabel
$assocRows = DB::table('supplier_buyers as sb')
    ->join('suppliers as s', 's.id', '=', 'sb.supplier_id')
    ->join('users as u', 'u.id', '=', 'sb.user_id')
    ->orderBy('s.name')
    ->get(['s.name as supplier', 'u.name as buyer']);
$grouped = [];
foreach ($assocRows as $r) {
    $grouped[$r->supplier][] = $r->buyer;
}

$withAssoc = DB::table('supplier_buyers')->pluck('supplier_id')->unique()->all();
$noAssocX  = ['Ebal Com', 'Italroman', 'Profil Import Export', 'Teraplast'];
$noAssocMissing = DB::table('suppliers')->whereNotIn('id', $withAssoc)->whereNotIn('name', $noAssocX)->orderBy('name')->pluck('name')->all();
$notFoundInDb   = ['Eltechim', 'Rezmives'];

$badgeClass = [
    'Teo'               => 'b-teo',
    'Cristian'          => 'b-cristi',
    'Eli'               => 'b-eli',
    'Razvan'            => 'b-razvan',
    'Madalin'           => 'b-madalin',
    'Tavi'              => 'b-tavi',
    'Mariana Gardareanu'=> 'b-mariana',
    'Mariana'           => 'b-mariana',
];

ob_start();
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<title>Asocieri furnizori → buyers</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:12.5px;color:#1a1a1a;padding:28px 36px}
h1{font-size:17px;margin-bottom:4px}
.sub{font-size:11px;color:#777;margin-bottom:28px}
h2{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#444;margin:30px 0 10px;padding-bottom:4px;border-bottom:2px solid #ddd}
/* tabel asocieri */
table{width:100%;border-collapse:collapse;margin-bottom:8px}
thead tr{background:#1e3a5f;color:#fff}
thead th{padding:7px 10px;text-align:left;font-size:11.5px}
tbody tr:nth-child(even){background:#f5f8fc}
tbody td{padding:5px 10px;border-bottom:1px solid #e8e8e8}
.bl{display:flex;gap:5px;flex-wrap:wrap}
.badge{display:inline-block;padding:1px 8px;border-radius:11px;font-size:10.5px;font-weight:700}
.b-teo{background:#dbeafe;color:#1d4ed8}
.b-cristi{background:#ede9fe;color:#6d28d9}
.b-eli{background:#fce7f3;color:#be185d}
.b-razvan{background:#dcfce7;color:#15803d}
.b-madalin{background:#fef3c7;color:#b45309}
.b-tavi{background:#ffedd5;color:#c2410c}
.b-mariana{background:#d1fae5;color:#065f46}
/* tag-uri */
.tag{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;margin:2px 3px 2px 0}
.tag-x{background:#fee2e2;color:#b91c1c}
.tag-m{background:#f3f4f6;color:#374151}
.tag-nf{background:#fef9c3;color:#854d0e}
.cnt{font-size:10.5px;color:#aaa;font-weight:400;margin-left:5px}
/* sectiuni buyer */
.buyer-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:6px}
.buyer-card{border:1px solid #e5e5e5;border-radius:8px;overflow:hidden}
.buyer-header{padding:9px 14px;display:flex;align-items:center;gap:10px}
.buyer-name{font-size:13px;font-weight:700}
.buyer-stats{font-size:11px;color:#666;margin-left:auto;text-align:right}
.buyer-body{padding:10px 14px 12px}
.buyer-section-title{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#888;margin:8px 0 4px}
.buyer-section-title:first-child{margin-top:0}
.supplier-list{display:flex;flex-wrap:wrap;gap:4px}
.supplier-tag{display:inline-block;padding:1px 7px;border-radius:8px;font-size:10.5px;background:#f0f0f0;color:#333}
.cat-list{display:flex;flex-wrap:wrap;gap:3px}
.cat-tag{display:inline-block;padding:1px 7px;border-radius:8px;font-size:10px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
/* header colors per buyer */
.h-teo{background:#dbeafe}.h-cristi{background:#ede9fe}.h-eli{background:#fce7f3}
.h-razvan{background:#dcfce7}.h-madalin{background:#fef3c7}.h-tavi{background:#ffedd5}.h-mariana{background:#d1fae5}
@media print{
  body{padding:12px 16px}
  .buyer-grid{grid-template-columns:repeat(2,1fr)}
  tbody tr:hover{background:inherit}
  h2{page-break-after:avoid}
  .buyer-card{page-break-inside:avoid}
}
</style>
</head>
<body>

<h1>Asocieri furnizori → responsabili achiziții</h1>
<p class="sub">Generat: <?= date('d.m.Y H:i') ?> &nbsp;|&nbsp; Sursa: distribuire furnizori.csv + distribuire furnizori 2.csv</p>

<h2>Responsabili achiziții — detalii per buyer</h2>
<div class="buyer-grid">
<?php
$headerClass = [
    'Teo'=>'h-teo','Cristian'=>'h-cristi','Eli'=>'h-eli',
    'Razvan'=>'h-razvan','Madalin'=>'h-madalin','Tavi'=>'h-tavi','Mariana Gardareanu'=>'h-mariana'
];
$badgeCls = $badgeClass;
foreach ($buyerData as $bd):
    $name = $bd['name'];
    $shortName = explode(' ', $name)[0];
    $hcls = $headerClass[$name] ?? '';
    $bcls = $badgeCls[$name] ?? ($badgeCls[$shortName] ?? '');
?>
<div class="buyer-card">
  <div class="buyer-header <?= $hcls ?>">
    <span class="badge <?= $bcls ?>" style="font-size:12px;padding:3px 12px"><?= htmlspecialchars($shortName) ?></span>
    <div class="buyer-stats">
      <strong><?= $bd['product_count'] ?></strong> produse &nbsp;|&nbsp;
      <strong><?= count($bd['suppliers']) ?></strong> furnizori &nbsp;|&nbsp;
      <strong><?= count($bd['categories']) ?></strong> categorii
    </div>
  </div>
  <div class="buyer-body">
    <div class="buyer-section-title">Furnizori</div>
    <div class="supplier-list">
      <?php foreach ($bd['suppliers'] as $s): ?>
        <span class="supplier-tag"><?= htmlspecialchars($s) ?></span>
      <?php endforeach ?>
    </div>
  </div>
</div>
<?php endforeach ?>
</div>

<h2>Asocieri complete <span class="cnt"><?= count($grouped) ?> furnizori</span></h2>
<table>
  <thead><tr><th style="width:52%">Furnizor</th><th>Responsabili achiziții</th></tr></thead>
  <tbody>
  <?php foreach ($grouped as $supplier => $buyers): ?>
    <tr>
      <td><?= htmlspecialchars($supplier) ?></td>
      <td><div class="bl">
        <?php foreach ($buyers as $b): $cls = $badgeClass[$b] ?? ''; ?>
          <span class="badge <?= $cls ?>"><?= htmlspecialchars($b) ?></span>
        <?php endforeach ?>
      </div></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>

<h2>Fără asociere — marcați cu X în CSV (intenționat) <span class="cnt"><?= count($noAssocX) ?></span></h2>
<?php foreach ($noAssocX as $s) echo "<span class='tag tag-x'>" . htmlspecialchars($s) . "</span>"; ?>

<h2>Fără asociere — nu apar în CSV <span class="cnt"><?= count($noAssocMissing) ?></span></h2>
<?php foreach ($noAssocMissing as $s) echo "<span class='tag tag-m'>" . htmlspecialchars($s) . "</span>"; ?>

<h2>Găsiți în CSV dar inexistenți în baza de date <span class="cnt"><?= count($notFoundInDb) ?></span></h2>
<?php foreach ($notFoundInDb as $s) echo "<span class='tag tag-nf'>" . htmlspecialchars($s) . "</span>"; ?>

</body>
</html>
<?php
$html = ob_get_clean();
file_put_contents(__DIR__ . '/asocieri_rezultat.html', $html);
echo "Done. Categorii per buyer:\n";
foreach ($buyerData as $bd) {
    echo $bd['name'] . ': ' . count($bd['categories']) . " categorii\n";
}
