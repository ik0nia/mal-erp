'use strict';

/**
 * ZoomManager
 *
 * Handles zoom for a CanvasManager with an arbitrary logical design space
 * (designWidth × designHeight). All coordinates are in design-space pixels.
 *
 * Artboard model:
 *
 *  #canvas-area  (workspace — CSS background, flex centering)
 *    #canvas-wrap  (shadow wrapper — sized to artboard display px)
 *      <svg class="guide-overlay">  (safe-margin guides, not exported)
 *      .canvas-container  (Fabric's wrapper div)
 *        canvas.lower-canvas
 *        canvas.upper-canvas
 *
 * How sizing is guaranteed:
 *  - displayW = round(designW * zoom)  displayH = round(designH * zoom)
 *  - After canvas.setDimensions({width: displayW, height: displayH}), ALL
 *    four Fabric elements are explicitly given those dimensions via direct
 *    style assignment. This beats Fabric's own injected CSS (width:100%).
 *  - viewportTransform[4,5] = 0 (canvas origin = artboard origin; no offset).
 *  - Guide overlay SVG is resized to displayW × displayH; viewBox is in
 *    design coords so the dashed rect auto-scales without any conversion.
 *
 * Requires: CanvasManager, Utils.
 */
class ZoomManager {

  static LEVELS  = [0.1, 0.25, 0.33, 0.5, 0.67, 0.75, 1.0, 1.5, 2.0];
  static MIN     = 0.05;
  static MAX     = 4.0;

  static DEFAULT_SAFE_MARGIN = 80;

  /**
   * @param {CanvasManager} canvasManager
   * @param {HTMLElement}   containerEl   — workspace element (#canvas-area)
   * @param {object}        [options]
   * @param {number}        [options.padding=48]      — px margin around artboard
   * @param {boolean}       [options.debug=false]     — log measurements to console
   * @param {boolean}       [options.guides=true]     — show safe margin guides
   * @param {number}        [options.safeMargin=80]   — safe margin in design px
   */
  constructor(canvasManager, containerEl, options = {}) {
    this._cm          = canvasManager;
    this._container   = containerEl;
    this._canvas      = canvasManager.getCanvas();
    this._zoom        = 1;
    this._padding     = options.padding    ?? 48;
    this._debug       = options.debug      ?? false;
    this._guidesOn    = options.guides     ?? false;  // off by default — toggle via toolbar
    this._safeMargin  = options.safeMargin ?? ZoomManager.DEFAULT_SAFE_MARGIN;
    this._rafId       = null;
    this._guideSvg    = null;

    this._initGuideOverlay();

    this._resizeObserver = new ResizeObserver(() => {
      if (this._rafId !== null) return;
      this._rafId = requestAnimationFrame(() => {
        this._rafId = null;
        this.fitToContainer();
      });
    });
    this._resizeObserver.observe(this._container);

    // Also observe the editor body and window — #canvas-area has overflow:auto
    // and flex-shrink:0 canvas inside it, so its own clientWidth may not change
    // when the window shrinks. Watching the parent (#editor-body) + window
    // guarantees we catch all resize events.
    const editorBody = this._container.parentElement;
    if (editorBody) this._resizeObserver.observe(editorBody);

    this._onWindowResize = () => {
      if (this._rafId !== null) return;
      this._rafId = requestAnimationFrame(() => {
        this._rafId = null;
        this.fitToContainer();
      });
    };
    window.addEventListener('resize', this._onWindowResize);

    this.fitToContainer();
  }

  // ─── Fit ─────────────────────────────────────────────────────────────────

  fitToContainer() {
    const cw  = this._container.clientWidth;
    const ch  = this._container.clientHeight;
    if (!cw || !ch) return;

    const dw  = this._cm.getDesignWidth();
    const dh  = this._cm.getDesignHeight();
    const pad = this._padding;

    // Fit the whole artboard: limit by both axes independently.
    const zoom = Math.min((cw - pad) / dw, (ch - pad) / dh);

    this._applyZoom(Utils.clamp(zoom, ZoomManager.MIN, ZoomManager.MAX));
  }

  // ─── Zoom controls ───────────────────────────────────────────────────────

  zoomIn() {
    const next = ZoomManager.LEVELS.find(l => l > this._zoom + 0.001);
    this._applyZoom(next !== undefined
      ? next : Math.min(this._zoom + 0.1, ZoomManager.MAX));
  }

