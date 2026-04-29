<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recategorizare Produse — Malinco ERP</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f0e8; color: #1a1a1a; }
header { background: #c0392b; color: white; padding: 18px 32px; display: flex; justify-content: space-between; align-items: center; }
header h1 { font-size: 21px; font-weight: 700; }
header p { font-size: 13px; opacity: .8; margin-top: 3px; }
.user-badge { background: rgba(255,255,255,.2); border-radius: 20px; padding: 6px 14px; font-size: 13px; font-weight: 600; white-space: nowrap; }
.progress-wrap { background: #1a1a1a; padding: 14px 32px; display: flex; gap: 32px; align-items: center; }
.progress-bar-bg { flex: 1; background: rgba(255,255,255,.15); border-radius: 20px; height: 10px; overflow: hidden; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg, #16a34a, #22c55e); border-radius: 20px; transition: width .4s ease; width: {{ $pct }}%; }
.prog-stat { color: white; font-size: 13px; white-space: nowrap; }
.prog-stat strong { font-size: 17px; font-weight: 700; display: block; }
.prog-stat span { font-size: 11px; opacity: .7; }
.prog-stat.green strong { color: #4ade80; }
.prog-stat.red strong { color: #f87171; }
.prog-stat.yellow strong { color: #fbbf24; }
.tabs { background: white; border-bottom: 2px solid #e8ddd4; padding: 0 32px; display: flex; position: sticky; top: 0; z-index: 20; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
.tab { padding: 14px 22px; font-size: 14px; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; color: #888; transition: all .15s; user-select: none; }
.tab:hover { color: #333; }
.tab.active { color: #c0392b; border-bottom-color: #c0392b; }
.tab .badge { border-radius: 20px; padding: 1px 8px; font-size: 11px; margin-left: 6px; background: #c0392b; color: white; }
.tab .badge.green { background: #16a34a; }
.toolbar { background: white; border-bottom: 1px solid #e8ddd4; padding: 12px 32px; display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.toolbar label { font-size: 11px; font-weight: 600; color: #888; display: block; margin-bottom: 3px; text-transform: uppercase; letter-spacing: .4px; }
.toolbar select, .toolbar input[type=text] { border: 1px solid #ddd; border-radius: 6px; padding: 7px 10px; font-size: 13px; background: #fafafa; min-width: 190px; }
.toolbar select:focus, .toolbar input:focus { outline: none; border-color: #c0392b; }
.btn-reset { background: white; color: #888; border: 1px solid #ddd; border-radius: 6px; padding: 7px 14px; font-size: 13px; cursor: pointer; }
.btn-reset:hover { border-color: #c0392b; color: #c0392b; }
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
.col-from { color: #999; font-size: 12px; max-width: 180px; }
.col-arrow { color: #ddd; width: 18px; font-size: 11px; }
.col-to { color: #15803d; font-weight: 600; font-size: 12px; max-width: 180px; }
.col-reason { color: #666; font-size: 11px; max-width: 220px; line-height: 1.45; }
.col-actions { white-space: nowrap; width: 155px; }
.col-reviewer { color: #64748b; font-size: 11px; white-space: nowrap; }
.col-date { color: #aaa; font-size: 11px; white-space: nowrap; }
.col-status-approved { color: #16a34a; font-weight: 600; font-size: 12px; }
.col-status-rejected { color: #dc2626; font-weight: 600; font-size: 12px; }
.btn-approve, .btn-reject { border: none; border-radius: 5px; padding: 5px 10px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .13s; }
.btn-approve { background: #dcfce7; color: #166534; margin-right: 4px; }
.btn-approve:hover { background: #16a34a; color: white; }
.btn-reject { background: #fee2e2; color: #991b1b; }
.btn-reject:hover { background: #dc2626; color: white; }
.btn-approve:disabled, .btn-reject:disabled { opacity: .35; cursor: not-allowed; }
.hidden { display: none !important; }
.empty-msg { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
.toast { position: fixed; bottom: 24px; right: 24px; background: #1e293b; color: white; padding: 12px 20px; border-radius: 8px; font-size: 13px; font-weight: 500; z-index: 9999; opacity: 0; transition: opacity .3s; pointer-events: none; max-width: 340px; }
.toast.show { opacity: 1; }
.toast.success { background: #16a34a; }
.toast.error   { background: #dc2626; }
</style>
</head>
<body>

<header>
  <div>
    <h1>Recategorizare Produse — Malinco.ro</h1>
    <p>Analiză AI a 5.123 produse · {{ $total }} propuneri identificate</p>
  </div>
  <div class="user-badge">👤 {{ $currentUser->name }}</div>
</header>

<div class="progress-wrap">
  <div class="prog-stat yellow"><strong>{{ $counts['pending'] }}</strong><span>în așteptare</span></div>
  <div class="progress-bar-bg" title="{{ $pct }}% procesat">
    <div class="progress-bar-fill" id="progressFill"></div>
  </div>
  <div class="prog-stat green"><strong>{{ $counts['approved'] }}</strong><span>acceptate</span></div>
  <div class="prog-stat red"><strong>{{ $counts['rejected'] }}</strong><span>respinse</span></div>
  <div class="prog-stat" style="color:white"><strong>{{ $pct }}%</strong><span>procesat</span></div>
</div>

<div class="tabs">
  <div class="tab active" onclick="switchTab('pending', this)">
    De procesat <span class="badge" id="badgePending">{{ $counts['pending'] }}</span>
  </div>
  <div class="tab" onclick="switchTab('processed', this)">
    Procesate <span class="badge green" id="badgeProcessed">{{ $counts['approved'] + $counts['rejected'] }}</span>
  </div>
</div>

<!-- TAB: DE PROCESAT -->
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
      @foreach(collect($pending)->pluck('current_cats')->unique()->sort()->values() as $cat)
        <option value="{{ $cat }}">{{ $cat }}</option>
      @endforeach
    </select>
  </div>
  <div>
    <label>În categoria</label>
    <select id="filterTo" onchange="filterPending()">
      <option value="">— Toate —</option>
      @foreach(collect($pending)->pluck('suggested_cat_name')->unique()->sort()->values() as $cat)
        <option value="{{ $cat }}">{{ $cat }}</option>
      @endforeach
    </select>
  </div>
  <button class="btn-reset" onclick="resetFilters()">Resetează</button>
  <div style="color:#888; font-size:13px; padding-bottom:2px;">
    <span id="visibleCount">{{ count($pending) }}</span> vizibile din {{ count($pending) }}
  </div>
</div>


<div class="container">
@if(empty($pending))
  <div class="empty-msg">🎉 Toate propunerile au fost procesate!</div>
@else
<table>
  <thead><tr>
    <th>ID</th><th>Produs</th><th>Categorie actuală</th><th></th>
    <th>Categorie propusă</th><th>Motiv AI</th><th>Acțiune</th>
  </tr></thead>
  <tbody id="pendingBody">
    @foreach($pending as $p)
    <tr id="row-{{ $p->id }}"
        data-from="{{ $p->current_cats }}"
        data-to="{{ $p->suggested_cat_name }}"
        data-name="{{ strtolower($p->product_name) }}">
      <td class="col-id">{{ $p->woo_product_id }}</td>
      <td class="col-name"><a href="https://erp.malinco.ro/produse/{{ $p->woo_product_id }}" target="_blank">{{ $p->product_name }}</a></td>
      <td class="col-from">{{ $p->current_cats }}</td>
      <td class="col-arrow">→</td>
      <td class="col-to">{{ $p->suggested_cat_name }}</td>
      <td class="col-reason">{{ $p->reason }}</td>
      <td class="col-actions">
        <button class="btn-approve" onclick="review({{ $p->id }}, 'approve', this)">✓ Acceptă</button>
        <button class="btn-reject"  onclick="review({{ $p->id }}, 'reject',  this)">✗ Respinge</button>
      </td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
</div>
</div>

<!-- TAB: PROCESATE -->
<div class="tab-content" id="tab-processed">
<div class="container">
@if(empty($processed))
  <div class="empty-msg">Nicio propunere procesată încă.</div>
@else
<table>
  <thead><tr>
    <th>ID</th><th>Produs</th><th>Categorie veche</th><th></th>
    <th>Categorie nouă</th><th>Status</th><th>Revizuit de</th><th>Data</th>
  </tr></thead>
  <tbody id="processedBody">
    @foreach($processed as $p)
    <tr>
      <td class="col-id">{{ $p->woo_product_id }}</td>
      <td class="col-name"><a href="https://erp.malinco.ro/produse/{{ $p->woo_product_id }}" target="_blank">{{ $p->product_name }}</a></td>
      <td class="col-from">{{ $p->current_cats }}</td>
      <td class="col-arrow">→</td>
      <td class="col-to">{{ $p->suggested_cat_name }}</td>
      <td class="col-status-{{ $p->status }}">{{ $p->status === 'approved' ? '✓ Acceptat' : '✗ Respins' }}</td>
      <td class="col-reviewer">{{ $p->reviewer_name ?? '—' }}</td>
      <td class="col-date">{{ $p->reviewed_at ? \Carbon\Carbon::parse($p->reviewed_at)->format('d.m.Y H:i') : '—' }}</td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif
</div>
</div>

<div class="toast" id="toast"></div>

<script>
const TOKEN        = '{{ config("services.category_review_token") }}';
const BASE         = 'https://erp.malinco.ro';
const CURRENT_USER = '{{ $currentUser->name }}';
let pendingCount   = {{ $counts['pending'] }};
let approvedCount  = {{ $counts['approved'] }};
let rejectedCount  = {{ $counts['rejected'] }};
const total        = {{ $total }};

function switchTab(name, el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
}

function showToast(msg, type) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show' + (type ? ' ' + type : '');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3200);
}

async function review(id, action, btn) {
  const row = document.getElementById('row-' + id);
  const btns = row.querySelectorAll('button');
  btns.forEach(b => b.disabled = true);
  try {
    const res = await fetch(BASE + '/api/category-review/' + id + '/' + action, {
      method: 'POST',
      headers: {
        'X-Review-Token': TOKEN,
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      }
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      showToast(res.status === 409 ? 'Deja revizuit.' : 'Eroare: ' + (err.error || res.status), 'error');
      btns.forEach(b => b.disabled = false);
      return;
    }
    const data = await res.json();
    moveToProcessed(row, action, data.reviewer || CURRENT_USER);
    updateStats(action);
    showToast(action === 'approve' ? '✓ Mutat în WooCommerce!' : '✗ Respins.', action === 'approve' ? 'success' : '');
  } catch(e) {
    showToast('Eroare de rețea', 'error');
    btns.forEach(b => b.disabled = false);
  }
}

function moveToProcessed(row, action, reviewer) {
  const id   = row.querySelector('.col-id').textContent.trim();
  const name = row.querySelector('.col-name').innerHTML;
  const from = row.querySelector('.col-from').textContent.trim();
  const to   = row.querySelector('.col-to').textContent.trim();
  const statusClass = action === 'approve' ? 'col-status-approved' : 'col-status-rejected';
  const statusText  = action === 'approve' ? '✓ Acceptat' : '✗ Respins';
  const now  = new Date().toLocaleDateString('ro-RO', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });

  const newRow = document.createElement('tr');
  newRow.innerHTML = `
    <td class="col-id">${id}</td>
    <td class="col-name">${name}</td>
    <td class="col-from">${from}</td>
    <td class="col-arrow">→</td>
    <td class="col-to">${to}</td>
    <td class="${statusClass}">${statusText}</td>
    <td class="col-reviewer">${reviewer || '—'}</td>
    <td class="col-date">${now}</td>`;

  document.getElementById('processedBody').insertBefore(newRow, document.getElementById('processedBody').firstChild);
  row.remove();
  filterPending();
}

function updateStats(action) {
  pendingCount--;
  if (action === 'approve') approvedCount++; else rejectedCount++;
  document.getElementById('badgePending').textContent   = pendingCount;
  document.getElementById('badgeProcessed').textContent = approvedCount + rejectedCount;
  document.getElementById('progressFill').style.width   = (total > 0 ? Math.round((approvedCount + rejectedCount) / total * 100) : 0) + '%';
}


function filterPending() {
  const search = document.getElementById('search').value.toLowerCase();
  const from   = document.getElementById('filterFrom').value;
  const to     = document.getElementById('filterTo').value;
  let visible  = 0;
  document.querySelectorAll('#pendingBody tr').forEach(row => {
    const ok = (!search || row.dataset.name.includes(search))
            && (!from   || row.dataset.from === from)
            && (!to     || row.dataset.to   === to);
    row.classList.toggle('hidden', !ok);
    if (ok) visible++;
  });
  document.getElementById('visibleCount').textContent = visible;
}

function resetFilters() {
  document.getElementById('search').value    = '';
  document.getElementById('filterFrom').value = '';
  document.getElementById('filterTo').value   = '';
  filterPending();
}

// ─── Live sync 15s ─────────────────────────────────────────────────────────
let lastSyncTs = new Date(Date.now() - 30000).toISOString().slice(0, 19).replace('T', ' ');

async function syncWithOthers() {
  try {
    const res = await fetch(BASE + '/api/category-review/sync?since=' + encodeURIComponent(lastSyncTs));
    if (!res.ok) return;
    const data = await res.json();
    let removed = 0;
    for (const item of (data.processed || [])) {
      const row = document.getElementById('row-' + item.id);
      if (row) {
        moveToProcessed(row, item.status === 'approved' ? 'approve' : 'reject', item.reviewer_name);
        removed++;
      }
    }
    if (data.counts) {
      pendingCount  = data.counts.pending  || 0;
      approvedCount = data.counts.approved || 0;
      rejectedCount = data.counts.rejected || 0;
      document.getElementById('badgePending').textContent   = pendingCount;
      document.getElementById('badgeProcessed').textContent = approvedCount + rejectedCount;
      document.getElementById('progressFill').style.width   = (total > 0 ? Math.round((approvedCount + rejectedCount) / total * 100) : 0) + '%';
      filterPending();
    }
    if (removed > 0) showToast(removed + (removed === 1 ? ' produs procesat' : ' produse procesate') + ' de alt utilizator', '');
    lastSyncTs = data.ts || lastSyncTs;
  } catch(e) {}
}

// Punct verde live sync
const dot = document.createElement('div');
dot.style.cssText = 'position:fixed;top:14px;right:18px;width:8px;height:8px;border-radius:50%;background:#4ade80;z-index:999;opacity:.8;transition:transform .3s';
dot.title = 'Live sync activ';
document.body.appendChild(dot);

setInterval(async () => {
  dot.style.background = '#fbbf24';
  await syncWithOthers();
  dot.style.background = '#4ade80';
  dot.style.transform = 'scale(1.6)';
  setTimeout(() => dot.style.transform = 'scale(1)', 400);
}, 15000);
</script>
</body>
</html>
