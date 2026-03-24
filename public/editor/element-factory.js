'use strict';

/**
 * ElementFactory
 *
 * Creates Fabric.js objects with a complete, normalised obj.data block.
 * Every object produced here satisfies the template data model:
 *
 *   obj.data = {
 *     id          : string   — stable unique id (serialised with canvas JSON)
 *     role        : string|null — semantic role (title, subtitle, …)
 *     type        : string   — 'text' | 'rect' | 'image'
 *     name        : string   — human-readable layer name
 *     editable    : boolean  — user can change content / style
 *     aiMappable  : boolean  — AI pipeline can populate this field
 *     locked      : boolean
 *     visible     : boolean
 *   }
 *
 * Invariant for shapes and text: scaleX === scaleY === 1; width/height reflect
 * the rendered size in the 1080-px design space.
 * Exception — fabric.Image: width/height are the natural (source) dimensions;
 * scaleX/scaleY carry the desired display scale relative to those natural dims.
 *
 * Primary entry point for template building:
 *   ElementFactory.createForRole(role, visualOverrides, metaOverrides)
 *
 * Low-level entry points (backward-compatible):
 *   ElementFactory.createText(options)
 *   ElementFactory.createRect(options)
 *   ElementFactory.createImageFromUrl(url, options)   → Promise
 *   ElementFactory.clone(fabricObj)                   → Promise
 */
class ElementFactory {

  // ── Default slot_role per semantic role ──────────────────────────────────
  //
  // Maps a design role (how element was created) to its default slot_role
  // (which dynamic value the renderer should inject at runtime).
  // Designers can override slot_role individually in the properties panel.
  //
  static ROLE_TO_SLOT_ROLE = {
    title:            'title',
    subtitle:         'subtitle',
    badge:            'badge',
    CTA_text:         'cta',
    cta_button:       'cta',
    product_image:    'product_image',
    brand_logo:       'brand_logo',
    background_shape: 'none',
    simple_text:      'none',
    bullet_list:      'none',
  };

  // ── Role registry ────────────────────────────────────────────────────────
  //
  // Canonical defaults for each semantic role. These define what an AI agent
  // should produce when populating a template slot.
  //
  // Fields per role:
  //   fabricType   'textbox' | 'rect' | 'image' | 'group'
  //   name         default layer name shown in the panel
  //   editable     user may change content / style in the editor
  //   aiMappable   AI pipeline may write content into this slot
  //   visual       default Fabric properties (position, size, style)
  //
  static ROLE_DEFAULTS = {

    title: {
      fabricType: 'textbox',
      name:       'Titlu',
      editable:   true,
      aiMappable: true,
      visual: {
        text:       'Titlu produs',
        left:       60,   top:  200,  width: 960,
        fontSize:   80,
        fontFamily: 'Montserrat',
        fontWeight: 'bold',
        fill:       '#1e293b',
        textAlign:  'left',
      },
    },

    subtitle: {
      fabricType: 'textbox',
      name:       'Subtitlu',
      editable:   true,
      aiMappable: true,
      visual: {
        text:       'Descriere scurtă produs',
        left:       60,   top:  420,  width: 900,
        fontSize:   42,
        fontFamily: 'Open Sans',
        fontWeight: 'normal',
        fill:       '#475569',
        textAlign:  'left',
      },
    },

    product_image: {
      fabricType: 'image',
      name:       'Imagine produs',
      editable:   true,
      aiMappable: true,
      visual: {
        left:  600, top: 200, width: 420,
      },
    },

    badge: {
      fabricType: 'textbox',
      name:       'Badge / Etichetă',
      editable:   true,
      aiMappable: true,
      visual: {
        text:       'PROMO',
        left:       60,  top: 60,  width: 300,
        fontSize:   28,
        fontFamily: 'Montserrat',
        fontWeight: 'bold',
        fill:       '#1e40af',
        textAlign:  'left',
      },
    },

    CTA_text: {
      fabricType: 'textbox',
      name:       'CTA Text',
      editable:   true,
      aiMappable: true,
      visual: {
        text:       'Comandă acum',
        left:       60,  top: 920,  width: 400,
        fontSize:   36,
        fontFamily: 'Montserrat',
        fontWeight: 'bold',
        fill:       '#ffffff',
        textAlign:  'left',
      },
    },

    brand_logo: {
      fabricType: 'image',
      name:       'Logo brand',
      editable:   false,    // layout item — AI does not populate text
      aiMappable: false,
      visual: {
        left: 860, top: 920, width: 160,
      },
    },

    background_shape: {
      fabricType: 'rect',
      name:       'Formă fundal',
      editable:   true,
      aiMappable: false,
      visual: {
        left: 0, top: 0, width: 1080, height: 1080,
        fill: '#f1f5f9',
      },
    },

    simple_text: {
      fabricType: 'textbox',
      name:       'Text simplu',
      editable:   true,
      aiMappable: true,
      visual: {
        text:       'Text informativ',
        left:       60,  top:  640,  width: 500,
        fontSize:   28,
        fontFamily: 'Open Sans',
        fontWeight: 'normal',
        fill:       '#475569',
        textAlign:  'left',
        lineHeight: 1.4,
      },
    },

    bullet_list: {
      fabricType: 'textbox',
      name:       'Listă beneficii',
      editable:   true,
      aiMappable: true,
      visual: {
        text:       '• Beneficiu 1\n• Beneficiu 2\n• Beneficiu 3',
        left:       60,  top:  500,  width: 500,
        fontSize:   30,
        fontFamily: 'Open Sans',
        fontWeight: 'normal',
        fill:       '#1e293b',
        textAlign:  'left',
        lineHeight: 1.6,
      },
    },

    cta_button: {
      fabricType: 'group',
      name:       'Buton CTA',
      editable:   true,
      aiMappable: true,
      visual: {
        left:         60,
        top:          820,
        text:         'Comandă acum',
        fontSize:     32,
        fontFamily:   'Montserrat',
        fontWeight:   'bold',
        textColor:    '#ffffff',
        bgColor:      '#1e40af',
        paddingH:     48,
        paddingV:     20,
        borderRadius: 8,
      },
    },
  };

