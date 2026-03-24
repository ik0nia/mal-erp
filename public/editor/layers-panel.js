'use strict';

/**
 * LayersPanel
 *
 * Renders the layers list and keeps it in sync with both the Fabric canvas and
 * StateManager at all times.
 *
 * Features:
 *   - Click a layer       → selects the Fabric object (locked objects highlight
 *                           in the panel and update state selection, so the
 *                           properties panel can display their metadata, but the
 *                           canvas is not set active to preserve lock semantics)
 *   - Drag a layer row    → reorders canvas stack + syncs state.zIndex
 *   - Eye button          → toggles Fabric obj.visible + state.visible
 *   - Lock button         → toggles Fabric interactivity + state.locked
 *   - Role badge          → shows semantic role of each object
 */
class LayersPanel {

  // Compact role abbreviations shown in the badge
  static ROLE_LABELS = {
    title:            'T',
    subtitle:         'S',
    badge:            'B',
    CTA_text:         'CTA',
    product_image:    'P',
    brand_logo:       'L',
    background_shape: 'BG',
    simple_text:      'TXT',
    bullet_list:      'LIST',
    cta_button:       'BTN',
  };

  // Badge colours per role (background, foreground)
  static ROLE_COLORS = {
    title:            ['#dbeafe', '#1d4ed8'],
    subtitle:         ['#e0e7ff', '#4338ca'],
    badge:            ['#fce7f3', '#be185d'],
    CTA_text:         ['#dcfce7', '#15803d'],
    product_image:    ['#ffedd5', '#c2410c'],
    brand_logo:       ['#fef9c3', '#a16207'],
    background_shape: ['#f1f5f9', '#475569'],
    simple_text:      ['#f0fdf4', '#166534'],
    bullet_list:      ['#f0f9ff', '#0369a1'],
    cta_button:       ['#fdf4ff', '#7e22ce'],
  };

  constructor(containerEl, canvasManager, stateManager) {
    this._el    = containerEl;
    this._cm    = canvasManager;
    this._state = stateManager;

    // Drag state
    this._isDragging   = false;
    this._dragFromIdx  = null;
    this._dropToIdx    = null;

    this._state.on('layers:changed',  () => this._safeRender());
    this._state.on('selection:changed', () => this._safeRender());
    this._state.on('template:loaded', () => this._safeRender());

    this._safeRender();
  }

  // ── Render ────────────────────────────────────────────────

  /** Skip re-render while a drag is in progress (would destroy the DOM mid-drag). */
  _safeRender() {
    if (this._isDragging) return;
    this._render();
  }

  _render() {
    const state    = this._state.getState();
    const objects  = state.objects;
    const activeId = state.activeObjectId;

    // Panel order: topmost canvas object first (highest zIndex first)
    const panelOrder = [...objects].sort((a, b) => b.zIndex - a.zIndex);

    let html = `
      <div class="panel-header">
        <span class="panel-title">Straturi</span>
        <span style="font-size:11px;color:#94a3b8">${objects.length}</span>
      </div>
      <div id="layers-list">
    `;

    if (panelOrder.length === 0) {
      html += `<div class="panel-empty">Niciun element. Adaugă din toolbar.</div>`;
    } else {
      panelOrder.forEach((meta, listIdx) => {
        html += this._renderItem(meta, meta.id === activeId, listIdx);
      });
    }

    html += `</div>`;
    this._el.innerHTML = html;

    this._bindEvents();
  }

