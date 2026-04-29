<?php
// ─── Config ────────────────────────────────────────────────────────────────────
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'erp_malinco';
const DB_USER = 'erp_user';
const DB_PASS = 'Typo1bmng!@!';
const API_TOKEN = '060155cb555b0465f1099f01da6a6798';
const BASE_URL  = 'https://erp.malinco.ro';

// ─── JSON API endpoints ─────────────────────────────────────────────────────────
if (isset($_GET['sync'])) {
    header('Content-Type: application/json');
    try {
        $db = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $since = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
        $rows = $db->prepare("
            SELECT p.id, p.status, u.name AS reviewer_name
            FROM toya_category_proposals p
            LEFT JOIN users u ON u.id = p.approved_by
            WHERE p.status != 'pending' AND p.updated_at >= ?
            ORDER BY p.updated_at DESC
        ");
        $rows->execute([$since]);
        $stats = $db->query("SELECT status, COUNT(*) as cnt, SUM(product_count) as prods FROM toya_category_proposals GROUP BY status")->fetchAll();
        $counts = ['pending'=>0,'approved'=>0,'rejected'=>0,'no_match'=>0];
        $products = ['pending'=>0,'approved'=>0,'rejected'=>0,'no_match'=>0];
        foreach ($stats as $s) {
            $counts[$s['status']] = (int)$s['cnt'];
            $products[$s['status']] = (int)$s['prods'];
        }
        echo json_encode(['processed'=>$rows->fetchAll(), 'counts'=>$counts, 'products'=>$products, 'ts'=>date('Y-m-d H:i:s')]);
    } catch(Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ─── DB ────────────────────────────────────────────────────────────────────────
$db = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

// ─── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->query("SELECT status, COUNT(*) as cnt, SUM(product_count) as prods FROM toya_category_proposals GROUP BY status")->fetchAll();
$counts = ['pending'=>0,'approved'=>0,'rejected'=>0,'no_match'=>0];
$products = ['pending'=>0,'approved'=>0,'rejected'=>0,'no_match'=>0];
foreach ($stats as $s) {
    $counts[$s['status']] = (int)$s['cnt'];
    $products[$s['status']] = (int)$s['prods'];
}
$totalProds = array_sum($products);
$doneProds  = $products['approved'] + $products['rejected'];
$pct        = $totalProds > 0 ? round($doneProds / $totalProds * 100) : 0;

// ─── Pending proposals grouped by confidence tier ──────────────────────────────
$pending = $db->query("
    SELECT p.id, p.toya_path, p.product_count, p.confidence, p.reasoning, p.proposed_woo_category_id,
           p.alternative_category_ids,
           wc.name AS woo_cat_name,
           pc.name AS woo_cat_parent
    FROM toya_category_proposals p
    LEFT JOIN woo_categories wc ON wc.id = p.proposed_woo_category_id
    LEFT JOIN woo_categories pc ON pc.id = wc.parent_id
    WHERE p.status = 'pending'
    ORDER BY p.confidence DESC, p.product_count DESC
")->fetchAll();

$high   = array_filter($pending, fn($r) => $r['confidence'] >= 0.85);
$medium = array_filter($pending, fn($r) => $r['confidence'] >= 0.65 && $r['confidence'] < 0.85);
$low    = array_filter($pending, fn($r) => $r['confidence'] < 0.65);

// ─── No-match grouped + recommendations ─────────────────────────────────────────
$noMatch = $db->query("
    SELECT p.id, p.toya_path, p.product_count, p.reasoning
    FROM toya_category_proposals p
    WHERE p.status = 'no_match'
    ORDER BY p.product_count DESC
")->fetchAll();

// My recommendations for each no_match root
$noMatchRec = [
    'Catering' => [
        'icon'    => '🍽️',
        'prods'   => 410,
        'paths'   => 82,
        'action'  => 'create_new',
        'new_cat' => 'Catering și HoReCa',
        'parent'  => 'Amenajare',
        'reason'  => 'Oale, cratițe, recipiente GN, termosuri, vitrine frigorifice, mixere planetare, ustensile bucătărie profesionale. Merită categorie proprie pentru echipamente HoReCa.',
        'subs'    => ['Ustensile bucătărie','Recipiente și containere GN','Termosuri și transport','Refrigerare catering','Bufet și servire'],
    ],
    'Unelte și echipamente pentru atelierul auto' => [
        'icon'    => '🔧',
        'prods'   => 307,
        'paths'   => 35,
        'action'  => 'create_new',
        'new_cat' => 'Unelte atelier auto',
        'parent'  => 'Scule și accesorii',
        'reason'  => 'Truse diagnosticare, extractoare rulmenți, dulapuri atelier, unelte frânare, cricuri și ascensoare, compresoare, scaune taburet. Categorie distinctă față de "Auto și întreținere utilaje" (care e pentru produse chimice auto).',
        'subs'    => ['Truse și dulapuri atelier','Extractoare și prese','Diagnosticare','Cricuri și dispozitive ridicare','Echipamente aer condiționat auto'],
    ],
    'Aparate de uz casnic' => [
        'icon'    => '🏠',
        'prods'   => 66,
        'paths'   => 28,
        'action'  => 'existing',
        'new_cat' => 'Uz casnic',
        'parent'  => 'Amenajare',
        'existing_id' => 245,
        'reason'  => 'Toastere, cazane, aspiratoare, mopuri abur, convectoare, ambalatoare vid, difuzoare parfum. Pot merge în "Amenajare > Uz casnic" (există) sau într-o nouă subcategorie "Aparate electrocasnice".',
        'subs'    => [],
    ],
    'Gospodărie' => [
        'icon'    => '🔥',
        'prods'   => 62,
        'paths'   => 7,
        'action'  => 'split',
        'new_cat' => 'Grătare și accesorii',
        'parent'  => 'Curte și grădină',
        'reason'  => 'Grătare pe cărbune (40 produse) → categorie nouă "Curte și grădină > Grătare și accesorii". Sigilii, aspiratoare cenușă → "Amenajare > Uz casnic".',
        'subs'    => [],
    ],
    'Unelte hidraulice' => [
        'icon'    => '⚙️',
        'prods'   => 11,
        'paths'   => 2,
        'action'  => 'existing',
        'new_cat' => 'Scule electrice',
        'parent'  => 'Scule și accesorii',
        'existing_id' => 101,
        'reason'  => 'Prese de expandat țevi, mașini de sudat țevi → "Scule și accesorii > Scule electrice" (există). Sunt scule specializate.',
        'subs'    => [],
    ],
    'Unelte electrice, unelte electrice și accesorii' => [
        'icon'    => '⚡',
        'prods'   => 11,
        'paths'   => 2,
        'action'  => 'create_new',
        'new_cat' => 'Generatoare și motoare',
        'parent'  => 'Scule și accesorii',
        'reason'  => 'Generatoare diesel, scule diesel specializate. Propun o subcategorie "Generatoare și motoare" sub Scule și accesorii.',
        'subs'    => [],
    ],
    'Seifuri, lacăte și încuietori' => [
        'icon'    => '🔒',
        'prods'   => 9,
        'paths'   => 2,
        'action'  => 'existing',
        'new_cat' => 'Uz casnic',
        'parent'  => 'Amenajare',
        'existing_id' => 245,
        'reason'  => 'Tăvi pentru bani (8 produse), etichete de agățat (1). Pot merge în "Amenajare > Uz casnic" sau "Feronerie" generic.',
        'subs'    => [],
    ],
    'Instalații sanitare și instalații sanitare' => [
        'icon'    => '🚽',
        'prods'   => 6,
        'paths'   => 1,
        'action'  => 'existing',
        'new_cat' => 'Accesorii pentru baie',
        'parent'  => 'Sanitare',
        'existing_id' => 271,
        'reason'  => 'Scaune de toaletă → "Sanitare > Accesorii pentru baie" (există).',
        'subs'    => [],
    ],
    'Unelte pneumatice' => [
        'icon'    => '💨',
        'prods'   => 6,
        'paths'   => 2,
        'action'  => 'existing',
        'new_cat' => 'Scule electrice',
        'parent'  => 'Scule și accesorii',
        'existing_id' => 101,
        'reason'  => 'Unelte și accesorii pentru sablare → "Scule și accesorii > Scule electrice" (cel mai aproape).',
        'subs'    => [],
    ],
    'Unelte de grădină' => [
        'icon'    => '🌿',
        'prods'   => 2,
        'paths'   => 1,
        'action'  => 'existing',
        'new_cat' => 'Articole pentru grădinărit',
        'parent'  => 'Curte și grădină',
        'existing_id' => 135,
        'reason'  => 'Grătare și accesorii → "Curte și grădină > Articole pentru grădinărit" sau noua "Grătare și accesorii".',
        'subs'    => [],
    ],
];

// ─── Recently approved ─────────────────────────────────────────────────────────
$approved = $db->query("
    SELECT p.id, p.toya_path, p.product_count, p.confidence,
           wc.name AS woo_cat_name, pc.name AS woo_cat_parent,
           u.name AS reviewer_name, p.approved_at
    FROM toya_category_proposals p
    LEFT JOIN woo_categories wc ON wc.id = p.proposed_woo_category_id
    LEFT JOIN woo_categories pc ON pc.id = wc.parent_id
    LEFT JOIN users u ON u.id = p.approved_by
    WHERE p.status IN ('approved','rejected')
    ORDER BY p.approved_at DESC
    LIMIT 100
")->fetchAll();

function hesc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function confBadge($c) {
    $pct = round($c * 100);
    if ($c >= 0.85) return "<span class='badge badge-high'>{$pct}%</span>";
    if ($c >= 0.65) return "<span class='badge badge-med'>{$pct}%</span>";
    return "<span class='badge badge-low'>{$pct}%</span>";
}
function pathShort($path) {
    $parts = explode(' / ', $path);
    return count($parts) > 2 ? implode(' › ', array_slice($parts, -2)) : $path;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Categorizare Toya — Malinco ERP</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f0e8; color: #1a1a1a; }
header { background: #c0392b; color: white; padding: 18px 32px; display: flex; justify-content: space-between; align-items: center; }
header h1 { font-size: 21px; font-weight: 700; }
header p  { font-size: 13px; opacity: .8; margin-top: 3px; }
.progress-wrap { background: #1a1a1a; padding: 14px 32px; display: flex; gap: 32px; align-items: center; }
.progress-bar-bg   { flex: 1; background: rgba(255,255,255,.15); border-radius: 20px; height: 10px; overflow: hidden; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg, #16a34a, #22c55e); border-radius: 20px; transition: width .4s; width: <?= $pct ?>%; }
.prog-stat { color: white; font-size: 13px; white-space: nowrap; }
.prog-stat strong { font-size: 17px; font-weight: 700; display: block; }
.prog-stat span { font-size: 11px; opacity: .7; }
.prog-stat.green strong { color: #4ade80; }
.prog-stat.red strong { color: #f87171; }
.prog-stat.yellow strong { color: #fbbf24; }
.prog-stat.blue strong { color: #60a5fa; }

/* Tabs */
.tabs { background: white; border-bottom: 2px solid #e8ddd4; padding: 0 32px; display: flex; position: sticky; top: 0; z-index: 20; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
.tab { padding: 14px 22px; font-size: 14px; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; color: #888; transition: all .15s; user-select: none; }
.tab:hover { color: #333; }
.tab.active { color: #c0392b; border-bottom-color: #c0392b; }
.tab .badge { border-radius: 20px; padding: 1px 8px; font-size: 11px; margin-left: 6px; background: #c0392b; color: white; }
.tab .badge.green { background: #16a34a; }
.tab .badge.blue  { background: #2563eb; }
.tab .badge.gray  { background: #888; }
.tab-content { display: none; }
.tab-content.active { display: block; }

/* Sections */
.section-head { background: #2c2c2c; color: white; padding: 10px 32px; display: flex; gap: 20px; align-items: center; }
.section-head h2 { font-size: 14px; font-weight: 700; }
.section-head .meta { font-size: 12px; opacity: .7; }
.section-head .conf-high { color: #4ade80; }
.section-head .conf-med  { color: #fbbf24; }
.section-head .conf-low  { color: #f87171; }
.container { padding: 16px 32px 48px; }

/* Table */
table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); font-size: 13px; margin-bottom: 24px; }
thead tr { background: #2c2c2c; color: white; }
thead th { padding: 9px 12px; text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; white-space: nowrap; }
tbody tr { border-bottom: 1px solid #f0ebe3; transition: background .1s; }
tbody tr:hover { background: #fffaf8; }
tbody tr:last-child { border-bottom: none; }
td { padding: 8px 12px; vertical-align: top; }
.col-path { max-width: 340px; }
.col-path .full { font-size: 11px; color: #bbb; margin-top: 2px; }
.col-path strong { font-size: 13px; color: #1a1a1a; }
.col-cat { color: #2563eb; font-weight: 500; }
.col-cat .parent { font-size: 11px; color: #888; display: block; }
.col-cnt { text-align: center; font-weight: 700; font-size: 14px; color: #1a1a1a; }
.col-reason { font-size: 12px; color: #666; max-width: 280px; }
.col-actions { white-space: nowrap; }
.btn { border: none; border-radius: 5px; padding: 5px 12px; font-size: 12px; font-weight: 600; cursor: pointer; transition: opacity .15s; }
.btn:disabled { opacity: .45; cursor: not-allowed; }
.btn-approve { background: #16a34a; color: white; margin-right: 4px; }
.btn-approve:hover:not(:disabled) { background: #15803d; }
.btn-reject  { background: #dc2626; color: white; }
.btn-reject:hover:not(:disabled)  { background: #b91c1c; }

/* Badges */
.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; }
.badge-high { background: #dcfce7; color: #166534; }
.badge-med  { background: #fef9c3; color: #854d0e; }
.badge-low  { background: #fee2e2; color: #991b1b; }
.badge-approved { background: #dcfce7; color: #166534; }
.badge-rejected  { background: #fee2e2; color: #991b1b; }

/* Recommendations section */
.rec-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 16px; margin-bottom: 24px; }
.rec-card { background: white; border-radius: 10px; padding: 18px; box-shadow: 0 1px 4px rgba(0,0,0,.08); border-left: 4px solid #c0392b; }
.rec-card.create { border-left-color: #2563eb; }
.rec-card.existing { border-left-color: #16a34a; }
.rec-card.split { border-left-color: #d97706; }
.rec-header { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 10px; }
.rec-icon { font-size: 28px; line-height: 1; }
.rec-title { font-size: 15px; font-weight: 700; color: #1a1a1a; }
.rec-meta { font-size: 12px; color: #888; margin-top: 2px; }
.rec-body { font-size: 13px; color: #555; line-height: 1.5; margin-bottom: 12px; }
.rec-action { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 6px; }
.rec-action.create  { background: #dbeafe; color: #1d4ed8; }
.rec-action.existing{ background: #dcfce7; color: #166534; }
.rec-action.split   { background: #fef3c7; color: #92400e; }
.rec-subs { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 4px; }
.rec-sub { background: #f1f5f9; color: #475569; font-size: 11px; padding: 2px 8px; border-radius: 10px; }

/* Sync dot */
.sync-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; display: inline-block; margin-right: 6px; animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* Toolbar */
.toolbar { background: white; border-bottom: 1px solid #e8ddd4; padding: 10px 32px; display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
.toolbar input[type=text], .toolbar select { border: 1px solid #ddd; border-radius: 6px; padding: 7px 10px; font-size: 13px; background: #fafafa; min-width: 180px; }
.toolbar input:focus, .toolbar select:focus { outline: none; border-color: #c0392b; }
.toolbar label { font-size: 11px; font-weight: 600; color: #888; text-transform: uppercase; margin-right: 4px; }
.btn-reset { background: white; color: #888; border: 1px solid #ddd; border-radius: 6px; padding: 7px 14px; font-size: 13px; cursor: pointer; }
.vis-count { font-size: 12px; color: #888; }

/* Toast */
.toast { position: fixed; bottom: 28px; right: 28px; background: #1e293b; color: white; padding: 12px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; opacity: 0; transition: opacity .25s; z-index: 999; pointer-events: none; }
.toast.show { opacity: 1; }
.toast.success { background: #16a34a; }
.toast.error   { background: #dc2626; }

.empty-msg { text-align: center; padding: 48px; color: #bbb; font-size: 16px; }
.done-row td { opacity: .45; text-decoration: line-through; }
</style>
</head>
<body>

<header>
  <div>
    <h1>🔧 Categorizare Produse Toya</h1>
    <p>Analiză completă — asocieri propuse și categorii noi necesare</p>
  </div>
  <div style="text-align:right; font-size:13px; color:rgba(255,255,255,.8);">
    <span class="sync-dot" id="syncDot"></span><span id="syncStatus">Live</span>
  </div>
</header>

<div class="progress-wrap">
  <div class="prog-stat">
    <strong id="statPending"><?= $counts['pending'] ?></strong>
    <span>Căi pending</span>
  </div>
  <div class="prog-stat yellow">
    <strong id="statPendingProds"><?= number_format($products['pending']) ?></strong>
    <span>Produse de asociat</span>
  </div>
  <div class="progress-bar-bg">
    <div class="progress-bar-fill" id="progressFill"></div>
  </div>
  <div class="prog-stat green">
    <strong id="statApproved"><?= $counts['approved'] ?></strong>
    <span>Aprobate (<?= number_format($products['approved']) ?> prod.)</span>
  </div>
  <div class="prog-stat red">
    <strong id="statNoMatch"><?= $counts['no_match'] ?></strong>
    <span>Fără categorie (<?= number_format($products['no_match']) ?> prod.)</span>
  </div>
  <div class="prog-stat blue">
    <strong><?= $pct ?>%</strong>
    <span>Procesat</span>
  </div>
</div>

<div class="tabs">
  <div class="tab active" onclick="switchTab('high',this)">
    Înaltă încredere ≥85%
    <span class="badge green"><?= count($high) ?> căi · <?= number_format(array_sum(array_column($high,'product_count'))) ?> prod.</span>
  </div>
  <div class="tab" onclick="switchTab('medium',this)">
    Medie 65–84%
    <span class="badge"><?= count($medium) ?> căi · <?= number_format(array_sum(array_column($medium,'product_count'))) ?> prod.</span>
  </div>
  <div class="tab" onclick="switchTab('low',this)">
    Scăzută &lt;65%
    <span class="badge gray"><?= count($low) ?> căi · <?= number_format(array_sum(array_column($low,'product_count'))) ?> prod.</span>
  </div>
  <div class="tab" onclick="switchTab('nomatch',this)">
    Fără asociere
    <span class="badge" style="background:#d97706"><?= $counts['no_match'] ?> căi · <?= number_format($products['no_match']) ?> prod.</span>
  </div>
  <div class="tab" onclick="switchTab('processed',this)">
    Procesate
    <span class="badge green"><?= $counts['approved'] + $counts['rejected'] ?></span>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: HIGH CONFIDENCE -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content active" id="tab-high">
  <div class="section-head">
    <h2 class="conf-high">✓ Înaltă încredere — pot fi aprobate rapid</h2>
    <span class="meta">Asocieri cu confidence ≥85% — mapare clară la categorie WooCommerce existentă</span>
  </div>
  <div class="toolbar">
    <div><label>Caută</label><input type="text" id="searchHigh" oninput="filterTable('high')" placeholder="cale Toya sau categorie..."></div>
    <button class="btn-reset" onclick="document.getElementById('searchHigh').value='';filterTable('high')">Resetează</button>
    <span class="vis-count" id="visHigh"><?= count($high) ?> vizibile</span>
  </div>
  <div class="container">
    <?php if(empty($high)): ?>
      <div class="empty-msg">🎉 Toate propunerile high confidence au fost procesate!</div>
    <?php else: ?>
    <table id="tblHigh">
      <thead><tr>
        <th>Cale Toya</th>
        <th style="text-align:center">Produse</th>
        <th>→ Categorie Woo</th>
        <th>Conf.</th>
        <th>Motiv</th>
        <th>Acțiune</th>
      </tr></thead>
      <tbody id="bodyHigh">
      <?php foreach($high as $r): ?>
      <tr id="trow-<?= $r['id'] ?>" data-id="<?= $r['id'] ?>" data-search="<?= hesc(strtolower($r['toya_path'].' '.($r['woo_cat_name']??''))) ?>">
        <td class="col-path">
          <strong><?= hesc(pathShort($r['toya_path'])) ?></strong>
          <div class="full"><?= hesc($r['toya_path']) ?></div>
        </td>
        <td class="col-cnt"><?= $r['product_count'] ?></td>
        <td class="col-cat">
          <?= hesc($r['woo_cat_name'] ?? '—') ?>
          <?php if($r['woo_cat_parent']): ?><span class="parent"><?= hesc($r['woo_cat_parent']) ?></span><?php endif; ?>
        </td>
        <td><?= confBadge($r['confidence']) ?></td>
        <td class="col-reason"><?= hesc(substr($r['reasoning']??'',0,120)) ?></td>
        <td class="col-actions">
          <button class="btn btn-approve" onclick="review(<?= $r['id'] ?>,'approve',this)">✓ Aprobă</button>
          <button class="btn btn-reject"  onclick="review(<?= $r['id'] ?>,'reject',this)">✗</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: MEDIUM CONFIDENCE -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-medium">
  <div class="section-head">
    <h2 class="conf-med">⚠ Medie încredere — verificare recomandată</h2>
    <span class="meta">65–84% confidence — asociere plauzibilă dar merită confirmat</span>
  </div>
  <div class="toolbar">
    <div><label>Caută</label><input type="text" id="searchMed" oninput="filterTable('medium')" placeholder="cale Toya sau categorie..."></div>
    <button class="btn-reset" onclick="document.getElementById('searchMed').value='';filterTable('medium')">Resetează</button>
    <span class="vis-count" id="visMed"><?= count($medium) ?> vizibile</span>
  </div>
  <div class="container">
    <?php if(empty($medium)): ?>
      <div class="empty-msg">✓ Toate propunerile medium au fost procesate!</div>
    <?php else: ?>
    <table id="tblMed">
      <thead><tr>
        <th>Cale Toya</th>
        <th style="text-align:center">Produse</th>
        <th>→ Categorie Woo</th>
        <th>Conf.</th>
        <th>Motiv</th>
        <th>Acțiune</th>
      </tr></thead>
      <tbody id="bodyMed">
      <?php foreach($medium as $r): ?>
      <tr id="trow-<?= $r['id'] ?>" data-id="<?= $r['id'] ?>" data-search="<?= hesc(strtolower($r['toya_path'].' '.($r['woo_cat_name']??''))) ?>">
        <td class="col-path">
          <strong><?= hesc(pathShort($r['toya_path'])) ?></strong>
          <div class="full"><?= hesc($r['toya_path']) ?></div>
        </td>
        <td class="col-cnt"><?= $r['product_count'] ?></td>
        <td class="col-cat">
          <?= hesc($r['woo_cat_name'] ?? '—') ?>
          <?php if($r['woo_cat_parent']): ?><span class="parent"><?= hesc($r['woo_cat_parent']) ?></span><?php endif; ?>
        </td>
        <td><?= confBadge($r['confidence']) ?></td>
        <td class="col-reason"><?= hesc(substr($r['reasoning']??'',0,120)) ?></td>
        <td class="col-actions">
          <button class="btn btn-approve" onclick="review(<?= $r['id'] ?>,'approve',this)">✓ Aprobă</button>
          <button class="btn btn-reject"  onclick="review(<?= $r['id'] ?>,'reject',this)">✗</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: LOW CONFIDENCE -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-low">
  <div class="section-head">
    <h2 class="conf-low">✗ Scăzută — necesită decizie manuală</h2>
    <span class="meta">&lt;65% confidence — categorie propusă nesigură; unele necesită categorii noi</span>
  </div>
  <div class="toolbar">
    <div><label>Caută</label><input type="text" id="searchLow" oninput="filterTable('low')" placeholder="cale Toya sau categorie..."></div>
    <button class="btn-reset" onclick="document.getElementById('searchLow').value='';filterTable('low')">Resetează</button>
    <span class="vis-count" id="visLow"><?= count($low) ?> vizibile</span>
  </div>
  <div class="container">
    <?php if(empty($low)): ?>
      <div class="empty-msg">✓ Toate propunerile low au fost procesate!</div>
    <?php else: ?>
    <table id="tblLow">
      <thead><tr>
        <th>Cale Toya</th>
        <th style="text-align:center">Produse</th>
        <th>→ Categorie Woo propusă</th>
        <th>Conf.</th>
        <th>Motiv / Problemă</th>
        <th>Acțiune</th>
      </tr></thead>
      <tbody id="bodyLow">
      <?php foreach($low as $r): ?>
      <tr id="trow-<?= $r['id'] ?>" data-id="<?= $r['id'] ?>" data-search="<?= hesc(strtolower($r['toya_path'].' '.($r['woo_cat_name']??''))) ?>">
        <td class="col-path">
          <strong><?= hesc(pathShort($r['toya_path'])) ?></strong>
          <div class="full"><?= hesc($r['toya_path']) ?></div>
        </td>
        <td class="col-cnt"><?= $r['product_count'] ?></td>
        <td class="col-cat">
          <?= hesc($r['woo_cat_name'] ?? '—') ?>
          <?php if($r['woo_cat_parent']): ?><span class="parent"><?= hesc($r['woo_cat_parent']) ?></span><?php endif; ?>
        </td>
        <td><?= confBadge($r['confidence']) ?></td>
        <td class="col-reason"><?= hesc(substr($r['reasoning']??'',0,120)) ?></td>
        <td class="col-actions">
          <button class="btn btn-approve" onclick="review(<?= $r['id'] ?>,'approve',this)">✓ Aprobă</button>
          <button class="btn btn-reject"  onclick="review(<?= $r['id'] ?>,'reject',this)">✗</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: NO MATCH — PROPUNERI CATEGORII NOI -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-nomatch">
  <div class="section-head">
    <h2 style="color:#fbbf24">⬡ Fără asociere — propuneri categorii noi</h2>
    <span class="meta"><?= $counts['no_match'] ?> căi Toya, <?= number_format($products['no_match']) ?> produse — nicio categorie WooCommerce potrivită</span>
  </div>
  <div class="container">

    <p style="font-size:14px; color:#555; margin-bottom:20px; line-height:1.6;">
      Aceste produse Toya nu au putut fi asociate automat la nicio categorie existentă. Am analizat grupurile și propun mai jos soluții concrete — fie asocierea la categorii existente, fie crearea de subcategorii noi.
    </p>

    <div class="rec-grid">
    <?php
    $rootProds = [];
    foreach ($noMatch as $r) {
        $root = explode(' / ', $r['toya_path'], 2)[0];
        if (!isset($rootProds[$root])) $rootProds[$root] = ['prods'=>0,'paths'=>0,'items'=>[]];
        $rootProds[$root]['prods'] += $r['product_count'];
        $rootProds[$root]['paths']++;
        $rootProds[$root]['items'][] = $r;
    }
    arsort($rootProds);

    foreach ($rootProds as $root => $data):
        $rec = $noMatchRec[$root] ?? null;
        if (!$rec || $data['prods'] == 0) continue;
        $actionClass = $rec['action'] === 'create_new' ? 'create' : ($rec['action'] === 'split' ? 'split' : 'existing');
        $actionLabel = $rec['action'] === 'create_new' ? 'Creează categorie nouă' : ($rec['action'] === 'split' ? 'Split — categorii diferite' : 'Categorie existentă');
    ?>
    <div class="rec-card <?= $actionClass ?>">
      <div class="rec-header">
        <div class="rec-icon"><?= $rec['icon'] ?></div>
        <div>
          <div class="rec-title"><?= hesc($root) ?></div>
          <div class="rec-meta"><?= $data['prods'] ?> produse · <?= $data['paths'] ?> căi Toya</div>
        </div>
      </div>
      <div class="rec-body"><?= hesc($rec['reason']) ?></div>
      <div>
        <span class="rec-action <?= $actionClass ?>">
          <?php if($rec['action']==='create_new'): ?>
            📁 <?= hesc($rec['parent']) ?> → <strong><?= hesc($rec['new_cat']) ?></strong>
          <?php elseif($rec['action']==='split'): ?>
            ⚡ <?= hesc($rec['parent']) ?> → <strong><?= hesc($rec['new_cat']) ?></strong>
          <?php else: ?>
            ✓ Există: <?= hesc($rec['parent']) ?> → <strong><?= hesc($rec['new_cat']) ?></strong>
          <?php endif; ?>
        </span>
      </div>
      <?php if(!empty($rec['subs'])): ?>
      <div class="rec-subs">
        <?php foreach($rec['subs'] as $sub): ?>
        <span class="rec-sub"><?= hesc($sub) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Detaliu căi -->
      <details style="margin-top:12px;">
        <summary style="font-size:12px; color:#888; cursor:pointer;">Arată căile Toya (<?= $data['paths'] ?>)</summary>
        <div style="margin-top:8px; max-height:200px; overflow-y:auto;">
        <?php foreach($data['items'] as $item): ?>
          <div style="font-size:11px; color:#555; padding:3px 0; border-bottom:1px solid #f0ebe3;">
            <strong><?= $item['product_count'] ?> prod.</strong> — <?= hesc(pathShort($item['toya_path'])) ?>
          </div>
        <?php endforeach; ?>
        </div>
      </details>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Produse închise / speciale -->
    <?php
    $special = ['Produse închise (321 produse)','Numai produse YTC (290 produse)','Produse noi (7 produse)'];
    $specRows = array_filter($noMatch, fn($r) => in_array($r['toya_path'], $special));
    if (!empty($specRows)): ?>
    <div style="background:white; border-radius:10px; padding:16px; box-shadow:0 1px 4px rgba(0,0,0,.08); margin-top:8px;">
      <h3 style="font-size:14px; font-weight:700; margin-bottom:10px; color:#888;">⚠ Căi speciale Toya (fără produse reale)</h3>
      <?php foreach($specRows as $r): ?>
      <div style="font-size:13px; color:#555; padding:4px 0;"><?= hesc($r['toya_path']) ?></div>
      <?php endforeach; ?>
      <p style="font-size:12px; color:#bbb; margin-top:8px;">Acestea sunt marcaje interne Toya și nu conțin produse care necesită acțiune.</p>
    </div>
    <?php endif; ?>

    <!-- Tabel complet no_match -->
    <h3 style="font-size:14px; font-weight:700; color:#1a1a1a; margin:24px 0 10px;">Lista completă căi fără asociere</h3>
    <div class="toolbar" style="background:transparent; padding:0; margin-bottom:12px;">
      <div><input type="text" id="searchNoMatch" oninput="filterNoMatch()" placeholder="Caută în căile Toya..." style="min-width:280px;"></div>
    </div>
    <table>
      <thead><tr>
        <th>Cale Toya</th>
        <th style="text-align:center">Produse</th>
        <th>Recomandare</th>
        <th>Tip acțiune</th>
      </tr></thead>
      <tbody id="bodyNoMatch">
      <?php foreach($noMatch as $r):
        $root = explode(' / ', $r['toya_path'], 2)[0];
        $rec = $noMatchRec[$root] ?? null;
        if ($r['product_count'] == 0) continue;
      ?>
      <tr data-search="<?= hesc(strtolower($r['toya_path'])) ?>">
        <td class="col-path">
          <strong><?= hesc(pathShort($r['toya_path'])) ?></strong>
          <div class="full"><?= hesc($r['toya_path']) ?></div>
        </td>
        <td class="col-cnt"><?= $r['product_count'] ?></td>
        <td class="col-cat">
          <?php if($rec): ?>
            <?= hesc($rec['parent']) ?> → <strong><?= hesc($rec['new_cat']) ?></strong>
          <?php else: ?><span style="color:#bbb">—</span><?php endif; ?>
        </td>
        <td>
          <?php if($rec):
            if($rec['action']==='create_new') echo '<span class="rec-action create" style="font-size:11px">📁 Categorie nouă</span>';
            elseif($rec['action']==='split')   echo '<span class="rec-action split" style="font-size:11px">⚡ Split</span>';
            else echo '<span class="rec-action existing" style="font-size:11px">✓ Existentă</span>';
          endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB: PROCESATE -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-processed">
  <div class="section-head">
    <h2 class="conf-high">✓ Procesate</h2>
    <span class="meta"><?= count($approved) ?> înregistrări (ultimele 100)</span>
  </div>
  <div class="container">
    <?php if(empty($approved)): ?>
      <div class="empty-msg">Nicio propunere procesată încă.</div>
    <?php else: ?>
    <table>
      <thead><tr>
        <th>Cale Toya</th>
        <th style="text-align:center">Produse</th>
        <th>→ Categorie Woo</th>
        <th>Conf.</th>
        <th>Status</th>
        <th>Revizuit de</th>
        <th>Data</th>
      </tr></thead>
      <tbody>
      <?php foreach($approved as $r): ?>
      <tr>
        <td class="col-path">
          <strong><?= hesc(pathShort($r['toya_path'])) ?></strong>
          <div class="full"><?= hesc($r['toya_path']) ?></div>
        </td>
        <td class="col-cnt"><?= $r['product_count'] ?></td>
        <td class="col-cat">
          <?= hesc($r['woo_cat_name'] ?? '—') ?>
          <?php if($r['woo_cat_parent']): ?><span class="parent"><?= hesc($r['woo_cat_parent']) ?></span><?php endif; ?>
        </td>
        <td><?= confBadge($r['confidence']) ?></td>
        <td>
          <?php if($r['status']==='approved'): ?>
            <span class="badge badge-approved">✓ Aprobat</span>
          <?php else: ?>
            <span class="badge badge-rejected">✗ Respins</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px"><?= hesc($r['reviewer_name'] ?? '—') ?></td>
        <td class="col-date" style="font-size:11px; color:#888"><?= $r['approved_at'] ? date('d.m.Y H:i', strtotime($r['approved_at'])) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const BASE  = '<?= BASE_URL ?>';
const TOKEN = '<?= API_TOKEN ?>';

// ── Tabs ─────────────────────────────────────────────────────────────────────
function switchTab(name, el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show' + (type ? ' ' + type : '');
  clearTimeout(t._t);
  t._t = setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Review (approve / reject) ─────────────────────────────────────────────────
async function review(id, action, btn) {
  const row = document.getElementById('trow-' + id);
  const btns = row ? row.querySelectorAll('button') : [btn];
  btns.forEach(b => b.disabled = true);

  try {
    const res = await fetch(BASE + '/api/toya-category/' + id + '/' + action, {
      method: 'POST',
      headers: { 'X-Review-Token': TOKEN, 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]?.replace(/%3D/g,'=') || '' }
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      showToast(res.status === 409 ? 'Deja procesat.' : 'Eroare: ' + (err.error || res.status), 'error');
      btns.forEach(b => b.disabled = false);
      return;
    }
    if (row) { row.classList.add('done-row'); setTimeout(() => row.remove(), 800); }
    showToast(action === 'approve' ? '✓ Aprobat!' : '✗ Respins.', action === 'approve' ? 'success' : '');
    updateCount(action);
  } catch(e) {
    showToast('Eroare de rețea', 'error');
    btns.forEach(b => b.disabled = false);
  }
}

let approvedCount = <?= $counts['approved'] ?>;
let rejectedCount = <?= $counts['rejected'] ?>;
let pendingCount  = <?= $counts['pending'] ?>;
const totalPaths  = <?= array_sum($counts) ?>;

function updateCount(action) {
  pendingCount--;
  if (action === 'approve') approvedCount++; else rejectedCount++;
  document.getElementById('statPending').textContent  = pendingCount;
  document.getElementById('statApproved').textContent = approvedCount;
  const pct = totalPaths > 0 ? Math.round((approvedCount + rejectedCount) / totalPaths * 100) : 0;
  document.getElementById('progressFill').style.width = pct + '%';
}

// ── Filter tables ─────────────────────────────────────────────────────────────
function filterTable(tier) {
  const ids   = {high:'searchHigh',medium:'searchMed',low:'searchLow'};
  const bodyId= {high:'bodyHigh',medium:'bodyMed',low:'bodyLow'};
  const visId = {high:'visHigh',medium:'visMed',low:'visLow'};
  const q = document.getElementById(ids[tier]).value.toLowerCase();
  let cnt = 0;
  document.querySelectorAll('#' + bodyId[tier] + ' tr').forEach(row => {
    const show = !q || row.dataset.search.includes(q);
    row.style.display = show ? '' : 'none';
    if (show) cnt++;
  });
  document.getElementById(visId[tier]).textContent = cnt + ' vizibile';
}

function filterNoMatch() {
  const q = document.getElementById('searchNoMatch').value.toLowerCase();
  document.querySelectorAll('#bodyNoMatch tr').forEach(row => {
    row.style.display = !q || row.dataset.search.includes(q) ? '' : 'none';
  });
}

// ── Live sync ─────────────────────────────────────────────────────────────────
let lastSyncTs = new Date(Date.now() - 30000).toISOString().slice(0,19).replace('T',' ');

async function syncWithOthers() {
  try {
    const res = await fetch(BASE + '/toya-categorizare.php?sync=1&since=' + encodeURIComponent(lastSyncTs));
    if (!res.ok) return;
    const data = await res.json();
    if (data.error) return;
    lastSyncTs = data.ts || lastSyncTs;
    for (const item of (data.processed || [])) {
      const row = document.getElementById('trow-' + item.id);
      if (row) { row.classList.add('done-row'); setTimeout(() => row.remove(), 800); }
    }
    if (data.counts) {
      document.getElementById('statPending').textContent  = data.counts.pending;
      document.getElementById('statApproved').textContent = data.counts.approved;
      const total = Object.values(data.counts).reduce((a,b)=>a+b,0);
      const done  = (data.counts.approved||0) + (data.counts.rejected||0);
      document.getElementById('progressFill').style.width = (total>0?Math.round(done/total*100):0) + '%';
    }
  } catch(e) {}
}
setInterval(syncWithOthers, 15000);
</script>
</body>
</html>
