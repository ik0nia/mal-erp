{{-- Buton scanare cod de bare (doar pe mobil) --}}
<div
    class="md:hidden"
    x-data="{
        isOpen: false,
        found: false,
        detected: false,
        detectedCode: '',
        engine: null,
        statusMessage: '',
        errorMessage: '',
        stream: null,
        rafId: null,
        detector: null,
        zxingReader: null,
        torchSupported: false,
        torchOn: false,

        // stări EAN negăsit
        notFound: false,
        scannedEan: '',

        // stare asociere
        associating: false,
        searchQuery: '',
        searchResults: [],
        searchLoading: false,
        selectedProduct: null,
        submitting: false,
        submitted: false,

        open() {
            this.isOpen = true;
            this.found = false;
            this.detected = false;
            this.detectedCode = '';
            this.notFound = false;
            this.scannedEan = '';
            this.associating = false;
            this.searchQuery = '';
            this.searchResults = [];
            this.selectedProduct = null;
            this.submitting = false;
            this.submitted = false;
            this.engine = null;
            this.torchSupported = false;
            this.torchOn = false;
            this.statusMessage = 'Pornesc camera...';
            this.errorMessage = '';
            this.$nextTick(() => this.startScanner());
        },

        close() {
            this.stopScanner();
            this.isOpen = false;
        },

        async startScanner() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.errorMessage = 'Camera nu este disponibilă pe acest browser.';
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width:  { ideal: 1920 },
                        height: { ideal: 1080 },
                    }
                });
            } catch (err) {
                this.errorMessage = 'Camera indisponibilă: ' + err.message;
                return;
            }

            try {
                const track = this.stream.getVideoTracks()[0];
                const cap = track.getCapabilities ? track.getCapabilities() : {};
                this.torchSupported = !!cap.torch;
            } catch (_) {}

            const video = this.$refs.video;
            video.srcObject = this.stream;
            await video.play();

            this.statusMessage = 'Se focusează camera...';
            await new Promise(r => setTimeout(r, 1000));
            if (!this.isOpen) return;

            this.statusMessage = 'Îndreaptă camera spre codul de bare...';

            if (typeof BarcodeDetector !== 'undefined') {
                this.engine = 'native';
                this.detector = new BarcodeDetector({
                    formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e', 'itf', 'data_matrix']
                });
                this.scanLoopNative();

            } else if (typeof ZXing !== 'undefined') {
                this.engine = 'zxing';
                const hints = new Map([[ZXing.DecodeHintType.TRY_HARDER, true]]);
                this.zxingReader = new ZXing.BrowserMultiFormatReader(hints);
                this.scanLoopZxing();

            } else {
                this.errorMessage = 'Librăria nu s-a încărcat. Reîncarcă pagina.';
            }
        },

        toggleTorch() {
            if (!this.torchSupported || !this.stream) return;
            this.torchOn = !this.torchOn;
            const track = this.stream.getVideoTracks()[0];
            track.applyConstraints({ advanced: [{ torch: this.torchOn }] }).catch(() => {
                this.torchOn = false;
            });
        },

        scanLoopNative() {
            if (!this.isOpen || !this.stream || this.found) return;
            this.rafId = requestAnimationFrame(async () => {
                const video = this.$refs.video;
                if (video && video.readyState >= 2) {
                    try {
                        const codes = await this.detector.detect(video);
                        if (codes.length > 0 && !this.found) {
                            this.found = true;
                            this.onScanSuccess(codes[0].rawValue);
                            return;
                        }
                    } catch (_) {}
                }
                this.scanLoopNative();
            });
        },

        scanLoopZxing() {
            if (!this.isOpen || !this.stream || this.found) return;
            this.rafId = requestAnimationFrame(() => {
                const video = this.$refs.video;
                if (video && video.readyState >= 2) {
                    try {
                        const result = this.zxingReader.decode(video);
                        if (result && !this.found) {
                            this.found = true;
                            this.onScanSuccess(result.getText());
                            return;
                        }
                    } catch (_) {}
                }
                this.scanLoopZxing();
            });
        },

        stopScanner() {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
            this.detector = null;
            if (this.zxingReader) {
                try { this.zxingReader.reset(); } catch (_) {}
                this.zxingReader = null;
            }
            if (this.stream) {
                this.stream.getTracks().forEach(t => t.stop());
                this.stream = null;
            }
            const video = this.$refs.video;
            if (video) video.srcObject = null;
            this.engine = null;
        },

        async onScanSuccess(code) {
            this.stopScanner();
            this.scannedEan = code.trim();
            this.statusMessage = 'Se verifică codul...';

            try {
                const resp = await fetch('/sku-check/' + encodeURIComponent(code.trim()), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await resp.json();

                if (data.found) {
                    this.detectedCode = code.trim();
                    this.detected = true;
                    setTimeout(() => { window.location.href = data.url; }, 1000);
                } else {
                    this.notFound = true;
                }
            } catch (e) {
                // fallback dacă AJAX pică
                this.detectedCode = code.trim();
                this.detected = true;
                setTimeout(() => {
                    window.location.href = '/sku/' + encodeURIComponent(code.trim());
                }, 1000);
            }
        },

        startAssociation() {
            this.associating = true;
        },

        async searchProducts() {
            if (this.searchQuery.trim().length < 2) return;
            this.searchLoading = true;
            this.searchResults = [];
            this.selectedProduct = null;
            try {
                const resp = await fetch('/products/search?q=' + encodeURIComponent(this.searchQuery.trim()), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                this.searchResults = await resp.json();
            } catch (_) {}
            this.searchLoading = false;
        },

        async submitAssociation() {
            if (!this.selectedProduct || this.submitting) return;
            this.submitting = true;
            try {
                const csrf = document.querySelector('meta[name=csrf-token]');
                const resp = await fetch('/ean-association', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf ? csrf.content : '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        ean: this.scannedEan,
                        woo_product_id: this.selectedProduct.id,
                    }),
                });
                if (resp.ok) {
                    this.submitted = true;
                } else {
                    alert('Eroare la trimiterea cererii. Încearcă din nou.');
                }
            } catch (_) {
                alert('Eroare de rețea. Încearcă din nou.');
            }
            this.submitting = false;
        },

        rescan() {
            this.notFound = false;
            this.associating = false;
            this.searchQuery = '';
            this.searchResults = [];
            this.selectedProduct = null;
            this.submitting = false;
            this.submitted = false;
            this.scannedEan = '';
            this.found = false;
            this.open();
        },

        retry() {
            this.found = false;
            this.detected = false;
            this.detectedCode = '';
            this.errorMessage = '';
            this.statusMessage = 'Pornesc camera...';
            this.$nextTick(() => this.startScanner());
        }
    }"
    @keydown.escape.window="if (isOpen) close()"
