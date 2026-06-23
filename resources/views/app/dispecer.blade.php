@extends('app.layout')

@section('title', 'Dispecerizare')
@section('back', '/app')

@push('head')
<style>
    .d-bar{display:flex;gap:.4rem;overflow-x:auto;padding-bottom:.5rem;margin-bottom:.6rem;-webkit-overflow-scrolling:touch;}
    .d-chip{flex:0 0 auto;display:flex;flex-direction:column;align-items:center;gap:.1rem;background:#fff;border:1px solid #ece6da;border-radius:.7rem;padding:.45rem .7rem;text-decoration:none;color:#1f2937;min-width:4.3rem;}
    .d-chip--on{border-color:#0e7490;background:#ecfeff;}
    .d-chip-n{font-size:1.1rem;font-weight:800;line-height:1;}
    .d-chip-l{font-size:.62rem;color:#6b7280;}
    .d-search{width:100%;border:1px solid #d1d5db;border-radius:.6rem;padding:.55rem .8rem;font-size:.9rem;}
    .d-card{background:#fff;border:1px solid #ece6da;border-radius:.85rem;margin-bottom:.7rem;overflow:hidden;}
    .d-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.65rem .8rem;cursor:pointer;}
    .d-head-l{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;min-width:0;}
    .d-badge{font-size:.65rem;font-weight:800;padding:.12rem .45rem;border-radius:9999px;}
    .d-badge--BON{background:#dcfce7;color:#065f46;}
    .d-badge--F{background:#dbeafe;color:#1d4ed8;}
    .d-badge--AE{background:#fef3c7;color:#92400e;}
    .d-client{font-weight:600;font-size:.85rem;max-width:11rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .d-tot{font-weight:700;font-size:.85rem;white-space:nowrap;}
    .d-srcpill{font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:9999px;}
    .src-magazin{background:#d1fae5;color:#065f46;}
    .src-depozit{background:#fef3c7;color:#92400e;}
    .src-livrare{background:#dbeafe;color:#1d4ed8;}
    .d-body{display:none;border-top:1px solid #f1ece2;}
    .d-card.open .d-body{display:block;}
    .d-confirm{margin:.5rem .8rem;width:calc(100% - 1.6rem);background:#16a34a;color:#fff;border:none;border-radius:.55rem;padding:.6rem;font-size:.85rem;font-weight:800;cursor:pointer;}
    .d-line{padding:.55rem .8rem;border-top:1px solid #f7f3ec;}
    .d-line-top{display:flex;justify-content:space-between;gap:.5rem;margin-bottom:.4rem;}
    .d-line-name{font-size:.82rem;font-weight:600;}
    .d-line-tot{font-size:.7rem;color:#9ca3af;white-space:nowrap;}
    .d-alocsum{display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:.4rem;}
    .d-quick{display:flex;gap:.3rem;}
    .d-qbtn{flex:1;border:1px solid #e5e7eb;background:#fff;border-radius:.5rem;padding:.4rem;font-size:.72rem;font-weight:700;color:#374151;cursor:pointer;}
    .d-qbtn.on-magazin{background:#10b981;border-color:#10b981;color:#fff;}
    .d-qbtn.on-depozit{background:#f59e0b;border-color:#f59e0b;color:#fff;}
    .d-qbtn.on-livrare{background:#3b82f6;border-color:#3b82f6;color:#fff;}
    .d-splittoggle{font-size:.7rem;color:#0e7490;background:none;border:none;cursor:pointer;margin-top:.35rem;padding:0;}
    .d-split{display:none;margin-top:.4rem;gap:.35rem;align-items:center;flex-wrap:wrap;}
    .d-split.open{display:flex;}
    .d-split label{font-size:.7rem;display:flex;align-items:center;gap:.2rem;}
    .d-split input{width:3.2rem;border:1px solid #d1d5db;border-radius:.4rem;padding:.3rem;font-size:.8rem;text-align:center;}
    .d-savebtn{background:#0e7490;color:#fff;border:none;border-radius:.45rem;padding:.35rem .6rem;font-size:.75rem;font-weight:700;cursor:pointer;}
    .d-empty{text-align:center;color:#9ca3af;padding:3rem 1rem;}
    .d-arrow{font-size:.7rem;color:#9ca3af;}
</style>
@endpush

@section('content')
    @php
        $base = url('/app/dispecer');
        $chips = ['' => ['Toate', $sumar['total']], 'neallocat' => ['Neallocat', $sumar['neallocat']], 'magazin' => ['Magazin', $sumar['magazin']], 'depozit' => ['Depozit', $sumar['depozit']], 'livrare' => ['Livrare', $sumar['livrare']]];
        $tipChips = ['' => ['Toate', $sumarTip['total']], 'F' => ['Facturi', $sumarTip['F']], 'AE' => ['Avize', $sumarTip['AE']], 'BON' => ['Bonuri', $sumarTip['BON']]];
        $qs = fn($over) => http_build_query(array_merge(['filtru'=>$filtru,'tip'=>$tip,'q'=>$q], $over));
        $tipLabels = ['BON'=>'Bon','F'=>'Factură','AE'=>'Aviz'];
        $fmt = fn($n) => rtrim(rtrim(number_format((float)$n,3,',','.'),'0'),',');
    @endphp

    <div class="d-bar">
        @foreach($chips as $val => [$label, $n])
            <a href="{{ $base }}?{{ $qs(['filtru'=>$val]) }}" class="d-chip {{ $filtru === $val ? 'd-chip--on' : '' }}">
                <span class="d-chip-n">{{ $n }}</span><span class="d-chip-l">{{ $label }}</span>
            </a>
        @endforeach
    </div>
    <div class="d-bar">
        @foreach($tipChips as $val => [$label, $n])
            <a href="{{ $base }}?{{ $qs(['tip'=>$val]) }}" class="d-chip {{ $tip === $val ? 'd-chip--on' : '' }}">
                <span class="d-chip-n">{{ $n }}</span><span class="d-chip-l">{{ $label }}</span>
            </a>
        @endforeach
    </div>

    <div style="display:flex;gap:.5rem;align-items:flex-start;">
        <form method="GET" action="{{ $base }}" style="flex:1;">
            <input type="hidden" name="filtru" value="{{ $filtru }}"><input type="hidden" name="tip" value="{{ $tip }}">
            <input type="text" name="q" value="{{ $q }}" class="d-search" placeholder="Caută client, produs, document..." />
        </form>
        <a href="{{ $base }}?{{ $qs(['refresh'=>1]) }}" title="Reîmprospătează" style="background:#0e7490;color:#fff;border-radius:.6rem;padding:.55rem .7rem;text-decoration:none;font-size:1.1rem;line-height:1;">⟳</a>
    </div>

    @forelse($documente as $doc)
        <div class="d-card" data-tip="{{ $doc['tip_doc'] }}" data-doc="{{ $doc['doc_id'] }}">
            <div class="d-head js-toggle">
                <div class="d-head-l">
                    <span class="d-badge d-badge--{{ $doc['tip_doc'] }}">{{ $tipLabels[$doc['tip_doc']] ?? $doc['tip_doc'] }} #{{ $doc['nr_doc'] }}</span>
                    <span class="d-client">{{ $doc['client'] }}</span>
                    @if($doc['confirmat'])<span class="d-srcpill" style="background:#dcfce7;color:#166534;">✓ trimis</span>@endif
                    @if($doc['rest_total'] > 0.001)<span class="d-srcpill src-null" style="background:#f3f4f6;color:#9ca3af;">neallocat</span>@endif
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;">
                    <span class="d-tot">{{ number_format($doc['total'], 2, ',', '.') }}</span>
                    <span class="d-arrow">▼</span>
                </div>
            </div>

            <div class="d-body">
                @foreach($doc['lines'] as $line)
                    @php
                        $aloc = $line['alocari'] ?? [];
                        $onlyS = count($aloc) === 1 ? array_key_first($aloc) : null;
                        $whole = ($onlyS && abs($aloc[$onlyS]['cant'] - $line['cantitate']) < 0.001) ? $onlyS : null;
                    @endphp
                    <div class="d-line" data-poz="{{ $line['pozitie'] }}" data-cant="{{ $line['cantitate'] }}">
                        <div class="d-line-top">
                            <span class="d-line-name">{{ $line['produs'] }}</span>
                            <span class="d-line-tot">{{ $fmt($line['cantitate']) }} buc</span>
                        </div>
                        <div class="d-alocsum js-sum">
                            @forelse($aloc as $s => $a)
                                <span class="d-srcpill src-{{ $s }}">{{ $s }} {{ $fmt($a['cant']) }}</span>
                            @empty
                                <span class="d-srcpill src-null">neallocat</span>
                            @endforelse
                        </div>
                        <div class="d-quick">
                            <button class="d-qbtn js-q {{ $whole==='magazin'?'on-magazin':'' }}" data-sursa="magazin">🏬 Magazin</button>
                            <button class="d-qbtn js-q {{ $whole==='depozit'?'on-depozit':'' }}" data-sursa="depozit">📦 Depozit</button>
                            <button class="d-qbtn js-q {{ $whole==='livrare'?'on-livrare':'' }}" data-sursa="livrare">🚚 Livrare</button>
                        </div>
                        <button class="d-splittoggle js-splittoggle">✂ împarte pe cantități</button>
                        <div class="d-split">
                            <label>🏬 <input type="number" min="0" step="0.01" class="js-in" data-s="magazin" value="{{ isset($aloc['magazin']) ? $fmt($aloc['magazin']['cant']) : '' }}"></label>
                            <label>📦 <input type="number" min="0" step="0.01" class="js-in" data-s="depozit" value="{{ isset($aloc['depozit']) ? $fmt($aloc['depozit']['cant']) : '' }}"></label>
                            <label>🚚 <input type="number" min="0" step="0.01" class="js-in" data-s="livrare" value="{{ isset($aloc['livrare']) ? $fmt($aloc['livrare']['cant']) : '' }}"></label>
                            <button class="d-savebtn js-savesplit">Salvează</button>
                        </div>
                    </div>
                @endforeach

                <button class="d-confirm js-confirm">✓ Confirmă predarea</button>
            </div>
        </div>
    @empty
        <div class="d-empty">Nicio vânzare {{ $filtru || $tip ? 'pe filtre' : 'azi' }}.</div>
    @endforelse
@endsection

@push('scripts')
<script>
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const ZI = @json($zi);
    const fmt = n => (Math.round(n*1000)/1000).toString().replace('.', ',');

    const post = (url, body) => fetch(url, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify(body),
    }).then(r => r.json()).catch(() => ({ok:false}));

    function repaintLine(lineEl, map) {
        // summary
        const sum = lineEl.querySelector('.js-sum');
        const parts = Object.entries(map).filter(([s,q]) => q > 0);
        sum.innerHTML = parts.length
            ? parts.map(([s,q]) => `<span class="d-srcpill src-${s}">${s} ${fmt(q)}</span>`).join('')
            : '<span class="d-srcpill src-null">neallocat</span>';
        // quick active
        const total = parseFloat(lineEl.dataset.cant);
        const only = parts.length === 1 ? parts[0][0] : null;
        const whole = (only && Math.abs(parts[0][1] - total) < 0.001) ? only : null;
        lineEl.querySelectorAll('.js-q').forEach(b => {
            b.className = 'd-qbtn js-q' + (b.dataset.sursa === whole ? ' on-' + whole : '');
        });
        // split inputs
        lineEl.querySelectorAll('.js-in').forEach(i => { i.value = map[i.dataset.s] > 0 ? fmt(map[i.dataset.s]) : ''; });
    }

    async function saveAloc(lineEl, map) {
        const card = lineEl.closest('.d-card');
        const ok = (await post('/app/dispecer/set', {
            tip_doc: card.dataset.tip, doc_id: card.dataset.doc, pozitie: lineEl.dataset.poz, alocari: map, zi: ZI,
        })).ok;
        if (ok) repaintLine(lineEl, map);
        return ok;
    }

    document.addEventListener('click', async (e) => {
        // expand/collapse
        const head = e.target.closest('.js-toggle');
        if (head) {
            const card = head.closest('.d-card'); card.classList.toggle('open');
            head.querySelector('.d-arrow').textContent = card.classList.contains('open') ? '▲' : '▼';
            return;
        }
        // quick whole-line source
        const q = e.target.closest('.js-q');
        if (q) {
            const line = q.closest('.d-line'); const total = parseFloat(line.dataset.cant);
            await saveAloc(line, { [q.dataset.sursa]: total });
            return;
        }
        // toggle split
        const st = e.target.closest('.js-splittoggle');
        if (st) { st.closest('.d-line').querySelector('.d-split').classList.toggle('open'); return; }
        // save split
        const ss = e.target.closest('.js-savesplit');
        if (ss) {
            const line = ss.closest('.d-line'); const total = parseFloat(line.dataset.cant);
            const map = {};
            line.querySelectorAll('.js-in').forEach(i => { const v = parseFloat(i.value); if (v > 0) map[i.dataset.s] = v; });
            const sum = Object.values(map).reduce((a,b)=>a+b, 0);
            if (sum > total + 0.001) { ss.textContent = 'Prea mult!'; setTimeout(()=>ss.textContent='Salvează',1500); return; }
            ss.textContent = '...';
            await saveAloc(line, map);
            ss.textContent = 'Salvat ✓'; setTimeout(()=>ss.textContent='Salvează',1200);
            return;
        }
        // confirm document
        const cf = e.target.closest('.js-confirm');
        if (cf) {
            const card = cf.closest('.d-card');
            cf.disabled = true; cf.textContent = '...';
            const res = await post('/app/dispecer/confirma', { tip_doc: card.dataset.tip, doc_id: card.dataset.doc, zi: ZI });
            if (res.ok) { cf.textContent = '✓ Trimis la predare'; cf.style.background = '#15803d'; }
            else { cf.disabled = false; cf.textContent = 'Alege întâi sursa'; setTimeout(()=>{cf.textContent='✓ Confirmă predarea';},1500); }
        }
    });
</script>
@endpush
