(function () {
    if (window.__samedayCustomMap) return;
    window.__samedayCustomMap = true;

    var map = null, cluster = null, lockers = [], loaded = false, activeBtn = null;

    // ── Overlay creat o singură dată, refolosit de toate modalele ──────────────
    function ensureOverlay() {
        if (document.getElementById('sameday-map-overlay')) return;

        var overlay = document.createElement('div');
        overlay.id = 'sameday-map-overlay';
        overlay.style.cssText = 'display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,.55);';
        overlay.innerHTML =
            '<div style="position:absolute; inset:4%; background:#fff; border-radius:14px; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 24px 70px rgba(0,0,0,.4);">'
            + '<div style="position:relative; z-index:1200; display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#fff;">'
            + '<strong style="font-size:15px; white-space:nowrap;">Alege căsuța Easybox</strong>'
            + '<div style="position:relative; flex:1;">'
            + '<input id="sameday-map-search" type="text" placeholder="Caută după oraș, nume sau adresă… (ex: Oradea, Penny)" autocomplete="off" '
            + 'style="width:100%; border:1.5px solid #d1d5db; border-radius:8px; padding:8px 12px; font-size:14px; outline:none;">'
            + '<div id="sameday-map-results" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:10000; background:#fff; border:1px solid #e5e7eb; border-radius:8px; margin-top:4px; max-height:260px; overflow:auto; box-shadow:0 8px 24px rgba(0,0,0,.12);"></div>'
            + '</div>'
            + '<span id="sameday-map-count" style="font-size:12px; color:#6b7280; white-space:nowrap;"></span>'
            + '<button type="button" onclick="samedayCloseLockerMap()" style="border:none; background:#f3f4f6; border-radius:8px; padding:8px 14px; font-weight:700; cursor:pointer;">✕ Închide</button>'
            + '</div>'
            + '<div id="sameday-map" style="flex:1;"></div>'
            + '</div>';
        document.body.appendChild(overlay);
    }

    function loadAsset(tag, attrs) {
        return new Promise(function (resolve, reject) {
            var el = document.createElement(tag);
            Object.keys(attrs).forEach(function (k) { el[k] = attrs[k]; });
            el.onload = resolve;
            el.onerror = reject;
            document.head.appendChild(el);
        });
    }

    function norm(s) {
        return (s || '').toString().toLowerCase()
            .replace(/[ăâ]/g, 'a').replace(/[î]/g, 'i').replace(/[șş]/g, 's').replace(/[țţ]/g, 't');
    }

    // Calea de stare Livewire diferă între pagină (data.*) și modal Action
    // (mountedActions.N.data.*) — o citim din componenta Filament a câmpului.
    function fieldState(fieldName) {
        if (!fieldName) return null;
        var els = document.querySelectorAll('[x-data*="filamentSchemaComponent"]');
        var found = null;
        els.forEach(function (el) {
            var m = (el.getAttribute('x-data') || '').match(/path:\s*'([^']+)'/);
            if (m && (m[1] === 'data.' + fieldName || m[1].endsWith('.data.' + fieldName))) {
                found = { el: el, path: m[1] }; // ultimul găsit = modalul deschis peste pagină
            }
        });
        return found;
    }

    function selectLocker(l) {
        var lockerField = (activeBtn && activeBtn.dataset.lockerField) || 'locker_last_mile';
        var serviceField = (activeBtn && activeBtn.dataset.serviceField) || '';

        var locker = fieldState(lockerField);
        var service = serviceField ? fieldState(serviceField) : null;

        if (locker && window.Livewire) {
            var root = locker.el.closest('[wire\\:id]');
            var component = root ? window.Livewire.find(root.getAttribute('wire:id')) : null;
            if (component) {
                component.set(locker.path, String(l.locker_id));
                if (service) {
                    component.set(service.path, 15); // Locker NextDay
                }
            }
        }
        samedayCloseLockerMap();
    }

    window.__samedaySelectLocker = function (id) {
        var l = lockers.find(function (x) { return String(x.locker_id) === String(id); });
        if (l) selectLocker(l);
    };

    function popupHtml(l) {
        return '<div style="min-width:190px"><strong>' + l.name + '</strong><br>'
            + (l.address || '') + '<br>' + (l.city || '') + ' (' + (l.county || '') + ')<br>'
            + '<button type="button" onclick="__samedaySelectLocker(' + l.locker_id + ')" '
            + 'style="margin-top:8px; background:#d42b2b; color:#fff; border:none; border-radius:6px; padding:6px 14px; font-weight:700; cursor:pointer;">'
            + 'Alege această căsuță</button></div>';
    }

    async function ensureMap() {
        if (loaded) return;
        await loadAsset('link', { rel: 'stylesheet', href: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css' });
        await loadAsset('link', { rel: 'stylesheet', href: 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css' });
        await loadAsset('link', { rel: 'stylesheet', href: 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css' });
        await loadAsset('script', { src: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js' });
        await loadAsset('script', { src: 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js' });

        var resp = await fetch('/sameday-lockers.json', { credentials: 'same-origin' });
        lockers = await resp.json();

        map = L.map('sameday-map').setView([45.94, 24.97], 7); // centrul României
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        cluster = L.markerClusterGroup({ chunkedLoading: true, maxClusterRadius: 55 });
        lockers.forEach(function (l) {
            var m = L.marker([parseFloat(l.lat), parseFloat(l.lng)]);
            m.bindPopup(popupHtml(l));
            l.__marker = m;
            cluster.addLayer(m);
        });
        map.addLayer(cluster);

        document.getElementById('sameday-map-count').textContent = lockers.length + ' căsuțe';

        var search = document.getElementById('sameday-map-search');
        var results = document.getElementById('sameday-map-results');
        search.addEventListener('input', function () {
            var q = norm(search.value.trim());
            if (q.length < 2) { results.style.display = 'none'; return; }
            var found = lockers.filter(function (l) {
                return norm(l.name).includes(q) || norm(l.city).includes(q) || norm(l.address).includes(q) || norm(l.county).includes(q);
            }).slice(0, 30);
            results.innerHTML = found.length
                ? found.map(function (l) {
                    return '<div onclick="__samedayFocusLocker(' + l.locker_id + ')" '
                        + 'style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #f3f4f6; font-size:13px;" '
                        + 'onmouseover="this.style.background=\'#fef2f2\'" onmouseout="this.style.background=\'#fff\'">'
                        + '<strong>' + l.name + '</strong> — ' + (l.address || '') + ', ' + (l.city || '') + ' (' + (l.county || '') + ')</div>';
                }).join('')
                : '<div style="padding:10px 12px; color:#6b7280; font-size:13px;">Nicio căsuță găsită.</div>';
            results.style.display = 'block';
        });
        document.addEventListener('click', function (e) {
            if (!results.contains(e.target) && e.target !== search) results.style.display = 'none';
        });

        window.__samedayFocusLocker = function (id) {
            var l = lockers.find(function (x) { return String(x.locker_id) === String(id); });
            if (!l) return;
            results.style.display = 'none';
            map.setView([parseFloat(l.lat), parseFloat(l.lng)], 16);
            cluster.zoomToShowLayer(l.__marker, function () { l.__marker.openPopup(); });
        };

        loaded = true;
    }

    window.samedayOpenLockerMap = async function (btn) {
        activeBtn = btn || null;
        ensureOverlay();
        var overlay = document.getElementById('sameday-map-overlay');

        // Mut overlay-ul ÎN modalul deschis — altfel focus-trap-ul Filament (modalul
        // face restul paginii inert) nu lasă tastare în inputul de căutare.
        var host = (btn && btn.closest('.fi-modal-window, [role="dialog"], .fi-modal')) || document.body;
        if (overlay.parentElement !== host) {
            host.appendChild(overlay);
        }

        overlay.style.display = 'block';
        try {
            await ensureMap();
            setTimeout(function () { map.invalidateSize(); }, 60);
        } catch (e) {
            alert('Harta nu s-a putut încărca: ' + e.message);
            samedayCloseLockerMap();
        }
    };

    window.samedayCloseLockerMap = function () {
        var o = document.getElementById('sameday-map-overlay');
        if (o) o.style.display = 'none';
    };
})();
