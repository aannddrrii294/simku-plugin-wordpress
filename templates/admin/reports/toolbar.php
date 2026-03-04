<?php
/**
 * Reports toolbar + filter.
 *
 * Variables expected:
 * - $tab (daily|weekly|monthly)
 * - $date, $from, $to, $month (depending on $tab)
 * - $user_login
 * - $tx_type
 * - $pdf_url, $csv_url (fallback for <noscript>)
 * - $pdf_tpl, $pdf_orient, $pdf_cols, $pdf_header, $pdf_footer
 */
if (!defined('ABSPATH')) { exit; }

$pdf_tpl = isset($pdf_tpl) ? (string)$pdf_tpl : 'standard';
if (!in_array($pdf_tpl, ['standard','compact','summary','custom'], true)) $pdf_tpl = 'standard';

$pdf_orient = isset($pdf_orient) ? (string)$pdf_orient : 'portrait';
if (!in_array($pdf_orient, ['portrait','landscape'], true)) $pdf_orient = 'portrait';

$pdf_header = isset($pdf_header) ? (string)$pdf_header : '';
$pdf_footer = isset($pdf_footer) ? (string)$pdf_footer : '';


$pdf_mode = isset($pdf_mode) ? (string)$pdf_mode : 'full_detail';
if (!in_array($pdf_mode, ['summary_breakdown','full_detail'], true)) $pdf_mode = 'full_detail';

$pdf_breakdowns = isset($pdf_breakdowns) && is_array($pdf_breakdowns) ? $pdf_breakdowns : [];
$pdf_breakdowns = array_values(array_unique(array_filter(array_map('sanitize_text_field', $pdf_breakdowns))));
$bd_labels = [
  'category' => 'Category',
  'tags' => 'Tags',
  'counterparty' => 'Counterparty',
  'date' => 'Date',
];
if (empty($pdf_breakdowns)) $pdf_breakdowns = ['category'];

$pdf_logo_id = isset($pdf_logo_id) ? (int)$pdf_logo_id : 0;
if ($pdf_logo_id < 0) $pdf_logo_id = 0;
$pdf_logo_url = '';
if ($pdf_logo_id > 0 && function_exists('wp_get_attachment_image_url')) {
  $pdf_logo_url = (string)wp_get_attachment_image_url($pdf_logo_id, 'thumbnail');
}


