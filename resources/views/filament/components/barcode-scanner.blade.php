{{-- Buton scanare cod de bare (doar pe mobil) --}}
<div
    class="barcode-scanner-mobile"
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

        // stari EAN negasit
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
                this.errorMessage = 'Camera nu este disponibila pe acest browser.';
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
                this.errorMessage = 'Camera indisponibila: ' + err.message;
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

            this.statusMessage = 'Se focalizeaza camera...';
            await new Promise(r => setTimeout(r, 1000));
            if (!this.isOpen) return;

            this.statusMessage = 'Indreapta camera spre codul de bare...';

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
                this.errorMessage = 'Libraria nu s-a incarcat. Reincarca pagina.';
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
            this.statusMessage = 'Se verifica codul...';

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
                // fallback daca AJAX pica
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
                    alert('Eroare la trimiterea cererii. Incearca din nou.');
                }
            } catch (_) {
                alert('Eroare de retea. Incearca din nou.');
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
    {{-- Buton camera --}}
    <button
        type="button"
        @click.prevent="open()"
        style="display:flex; align-items:center; justify-content:center; width:2.25rem; height:2.25rem; border-radius:0.5rem; color:#6b7280; background:transparent; border:none; cursor:pointer; transition:background 0.15s;"
        title="Scaneaza cod de bare"
    >
        <x-filament::icon icon="heroicon-o-camera" style="width:1.25rem; height:1.25rem;" />
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
            style="display:none; position:fixed; inset:0; z-index:9999; flex-direction:column;"
            x-bind:style="(detected || notFound || associating || submitted) ? 'display:flex; position:fixed; inset:0; z-index:9999; flex-direction:column; background:#fff;' : 'display:flex; position:fixed; inset:0; z-index:9999; flex-direction:column; background:#000;'"
        >
            {{-- Header --}}
            <div style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem 1rem; flex-shrink:0;"
                x-bind:style="(detected || notFound || associating || submitted) ? 'display:flex; align-items:center; justify-content:space-between; padding:0.75rem 1rem; flex-shrink:0; background:#fff; border-bottom:1px solid #e5e7eb;' : 'display:flex; align-items:center; justify-content:space-between; padding:0.75rem 1rem; flex-shrink:0; background:#111827;'">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <x-filament::icon icon="heroicon-o-camera" style="width:1.25rem; height:1.25rem;"
                        x-bind:style="detected ? 'width:1.25rem; height:1.25rem; color:#16a34a;' : (notFound || submitted) ? 'width:1.25rem; height:1.25rem; color:#9ca3af;' : 'width:1.25rem; height:1.25rem; color:#f87171;'" />
                    <span style="font-weight:600; font-size:0.875rem;"
                        x-bind:style="(detected || notFound || associating || submitted) ? 'font-weight:600; font-size:0.875rem; color:#111827;' : 'font-weight:600; font-size:0.875rem; color:#fff;'"
                        x-text="associating ? 'Asociaza EAN la produs' : (submitted ? 'Cerere trimisa' : 'Scaneaza cod de bare')">
                    </span>
                </div>
                <div style="display:flex; align-items:center; gap:0.25rem;">
                    <button x-show="torchSupported && !detected && !notFound" type="button" @click.prevent="toggleTorch()"
                        style="display:flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:0.5rem; border:none; background:transparent; cursor:pointer; transition:color 0.15s;"
                        x-bind:style="torchOn ? 'display:flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:0.5rem; border:none; background:transparent; cursor:pointer; color:#facc15;' : 'display:flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:0.5rem; border:none; background:transparent; cursor:pointer; color:#9ca3af;'"
                        title="Lanterna">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:18px;height:18px;">
                            <path d="M17 4H7l-2 7h5v9l7-11h-5l3-5z"/>
                        </svg>
                    </button>
                    <button type="button" @click.prevent="close()"
                        style="display:flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:0.5rem; border:none; background:transparent; cursor:pointer;"
                        x-bind:style="(detected || notFound || associating || submitted) ? 'display:flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:0.5rem; border:none; background:transparent; cursor:pointer; color:#9ca3af;' : 'display:flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:0.5rem; border:none; background:transparent; cursor:pointer; color:#9ca3af;'">
                        <x-filament::icon icon="heroicon-o-x-mark" style="width:1.25rem; height:1.25rem;" />
                    </button>
                </div>
            </div>

            {{-- 1 Ecran confirmare produs gasit (verde) --}}
            <div x-show="detected" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1rem; background:#fff;">
                <div style="width:72px;height:72px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:40px;height:40px;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div style="text-align:center; padding:0 1.5rem;">
                    <p style="color:#16a34a;font-weight:700;font-size:1.1rem;margin:0 0 4px;">Cod detectat</p>
                    <p x-text="detectedCode" style="color:#374151;font-family:monospace;font-size:0.95rem;margin:0 0 8px;"></p>
                    <p style="color:#9ca3af;font-size:0.8rem;margin:0;">Se incarca produsul...</p>
                </div>
            </div>

            {{-- 2 Ecran EAN negasit --}}
            <div x-show="notFound && !associating && !submitted" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1.25rem; padding:0 1.5rem; background:#fff;">
                <div style="width:72px;height:72px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:38px;height:38px;">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div style="text-align:center;">
                    <p style="color:#111827;font-weight:700;font-size:1.05rem;margin:0 0 6px;">EAN negasit in baza de date</p>
                    <p x-text="scannedEan" style="color:#6b7280;font-family:monospace;font-size:0.9rem;margin:0 0 4px;"></p>
                    <p style="color:#9ca3af;font-size:0.82rem;margin:0;">Vrei sa asociezi acest EAN unui produs existent?</p>
                </div>
                <div style="display:flex; flex-direction:column; gap:0.75rem; width:100%; max-width:280px;">
                    <button type="button" @click.prevent="startAssociation()"
                        style="width:100%; border-radius:0.75rem; padding:0.75rem; font-size:0.875rem; font-weight:600; color:#fff; background:#2563eb; border:none; cursor:pointer;">
                        Da, asociaza la un produs
                    </button>
                    <button type="button" @click.prevent="rescan()"
                        style="width:100%; border-radius:0.75rem; padding:0.75rem; font-size:0.875rem; font-weight:600; background:#f3f4f6; color:#374151; border:none; cursor:pointer;">
                        Scaneaza din nou
                    </button>
                </div>
            </div>

            {{-- 3 Ecran cautare produs pentru asociere --}}
            <div x-show="associating && !submitted" style="flex:1; display:flex; flex-direction:column; background:#fff; overflow:hidden;">
                <div style="padding:1rem 1rem 0.75rem; flex-shrink:0;">
                    <p style="font-size:0.82rem;color:#6b7280;margin:0 0 10px;">
                        EAN: <span x-text="scannedEan" style="font-family:monospace;color:#111827;font-weight:600;"></span>
                    </p>
                    <div style="display:flex; gap:0.5rem;">
                        <input
                            type="text"
                            x-model="searchQuery"
                            @keydown.enter.prevent="searchProducts()"
                            placeholder="Cauta dupa nume sau SKU..."
                            style="flex:1; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; min-width:0; outline:none;"
                        />
                        <button type="button" @click.prevent="searchProducts()"
                            style="border-radius:0.5rem; padding:0.5rem 1rem; font-size:0.875rem; font-weight:600; color:#fff; background:#2563eb; border:none; cursor:pointer; flex-shrink:0;"
                            x-bind:disabled="searchLoading">
                            <span x-show="!searchLoading">Cauta</span>
                            <span x-show="searchLoading">...</span>
                        </button>
                    </div>
                </div>

                {{-- Rezultate cautare --}}
                <div style="flex:1; overflow-y:auto; padding:0 1rem 1rem;">
                    <template x-if="searchLoading">
                        <p style="color:#9ca3af;font-size:0.85rem;text-align:center;padding:20px 0;">Se cauta...</p>
                    </template>
                    <template x-if="!searchLoading && searchResults.length === 0 && searchQuery.length >= 2">
                        <p style="color:#9ca3af;font-size:0.85rem;text-align:center;padding:20px 0;">Niciun produs gasit.</p>
                    </template>
                    <template x-for="product in searchResults" :key="product.id">
                        <div
                            @click.prevent="selectedProduct = product"
                            style="border-radius:0.75rem; border:1px solid #e5e7eb; margin-bottom:0.5rem; padding:0.75rem; cursor:pointer; transition:border-color 0.15s, background 0.15s;"
                            x-bind:style="selectedProduct && selectedProduct.id === product.id
                                ? 'border-radius:0.75rem; border:1px solid #2563eb; margin-bottom:0.5rem; padding:0.75rem; cursor:pointer; background:#eff6ff;'
                                : 'border-radius:0.75rem; border:1px solid #e5e7eb; margin-bottom:0.5rem; padding:0.75rem; cursor:pointer; background:#fff;'"
                        >
                            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:0.5rem;">
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
                <div x-show="selectedProduct" style="flex-shrink:0; padding:0.5rem 1rem 1rem; border-top:1px solid #f3f4f6;">
                    <div style="border-radius:0.75rem; padding:0.75rem; margin-bottom:0.75rem; background:#eff6ff;">
                        <p style="font-size:0.78rem;color:#1d4ed8;margin:0;">
                            EAN <span x-text="scannedEan" style="font-family:monospace;font-weight:700;"></span>
                            va fi asociat produsului <strong x-text="selectedProduct ? selectedProduct.name : ''"></strong>.
                            Un admin va trebui sa aprobe aceasta schimbare.
                        </p>
                    </div>
                    <button type="button" @click.prevent="submitAssociation()"
                        style="width:100%; border-radius:0.75rem; padding:0.75rem; font-size:0.875rem; font-weight:600; color:#fff; background:#2563eb; border:none; cursor:pointer;"
                        x-bind:disabled="submitting">
                        <span x-show="!submitting">Trimite cererea</span>
                        <span x-show="submitting">Se trimite...</span>
                    </button>
                </div>
            </div>

            {{-- 4 Ecran cerere trimisa cu succes --}}
            <div x-show="submitted" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1.25rem; padding:0 1.5rem; background:#fff;">
                <div style="width:72px;height:72px;border-radius:50%;background:#dbeafe;display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:38px;height:38px;">
                        <path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/>
                    </svg>
                </div>
                <div style="text-align:center;">
                    <p style="color:#111827;font-weight:700;font-size:1.05rem;margin:0 0 6px;">Cerere trimisa!</p>
                    <p style="color:#6b7280;font-size:0.85rem;margin:0 0 4px;">
                        Un administrator va verifica si va procesa asocierea EAN-ului.
                    </p>
                </div>
                <div style="display:flex; flex-direction:column; gap:0.75rem; width:100%; max-width:280px;">
                    <button type="button" @click.prevent="rescan()"
                        style="width:100%; border-radius:0.75rem; padding:0.75rem; font-size:0.875rem; font-weight:600; color:#fff; background:#2563eb; border:none; cursor:pointer;">
                        Scaneaza din nou
                    </button>
                    <button type="button" @click.prevent="close()"
                        style="width:100%; border-radius:0.75rem; padding:0.75rem; font-size:0.875rem; font-weight:600; background:#f3f4f6; color:#374151; border:none; cursor:pointer;">
                        Inchide
                    </button>
                </div>
            </div>

            {{-- 5 Camera (scanner activ) --}}
            <div x-show="!detected && !notFound && !associating && !submitted" style="flex:1; position:relative; min-height:0; overflow:hidden;">
                <video x-ref="video" playsinline muted style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block;"></video>

                <div style="position:absolute; inset:0; pointer-events:none; z-index:2;">
                    <div style="position:absolute; inset:0; bottom:calc(50% + 70px); background:rgba(0,0,0,0.45);"></div>
                    <div style="position:absolute; inset:0; top:calc(50% + 70px); background:rgba(0,0,0,0.45);"></div>
                    <div style="position:absolute; top:calc(50% - 70px); bottom:calc(50% - 70px); left:0; right:calc(50% + 140px); background:rgba(0,0,0,0.45);"></div>
                    <div style="position:absolute; top:calc(50% - 70px); bottom:calc(50% - 70px); right:0; left:calc(50% + 140px); background:rgba(0,0,0,0.45);"></div>

                    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:280px; height:140px;">
                        <div style="position:absolute;top:0;left:0;width:28px;height:28px;border-top:3px solid #ef4444;border-left:3px solid #ef4444;"></div>
                        <div style="position:absolute;top:0;right:0;width:28px;height:28px;border-top:3px solid #ef4444;border-right:3px solid #ef4444;"></div>
                        <div style="position:absolute;bottom:0;left:0;width:28px;height:28px;border-bottom:3px solid #ef4444;border-left:3px solid #ef4444;"></div>
                        <div style="position:absolute;bottom:0;right:0;width:28px;height:28px;border-bottom:3px solid #ef4444;border-right:3px solid #ef4444;"></div>
                        <div class="barcode-scan-line" style="position:absolute;left:8px;right:8px;height:2px;background:#ef4444;opacity:0.9;"></div>
                    </div>
                </div>
            </div>

            {{-- Status bar (doar cand camera e activa) --}}
            <div x-show="!detected && !notFound && !associating && !submitted"
                style="flex-shrink:0; text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.5rem; padding:1rem; background:#111827; min-height:76px;">
                <p x-show="statusMessage && !errorMessage" x-text="statusMessage" style="font-size:0.875rem; color:#d1d5db; margin:0;"></p>
                <p x-show="errorMessage" x-text="errorMessage" style="font-size:0.875rem; color:#f87171; font-weight:500; margin:0;"></p>
                <button x-show="errorMessage" type="button" @click.prevent="retry()"
                    style="font-size:0.75rem; color:#fff; border-radius:0.5rem; padding:0.25rem 0.75rem; background:#dc2626; border:none; cursor:pointer; margin-top:4px;">
                    Incearca din nou
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
.barcode-scanner-mobile { display: block; }
@media (min-width: 768px) {
    .barcode-scanner-mobile { display: none !important; }
}
</style>
