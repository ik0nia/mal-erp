'use strict';

class EditorCore {
  constructor(templateData, allTemplates) {
    this._template     = templateData;
    this._allTemplates = allTemplates || [];

    // Resolve canvas preset from saved config (backward-compat: default square_post)
    this._preset = CanvasPresets.fromConfig(templateData.config || {});

    // 1. State manager
    this._state = new StateManager();

    // 2. Canvas manager
    // NOTE: onReady fires synchronously inside the CanvasManager constructor,
    // so this._cm is not yet assigned at that point. The callback receives the
    // CanvasManager instance as its first argument — use that instead.
    this._cm = new CanvasManager('c', this._state, (cm) => {
      this._cm = cm; // assign before anything uses this._cm

      // 3. Zoom manager
      this._zoom = new ZoomManager(this._cm, document.getElementById('canvas-area'));

      // 4. Alignment tools
      this._align = new AlignmentTools(this._cm);

      // 5. Panels
      this._layers = new LayersPanel(
        document.getElementById('layers-panel'),
        this._cm,
        this._state
      );

      this._props = new PropertiesPanel(
        document.getElementById('props-panel'),
        this._cm,
        this._state
      );

      // 6. Toolbar (rendered last so all methods are ready)
      this._toolbar = new Toolbar(
        document.getElementById('toolbar'),
        this,
        this._allTemplates,
        this._template.id
      );

      // 7. Load template — wait for fonts first to avoid text render race
      this._state.setTemplate(
        this._template.id,
        this._template.name,
        this._template.config || {}
      );
      this._waitForFonts().then(() => {
        this._loadTemplate(this._template);
        // 8. Fit to container after short delay (DOM layout settled)
        setTimeout(() => this._zoom.fitToContainer(), 100);
      });
    }, this._preset.width, this._preset.height);
  }

  // ── Font preloading ───────────────────────────────────────

  /**
   * Collect all font families needed for this session:
   *  1. Fonts declared in the saved canvas JSON (for loaded templates)
   *  2. Default fonts from ElementFactory.ROLE_DEFAULTS
   *  3. All fonts available in the picker (for deterministic UI behaviour)
   *
   * System fonts (Arial) are excluded — they need no web-font loading.
   *
   * @param {object} templateData
   * @returns {string[]}
   */
  _collectFontsFromTemplateData(templateData) {
    const fonts = new Set();

    // All picker fonts — ensures the dropdown always works immediately
    ['Montserrat', 'Open Sans', 'Roboto', 'Lato', 'Oswald', 'Raleway', 'Playfair Display']
      .forEach(f => fonts.add(f));

    // Fonts stored in the saved canvas JSON (including nested group children)
    const canvasJson = templateData?.config?.canvas_json;
    if (canvasJson) {
      try {
        const parsed = typeof canvasJson === 'string' ? JSON.parse(canvasJson) : canvasJson;
        const extract = (objs) => {
          (objs || []).forEach(o => {
            if (o.fontFamily) fonts.add(o.fontFamily);
            if (o.objects)    extract(o.objects); // Fabric Group children
          });
        };
        extract(parsed.objects);
      } catch (_) { /* ignore malformed JSON */ }
    }

    // Fonts declared in ROLE_DEFAULTS (covers newly-created elements)
    Object.values(ElementFactory.ROLE_DEFAULTS).forEach(def => {
      if (def.visual?.fontFamily) fonts.add(def.visual.fontFamily);
      if (def.visual?.textFamily) fonts.add(def.visual.textFamily);
    });

    return [...fonts].filter(f => f && f !== 'Arial');
  }

