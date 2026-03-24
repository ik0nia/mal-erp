'use strict';

class PropertiesPanel {
  static FONTS = [
    'Montserrat', 'Open Sans', 'Roboto', 'Lato',
    'Oswald', 'Raleway', 'Playfair Display', 'Arial',
  ];

  constructor(containerEl, canvasManager, stateManager) {
    this._el = containerEl;
    this._cm = canvasManager;
    this._state = stateManager;

    this._state.on('selection:changed', () => this.render());
    this._state.on('object:modified', () => this.render());
    this._state.on('template:loaded', () => this.render());

    this.render();
  }

  render() {
    const activeId = this._state.getActiveObjectId();

    if (!activeId) {
      this._el.innerHTML = `
        <div class="panel-header">
          <span class="panel-title">Proprietăți</span>
        </div>
        <div class="panel-empty">Selectează un element pentru a-i edita proprietățile.</div>
      `;
      return;
    }

    const obj = this._cm.getObjectById(activeId);
    if (!obj) {
      this._el.innerHTML = `<div class="panel-empty">Element negăsit.</div>`;
      return;
    }

    const meta = obj.data || {};
    const isCtaButton = meta.role === 'cta_button' && obj.type === 'group';
    const isText = !isCtaButton && (obj.type === 'textbox' || obj.type === 'text' || obj.type === 'i-text');
    const isImage = obj.type === 'image';

    // Coordinates in logical 1080 space
    const x = Math.round(obj.left || 0);
    const y = Math.round(obj.top || 0);
    const w = Math.round(obj.getScaledWidth ? obj.getScaledWidth() : (obj.width || 0));
    const h = Math.round(obj.getScaledHeight ? obj.getScaledHeight() : (obj.height || 0));
    const angle = Math.round((obj.angle || 0) * 10) / 10;
    const opacity = Math.round((obj.opacity !== undefined ? obj.opacity : 1) * 100);

    const fillColor = Utils.toHex(obj.fill || '#000000');

    let html = `
      <div class="panel-header">
        <span class="panel-title">Proprietăți</span>
        <span class="panel-obj-type">${this._typeName(meta.type || obj.type)}</span>
      </div>
    `;

    // ── Position & Size ───────────────────────────────────

    if (isCtaButton) {
      html += `
        <div class="panel-section">
          <div class="panel-section-title">Poziție</div>
          <div class="prop-grid">
            <div class="prop-field"><label>X</label><input type="number" id="pp-x" value="${x}"></div>
            <div class="prop-field"><label>Y</label><input type="number" id="pp-y" value="${y}"></div>
            <div class="prop-field"><label>Lățime</label><input type="number" id="pp-w" value="${w}" disabled style="opacity:.5"></div>
            <div class="prop-field"><label>Înălțime</label><input type="number" id="pp-h" value="${h}" disabled style="opacity:.5"></div>
            <div class="prop-field"><label>Unghi °</label><input type="number" id="pp-angle" value="${angle}"></div>
            <div class="prop-field"><label>Opacitate %</label><input type="number" id="pp-opacity" value="${opacity}" min="0" max="100"></div>
          </div>
        </div>
      `;
    } else {
      html += `
        <div class="panel-section">
          <div class="panel-section-title">Poziție &amp; Dimensiuni</div>
          <div class="prop-grid">
            <div class="prop-field">
              <label>X</label>
              <input type="number" id="pp-x" value="${x}" min="0" max="1080">
            </div>
            <div class="prop-field">
              <label>Y</label>
              <input type="number" id="pp-y" value="${y}" min="0" max="1080">
            </div>
            <div class="prop-field">
              <label>Lățime</label>
              <input type="number" id="pp-w" value="${w}" min="1" max="3000">
            </div>
            <div class="prop-field">
              <label>Înălțime</label>
              <input type="number" id="pp-h" value="${h}" min="1" max="3000">
            </div>
            <div class="prop-field">
              <label>Unghi °</label>
              <input type="number" id="pp-angle" value="${angle}" min="-360" max="360">
            </div>
            <div class="prop-field">
              <label>Opacitate %</label>
              <input type="number" id="pp-opacity" value="${opacity}" min="0" max="100">
            </div>
          </div>
        </div>
      `;
    }

    // ── CTA Button section ────────────────────────────────

    if (isCtaButton) {
      const ctaProps = obj.data?.ctaProps || {};
      const ctaText      = ctaProps.text      || (obj.getObjects && obj.getObjects()[1]?.text) || '';
      const ctaFontSize  = ctaProps.fontSize  || 32;
      const ctaFont      = ctaProps.fontFamily || 'Montserrat';
      const ctaTextColor = Utils.toHex(ctaProps.textColor || '#ffffff');
      const ctaBgColor   = Utils.toHex(ctaProps.bgColor   || '#1e40af');

      const ctaFontLc = ctaFont.toLowerCase().trim();
      const ctaFontOptions = PropertiesPanel.FONTS.map(f =>
        `<option value="${f}" ${f.toLowerCase().trim() === ctaFontLc ? 'selected' : ''}>${f}</option>`
      ).join('');

      html += `
        <div class="panel-section">
          <div class="panel-section-title">Buton CTA</div>

          <label>Text buton</label>
          <input type="text" id="cta-text" value="${this._esc(ctaText)}">

          <label>Font</label>
          <select id="cta-font-family">${ctaFontOptions}</select>

          <div class="prop-grid" style="margin-top:8px">
            <div class="prop-field">
              <label>Mărime (px)</label>
              <input type="number" id="cta-font-size" value="${ctaFontSize}" min="12" max="200">
            </div>
          </div>

          <div class="prop-grid" style="margin-top:8px">
            <div class="prop-field">
              <label>Culoare text</label>
              <div class="color-row" style="margin-top:2px">
                <input type="color" id="cta-text-color" value="${ctaTextColor}">
              </div>
            </div>
            <div class="prop-field">
              <label>Culoare fundal</label>
              <div class="color-row" style="margin-top:2px">
                <input type="color" id="cta-bg-color" value="${ctaBgColor}">
              </div>
            </div>
          </div>
        </div>
      `;
    }

    // ── Fill color (non-text, non-CTA) ────────────────────

    if (!isText && !isCtaButton) {
      html += `
        <div class="panel-section">
          <div class="panel-section-title">Culoare fundal</div>
          <div class="color-row">
            <input type="color" id="pp-fill-color" value="${fillColor}">
            <input type="text" id="pp-fill-hex" value="${fillColor}" maxlength="7" style="font-family:monospace">
          </div>
        </div>
      `;
    }

    // ── Text properties ───────────────────────────────────

    if (isText) {
      const textColor = Utils.toHex(obj.fill || '#111111');
      const fontSize = obj.fontSize || 48;
      const fontFamily = obj.fontFamily || 'Montserrat';
      const fontWeight = obj.fontWeight || 'normal';
      const isBold = fontWeight === 'bold' || fontWeight === '700' || fontWeight >= 700;
      const textAlign = obj.textAlign || 'left';
      const content = obj.text || '';

      // Case-insensitive match so that fonts saved in JSON with slightly different
      // casing still show the correct selection in the dropdown.
      const fontFamilyLc = fontFamily.toLowerCase().trim();
      const fontOptions = PropertiesPanel.FONTS.map(f =>
        `<option value="${f}" ${f.toLowerCase().trim() === fontFamilyLc ? 'selected' : ''}>${f}</option>`
      ).join('');

      const alignOptions = ['left','center','right','justify'].map(a => `
        <button class="align-btn ${textAlign === a ? 'active' : ''}" data-align="${a}">
          ${this._alignIcon(a)}
        </button>
      `).join('');

      html += `
        <div class="panel-section">
          <div class="panel-section-title">Text</div>
          <label>Conținut</label>
          <textarea id="pp-text-content" rows="3">${this._esc(content)}</textarea>

          <label>Font</label>
          <select id="pp-font-family">${fontOptions}</select>

          <div class="prop-grid" style="margin-top:8px">
            <div class="prop-field">
              <label>Mărime (px)</label>
              <input type="number" id="pp-font-size" value="${fontSize}" min="8" max="500">
            </div>
            <div class="prop-field">
              <label>Culoare text</label>
              <div class="color-row" style="margin-top:2px">
                <input type="color" id="pp-text-color" value="${textColor}">
              </div>
            </div>
          </div>

          <label>
            <input type="checkbox" id="pp-bold" ${isBold ? 'checked' : ''}> Bold
          </label>

          <div style="margin-top:8px">
            <div style="font-size:10px;color:#64748b;margin-bottom:4px">Aliniere</div>
            <div class="align-row" id="pp-align-row">
              ${alignOptions}
            </div>
          </div>
        </div>
      `;
    }

    // ── Image info ────────────────────────────────────────

    if (isImage) {
      html += `
        <div class="panel-section">
          <div class="panel-section-title">Imagine</div>
          <div style="font-size:11px;color:#94a3b8">
            Natural: ${obj.width}×${obj.height}px<br>
            Afișat: ${w}×${h}px
          </div>
        </div>
      `;
    }

    // ── Name field ────────────────────────────────────────

    const slotRoleOptions = [
      ['none',             '— Niciun rol —'],
      ['title',            'Titlu'],
      ['subtitle',         'Subtitlu'],
      ['badge',            'Badge / Etichetă'],
      ['cta',              'CTA text'],
      ['product_image',    'Imagine produs'],
      ['brand_logo',       'Logo brand'],
      ['malinco_logo',     'Logo Malinco'],
      ['footer_text',      'Text footer'],
      ['background_shape', 'Formă fundal'],
    ].map(([v, l]) =>
      `<option value="${v}" ${(meta.slot_role || 'none') === v ? 'selected' : ''}>${l}</option>`
    ).join('');

    html += `
      <div class="panel-section">
        <div class="panel-section-title">Identificare</div>
        <label>Nume strat</label>
        <input type="text" id="pp-name" value="${this._esc(meta.name || '')}">
        <label style="margin-top:8px">Rol conținut dinamic</label>
        <select id="pp-slot-role">${slotRoleOptions}</select>
      </div>
    `;

    this._el.innerHTML = html;
    this._bindInputs(obj, isText, isCtaButton, activeId);
  }

