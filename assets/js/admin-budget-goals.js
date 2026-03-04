(function($){
  function clamp(n, min, max){
    n = Number(n);
    if (isNaN(n)) return min;
    return Math.max(min, Math.min(max, n));
  }

  function renderOne(el){
    if (typeof echarts === 'undefined') return;

    var $el = $(el);
    var pctRaw = $el.data('pct');
    var pct = Number(pctRaw);
    if (isNaN(pct)) return;

    // Visual bar is clamped to 0..100, but label keeps original %.
    var used = clamp(pct, 0, 100);
    var rem = 100 - used;

    var chart = echarts.init(el, null, {renderer: 'canvas'});
    var opt = {
      animation: false,
      grid: {left: 0, right: 0, top: 0, bottom: 0, containLabel: false},
      xAxis: {type: 'value', show: false, min: 0, max: 100},
      yAxis: {type: 'category', show: false, data: ['']},
      series: [
        {
          type: 'bar',
          stack: 'pct',
          data: [used],
          barWidth: 12,
          itemStyle: {
            borderRadius: [6, 0, 0, 6]
          }
        },
        {
          type: 'bar',
          stack: 'pct',
          data: [rem],
          barWidth: 12,
          itemStyle: {
            opacity: 0.18,
            borderRadius: [0, 6, 6, 0]
          }
        }
      ]
    };

    chart.setOption(opt);

    // Store instance for resize.
    $el.data('simkuChart', chart);
  }

  function init(){
    $('.simku-pct-chart').each(function(){
      renderOne(this);
    });
  }

  $(document).ready(init);

  $(window).on('resize', function(){
    $('.simku-pct-chart').each(function(){
      var c = $(this).data('simkuChart');
      if (c && typeof c.resize === 'function') c.resize();
    });
  });
})(jQuery);