  _renderItem(meta, isActive, listIdx) {
    const activeClass = isActive          ? ' active'       : '';
    const lockedClass = meta.locked       ? ' locked'       : '';
    const hiddenClass = !meta.visible     ? ' hidden-layer' : '';

    const icon     = this._typeIcon(meta.type);
    const visIcon  = meta.visible  ? '👁' : '🙈';
    const lockIcon = meta.locked   ? '🔒' : '🔓';
    const roleBadge = meta.role && LayersPanel.ROLE_LABELS[meta.role]
      ? this._roleBadgeHtml(meta.role)
      : '';

    return `
      <div class="layer-item${activeClass}${lockedClass}${hiddenClass}"
           draggable="true"
           data-layer-id="${meta.id}"
           data-list-idx="${listIdx}">
        <span class="layer-drag-handle" title="Trage pentru reordonare">⠿</span>
        <span class="layer-icon">${icon}</span>
        ${roleBadge}
        <span class="layer-name" title="${this._esc(meta.name)}">${this._esc(meta.name)}</span>
        <span class="layer-actions">
          <button class="layer-action" data-action="visibility" data-id="${meta.id}"
                  title="${meta.visible ? 'Ascunde' : 'Arată'}">${visIcon}</button>
          <button class="layer-action" data-action="lock" data-id="${meta.id}"
                  title="${meta.locked ? 'Deblochează' : 'Blochează'}">${lockIcon}</button>
        </span>
      </div>
    `;
  }

  _roleBadgeHtml(role) {
    const label  = LayersPanel.ROLE_LABELS[role] || role;
    const colors = LayersPanel.ROLE_COLORS[role] || ['#f1f5f9', '#475569'];
    return `<span class="layer-role-badge"
                  style="background:${colors[0]};color:${colors[1]}"
                  title="${role}">${label}</span>`;
  }

  _typeIcon(type) {
    switch (type) {
      case 'heading': return 'H';
      case 'text':    return 'T';
      case 'image':   return '🖼';
      case 'rect':    return '▭';
      case 'group':   return '⊞';
      default:        return '◻';
    }
  }

  // ── Event binding ─────────────────────────────────────────

  _bindEvents() {
    const list = this._el.querySelector('#layers-list');
    if (!list) return;

    list.querySelectorAll('.layer-item').forEach(item => {

      // ── Click to select ──
      item.addEventListener('click', (e) => {
        if (e.target.closest('.layer-action')) return;
        const id = item.dataset.layerId;

        // Always update state so properties panel shows this object's data
        this._state.setActiveObject(id);

        // Only drive Fabric canvas selection for unlocked objects
        const meta = this._state.getObjectMeta(id);
        if (!meta || !meta.locked) {
          this._cm.selectById(id);
        }
      });

      // ── Visibility button ──
      item.querySelector('[data-action="visibility"]')
        ?.addEventListener('click', (e) => {
          e.stopPropagation();
          this._toggleVisibility(item.dataset.layerId);
        });

      // ── Lock button ──
      item.querySelector('[data-action="lock"]')
        ?.addEventListener('click', (e) => {
          e.stopPropagation();
          this._toggleLock(item.dataset.layerId);
        });
    });

    // Drag-and-drop
    this._bindDragDrop(list);
  }

  // ── Drag-and-drop reorder ─────────────────────────────────

