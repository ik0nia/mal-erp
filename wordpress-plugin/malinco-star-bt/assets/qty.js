/* Malinco Star BT — pe pagina produs: afișează/ascunde badge-ul după preț × cantitate
   și recalculează ratele live. Citește tot din data-attrs pe [data-btstar-badge]. */
(function () {
  var QSEL = '#productQty, .qty-inp, form.cart input.qty, form.cart input[name="quantity"]';
  function qtyVal() { var q = document.querySelector(QSEL); return q ? (parseFloat(q.value) || 1) : 1; }
  function fmt(v, sym) {
    return (Math.round(v * 100) / 100).toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + sym;
  }
  function upd() {
    document.querySelectorAll('[data-btstar-badge]').forEach(function (b) {
      var unit = parseFloat(b.getAttribute('data-unit')) || 0;
      var min  = parseFloat(b.getAttribute('data-min')) || 0;
      var sym  = b.getAttribute('data-sym') || 'lei';
      var total = unit * qtyVal();
      if (total < min) { b.style.display = 'none'; return; }
      b.style.display = '';
      var rates = (b.getAttribute('data-rates') || '6,3').split(',');
      var rc = b.querySelector('.btstar__rates');
      if (rc) {
        rc.innerHTML = rates.map(function (n) {
          n = parseInt(n, 10);
          return '<span class="msbt-amt">' + fmt(total / n, sym) + '</span>/lună <span class="msbt-n">(' + n + ' rate)</span>';
        }).join(' &middot; ');
      }
    });
  }
  function bind() {
    upd();
    var q = document.querySelector(QSEL);
    if (q) { ['input', 'change', 'keyup'].forEach(function (ev) { q.addEventListener(ev, upd); }); }
    // stepper custom al temei (.qty-btn) + toggle unitate (.qty-unit-btn) + fallback WooCommerce (.quantity)
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.qty-btn, .qty-unit-btn, .quantity')) setTimeout(upd, 70);
    });
    if (window.jQuery) { jQuery(document.body).on('found_variation reset_data', function () { setTimeout(upd, 70); }); }
  }
  if (document.readyState !== 'loading') bind(); else document.addEventListener('DOMContentLoaded', bind);
})();
