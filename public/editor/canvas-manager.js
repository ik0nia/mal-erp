'use strict';

/**
 * CanvasManager
 *
 * Maintains a stable Fabric.js canvas where ALL object coordinates live in a
 * fixed design space of (designWidth × designHeight) — regardless of how the
 * canvas is displayed. The display size is managed exclusively by ZoomManager.
 *
 * Design principles:
 *  - The canvas DOM element is sized by ZoomManager to fill its container.
 *    CanvasManager never touches width / height itself.
 *  - enableRetinaScaling is off; ZoomManager accounts for devicePixelRatio.
 *  - After every user interaction (move / resize / scale), object coords are
 *    normalised. For shapes, scaleX/scaleY are absorbed into explicit width/height.
 *    For fabric.Image objects, scaleX/scaleY are preserved — they carry the display
 *    size relative to the natural source dimensions (width/height = naturalWidth/Height).
 *    All left/top values are rounded to integers. This prevents floating-point drift.
 *  - Semantic "role" is a first-class property stored on every object under
 *    obj.data.role. Valid roles: title, subtitle, product_image, badge,
 *    CTA_text, brand_logo, background_shape, simple_text, bullet_list, cta_button.
 *
 * Canvas size:
 *  - designWidth / designHeight define the logical coordinate space.
 *  - Default is 1080×1080 (square post). Override via constructor arguments.
 *  - Call setDesignSize(w, h) to switch presets after construction.
 *  - ZoomManager reads getDesignWidth() / getDesignHeight() at every fit.
 */
class CanvasManager {

  // Kept for backward compatibility. Use getDesignWidth() / getDesignHeight() internally.
  static DESIGN_SIZE = 1080;

  /**
   * @param {string}       canvasId
   * @param {StateManager} stateManager
   * @param {Function}     [onReady]      — called with (this) once initialised
   * @param {number}       [designWidth=1080]
   * @param {number}       [designHeight=1080]
   */
  constructor(canvasId, stateManager, onReady, designWidth = 1080, designHeight = 1080) {
    this._state        = stateManager;
    this._designWidth  = designWidth;
    this._designHeight = designHeight;

    this._canvas = new fabric.Canvas(canvasId, {
      width:                  this._designWidth,
      height:                 this._designHeight,
      preserveObjectStacking: true,
      selection:              true,
      renderOnAddRemove:      false,
      enableRetinaScaling:    false,
      stopContextMenu:        false,
      backgroundColor:        '#FFFFFF',
    });

    this._bindEvents();

    if (typeof onReady === 'function') onReady(this);
  }

  // ─── Design size ─────────────────────────────────────────────────────────

  getDesignWidth()  { return this._designWidth; }
  getDesignHeight() { return this._designHeight; }

  /**
   * Update the logical design space dimensions.
   * Call ZoomManager.fitToContainer() after this to apply the new size.
   *
   * @param {number} w
   * @param {number} h
   */
  setDesignSize(w, h) {
    this._designWidth  = Math.round(w);
    this._designHeight = Math.round(h);
  }

  // ─── Fabric canvas accessor ──────────────────────────────────────────────

  /** @returns {fabric.Canvas} */
  getCanvas() {
    return this._canvas;
  }

  // ─── Event binding ───────────────────────────────────────────────────────

