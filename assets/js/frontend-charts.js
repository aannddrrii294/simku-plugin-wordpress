(function($){
  function ajaxChartData(params, cb){
    // If config is provided we must use POST (can be long).
    var hasConfig = !!params.config;
    $.ajax({
      url: SIMAK_AJAX.ajax_url,
      method: hasConfig ? 'POST' : 'GET',
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

  function renderError($box, msg){
    $box.html('<div class="fl-chart-error"><span class="dashicons dashicons-warning"></span> '+(msg||'Request failed')+'</div>');
  }


  function buildOption(payload){

    var type = payload.type || 'line';

    var x = payload.x || [];

    var series = payload.series || [];

	    var msg = (payload && payload.message) ? String(payload.message) : '';
	    var isMobile = !!(window.matchMedia && window.matchMedia('(max-width: 782px)').matches);


		    var opt = {
		      tooltip: { trigger: 'axis' },
		      legend: { top: 0 },
		      grid: { left: (isMobile ? 2 : 8), right: (isMobile ? 10 : 18), top: 40, bottom: 40, containLabel: true },
	      xAxis: { type: 'category', data: x },
	      yAxis: { type: 'value', axisLabel: { formatter: function(v){ return fmtNumberID(v); }, margin: (isMobile ? 2 : 6) } },
	      series: []
	    };


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

    return opt;

  }


  function renderBox($el, payload){
    var chart = echarts.getInstanceByDom($el[0]);
    if(!chart) chart = echarts.init($el[0]);
    chart.setOption(buildOption(payload), true);
    window.addEventListener('resize', function(){ chart.resize(); });
  }

  $(function(){
    if(typeof SIMAK_AJAX === 'undefined') return;
    $('[data-fl-chart]').each(function(){
      var $box = $(this);
      var id = $box.data('fl-chart');
      var cfg = $box.data('fl-config');
      if(!id) return;
      if(cfg){
        // dashboard can pass a json config via data attribute
        ajaxChartData({id:id, config: JSON.stringify(cfg)}, function(res){
          if(res && res.success) renderBox($box, res.data);
          else renderError($box, (res&&res.data&&res.data.message)||'Request failed');
        });
        return;
      }
      ajaxChartData({id:id}, function(res){
        if(res && res.success) renderBox($box, res.data);
          else renderError($box, (res&&res.data&&res.data.message)||'Request failed');
      });
    });
  });
})(jQuery);
