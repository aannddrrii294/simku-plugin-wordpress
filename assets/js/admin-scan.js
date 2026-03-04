(function(){
  function el(id){ return document.getElementById(id); }

  function setFieldState(input, enabled){
    if (!input) return;
    if (enabled){
      var prev = input.getAttribute('data-prev') || '';
      input.disabled = false;
      input.required = true;
      input.type = 'date';
      if (input.value === 'N/A') input.value = prev || '';
      input.placeholder = '';
    } else {
      // Save previous date before disabling
      if (input.type === 'date' && input.value) input.setAttribute('data-prev', input.value);
      input.disabled = true;
      input.required = false;
      // Use text + N/A so users understand it's not applicable.
      input.type = 'text';
      input.value = 'N/A';
    }
  }

  function applyCategoryRules(){
    var cat = el('simku_scan_kategori');
    var purchase = el('simku_scan_purchase_date');
    var receive  = el('simku_scan_receive_date');
    if (!cat || !purchase || !receive) return;
    var v = (cat.value || '').toLowerCase();
    if (v === 'income'){
      setFieldState(receive, true);
      setFieldState(purchase, false);
    } else {
      setFieldState(purchase, true);
      setFieldState(receive, false);
    }
  }

  function initLineItems(){
    var wrap = el('simak-scan-line-items');
    var addBtn = el('simak-scan-add-item-row');
    if (!wrap || !addBtn) return;

    var template = wrap.querySelector('.simak-line-item-row');
    if (!template) return;

    function renumber(){
      var rows = wrap.querySelectorAll('.simak-line-item-row');
      rows.forEach(function(row){
        var rm = row.querySelector('.simak-remove-row');
        if (rm) rm.style.visibility = (rows.length > 1) ? 'visible' : 'hidden';
      });
    }

    addBtn.addEventListener('click', function(){
      var clone = template.cloneNode(true);
      clone.querySelectorAll('input').forEach(function(inp){
        var def = inp.getAttribute('data-default');
        inp.value = (def !== null) ? def : '';
      });
      wrap.appendChild(clone);
      renumber();
    });

    wrap.addEventListener('click', function(e){
      var btn = e.target.closest('.simak-remove-row');
      if (!btn) return;
      var row = btn.closest('.simak-line-item-row');
      var rows = wrap.querySelectorAll('.simak-line-item-row');
      if (row && rows.length > 1){
        row.remove();
        renumber();
      }
    });

    renumber();
  }

  document.addEventListener('DOMContentLoaded', function(){
    var cat = el('simku_scan_kategori');
    if (cat){
      cat.addEventListener('change', applyCategoryRules);
      applyCategoryRules();
    }
    initLineItems();
  });
})();