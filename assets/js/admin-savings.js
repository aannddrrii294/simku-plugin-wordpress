(function(){
  function ready(fn){
    if(document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function initTrend(){
    var el = document.getElementById('fl-savings-trend');
    if(!el) return;
    if(typeof window.echarts === 'undefined'){
      el.innerHTML = '<div class="fl-muted" style="padding:10px">⚠ ECharts library not loaded (chart cannot be rendered).</div>';
      return;
    }

    try{ if(window.echarts.getInstanceByDom(el)) return; }catch(e){}

    var labels = [];
    var values = [];
    try{ labels = JSON.parse(el.getAttribute('data-labels')||'[]') || []; }catch(e){ labels = []; }
    try{ values = JSON.parse(el.getAttribute('data-values')||'[]') || []; }catch(e){ values = []; }

    var chart = window.echarts.init(el);
    chart.setOption({
      tooltip: { trigger: 'axis' },
      grid: { left: 10, right: 18, top: 35, bottom: 45, containLabel: true },
      xAxis: { type: 'category', data: labels },
      yAxis: { type: 'value' },
      series: [{ name: 'Savings', type: 'line', data: values }]
    }, true);

    window.addEventListener('resize', function(){
      try{ chart.resize(); }catch(e){}
    });
  }

  function initModal(){
    var modal = document.getElementById('simku-sv-img-modal');
    var body = document.getElementById('simku-sv-img-body');
    if(!modal || !body) return;

    function closeModal(){
      modal.style.display = 'none';
      body.innerHTML = '';
    }

    function openModal(urls){
      var html = '';
      if(!urls || !urls.length){
        html = '<div class="fl-muted">No images.</div>';
      }else{
        html = '<div class="simku-img-grid">';
        for(var i=0;i<urls.length;i++){
          var u = String(urls[i]||'');
          if(!u) continue;
          html += '<a href="' + u + '" target="_blank" rel="noopener">' +
                  '<img src="' + u + '" alt="attachment" loading="lazy">' +
                  '</a>';
        }
        html += '</div>';
      }
      body.innerHTML = html;
      modal.style.display = 'block';
    }

    document.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('.simku-sv-view-images') : null;
      if(btn){
        e.preventDefault();
        var raw = btn.getAttribute('data-urls') || '[]';
        var urls = [];
        try{ urls = JSON.parse(raw) || []; }catch(err){ urls = []; }
        openModal(urls);
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
    initModal();
    // ECharts scripts are enqueued in footer; but DOMContentLoaded should be enough.
    // If echarts loads later, the init will show a warning; users can reload.
    initTrend();
    window.addEventListener('load', initTrend);
  });
})();
