<?php
/**
 * Admin Dashboard template.
 *
 * Variables expected:
 * - $charts, $dash_ids, $from, $to, $date_basis, $group, $tx_type
 * - $tot, $outcome_today, $savings_total
 */
if (!defined('ABSPATH')) { exit; }

$basis_label = __('Entry date', self::TEXT_DOMAIN);
switch ($date_basis) {
    case 'purchase':
        $basis_label = __('Purchase date (fallback: entry date)', self::TEXT_DOMAIN);
        break;
    case 'receive':
        $basis_label = __('Receive date (fallback: entry date)', self::TEXT_DOMAIN);
        break;
    case 'receipt':
        $basis_label = __('Receipt date (fallback: entry date)', self::TEXT_DOMAIN);
        break;
}
?>

<div class="wrap fl-wrap">
  <?php echo $this->page_header_html(__(self::PLUGIN_SHORT_NAME, self::TEXT_DOMAIN), '[simku_dashboard]', '[simku page="dashboard"]'); ?>

  <div class="fl-mt">
    <div class="fl-grid fl-grid-2">

      <div class="fl-card">
        <div class="fl-card-head">
          <h2 style="margin:0"><?php echo esc_html__('Quick Links', self::TEXT_DOMAIN); ?></h2>
        </div>
        <div class="fl-card-body">
          <div class="fl-btnrow">
            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=fl-transactions')); ?>"><?php echo esc_html__('Transactions', self::TEXT_DOMAIN); ?></a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fl-scan-struk')); ?>"><?php echo esc_html__('Scan Receipt', self::TEXT_DOMAIN); ?></a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fl-savings')); ?>"><?php echo esc_html__('Savings', self::TEXT_DOMAIN); ?></a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fl-budget-goals')); ?>"><?php echo esc_html__('Budget Target', self::TEXT_DOMAIN); ?></a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fl-reminders')); ?>"><?php echo esc_html__('Reminders', self::TEXT_DOMAIN); ?></a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fl-reports')); ?>"><?php echo esc_html__('Reports', self::TEXT_DOMAIN); ?></a>
          </div>
        </div>
      </div>

      <div class="fl-card">
        <div class="fl-card-head">
          <h2 style="margin:0"><?php echo esc_html__('Today', self::TEXT_DOMAIN); ?></h2>
          <div class="fl-muted"><?php echo esc_html(sprintf(__('Basis: %s', self::TEXT_DOMAIN), $basis_label)); ?></div>
        </div>
        <div class="fl-card-body">
          <div class="fl-kpis">
            <div class="fl-kpi">
              <div class="fl-kpi-label"><?php echo esc_html__('Income', self::TEXT_DOMAIN); ?></div>
              <div class="fl-kpi-value"><?php echo esc_html('Rp ' . number_format_i18n((float)($tot['income'] ?? 0))); ?></div>
            </div>
            <div class="fl-kpi">
              <div class="fl-kpi-label"><?php echo esc_html__('Expense', self::TEXT_DOMAIN); ?></div>
              <div class="fl-kpi-value"><?php echo esc_html('Rp ' . number_format_i18n((float)$outcome_today)); ?></div>
            </div>
            <div class="fl-kpi">
              <div class="fl-kpi-label"><?php echo esc_html__('Savings Total', self::TEXT_DOMAIN); ?></div>
              <div class="fl-kpi-value"><?php echo esc_html('Rp ' . number_format_i18n((float)$savings_total)); ?></div>
            </div>
          </div>
        </div>
      </div>

    </div>

    <div class="fl-card fl-mt simku-dashboard-filter-card">
      <div class="fl-card-head">
        <h2 style="margin:0"><?php echo esc_html__('Dashboard Charts', self::TEXT_DOMAIN); ?></h2>
        <span class="fl-muted"><?php echo esc_html__('Filter range', self::TEXT_DOMAIN); ?></span>
      </div>
      <div class="fl-card-body">
        <form method="get" class="simku-report-filter simku-dashboard-filter">
          <input type="hidden" name="page" value="fl-dashboard" />

          <div class="simku-filter-field simku-filter-from">
            <label for="simku_report_from"><?php echo esc_html__('From', self::TEXT_DOMAIN); ?></label>
            <input id="simku_report_from" type="date" name="from" value="<?php echo esc_attr($from); ?>" />
          </div>

          <div class="simku-filter-field simku-filter-to">
            <label for="simku_report_to"><?php echo esc_html__('To', self::TEXT_DOMAIN); ?></label>
            <input id="simku_report_to" type="date" name="to" value="<?php echo esc_attr($to); ?>" />
          </div>

          <div class="simku-filter-field simku-filter-group">
            <label for="simku_dash_group"><?php echo esc_html__('Group', self::TEXT_DOMAIN); ?></label>
            <select id="simku_dash_group" name="group">
              <option value="daily" <?php echo selected($group, 'daily', false); ?>><?php echo esc_html__('Daily', self::TEXT_DOMAIN); ?></option>
              <option value="weekly" <?php echo selected($group, 'weekly', false); ?>><?php echo esc_html__('Weekly', self::TEXT_DOMAIN); ?></option>
              <option value="monthly" <?php echo selected($group, 'monthly', false); ?>><?php echo esc_html__('Monthly', self::TEXT_DOMAIN); ?></option>
            </select>
          </div>

          <div class="simku-filter-field simku-filter-basis">
            <label for="simku_dash_basis"><?php echo esc_html__('Basis', self::TEXT_DOMAIN); ?></label>
            <select id="simku_dash_basis" name="date_basis" class="fl-select">
              <option value="input" <?php echo selected($date_basis, 'input', false); ?>><?php echo esc_html__('Entry date', self::TEXT_DOMAIN); ?></option>
              <option value="purchase" <?php echo selected($date_basis, 'purchase', false); ?>><?php echo esc_html__('Purchase date', self::TEXT_DOMAIN); ?></option>
              <option value="receive" <?php echo selected($date_basis, 'receive', false); ?>><?php echo esc_html__('Receive date', self::TEXT_DOMAIN); ?></option>
              <option value="receipt" <?php echo selected($date_basis, 'receipt', false); ?>><?php echo esc_html__('Receipt date', self::TEXT_DOMAIN); ?></option>
            </select>
          </div>

          <div class="simku-filter-field simku-filter-category">
            <label for="simku_dash_type"><?php echo esc_html__('Category', self::TEXT_DOMAIN); ?></label>
            <select id="simku_dash_type" name="tx_type" class="fl-select">
              <option value="all" <?php echo selected($tx_type, 'all', false); ?>><?php echo esc_html__('All', self::TEXT_DOMAIN); ?></option>
              <option value="income" <?php echo selected($tx_type, 'income', false); ?>><?php echo esc_html__('Income', self::TEXT_DOMAIN); ?></option>
              <option value="expense" <?php echo selected($tx_type, 'expense', false); ?>><?php echo esc_html__('Expense', self::TEXT_DOMAIN); ?></option>
            </select>
          </div>

          <div class="simku-filter-actions">
            <button class="button button-primary"><?php echo esc_html__('Apply', self::TEXT_DOMAIN); ?></button>
          </div>
        </form>
      </div>
    </div>

    <div class="fl-grid fl-grid-2">
      <?php foreach ((array)$dash_ids as $cid): ?>
        <?php
          $c = $this->find_chart($cid);
          if (!$c) { continue; }

          $c['date_basis'] = $date_basis;
          $c['filter'] = is_array($c['filter'] ?? null) ? $c['filter'] : [];

          if ($tx_type !== 'all') {
              $c['filter']['tx_type'] = $tx_type;
          } else {
              unset($c['filter']['tx_type']);
          }

          // For the default 7d charts, follow the dashboard From/To filter.
          if (in_array($cid, ['income_vs_outcome_day_7','by_category_7'], true)) {
              $c['range'] = ['mode' => 'custom', 'from' => $from, 'to' => $to];
          }

          // Apply dashboard grouping (daily/weekly/monthly) to the time-series chart.
          if ($cid === 'income_vs_outcome_day_7') {
              $x_map = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'];
              $c['x'] = $x_map[$group] ?? 'day';
          }

          $shortcode = '[fl_chart id="' . esc_attr($c['id']) . '"]';

          $dash_title = (string)($c['title'] ?? '');
          $dash_title = preg_replace('/\s*\((?:Last|Past)\s+[^\)]*\)\s*/i', '', $dash_title);
        ?>

        <div class="fl-card">
          <div class="fl-card-head fl-card-head-between">
            <h3><?php echo esc_html($dash_title); ?></h3>
            <div class="fl-head-actions">
              <button type="button" class="fl-kebab" aria-label="<?php echo esc_attr__('Chart actions', self::TEXT_DOMAIN); ?>" data-shortcode="<?php echo esc_attr($shortcode); ?>">
                <span class="fl-kebab-dots">⋮</span>
              </button>
              <div class="fl-menu" hidden>
                <button type="button" class="fl-menu-item fl-copy-shortcode" data-shortcode="<?php echo esc_attr($shortcode); ?>"><?php echo esc_html__('Copy shortcode', self::TEXT_DOMAIN); ?></button>
              </div>
            </div>
          </div>

          <?php echo $this->render_chart_container_with_config($c['id'], $c, true); ?>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>
