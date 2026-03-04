<?php
/**
 * Admin UI: Add / Edit Budget Target.
 * Variables provided:
 *  - $editing (bool)
 *  - $goal (array|null) goal row for edit
 *  - $user_logins
 *  - $notices
 */
if (!defined('ABSPATH')) { exit; }

// Budget Targets are currently supported for Income and Savings only.
// (Expense/Invest targets are removed from the UI by request.)
$categories = [
    'income'  => __('Income', SIMAK_App_Simak::TEXT_DOMAIN),
    'saving'  => __('Savings', SIMAK_App_Simak::TEXT_DOMAIN),
];

$goal = is_array($goal) ? $goal : [];
$goal_id_val = $editing ? (int)($goal['id'] ?? 0) : 0;
$goal_name_val = $editing ? (string)($goal['goal_name'] ?? '') : '';
$goal_basis_raw = $editing ? (string)($goal['basis'] ?? 'saving') : 'saving';
$goal_basis_val = $goal_basis_raw;
// If editing a legacy target (expense/invest), keep its basis but do not offer it as a selectable option.
$is_legacy_basis = (!isset($categories[$goal_basis_val]) && $editing);
if (!$editing && !isset($categories[$goal_basis_val])) $goal_basis_val = 'saving';
$goal_amount_val = $editing ? (int)($goal['target_amount'] ?? 0) : 0;

$goal_date_iso = $editing ? (string)($goal['target_date'] ?? '') : '';
$goal_date_val = ($goal_date_iso && preg_match('/^\d{4}-\d{2}-\d{2}$/', $goal_date_iso)) ? $goal_date_iso : '';

$goal_start_iso = $editing ? (string)($goal['start_date'] ?? '') : '';
$goal_start_val = ($goal_start_iso && preg_match('/^\d{4}-\d{2}-\d{2}$/', $goal_start_iso)) ? $goal_start_iso : '';

$goal_user_val = $editing ? (string)($goal['user_scope'] ?? 'all') : 'all';
if ($goal_user_val === '') $goal_user_val = 'all';

// Income tag filter (CSV). Only applies when Basis=Income.
$goal_tag_filter = $editing ? (string)($goal['tag_filter'] ?? '') : '';
$goal_income_tags_selected = [];
if ($goal_tag_filter !== '') {
    $goal_income_tags_selected = array_values(array_filter(array_map('trim', explode(',', $goal_tag_filter))));
}


$back_url = add_query_arg([
    'page' => 'fl-budget-goals',
], admin_url('admin.php'));

?>

