(function(){
  function ready(fn){
    if(document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function initNotesModal(){
    var modal = document.getElementById('simku-rem-notes-modal');
    var body = document.getElementById('simku-rem-notes-body');
    var titleEl = document.getElementById('simku-rem-notes-title');
    if(!modal || !body) return;

    function closeModal(){
      modal.style.display = 'none';
      body.innerHTML = '';
      if(titleEl) titleEl.textContent = 'Notes';
    }

    function openModal(title, notes){
      if(titleEl) titleEl.textContent = title || 'Notes';
      body.innerHTML = '';
      var box = document.createElement('div');
      box.className = 'simku-notes-box';
      box.textContent = notes || '';
      body.appendChild(box);
      modal.style.display = 'block';
    }

    document.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('.simku-rem-view-notes') : null;
      if(btn){
        e.preventDefault();
        var raw = btn.getAttribute('data-notes') || '""';
        var notes = '';
        try{ notes = JSON.parse(raw) || ''; }catch(err){ notes = String(raw || ''); }
        var title = btn.getAttribute('data-title') || 'Notes';
        openModal(title, notes);
        return;
      }

      var closeBtn = e.target && e.target.closest ? e.target.closest('.simku-modal-close') : null;
      if(closeBtn){
        e.preventDefault();
        closeModal();
        return;
      }

      if(e.target && e.target.classList && e.target.classList.contains('simku-modal-backdrop')){
        closeModal();
      }
    });

    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape') closeModal();
    });
  }

  ready(function(){
    initNotesModal();
  });
})();