  /**
   * Ask the browser to fully load all required fonts before any Fabric text
   * object is created or rendered.
   *
   * Using document.fonts.load() with a Romanian-diacritic sample text forces
   * the browser to download the correct Unicode ranges (not just the ASCII
   * subset), ensuring ă â î ș ț render in the correct typeface even on first
   * hard-refresh with an empty cache.
   *
   * @returns {Promise<void>}
   */
  _waitForFonts() {
    const fonts  = this._collectFontsFromTemplateData(this._template);
    // Romanian diacritics + ASCII subset — covers all characters used in templates
    const sample = 'ăâîșțĂÂÎȘȚ ABCDEFabcdef 0123456789';

    return Promise.all(
      fonts.flatMap(font => [
        document.fonts.load(`400 16px "${font}"`, sample),
        document.fonts.load(`700 16px "${font}"`, sample),
      ]).map(p => p.catch(() => { /* ignore individual failures */ }))
    ).then(() => { /* void */ });
  }

  /**
   * Force all Fabric text objects (including those inside Groups) to
   * re-measure their glyph metrics and re-render.
   *
   * Call this after document.fonts.load() resolves to guarantee that Fabric
   * uses the real typeface metrics rather than fallback-font measurements.
   */
  _rerenderTextObjects() {
    this._cm.getObjects().forEach(obj => {
      if (obj.type === 'textbox' || obj.type === 'text' || obj.type === 'i-text') {
        obj.dirty = true;
        if (typeof obj.initDimensions === 'function') obj.initDimensions();
      } else if (obj.type === 'group') {
        obj.getObjects().forEach(child => {
          if (child.type === 'textbox' || child.type === 'text' || child.type === 'i-text') {
            child.dirty = true;
            if (typeof child.initDimensions === 'function') child.initDimensions();
          }
        });
        obj.dirty = true;
        obj.setCoords();
      }
    });
    this._cm.render();
  }

  // ── Canvas preset ─────────────────────────────────────────

  getPreset() { return this._preset; }

  /**
   * Switch to a different canvas format.
   * Existing objects keep their design-space coordinates.
   */
  switchPreset(presetKey) {
    const preset = CanvasPresets.get(presetKey);
    this._preset = preset;
    this._cm.setDesignSize(preset.width, preset.height);
    this._zoom.fitToContainer();
  }

  /** Toggle the safe-margin guide overlay. Syncs the toolbar button active state. */
  toggleGuides() {
    this._zoom.toggleGuides();
    const btn = document.getElementById('tb-guides');
    if (btn) btn.classList.toggle('active', this._zoom.isGuidesOn());
  }

  // ── Template loading ──────────────────────────────────────

  _loadTemplate(tpl) {
    const config = tpl.config || {};
    const canvasJson = config.canvas_json;

    if (canvasJson) {
      let parsed;
      try {
        parsed = typeof canvasJson === 'string' ? JSON.parse(canvasJson) : canvasJson;
      } catch (e) {
        console.warn('canvas_json parse error, building defaults', e);
        parsed = null;
      }

      if (parsed) {
        this._cm.loadFromJSON(parsed, () => {
          // Rebuild state objects from loaded canvas
          this._state.getState().objects = [];
          this._cm.getObjects().forEach(obj => {
            if (obj.data && obj.data.id) {
              this._state.addObject(obj.data);
            } else {
              obj.data = {
                id:         Utils.generateId(),
                role:       null,
                type:       obj.type === 'textbox' ? 'text' : (obj.type || 'rect'),
                name:       obj.type === 'textbox' ? 'Text' : 'Element',
                editable:   true,
                aiMappable: false,
                locked:     false,
                visible:    true,
              };
              this._state.addObject(obj.data);
            }
          });
          this._state._emit('layers:changed', { objects: this._state.getObjects() });
          // Re-render once — fonts are already loaded at this point (we waited
          // in _waitForFonts before _loadTemplate was called), but a second
          // requestRenderAll after the callback fires ensures Fabric picks up
          // the correct metrics for any text objects (including group children).
          requestAnimationFrame(() => this._rerenderTextObjects());
        });
        return;
      }
    }

    // No saved JSON → build default elements
    this._buildDefaultElements(tpl);
  }

