'use strict';

const Utils = {
  generateId() {
    return 'el_' + Date.now().toString(36) + '_' + Math.random().toString(36).substr(2, 6);
  },

  debounce(fn, ms = 150) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  },

  clamp(v, min, max) { return Math.min(Math.max(v, min), max); },

  deepClone(o) { return JSON.parse(JSON.stringify(o)); },

  toHex(color) {
    if (!color || typeof color !== 'string') return '#000000';
    if (color.startsWith('#') && (color.length === 7 || color.length === 4)) return color;
    // Handle rgb/rgba
    const m = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!m) return '#000000';
    return '#' + [m[1], m[2], m[3]].map(n => (+n).toString(16).padStart(2, '0')).join('');
  },
};
