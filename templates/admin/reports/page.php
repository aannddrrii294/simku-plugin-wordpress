<?php
/**
 * Reports page wrapper.
 *
 * Variables expected:
 * - $tab (daily|weekly|monthly)
 * - $user_param
 * - $type_param
 */
if (!defined('ABSPATH')) { exit; }
?>
<div class="wrap fl-wrap">
  <?php echo $this->page_header_html('Reports', '[simku_reports]', '[simku page="reports"]'); ?>
  <h2 class="nav-tab-wrapper">
    <?php foreach (['daily'=>'Daily','weekly'=>'Weekly (Custom)','monthly'=>'Monthly'] as $k=>$label):
      $args = ['page'=>'fl-reports','tab'=>$k];
      if ($user_param !== '' && $user_param !== '0') { $args['user'] = $user_param; }
      if ($type_param !== '' && $type_param !== '0') { $args['type'] = $type_param; }
      $url = add_query_arg($args, admin_url('admin.php'));
    ?>
      <a class="nav-tab <?php echo ($tab === $k ? 'nav-tab-active' : ''); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
    <?php endforeach; ?>
  </h2>

  <?php
    if ($tab === 'daily') {
      $this->report_daily();
    } elseif ($tab === 'weekly') {
      $this->report_weekly_custom();
    } else {
      $this->report_monthly();
    }
  ?>
</div>
