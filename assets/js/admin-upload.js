(function($){
  'use strict';

  const OPTS = (window.SIMAK_UPLOAD || {});
  const MAX_BYTES = Number(OPTS.max_bytes || (1400 * 1024));
  const MAX_DIM   = Number(OPTS.max_dim || 1600);
  const QUALITY   = Number(OPTS.quality || 0.78);

  function bytesToSize(bytes){
    const mb = bytes / (1024*1024);
    return mb.toFixed(mb < 10 ? 2 : 1) + ' MB';
  }

  function isImageFile(file){
    return file && typeof file.type === 'string' && file.type.indexOf('image/') === 0;
  }

  function loadImageFromFile(file){
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = (e) => { URL.revokeObjectURL(url); reject(e); };
      img.src = url;
    });
  }

  function canvasToBlob(canvas, type, quality){
    return new Promise((resolve) => {
      // Safari needs callback form
      canvas.toBlob((blob) => resolve(blob), type, quality);
    });
  }

  function calcResize(w, h, maxDim){
    if (!w || !h) return {w, h};
    const longEdge = Math.max(w, h);
    if (longEdge <= maxDim) return { w, h };
    const scale = maxDim / longEdge;
    return { w: Math.round(w * scale), h: Math.round(h * scale) };
  }

  async function compressImageFile(file, opts){
    const maxBytes = opts.maxBytes;
    let maxDim = opts.maxDim;
    let quality = opts.quality;

    const img = await loadImageFromFile(file);

    const drawToCanvas = (dim) => {
      const sz = calcResize(img.naturalWidth || img.width, img.naturalHeight || img.height, dim);
      const canvas = document.createElement('canvas');
      canvas.width = sz.w;
      canvas.height = sz.h;
      const ctx = canvas.getContext('2d');
      // Fill white background (helps when converting PNG with transparency to JPEG)
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      return canvas;
    };

    let canvas = drawToCanvas(maxDim);

    // Iteratively reduce quality / dimension until under maxBytes
    let blob = await canvasToBlob(canvas, 'image/jpeg', quality);
    for (let i = 0; i < 7 && blob && blob.size > maxBytes; i++) {
      if (quality > 0.60) {
        quality = Math.max(0.55, quality - 0.08);
      } else {
        maxDim = Math.max(900, Math.round(maxDim * 0.85));
        canvas = drawToCanvas(maxDim);
      }
      blob = await canvasToBlob(canvas, 'image/jpeg', quality);
    }

    // Fallback: if toBlob failed
    if (!blob) return file;

    const baseName = (file.name || 'image').replace(/\.(png|jpe?g|webp|gif|bmp|tiff?)$/i, '');
    const newName = baseName + '.jpg';
    return new File([blob], newName, { type: 'image/jpeg', lastModified: Date.now() });
  }

  function ensureHint($input){
    const id = $input.attr('id') || '';
    const $hint = $('<div class="simak-upload-hint" style="margin-top:6px;color:#646970;font-size:12px;"></div>');
    $hint.text('Tip: gambar akan dikompres otomatis agar lebih kecil (target < ' + bytesToSize(MAX_BYTES) + ' per gambar).');

    // Only insert once
    const $existing = $input.closest('.fl-filepicker').find('.simak-upload-hint');
    if ($existing.length) return $existing;

    const $wrap = $input.closest('.fl-filepicker');
    if ($wrap.length) {
      $wrap.append($hint);
      return $hint;
    }

    $input.after($hint);
    return $hint;
  }

  async function handleFileInput(input){
    const files = Array.from(input.files || []);
    if (!files.length) return;

    // Only act on image inputs
    const accept = String(input.getAttribute('accept') || '');
    if (accept && accept.indexOf('image') === -1) return;

    const $input = $(input);
    const $hint = ensureHint($input);

    const $form = $input.closest('form');
    const $submit = $form.find('button[type=submit], input[type=submit]');

    const needsWork = files.some(f => isImageFile(f) && f.size > MAX_BYTES);
    if (!needsWork) {
      $hint.text('Ukuran gambar OK.');
      return;
    }

    $submit.prop('disabled', true);
    $hint.text('Mengompres gambar...');

    try {
      const out = [];
      for (const f of files) {
        if (!isImageFile(f) || f.size <= MAX_BYTES) {
          out.push(f);
          continue;
        }
        const compressed = await compressImageFile(f, { maxBytes: MAX_BYTES, maxDim: MAX_DIM, quality: QUALITY });
        out.push(compressed);
      }

      // Replace file list
      if (typeof DataTransfer !== 'undefined') {
        const dt = new DataTransfer();
        out.forEach(f => dt.items.add(f));
        input.files = dt.files;
      } else {
        // Older browsers: can't replace; keep original
        console.warn('SIMKU: DataTransfer not supported; cannot replace files with compressed versions.');
      }

      const sizeInfo = out
        .filter(isImageFile)
        .map(f => (f.name + ' (' + bytesToSize(f.size) + ')'))
        .join(', ');
      $hint.text('Selesai kompres: ' + sizeInfo);
    } catch (e) {
      console.error('SIMKU: compression failed', e);
      $hint.text('Gagal kompres gambar (tetap mencoba upload).');
    } finally {
      $submit.prop('disabled', false);
    }
  }

  function init(){
    const selector = 'input[type=file][accept*="image"], input[type=file][name="gambar_file"], input[type=file][name="gambar_files[]"]';
    $(document).on('change', selector, function(){
      // Fire-and-forget; we handle UI state
      handleFileInput(this);
    });

    // Support custom "Pilih File" buttons that trigger hidden file inputs.
    // Button markup example:
    //   <button type="button" data-fl-file-trigger="fl_tx_images">Pilih File</button>
    //   <input id="fl_tx_images" class="fl-hidden-file" type="file" ...>
    $(document).on('click', '[data-fl-file-trigger]', function(e){
      e.preventDefault();
      const targetId = $(this).attr('data-fl-file-trigger');
      if (!targetId) return;
      const $input = $('#' + targetId);
      if ($input.length) {
        $input.trigger('click');
      }
    });

    // Update the small filename label next to our custom file button.
    $(document).on('change', 'input[type=file].fl-hidden-file', function(){
      const files = this.files || [];
      let label = 'Tidak ada file yang dipilih';
      if (files.length === 1) label = files[0].name;
      else if (files.length > 1) label = files.length + ' file dipilih';
      $(this).closest('.fl-filepicker').find('.fl-file-label').text(label);
    });

    // Add hint on load for existing inputs
    $(selector).each(function(){ ensureHint($(this)); });
  }

  $(init);

})(jQuery);
