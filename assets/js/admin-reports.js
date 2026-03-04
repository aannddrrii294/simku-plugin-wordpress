(function($){
  function setLayoutVisibility(){
    var tpl = $('#simku_pdf_tpl').val() || 'standard';
    $('.simku-pdf-custom-fields').toggle(tpl === 'custom');
  }

  function exportFromFilter(kind){
    var $filter = $('form.simku-report-filter').first();
    var $form = $('#simku-export-' + kind);
    if (!$filter.length || !$form.length) return;

    // Remove dynamic inputs from previous exports
    $form.find('input[data-simku-dyn="1"]').remove();

    // Copy all filter inputs/selects/textareas
    $filter.find('input, select, textarea').each(function(){
      var el = this;
      if (!el.name) return;

      var tag = (el.tagName || '').toLowerCase();
      var type = (el.type || '').toLowerCase();

      // Skip buttons
      if (type === 'submit' || type === 'button') return;

      // Don't send WP admin routing field
      if (el.name === 'page') return;

      // Checkbox: include only if checked
      if (type === 'checkbox'){
        if (!el.checked) return;
        add(el.name, el.value || '1');
        return;
      }

      // Radio: only checked
      if (type === 'radio'){
        if (!el.checked) return;
        add(el.name, el.value);
        return;
      }

      // Multi-select
      if (tag === 'select' && el.multiple){
        $(el).find('option:selected').each(function(){
          add(el.name, this.value);
        });
        return;
      }

      add(el.name, $(el).val());
    });

    // Submit
    var formEl = $form.get(0);
    if (formEl && formEl.requestSubmit) formEl.requestSubmit();
    else if (formEl) formEl.submit();

    function add(name, value){
      if (value === undefined || value === null) value = '';
      // Skip empty values to keep request small
      if (String(value).trim() === '') return;
      var $i = $('<input type="hidden" />');
      $i.attr('name', name).val(value).attr('data-simku-dyn','1');
      $form.append($i);
    }
  }

  // Expose for older templates if needed
  window.simkuSubmitExport = function(kind){
    exportFromFilter(kind);
  };

  $(document).on('click', '[data-simku-export]', function(e){
    e.preventDefault();
    var kind = $(this).data('simkuExport');
    if (!kind) return;
    exportFromFilter(kind);
  });


  function initLogoPicker(){
    if (!window.wp || !wp.media) return;

    var frame = null;
    function openPicker(){
      if (frame) { frame.open(); return; }
      frame = wp.media({
        title: 'Choose PDF logo',
        button: { text: 'Use this logo' },
        multiple: false
      });
      frame.on('select', function(){
        var att = frame.state().get('selection').first();
        if (!att) return;
        var json = att.toJSON();
        var id = json.id || 0;
        var url = (json.sizes && json.sizes.thumbnail && json.sizes.thumbnail.url) ? json.sizes.thumbnail.url
                  : (json.sizes && json.sizes.medium && json.sizes.medium.url) ? json.sizes.medium.url
                  : (json.url || '');
        $('#simku_pdf_logo_id').val(id);
        if (url){
          $('#simku_pdf_logo_preview').attr('src', url).show();
        }
        $('#simku_pdf_logo_clear').show();
      });
      frame.open();
    }

    $(document).on('click', '#simku_pdf_logo_choose', function(e){
      e.preventDefault();
      openPicker();
    });

    $(document).on('click', '#simku_pdf_logo_clear', function(e){
      e.preventDefault();
      $('#simku_pdf_logo_id').val('');
      $('#simku_pdf_logo_preview').attr('src','').hide();
      $('#simku_pdf_logo_clear').hide();
    });
  }

  $(function(){
    setLayoutVisibility();
    initLogoPicker();
    $(document).on('change', '#simku_pdf_tpl', setLayoutVisibility);
  });
})(jQuery);
