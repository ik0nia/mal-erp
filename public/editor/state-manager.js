'use strict';

/**
 * StateManager
 *
 * Single source of truth for editor metadata. Fabric canvas owns visual state
 * (coordinates, style); StateManager owns semantic state (role, aiMappable,
 * editable, locked, visible, zIndex, etc.).
 *
 * Data model — every tracked object has an ObjectMeta entry:
 *
 *   {
 *     id          : string   — stable, unique (matches obj.data.id on canvas)
 *     role        : string|null — semantic role (title, subtitle, …) or null
 *     type        : string   — fabric-level type: 'text' | 'rect' | 'image'
 *     name        : string   — human-readable layer name
 *     editable    : boolean  — user can change content / style
 *     aiMappable  : boolean  — AI pipeline can populate this field
 *     locked      : boolean  — movement / resize locked
 *     visible     : boolean  — layer visibility
 *     zIndex      : number   — canvas stack position (0 = bottom); kept in
 *                              sync via reorderObjects(), NOT in obj.data
 *   }
 *
 * Events emitted:
 *   template:loaded   { id, name, config }
 *   selection:changed { id }          — id is null when cleared
 *   layers:changed    { objects }     — full objects array
 *   object:modified   { id, obj }     — after canvas modification
 */
class StateManager {

  // ── Supported semantic roles ───────────────────────────────────────────────
  static ROLES = [
    'title',
    'subtitle',
    'product_image',
    'badge',
    'CTA_text',
    'brand_logo',
    'background_shape',
    'simple_text',
    'bullet_list',
    'cta_button',
  ];

  constructor() {
    this._state = {
      templateId:     null,
      templateName:   '',
      templateConfig: {},
      activeObjectId: null,
      objects:        [],   // ObjectMeta[]
      isDirty:        false,
    };
    this._listeners = {};
  }

  // ── Getters ──────────────────────────────────────────────────────────────

  getState()          { return this._state; }
  getActiveObjectId() { return this._state.activeObjectId; }
  getObjects()        { return this._state.objects; }

  /** @returns {ObjectMeta|null} */
  getObjectMeta(id) {
    return this._state.objects.find(o => o.id === id) ?? null;
  }

  /**
   * Objects the AI pipeline can populate, ordered bottom-to-top (zIndex asc).
   * @returns {ObjectMeta[]}
   */
  getAiMappableObjects() {
    return this._state.objects
      .filter(o => o.aiMappable)
      .sort((a, b) => a.zIndex - b.zIndex);
  }

  // ── Template ─────────────────────────────────────────────────────────────

  setTemplate(id, name, config) {
    this._state.templateId     = id;
    this._state.templateName   = name;
    this._state.templateConfig = config || {};
    this._state.isDirty        = false;
    this._emit('template:loaded', { id, name, config });
  }

  // ── Selection ────────────────────────────────────────────────────────────

  setActiveObject(id) {
    if (this._state.activeObjectId === id) return;
    this._state.activeObjectId = id;
    this._emit('selection:changed', { id });
  }

  clearSelection() {
    if (this._state.activeObjectId === null) return;
    this._state.activeObjectId = null;
    this._emit('selection:changed', { id: null });
  }

  // ── Object lifecycle ─────────────────────────────────────────────────────

  /**
   * Register a new object from its obj.data (or any partial metadata).
   * Always goes through _buildEntry so every field is guaranteed present.
   *
   * @param {object} meta
   * @returns {ObjectMeta}  — the normalised entry that was stored
   */
  addObject(meta) {
    const entry = this._buildEntry(meta);
    this._state.objects.push(entry);
    this._state.isDirty = true;
    this._emit('layers:changed', { objects: this._state.objects });
    return entry;
  }

  /** @param {string} id */
  removeObject(id) {
    this._state.objects = this._state.objects.filter(o => o.id !== id);
    if (this._state.activeObjectId === id) {
      this._state.activeObjectId = null;
      this._emit('selection:changed', { id: null });
    }
    this._state.isDirty = true;
    this._emit('layers:changed', { objects: this._state.objects });
  }