  _buildDefaultElements(tpl) {
    const config       = tpl.config || {};
    const primaryColor = config.primary_color || '#1e40af';
    const dw           = this._cm.getDesignWidth();
    const dh           = this._cm.getDesignHeight();

    const add = (obj) => {
      this._cm.add(obj);
      this._state.addObject(obj.data);
    };

    // 1. Full background
    add(ElementFactory.createForRole('background_shape', {
      fill: '#f1f5f9', left: 0, top: 0, width: dw, height: dh,
    }));

    // 2. Bottom colour bar — occupies bottom 1/6 of artboard height
    const barH = Math.round(dh / 6);
    add(ElementFactory.createForRole('background_shape', {
      left: 0, top: dh - barH, width: dw, height: barH, fill: primaryColor,
    }, { name: 'Bara jos', aiMappable: false }));

    // 3. Badge / eyebrow label
    add(ElementFactory.createForRole('badge', {
      text: config.cta_text || 'PROMO',
      fill: primaryColor,
    }));

    // 4. Title
    add(ElementFactory.createForRole('title', {
      text: config.bottom_text || 'Titlu produs',
    }));

    // 5. Subtitle
    add(ElementFactory.createForRole('subtitle', {
      text: config.bottom_subtext || 'Subtitlu sau descriere scurtă',
    }));

    this._state._emit('layers:changed', { objects: this._state.getObjects() });
  }

  // ── Add elements ──────────────────────────────────────────

  /**
   * Primary API for toolbar/AI: create a role-based element and add it to canvas.
   * Handles both synchronous (text/rect/group) and async (image) results from ElementFactory.
   */
  addElementForRole(role, visualOverrides = {}, metaOverrides = {}) {
    const result = ElementFactory.createForRole(role, visualOverrides, metaOverrides);

    if (result instanceof Promise) {
      this._setStatus('Se încarcă…', 'info');
      result
        .then(obj => { this._addToCanvas(obj); this._setStatus('Element adăugat.', 'ok'); })
        .catch(err => { console.error(err); this._setStatus('Eroare la adăugare.', 'err'); });
    } else {
      this._addToCanvas(result);
    }
  }

  /** Shared helper: add a ready Fabric object to canvas + state, then select it. */
  _addToCanvas(obj) {
    this._cm.add(obj);
    this._state.addObject(obj.data);
    this._cm.getCanvas().setActiveObject(obj);
    this._state.setActiveObject(obj.data.id);
    this._cm.render();
  }

  addImageFromFile(file) {
    const reader = new FileReader();
    reader.onload = (e) => {
      this.addImageFromUrl(e.target.result, {
        name: file.name.replace(/\.[^.]+$/, '') || 'Imagine',
        role: 'product_image',
      });
    };
    reader.readAsDataURL(file);
  }

  addImageFromUrl(url, opts = {}) {
    this._setStatus('Se încarcă imaginea…', 'info');
    ElementFactory.createImageFromUrl(url, {
      left:       opts.left   || 200,
      top:        opts.top    || 200,
      name:       opts.name   || 'Imagine',
      width:      opts.width  || undefined,
      role:       opts.role   || 'product_image',
      aiMappable: opts.aiMappable !== undefined ? opts.aiMappable : true,
    }).then(obj => {
      this._addToCanvas(obj);
      this._setStatus('Imagine adăugată.', 'ok');
    }).catch(err => {
      console.error(err);
      this._setStatus('Eroare la încărcarea imaginii.', 'err');
    });
  }

  // ── Edit actions ──────────────────────────────────────────

  deleteSelected() {
    const obj = this._cm.getActiveObject();
    if (!obj) return;
    if (obj.data && obj.data.locked) return;
    const id = obj.data && obj.data.id;
    this._cm.remove(obj);
    if (id) this._state.removeObject(id);
    this._state.clearSelection();
  }

  duplicateSelected() {
    const obj = this._cm.getActiveObject();
    if (!obj) return;
    ElementFactory.clone(obj).then(cloned => {
      this._addToCanvas(cloned);
    });
  }

