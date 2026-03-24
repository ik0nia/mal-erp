'use strict';

class Toolbar {
  constructor(containerEl, editorCore, allTemplates, currentTemplateId) {
    this._el                = containerEl;
    this._editor            = editorCore;
    this._allTemplates      = allTemplates || [];
    this._currentTemplateId = currentTemplateId;

    this._render();
    this._bind();
  }

  _render() {
    // Template switcher pills
    const tplPills = this._allTemplates.map(tpl => {
      const isActive = tpl.id === this._currentTemplateId;
      return `<a href="/template-editor/${tpl.id}"
                 class="tpl-btn${isActive ? ' active' : ''}"
                 title="${this._esc(tpl.name)}"
                 style="text-decoration:none">${this._esc(tpl.name)}</a>`;
    }).join('');

    // Role buttons: label, role key, title tooltip
    const roles = [
      { role: 'title',            label: 'Titlu',    title: 'Adaugă titlu principal' },
      { role: 'subtitle',         label: 'Subtitlu', title: 'Adaugă subtitlu' },
      { role: 'badge',            label: 'Badge',    title: 'Adaugă etichetă / badge' },
      { role: 'CTA_text',         label: 'CTA',      title: 'Adaugă text CTA' },
      { role: 'product_image',    label: 'Imagine',  title: 'Adaugă placeholder imagine produs' },
      { role: 'brand_logo',       label: 'Logo',     title: 'Adaugă placeholder logo brand' },
      { role: 'background_shape', label: 'Fundal',   title: 'Adaugă formă de fundal' },
      { role: 'simple_text',      label: 'Text',     title: 'Adaugă text simplu / informativ' },
      { role: 'bullet_list',      label: 'Listă',    title: 'Adaugă listă cu beneficii' },
      { role: 'cta_button',       label: 'Buton',    title: 'Adaugă buton CTA compozit' },
    ];

    const roleBtns = roles.map(r =>
      `<button class="tb-btn tb-role" data-role="${r.role}" title="${r.title}">${r.label}</button>`
    ).join('');

    // Canvas preset options
    const presetOptions = CanvasPresets.all().map(p => {
      const sel = p.key === this._editor.getPreset().key ? ' selected' : '';
      return `<option value="${p.key}"${sel}>${this._esc(p.name)} — ${p.label}</option>`;
    }).join('');

    this._el.innerHTML = `
      ${tplPills ? `<div class="toolbar-group">${tplPills}</div><div class="toolbar-sep"></div>` : ''}

      <!-- Role-based element creation -->
      <div class="toolbar-group">
        ${roleBtns}
        <button class="tb-btn" id="tb-upload-img" title="Încarcă imagine din fișier">↑ Upload</button>
        <input type="file" id="tb-file-input" accept="image/*" style="display:none">
      </div>

      <div class="toolbar-sep"></div>

      <!-- Canvas preset -->
      <div class="toolbar-group">
        <select class="tb-select" id="tb-preset" title="Format canvas">
          ${presetOptions}
        </select>
      </div>

      <div class="toolbar-sep"></div>

      <!-- Alignment (align to artboard) -->
      <div class="toolbar-group" title="Aliniere față de artboard">
        <button class="tb-btn tb-align" id="tb-align-left"    title="Aliniere stânga">⇤</button>
        <button class="tb-btn tb-align" id="tb-align-ch"      title="Centrat orizontal">⇔</button>
        <button class="tb-btn tb-align" id="tb-align-right"   title="Aliniere dreapta">⇥</button>
        <button class="tb-btn tb-align" id="tb-align-top"     title="Aliniere sus">⇡</button>
        <button class="tb-btn tb-align" id="tb-align-cv"      title="Centrat vertical">⇕</button>
        <button class="tb-btn tb-align" id="tb-align-bottom"  title="Aliniere jos">⇣</button>
        <button class="tb-btn tb-align" id="tb-align-center"  title="Centrat pe ambele axe">⊛</button>
      </div>

      <div class="toolbar-sep"></div>

      <!-- Edit -->
      <div class="toolbar-group">
        <button class="tb-btn" id="tb-duplicate" title="Duplică (Ctrl+D)">⧉ Duplică</button>
        <button class="tb-btn tb-danger" id="tb-delete" title="Șterge (Del)">✕ Șterge</button>
      </div>

      <div class="toolbar-sep"></div>

      <!-- Z-order -->
      <div class="toolbar-group">
        <button class="tb-btn" id="tb-bring-front"   title="Aduce în față">⇈</button>
        <button class="tb-btn" id="tb-bring-forward" title="Un nivel în față">↑</button>
        <button class="tb-btn" id="tb-send-backward" title="Un nivel în spate">↓</button>
        <button class="tb-btn" id="tb-send-back"     title="Trimite în spate">⇊</button>
      </div>

      <div class="toolbar-sep"></div>

      <!-- Lock / unlock -->
      <div class="toolbar-group">
        <button class="tb-btn" id="tb-lock"   title="Blochează">🔒 Lock</button>
        <button class="tb-btn" id="tb-unlock" title="Deblochează">🔓 Unlock</button>
      </div>

      <div class="toolbar-sep"></div>

      <!-- Guide toggle -->
      <div class="toolbar-group">
        <button class="tb-btn" id="tb-guides" title="Afișează/ascunde ghidaje margine de siguranță">⊞ Ghid</button>
      </div>

      <div class="toolbar-spacer"></div>

      <!-- Zoom -->
      <div class="toolbar-group">
        <button class="tb-btn" id="tb-zoom-out" title="Zoom out">−</button>
        <span class="zoom-indicator" id="zoom-level">50%</span>
        <button class="tb-btn" id="tb-zoom-in"  title="Zoom in">+</button>
        <button class="tb-btn" id="tb-zoom-fit" title="Fit to window">⊡ Fit</button>
      </div>

      <div class="toolbar-sep"></div>

      <!-- Save / Preview -->
      <div class="toolbar-group">
        <button class="tb-btn tb-save"    id="tb-save"    title="Salvează (Ctrl+S)">Salvează</button>
        <button class="tb-btn tb-preview" id="tb-preview" title="Preview">Preview</button>
      </div>
    `;
  }

