<x-filament-panels::page>
    @php
        $img        = $this->getImageStats();
        $cat        = $this->getCategoryStats();
        $desc       = $this->getDescriptionStats();
        $attrs      = $this->getAttributeStats();
        $topAttrs   = $this->getTopAttributes();
        $recentImgs = $this->getRecentlyApproved();
        $recentCats = $this->getRecentlyCategorized();
        $recentDesc = $this->getRecentlyDescribed();
    @endphp

    {{-- ── Header stat cards ─────────────────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem;">
        @php
        $cards = [
            ['label'=>'Total produse ERP',   'value'=> number_format($img['total']),                           'color'=>'#6b7280'],
            ['label'=>'Cu imagine',           'value'=> number_format($img['with_image']),                      'color'=>'#16a34a'],
            ['label'=>'Cu atribute',          'value'=> number_format($attrs['with_attrs']),                    'color'=>'#7c3aed'],
            ['label'=>'Categorizate',         'value'=> number_format($cat['categorized']),                     'color'=>'#d97706'],
            ['label'=>'Cu descriere',         'value'=> number_format($desc['with_desc']),                      'color'=>'#0891b2'],
            ['label'=>'Titluri reformatate',  'value'=> number_format($attrs['reformatted']),                   'color'=>'#059669'],
        ];
        @endphp
        @foreach($cards as $card)
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1rem 1.25rem;">
            <div style="font-size:0.72rem;color:#6b7280;margin-bottom:0.25rem;text-transform:uppercase;letter-spacing:.03em;">{{ $card['label'] }}</div>
            <div style="font-size:1.7rem;font-weight:700;color:{{ $card['color'] }};">{{ $card['value'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- ── Rând 1: Imagini + Atribute + Titluri ───────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;margin-bottom:1.5rem;">

        {{-- ── Imagini ──────────────────────────────────────────────────── --}}
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.9rem;font-weight:600;margin-bottom:1rem;color:#111827;">Imagini</div>

            @foreach([
                ['Căutare Bing', $img['searched'], $img['total'], $img['search_pct'], '#2563eb'],
                ['Imagini aplicate', $img['with_image'], $img['total'], $img['image_pct'], '#16a34a'],
            ] as [$lbl, $val, $tot, $pct, $clr])
            <div style="margin-bottom:0.9rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#374151;margin-bottom:0.3rem;">
                    <span>{{ $lbl }}</span><span>{{ $val }} / {{ $tot }} ({{ $pct }}%)</span>
                </div>
                <div style="background:#e5e7eb;border-radius:999px;height:7px;">
                    <div style="background:{{ $clr }};height:100%;width:{{ $pct }}%;border-radius:999px;"></div>
                </div>
            </div>
            @endforeach

            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.4rem;margin-bottom:0.9rem;">
                @foreach([['Pending','#f59e0b',$img['pending']],['Aprobate','#16a34a',$img['approved']],['Respinse','#dc2626',$img['rejected']]] as [$lbl,$clr,$val])
                <div style="background:#f9fafb;border-radius:0.5rem;padding:0.5rem;text-align:center;">
                    <div style="font-size:1rem;font-weight:700;color:{{ $clr }};">{{ number_format($val) }}</div>
                    <div style="font-size:0.68rem;color:#6b7280;">{{ $lbl }}</div>
                </div>
                @endforeach
            </div>

            @if($recentImgs->isNotEmpty())
            <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.4rem;text-transform:uppercase;">Ultimele aprobate</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.35rem;">
                @foreach($recentImgs->take(6) as $r)
                <div style="aspect-ratio:1;overflow:hidden;border-radius:0.4rem;border:1px solid #e5e7eb;background:#f3f4f6;">
                    <img src="{{ $r->image_url }}" alt="{{ $r->name }}" loading="lazy" title="{{ $r->name }}"
                         style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'">
                </div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- ── Atribute tehnice ──────────────────────────────────────────── --}}
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.9rem;font-weight:600;margin-bottom:1rem;color:#111827;">Atribute tehnice</div>

            <div style="margin-bottom:0.9rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#374151;margin-bottom:0.3rem;">
                    <span>Produse cu atribute</span>
                    <span>{{ $attrs['with_attrs'] }} / {{ $attrs['total'] }} ({{ $attrs['attr_pct'] }}%)</span>
                </div>
                <div style="background:#e5e7eb;border-radius:999px;height:7px;">
                    <div style="background:#7c3aed;height:100%;width:{{ $attrs['attr_pct'] }}%;border-radius:999px;"></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.4rem;margin-bottom:0.9rem;">
                <div style="background:#f9fafb;border-radius:0.5rem;padding:0.5rem;text-align:center;">
                    <div style="font-size:1rem;font-weight:700;color:#7c3aed;">{{ number_format($attrs['total_attrs']) }}</div>
                    <div style="font-size:0.68rem;color:#6b7280;">Total atribute</div>
                </div>
                <div style="background:#f9fafb;border-radius:0.5rem;padding:0.5rem;text-align:center;">
                    <div style="font-size:1rem;font-weight:700;color:#6b7280;">{{ $attrs['total_attrs'] > 0 && $attrs['with_attrs'] > 0 ? round($attrs['total_attrs'] / $attrs['with_attrs'], 1) : 0 }}</div>
                    <div style="font-size:0.68rem;color:#6b7280;">Medie / produs</div>
                </div>
            </div>

            @if($topAttrs->isNotEmpty())
            <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.4rem;text-transform:uppercase;">Top atribute</div>
            <div style="max-height:10rem;overflow-y:auto;">
                @foreach($topAttrs as $a)
                @php $pct = $attrs['with_attrs'] > 0 ? round($a->cnt / $attrs['with_attrs'] * 100) : 0; @endphp
                <div style="margin-bottom:0.35rem;">
                    <div style="display:flex;justify-content:space-between;font-size:0.74rem;color:#374151;margin-bottom:0.1rem;">
                        <span>{{ $a->name }}</span><span style="color:#9ca3af;">{{ $a->cnt }}</span>
                    </div>
                    <div style="background:#e5e7eb;border-radius:999px;height:4px;">
                        <div style="background:#7c3aed;height:100%;width:{{ $pct }}%;border-radius:999px;"></div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- ── Titluri & Normalizare ─────────────────────────────────────── --}}
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.9rem;font-weight:600;margin-bottom:1rem;color:#111827;">Titluri & Normalizare</div>

            @foreach([
                ['Denumiri normalizate', $attrs['normalized'], $attrs['total'], $attrs['norm_pct'], '#059669'],
                ['Titluri reformatate', $attrs['reformatted'], $attrs['total'], $attrs['reformat_pct'], '#0d9488'],
            ] as [$lbl, $val, $tot, $pct, $clr])
            <div style="margin-bottom:0.9rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#374151;margin-bottom:0.3rem;">
                    <span>{{ $lbl }}</span><span>{{ $val }} / {{ $tot }} ({{ $pct }}%)</span>
                </div>
                <div style="background:#e5e7eb;border-radius:999px;height:7px;">
                    <div style="background:{{ $clr }};height:100%;width:{{ $pct }}%;border-radius:999px;"></div>
                </div>
            </div>
            @endforeach

            <div style="margin-top:1rem;border-top:1px solid #f3f4f6;padding-top:0.75rem;">
                <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.5rem;text-transform:uppercase;">Pipeline activ</div>
                @foreach([
                    ['Căutare imagini Bing', $img['search_pct'] < 100],
                    ['Evaluare imagini Claude', $img['image_pct'] < 100],
                    ['Generare atribute', $attrs['attr_pct'] < 100],
                    ['Reformatare titluri', $attrs['reformat_pct'] < 100],
                    ['Generare descrieri', $desc['desc_pct'] < 100],
                ] as [$task, $active])
                <div style="display:flex;align-items:center;gap:0.4rem;padding:0.2rem 0;font-size:0.75rem;color:{{ $active ? '#374151' : '#9ca3af' }};">
                    <span style="width:8px;height:8px;border-radius:50%;background:{{ $active ? '#f59e0b' : '#16a34a' }};flex-shrink:0;"></span>
                    {{ $task }}
                    @if(!$active)<span style="color:#16a34a;font-size:0.7rem;">✓</span>@endif
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Rând 2: Categorii + Descrieri ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:1.5rem;">

        {{-- ── Categorii ────────────────────────────────────────────────── --}}
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.9rem;font-weight:600;margin-bottom:1rem;color:#111827;">Categorii</div>

            <div style="margin-bottom:0.9rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#374151;margin-bottom:0.3rem;">
                    <span>Produse categorizate</span>
                    <span>{{ $cat['categorized'] }} / {{ $cat['total'] }} ({{ $cat['cat_pct'] }}%)</span>
                </div>
                <div style="background:#e5e7eb;border-radius:999px;height:7px;">
                    <div style="background:#d97706;height:100%;width:{{ $cat['cat_pct'] }}%;border-radius:999px;"></div>
                </div>
            </div>

            @if($cat['breakdown']->isNotEmpty())
            <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.4rem;text-transform:uppercase;">Pe categorie principală</div>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.35rem;max-height:13rem;overflow-y:auto;">
                @foreach($cat['breakdown'] as $row)
                @php $pct = $cat['categorized'] > 0 ? round($row->cnt / $cat['categorized'] * 100) : 0; @endphp
                <div style="background:#fffbeb;border-radius:0.4rem;padding:0.4rem 0.5rem;">
                    <div style="font-size:0.73rem;font-weight:500;color:#92400e;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $row->top_name }}">{{ $row->top_name }}</div>
                    <div style="font-size:0.9rem;font-weight:700;color:#d97706;">{{ $row->cnt }}</div>
                </div>
                @endforeach
            </div>
            @endif

            @if($recentCats->isNotEmpty())
            <div style="margin-top:0.75rem;border-top:1px solid #f3f4f6;padding-top:0.6rem;">
                <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.35rem;text-transform:uppercase;">Ultimele categorizate</div>
                @foreach($recentCats->take(5) as $r)
                <div style="display:flex;justify-content:space-between;padding:0.18rem 0;font-size:0.75rem;border-bottom:1px solid #f9fafb;">
                    <span style="color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:52%;" title="{{ $r->product_name }}">{{ $r->product_name }}</span>
                    <span style="color:#d97706;font-size:0.7rem;white-space:nowrap;">{{ $r->parent_name ? $r->parent_name.' > ' : '' }}{{ $r->category_name }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        {{-- ── Descrieri ────────────────────────────────────────────────── --}}
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.9rem;font-weight:600;margin-bottom:1rem;color:#111827;">Descrieri</div>

            <div style="margin-bottom:0.9rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#374151;margin-bottom:0.3rem;">
                    <span>Descrieri generate</span>
                    <span>{{ $desc['with_desc'] }} / {{ $desc['total'] }} ({{ $desc['desc_pct'] }}%)</span>
                </div>
                <div style="background:#e5e7eb;border-radius:999px;height:7px;">
                    <div style="background:#0891b2;height:100%;width:{{ $desc['desc_pct'] }}%;border-radius:999px;"></div>
                </div>
            </div>

            @if($recentDesc->isNotEmpty())
            <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.4rem;text-transform:uppercase;">Ultimele generate</div>
            @foreach($recentDesc as $r)
            <div style="margin-bottom:0.6rem;padding:0.5rem;background:#f0f9ff;border-radius:0.5rem;border-left:3px solid #0891b2;">
                <div style="font-size:0.75rem;font-weight:600;color:#0c4a6e;margin-bottom:0.2rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $r->name }}</div>
                <div style="font-size:0.72rem;color:#374151;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">{{ $r->short_description }}</div>
            </div>
            @endforeach
            @else
            <div style="text-align:center;padding:2rem 0;color:#9ca3af;font-size:0.8rem;">În curs de generare...</div>
            @endif
        </div>
    </div>

    {{-- ── Refresh note ──────────────────────────────────────────────────── --}}
    <div style="margin-top:1rem;text-align:right;font-size:0.72rem;color:#9ca3af;">
        Se actualizează automat la fiecare 8 secunde &nbsp;·&nbsp; {{ now()->format('H:i:s') }}
    </div>

</x-filament-panels::page>