  _bindDragDrop(list) {
    const items = list.querySelectorAll('.layer-item[draggable]');

    items.forEach(item => {

      item.addEventListener('dragstart', (e) => {
        this._isDragging  = true;
        this._dragFromIdx = parseInt(item.dataset.listIdx, 10);
        this._dropToIdx   = this._dragFromIdx;

        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', item.dataset.layerId);

        // Slight delay so the ghost image shows the full item before fade
        requestAnimationFrame(() => item.classList.add('layer-dragging'));
      });

      item.addEventListener('dragend', () => {
        item.classList.remove('layer-dragging');
        this._clearDropIndicators(list);
        this._isDragging = false;
        // Re-render to remove any stale indicator classes
        this._render();
      });

      item.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        this._clearDropIndicators(list);

        const rect  = item.getBoundingClientRect();
        const upper = e.clientY < rect.top + rect.height / 2;

        if (upper) {
          item.classList.add('drop-above');
          this._dropToIdx = parseInt(item.dataset.listIdx, 10);
        } else {
          item.classList.add('drop-below');
          this._dropToIdx = parseInt(item.dataset.listIdx, 10) + 1;
        }
      });

      item.addEventListener('dragleave', (e) => {
        // Only clear when leaving the item itself (not into a child)
        if (!item.contains(e.relatedTarget)) {
          item.classList.remove('drop-above', 'drop-below');
        }
      });

      item.addEventListener('drop', (e) => {
        e.preventDefault();
        this._clearDropIndicators(list);
        const to = this._dropToIdx ?? parseInt(item.dataset.listIdx, 10);
        this._performReorder(this._dragFromIdx, to);
        this._isDragging = false;
        // layers:changed fires from setObjectOrder → _syncLayerOrder → reorderObjects
        // which calls _safeRender automatically
      });
    });
  }

  _clearDropIndicators(list) {
    list?.querySelectorAll('.drop-above, .drop-below').forEach(el => {
      el.classList.remove('drop-above', 'drop-below');
    });
  }

  /**
   * Move the layer at `fromListIdx` to `toListIdx` in panel order (top = 0).
   * Translates to canvas order and calls cm.setObjectOrder().
   */
  _performReorder(fromListIdx, toListIdx) {
    const objects = this._state.getObjects();
    const N = objects.length;
    if (N < 2) return;

    // Panel order: sorted by zIndex descending (top layer = index 0)
    const panelOrder = [...objects]
      .sort((a, b) => b.zIndex - a.zIndex)
      .map(o => o.id);

    const from = Math.max(0, Math.min(fromListIdx, N - 1));
    const to   = Math.max(0, Math.min(toListIdx,   N - 1));
    if (from === to) return;

    // Move the dragged id in panel order
    const [movedId] = panelOrder.splice(from, 1);
    // After removal the array is N-1 long; clamp again
    const insertAt = Math.min(to, panelOrder.length);
    panelOrder.splice(insertAt, 0, movedId);

    // Canvas order = reverse of panel order (bottom canvas object first)
    this._cm.setObjectOrder([...panelOrder].reverse());
  }

  // ── Visibility ────────────────────────────────────────────

  _toggleVisibility(id) {
    const meta = this._state.getObjectMeta(id);
    if (!meta) return;

    const newVisible = !meta.visible;

    const canvasObj = this._cm.getObjectById(id);
    if (canvasObj) {
      canvasObj.set('visible', newVisible);

      if (!newVisible && this._state.getActiveObjectId() === id) {
        this._cm.discardSelection();
        this._state.clearSelection();
      }

      this._cm.render();
    }

    // updateObjectMeta emits layers:changed → _safeRender()
    this._state.updateObjectMeta(id, { visible: newVisible });
  }

  // ── Lock ──────────────────────────────────────────────────

  _toggleLock(id) {
    const meta = this._state.getObjectMeta(id);
    if (!meta) return;

    const newLocked = !meta.locked;

    const canvasObj = this._cm.getObjectById(id);
    if (canvasObj) {
      const isCta = canvasObj.data?.role === 'cta_button';
      canvasObj.set({
        selectable:    !newLocked,
        evented:       !newLocked,
        lockMovementX: newLocked,
        lockMovementY: newLocked,
        lockRotation:  newLocked,
        lockScalingX:  newLocked || isCta,  // CTA always keeps scaling locked
        lockScalingY:  newLocked || isCta,
      });

      if (newLocked && this._state.getActiveObjectId() === id) {
        // Remove Fabric canvas selection but keep state.activeObjectId
        // so the properties panel still shows the object's data
        this._cm.discardSelection();
      }

      this._cm.render();
    }

    // updateObjectMeta emits layers:changed → _safeRender()
    this._state.updateObjectMeta(id, { locked: newLocked });
  }

  // ── Utilities ─────────────────────────────────────────────

  _esc(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
}
