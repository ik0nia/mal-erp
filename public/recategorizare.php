<?php
// ─── Config ──────────────────────────────────────────────────────────────────
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'erp_malinco';
const DB_USER = 'erp_user';
const DB_PASS = 'Typo1bmng!@!';
const API_TOKEN = '060155cb555b0465f1099f01da6a6798';
const BASE_URL  = 'https://erp.malinco.ro';

// ─── JSON sync endpoint ───────────────────────────────────────────────────────
// ?sync=1 returnează ID-urile procesate de alți utilizatori de la un timestamp
if (isset($_GET['sync'])) {
    header('Content-Type: application/json');
    try {
        $db = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $since = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-1 minute'));
        $rows = $db->prepare("SELECT p.id, p.status, u.name AS reviewer_name FROM category_review_proposals p LEFT JOIN users u ON u.id = p.reviewed_by WHERE p.status != 'pending' AND p.reviewed_at >= ? ORDER BY p.reviewed_at DESC");
        $rows->execute([$since]);
        $stats = $db->query("SELECT status, COUNT(*) as cnt FROM category_review_proposals GROUP BY status")->fetchAll();
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($stats as $s) $counts[$s['status']] = (int)$s['cnt'];
        echo json_encode(['processed' => $rows->fetchAll(), 'counts' => $counts, 'ts' => date('Y-m-d H:i:s')]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── DB ──────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    die('DB error: ' . $e->getMessage());
}

// ─── Stats ───────────────────────────────────────────────────────────────────
$stats = $pdo->query("SELECT status, COUNT(*) as cnt FROM category_review_proposals GROUP BY status")->fetchAll();
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($stats as $s) $counts[$s['status']] = (int)$s['cnt'];
$total = array_sum($counts);

// ─── Pending proposals ───────────────────────────────────────────────────────
$pending = $pdo->query("
    SELECT id, woo_product_id, product_name, current_cats, suggested_cat_name, reason
    FROM category_review_proposals
    WHERE status = 'pending'
    ORDER BY current_cats, suggested_cat_name, product_name
")->fetchAll();

// ─── Processed proposals ─────────────────────────────────────────────────────
$processed = $pdo->query("
    SELECT p.id, p.woo_product_id, p.product_name, p.current_cats, p.suggested_cat_name, p.reason, p.status, p.reviewed_at, u.name AS reviewer_name
    FROM category_review_proposals p
    LEFT JOIN users u ON u.id = p.reviewed_by
    WHERE p.status IN ('approved','rejected')
    ORDER BY p.reviewed_at DESC
")->fetchAll();

// ─── Build filter options ─────────────────────────────────────────────────────
$fromCats = array_unique(array_column($pending, 'current_cats'));
sort($fromCats);
$toCats = array_unique(array_column($pending, 'suggested_cat_name'));
sort($toCats);

function opt(array $list, array $all): string {
    $html = '';
    foreach ($list as $v) {
        $cnt = count(array_filter($all, fn($p) => $p['current_cats'] === $v || $p['suggested_cat_name'] === $v));
        $html .= '<option value="'.htmlspecialchars($v).'">'.htmlspecialchars($v).'</option>';
    }
    return $html;
}

function fromOpts(array $pending): string {
    $cats = array_unique(array_column($pending, 'current_cats'));
    sort($cats);
    $html = '';
    foreach ($cats as $v) {
        $cnt = count(array_filter($pending, fn($p) => $p['current_cats'] === $v));
        $html .= '<option value="'.htmlspecialchars($v).'">'.htmlspecialchars($v).' ('.$cnt.')</option>';
    }
    return $html;
}

function toOpts(array $pending): string {
    $cats = array_unique(array_column($pending, 'suggested_cat_name'));
    sort($cats);
    $html = '';
    foreach ($cats as $v) {
        $cnt = count(array_filter($pending, fn($p) => $p['suggested_cat_name'] === $v));
        $html .= '<option value="'.htmlspecialchars($v).'">'.htmlspecialchars($v).' ('.$cnt.')</option>';
    }
    return $html;
}

$pct = $total > 0 ? round(($counts['approved'] + $counts['rejected']) / $total * 100) : 0;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recategorizare Produse — Malinco ERP</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f0e8; color: #1a1a1a; }
header { background: #c0392b; color: white; padding: 18px 32px; }
header h1 { font-size: 21px; font-weight: 700; }
header p { font-size: 13px; opacity: .8; margin-top: 3px; }

/* Progress bar */
.progress-wrap { background: #1a1a1a; padding: 14px 32px; display: flex; gap: 32px; align-items: center; }
.progress-bar-bg { flex: 1; background: rgba(255,255,255,.15); border-radius: 20px; height: 10px; overflow: hidden; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg, #16a34a, #22c55e); border-radius: 20px; transition: width .4s ease; width: <?= $pct ?>%; }
.prog-stat { color: white; font-size: 13px; white-space: nowrap; }
.prog-stat strong { font-size: 17px; font-weight: 700; }
.prog-stat span { font-size: 11px; opacity: .7; display: block; }
.prog-stat.green strong { color: #4ade80; }
.prog-stat.red strong { color: #f87171; }
.prog-stat.gray strong { color: #fbbf24; }

/* Tabs */
.tabs { background: white; border-bottom: 2px solid #e8ddd4; padding: 0 32px; display: flex; gap: 0; position: sticky; top: 0; z-index: 20; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
.tab { padding: 14px 22px; font-size: 14px; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; color: #888; transition: all .15s; user-select: none; }
.tab:hover { color: #333; }
.tab.active { color: #c0392b; border-bottom-color: #c0392b; }
.tab .badge { background: #c0392b; color: white; border-radius: 20px; padding: 1px 8px; font-size: 11px; margin-left: 6px; }
.tab.active .badge { background: #c0392b; }
.tab .badge.green { background: #16a34a; }
.tab .badge.orange { background: #ea580c; }

/* Toolbar */
.toolbar { background: white; border-bottom: 1px solid #e8ddd4; padding: 12px 32px; display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.toolbar label { font-size: 11px; font-weight: 600; color: #888; display: block; margin-bottom: 3px; text-transform: uppercase; letter-spacing: .4px; }
.toolbar select, .toolbar input[type=text] { border: 1px solid #ddd; border-radius: 6px; padding: 7px 10px; font-size: 13px; background: #fafafa; min-width: 190px; }
.toolbar select:focus, .toolbar input:focus { outline: none; border-color: #c0392b; }
.btn-reset { background: white; color: #888; border: 1px solid #ddd; border-radius: 6px; padding: 7px 14px; font-size: 13px; cursor: pointer; }
.btn-reset:hover { border-color: #c0392b; color: #c0392b; }

/* Bulk bar */

/* Table */
.tab-content { display: none; }
.tab-content.active { display: block; }
.container { padding: 20px 32px 48px; }
table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); font-size: 13px; }
thead tr { background: #2c2c2c; color: white; }
thead th { padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; white-space: nowrap; }
tbody tr { border-bottom: 1px solid #f0ebe3; transition: background .1s; }
tbody tr:hover { background: #fffaf8; }
tbody tr:last-child { border-bottom: none; }
td { padding: 8px 12px; vertical-align: top; }
.col-id { color: #bbb; font-size: 11px; width: 55px; }
.col-name a { font-weight: 500; color: #1a1a1a; text-decoration: none; }
.col-name a:hover { color: #c0392b; text-decoration: underline; }
.col-from { color: #999; font-size: 12px; max-width: 190px; }
.col-arrow { color: #ddd; width: 18px; font-size: 11px; }
.col-to { color: #15803d; font-weight: 600; font-size: 12px; max-width: 190px; }
.col-reason { color: #666; font-size: 11px; max-width: 240px; line-height: 1.45; }
.col-actions { white-space: nowrap; width: 155px; }
.btn-approve, .btn-reject { border: none; border-radius: 5px; padding: 5px 10px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .13s; }
.btn-approve { background: #dcfce7; color: #166534; margin-right: 4px; }
.btn-approve:hover { background: #16a34a; color: white; }
.btn-reject { background: #fee2e2; color: #991b1b; }
.btn-reject:hover { background: #dc2626; color: white; }
.btn-approve:disabled, .btn-reject:disabled { opacity: .35; cursor: not-allowed; }
.hidden { display: none !important; }
.empty-msg { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }

/* Processed table */
.col-status-approved { color: #16a34a; font-weight: 600; font-size: 12px; }
.col-status-rejected { color: #dc2626; font-weight: 600; font-size: 12px; }
.col-date { color: #aaa; font-size: 11px; white-space: nowrap; }

/* Toast */
.toast { position: fixed; bottom: 24px; right: 24px; background: #1e293b; color: white; padding: 12px 20px; border-radius: 8px; font-size: 13px; font-weight: 500; z-index: 9999; opacity: 0; transition: opacity .3s; pointer-events: none; max-width: 340px; }
.toast.show { opacity: 1; }
.toast.success { background: #16a34a; }
.toast.error { background: #dc2626; }
</style>
</head>
<body>

<header>
  <h1>Recategorizare Produse — Malinco.ro</h1>
  <p>Analiză AI a 5.123 produse · <?= $total ?> propuneri identificate · Click pe produs deschide în ERP</p>
</header>

<div class="progress-wrap">
  <div class="prog-stat gray">
    <strong><?= $counts['pending'] ?></strong>
    <span>în așteptare</span>
  </div>
  <div class="progress-bar-bg" title="<?= $pct ?>% procesat">
    <div class="progress-bar-fill" id="progressFill"></div>
  </div>
  <div class="prog-stat green">
    <strong><?= $counts['approved'] ?></strong>
    <span>acceptate</span>
  </div>
  <div class="prog-stat red">
    <strong><?= $counts['rejected'] ?></strong>
    <span>respinse</span>
  </div>
  <div class="prog-stat" style="color:white">
    <strong><?= $pct ?>%</strong>
    <span>procesat</span>
  </div>
</div>

<div class="tabs">
  <div class="tab active" onclick="switchTab('pending', this)">
    De procesat <span class="badge" id="badgePending"><?= $counts['pending'] ?></span>
  </div>
  <div class="tab" onclick="switchTab('processed', this)">
    Procesate <span class="badge green" id="badgeProcessed"><?= $counts['approved'] + $counts['rejected'] ?></span>
  </div>
</div>

<!-- ═══════════════ TAB: DE PROCESAT ═══════════════ -->
<div class="tab-content active" id="tab-pending">

<div class="toolbar">
  <div>
    <label>Caută produs</label>
    <input type="text" id="search" placeholder="Tastează un nume..." oninput="filterPending()">
  </div>
  <div>
    <label>Din categoria</label>
    <select id="filterFrom" onchange="filterPending()">
      <option value="">— Toate —</option>
      <?= fromOpts($pending) ?>
    </select>
  </div>
  <div>
    <label>În categoria</label>
    <select id="filterTo" onchange="filterPending()">
      <option value="">— Toate —</option>
      <?= toOpts($pending) ?>
    </select>
  </div>
  <button class="btn-reset" onclick="resetFilters()">Resetează</button>
  <div style="color:#888; font-size:13px; padding-bottom:2px;">
    <span id="visibleCount"><?= count($pending) ?></span> vizibile din <?= count($pending) ?>
  </div>
</div>


<div class="container">
<?php if (empty($pending)): ?>
  <div class="empty-msg">🎉 Toate propunerile au fost procesate!</div>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Produs</th>
      <th>Categorie actuală</th>
      <th></th>
      <th>Categorie propusă</th>
      <th>Motiv AI</th>
      <th>Acțiune</th>
    </tr>
  </thead>
  <tbody id="pendingBody">
    <?php foreach ($pending as $p): ?>
    <tr id="row-<?= $p['id'] ?>" data-from="<?= htmlspecialchars($p['current_cats']) ?>" data-to="<?= htmlspecialchars($p['suggested_cat_name']) ?>" data-name="<?= htmlspecialchars(strtolower($p['product_name'])) ?>">
      <td class="col-id"><?= $p['woo_product_id'] ?></td>
      <td class="col-name"><a href="<?= BASE_URL ?>/produse/<?= $p['woo_product_id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($p['product_name']) ?></a></td>
      <td class="col-from"><?= htmlspecialchars($p['current_cats']) ?></td>
      <td class="col-arrow">→</td>
      <td class="col-to"><?= htmlspecialchars($p['suggested_cat_name']) ?></td>
      <td class="col-reason"><?= htmlspecialchars($p['reason']) ?></td>
      <td class="col-actions">
        <button class="btn-approve" onclick="review(<?= $p['id'] ?>, 'approve', this)">✓ Acceptă</button>
        <button class="btn-reject" onclick="review(<?= $p['id'] ?>, 'reject', this)">✗ Respinge</button>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
</div>

<!-- ═══════════════ TAB: PROCESATE ═══════════════ -->
<div class="tab-content" id="tab-processed">
<div class="container">
<?php if (empty($processed)): ?>
  <div class="empty-msg">Nicio propunere procesată încă.</div>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Produs</th>
      <th>Categorie veche</th>
      <th></th>
      <th>Categorie nouă</th>
      <th>Status</th>
      <th>Revizuit de</th>
      <th>Data</th>
    </tr>
  </thead>
  <tbody id="processedBody">
    <?php foreach ($processed as $p): ?>
    <tr>
      <td class="col-id"><?= $p['woo_product_id'] ?></td>
      <td class="col-name"><a href="<?= BASE_URL ?>/produse/<?= $p['woo_product_id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($p['product_name']) ?></a></td>
      <td class="col-from"><?= htmlspecialchars($p['current_cats']) ?></td>
      <td class="col-arrow">→</td>
      <td class="col-to"><?= htmlspecialchars($p['suggested_cat_name']) ?></td>
      <td class="col-status-<?= $p['status'] ?>"><?= $p['status'] === 'approved' ? '✓ Acceptat' : '✗ Respins' ?></td>
      <td><?= htmlspecialchars($p['reviewer_name'] ?? '—') ?></td>
      <td class="col-date"><?= $p['reviewed_at'] ? date('d.m.Y H:i', strtotime($p['reviewed_at'])) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
const TOKEN = '<?= API_TOKEN ?>';
const BASE  = '<?= BASE_URL ?>';

// ─── Tabs ──────────────────────────────────────────────────────────────────
function switchTab(name, el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
}

// ─── Toast ─────────────────────────────────────────────────────────────────
function showToast(msg, type) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show' + (type ? ' ' + type : '');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3200);
}

// ─── Review (single) ───────────────────────────────────────────────────────
async function review(id, action, btn) {
  const row = document.getElementById('row-' + id);
  const btns = row.querySelectorAll('button');
  btns.forEach(b => b.disabled = true);
  try {
    const res = await fetch(BASE + '/api/category-review/' + id + '/' + action, {
      method: 'POST',
      headers: { 'X-Review-Token': TOKEN, 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      showToast(res.status === 409 ? 'Deja revizuit.' : 'Eroare: ' + (err.error || res.status), 'error');
      btns.forEach(b => b.disabled = false);
      return;
    }
    const data = await res.json().catch(() => ({}));
    // Move row to processed tab
    moveToProcessed(row, action, data.reviewer || '—');
    updateStats(action);
    showToast(action === 'approve' ? '✓ Mutat în WooCommerce!' : '✗ Respins.', action === 'approve' ? 'success' : '');
  } catch(e) {
    showToast('Eroare de rețea', 'error');
    btns.forEach(b => b.disabled = false);
  }
}

// ─── Move row to processed tab ─────────────────────────────────────────────
function moveToProcessed(row, action, reviewer) {
  const id = row.querySelector('.col-id').textContent.trim();
  const name = row.querySelector('.col-name').innerHTML;
  const from = row.querySelector('.col-from').textContent.trim();
  const to = row.querySelector('.col-to').textContent.trim();
  const statusClass = action === 'approve' ? 'col-status-approved' : 'col-status-rejected';
  const statusText  = action === 'approve' ? '✓ Acceptat' : '✗ Respins';
  const now = new Date().toLocaleDateString('ro-RO', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });

  const newRow = document.createElement('tr');
  newRow.innerHTML = `
    <td class="col-id">${id}</td>
    <td class="col-name">${name}</td>
    <td class="col-from">${from}</td>
    <td class="col-arrow">→</td>
    <td class="col-to">${to}</td>
    <td class="${statusClass}">${statusText}</td>
    <td>${reviewer || '—'}</td>
    <td class="col-date">${now}</td>`;

  const pb = document.getElementById('processedBody');
  pb.insertBefore(newRow, pb.firstChild);

  row.remove();
  filterPending();
}

// ─── Stats update ──────────────────────────────────────────────────────────
let pendingCount = <?= $counts['pending'] ?>;
let approvedCount = <?= $counts['approved'] ?>;
let rejectedCount = <?= $counts['rejected'] ?>;
const total = <?= $total ?>;

function updateStats(action) {
  pendingCount--;
  if (action === 'approve') approvedCount++; else rejectedCount++;
  document.getElementById('badgePending').textContent = pendingCount;
  document.getElementById('badgeProcessed').textContent = approvedCount + rejectedCount;
  const pct = total > 0 ? Math.round((approvedCount + rejectedCount) / total * 100) : 0;
  document.getElementById('progressFill').style.width = pct + '%';
}


// ─── Filter pending ────────────────────────────────────────────────────────
function filterPending() {
  const search = document.getElementById('search').value.toLowerCase();
  const from   = document.getElementById('filterFrom').value;
  const to     = document.getElementById('filterTo').value;
  const rows   = document.querySelectorAll('#pendingBody tr');
  let visible  = 0;
  rows.forEach(row => {
    const ms = !search || row.dataset.name.includes(search);
    const mf = !from   || row.dataset.from === from;
    const mt = !to     || row.dataset.to   === to;
    if (ms && mf && mt) { row.classList.remove('hidden'); visible++; }
    else row.classList.add('hidden');
  });
  document.getElementById('visibleCount').textContent = visible;
}

function resetFilters() {
  document.getElementById('search').value = '';
  document.getElementById('filterFrom').value = '';
  document.getElementById('filterTo').value = '';
  filterPending();
}

// ─── Live sync (polling 15s) ────────────────────────────────────────────────
let lastSyncTs = new Date(Date.now() - 30000).toISOString().slice(0, 19).replace('T', ' ');
let syncPaused = false;

async function syncWithOthers() {
  if (syncPaused) return;
  try {
    const res = await fetch(BASE + '/recategorizare.php?sync=1&since=' + encodeURIComponent(lastSyncTs));
    if (!res.ok) return;
    const data = await res.json();
    if (data.error) return;

    let removed = 0;
    for (const item of (data.processed || [])) {
      const row = document.getElementById('row-' + item.id);
      if (row) {
        // Altcineva a procesat acest rând — îl mutăm fără toast
        moveToProcessed(row, item.status === 'approved' ? 'approve' : 'reject', item.reviewer_name || '—');
        removed++;
      }
    }

    // Actualizează contoarele cu valorile reale din DB
    if (data.counts) {
      pendingCount   = data.counts.pending   || 0;
      approvedCount  = data.counts.approved  || 0;
      rejectedCount  = data.counts.rejected  || 0;
      document.getElementById('badgePending').textContent  = pendingCount;
      document.getElementById('badgeProcessed').textContent = approvedCount + rejectedCount;
      const pct = total > 0 ? Math.round((approvedCount + rejectedCount) / total * 100) : 0;
      document.getElementById('progressFill').style.width = pct + '%';
      filterPending();
    }

    if (removed > 0) {
      showToast(removed + (removed === 1 ? ' produs procesat' : ' produse procesate') + ' de alt utilizator', '');
    }

    lastSyncTs = data.ts || lastSyncTs;
  } catch(e) { /* silent */ }
}

// Patch moveToProcessed să accepte parametru silent
const _origMoveToProcessed = moveToProcessed;
// Override ca să suporte apelul din sync (fără a duplica în tab procesate vizual dacă e ascuns)
function moveToProcessed(row, action, silent) {
  const id = row.querySelector('.col-id').textContent.trim();
  const name = row.querySelector('.col-name').innerHTML;
  const from = row.querySelector('.col-from').textContent.trim();
  const to = row.querySelector('.col-to').textContent.trim();
  const statusClass = action === 'approve' ? 'col-status-approved' : 'col-status-rejected';
  const statusText  = action === 'approve' ? '✓ Acceptat' : '✗ Respins';
  const now = new Date().toLocaleDateString('ro-RO', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });

  const newRow = document.createElement('tr');
  newRow.innerHTML = `
    <td class="col-id">${id}</td>
    <td class="col-name">${name}</td>
    <td class="col-from">${from}</td>
    <td class="col-arrow">→</td>
    <td class="col-to">${to}</td>
    <td class="${statusClass}">${statusText}</td>
    <td class="col-date">${now}</td>`;

  const pb = document.getElementById('processedBody');
  pb.insertBefore(newRow, pb.firstChild);
  row.remove();
  filterPending();
}

// Indicator sync în colțul din dreapta sus
const syncDot = document.createElement('div');
syncDot.style.cssText = 'position:fixed;top:12px;right:16px;width:8px;height:8px;border-radius:50%;background:#4ade80;z-index:999;opacity:.8;title:Live sync activ';
syncDot.title = 'Live sync activ — se actualizează la 15s';
document.body.appendChild(syncDot);

function pulseDot() {
  syncDot.style.transform = 'scale(1.6)';
  setTimeout(() => syncDot.style.transform = 'scale(1)', 400);
}

setInterval(async () => {
  syncDot.style.background = '#fbbf24';
  await syncWithOthers();
  syncDot.style.background = '#4ade80';
  pulseDot();
}, 15000);
</script>
</body>
</html>
