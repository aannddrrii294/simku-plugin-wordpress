(function($){
  // ---------- small utilities ----------
  function isPlainObject(o){
    return Object.prototype.toString.call(o) === '[object Object]';
  }

  // Format numbers using Indonesian grouping (e.g. 1500000 -> 1.500.000)
  function fmtNumberID(v){
    var n = Number(v);
    if(!isFinite(n)) return String(v);
    try{
      return new Intl.NumberFormat('id-ID').format(n);
    }catch(e){
      // Fallback: basic thousands grouping
      return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
  }

  // Deep-merge plain objects (used to apply per-chart option overrides)
  function mergeDeep(target, src){
    if(!isPlainObject(target)) target = {};
    if(!isPlainObject(src)) return target;
    Object.keys(src).forEach(function(k){
      var v = src[k];
      if(isPlainObject(v)){
        target[k] = mergeDeep(target[k], v);
      } else {
        target[k] = v;
      }
    });
    return target;
  }

  function ajaxChartData(params, cb){
    $.ajax({
      url: SIMAK_AJAX.ajax_url,
      method: 'POST',
      dataType: 'json',
      data: Object.assign({action:'simak_chart_data', nonce: SIMAK_AJAX.nonce}, params),
      success: function(res){ cb(res); },
      error: function(xhr){
        var msg = 'Request failed';
        try {
          if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) msg = xhr.responseJSON.data.message;
          else if(xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
          else if(xhr && xhr.responseText) msg = (xhr.status?('HTTP '+xhr.status+': '):'') + String(xhr.responseText).slice(0,200);
        } catch(e) {}
        cb({success:false, data:{message: msg}});
      }
    });
  }

  function buildOption(payload){
    var type = payload.type || 'line';
    var x = payload.x || [];
    var series = payload.series || [];
    var msg = (payload && payload.message) ? String(payload.message) : '';

    var isMobile = !!(window.matchMedia && window.matchMedia('(max-width: 782px)').matches);

    // Grid spacing: keep plot area as wide as possible.
    // Use a small base left padding and rely on `containLabel:true` to auto-fit the Y labels.
    // (Estimating label width *and* using containLabel makes the left gap too large.)
    var gridLeft = isMobile ? 2 : 8;

    var opt = {
      tooltip: { trigger: 'axis' },
      legend: { top: 0 },
      grid: { left: gridLeft, right: (isMobile ? 10 : 18), top: 40, bottom: 50, containLabel: true },
      xAxis: { type: 'category', data: x },
      yAxis: { type: 'value', axisLabel: { formatter: function(v){ return fmtNumberID(v); }, margin: (isMobile ? 2 : 6) } },
      series: []
    };

    // Responsive defaults for WordPress admin mobile breakpoint.
    if(isMobile){
      opt.grid.left = 2;
      opt.grid.right = 10;
      opt.grid.top = 50;
      opt.grid.bottom = (x.length > 6) ? 70 : 55;

      opt.legend.type = 'scroll';
      opt.legend.textStyle = opt.legend.textStyle || {};
      opt.legend.textStyle.fontSize = 11;

      opt.xAxis.axisLabel = opt.xAxis.axisLabel || {};
      opt.xAxis.axisLabel.rotate = (x.length > 6) ? 45 : 0;
      opt.xAxis.axisLabel.hideOverlap = true;
      opt.xAxis.axisLabel.fontSize = 10;

      opt.yAxis.axisLabel = opt.yAxis.axisLabel || {};
      opt.yAxis.axisLabel.fontSize = 10;
      opt.yAxis.axisLabel.margin = 2;
    }

    if(type === 'pie' || type === 'donut'){
      var data = [];
      if(series.length && series[0].data){
        for(var i=0;i<x.length;i++) data.push({ name: x[i], value: series[0].data[i] });
      }
      opt = {
        tooltip: { trigger: 'item' },
        legend: { top: 0 },
        series: [{ type: 'pie', radius: (type==='donut'?['40%','70%']:'60%'), data: data }]
      };
      if(!data.length){
        opt.graphic = { type: 'text', left: 'center', top: 'middle', style: { text: (msg||'No data'), fontSize: 14 } };
      }
      if(isMobile){
        opt.legend = opt.legend || {};
        opt.legend.type = 'scroll';
        opt.legend.textStyle = opt.legend.textStyle || {};
        opt.legend.textStyle.fontSize = 11;

        if(opt.series && opt.series[0]){
          opt.series[0].radius = (type==='donut'?['35%','65%']:'55%');
          opt.series[0].label = opt.series[0].label || {};
          if(!opt.series[0].label.fontSize) opt.series[0].label.fontSize = 10;
        }
      }

      if(payload && payload.option){
        opt = mergeDeep(opt, payload.option);
      }
      return opt;
    }

    for(var j=0;j<series.length;j++){
      opt.series.push({
        name: series[j].name,
        type: (type==='area'?'line':type),
        data: series[j].data || [],
        areaStyle: (type==='area'?{}:undefined)
      });
    }

    var hasData = x.length && opt.series.length && (opt.series[0].data && opt.series[0].data.length);
    if(!hasData){
      opt.graphic = { type: 'text', left: 'center', top: 'middle', style: { text: (msg||'No data'), fontSize: 14 } };
    }

    if(payload && payload.option){
      opt = mergeDeep(opt, payload.option);
    }

    // Apply responsive tweaks again after custom overrides (keep charts usable on mobile).
    if(isMobile){
      if(opt.grid){
        if(typeof opt.grid.left === 'number' && opt.grid.left > 70) opt.grid.left = 58;
        if(typeof opt.grid.right === 'number' && opt.grid.right > 20) opt.grid.right = 10;
        if(typeof opt.grid.bottom === 'number' && opt.grid.bottom < 55) opt.grid.bottom = (x.length > 6) ? 70 : 55;
      }
      if(opt.xAxis && opt.xAxis.axisLabel){
        if(opt.xAxis.axisLabel.fontSize == null) opt.xAxis.axisLabel.fontSize = 10;
        if(opt.xAxis.axisLabel.rotate == null) opt.xAxis.axisLabel.rotate = (x.length > 6) ? 45 : 0;
        opt.xAxis.axisLabel.hideOverlap = true;
      }
      if(opt.yAxis && opt.yAxis.axisLabel){
        if(opt.yAxis.axisLabel.fontSize == null) opt.yAxis.axisLabel.fontSize = 10;
        if(opt.yAxis.axisLabel.margin == null) opt.yAxis.axisLabel.margin = 4;
      }
      if(opt.legend){
        opt.legend.type = opt.legend.type || 'scroll';
        opt.legend.textStyle = opt.legend.textStyle || {};
        if(opt.legend.textStyle.fontSize == null) opt.legend.textStyle.fontSize = 11;
      }
    }
    return opt;
  }

  function renderBox($el, payload){
    if(!$el.length) return;
    var chart = echarts.getInstanceByDom($el[0]);
    if(!chart) chart = echarts.init($el[0]);
    chart.setOption(buildOption(payload), true);
    window.addEventListener('resize', function(){ chart.resize(); });
  }

  // ---------------- Drag & Drop Builder ----------------
  function setDrop(target, fieldLabel, fieldValue){
    var $drop = $('.fl-drop[data-target="'+target+'"]');
    if(!$drop.length) return;
    $drop.empty().append($('<span class="fl-drop-value"></span>').text(fieldLabel));
    $drop.removeClass('fl-drop-empty');
    $('#fl_'+target).val(fieldValue);
    if(target.indexOf('metric_')===0){
      $('#fl_'+target).val(fieldValue);
    }
  }

  function clearDrop(target){
    var $drop = $('.fl-drop[data-target="'+target+'"]');
    $drop.empty().append($('<span class="fl-drop-hint">Drop a field</span>')).addClass('fl-drop-empty');
    $('#fl_'+target).val('');
    if(target.indexOf('metric_')===0){
      $('#fl_'+target).val('');
    }
  }

  function hydrateFromInputs(){
    // X & Series
    var x = $('#fl_x').val();
    var series = $('#fl_series').val();
    if(x) setDrop('x', x, x); else clearDrop('x');
    if(series) setDrop('series', series, series); else clearDrop('series');

    for(var i=1;i<=3;i++){
      var v = $('#fl_metric_'+i).val();
      if(v) setDrop('metric_'+i, v, v); else clearDrop('metric_'+i);
    }
    var cid = $('#fl_chart_id').val();
    $('#fl_shortcode_id').text(cid || '...');
  }

  function bindDrag(){
    $(document).on('dragstart', '.fl-pill', function(e){
      var field = $(this).data('field');
      var kind = $(this).data('kind');
      e.originalEvent.dataTransfer.setData('text/plain', JSON.stringify({field:field, kind:kind, label: $(this).text()}));
    });

    $(document).on('dragover', '.fl-drop', function(e){
      e.preventDefault();
      $(this).addClass('fl-drop-over');
    });
    $(document).on('dragleave', '.fl-drop', function(){ $(this).removeClass('fl-drop-over'); });

    $(document).on('drop', '.fl-drop', function(e){
      e.preventDefault();
      $(this).removeClass('fl-drop-over');
      var target = $(this).data('target');
      var raw = e.originalEvent.dataTransfer.getData('text/plain');
      if(!raw) return;
      var obj;
      try{ obj = JSON.parse(raw); }catch(err){ return; }

      // Validate kinds
      var isMetricSlot = (target.indexOf('metric_')===0);
      if(isMetricSlot && obj.kind!=='metric') return;
      if((target==='x' || target==='series') && obj.kind!=='dim') return;

      // Write input
      if(target==='x') $('#fl_x').val(obj.field);
      if(target==='series') $('#fl_series').val(obj.field);
      if(isMetricSlot) $('#fl_'+target).val(obj.field);

      // Update UI
      $(this).empty().append($('<span class="fl-drop-value"></span>').text(obj.label));
    });
  }

  function buildConfigFromForm(){
    var cfg = {
      id: $('#fl_chart_id').val() || '',
      title: $('#fl_title').val() || 'Untitled',
      chart_type: $('#fl_chart_type').val() || 'bar',
      data_source_mode: $('#fl_data_source_mode').val() || 'builder',
      sql_query: $('#fl_sql_query').val() || '',
      custom_option_json: $('#fl_custom_option_json').val() || '',
      is_public: ($('#fl_is_public').length && $('#fl_is_public').is(':checked')) ? 1 : 0,
      date_basis: $('#fl_date_basis').val() || 'input',
      show_on_dashboard: ($('input[name="show_on_dashboard"]').length && $('input[name="show_on_dashboard"]').is(':checked')) ? 1 : 0,
      x: $('#fl_x').val() || 'day',
      series: $('#fl_series').val() || '',
      metrics: [],
      range: {
        mode: $('#fl_range_mode').val(),
        days: parseInt($('#fl_range_days').val()||'30',10),
        from: $('#fl_range_from').val() || '',
        to: $('#fl_range_to').val() || ''
      },
      filter: {
        kategori: [],
        top_n: parseInt(($('input[name="top_n"]').val()||'0'),10)
      }
    };
    $('input[name="filter_kategori[]"]:checked').each(function(){ cfg.filter.kategori.push($(this).val()); });
    for(var i=1;i<=3;i++){
      var m = $('#fl_metric_'+i).val();
      if(!m) continue;
      cfg.metrics.push({metric:m, agg: $('#fl_agg_'+i).val() || 'SUM'});
    }
    if(!cfg.metrics.length) cfg.metrics.push({metric:'amount_total', agg:'SUM'});
    return cfg;
  }

  function previewChart(){
    var cfg = buildConfigFromForm();
    ajaxChartData({id: cfg.id || 'preview', config: JSON.stringify(cfg)}, function(res){
      if(!res || !res.success){
        alert((res && res.data && res.data.message) ? res.data.message : 'Preview failed');
        return;
      }
      renderBox($('#fl_chart_preview'), res.data);
    });
  }

  function previewSaved(id, title){
    ajaxChartData({id:id}, function(res){
      if(!res || !res.success){
        alert((res && res.data && res.data.message) ? res.data.message : 'Preview failed');
        return;
      }
      $('#fl_row_preview_title').text(title || id);
      $('#fl_row_preview_wrap').show();
      renderBox($('#fl_row_preview'), res.data);
    });
  }

  $(function(){
    if(typeof SIMAK_AJAX === 'undefined') return;

    hydrateFromInputs();
    bindDrag();

    $('#fl_preview_btn').on('click', function(){ previewChart(); });
    $('#fl_clear_btn').on('click', function(){
      $('#fl_x').val('day'); $('#fl_series').val('');
      for(var i=1;i<=3;i++) $('#fl_metric_'+i).val('');
      hydrateFromInputs();
      $('#fl_chart_preview').empty();
    });

    // Search fields list
    $('#fl_field_search').on('input', function(){
      var q = ($(this).val()||'').toLowerCase();
      $('.fl-pill').each(function(){
        var t = ($(this).text()||'').toLowerCase();
        $(this).toggle(t.indexOf(q)>=0);
      });
    });

    // Saved table search
    $('#fl_saved_search').on('input', function(){
      var q = ($(this).val()||'').toLowerCase();
      $('#fl_saved_table tbody tr').each(function(){
        var t = ($(this).data('title')||'');
        $(this).toggle(t.indexOf(q)>=0);
      });
    });

    // Row preview
    $(document).on('click', '.fl-preview-row', function(){
      var id = $(this).data('id');
      var title = $(this).closest('tr').find('td:first').text();
      previewSaved(id, title);
    });

    // Hydrate shortcode id on change
    $('#fl_chart_id').on('input', function(){ $('#fl_shortcode_id').text($(this).val()||'...'); });

    // Auto-toggle custom date inputs
    function toggleMode(){
    var mode = $('#fl_data_source_mode').val() || 'builder';
    var isSql = (mode === 'sql');

    // SQL panel
    $('#fl_sql_panel').toggle(isSql);

    // Builder UI: hide only drag/drop inputs when SQL,
    // but keep range, actions, and preview visible.
    $('.fl-fields').toggle(!isSql);
    $('.fl-zone-row').toggle(!isSql);
    $('.fl-y-grid').toggle(!isSql);

    // Layout tweak: collapse builder grid to 1 column in SQL mode.
    $('.fl-builder-shell').toggle(true).toggleClass('fl-sql-mode', isSql);
  }

  function toggleRange(){
      var mode = $('#fl_range_mode').val();
      var isCustom = (mode==='custom');
      $('#fl_range_from, #fl_range_to').prop('disabled', !isCustom);
      $('#fl_range_days').prop('disabled', isCustom);
    }
    $('#fl_data_source_mode').on('change', toggleMode);
    toggleMode();

    $('#fl_range_mode').on('change', toggleRange);
    toggleRange();

    // Render all admin chart boxes (dashboard widget etc.)
    $('[data-fl-chart]').each(function(){
      var $box = $(this);
      var id = $box.data('fl-chart');
      var cfg = $box.attr('data-fl-config') || '';
      ajaxChartData({id:id, config: cfg}, function(res){
        if(res && res.success){
          renderBox($box, res.data);
          setTimeout(function(){ try{ var inst = echarts.getInstanceByDom($box[0]); if(inst) inst.resize(); }catch(e){} }, 120);
        }else{
          var msg = (res && res.data && res.data.message) ? res.data.message : 'Chart data error';
          $box.html('<div class="fl-muted" style="padding:10px">⚠ '+ msg +'</div>');
        }
      });
    });

    // Kebab menu: copy shortcode
    function flToast(msg){
      var el = document.querySelector('.fl-toast');
      if(!el){
        el = document.createElement('div');
        el.className = 'fl-toast';
        document.body.appendChild(el);
      }
      el.textContent = msg;
      el.classList.add('show');
      window.clearTimeout(el._t);
      el._t = window.setTimeout(function(){ el.classList.remove('show'); }, 1600);
    }

    function copyText(text){
      if(navigator && navigator.clipboard && navigator.clipboard.writeText){
        return navigator.clipboard.writeText(text);
      }
      return new Promise(function(resolve){
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly','');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try{ document.execCommand('copy'); }catch(e){}
        document.body.removeChild(ta);
        resolve();
      });
    }

    function closeMenus(){
      document.querySelectorAll('.fl-menu').forEach(function(m){
        m.hidden = true;
        // Reset any runtime positioning so it doesn't "stick" between opens.
        m.classList.remove('fl-menu-fixed');
        m.style.position = '';
        m.style.left = '';
        m.style.top = '';
        m.style.right = '';
        m.style.bottom = '';
        m.style.visibility = '';
      });
    }

    document.addEventListener('click', function(e){
      var btn = e.target.closest ? e.target.closest('.fl-kebab') : null;
      var item = e.target.closest ? e.target.closest('.fl-copy-shortcode') : null;

      if(item){
        e.preventDefault();
        var sc = item.getAttribute('data-shortcode') || '';
        copyText(sc).then(function(){ flToast('Shortcode copied'); });
        closeMenus();
        return;
      }

      if(btn){
        e.preventDefault();
        var menu = btn.parentElement ? btn.parentElement.querySelector('.fl-menu') : null;
        if(!menu) return;
        var willShow = menu.hidden;
        closeMenus();

        if(willShow){
          // Show first (hidden) so we can measure width/height.
          menu.hidden = false;

          // Position the menu near the kebab button, not at the far-right of a flex container.
          // We use fixed positioning to be robust across different admin layouts.
          var r = btn.getBoundingClientRect();
          menu.classList.add('fl-menu-fixed');
          menu.style.position = 'fixed';
          menu.style.right = 'auto';
          menu.style.visibility = 'hidden';
          menu.style.left = '8px';
          menu.style.top = '8px';

          // Measure.
          var mw = menu.offsetWidth || 180;
          var mh = menu.offsetHeight || 120;
          var vw = window.innerWidth || document.documentElement.clientWidth || 1024;
          var vh = window.innerHeight || document.documentElement.clientHeight || 768;

          // Default: open under the button.
          var left = r.left;
          var top = r.bottom + 8;

          // Keep inside viewport.
          if(left + mw > vw - 8) left = vw - mw - 8;
          if(left < 8) left = 8;

          // If doesn't fit below, open above.
          if(top + mh > vh - 8) top = r.top - mh - 8;
          if(top < 8) top = 8;

          menu.style.left = left + 'px';
          menu.style.top = top + 'px';
          menu.style.visibility = '';
        }

        // Toggle state.
        menu.hidden = !willShow;
        return;
      }

      // click outside
      if(!e.target.closest || !e.target.closest('.fl-head-actions')) closeMenus();
    });
  });
})(jQuery);



// Custom file picker: show only a button, display selected names.
document.addEventListener('click', function(e){
  const btn = e.target.closest('.fl-file-trigger');
  if(!btn) return;
  const sel = btn.getAttribute('data-target');
  if(!sel) return;
  const input = document.querySelector(sel);
  if(input) input.click();
});

document.addEventListener('change', function(e){
  const input = e.target;
  if(!(input && input.matches('#fl_reminder_images'))) return;
  const wrap = input.closest('.fl-filepicker');
  if(!wrap) return;
  const label = wrap.querySelector('.fl-file-names');
  if(!label) return;

  const files = Array.from(input.files || []);
  if(files.length === 0){
    label.textContent = 'No files chosen';
    return;
  }
  label.textContent = files.map(f => f.name).join(', ');
});