$pdf_cols = isset($pdf_cols) && is_array($pdf_cols) ? $pdf_cols : [];
if (empty($pdf_cols)) {
  $pdf_cols = ['date','party','category','item','qty','price'];
}
$col_labels = [
  'date' => 'Date',
  'party' => 'Payee/Source',
  'category' => 'Category',
  'item' => 'Item',
  'qty' => 'Qty',
  'price' => 'Price',
  'tags' => 'Tags',
  'description' => 'Description',
  'txid' => 'Tx ID',
];
?>
<div class="simku-report-toolbar">
  <form method="get" class="fl-inline simku-report-filter">
    <input type="hidden" name="page" value="fl-reports" />
    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />

    <?php if ($tab === 'daily'): ?>
      <div class="simku-filter-field simku-filter-date">
        <label for="simku_report_date">Date</label>
        <input id="simku_report_date" type="date" name="date" value="<?php echo esc_attr($date); ?>" />
      </div>
    <?php elseif ($tab === 'weekly'): ?>
      <div class="simku-filter-field simku-filter-from">
        <label for="simku_report_from">From</label>
        <input id="simku_report_from" type="date" name="from" value="<?php echo esc_attr($from); ?>" />
      </div>
      <div class="simku-filter-field simku-filter-to">
        <label for="simku_report_to">To</label>
        <input id="simku_report_to" type="date" name="to" value="<?php echo esc_attr($to); ?>" />
      </div>
    <?php else: ?>
      <div class="simku-filter-field simku-filter-month">
        <label for="simku_report_month">Month</label>
        <input id="simku_report_month" type="month" name="month" value="<?php echo esc_attr($month); ?>" />
      </div>
    <?php endif; ?>

    <?php $this->reports_render_user_dropdown($user_login); ?>
    <?php $this->reports_render_type_dropdown($tx_type); ?>

    <details class="simku-pdf-layout">
      <summary>PDF layout</summary>

      <div class="simku-pdf-options-grid">
        <div class="simku-filter-field">
          <label for="simku_pdf_tpl">Layout</label>
          <select id="simku_pdf_tpl" name="pdf_tpl">
            <option value="standard" <?php selected($pdf_tpl, 'standard'); ?>>Standard</option>
            <option value="compact" <?php selected($pdf_tpl, 'compact'); ?>>Compact</option>
            <option value="summary" <?php selected($pdf_tpl, 'summary'); ?>>Summary only</option>
            <option value="custom" <?php selected($pdf_tpl, 'custom'); ?>>Custom columns</option>
          </select>
          <div class="description">Standard = Summary + Detail table. Compact = smaller margins/font to fit more rows per page. Summary only = totals without the detail table. Custom columns = choose which columns to print.</div>
        </div>

        <div class="simku-filter-field">
          <label for="simku_pdf_mode">Content</label>
          <select id="simku_pdf_mode" name="pdf_mode">
            <option value="full_detail" <?php selected($pdf_mode, 'full_detail'); ?>>Full detail</option>
            <option value="summary_breakdown" <?php selected($pdf_mode, 'summary_breakdown'); ?>>Summary + breakdown</option>
          </select>
          <div class="description">Full detail = summary + breakdown + detail transactions table. Summary + breakdown = summary and breakdown sections only (no detail table).</div>
        </div>

        <div class="simku-filter-field">
          <label for="simku_pdf_orient">Orientation</label>
          <select id="simku_pdf_orient" name="pdf_orient">
            <option value="portrait" <?php selected($pdf_orient, 'portrait'); ?>>Portrait</option>
            <option value="landscape" <?php selected($pdf_orient, 'landscape'); ?>>Landscape</option>
          </select>
        </div>

        <div class="simku-filter-field">
          <label for="simku_pdf_header">Header (optional)</label>
          <input id="simku_pdf_header" type="text" name="pdf_header" value="<?php echo esc_attr($pdf_header); ?>" placeholder="e.g. HONET Finance Report" />
        </div>

        <div class="simku-filter-field">
          <label for="simku_pdf_footer">Footer note (optional)</label>
          <input id="simku_pdf_footer" type="text" name="pdf_footer" value="<?php echo esc_attr($pdf_footer); ?>" placeholder="e.g. Generated by WP SIMKU" />
        </div>

        <div class="simku-filter-field simku-pdf-logo">
          <label>Logo (optional)</label>
          <input type="hidden" id="simku_pdf_logo_id" name="pdf_logo_id" value="<?php echo esc_attr($pdf_logo_id); ?>" />
          <div class="simku-logo-picker">
            <button class="button" type="button" id="simku_pdf_logo_choose">Choose logo</button>
            <button class="button" type="button" id="simku_pdf_logo_clear" <?php echo ($pdf_logo_id>0?'':'style="display:none;"'); ?>>Clear</button>
            <span class="simku-logo-preview">
              <?php if ($pdf_logo_url !== ''): ?>
                <img id="simku_pdf_logo_preview" src="<?php echo esc_url($pdf_logo_url); ?>" alt="logo preview" />
              <?php else: ?>
                <img id="simku_pdf_logo_preview" src="" alt="logo preview" style="display:none;" />
              <?php endif; ?>
            </span>
          </div>
          <div class="description">If set, the logo is printed on the top-left of each PDF page.</div>
        </div>
      </div>

      <div class="simku-pdf-breakdowns">
        <div class="simku-filter-field">
          <label>Breakdown</label>
          <div class="simku-checkbox-grid simku-breakdown-grid">
            <?php foreach ($bd_labels as $k => $lbl): ?>
              <label class="simku-checkbox">
                <input type="checkbox" name="pdf_breakdowns[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $pdf_breakdowns, true)); ?> />
                <span><?php echo esc_html($lbl); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="description">Choose one or more breakdown sections to include in the PDF (Category, Tags, Counterparty, Date).</div>
        </div>
      </div>

      <div class="simku-pdf-custom-fields">
        <div class="simku-filter-field simku-pdf-cols">
          <label>Columns</label>
          <div class="simku-checkbox-grid">
            <?php foreach ($col_labels as $k => $lbl): ?>
              <label class="simku-checkbox">
                <input type="checkbox" name="pdf_cols[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $pdf_cols, true)); ?> />
                <span><?php echo esc_html($lbl); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="description">Columns are used only for Custom columns layout. For Standard/Compact the default columns are used.</div>
        </div>
      </div>
    </details>

    <div class="simku-filter-actions">
      <button class="button button-primary" type="submit">Run</button>
      <button class="button" type="button" data-simku-export="pdf">Export PDF</button>
      <button class="button" type="button" data-simku-export="csv">Export CSV</button>

      <noscript>
        <a class="button" href="<?php echo esc_url($pdf_url); ?>">Export PDF</a>
        <a class="button" href="<?php echo esc_url($csv_url); ?>">Export CSV</a>
      </noscript>
    </div>
  </form>

  <!-- Hidden export forms -->
  <form id="simku-export-pdf" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
    <input type="hidden" name="action" value="simku_export_report_pdf" />
    <?php wp_nonce_field('simku_export_report_pdf'); ?>
  </form>

  <form id="simku-export-csv" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
    <input type="hidden" name="action" value="simku_export_report_csv" />
    <?php wp_nonce_field('simku_export_report_csv'); ?>
  </form>
</div>