>
    {{-- Buton cameră --}}
    <button
        type="button"
        @click.prevent="open()"
        class="flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition-colors"
        title="Scanează cod de bare"
    >
        <x-heroicon-o-camera class="w-5 h-5" />
    </button>

    <template x-teleport="body">
        <div
            x-show="isOpen"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            style="display:none;"
            x-bind:style="(detected || notFound || associating || submitted) ? 'background:#fff;' : 'background:#000;'"
            class="fixed inset-0 z-[9999] flex flex-col"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 flex-shrink-0"
                x-bind:style="(detected || notFound || associating || submitted) ? 'background:#fff; border-bottom:1px solid #e5e7eb;' : 'background:#111827;'">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-camera class="w-5 h-5"
                        x-bind:class="detected ? 'text-green-600' : (notFound || submitted) ? 'text-gray-400' : 'text-red-400'" />
                    <span class="font-semibold text-sm"
                        x-bind:style="(detected || notFound || associating || submitted) ? 'color:#111827;' : 'color:#fff;'"
                        x-text="associating ? 'Asociază EAN la produs' : (submitted ? 'Cerere trimisă' : 'Scanează cod de bare')">
                    </span>
                </div>
                <div class="flex items-center gap-1">
                    <button x-show="torchSupported && !detected && !notFound" type="button" @click.prevent="toggleTorch()"
                        class="flex items-center justify-center w-8 h-8 rounded-lg transition-colors"
                        x-bind:class="torchOn ? 'text-yellow-400' : 'text-gray-400 hover:text-white'"
                        title="Lanternă">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:18px;height:18px;">
                            <path d="M17 4H7l-2 7h5v9l7-11h-5l3-5z"/>
                        </svg>
                    </button>
                    <button type="button" @click.prevent="close()"
                        class="flex items-center justify-center w-8 h-8 rounded-lg"
                        x-bind:class="(detected || notFound || associating || submitted) ? 'text-gray-400 hover:text-gray-700' : 'text-gray-400 hover:text-white'">
                        <x-heroicon-o-x-mark class="w-5 h-5" />
                    </button>
                </div>
            </div>

            {{-- ① Ecran confirmare produs găsit (verde) --}}
            <div x-show="detected" class="flex-1 flex flex-col items-center justify-center gap-4" style="background:#fff;">
                <div style="width:72px;height:72px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:40px;height:40px;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div class="text-center px-6">
                    <p style="color:#16a34a;font-weight:700;font-size:1.1rem;margin:0 0 4px;">Cod detectat</p>
                    <p x-text="detectedCode" style="color:#374151;font-family:monospace;font-size:0.95rem;margin:0 0 8px;"></p>
                    <p style="color:#9ca3af;font-size:0.8rem;margin:0;">Se încarcă produsul...</p>
                </div>
            </div>

            {{-- ② Ecran EAN negăsit --}}
            <div x-show="notFound && !associating && !submitted" class="flex-1 flex flex-col items-center justify-center gap-5 px-6" style="background:#fff;">
                <div style="width:72px;height:72px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:38px;height:38px;">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div class="text-center">
                    <p style="color:#111827;font-weight:700;font-size:1.05rem;margin:0 0 6px;">EAN negăsit în baza de date</p>
                    <p x-text="scannedEan" style="color:#6b7280;font-family:monospace;font-size:0.9rem;margin:0 0 4px;"></p>
                    <p style="color:#9ca3af;font-size:0.82rem;margin:0;">Vrei să asociezi acest EAN unui produs existent?</p>
                </div>
                <div class="flex flex-col gap-3 w-full" style="max-width:280px;">
                    <button type="button" @click.prevent="startAssociation()"
                        class="w-full rounded-xl py-3 text-sm font-semibold text-white"
                        style="background:#2563eb;">
                        Da, asociează la un produs
                    </button>
                    <button type="button" @click.prevent="rescan()"
                        class="w-full rounded-xl py-3 text-sm font-semibold"
                        style="background:#f3f4f6;color:#374151;">
                        Scanează din nou
                    </button>
                </div>
            </div>

            {{-- ③ Ecran căutare produs pentru asociere --}}
            <div x-show="associating && !submitted" class="flex-1 flex flex-col" style="background:#fff; overflow:hidden;">
                <div class="px-4 pt-4 pb-3 flex-shrink-0">
                    <p style="font-size:0.82rem;color:#6b7280;margin:0 0 10px;">
                        EAN: <span x-text="scannedEan" style="font-family:monospace;color:#111827;font-weight:600;"></span>
                    </p>
                    <div class="flex gap-2">
                        <input
                            type="text"
                            x-model="searchQuery"
                            @keydown.enter.prevent="searchProducts()"
                            placeholder="Caută după nume sau SKU..."
                            class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-500"
                            style="min-width:0;"
                        />
                        <button type="button" @click.prevent="searchProducts()"
                            class="rounded-lg px-4 py-2 text-sm font-semibold text-white flex-shrink-0"
                            style="background:#2563eb;"
                            x-bind:disabled="searchLoading">
                            <span x-show="!searchLoading">Caută</span>
                            <span x-show="searchLoading">...</span>
                        </button>
                    </div>
                </div>

                {{-- Rezultate căutare --}}
                <div class="flex-1 overflow-y-auto px-4 pb-4">
                    <template x-if="searchLoading">
                        <p style="color:#9ca3af;font-size:0.85rem;text-align:center;padding:20px 0;">Se caută...</p>
                    </template>
                    <template x-if="!searchLoading && searchResults.length === 0 && searchQuery.length >= 2">
                        <p style="color:#9ca3af;font-size:0.85rem;text-align:center;padding:20px 0;">Niciun produs găsit.</p>
                    </template>
                    <template x-for="product in searchResults" :key="product.id">
                        <div
                            @click.prevent="selectedProduct = product"
                            class="rounded-xl border mb-2 px-3 py-3 cursor-pointer transition-colors"
                            x-bind:style="selectedProduct && selectedProduct.id === product.id
                                ? 'border-color:#2563eb;background:#eff6ff;'
                                : 'border-color:#e5e7eb;background:#fff;'"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <p x-text="product.name" style="font-size:0.85rem;font-weight:600;color:#111827;margin:0;line-height:1.35;"></p>
                                <template x-if="selectedProduct && selectedProduct.id === product.id">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#2563eb" style="width:18px;height:18px;flex-shrink:0;">
                                        <path fill-rule="evenodd" d="M2.25 12a9.75 9.75 0 1119.5 0 9.75 9.75 0 01-19.5 0zm13.28-2.47a.75.75 0 00-1.06-1.06l-4.5 4.5-1.5-1.5a.75.75 0 00-1.06 1.06l2.03 2.03a.75.75 0 001.06 0l5.03-5.03z" clip-rule="evenodd"/>
                                    </svg>
                                </template>
                            </div>
                            <p x-text="'SKU: ' + product.sku" style="font-size:0.75rem;color:#6b7280;font-family:monospace;margin:2px 0 0;"></p>
                        </div>
                    </template>
                </div>

                {{-- Footer cu butoane confirmare --}}
                <div x-show="selectedProduct" class="flex-shrink-0 px-4 pb-4 pt-2 border-t border-gray-100">
                    <div class="rounded-xl p-3 mb-3" style="background:#eff6ff;">
                        <p style="font-size:0.78rem;color:#1d4ed8;margin:0;">
                            EAN <span x-text="scannedEan" style="font-family:monospace;font-weight:700;"></span>
                            va fi asociat produsului <strong x-text="selectedProduct ? selectedProduct.name : ''"></strong>.
                            Un admin va trebui să aprobe această schimbare.
                        </p>
                    </div>
                    <button type="button" @click.prevent="submitAssociation()"
                        class="w-full rounded-xl py-3 text-sm font-semibold text-white"
                        style="background:#2563eb;"
                        x-bind:disabled="submitting">
                        <span x-show="!submitting">Trimite cererea</span>
                        <span x-show="submitting">Se trimite...</span>
                    </button>
                </div>
            </div>

            {{-- ④ Ecran cerere trimisă cu succes --}}
            <div x-show="submitted" class="flex-1 flex flex-col items-center justify-center gap-5 px-6" style="background:#fff;">
                <div style="width:72px;height:72px;border-radius:50%;background:#dbeafe;display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:38px;height:38px;">
                        <path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/>
                    </svg>
                </div>
                <div class="text-center">
                    <p style="color:#111827;font-weight:700;font-size:1.05rem;margin:0 0 6px;">Cerere trimisă!</p>
                    <p style="color:#6b7280;font-size:0.85rem;margin:0 0 4px;">
                        Un administrator va verifica și va procesa asocierea EAN-ului.
                    </p>
                </div>
                <div class="flex flex-col gap-3 w-full" style="max-width:280px;">
                    <button type="button" @click.prevent="rescan()"
                        class="w-full rounded-xl py-3 text-sm font-semibold text-white"
                        style="background:#2563eb;">
                        Scanează din nou
                    </button>
                    <button type="button" @click.prevent="close()"
                        class="w-full rounded-xl py-3 text-sm font-semibold"
                        style="background:#f3f4f6;color:#374151;">
                        Închide
                    </button>
                </div>
            </div>

            {{-- ⑤ Camera (scanner activ) --}}
            <div x-show="!detected && !notFound && !associating && !submitted" class="flex-1 relative" style="min-height:0; overflow:hidden;">
                <video x-ref="video" playsinline muted class="absolute inset-0 w-full h-full" style="object-fit:cover; display:block;"></video>

                <div class="absolute inset-0 pointer-events-none" style="z-index:2;">
                    <div class="absolute inset-x-0 top-0" style="bottom:calc(50% + 70px); background:rgba(0,0,0,0.45);"></div>
                    <div class="absolute inset-x-0 bottom-0" style="top:calc(50% + 70px); background:rgba(0,0,0,0.45);"></div>
                    <div class="absolute" style="top:calc(50% - 70px); bottom:calc(50% - 70px); left:0; right:calc(50% + 140px); background:rgba(0,0,0,0.45);"></div>
                    <div class="absolute" style="top:calc(50% - 70px); bottom:calc(50% - 70px); right:0; left:calc(50% + 140px); background:rgba(0,0,0,0.45);"></div>

                    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:280px; height:140px;">
                        <div style="position:absolute;top:0;left:0;width:28px;height:28px;border-top:3px solid #ef4444;border-left:3px solid #ef4444;"></div>
                        <div style="position:absolute;top:0;right:0;width:28px;height:28px;border-top:3px solid #ef4444;border-right:3px solid #ef4444;"></div>
                        <div style="position:absolute;bottom:0;left:0;width:28px;height:28px;border-bottom:3px solid #ef4444;border-left:3px solid #ef4444;"></div>
                        <div style="position:absolute;bottom:0;right:0;width:28px;height:28px;border-bottom:3px solid #ef4444;border-right:3px solid #ef4444;"></div>
                        <div class="barcode-scan-line" style="position:absolute;left:8px;right:8px;height:2px;background:#ef4444;opacity:0.9;"></div>
                    </div>
                </div>
            </div>

            {{-- Status bar (doar când camera e activă) --}}
            <div x-show="!detected && !notFound && !associating && !submitted"
                class="flex-shrink-0 text-center flex flex-col items-center justify-center gap-2 py-4 px-4"
                style="background:#111827; min-height:76px;">
                <p x-show="statusMessage && !errorMessage" x-text="statusMessage" class="text-sm text-gray-300 m-0"></p>
                <p x-show="errorMessage" x-text="errorMessage" class="text-sm text-red-400 font-medium m-0"></p>
                <button x-show="errorMessage" type="button" @click.prevent="retry()"
                    class="text-xs text-white rounded-lg px-3 py-1" style="background:#dc2626; border:none; cursor:pointer; margin-top:4px;">
                    Încearcă din nou
                </button>
            </div>
        </div>
    </template>
</div>

<style>
@keyframes barcode-scan {
    0%   { top: 8px; }
    50%  { top: calc(100% - 8px); }
    100% { top: 8px; }
}
.barcode-scan-line { animation: barcode-scan 2.2s ease-in-out infinite; }
</style>
