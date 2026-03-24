'use strict';

/**
 * AlignmentTools
 *
 * Aligns the currently selected Fabric object relative to the artboard
 * (logical design-space boundaries from CanvasManager).
 *
 * Rules:
 *  - All math uses logical design coordinates only (never viewport pixels).
 *  - scaleX/scaleY are always 1 (enforced by CanvasManager._normalizeObject),
 *    so obj.getScaledWidth() === obj.width. We still call getScaledWidth()
 *    for correctness in case an operation runs before normalisation fires.
 *  - After every move, setCoords() is called and canvas is re-rendered.
 *  - Emits object:modified so the properties panel stays in sync.
 *  - Safe margin is the inset zone from each edge (default 80px).
 *    It mirrors the visual guide overlay in ZoomManager.
 */
class AlignmentTools {

  static DEFAULT_SAFE_MARGIN = 80;

  /**
   * @param {CanvasManager} canvasManager
   */
  constructor(canvasManager) {
    this._cm         = canvasManager;
    this._safeMargin = AlignmentTools.DEFAULT_SAFE_MARGIN;
  }

  // ── Safe margin ───────────────────────────────────────────

  getSafeMargin()   { return this._safeMargin; }
  setSafeMargin(px) { this._safeMargin = Math.max(0, Math.round(px)); }

  // ── Align to artboard edges ───────────────────────────────

  alignLeftToArtboard()   { this._setLeft(0); }
  alignRightToArtboard()  { this._setLeft(this._cw() - this._objW()); }
  alignTopToArtboard()    { this._setTop(0); }
  alignBottomToArtboard() { this._setTop(this._ch() - this._objH()); }

  // ── Align to artboard centre ──────────────────────────────

  alignCenterHorizontally() {
    this._setLeft(Math.round((this._cw() - this._objW()) / 2));
  }

  alignCenterVertically() {
    this._setTop(Math.round((this._ch() - this._objH()) / 2));
  }

  alignCenterBoth() {
    const obj = this._obj();
    if (!obj) return;
    obj.set({
      left: Math.round((this._cw() - this._objW(obj)) / 2),
      top:  Math.round((this._ch() - this._objH(obj)) / 2),
    });
    this._finalize(obj);
  }

  // ── Snap to safe margin ───────────────────────────────────

  snapToSafeMarginLeft()   { this._setLeft(this._safeMargin); }
  snapToSafeMarginTop()    { this._setTop(this._safeMargin); }

  snapToSafeMarginRight() {
    this._setLeft(this._cw() - this._objW() - this._safeMargin);
  }

  snapToSafeMarginBottom() {
    this._setTop(this._ch() - this._objH() - this._safeMargin);
  }

  // ── Internal ──────────────────────────────────────────────

  _setLeft(x) {
    const obj = this._obj();
    if (!obj) return;
    obj.set('left', Math.round(x));
    this._finalize(obj);
  }

  _setTop(y) {
    const obj = this._obj();
    if (!obj) return;
    obj.set('top', Math.round(y));
    this._finalize(obj);
  }

  _finalize(obj) {
    obj.setCoords();
    this._cm.render();
    // Notify properties panel about the coordinate change
    if (obj.data?.id) {
      this._cm._state._emit('object:modified', { id: obj.data.id, obj });
    }
  }

  _obj()        { return this._cm.getActiveObject(); }
  _cw()         { return this._cm.getDesignWidth(); }
  _ch()         { return this._cm.getDesignHeight(); }
  _objW(o = this._obj()) { return o ? Math.round(o.getScaledWidth())  : 0; }
  _objH(o = this._obj()) { return o ? Math.round(o.getScaledHeight()) : 0; }
}
