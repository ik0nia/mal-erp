@extends('app.layout')

@section('title', 'De predat')
@section('back', '/app')

@push('head')
<style>
    .p-bar{display:flex;gap:.4rem;overflow-x:auto;padding-bottom:.5rem;margin-bottom:.6rem;}
    .p-chip{flex:0 0 auto;background:#fff;border:1px solid #ece6da;border-radius:.6rem;padding:.45rem .8rem;text-decoration:none;color:#374151;font-size:.8rem;font-weight:600;}
    .p-chip--on{border-color:#0e7490;background:#ecfeff;color:#0e7490;}
    .p-card{background:#fff;border:1px solid #ece6da;border-radius:.85rem;margin-bottom:.7rem;overflow:hidden;}
    .p-head{padding:.65rem .8rem;background:#fffbeb;border-bottom:1px solid #fef3c7;display:flex;flex-direction:column;gap:.35rem;}
    .p-head-top{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
    .p-badge{font-size:.65rem;font-weight:800;padding:.12rem .45rem;border-radius:9999px;background:#e0e7ff;color:#3730a3;}
    .p-client{font-weight:700;font-size:.9rem;}
    .p-pill{font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:9999px;}
    .src-magazin{background:#d1fae5;color:#065f46;}
    .src-depozit{background:#fef3c7;color:#92400e;}
    .src-livrare{background:#dbeafe;color:#1d4ed8;}
    .p-line{display:flex;align-items:center;gap:.5rem;padding:.5rem .8rem;border-top:1px solid #f7f3ec;font-size:.85rem;}
    .p-line-info{flex:1;min-width:0;}
    .p-line-name{font-weight:600;}
    .p-line-meta{font-size:.7rem;color:#9ca3af;}
    .p-qty{width:3.4rem;border:1px solid #d1d5db;border-radius:.45rem;padding:.4rem;font-size:.85rem;text-align:center;}
    .p-preda{background:#0e7490;color:#fff;border:none;border-radius:.45rem;padding:.45rem .6rem;font-size:.78rem;font-weight:700;cursor:pointer;}
    .p-check{flex:0 0 auto;width:2.4rem;height:2.4rem;border:1px solid #bbf7d0;background:#f0fdf4;color:#16a34a;border-radius:.55rem;font-size:1rem;font-weight:800;cursor:pointer;}
    .p-foot{padding:.6rem .8rem;}
    .p-done{width:100%;background:#16a34a;color:#fff;border:none;border-radius:.55rem;padding:.65rem;font-size:.9rem;font-weight:800;cursor:pointer;}
    .p-empty{text-align:center;color:#9ca3af;padding:3rem 1rem;}
</style>
@endpush

@section('content')
    @php
        $tipLabels = ['BON'=>'Bon','F'=>'Factură','AE'=>'Aviz'];
        $chips = ['' => 'Toate', 'depozit' => '📦 Depozit', 'livrare' => '🚚 Livrare'];
        $fmt = fn($n) => rtrim(rtrim(number_format((float)$n,3,',','.'),'0'),',');
    @endphp

    <div class="p-bar">
        @foreach($chips as $val => $label)
            <a href="{{ url('/app/de-predat') }}?sursa={{ $val }}" class="p-chip {{ $sursa === $val ? 'p-chip--on' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    @forelse($documente as $doc)
        <div class="p-card" data-tip="{{ $doc['tip_doc'] }}" data-doc="{{ $doc['doc_id'] }}" data-sursa="{{ $doc['sursa'] }}">
            <div class="p-head">
                <div class="p-head-top">
                    <span class="p-pill src-{{ $doc['sursa'] }}">{{ strtoupper($doc['sursa']) }}</span>
                    <span class="p-badge">{{ $tipLabels[$doc['tip_doc']] ?? $doc['tip_doc'] }} #{{ $doc['nr_doc'] }}</span>
                    <span class="p-client">{{ $doc['client'] }}</span>
                </div>
                <div class="p-line-meta">{{ $doc['nr_linii'] }} produse · {{ $fmt($doc['rest_total']) }} buc de predat</div>
            </div>

            @foreach($doc['lines'] as $line)
                <div class="p-line" data-poz="{{ $line['pozitie'] }}" data-rest="{{ $line['rest'] }}">
                    <div class="p-line-info">
                        <div class="p-line-name">{{ $line['produs'] }}</div>
                        <div class="p-line-meta js-rest">rămas {{ $fmt($line['rest']) }} buc @if($line['cant_predata'] > 0) (din {{ $fmt($line['cant_alocata']) }})@endif</div>
                    </div>
                    <input type="number" min="0" step="0.01" class="p-qty js-qty" value="{{ $fmt($line['rest']) }}">
                    <button class="p-preda js-predapart" title="Predă cantitatea introdusă">Predă</button>
                    <button class="p-check js-linie" title="Predă tot restul">✓</button>
                </div>
            @endforeach

            <div class="p-foot">
                <button class="p-done js-predat">✓ {{ $doc['sursa']==='livrare' ? 'Livrat tot' : 'Predat tot' }}</button>
            </div>
        </div>
    @empty
        <div class="p-empty">Nimic de predat {{ $sursa ? 'pe sursa aleasă' : 'momentan' }}.<br>Confirmă documente în „Dispecerizare".</div>
    @endforelse
@endsection

@push('scripts')
<script>
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const fmt = n => (Math.round(n*1000)/1000).toString().replace('.', ',');
    const post = (url, body) => fetch(url, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify(body),
    }).then(r => r.json()).catch(() => ({ok:false}));
    const removeCard = (card) => { card.style.transition='opacity .3s'; card.style.opacity='0'; setTimeout(()=>card.remove(),300); };
    const afterLine = (card, line) => { line.remove(); if (!card.querySelector('.p-line')) removeCard(card); };

    document.addEventListener('click', async (e) => {
        // Predare PARȚIALĂ pe cantitate
        const pp = e.target.closest('.js-predapart');
        if (pp) {
            const card = pp.closest('.p-card'), line = pp.closest('.p-line');
            const rest = parseFloat(line.dataset.rest);
            let qty = parseFloat(line.querySelector('.js-qty').value);
            if (!(qty > 0)) return;
            if (qty > rest) qty = rest;
            pp.disabled = true; pp.textContent = '...';
            const ok = (await post('/app/de-predat/linie', { tip_doc:card.dataset.tip, doc_id:card.dataset.doc, pozitie:line.dataset.poz, sursa:card.dataset.sursa, cant:qty })).ok;
            if (ok) {
                const newRest = Math.round((rest - qty)*1000)/1000;
                if (newRest <= 0.001) { afterLine(card, line); }
                else {
                    line.dataset.rest = newRest;
                    line.querySelector('.js-rest').textContent = 'rămas ' + fmt(newRest) + ' buc';
                    line.querySelector('.js-qty').value = fmt(newRest);
                    pp.disabled = false; pp.textContent = 'Predă';
                }
            } else { pp.disabled = false; pp.textContent = 'Predă'; }
            return;
        }
        // Predă tot restul produsului
        const lb = e.target.closest('.js-linie');
        if (lb) {
            const card = lb.closest('.p-card'), line = lb.closest('.p-line');
            lb.disabled = true; lb.textContent = '…';
            const ok = (await post('/app/de-predat/linie', { tip_doc:card.dataset.tip, doc_id:card.dataset.doc, pozitie:line.dataset.poz, sursa:card.dataset.sursa })).ok;
            if (ok) afterLine(card, line); else { lb.disabled=false; lb.textContent='✓'; }
            return;
        }
        // Predă tot (toată sursa documentului)
        const pt = e.target.closest('.js-predat');
        if (pt) {
            const card = pt.closest('.p-card');
            pt.disabled = true; pt.textContent = '...';
            const ok = (await post('/app/de-predat/gata', { tip_doc:card.dataset.tip, doc_id:card.dataset.doc, sursa:card.dataset.sursa })).ok;
            if (ok) removeCard(card); else { pt.disabled=false; pt.textContent='✓ Predat tot'; }
        }
    });
</script>
@endpush