  _bind() {
    const e = this._editor;

    // Role buttons — each creates an element for its semantic role
    this._el.querySelectorAll('.tb-role').forEach(btn => {
      btn.addEventListener('click', () => {
        e.addElementForRole(btn.dataset.role);
      });
    });

    // Image upload from file
    this._on('tb-upload-img', () => document.getElementById('tb-file-input').click());
    const fileInput = document.getElementById('tb-file-input');
    if (fileInput) {
      fileInput.addEventListener('change', (ev) => {
        const file = ev.target.files && ev.target.files[0];
        if (file) { e.addImageFromFile(file); fileInput.value = ''; }
      });
    }

    // Canvas preset switcher
    const presetSel = document.getElementById('tb-preset');
    if (presetSel) {
      presetSel.addEventListener('change', () => {
        e.switchPreset(presetSel.value);
      });
    }

    // Alignment
    this._on('tb-align-left',   () => e.alignLeft());
    this._on('tb-align-ch',     () => e.alignCenterH());
    this._on('tb-align-right',  () => e.alignRight());
    this._on('tb-align-top',    () => e.alignTop());
    this._on('tb-align-cv',     () => e.alignCenterV());
    this._on('tb-align-bottom', () => e.alignBottom());
    this._on('tb-align-center', () => e.alignCenter());

    // Guide toggle
    this._on('tb-guides', () => e.toggleGuides());

    // Edit
    this._on('tb-duplicate', () => e.duplicateSelected());
    this._on('tb-delete',    () => e.deleteSelected());

    // Z-order
    this._on('tb-bring-front',   () => e.bringToFront());
    this._on('tb-bring-forward', () => e.bringForward());
    this._on('tb-send-backward', () => e.sendBackward());
    this._on('tb-send-back',     () => e.sendToBack());

    // Lock
    this._on('tb-lock',   () => e.lockSelected());
    this._on('tb-unlock', () => e.unlockSelected());

    // Zoom
    this._on('tb-zoom-out', () => e.zoomOut());
    this._on('tb-zoom-in',  () => e.zoomIn());
    this._on('tb-zoom-fit', () => e.zoomFit());

    // Save / Preview
    this._on('tb-save',    () => e.save());
    this._on('tb-preview', () => e.saveAndPreview());

    // Keyboard shortcuts
    document.addEventListener('keydown', (ev) => {
      const tag = document.activeElement && document.activeElement.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

      if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') {
        ev.preventDefault();
        e.save();
      }
      if ((ev.ctrlKey || ev.metaKey) && ev.key === 'd') {
        ev.preventDefault();
        e.duplicateSelected();
      }
      if (ev.key === 'Delete' || ev.key === 'Backspace') {
        e.deleteSelected();
      }
    });
  }

  _on(id, fn) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', fn);
  }

  _esc(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
}
