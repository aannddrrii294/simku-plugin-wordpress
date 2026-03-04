<?php
/**
 * Reports detail transaction table.
 *
 * Variables expected:
 * - $rows (array)
 */
if (!defined('ABSPATH')) { exit; }
?>
<div class="fl-card fl-mt">
  <div class="fl-card-title">Transactions</div>
  <div class="fl-help">Up to 500 rows</div>

  <?php if (empty($rows)): ?>
    <p class="fl-help">No transactions found for this filter range.</p>
  </div>
  <?php return; ?>
  <?php endif; ?>

  <div class="fl-table-wrap">
    <table class="widefat striped">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Counterparty</th>
          <th>Item</th>
          <th class="num">Qty</th>
          <th class="num">Price</th>
          <th class="num">Total</th>
          <th>Purchase</th>
          <th>Receive</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ((array)$rows as $r):
          $qty = isset($r['quantity']) ? (float) $r['quantity'] : 0;
          $price = isset($r['harga']) ? (float) $r['harga'] : 0;
          $total = $qty * $price;

          $cat_norm = method_exists($this, 'normalize_category') ? $this->normalize_category((string)($r['kategori'] ?? '')) : strtolower(trim((string)($r['kategori'] ?? '')));
          $is_income  = ($cat_norm === 'income');
          $is_expense = (!$is_income); // treat any non-income category as expense for display consistency

          $struk = trim((string)($r['tanggal_struk'] ?? ''));
          $purchase_val = trim((string)($r['purchase_date'] ?? ''));
          $receive_val  = trim((string)($r['receive_date'] ?? ''));

          $purchase_disp = 'N/A';
          $receive_disp  = 'N/A';
          if ($is_expense) {
            $v = ($purchase_val && $purchase_val !== '0000-00-00') ? $purchase_val : $struk;
            $purchase_disp = ($v && $v !== '0000-00-00') ? $v : 'N/A';
          } elseif ($is_income) {
            $v = ($receive_val && $receive_val !== '0000-00-00') ? $receive_val : $struk;
            $receive_disp = ($v && $v !== '0000-00-00') ? $v : 'N/A';
          }
        ?>
          <tr>
            <td><?php echo esc_html(method_exists($this, 'fmt_mysql_dt_display') ? $this->fmt_mysql_dt_display((string)($r['tanggal_input'] ?? '')) : ($r['tanggal_input'] ?? '')); ?></td>
            <td><?php echo esc_html(method_exists($this, 'category_label') ? $this->category_label((string)($r['kategori'] ?? '')) : ($r['kategori'] ?? '')); ?></td>
            <td><?php echo esc_html($r['nama_toko'] ?? ''); ?></td>
            <td><?php echo esc_html($r['items'] ?? ''); ?></td>
            <td class="num"><?php echo esc_html($qty); ?></td>
            <td class="num"><?php echo esc_html(number_format_i18n($price, 0)); ?></td>
            <td class="num"><?php echo esc_html(number_format_i18n($total, 0)); ?></td>
            <td><?php echo esc_html($purchase_disp); ?></td>
            <td><?php echo esc_html($receive_disp); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
