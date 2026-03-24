'use strict';

/**
 * CanvasPresets
 *
 * Central registry of all supported canvas formats.
 * Adding a new format = adding one entry to PRESETS.
 *
 * Each preset defines a LOGICAL design space in pixels.
 * ZoomManager fits this space into the available viewport at runtime.
 *
 * Template JSON persists canvas info as:
 *   config.canvas = { width, height, preset }
 */
class CanvasPresets {

  static PRESETS = {
    square_post: {
      key:    'square_post',
      width:  1080,
      height: 1080,
      label:  '1080×1080',
      name:   'Square Post',
    },
    portrait_post: {
      key:    'portrait_post',
      width:  1080,
      height: 1350,
      label:  '1080×1350',
      name:   'Portrait Post',
    },
    story: {
      key:    'story',
      width:  1080,
      height: 1920,
      label:  '1080×1920',
      name:   'Story / Reel',
    },
    landscape: {
      key:    'landscape',
      width:  1920,
      height: 1080,
      label:  '1920×1080',
      name:   'Landscape',
    },
    landscape_ad: {
      key:    'landscape_ad',
      width:  1200,
      height: 628,
      label:  '1200×628',
      name:   'Landscape Ad',
    },
    product_square: {
      key:    'product_square',
      width:  1000,
      height: 1000,
      label:  '1000×1000',
      name:   'Product Square',
    },
    vertical_ad: {
      key:    'vertical_ad',
      width:  1080,
      height: 1440,
      label:  '1080×1440',
      name:   'Vertical Ad',
    },
  };

  static DEFAULT_KEY = 'square_post';

  /**
   * Get a preset by key. Falls back to the default.
   * @param {string} key
   * @returns {{ key, width, height, label, name }}
   */
  static get(key) {
    return CanvasPresets.PRESETS[key] ?? CanvasPresets.PRESETS[CanvasPresets.DEFAULT_KEY];
  }

  /**
   * Resolve canvas dimensions from a saved template config.
   *
   * Lookup order:
   *   1. config.canvas.preset key (preferred)
   *   2. config.canvas.width + height (custom size)
   *   3. default square_post
   *
   * @param {object} config  — template.config
   * @returns {{ key, width, height, label, name }}
   */
  static fromConfig(config) {
    const c = config?.canvas;
    if (!c) return CanvasPresets.get(CanvasPresets.DEFAULT_KEY);

    if (c.preset && CanvasPresets.PRESETS[c.preset]) {
      return CanvasPresets.PRESETS[c.preset];
    }

    if (c.width && c.height) {
      return {
        key:    'custom',
        width:  c.width,
        height: c.height,
        label:  `${c.width}×${c.height}`,
        name:   'Custom',
      };
    }

    return CanvasPresets.get(CanvasPresets.DEFAULT_KEY);
  }

  /** All presets as an ordered array (for building UI). */
  static all() {
    return Object.values(CanvasPresets.PRESETS);
  }
}