  _bindEvents() {
    const c = this._canvas;

    c.on('selection:created', (e) => this._onSelectionChange(e));
    c.on('selection:updated', (e) => this._onSelectionChange(e));
    c.on('selection:cleared', () => this._state.clearSelection());

    c.on('object:modified', (e) => {
      if (!e.target) return;
      this._normalizeObject(e.target);
      // Force an immediate re-render so the canvas shows the normalised
      // dimensions rather than whatever Fabric had queued before the handler ran.
      this._canvas.requestRenderAll();
      this._state._emit('object:modified', {
        id:  e.target.data && e.target.data.id,
        obj: e.target,
      });
    });

    // Live update of the properties panel while the user is actively dragging
    // a resize handle. We do NOT normalise here (that would fight Fabric's
    // internal transform tracking); we only re-emit so the panel can show the
    // current getScaledWidth/Height() values during the drag.
    c.on('object:scaling', (e) => {
      if (!e.target) return;
      this._state._emit('object:modified', {
        id:  e.target.data && e.target.data.id,
        obj: e.target,
      });
    });

    c.on('object:added',   () => this._syncLayerOrder());
    c.on('object:removed', () => this._syncLayerOrder());

    // Right-click → bring forward. Shift+right-click → send backward.
    c.upperCanvasEl.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      const obj = c.getActiveObject();
      if (!obj) return;
      e.shiftKey ? c.sendBackwards(obj, true) : c.bringForward(obj, true);
      this._syncLayerOrder();
      c.requestRenderAll();
    });
  }

  // ─── Coordinate normalisation ────────────────────────────────────────────

  /**
   * Normalise object geometry after a user interaction.
   *
   * Shapes (rect, textbox, etc.): scaleX/scaleY are absorbed into explicit
   * width/height, then left/top are rounded. Invariant after normalisation:
   * scaleX === scaleY === 1; width/height = rendered size in design-space px.
   *
   * fabric.Image: scaleX/scaleY are NOT absorbed. The image's width/height
   * are its natural (source) dimensions; scaleX/scaleY carry the display size.
   * Only left/top are rounded.
   *
   * Groups (e.g. cta_button): only position is normalised (lockScalingX/Y = true
   * prevents scale drift in normal usage).
   *
   * @param {fabric.Object} obj
   */
  _normalizeObject(obj) {
    if (!obj) return;

    if (obj.type === 'activeSelection') {
      obj.getObjects().forEach(child => this._normalizeObject(child));
      return;
    }

    // For Fabric Groups (e.g. cta_button): only normalise position.
    // Scale absorption into children would require distributing each child's
    // dimensions, which is handled instead by ElementFactory.rebuildCtaButton.
    // cta_button groups have lockScalingX/Y = true so scale drift should
    // not occur in normal usage.
    if (obj.type === 'group') {
      obj.set({
        left: Math.round(obj.left),
        top:  Math.round(obj.top),
      });
      obj.setCoords();
      return;
    }

    // fabric.Image: width/height are the natural (source) dimensions and must
    // not be changed. Display size is expressed via scaleX/scaleY. Absorbing
    // scale into width/height corrupts repeated resizes (each drag starts from
    // the wrong base) and makes the bitmap render at the wrong size.
    // Only position is normalised for images; scaleX/Y are left as-is.
    if (obj.type !== 'image' && (obj.scaleX !== 1 || obj.scaleY !== 1)) {
      obj.set({
        width:  Math.round(obj.width  * obj.scaleX),
        height: Math.round(obj.height * obj.scaleY),
        scaleX: 1,
        scaleY: 1,
      });
      // Explicitly invalidate the object cache. Fabric auto-sets dirty=true
      // via cacheProperties when 'width' changes, but explicitly setting it
      // here guards against edge cases where the numeric value didn't change
      // yet the scale composition did (e.g. repeated resize to the same px).
      obj.dirty = true;
    }

    obj.set({
      left: Math.round(obj.left),
      top:  Math.round(obj.top),
    });

    obj.setCoords();
  }

  // ─── Active object ───────────────────────────────────────────────────────

  /** @returns {fabric.Object|null} */
  getActiveObject() {
    return this._canvas.getActiveObject() ?? null;
  }

  /** @param {fabric.Object|null} obj */
  setActiveObject(obj) {
    if (!obj) {
      this._canvas.discardActiveObject();
    } else {
      this._canvas.setActiveObject(obj);
    }
    this._canvas.requestRenderAll();
  }

  discardSelection() {
    this._canvas.discardActiveObject();
    this._canvas.requestRenderAll();
  }

  // ─── Object bounds — design space ────────────────────────────────────────

  /**
   * @param {fabric.Object} obj
   * @returns {{ x: number, y: number, w: number, h: number }|null}
   */
  getObjectBounds(obj) {
    if (!obj) return null;
    return {
      x: Math.round(obj.left),
      y: Math.round(obj.top),
      w: Math.round(obj.getScaledWidth()),
      h: Math.round(obj.getScaledHeight()),
    };
  }

  /**
   * @param {fabric.Object} obj
   * @param {{ x?: number, y?: number, w?: number, h?: number }} bounds
   */
  setObjectBounds(obj, { x, y, w, h } = {}) {
    if (!obj) return;
    if (x !== undefined) obj.set('left', Math.round(x));
    if (y !== undefined) obj.set('top',  Math.round(y));
    if (obj.type === 'image') {
      // For fabric.Image, width/height are the natural (source) dimensions and
      // must not be changed. Express the desired display size as scaleX/scaleY.
      if (w !== undefined && obj.width)  obj.set({ scaleX: w / obj.width });
      if (h !== undefined && obj.height) obj.set({ scaleY: h / obj.height });
    } else {
      // Shapes/text: scaleX === scaleY === 1 invariant — set width/height directly.
      if (w !== undefined) obj.set({ width:  Math.round(w), scaleX: 1 });
      if (h !== undefined) obj.set({ height: Math.round(h), scaleY: 1 });
    }
    obj.dirty = true;
    obj.setCoords();
    this._canvas.requestRenderAll();
  }

  // ─── Object management ───────────────────────────────────────────────────

  /** @param {fabric.Object} obj */
  add(obj) {
    this._canvas.add(obj);
    obj.setCoords();
    this._canvas.requestRenderAll();
  }

  /** @param {fabric.Object} obj */
  remove(obj) {
    this._canvas.remove(obj);
    this._canvas.requestRenderAll();
  }

  /** @returns {fabric.Object[]} */
  getObjects() {
    return this._canvas.getObjects();
  }

  /**
   * @param {string} id
   * @returns {fabric.Object|null}
   */
  getObjectById(id) {
    return this._canvas.getObjects().find(o => o.data && o.data.id === id) ?? null;
  }

  /**
   * @param {string} role
   * @returns {fabric.Object|undefined}
   */
  getObjectByRole(role) {
    return this._canvas.getObjects().find(o => o.data && o.data.role === role);
  }

  /**
   * Select an object by id. No-op for locked objects.
   * @param {string} id
   */
  selectById(id) {
    const obj = this.getObjectById(id);
    if (!obj) return;
    if (obj.data && obj.data.locked) return;
    this._canvas.setActiveObject(obj);
    this._canvas.requestRenderAll();
  }

  // ─── Layer ordering ──────────────────────────────────────────────────────

  /** @param {fabric.Object} obj */
  bringForward(obj) {
    if (!obj) return;
    this._canvas.bringForward(obj, true);
    this._syncLayerOrder();
    this._canvas.requestRenderAll();
  }

  /** @param {fabric.Object} obj */
  sendBackward(obj) {
    if (!obj) return;
    this._canvas.sendBackwards(obj, true);
    this._syncLayerOrder();
    this._canvas.requestRenderAll();
  }

  /** @param {fabric.Object} obj */
  bringToFront(obj) {
    if (!obj) return;
    this._canvas.bringToFront(obj);
    this._syncLayerOrder();
    this._canvas.requestRenderAll();
  }

  /** @param {fabric.Object} obj */
  sendToBack(obj) {
    if (!obj) return;
    this._canvas.sendToBack(obj);
    this._syncLayerOrder();
    this._canvas.requestRenderAll();
  }

  /**
   * Reorder all canvas objects to match the given id sequence (bottom → top).
   * Called by LayersPanel after a drag-to-reorder operation.
   *
   * @param {string[]} orderedIds  — object ids from bottom (z=0) to top
   */
  setObjectOrder(orderedIds) {
    const objects = this._canvas.getObjects();
    const idToObj = new Map(
      objects.filter(o => o.data?.id).map(o => [o.data.id, o])
    );
    const noId    = objects.filter(o => !o.data?.id);
    const ordered = orderedIds.map(id => idToObj.get(id)).filter(Boolean);

    const arr = this._canvas._objects;
    arr.splice(0, arr.length);
    noId.forEach(o => arr.push(o));
    ordered.forEach(o => arr.push(o));

    this._syncLayerOrder();
    this._canvas.requestRenderAll();
  }

  // ─── Render ──────────────────────────────────────────────────────────────

  render() {
    this._canvas.requestRenderAll();
  }

  /**
   * Normalise every object on the canvas.
   * Shapes: absorbs scaleX/Y into width/height.
   * Images: only rounds left/top; scaleX/Y are preserved as display scale.
   * Call this before serialisation to guarantee the saved JSON is consistent.
   */
  normalizeAll() {
    this._canvas.getObjects().forEach(obj => this._normalizeObject(obj));
  }

  // ─── Serialisation ───────────────────────────────────────────────────────

  /**
   * Serialise canvas objects to JSON (includes obj.data with semantic metadata).
   * @returns {object}
   */
  toJSON() {
    return this._canvas.toJSON([
      'data',
      'selectable',
      'evented',
      'lockMovementX',
      'lockMovementY',
      'lockRotation',
      'lockScalingX',
      'lockScalingY',
    ]);
  }

  /**
   * Restore canvas from serialised JSON. Normalises all loaded objects.
   * @param {object|string} json
   * @param {Function}      [cb]
   */
  loadFromJSON(json, cb) {
    this._canvas.loadFromJSON(json, () => {
      this._canvas.getObjects().forEach(obj => this._normalizeObject(obj));
      this._canvas.requestRenderAll();
      if (typeof cb === 'function') cb();
    });
  }

  // ─── Cleanup ─────────────────────────────────────────────────────────────

  destroy() {
    this._canvas.dispose();
  }

  // ─── Internal helpers ────────────────────────────────────────────────────

  _syncLayerOrder() {
    const ids = this._canvas.getObjects()
      .filter(o => o.data && o.data.id)
      .map(o => o.data.id);
    this._state.reorderObjects(ids);
  }

  _onSelectionChange(e) {
    const obj = e.selected && e.selected[0];
    if (obj && obj.data && obj.data.id) {
      this._state.setActiveObject(obj.data.id);
    }
  }
}