  // ── Role-aware factory ────────────────────────────────────────────────────

  /**
   * Create a Fabric object for a given semantic role.
   *
   * For roles with fabricType 'textbox' or 'rect', returns the object directly.
   * For roles with fabricType 'image', returns a Promise.
   * For roles with fabricType 'group', returns the group object directly.
   * If a 'product_image' or 'brand_logo' role is requested without a url,
   * a placeholder rect is returned instead (synchronously).
   *
   * @param {string} role            — key of ROLE_DEFAULTS
   * @param {object} [visual={}]     — override any default visual property
   *                                   (text, fill, left, top, width, …)
   * @param {object} [metaOverrides={}] — override name, editable, aiMappable, …
   * @returns {fabric.Object | Promise<fabric.Object>}
   */
  static createForRole(role, visual = {}, metaOverrides = {}) {
    const def = ElementFactory.ROLE_DEFAULTS[role];
    if (!def) throw new Error('ElementFactory.createForRole: unknown role "' + role + '"');

    const mergedVisual = Object.assign({}, def.visual, visual);

    const baseMeta = {
      role,
      type:       def.fabricType === 'textbox' ? 'text' : def.fabricType,
      name:       metaOverrides.name       ?? def.name,
      editable:   metaOverrides.editable   !== undefined ? metaOverrides.editable   : def.editable,
      aiMappable: metaOverrides.aiMappable !== undefined ? metaOverrides.aiMappable : def.aiMappable,
      locked:     metaOverrides.locked     ?? false,
      visible:    metaOverrides.visible    !== undefined ? metaOverrides.visible    : true,
    };

    if (def.fabricType === 'textbox') {
      return ElementFactory.createText(Object.assign({}, mergedVisual, baseMeta));
    }

    if (def.fabricType === 'rect') {
      return ElementFactory.createRect(Object.assign({}, mergedVisual, baseMeta));
    }

    if (def.fabricType === 'image') {
      if (!mergedVisual.url) {
        // No image URL yet — create a labelled placeholder rect
        return ElementFactory.createRect(Object.assign({}, {
          width:  mergedVisual.width  || 400,
          height: mergedVisual.height || 400,
          left:   mergedVisual.left   || 100,
          top:    mergedVisual.top    || 100,
          fill:   '#e2e8f0',
        }, baseMeta));
      }
      return ElementFactory.createImageFromUrl(
        mergedVisual.url,
        Object.assign({}, mergedVisual, baseMeta),
      );
    }

    if (def.fabricType === 'group') {
      return ElementFactory.createCtaButton(Object.assign({}, mergedVisual, baseMeta));
    }
  }