  zoomOut() {
    let prev = null;
    for (let i = ZoomManager.LEVELS.length - 1; i >= 0; i--) {
      if (ZoomManager.LEVELS[i] < this._zoom - 0.001) { prev = ZoomManager.LEVELS[i]; break; }
    }
    this._applyZoom(prev !== null
      ? prev : Math.max(this._zoom - 0.1, ZoomManager.MIN));
  }

  setZoom(zoom)        { this._applyZoom(Utils.clamp(zoom, ZoomManager.MIN, ZoomManager.MAX)); }
  setZoomPercent(pct)  { this.setZoom(pct / 100); }
  reset()              { this.fitToContainer(); }

  // ─── Accessors ───────────────────────────────────────────────────────────

  getZoom()      { return this._zoom; }
  getZoomLabel() { return Math.round(this._zoom * 100) + '%'; }

  // ─── Guide overlay controls ───────────────────────────────────────────────

  isGuidesOn() { return this._guidesOn; }

  /** Toggle safe-margin guide overlay visibility. */
  toggleGuides() {
    this._guidesOn = !this._guidesOn;
    this._updateGuideOverlay(
      Math.round(this._cm.getDesignWidth()  * this._zoom),
      Math.round(this._cm.getDesignHeight() * this._zoom),
    );
  }

  getSafeMargin()   { return this._safeMargin; }

  /** @param {number} px — safe margin in design-space pixels */
  setSafeMargin(px) {
    this._safeMargin = Math.max(0, Math.round(px));
    this._updateGuideOverlay(
      Math.round(this._cm.getDesignWidth()  * this._zoom),
      Math.round(this._cm.getDesignHeight() * this._zoom),
    );
  }

  // ─── Coordinate conversion ───────────────────────────────────────────────

  screenToDesign(screenX, screenY) {
    const vt = this._canvas.viewportTransform;
    return {
      x: Math.round((screenX - vt[4]) / this._zoom),
      y: Math.round((screenY - vt[5]) / this._zoom),
    };
  }

  designToScreen(designX, designY) {
    const vt = this._canvas.viewportTransform;
    return {
      x: Math.round(designX * this._zoom + vt[4]),
      y: Math.round(designY * this._zoom + vt[5]),
    };
  }

  // ─── Cleanup ─────────────────────────────────────────────────────────────

  destroy() {
    this._resizeObserver.disconnect();
    window.removeEventListener('resize', this._onWindowResize);
    if (this._rafId !== null) { cancelAnimationFrame(this._rafId); this._rafId = null; }
    if (this._guideSvg && this._guideSvg.parentNode) {
      this._guideSvg.parentNode.removeChild(this._guideSvg);
    }
  }

  // ─── Core ────────────────────────────────────────────────────────────────

  /**
   * Apply zoom. This is the single place where all canvas dimensions are set.
   *
   * Why we force-assign styles directly:
   *  Fabric 5.x injects a rule: .canvas-container canvas { width:100%; height:100% }
   *  We must set ALL four elements explicitly so our values win over any CSS.
   *
   * @param {number} zoom
   */
  _applyZoom(zoom) {
    this._zoom = zoom;

    const dw       = this._cm.getDesignWidth();
    const dh       = this._cm.getDesignHeight();
    const displayW = Math.round(dw * zoom);
    const displayH = Math.round(dh * zoom);

    // ── Step 1: Fabric API call ──────────────────────────────────────────
    this._canvas.setDimensions({ width: displayW, height: displayH });

    // ── Step 2: Force ALL Fabric-created elements to exact display size ──
    const wPx = displayW + 'px';
    const hPx = displayH + 'px';
    const els = [
      this._canvas.wrapperEl,
      this._canvas.lowerCanvasEl,
      this._canvas.upperCanvasEl,
    ];
    els.forEach(el => {
      if (!el) return;
      el.style.setProperty('width',      wPx,    'important');
      el.style.setProperty('height',     hPx,    'important');
      el.style.setProperty('max-width',  'none', 'important');
      el.style.setProperty('max-height', 'none', 'important');
    });

    // ── Step 3: Fabric zoom ──────────────────────────────────────────────
    this._canvas.setZoom(zoom);

    // ── Step 4: Zero out viewport offset ────────────────────────────────
    this._canvas.viewportTransform[4] = 0;
    this._canvas.viewportTransform[5] = 0;

    // ── Step 5: Refresh Fabric hit-boxes ────────────────────────────────
    this._canvas.getObjects().forEach(obj => obj.setCoords());

    // ── Step 6: Repaint ──────────────────────────────────────────────────
    this._canvas.requestRenderAll();

    // ── Step 7: Update UI label ──────────────────────────────────────────
    const label = document.getElementById('zoom-level');
    if (label) label.textContent = this.getZoomLabel();

    // ── Step 8: Update guide overlay ────────────────────────────────────
    this._updateGuideOverlay(displayW, displayH);

    // ── Step 9: Debug output (only when debug mode on) ───────────────────
    if (this._debug) this._printDebug(displayW, displayH);
  }