  // ── Z-order ───────────────────────────────────────────────

  bringForward()  { const o = this._cm.getActiveObject(); if (o) this._cm.bringForward(o); }
  sendBackward()  { const o = this._cm.getActiveObject(); if (o) this._cm.sendBackward(o); }
  bringToFront()  { const o = this._cm.getActiveObject(); if (o) this._cm.bringToFront(o); }
  sendToBack()    { const o = this._cm.getActiveObject(); if (o) this._cm.sendToBack(o); }

  // ── Lock / unlock ─────────────────────────────────────────

  lockSelected() {
    const obj = this._cm.getActiveObject();
    if (!obj || !obj.data) return;
    obj.set({
      selectable: false, evented: false,
      lockMovementX: true, lockMovementY: true,
      lockRotation: true, lockScalingX: true, lockScalingY: true,
    });
    this._state.updateObjectMeta(obj.data.id, { locked: true });
    this._cm.discardSelection();
    this._state.clearSelection();
    this._cm.render();
  }

  unlockSelected() {
    const activeObj = this._cm.getActiveObject();
    if (activeObj && activeObj.data) { this._unlockObj(activeObj); return; }
    const locked = this._state.getObjects().filter(o => o.locked);
    if (!locked.length) return;
    const canvasObj = this._cm.getObjectById(locked[locked.length - 1].id);
    if (canvasObj) this._unlockObj(canvasObj);
  }

  _unlockObj(obj) {
    if (!obj || !obj.data) return;
    const isCta = obj.data.role === 'cta_button';
    obj.set({
      selectable:    true,
      evented:       true,
      lockMovementX: false,
      lockMovementY: false,
      lockRotation:  false,
      lockScalingX:  isCta, // CTA buttons always keep scale locked
      lockScalingY:  isCta,
    });
    this._state.updateObjectMeta(obj.data.id, { locked: false });
    this._cm.render();
  }

  // ── Zoom ──────────────────────────────────────────────────

  zoomIn()  { this._zoom.zoomIn(); }
  zoomOut() { this._zoom.zoomOut(); }
  zoomFit() { this._zoom.fitToContainer(); }

  // ── Alignment proxy ───────────────────────────────────────

  alignLeft()            { this._align.alignLeftToArtboard(); }
  alignRight()           { this._align.alignRightToArtboard(); }
  alignTop()             { this._align.alignTopToArtboard(); }
  alignBottom()          { this._align.alignBottomToArtboard(); }
  alignCenterH()         { this._align.alignCenterHorizontally(); }
  alignCenterV()         { this._align.alignCenterVertically(); }
  alignCenter()          { this._align.alignCenterBoth(); }
  snapMarginLeft()       { this._align.snapToSafeMarginLeft(); }
  snapMarginRight()      { this._align.snapToSafeMarginRight(); }
  snapMarginTop()        { this._align.snapToSafeMarginTop(); }
  snapMarginBottom()     { this._align.snapToSafeMarginBottom(); }

  // ── Selection proxy ───────────────────────────────────────

  selectObjectById(id) {
    this._cm.selectById(id);
    this._state.setActiveObject(id);
  }

  updateSelectedProperty(key, value) {
    const obj = this._cm.getActiveObject();
    if (!obj) return;
    obj.set(key, value);
    obj.setCoords && obj.setCoords();
    this._cm.render();
    this._state._emit('object:modified', { id: obj.data && obj.data.id, obj });
  }

  // ── Save ──────────────────────────────────────────────────