  _bindInputs(obj, isText, isCtaButton, activeId) {
    const cm = this._cm;
    const state = this._state;

    // CTA buttons use a dedicated binding method
    if (isCtaButton) {
      this._bindCtaButtonInputs(obj, activeId);
      return;
    }

    const emit = () => {
      obj.setCoords && obj.setCoords();
      cm.render();
      state._emit('object:modified', { id: activeId, obj });
    };

    const bind = (id, handler) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('change', handler);
    };

    const bindInput = (id, handler) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', handler);
    };

    // ── Position ─────────────────────────────────────────

    bind('pp-x', e => {
      obj.set('left', parseFloat(e.target.value) || 0);
      emit();
    });

    bind('pp-y', e => {
      obj.set('top', parseFloat(e.target.value) || 0);
      emit();
    });

    bind('pp-w', e => {
      const val = Math.max(1, Math.round(parseFloat(e.target.value) || 1));
      if (isText) {
        obj.set('width', val);
      } else if (isImage) {
        // For fabric.Image: natural width/height must not be changed.
        // Express the desired display size as scaleX relative to natural width.
        if (obj.width) obj.set({ scaleX: val / obj.width });
        obj.dirty = true;
      } else {
        // Shapes: scaleX === 1 invariant — set width directly.
        obj.set({ width: val, scaleX: 1 });
        obj.dirty = true;
      }
      obj.setCoords && obj.setCoords();
      emit();
    });

    bind('pp-h', e => {
      const val = Math.max(1, Math.round(parseFloat(e.target.value) || 1));
      if (isImage) {
        // For fabric.Image: natural height must not be changed.
        if (obj.height) obj.set({ scaleY: val / obj.height });
        obj.dirty = true;
        obj.setCoords && obj.setCoords();
        emit();
      } else if (!isText) {
        // Shapes: scaleY === 1 invariant — set height directly.
        obj.set({ height: val, scaleY: 1 });
        obj.dirty = true;
        obj.setCoords && obj.setCoords();
        emit();
      }
    });

    bind('pp-angle', e => {
      obj.set('angle', parseFloat(e.target.value) || 0);
      emit();
    });

    bind('pp-opacity', e => {
      const val = Utils.clamp(parseFloat(e.target.value) || 0, 0, 100) / 100;
      obj.set('opacity', val);
      cm.render();
    });

    // ── Fill color ────────────────────────────────────────

    if (!isText) {
      bindInput('pp-fill-color', e => {
        obj.set('fill', e.target.value);
        const hexEl = document.getElementById('pp-fill-hex');
        if (hexEl) hexEl.value = e.target.value;
        cm.render();
      });

      bind('pp-fill-hex', e => {
        const val = e.target.value.trim();
        if (/^#[0-9a-fA-F]{6}$/.test(val)) {
          obj.set('fill', val);
          const colorEl = document.getElementById('pp-fill-color');
          if (colorEl) colorEl.value = val;
          cm.render();
        }
      });
    }

    // ── Text ──────────────────────────────────────────────

    if (isText) {
      bindInput('pp-text-content', e => {
        obj.set('text', e.target.value);
        obj.setCoords && obj.setCoords();
        cm.render();
      });

      bind('pp-font-family', e => {
        const fontFamily = e.target.value;
        const weight = (obj.fontWeight === 'bold' || Number(obj.fontWeight) >= 700) ? '700' : '400';
        // Load the font before applying so Fabric measures with the real glyph metrics.
        document.fonts.load(`${weight} ${obj.fontSize || 48}px "${fontFamily}"`)
          .catch(() => { /* use whatever is available */ })
          .then(() => {
            obj.set('fontFamily', fontFamily);
            emit();
          });
      });

      bind('pp-font-size', e => {
        obj.set('fontSize', parseFloat(e.target.value) || 12);
        emit();
      });

      bindInput('pp-text-color', e => {
        obj.set('fill', e.target.value);
        cm.render();
      });

      bind('pp-bold', e => {
        obj.set('fontWeight', e.target.checked ? 'bold' : 'normal');
        emit();
      });

      // Align buttons
      const alignRow = document.getElementById('pp-align-row');
      if (alignRow) {
        alignRow.querySelectorAll('.align-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            const align = btn.dataset.align;
            obj.set('textAlign', align);
            // Update active class
            alignRow.querySelectorAll('.align-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            cm.render();
          });
        });
      }
    }

    // ── Name ─────────────────────────────────────────────

    bind('pp-name', e => {
      if (obj.data) obj.data.name = e.target.value;
      state.updateObjectMeta(activeId, { name: e.target.value });
    });

    // ── Slot role ─────────────────────────────────────────

    bind('pp-slot-role', e => {
      if (obj.data) obj.data.slot_role = e.target.value;
      state.updateObjectMeta(activeId, { slot_role: e.target.value });
    });
  }

  _bindCtaButtonInputs(group, activeId) {
    const cm    = this._cm;
    const state = this._state;

    const applyCtaProps = (changes) => {
      const newProps = Object.assign({}, group.data.ctaProps || {}, changes);
      ElementFactory.rebuildCtaButton(group, newProps);
      cm.render();
      state._emit('object:modified', { id: activeId, obj: group });
    };

    const bind = (id, fn) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', fn);
    };
    const bindInput = (id, fn) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', fn);
    };

    // Position
    bind('pp-x', e => {
      group.set('left', Math.round(parseFloat(e.target.value) || 0));
      group.setCoords();
      cm.render();
    });
    bind('pp-y', e => {
      group.set('top', Math.round(parseFloat(e.target.value) || 0));
      group.setCoords();
      cm.render();
    });
    bind('pp-angle', e => {
      group.set('angle', parseFloat(e.target.value) || 0);
      group.setCoords();
      cm.render();
    });
    bind('pp-opacity', e => {
      group.set('opacity', Utils.clamp(parseFloat(e.target.value) || 0, 0, 100) / 100);
      cm.render();
    });

    // CTA content
    bind('cta-text',  e => applyCtaProps({ text: e.target.value }));
    bind('cta-font-family', e => {
      const fontFamily = e.target.value;
      const props = group.data.ctaProps || {};
      const weight = (props.fontWeight === 'bold') ? '700' : '400';
      document.fonts.load(`${weight} ${props.fontSize || 32}px "${fontFamily}"`)
        .catch(() => {})
        .then(() => applyCtaProps({ fontFamily }));
    });
    bind('cta-font-size',  e => applyCtaProps({ fontSize: parseFloat(e.target.value) || 32 }));
    // Color pickers: live canvas-only update on input (no emit → no panel re-render → picker stays open)
    bindInput('cta-text-color', e => {
      const newProps = Object.assign({}, group.data.ctaProps || {}, { textColor: e.target.value });
      ElementFactory.rebuildCtaButton(group, newProps);
      cm.render();
    });
    bind('cta-text-color', e => applyCtaProps({ textColor: e.target.value }));
    bindInput('cta-bg-color', e => {
      const newProps = Object.assign({}, group.data.ctaProps || {}, { bgColor: e.target.value });
      ElementFactory.rebuildCtaButton(group, newProps);
      cm.render();
    });
    bind('cta-bg-color', e => applyCtaProps({ bgColor: e.target.value }));

    // Name
    bind('pp-name', e => {
      if (group.data) group.data.name = e.target.value;
      state.updateObjectMeta(activeId, { name: e.target.value });
    });

    // Slot role
    bind('pp-slot-role', e => {
      if (group.data) group.data.slot_role = e.target.value;
      state.updateObjectMeta(activeId, { slot_role: e.target.value });
    });
  }

  _typeName(type) {
    const map = { text: 'Text', heading: 'Titlu', rect: 'Dreptunghi', image: 'Imagine', textbox: 'Text', group: 'Grup' };
    return map[type] || type || 'Element';
  }

  _alignIcon(align) {
    const icons = { left: '≡L', center: '≡C', right: '≡R', justify: '≡J' };
    return icons[align] || align;
  }

  _esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  _toHex(color) {
    return Utils.toHex(color);
  }
}