  // ─── Guide overlay ───────────────────────────────────────────────────────

  /**
   * Create the SVG element and insert it into #canvas-wrap (before the
   * Fabric .canvas-container so it renders below interaction layer).
   * The SVG is positioned absolutely and sized to match the artboard.
   */
  _initGuideOverlay() {
    const wrap = document.getElementById('canvas-wrap');
    if (!wrap) return;

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.classList.add('guide-overlay');
    svg.style.cssText = [
      'position:absolute',
      'top:0',
      'left:0',
      'pointer-events:none',
      'z-index:10',
      'overflow:visible',
    ].join(';');

    // Insert before the .canvas-container so it sits below Fabric's
    // interaction layer but above the canvas background.
    const container = wrap.querySelector('.canvas-container');
    if (container) {
      wrap.insertBefore(svg, container);
    } else {
      wrap.appendChild(svg);
    }

    this._guideSvg = svg;
  }

  /**
   * Resize and redraw the guide overlay to match current display dimensions.
   * The viewBox uses design-space coordinates so the dashed rect is always
   * at the exact safe-margin distance from the artboard edge.
   *
   * @param {number} displayW — artboard display width in screen px
   * @param {number} displayH — artboard display height in screen px
   */
  _updateGuideOverlay(displayW, displayH) {
    const svg = this._guideSvg;
    if (!svg) return;

    const dw = this._cm.getDesignWidth();
    const dh = this._cm.getDesignHeight();
    const m  = this._safeMargin;

    // Size the SVG to exactly cover the artboard.
    svg.setAttribute('width',   displayW);
    svg.setAttribute('height',  displayH);
    svg.setAttribute('viewBox', `0 0 ${dw} ${dh}`);

    // Clear existing children.
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    if (!this._guidesOn || m <= 0) return;

    // Thin solid rect inset by the safe margin — kept subtle so it helps
    // without distracting from the design.
    const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    rect.setAttribute('x',            m);
    rect.setAttribute('y',            m);
    rect.setAttribute('width',        Math.max(0, dw - m * 2));
    rect.setAttribute('height',       Math.max(0, dh - m * 2));
    rect.setAttribute('fill',         'none');
    rect.setAttribute('stroke',       '#6366f1');
    rect.setAttribute('stroke-width', '1.5');
    rect.setAttribute('opacity',      '0.22');
    svg.appendChild(rect);
  }

  // ─── Debug ───────────────────────────────────────────────────────────────

  _printDebug(displayW, displayH) {
    const lc = this._canvas.lowerCanvasEl;
    const uc = this._canvas.upperCanvasEl;
    const wr = this._canvas.wrapperEl;
    const cw = this._container;

    console.group('[ZoomManager] _applyZoom @ ' + this.getZoomLabel());
    console.log('design size:', this._cm.getDesignWidth(), '×', this._cm.getDesignHeight());
    console.log('display px:', displayW, '×', displayH);
    console.log('canvas.width attr  :', lc ? lc.width  : 'n/a',
                '  canvas.height attr  :', lc ? lc.height : 'n/a');
    console.log('lower-canvas style  :', lc ? `${lc.style.width} × ${lc.style.height}` : 'n/a');
    console.log('upper-canvas style  :', uc ? `${uc.style.width} × ${uc.style.height}` : 'n/a');
    console.log('canvas-container style:', wr ? `${wr.style.width} × ${wr.style.height}` : 'n/a');
    if (lc) {
      console.log('lower-canvas clientW×H:', lc.clientWidth, '×', lc.clientHeight);
    }
    if (wr) {
      console.log('canvas-container clientW×H:', wr.clientWidth, '×', wr.clientHeight);
    }
    const wrap = document.getElementById('canvas-wrap');
    if (wrap) {
      console.log('#canvas-wrap clientW×H:', wrap.clientWidth, '×', wrap.clientHeight);
    }
    if (cw) {
      console.log('#canvas-area clientW×H:', cw.clientWidth, '×', cw.clientHeight);
    }
    console.log('viewportTransform:', JSON.stringify(this._canvas.viewportTransform));
    console.groupEnd();
  }
}
