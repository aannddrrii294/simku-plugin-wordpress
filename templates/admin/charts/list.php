<?php
/**
 * Charts list page.
 *
 * Variables expected:
 * - $visible (array of chart configs)
 * - $uid (int current user id)
 */
if (!defined('ABSPATH')) { exit; }
?>
<div class="wrap fl-wrap">
  <?php echo $this->page_header_html('Charts', '[simku_charts]', '[simku page="charts"]'); ?>

  <?php if (is_admin()):
    $add_url = add_query_arg(['page'=>'fl-add-chart'], admin_url('admin.php'));
  ?>
    <div class="fl-actions fl-mt" style="justify-content:flex-start">
      <a class="button button-primary" href="<?php echo esc_url($add_url); ?>">Add Chart</a>
    </div>
  <?php else: ?>
    <div class="fl-muted fl-mt">Untuk membuat chart baru, buat halaman dengan shortcode <code>[simku_add_chart]</code>.</div>
  <?php endif; ?>

  <div class="fl-card fl-mt">
    <div class="fl-card-head">
      <h2>Saved Charts</h2>
      <span class="fl-muted">Public chart = template yang bisa dipakai semua user login (data tetap milik masing-masing user)</span>
    </div>

    <div class="fl-field"><input type="search" id="fl_saved_search" placeholder="Search charts…" /></div>

    <div class="fl-table-wrap">
      <table class="widefat striped simku-table" id="fl_saved_table">
        <thead>
          <tr>
            <?php foreach (['Title','ID','Type','Source','Visibility','Shortcode','Actions'] as $c): ?>
              <th><?php echo esc_html($c); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((array)$visible as $c):
            $id = (string)($c['id'] ?? '');
            $edit_url = add_query_arg(['page'=>'fl-add-chart','edit'=>$id], admin_url('admin.php'));
            $source = ($c['data_source_mode'] ?? 'builder') === 'sql' ? 'SQL' : 'Builder';
            $vis = !empty($c['is_public']) ? 'Public' : 'Private';
          ?>
            <tr data-title="<?php echo esc_attr(strtolower((string)($c['title'] ?? ''))); ?>">
              <td><b><?php echo esc_html($c['title'] ?? ''); ?></b></td>
              <td><code><?php echo esc_html($id); ?></code></td>
              <td><?php echo esc_html($c['chart_type'] ?? ''); ?></td>
              <td><?php echo esc_html($source); ?></td>
              <td><?php echo esc_html($vis); ?></td>
              <td><code>[fl_chart id="<?php echo esc_html($id); ?>"]</code></td>
              <td>
                <button class="button button-small fl-preview-row" type="button" data-id="<?php echo esc_attr($id); ?>">Preview</button>
                <?php if (is_admin() && $this->can_edit_chart($c, $uid)): ?>
                  <a class="button button-small" href="<?php echo esc_url($edit_url); ?>">Edit</a>
                  <form method="post" style="display:inline-block" onsubmit="return confirm('Delete this chart?')">
                    <?php wp_nonce_field('fl_delete_chart'); ?>
                    <input type="hidden" name="fl_delete_chart" value="1" />
                    <input type="hidden" name="delete_id" value="<?php echo esc_attr($id); ?>" />
                    <button class="button button-small button-link-delete">Delete</button>
                  </form>
                <?php else: ?>
                  <span class="fl-muted">Read only</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="fl_row_preview_wrap" class="fl-row-preview fl-mt" style="display:none;">
      <h3 id="fl_row_preview_title"></h3>
      <div id="fl_row_preview" class="fl-chart-box"></div>
    </div>
  </div>
</div>