  // ── Text / Textbox ────────────────────────────────────────────────────────

  /**
   * @param {object} options
   * @returns {fabric.Textbox}
   */
  static createText(options = {}) {
    const obj = new fabric.Textbox(options.text || 'Text', {
      left:            options.left        !== undefined ? options.left        : 100,
      top:             options.top         !== undefined ? options.top         : 100,
      width:           options.width       !== undefined ? options.width       : 400,
      fontSize:        options.fontSize    !== undefined ? options.fontSize    : 48,
      fontFamily:      options.fontFamily  || 'Montserrat',
      fontWeight:      options.fontWeight  || 'normal',
      fill:            options.fill        || '#111111',
      textAlign:       options.textAlign   || 'left',
      lineHeight:      options.lineHeight  || 1.2,
      charSpacing:     options.charSpacing || 0,
      underline:       options.underline   || false,
      fontStyle:       options.fontStyle   || 'normal',
      stroke:          options.stroke      || null,
      strokeWidth:     options.strokeWidth || 0,
      opacity:         options.opacity     !== undefined ? options.opacity     : 1,
      angle:           options.angle       || 0,
      splitByGrapheme: false,
    });

    obj.data = ElementFactory._buildMeta(options, 'text', options.name || 'Text');
    obj.setCoords();
    return obj;
  }

  // ── Rectangle ─────────────────────────────────────────────────────────────

  /**
   * @param {object} options
   * @returns {fabric.Rect}
   */
  static createRect(options = {}) {
    const obj = new fabric.Rect({
      left:        options.left        !== undefined ? options.left        : 100,
      top:         options.top         !== undefined ? options.top         : 100,
      width:       options.width       !== undefined ? options.width       : 300,
      height:      options.height      !== undefined ? options.height      : 200,
      fill:        options.fill        || '#e2e8f0',
      stroke:      options.stroke      || null,
      strokeWidth: options.strokeWidth || 0,
      rx:          options.rx          || 0,
      ry:          options.ry          || 0,
      opacity:     options.opacity     !== undefined ? options.opacity     : 1,
      angle:       options.angle       || 0,
    });

    obj.data = ElementFactory._buildMeta(options, 'rect', options.name || 'Dreptunghi');
    obj.setCoords();
    return obj;
  }

  // ── Image from URL ────────────────────────────────────────────────────────

  /**
   * Load an image from a URL and return a positioned Fabric.Image.
   * width/height remain at the image's natural (source) dimensions.
   * scaleX/scaleY express the desired display size relative to natural dims.
   *
   * @param {string} url
   * @param {object} options
   * @returns {Promise<fabric.Image>}
   */
  static createImageFromUrl(url, options = {}) {
    return new Promise((resolve, reject) => {
      fabric.Image.fromURL(url, (img, isError) => {
        if (isError || !img) {
          reject(new Error('Nu s-a putut încărca imaginea: ' + url));
          return;
        }

        img.set({
          left:    options.left    !== undefined ? options.left    : 100,
          top:     options.top     !== undefined ? options.top     : 100,
          opacity: options.opacity !== undefined ? options.opacity : 1,
          angle:   options.angle   || 0,
        });

        // Scale to desired display size
        if (options.width) {
          img.scaleToWidth(options.width);
        } else if (options.height) {
          img.scaleToHeight(options.height);
        } else if (img.width > 400) {
          img.scaleToWidth(400);
        }

        // scaleX/scaleY set by scaleToWidth/Height above are intentionally
        // preserved. For fabric.Image, width/height are the natural source
        // dimensions; display size is expressed via scaleX/scaleY. Absorbing
        // scale into width/height would corrupt repeated resizes.

        img.data = ElementFactory._buildMeta(options, 'image', options.name || 'Imagine');
        img.setCoords();
        resolve(img);
      }, { crossOrigin: 'anonymous' });
    });
  }