  /**
   * Partial update of an object's metadata. The id field is protected.
   *
   * @param {string} id
   * @param {object} updates
   */
  updateObjectMeta(id, updates) {
    const obj = this._state.objects.find(o => o.id === id);
    if (!obj) return;
    const { id: _ignored, ...safe } = updates;   // never overwrite id
    Object.assign(obj, safe);
    this._state.isDirty = true;
    this._emit('layers:changed', { objects: this._state.objects });
  }

  /**
   * Sync zIndex values from the live canvas stack order.
   * Call this whenever canvas z-order changes.
   *
   * @param {string[]} orderedIds  — object ids from bottom (0) to top
   */
  reorderObjects(orderedIds) {
    const map = {};
    this._state.objects.forEach(o => { map[o.id] = o; });

    const reordered = orderedIds
      .filter(id => map[id])
      .map((id, index) => {
        map[id].zIndex = index;
        return map[id];
      });

    // Orphans should not exist in practice but keep them out of the way
    const orphans = this._state.objects
      .filter(o => !orderedIds.includes(o.id))
      .map((o, i) => { o.zIndex = reordered.length + i; return o; });

    this._state.objects = [...orphans, ...reordered];
    this._emit('layers:changed', { objects: this._state.objects });
  }

  markSaved() {
    this._state.isDirty = false;
  }

  // ── Serialisation ────────────────────────────────────────────────────────

  /**
   * Serialise the editor state to a plain JSON-safe object.
   * Pair with CanvasManager.toJSON() to get the full snapshot.
   *
   * @returns {object}
   */
  toJSON() {
    return {
      templateId:     this._state.templateId,
      templateName:   this._state.templateName,
      templateConfig: this._state.templateConfig,
      objects:        this._state.objects.map(o => ({ ...o })),
    };
  }

  /**
   * Restore state from a serialised snapshot.
   * Typically called AFTER the Fabric canvas has been loaded from canvas_json,
   * so that Fabric object references already exist.
   *
   * @param {object} data  — output of toJSON()
   */
  fromJSON(data) {
    this._state.templateId     = data.templateId     ?? null;
    this._state.templateName   = data.templateName   ?? '';
    this._state.templateConfig = data.templateConfig ?? {};
    this._state.activeObjectId = null;
    this._state.objects        = (data.objects || []).map(o => this._buildEntry(o));
    this._state.isDirty        = false;

    this._emit('template:loaded', {
      id:     this._state.templateId,
      name:   this._state.templateName,
      config: this._state.templateConfig,
    });
    this._emit('layers:changed', { objects: this._state.objects });
  }

  // ── Events ───────────────────────────────────────────────────────────────

  /**
   * Subscribe to a state event.
   * @param {string}   event
   * @param {Function} fn
   * @returns {Function} unsubscribe
   */
  on(event, fn) {
    if (!this._listeners[event]) this._listeners[event] = [];
    this._listeners[event].push(fn);
    return () => {
      this._listeners[event] = this._listeners[event].filter(f => f !== fn);
    };
  }

  _emit(event, data) {
    if (!this._listeners[event]) return;
    this._listeners[event].forEach(fn => {
      try { fn(data); } catch (e) { console.error('StateManager event error:', event, e); }
    });
  }

  // ── Internal ─────────────────────────────────────────────────────────────

  /**
   * Produce a fully-specified ObjectMeta from any partial input.
   * Every field has a deterministic default; no field is ever undefined.
   *
   * Note: zIndex defaults to 0 here. It will be corrected by the next
   * reorderObjects() call (triggered by CanvasManager._syncLayerOrder).
   *
   * @param {object} meta
   * @returns {ObjectMeta}
   */
  _buildEntry(meta) {
    return {
      id:         meta.id         ?? Utils.generateId(),
      role:       meta.role       ?? null,
      slot_role:  meta.slot_role  ?? 'none',
      type:       meta.type       ?? 'rect',
      name:       meta.name       ?? 'Element',
      editable:   meta.editable   !== undefined ? !!meta.editable   : true,
      aiMappable: meta.aiMappable !== undefined ? !!meta.aiMappable : false,
      locked:     meta.locked     !== undefined ? !!meta.locked     : false,
      visible:    meta.visible    !== undefined ? !!meta.visible     : true,
      zIndex:     meta.zIndex     !== undefined ?   meta.zIndex      : 0,
    };
  }
}
