(function($){
  function closeAll(){
    $('.simku-help-pop.is-open').each(function(){
      $(this).removeClass('is-open').attr('aria-hidden','true');
    });
    $('.simku-help-icon[aria-expanded="true"]').attr('aria-expanded','false');
  }

  function makeHelp(html){
    var $wrap = $('<span class="simku-help"></span>');
    var $btn = $('<button type="button" class="simku-help-icon" aria-expanded="false" aria-label="Info"><span class="dashicons dashicons-info-outline"></span></button>');
    var $pop = $('<div class="simku-help-pop" aria-hidden="true"></div>');
    $pop.html(html);
    $wrap.append($btn).append($pop);
    return $wrap;
  }

  // Click-to-open tooltips
  $(document).on('click', '.simku-help-icon', function(e){
    e.preventDefault();
    e.stopPropagation();

    var $btn = $(this);
    var $wrap = $btn.closest('.simku-help');
    var $pop = $wrap.find('.simku-help-pop').first();

    // close other popovers
    $('.simku-help-pop').not($pop).removeClass('is-open').attr('aria-hidden','true');
    $('.simku-help-icon').not($btn).attr('aria-expanded','false');

    var open = $pop.hasClass('is-open');
    if (open){
      $pop.removeClass('is-open').attr('aria-hidden','true');
      $btn.attr('aria-expanded','false');
    } else {
      $pop.addClass('is-open').attr('aria-hidden','false');
      $btn.attr('aria-expanded','true');
    }
  });

  $(document).on('click', function(){
    closeAll();
  });

  $(document).on('keydown', function(e){
    if (e.key === 'Escape') closeAll();
  });

  // Auto-collapse long descriptions into an info icon (SIMKU pages only)
  $(function(){
    if (!$('.wrap.fl-wrap').length) return;

    $('.wrap.fl-wrap .description').each(function(){
      var $d = $(this);
      if ($d.closest('.simku-help-pop').length) return;
      if ($d.data('simkuKeep')) return;
      if ($d.find('a, button, input, select, textarea').length) return;
      var txt = ($d.text() || '').trim();
      if (txt.length < 90) return;

      // Avoid double conversion
      if ($d.prev().find('.simku-help').length) return;

      var html = $d.html();
      var $label = null;

      // Prefer attaching to a sibling label (cleaner)
      if ($d.prev().length && $d.prev().is('label')) {
        $label = $d.prev();
      } else {
        // Or inside the nearest label in the same field container
        var $field = $d.closest('.simku-filter-field, .fl-field');
        if ($field.length) {
          var $l = $field.find('label').first();
          if ($l.length) $label = $l;
        }
      }

      var $help = makeHelp(html);

      if ($label && $label.length) {
        $label.addClass('simku-label-with-help');
        $label.append($help);
      } else {
        // fallback: place icon before the description
        $d.before($help);
      }

      $d.hide();
    });
  });

})(jQuery);
