(function(){
  function ready(fn){
    if(document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function(){
    var form = document.getElementById('simku-budget-form');
    if(!form) return;

    var elId = document.getElementById('simku_budget_id');
    var elYm = document.getElementById('simku_budget_ym');
    var elCat = document.getElementById('simku_budget_category');
    var elUser = document.getElementById('simku_budget_user');
    var elAmt = document.getElementById('simku_budget_amount');
    var wrapTags = document.getElementById('simku_budget_tags_wrap');
    var wrapTagsManual = document.getElementById('simku_budget_tags_manual_wrap');
    var elTags = document.getElementById('simku_budget_tags');
    var elTagsManual = document.getElementById('simku_budget_tags_manual');
    var btnCancel = document.getElementById('simku-budget-cancel');
    var notice = document.getElementById('simku-budget-editing');

    var defaults = {
      id: '0',
      ym: (elYm && elYm.value) ? elYm.value : '',
      category: (elCat && elCat.value) ? elCat.value : 'expense',
      user: (elUser && elUser.value) ? elUser.value : 'all',
      amount: '',
      tag_filter: ''
    };

    function isTagCapableCategory(cat){
      return cat === 'income' || cat === 'expense';
    }

    function clearTagInputs(){
      if(elTags){
        for(var i=0;i<elTags.options.length;i++) elTags.options[i].selected = false;
      }
      if(elTagsManual) elTagsManual.value = '';
    }

    function toggleTagFields(){
      var cat = elCat ? String(elCat.value||'') : '';
      var show = isTagCapableCategory(cat);
      if(wrapTags) wrapTags.style.display = show ? '' : 'none';

      // Keep the Save/Cancel actions always visible, even when tags are not applicable.
      if(wrapTagsManual) wrapTagsManual.style.display = '';

      if(wrapTagsManual){
        var lbl = wrapTagsManual.querySelector('.simku-tags-manual-label');
        var inp = wrapTagsManual.querySelector('.simku-tags-manual-input');
        if(lbl) lbl.style.display = show ? '' : 'none';
        if(inp){
          inp.style.display = show ? '' : 'none';
          inp.disabled = !show;
        }
      }

      if(!show) clearTagInputs();
    }

    function setEditingState(on, label){
      if(!btnCancel) return;
      if(on){
        btnCancel.style.display = '';
        if(notice){
          notice.style.display = '';
          var p = notice.querySelector('p');
          if(p) p.textContent = label || 'Editing budget';
        }
      }else{
        btnCancel.style.display = 'none';
        if(notice){
          notice.style.display = 'none';
          var p2 = notice.querySelector('p');
          if(p2) p2.textContent = '';
        }
      }
    }

    function resetForm(){
      if(elId) elId.value = defaults.id;
      if(elYm) elYm.value = defaults.ym;
      if(elCat) elCat.value = defaults.category;
      if(elUser) elUser.value = defaults.user;
      if(elAmt) elAmt.value = defaults.amount;
      clearTagInputs();
      toggleTagFields();
      setEditingState(false);
    }

    function applyBudget(b){
      if(!b) return;
      if(elId) elId.value = String(b.id || '0');
      if(elYm && b.ym) elYm.value = String(b.ym);
      if(elCat && b.category) elCat.value = String(b.category);
      if(elUser && (b.user !== undefined)) elUser.value = String(b.user);
      if(elAmt && (b.budget !== undefined)) elAmt.value = String(b.budget);

      clearTagInputs();
      toggleTagFields();

      var cat = elCat ? String(elCat.value||'') : '';
      var tf = String(b.tag_filter || '');

      if(tf && isTagCapableCategory(cat)){
        var tags = tf.split(',').map(function(x){ return String(x||'').trim(); }).filter(Boolean);
        var unknown = [];
        if(elTags){
          var optMap = {};
          for(var i=0;i<elTags.options.length;i++) optMap[String(elTags.options[i].value)] = elTags.options[i];
          for(var j=0;j<tags.length;j++){
            var t = tags[j];
            if(optMap[t]) optMap[t].selected = true;
            else unknown.push(t);
          }
        } else {
          unknown = tags;
        }
        if(elTagsManual && unknown.length){
          elTagsManual.value = unknown.join(',');
        }
      }

      setEditingState(true, 'Editing budget ID #' + String(b.id || ''));
      try{ form.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e){}
    }

    document.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('.simku-budget-edit') : null;
      if(btn){
        e.preventDefault();
        var raw = btn.getAttribute('data-budget') || '{}';
        var data = {};
        try{ data = JSON.parse(raw) || {}; }catch(err){ data = {}; }
        applyBudget(data);
        return;
      }
    });

    if(elCat){
      elCat.addEventListener('change', function(){
        toggleTagFields();
      });
    }

    if(btnCancel){
      btnCancel.addEventListener('click', function(e){
        e.preventDefault();
        resetForm();
      });
    }

    toggleTagFields();
  });
})();