<div class="wrap fl-wrap">
    <h1 style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <span><?php echo esc_html($editing ? __('Edit Budget Target', SIMAK_App_Simak::TEXT_DOMAIN) : __('Add Budget Target', SIMAK_App_Simak::TEXT_DOMAIN)); ?></span>
        <a class="button" href="<?php echo esc_url($back_url); ?>"><?php echo esc_html__('Back to List', SIMAK_App_Simak::TEXT_DOMAIN); ?></a>
    </h1>

    <?php if (!empty($notices)) : ?>
        <?php foreach ($notices as $n) : ?>
            <div class="notice notice-<?php echo esc_attr($n['type'] ?? 'info'); ?> is-dismissible"><p><?php echo esc_html($n['msg'] ?? ''); ?></p></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="fl-grid" style="grid-template-columns: 1fr; gap: 16px;">
        <div class="fl-card">
            <h2 style="margin-top:0;"><?php echo esc_html__('Budget Target', SIMAK_App_Simak::TEXT_DOMAIN); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="simku_save_goal" />
                <?php wp_nonce_field('simku_save_goal'); ?>
                <input type="hidden" name="goal_id" value="<?php echo esc_attr($goal_id_val); ?>" />

                <div class="simku-form-vertical">
                    <div class="fl-field">
                        <label><?php echo esc_html__('Target Name', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <input type="text" name="goal_name" value="<?php echo esc_attr($goal_name_val); ?>" placeholder="e.g. Eid Budget / Food Budget" required />
                    </div>

                    <div class="fl-field">
                        <label><?php echo esc_html__('Basis', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <?php if ($is_legacy_basis) : ?>
                            <input type="text" class="regular-text" value="<?php echo esc_attr(ucfirst($goal_basis_val)); ?>" disabled />
                            <input type="hidden" name="goal_basis" value="<?php echo esc_attr($goal_basis_val); ?>" />
                            <p class="description"><?php echo esc_html__('This target uses a legacy basis that is no longer available for new targets.', SIMAK_App_Simak::TEXT_DOMAIN); ?></p>
                        <?php else : ?>
                            <select name="goal_basis" required>
                                <?php foreach ($categories as $k => $label) : ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($goal_basis_val, $k); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

<div class="fl-field simku-income-tags-field" style="display:none;">
    <label><?php echo esc_html__('Income Tags (optional)', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
    <select name="goal_income_tags[]" class="fl-input" multiple style="width:100%;min-height:80px;">
        <?php if (empty($income_tags_options)) : ?>
            <option value="" disabled><?php echo esc_html__('No tags found yet. Add tags in Transactions first.', SIMAK_App_Simak::TEXT_DOMAIN); ?></option>
        <?php else : ?>
            <?php foreach ((array)$income_tags_options as $t) : ?>
                <option value="<?php echo esc_attr($t); ?>" <?php echo in_array($t, $goal_income_tags_selected, true) ? 'selected' : ''; ?>><?php echo esc_html($t); ?></option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <p class="description"><?php echo esc_html__('Filter this Income target by one or more tags. Leave empty to count all income.', SIMAK_App_Simak::TEXT_DOMAIN); ?></p>
</div>

                    <div class="fl-field">
                        <label><?php echo esc_html__('Target Amount (Rp)', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <input type="number" name="goal_target_amount" min="0" step="1" value="<?php echo esc_attr($goal_amount_val ?: ''); ?>" placeholder="500000" required />
                    </div>

                    <div class="fl-field">
                        <label><?php echo esc_html__('Target Date', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <input type="date" class="fl-input" name="goal_target_date" value="<?php echo esc_attr($goal_date_val); ?>" required />
                    </div>

                    <div class="fl-field">
                        <label><?php echo esc_html__('Start Date', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <input type="date" class="fl-input" name="goal_start_date" value="<?php echo esc_attr($goal_start_val); ?>" />
                    </div>

                    <div class="fl-field">
                        <label><?php echo esc_html__('User Scope', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <select name="goal_user">
                            <?php foreach ($user_logins as $u) : ?>
                                <option value="<?php echo esc_attr($u); ?>" <?php selected($goal_user_val, $u); ?>><?php echo esc_html($u); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <p class="description" style="margin-top:10px;">
                    <?php echo esc_html__('Progress is calculated by Basis using: Income → Receive Date (Transactions), Savings → Saved at (Savings allocations). For Savings progress, allocate Savings entries to this Budget Target.', SIMAK_App_Simak::TEXT_DOMAIN); ?>
                </p>

                <div class="fl-btnrow" style="margin-top:12px;">
                    <button class="button button-primary" type="submit"><?php echo esc_html($editing ? __('Update Target', SIMAK_App_Simak::TEXT_DOMAIN) : __('Save Target', SIMAK_App_Simak::TEXT_DOMAIN)); ?></button>
                    <a class="button" href="<?php echo esc_url($back_url); ?>"><?php echo esc_html__('Cancel', SIMAK_App_Simak::TEXT_DOMAIN); ?></a>
                </div>
            <script>
document.addEventListener('DOMContentLoaded', function () {
  var basisSel = document.querySelector('select[name="goal_basis"]');
  var field = document.querySelector('.simku-income-tags-field');
  function toggle() {
    if (!basisSel || !field) return;
    field.style.display = (basisSel.value === 'income') ? 'block' : 'none';
  }
  if (basisSel && field) {
    basisSel.addEventListener('change', toggle);
    toggle();
  }
});
</script>
</form>
        </div>
    </div>
</div>