  save() {
    this._setStatus('Se salvează…', 'info');

    const config = this._state.getState().templateConfig || {};

    // Normalise all objects before serialisation: absorbs any residual scaleX/Y
    // into width/height so the saved canvas_json always contains correct dimensions
    // (guards against edge cases where object:modified was skipped or a resize
    // was in progress when save was triggered).
    this._cm.normalizeAll();

    const canvasJson = JSON.stringify(this._cm.toJSON());

    // Extract element_positions from canvas objects that have an assigned slot_role.
    // These tell render.js where to place dynamic content (title, subtitle, etc.).
    const elementPositions = {};
    this._cm.getObjects().forEach(obj => {
      const sr = obj.data?.slot_role;
      if (!sr || sr === 'none') return;
      elementPositions[sr] = {
        x: Math.round(obj.left || 0),
        y: Math.round(obj.top  || 0),
        w: Math.round(obj.getScaledWidth  ? obj.getScaledWidth()  : (obj.width  || 0)),
        h: Math.round(obj.getScaledHeight ? obj.getScaledHeight() : (obj.height || 0)),
      };
    });

    // Persist canvas dimensions and preset alongside the canvas JSON.
    const canvasInfo = {
      width:  this._cm.getDesignWidth(),
      height: this._cm.getDesignHeight(),
      preset: this._preset.key,
    };

    const payload = {
      _token:            window.CSRF,
      name:              this._state.getState().templateName,
      layout:            config.layout            || this._template.layout || 'product',
      primary_color:     config.primary_color     || '#1e40af',
      bottom_text:       config.bottom_text       || '',
      bottom_subtext:    config.bottom_subtext    || '',
      cta_text:          config.cta_text          || '',
      show_rainbow_bar:  config.show_rainbow_bar  !== false ? '1' : '0',
      show_truck:        config.show_truck        !== false ? '1' : '0',
      logo_scale:        config.logo_scale        || 0.3,
      title_size_pct:    config.title_size_pct    || 0.07,
      subtitle_size_pct: config.subtitle_size_pct || 0.04,
      canvas_json:        canvasJson,
      canvas:             canvasInfo,
      element_positions:  Object.keys(elementPositions).length ? elementPositions : null,
    };

    return fetch(`/template-editor/${this._template.id}/save`, {
      method:      'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type':  'application/json',
        'X-CSRF-TOKEN':  window.CSRF,
        'Accept':        'application/json',
      },
      body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        this._state.markSaved();
        this._setStatus('Salvat cu succes!', 'ok');
      } else {
        const msg = data.message || JSON.stringify(data.errors || data);
        this._setStatus('Eroare: ' + msg, 'err');
      }
      return data;
    })
    .catch(err => {
      console.error('Save error:', err);
      this._setStatus('Eroare la salvare.', 'err');
      throw err;
    });
  }

  saveAndPreview() {
    this.save().then(() => {
      this._setStatus('Generez preview…', 'info');

      return fetch(`/template-editor/${this._template.id}/preview`, {
        method:      'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': window.CSRF,
          'Accept':       'application/json',
        },
        body: JSON.stringify({ _token: window.CSRF }),
      });
    })
    .then(r => r.json())
    .then(data => {
      if (data.url) {
        const previewEmpty  = document.getElementById('preview-empty');
        const previewResult = document.getElementById('preview-result');
        const previewImg    = document.getElementById('preview-img');
        const previewLink   = document.getElementById('preview-link');

        if (previewEmpty)  previewEmpty.style.display  = 'none';
        if (previewResult) previewResult.style.display = 'block';
        if (previewImg)    previewImg.src               = data.url;
        if (previewLink)   previewLink.href             = data.url;

        this._setStatus('Preview generat!', 'ok');
      } else {
        this._setStatus('Eroare preview: ' + (data.error || 'necunoscută'), 'err');
      }
    })
    .catch(err => {
      console.error('Preview error:', err);
      this._setStatus('Eroare la preview.', 'err');
    });
  }

  // ── Status message ────────────────────────────────────────

  _setStatus(msg, type = 'info') {
    const el = document.getElementById('status-msg');
    if (!el) return;
    el.textContent   = msg;
    el.className     = 'status-' + type;
    el.style.display = 'block';

    if (type !== 'info') {
      clearTimeout(this._statusTimer);
      this._statusTimer = setTimeout(() => { el.style.display = 'none'; }, 3000);
    }
  }
}
