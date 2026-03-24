<x-filament-panels::page>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>

<style>
.ve-wrap { display: flex; gap: 16px; align-items: flex-start; }
.ve-canvas-col { flex: 0 0 auto; }
.ve-side-col { flex: 1; min-width: 260px; display: flex; flex-direction: column; gap: 12px; }
#ve-canvas-wrap {
    border: 2px solid #e5e7eb; border-radius: 12px;
    overflow: hidden; background: #f3f4f6; display: inline-block;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
}
.elem-pill {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 10px; border-radius: 8px; background: #f3f4f6;
    margin-bottom: 5px; font-size: 13px; cursor: pointer;
    border: 2px solid transparent; transition: border-color .12s;
    color: #374151;
}
.dark .elem-pill { background: #1f2937; color: #d1d5db; }
.elem-pill:hover { border-color: #d1d5db; }
.elem-pill.active { border-color: #6366f1; background: #eef2ff; }
.dark .elem-pill.active { background: #312e81; }
.swatch { width: 13px; height: 13px; border-radius: 3px; flex-shrink: 0; }
.coord-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 6px; }
.coord-grid label { font-size: 11px; color: #6b7280; display: block; margin-bottom: 2px; }
.coord-grid input {
    width: 100%; border: 1px solid #d1d5db; border-radius: 6px;
    padding: 4px 5px; font-size: 12px; text-align: center;
    background: white; color: #111;
}
.dark .coord-grid input { background: #374151; border-color: #4b5563; color: #f9fafb; }
</style>

<div
    x-data="veEditor()"
    x-init="init()"
    @template-loaded.window="onTemplateLoaded($event.detail)"
    class="flex flex-col gap-4"
>
    {{-- Header --}}
    <div class="flex flex-wrap gap-2 items-center justify-between">
        <div class="flex flex-wrap gap-2 items-center">
            <span class="text-sm font-medium text-gray-500">Template:</span>
            @foreach(\App\Models\GraphicTemplate::orderBy('layout')->orderBy('name')->get() as $tpl)
            <button
                wire:click="switchTemplate({{ $tpl->id }})"
                class="px-3 py-1.5 rounded-lg text-sm font-medium transition
                    {{ $templateId === $tpl->id
                        ? 'bg-primary-600 text-white'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700' }}">
                {{ $tpl->name }}
                <span class="opacity-50 text-xs">({{ $tpl->layout }})</span>
            </button>
            @endforeach
        </div>
        <div class="flex gap-2">
            <button @click="resetPositions()"
                class="px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm hover:bg-gray-200 transition">
                Reset layout
            </button>
            <button @click="saveAndPreview()" :disabled="saving"
                class="px-4 py-2 rounded-lg bg-primary-600 text-white text-sm font-semibold hover:bg-primary-700 transition disabled:opacity-60">
                <span x-show="!saving">Salvează & Preview</span>
                <span x-show="saving">Se renderează...</span>
            </button>
        </div>
    </div>

    <div class="ve-wrap">

        {{-- Canvas — wire:ignore previne Livewire să distrugă Fabric.js --}}
        <div class="ve-canvas-col" wire:ignore>
            <div id="ve-canvas-wrap">
                <canvas id="ve-canvas"></canvas>
            </div>
            <p class="text-xs text-gray-400 text-center mt-2">
                Drag = mută &nbsp;·&nbsp; Colțuri = resize &nbsp;·&nbsp; Click dreapta = aduce în față
            </p>
        </div>

        {{-- Panou lateral --}}
        <div class="ve-side-col">

            {{-- Elemente --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Elemente</h3>
                <template x-for="el in elements" :key="el.id">
                    <div class="elem-pill" :class="{active: selectedId === el.id}"
                         @click="selectEl(el.id)">
                        <span class="swatch" :style="{background: el.color}"></span>
                        <span x-text="el.label"></span>
                    </div>
                </template>
            </div>

            {{-- Coordonate --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4"
                 x-show="selectedId">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Poziție</h3>
                <div class="coord-grid">
                    <div><label>X</label>
                        <input type="number" :value="selPos.x" @change="setCoord('x', +$event.target.value)">
                    </div>
                    <div><label>Y</label>
                        <input type="number" :value="selPos.y" @change="setCoord('y', +$event.target.value)">
                    </div>
                    <div><label>W</label>
                        <input type="number" :value="selPos.w" @change="setCoord('w', +$event.target.value)">
                    </div>
                    <div><label>H</label>
                        <input type="number" :value="selPos.h" @change="setCoord('h', +$event.target.value)">
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Coordonate la 1080px</p>
            </div>

            {{-- Preview --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Preview</h3>
                    <a x-show="previewUrl" :href="previewUrl" target="_blank"
                       class="text-xs text-primary-600 hover:underline">Deschide →</a>
                </div>
                <div x-show="saving" class="text-xs text-center text-gray-400 animate-pulse py-8">
                    Se renderează imaginea...
                </div>
                <template x-if="previewUrl && !saving">
                    <img :src="previewUrl" class="w-full rounded-lg shadow" alt="preview">
                </template>
                <template x-if="!previewUrl && !saving">
                    <div class="aspect-square bg-gray-100 dark:bg-gray-800 rounded-lg flex items-center justify-center">
                        <p class="text-sm text-gray-400">Apasă "Salvează & Preview"</p>
                    </div>
                </template>
            </div>

        </div>
    </div>
</div>

<script>
const SCALE = 0.5;  // 540 / 1080
const CW = 1080 * SCALE;
const CH = 1080 * SCALE;

const ELEMENTS = [
    { id: 'malinco_logo',   label: 'Logo Malinco',              color: '#3b82f6', fill: 'rgba(59,130,246,0.25)',  stroke: '#3b82f6' },
    { id: 'visual_area',    label: 'Imagine produs / Brand',    color: '#f97316', fill: 'rgba(249,115,22,0.22)',  stroke: '#f97316' },
    { id: 'title_block',    label: 'Titlu principal',           color: '#111827', fill: 'rgba(15,23,42,0.18)',    stroke: '#111827' },
    { id: 'subtitle_block', label: 'Subtitlu',                  color: '#64748b', fill: 'rgba(100,116,139,0.22)', stroke: '#64748b' },
    { id: 'eyebrow_block',  label: 'Eyebrow label',             color: '#a52a3f', fill: 'rgba(165,42,63,0.28)',   stroke: '#a52a3f' },
    { id: 'cta_button',     label: 'Buton CTA',                 color: '#7c3aed', fill: 'rgba(124,58,237,0.30)',  stroke: '#7c3aed' },
    { id: 'bottom_bar',     label: 'Bara de jos',               color: '#1e293b', fill: 'rgba(30,41,59,0.40)',    stroke: '#1e293b' },
];

function defaultPositions() {
    const PAD      = Math.round(1080 * 0.058);
    const LOGO_H   = Math.round(1080 * 0.192);
    const BOT_H    = Math.round(1080 * 0.150);
    const CONT_Y   = LOGO_H;
    const CONT_H   = 1080 - LOGO_H - BOT_H - 10;
    const IMG_W    = Math.round(1080 * 0.44);
    const IMG_X    = 1080 - IMG_W - Math.round(PAD * 0.3);
    const TXT_W    = IMG_X - PAD - Math.round(PAD * 0.5);
    const ROW_H    = Math.round(1080 * 0.075);
    const ROW_Y    = CONT_Y + CONT_H - ROW_H - Math.round(1080 * 0.022);
    const LOGO_W   = Math.round(1080 * 0.36);
    const CTA_W    = Math.round(1080 * 0.31);

    return {
        malinco_logo:   { x: Math.round((1080-LOGO_W)/2), y: 10,                                   w: LOGO_W, h: LOGO_H-20 },
        visual_area:    { x: IMG_X,                        y: CONT_Y,                               w: IMG_W,  h: CONT_H },
        title_block:    { x: PAD+20,                       y: CONT_Y + Math.round(CONT_H*0.15),    w: TXT_W,  h: Math.round(CONT_H*0.35) },
        subtitle_block: { x: PAD+20,                       y: CONT_Y + Math.round(CONT_H*0.58),    w: TXT_W,  h: Math.round(CONT_H*0.15) },
        eyebrow_block:  { x: PAD+20,                       y: CONT_Y + Math.round(CONT_H*0.08),    w: Math.round(TXT_W*0.55), h: Math.round(1080*0.04) },
        cta_button:     { x: 1080-PAD-CTA_W,               y: ROW_Y,                               w: CTA_W,  h: ROW_H },
        bottom_bar:     { x: 0,                             y: 1080-BOT_H-10,                       w: 1080,   h: BOT_H },
    };
}

function veEditor() {
    return {
        fc: null,           // Fabric.Canvas
        fObjs: {},          // { id -> fabric.Group }
        elements: ELEMENTS,
        selectedId: null,
        positions: {},
        saving: false,
        previewUrl: '{{ $previewUrl ?? '' }}',

        get selPos() {
            const p = this.positions[this.selectedId];
            return p ? { x: Math.round(p.x), y: Math.round(p.y), w: Math.round(p.w), h: Math.round(p.h) } : {};
        },

        init() {
            this.fc = new fabric.Canvas('ve-canvas', {
                width: CW, height: CH,
                preserveObjectStacking: true,
                selection: false,
            });

            // Pozițiile inițiale din PHP
            const initJson = @json($positionsJson);
            try {
                const p = JSON.parse(initJson);
                this.positions = (p && Object.keys(p).length) ? p : defaultPositions();
            } catch(e) {
                this.positions = defaultPositions();
            }

            this.redraw();

            // Urmărește previewUrl din Livewire — mai fiabil decât events
            this.$watch('$wire.previewUrl', url => {
                if (!url) return;
                if (url === '__error__') { this.saving = false; return; }
                this.previewUrl = url;
                this.saving = false;
            });

            // Fallback suplimentar: polling pe proprietatea Livewire
            const autoSaveInterval = setInterval(() => {
                const url = $wire.get('previewUrl');
                if (url && url !== '__error__' && url !== this.previewUrl) {
                    this.previewUrl = url;
                    this.saving = false;
                }
            }, 1000);

            // Cleanup interval la navigare (previne memory leak)
            document.addEventListener('livewire:navigating', () => {
                clearInterval(autoSaveInterval);
            });

            this.fc.on('object:modified', e => this.onModified(e));
            this.fc.on('selection:created', e => this.onSelect(e));
            this.fc.on('selection:updated', e => this.onSelect(e));
            this.fc.on('selection:cleared', () => { this.selectedId = null; });

            // Context menu: bring forward / send backward
            document.getElementById('ve-canvas').addEventListener('contextmenu', e => {
                e.preventDefault();
                const o = this.fc.getActiveObject();
                if (!o) return;
                e.shiftKey ? this.fc.sendBackwards(o, true) : this.fc.bringForward(o, true);
                this.fc.renderAll();
            });
        },

        redraw() {
            this.fc.clear();

            // Background
            this.fc.add(new fabric.Rect({
                left:0, top:0, width:CW, height:CH,
                fill:'#F8F5F2', selectable:false, evented:false,
            }));

            this.fObjs = {};
            for (const el of ELEMENTS) {
                const pos = this.positions[el.id] || { x:0, y:0, w:100, h:60 };
                const x = pos.x * SCALE, y = pos.y * SCALE;
                const w = pos.w * SCALE, h = pos.h * SCALE;

                const rect = new fabric.Rect({
                    width: w, height: h,
                    fill: el.fill, stroke: el.stroke, strokeWidth: 1.5,
                    rx: 4, ry: 4,
                });
                const txt = new fabric.Text(el.label, {
                    left: 8, top: h/2 - 7,
                    fontSize: 11, fill: el.stroke,
                    fontFamily: 'system-ui, sans-serif', fontWeight: 'bold',
                    selectable: false, evented: false,
                });
                const grp = new fabric.Group([rect, txt], {
                    left: x, top: y,
                    lockRotation: true, hasRotatingPoint: false,
                    transparentCorners: false,
                    cornerColor: el.stroke, cornerSize: 8,
                });
                grp._elemId = el.id;
                this.fc.add(grp);
                this.fObjs[el.id] = grp;
            }
            this.fc.renderAll();
        },

        onModified(e) {
            const obj = e.target;
            if (!obj?._elemId) return;
            const br = obj.getBoundingRect();
            this.positions[obj._elemId] = {
                x: Math.round(br.left  / SCALE),
                y: Math.round(br.top   / SCALE),
                w: Math.round(br.width / SCALE),
                h: Math.round(br.height/ SCALE),
            };
        },

        onSelect(e) {
            this.selectedId = e.selected?.[0]?._elemId ?? null;
        },

        selectEl(id) {
            this.selectedId = id;
            const o = this.fObjs[id];
            if (o) { this.fc.setActiveObject(o); this.fc.renderAll(); }
        },

        setCoord(coord, val) {
            if (!this.selectedId) return;
            const pos = this.positions[this.selectedId] || {};
            pos[coord] = val;
            this.positions[this.selectedId] = pos;
            const o = this.fObjs[this.selectedId];
            if (!o) return;
            if (coord === 'x') o.set('left',  val * SCALE);
            if (coord === 'y') o.set('top',   val * SCALE);
            if (coord === 'w') o.set('width', val * SCALE);
            if (coord === 'h') o.set('height',val * SCALE);
            this.fc.renderAll();
        },

        resetPositions() {
            this.positions = defaultPositions();
            this.redraw();
        },

        saveAndPreview() {
            this.saving = true;
            $wire.savePositionsAndPreview(JSON.stringify(this.positions))
                .catch(() => { this.saving = false; });
            // Fallback: dacă după 30s nu a venit răspuns, resetăm
            setTimeout(() => { this.saving = false; }, 30000);
        },

        // Livewire events — NU re-randează canvas
        // switchTemplate — primim pozițiile prin event (canvas nu e re-randat de Livewire)
        onTemplateLoaded(detail) {
            try {
                const p = JSON.parse(detail.positionsJson || '{}');
                this.positions = (p && Object.keys(p).length) ? p : defaultPositions();
            } catch(e) {
                this.positions = defaultPositions();
            }
            this.previewUrl = detail.previewUrl || '';
            this.selectedId = null;
            this.redraw();
        },

        onPreviewUpdated(detail) {
            this.previewUrl = detail.url;
            this.saving = false;
        },
    };
}
</script>
</x-filament-panels::page>