  // ── CTA Button (Group) ────────────────────────────────────────────────────

  /**
   * Create a CTA button as a Fabric Group: background Rect + label Textbox.
   *
   * Coordinate notes:
   *  - Children are created at (0,0) with originX/Y: 'center' so that
   *    the group bounding-box centre sits at the canvas origin.
   *  - The Group is created without an explicit left/top so Fabric derives
   *    its anchor from the bounding-box (also at origin).
   *  - We then call group.set({ left, top }) to move it to the desired position.
   *  - lockScalingX/Y are always true — button size is driven by content/panel.
   *
   * @param {object} options
   * @returns {fabric.Group}
   */
  static createCtaButton(options = {}) {
    const text         = options.text           || 'Comandă acum';
    const fontSize     = options.fontSize       || 32;
    const fontFamily   = options.fontFamily     || 'Montserrat';
    const fontWeight   = options.fontWeight     || 'bold';
    const textColor    = options.textColor      || '#ffffff';
    const bgColor      = options.bgColor        || '#1e40af';
    const paddingH     = options.paddingH       !== undefined ? options.paddingH     : 48;
    const paddingV     = options.paddingV       !== undefined ? options.paddingV     : 20;
    const borderRadius = options.borderRadius   !== undefined ? options.borderRadius : 8;
    const groupLeft    = options.left           !== undefined ? options.left          : 60;
    const groupTop     = options.top            !== undefined ? options.top           : 820;

    const textH  = Math.round(fontSize * 1.25);
    const textW  = Math.max(Math.round(text.length * fontSize * 0.6), fontSize * 2);
    const btnW   = textW + paddingH * 2;
    const btnH   = textH + paddingV * 2;

    const bgRect = new fabric.Rect({
      left: 0, top: 0,
      width: btnW, height: btnH,
      fill: bgColor,
      rx: borderRadius, ry: borderRadius,
      stroke: null, strokeWidth: 0,
      originX: 'center', originY: 'center',
      selectable: false, evented: false,
    });

    const label = new fabric.Textbox(text, {
      left: 0, top: 0,
      width: Math.max(textW, fontSize),
      fontSize:        fontSize,
      fontFamily:      fontFamily,
      fontWeight:      fontWeight,
      fill:            textColor,
      textAlign:       'center',
      lineHeight:      1,
      splitByGrapheme: false,
      originX: 'center', originY: 'center',
      selectable: false, evented: false,
    });

    // Create group — Fabric derives left/top from bounding-box centre (0,0)
    const group = new fabric.Group([bgRect, label], {
      lockScalingX: true,
      lockScalingY: true,
    });

    // Move to desired canvas position (group.left = left edge, originX default = 'left')
    group.set({ left: groupLeft, top: groupTop });
    group.setCoords();

    group.data = ElementFactory._buildMeta(
      Object.assign({}, options, { role: 'cta_button', type: 'group', name: options.name || 'Buton CTA' }),
      'group', 'Buton CTA'
    );
    group.data.ctaProps = {
      text, fontSize, fontFamily, fontWeight,
      textColor, bgColor, paddingH, paddingV, borderRadius,
    };

    return group;
  }

