<?php
/**
 * Add/Edit chart page.
 *
 * Variables expected:
 * - $edit_chart (array)
 * - $uid (int)
 * - $back_url (string)
 * - $types (array)
 * - $modes (array)
 * - $can_dash (bool)
 * - $range (array)
 * - $filter (array)
 * - $metrics (array)
 */
if (!defined('ABSPATH')) { exit; }

$metrics = is_array($metrics) ? $metrics : [['metric'=>'amount_total','agg'=>'SUM']];
$m1 = $metrics[0]['metric'] ?? '';
$a1 = $metrics[0]['agg'] ?? 'SUM';
$m2 = $metrics[1]['metric'] ?? '';
$a2 = $metrics[1]['agg'] ?? 'SUM';
$m3 = $metrics[2]['metric'] ?? '';
$a3 = $metrics[2]['agg'] ?? 'SUM';
?>

<div class="wrap fl-wrap">
  <?php echo $this->page_header_html('Add Chart', '[simku_add_chart]', '[simku page="add-chart"]'); ?>

  <?php if (is_admin()): ?>
    <div class="fl-actions fl-mt" style="justify-content:flex-start">
      <a class="button" href="<?php echo esc_url($back_url); ?>">← Back to Charts</a>
    </div>
  <?php endif; ?>

  <div class="fl-card fl-builder fl-mt">
    <div class="fl-card-head"><h2>Chart Builder</h2><span class="fl-muted">Mode: Builder or SQL</span></div>

    <form method="post" id="fl-chart-form">
      <?php wp_nonce_field('fl_save_chart'); ?>
      <input type="hidden" name="fl_save_chart" value="1" />
      <input type="hidden" name="chart_id" id="fl_chart_id" value="<?php echo esc_attr($edit_chart['id'] ?? ''); ?>" />

      <div class="fl-grid fl-grid-3 fl-gap-md">
        <div class="fl-field">
          <label>Title</label>
          <input name="title" id="fl_title" value="<?php echo esc_attr($edit_chart['title'] ?? 'New chart'); ?>" />
        </div>

        <div class="fl-field">
          <label>Chart Type</label>
          <select name="chart_type" id="fl_chart_type">
            <?php foreach ((array)$types as $k=>$label): ?>
              <option value="<?php echo esc_attr($k); ?>" <?php echo selected(($edit_chart['chart_type'] ?? 'bar'), $k, false); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="fl-field">
          <label>Data Source</label>
          <select name="data_source_mode" id="fl_data_source_mode">
            <?php foreach ((array)$modes as $k=>$label): ?>
              <option value="<?php echo esc_attr($k); ?>" <?php echo selected(($edit_chart['data_source_mode'] ?? 'builder'), $k, false); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="fl-grid fl-grid-3 fl-gap-md fl-mt">
        <div class="fl-field">
          <label>Date Basis</label>
          <select name="date_basis" id="fl_date_basis">
            <?php foreach (['input'=>'Entry Date','receipt'=>'Purchase Date'] as $k=>$label): ?>
              <option value="<?php echo esc_attr($k); ?>" <?php echo selected(($edit_chart['date_basis'] ?? 'input'), $k, false); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="fl-field fl-check">
          <label><input type="checkbox" id="fl_is_public" name="is_public" value="1" <?php echo checked(!empty($edit_chart['is_public']), true, false); ?> /> Public (template for all logged-in users)</label>
        </div>

        <?php if (!empty($can_dash)): ?>
          <div class="fl-field fl-check">
            <label><input type="checkbox" name="show_on_dashboard" value="1" <?php echo checked(!empty($edit_chart['show_on_dashboard']), true, false); ?> /> Show on WP SIMKU dashboard</label>
          </div>
        <?php else: ?>
          <div class="fl-field"><span class="fl-muted">Dashboard: Finance Manager/Admin only</span></div>
        <?php endif; ?>
      </div>

      <!-- SQL panel -->
      <div id="fl_sql_panel" class="fl-card fl-mini fl-sql-panel fl-mt" style="display:none;">
        <h3>SQL Query</h3>
        <div class="fl-field fl-full">
          <label>Query (SELECT only)</label>
          <textarea class="fl-template" name="sql_query" id="fl_sql_query" rows="10" placeholder="SELECT DATE(tanggal_input) AS label, SUM(harga*quantity) AS value\nFROM {{active}}\nWHERE tanggal_input >= {{from_dt}} AND tanggal_input <= {{to_dt}}\nGROUP BY DATE(tanggal_input)\nORDER BY label"><?php echo esc_textarea($edit_chart['sql_query'] ?? ''); ?></textarea>
          <div class="fl-help">Wajib mengembalikan kolom: <code>label</code>, <code>value</code>, opsional <code>series</code> (multi-series). Placeholder tabel: <code>{{active}}</code>, <code>{{savings}}</code>, <code>{{reminders}}</code>. Placeholder range: <code>{{from}}</code>, <code>{{to}}</code>, <code>{{from_dt}}</code>, <code>{{to_dt}}</code>.</div>
        </div>
        <div class="fl-field fl-full">
          <label>Custom Option JSON (ECharts) <span class="fl-muted">(optional)</span></label>
          <textarea class="fl-template" name="custom_option_json" id="fl_custom_option_json" rows="6" placeholder="{\n  &quot;tooltip&quot;: {&quot;trigger&quot;: &quot;axis&quot;},\n  &quot;yAxis&quot;: {&quot;axisLabel&quot;: {&quot;formatter&quot;: &quot;Rp {value}&quot;}}\n}"><?php echo esc_textarea($edit_chart['custom_option_json'] ?? ''); ?></textarea>
          <div class="fl-help">Jika JSON valid, akan di-merge ke option ECharts default.</div>
        </div>
      </div>

      <!-- Builder shell (existing) -->
      <div class="fl-builder-shell fl-mt">
        <!-- Fields panel -->
        <div class="fl-fields">
          <div class="fl-subtitle">Fields</div>
          <input class="fl-field-search" type="search" placeholder="Search fields…" id="fl_field_search" />
          <div class="fl-field-groups">
            <div class="fl-field-group">
              <div class="fl-field-group-title">Dimensions</div>
              <?php foreach (['day'=>'day (tanggal_input)','week'=>'week','month'=>'month','year'=>'year','dow'=>'day of week','nama_toko'=>'nama_toko','kategori'=>'kategori','items'=>'items'] as $k=>$label): ?>
                <div class="fl-pill" draggable="true" data-field="<?php echo esc_attr($k); ?>" data-kind="dim"><?php echo esc_html($label); ?></div>
              <?php endforeach; ?>
            </div>
            <div class="fl-field-group">
              <div class="fl-field-group-title">Metrics</div>
              <?php foreach (['amount_total'=>'amount (harga*qty)','quantity_total'=>'quantity','count_rows'=>'count rows','income_total'=>'income total','expense_total'=>'expense total','avg_price'=>'avg harga'] as $k=>$label): ?>
                <div class="fl-pill fl-pill-metric" draggable="true" data-field="<?php echo esc_attr($k); ?>" data-kind="metric"><?php echo esc_html($label); ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Dropzones panel -->
        <div class="fl-zones">
          <div class="fl-zone-row">
            <div class="fl-zone">
              <div class="fl-zone-title">X Axis</div>
              <div class="fl-drop" data-target="x"><span class="fl-drop-hint">Drop a dimension</span></div>
              <input type="hidden" name="x" id="fl_x" value="<?php echo esc_attr($edit_chart['x'] ?? 'day'); ?>" />
            </div>
            <div class="fl-zone">
              <div class="fl-zone-title">Series (optional)</div>
              <div class="fl-drop" data-target="series"><span class="fl-drop-hint">Drop a dimension</span></div>
              <input type="hidden" name="series" id="fl_series" value="<?php echo esc_attr($edit_chart['series'] ?? ''); ?>" />
            </div>
          </div>

          <div class="fl-zone">
            <div class="fl-zone-title">Y Values (up to 3)</div>
            <div class="fl-y-grid">
              <?php for ($i=1;$i<=3;$i++):
                $mi = ${"m$i"};
                $ai = ${"a$i"};
              ?>
                <div class="fl-y-item">
                  <div class="fl-drop fl-drop-metric" data-target="metric_<?php echo (int)$i; ?>"><span class="fl-drop-hint">Drop a metric</span></div>
                  <input type="hidden" name="metric_<?php echo (int)$i; ?>" id="fl_metric_<?php echo (int)$i; ?>" value="<?php echo esc_attr($mi); ?>" />
                  <select name="agg_<?php echo (int)$i; ?>" id="fl_agg_<?php echo (int)$i; ?>">
                    <?php foreach (['SUM','AVG','COUNT','MAX','MIN'] as $agg): ?>
                      <option value="<?php echo esc_attr($agg); ?>" <?php echo selected($ai, $agg, false); ?>><?php echo esc_html($agg); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endfor; ?>
            </div>
          </div>

          <div class="fl-grid fl-grid-2 fl-gap-md fl-mt">
            <div class="fl-card fl-mini">
              <h3>Date Range</h3>
              <div class="fl-field">
                <label>Mode</label>
                <select name="range_mode" id="fl_range_mode">
                  <?php foreach (['last_days'=>'Last N days','custom'=>'Custom (from/to)'] as $k=>$label): ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php echo selected(($range['mode'] ?? 'last_days'), $k, false); ?>><?php echo esc_html($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="fl-grid fl-grid-2">
                <div class="fl-field"><label>Days</label><input type="number" min="1" name="range_days" id="fl_range_days" value="<?php echo esc_attr($range['days'] ?? 30); ?>" /></div>
                <div class="fl-field"><label>Top N</label><input type="number" min="0" name="top_n" value="<?php echo esc_attr($filter['top_n'] ?? 0); ?>" /></div>
              </div>
              <div class="fl-grid fl-grid-2">
                <div class="fl-field"><label>From</label><input type="date" name="range_from" id="fl_range_from" value="<?php echo esc_attr($range['from'] ?? ''); ?>" /></div>
                <div class="fl-field"><label>To</label><input type="date" name="range_to" id="fl_range_to" value="<?php echo esc_attr($range['to'] ?? ''); ?>" /></div>
              </div>
            </div>

            <div class="fl-card fl-mini">
              <h3>Filter kategori</h3>
              <div class="fl-check-group">
                <?php foreach (['expense','income','saving','invest'] as $cat):
                  $checked = in_array($cat, (array)($filter['kategori'] ?? []), true);
                ?>
                  <label><input type="checkbox" name="filter_kategori[]" value="<?php echo esc_attr($cat); ?>" <?php echo checked($checked, true, false); ?> /> <?php echo esc_html($cat); ?></label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="fl-actions fl-mt">
            <button class="button button-primary">Save Chart</button>
            <button type="button" class="button" id="fl_preview_btn">Preview</button>
            <button type="button" class="button" id="fl_clear_btn">Clear</button>
          </div>

          <div class="fl-card fl-preview fl-mt">
            <h3>Preview</h3>
            <div id="fl_chart_preview" class="fl-chart-box" data-chart-id="<?php echo esc_attr($edit_chart['id'] ?? ''); ?>"></div>
            <div class="fl-muted">Shortcode: <code>[fl_chart id=&quot;<span id="fl_shortcode_id"><?php echo esc_html($edit_chart['id'] ?? '...'); ?></span>&quot;]</code></div>
          </div>

        </div> <!-- zones -->
      </div> <!-- builder shell -->

    </form>
  </div>
</div>
