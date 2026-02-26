<x-filament-panels::page>
    @php
        $img        = $this->getImageStats();
        $localImg   = $this->getLocalImageStats();
        $cat        = $this->getCategoryStats();
        $desc       = $this->getDescriptionStats();
        $attrs      = $this->getAttributeStats();
        $web        = $this->getWebEnrichStats();
        $woo        = $this->getWooContentStats();
        $topAttrs   = $this->getTopAttributes();
        $recentImgs = $this->getRecentlyApproved();
        $recentCats = $this->getRecentlyCategorized();
        $recentDesc = $this->getRecentlyDescribed();
        $recentWeb  = $this->getRecentlyWebEnriched();
        $recentWoo  = $this->getRecentlyEvaluatedWoo();
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
            ['label'=>'Îmbogățite web',       'value'=> number_format($web['enriched']),                        'color'=>'#ea580c'],
            ['label'=>'Woo evaluate',         'value'=> number_format($woo['done']),                            'color'=>'#7c3aed'],
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
                ['Salvate pe server', $localImg['local'], $localImg['total_with_image'], $localImg['local_pct'], '#0891b2'],
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

    {{-- ── Rând 3: Îmbogățire web ─────────────────────────────────────────── --}}
    <div style="margin-top:1.5rem;background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1.25rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <div style="font-size:0.9rem;font-weight:600;color:#111827;">Îmbogățire din web (EAN lookup)</div>
            <div style="display:flex;align-items:center;gap:1rem;">
                <div style="font-size:0.78rem;color:#9ca3af;">{{ $web['enriched'] }} / {{ $web['total'] }} produse cu EAN internațional ({{ $web['pct'] }}%)</div>
                @if($web['enriched'] > 0)
                <a href="{{ route('filament.app.resources.woo-products.index', ['tableFilters' => ['web_enriched' => ['value' => '1']]]) }}"
                   style="font-size:0.75rem;color:#ea580c;text-decoration:none;font-weight:600;white-space:nowrap;">
                    Vezi toate →
                </a>
                @endif
            </div>
        </div>

        <div style="margin-bottom:1rem;">
            <div style="background:#e5e7eb;border-radius:999px;height:7px;">
                <div style="background:#ea580c;height:100%;width:{{ $web['pct'] }}%;border-radius:999px;"></div>
            </div>
        </div>

        @if($recentWeb->isNotEmpty())
        <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.5rem;text-transform:uppercase;">Ultimele îmbogățite</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:0.6rem;">
            @foreach($recentWeb as $r)
            <a href="{{ route('filament.app.resources.woo-products.edit', $r->id) }}" style="text-decoration:none;display:block;">
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:0.5rem;padding:0.6rem 0.75rem;cursor:pointer;transition:border-color .15s;" onmouseover="this.style.borderColor='#ea580c'" onmouseout="this.style.borderColor='#fed7aa'">
                <div style="font-size:0.78rem;font-weight:600;color:#9a3412;margin-bottom:0.2rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $r->name }}">
                    {{ $r->name }}
                </div>
                @if($r->description)
                <div style="font-size:0.7rem;color:#374151;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                    {{ strip_tags($r->description) }}
                </div>
                @endif
                <div style="margin-top:0.3rem;font-size:0.68rem;color:#ea580c;">
                    {{ $r->attr_count }} atribute
                    &nbsp;·&nbsp;
                    {{ \Carbon\Carbon::parse($r->updated_at)->diffForHumans() }}
                </div>
            </div>
            </a>
            @endforeach
        </div>
        @else
        <div style="text-align:center;padding:1.5rem 0;color:#9ca3af;font-size:0.8rem;">
            Niciun produs îmbogățit încă. Rulați: <code>php artisan products:enrich-from-web</code>
        </div>
        @endif
    </div>

    {{-- ── Rând 4: Conținut + Atribute produse WooCommerce ───────────────── --}}
    <div style="margin-top:1.5rem;background:#fff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1.25rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <div style="font-size:0.9rem;font-weight:600;color:#111827;">Conținut produse WooCommerce</div>
            <div style="font-size:0.78rem;color:#9ca3af;">{{ $woo['total'] }} produse · {{ $woo['done_pct'] }}% evaluate</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.25rem;">

            {{-- Coloana 1: Texte --}}
            <div>
                <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.6rem;text-transform:uppercase;letter-spacing:.04em;">Texte</div>
                @foreach([
                    ['Short description', $woo['with_short'], $woo['total'], $woo['short_pct'], '#8b5cf6'],
                    ['Description completă', $woo['with_desc'], $woo['total'], $woo['desc_pct'], '#0891b2'],
                    ['Evaluate (orice câmp)', $woo['done'], $woo['total'], $woo['done_pct'], '#16a34a'],
                ] as [$lbl, $val, $tot, $pct, $clr])
                <div style="margin-bottom:0.75rem;">
                    <div style="display:flex;justify-content:space-between;font-size:0.76rem;color:#374151;margin-bottom:0.25rem;">
                        <span>{{ $lbl }}</span>
                        <span style="color:{{ $clr }};font-weight:600;">{{ number_format($val) }} <span style="color:#9ca3af;font-weight:400;">({{ $pct }}%)</span></span>
                    </div>
                    <div style="background:#e5e7eb;border-radius:999px;height:6px;">
                        <div style="background:{{ $clr }};height:100%;width:{{ $pct }}%;border-radius:999px;transition:width .3s;"></div>
                    </div>
                </div>
                @endforeach

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem;margin-top:0.75rem;">
                    <div style="background:#fef2f2;border-radius:0.5rem;padding:0.45rem 0.6rem;text-align:center;">
                        <div style="font-size:1rem;font-weight:700;color:#dc2626;">{{ number_format($woo['needs_attention']) }}</div>
                        <div style="font-size:0.65rem;color:#6b7280;">Necesită atenție</div>
                    </div>
                    <div style="background:#f0fdf4;border-radius:0.5rem;padding:0.45rem 0.6rem;text-align:center;">
                        <div style="font-size:1rem;font-weight:700;color:#16a34a;">{{ number_format($woo['done']) }}</div>
                        <div style="font-size:0.65rem;color:#6b7280;">Evaluate OK</div>
                    </div>
                </div>
            </div>

            {{-- Coloana 2: Atribute --}}
            <div>
                <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.6rem;text-transform:uppercase;letter-spacing:.04em;">Atribute tehnice</div>
                @foreach([
                    ['Cu atribute (orice sursă)', $woo['with_any_attr'], $woo['total'], $woo['attr_pct'], '#d97706'],
                    ['Din WooCommerce nativ', $woo['with_attr_woo'], $woo['total'], $woo['total'] > 0 ? round($woo['with_attr_woo']/$woo['total']*100) : 0, '#2563eb'],
                    ['Generate de Claude', $woo['with_attr_gen'], $woo['total'], $woo['total'] > 0 ? round($woo['with_attr_gen']/$woo['total']*100) : 0, '#7c3aed'],
                ] as [$lbl, $val, $tot, $pct, $clr])
                <div style="margin-bottom:0.75rem;">
                    <div style="display:flex;justify-content:space-between;font-size:0.76rem;color:#374151;margin-bottom:0.25rem;">
                        <span>{{ $lbl }}</span>
                        <span style="color:{{ $clr }};font-weight:600;">{{ number_format($val) }} <span style="color:#9ca3af;font-weight:400;">({{ $pct }}%)</span></span>
                    </div>
                    <div style="background:#e5e7eb;border-radius:999px;height:6px;">
                        <div style="background:{{ $clr }};height:100%;width:{{ $pct }}%;border-radius:999px;transition:width .3s;"></div>
                    </div>
                </div>
                @endforeach

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem;margin-top:0.75rem;">
                    <div style="background:#fffbeb;border-radius:0.5rem;padding:0.45rem 0.6rem;text-align:center;">
                        <div style="font-size:1rem;font-weight:700;color:#2563eb;">{{ number_format($woo['total_attr_woo']) }}</div>
                        <div style="font-size:0.65rem;color:#6b7280;">Atribute Woo</div>
                    </div>
                    <div style="background:#f5f3ff;border-radius:0.5rem;padding:0.45rem 0.6rem;text-align:center;">
                        <div style="font-size:1rem;font-weight:700;color:#7c3aed;">{{ number_format($woo['total_attr_gen']) }}</div>
                        <div style="font-size:0.65rem;color:#6b7280;">Atribute generate</div>
                    </div>
                </div>
            </div>

            {{-- Coloana 3: Ultimele evaluate --}}
            <div>
                <div style="font-size:0.72rem;font-weight:600;color:#9ca3af;margin-bottom:0.5rem;text-transform:uppercase;letter-spacing:.04em;">Ultimele evaluate</div>
                @forelse($recentWoo as $r)
                <div style="margin-bottom:0.45rem;padding:0.4rem 0.55rem;background:#f5f3ff;border-radius:0.5rem;border-left:3px solid #8b5cf6;">
                    <div style="font-size:0.73rem;font-weight:600;color:#4c1d95;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $r->name }}">{{ $r->name }}</div>
                    <div style="font-size:0.68rem;color:#6b7280;margin-top:0.1rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;">{{ $r->short_description }}</div>
                    @if(isset($r->attr_count) && $r->attr_count > 0)
                    <div style="font-size:0.65rem;color:#8b5cf6;margin-top:0.1rem;">{{ $r->attr_count }} atribute</div>
                    @endif
                </div>
                @empty
                <div style="color:#9ca3af;font-size:0.78rem;padding-top:0.5rem;">În curs de evaluare...</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Refresh note ──────────────────────────────────────────────────── --}}
    <div style="margin-top:1rem;text-align:right;font-size:0.72rem;color:#9ca3af;">
        Se actualizează automat la fiecare 8 secunde &nbsp;·&nbsp; {{ now()->format('H:i:s') }}
    </div>

</x-filament-panels::page>
