<?php
/**
 * Reports summary KPI cards.
 *
 * Variables expected:
 * - $title
 * - $tot (array)
 * - $tx_type (income|expense|all)
 */
if (!defined('ABSPATH')) { exit; }
$tx_type = $this->reports_sanitize_tx_type($tx_type);
?>
<?php if ($tx_type === 'income'): ?>
  <div class="fl-grid fl-grid-2 fl-mt">
    <div class="fl-card"><div class="fl-kpi-label"><?php echo esc_html($title); ?></div><div class="fl-kpi-value">—</div></div>
    <div class="fl-card"><div class="fl-kpi-label">Income</div><div class="fl-kpi-value">Rp <?php echo esc_html(number_format_i18n((float)($tot['income'] ?? 0))); ?></div></div>
  </div>
  <?php return; ?>
<?php endif; ?>

<?php if ($tx_type === 'expense'): ?>
  <div class="fl-grid fl-grid-2 fl-mt">
    <div class="fl-card"><div class="fl-kpi-label"><?php echo esc_html($title); ?></div><div class="fl-kpi-value">—</div></div>
    <div class="fl-card"><div class="fl-kpi-label">Expense</div><div class="fl-kpi-value">Rp <?php echo esc_html(number_format_i18n((float)($tot['expense'] ?? 0))); ?></div></div>
  </div>
  <?php return; ?>
<?php endif; ?>

<div class="fl-grid fl-grid-3 fl-mt">
  <div class="fl-card"><div class="fl-kpi-label"><?php echo esc_html($title); ?></div><div class="fl-kpi-value">—</div></div>
  <div class="fl-card"><div class="fl-kpi-label">Income</div><div class="fl-kpi-value">Rp <?php echo esc_html(number_format_i18n((float)($tot['income'] ?? 0))); ?></div></div>
  <div class="fl-card"><div class="fl-kpi-label">Expense</div><div class="fl-kpi-value">Rp <?php echo esc_html(number_format_i18n((float)($tot['expense'] ?? 0))); ?></div></div>
</div>