  /**
   * Rebuild the visual of an existing cta_button Group from a props object.
   * Used by the properties panel when any CTA property changes.
   *
   * Keeps the GROUP CENTRE fixed on the canvas — the button grows/shrinks
   * symmetrically when font size or padding change.
   *
   * @param {fabric.Group} group
   * @param {object}       props   — same shape as ctaProps
   */
  static rebuildCtaButton(group, props) {
    const text         = props.text           || 'CTA';
    const fontSize     = props.fontSize       || 32;
    const fontFamily   = props.fontFamily     || 'Montserrat';
    const fontWeight   = props.fontWeight     || 'bold';
    const textColor    = props.textColor      || '#ffffff';
    const bgColor      = props.bgColor        || '#1e40af';
    const paddingH     = props.paddingH       !== undefined ? props.paddingH     : 48;
    const paddingV     = props.paddingV       !== undefined ? props.paddingV     : 20;
    const borderRadius = props.borderRadius   !== undefined ? props.borderRadius : 8;

    const textH    = Math.round(fontSize * 1.25);
    const textW    = Math.max(Math.round(text.length * fontSize * 0.6), fontSize * 2);
    const newBtnW  = textW + paddingH * 2;
    const newBtnH  = textH + paddingV * 2;
    const oldW     = group.width  || newBtnW;
    const oldH     = group.height || newBtnH;

    const objs = group.getObjects();
    if (objs.length < 2) return;
    const bgRect = objs[0];
    const label  = objs[1];

    bgRect.set({
      width: newBtnW, height: newBtnH,
      fill:  bgColor,
      rx:    borderRadius,
      ry:    borderRadius,
    });
    bgRect.setCoords();

    label.set({
      text:       text,
      fontSize:   fontSize,
      fontFamily: fontFamily,
      fontWeight: fontWeight,
      fill:       textColor,
      width:      Math.max(textW, fontSize),
    });
    label.setCoords();

    // Keep visual CENTRE fixed while the button resizes
    const dw = newBtnW - oldW;
    const dh = newBtnH - oldH;
    group.set({
      width:  newBtnW,
      height: newBtnH,
      left:   Math.round((group.left || 0) - dw / 2),
      top:    Math.round((group.top  || 0) - dh / 2),
    });
    group.dirty = true;
    group.setCoords();

    if (group.data) {
      group.data.ctaProps = {
        text, fontSize, fontFamily, fontWeight,
        textColor, bgColor, paddingH, paddingV, borderRadius,
      };
    }
  }

  // ── Clone ─────────────────────────────────────────────────────────────────

  /**
   * Deep-clone a Fabric object. The clone gets a new unique id.
   * Role and all other metadata are preserved on the clone.
   *
   * @param {fabric.Object} obj
   * @returns {Promise<fabric.Object>}
   */
  static clone(obj) {
    return new Promise((resolve) => {
      obj.clone((cloned) => {
        cloned.data = Object.assign({}, obj.data, {
          id:   Utils.generateId(),
          name: (obj.data?.name ?? 'Element') + ' (copie)',
          // role is intentionally kept — the clone serves the same slot
        });
        cloned.set({
          left: (cloned.left || 0) + 20,
          top:  (cloned.top  || 0) + 20,
        });
        cloned.setCoords();
        resolve(cloned);
      }, [
        'data',
        'selectable', 'evented',
        'lockMovementX', 'lockMovementY',
        'lockRotation', 'lockScalingX', 'lockScalingY',
      ]);
    });
  }

  // ── Internal ──────────────────────────────────────────────────────────────

  /**
   * Build the canonical obj.data block.
   *
   * obj.data is stored on the Fabric object and is included in canvas JSON
   * serialisation. It intentionally does NOT include zIndex — that is a
   * StateManager concern derived from canvas stack order.
   *
   * @param {object} options       — caller-supplied options (may contain meta fields)
   * @param {string} defaultType   — 'text' | 'rect' | 'image' | 'group'
   * @param {string} defaultName   — fallback layer name
   * @returns {ObjectMeta}
   */
  static _buildMeta(options, defaultType, defaultName) {
    const defaultSlot = ElementFactory.ROLE_TO_SLOT_ROLE[options.role] ?? 'none';
    return {
      id:         options.id         ?? Utils.generateId(),
      role:       options.role       ?? null,
      slot_role:  options.slot_role  ?? defaultSlot,
      type:       options.type       ?? defaultType,
      name:       options.name       ?? defaultName,
      editable:   options.editable   !== undefined ? !!options.editable   : true,
      aiMappable: options.aiMappable !== undefined ? !!options.aiMappable : false,
      locked:     options.locked     !== undefined ? !!options.locked     : false,
      visible:    options.visible    !== undefined ? !!options.visible    : true,
    };
  }
}
