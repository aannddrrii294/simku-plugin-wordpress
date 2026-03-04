<?php
/**
 * Admin UI: Budget Target (Goals).
 * Variables provided:
 *  - $user_scope ('all' or user_login)
 *  - $goals (budget goals with progress)
 *  - $user_logins
 *  - $notices
 */
if (!defined('ABSPATH')) { exit; }

$categories = [
    'income'  => __('Income', SIMAK_App_Simak::TEXT_DOMAIN),
    'saving'  => __('Savings', SIMAK_App_Simak::TEXT_DOMAIN),
];

$add_url = add_query_arg([
    'page' => 'fl-add-budgeting',
], admin_url('admin.php'));

$budgets_url = add_query_arg([
    'page' => 'fl-budgets',
], admin_url('admin.php'));

// Overview stats
$total_targets = is_array($goals) ? count($goals) : 0;
$completed_targets = 0;
$active_targets = 0;
foreach ((array)$goals as $g) {
    $pct = $g['pct'] ?? null;
    if ($pct !== null && (float)$pct >= 100.0) {
        $completed_targets++;
    } else {
        $active_targets++;
    }
}

?>

<div class="wrap fl-wrap">
    <h1><?php echo esc_html__('Budget Target', SIMAK_App_Simak::TEXT_DOMAIN); ?></h1>

    <?php if (!empty($notices)) : ?>
        <?php foreach ($notices as $n) : ?>
            <div class="notice notice-<?php echo esc_attr($n['type'] ?? 'info'); ?> is-dismissible"><p><?php echo esc_html($n['msg'] ?? ''); ?></p></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Top row: Quick Links + Overview -->
    <div class="simku-top-row" style="margin-top: 16px;">
        <div class="fl-card">
            <h2 style="margin-top:0;"><?php echo esc_html__('Quick Links', SIMAK_App_Simak::TEXT_DOMAIN); ?></h2>
            <div class="fl-btnrow">
                <a class="button button-primary" href="<?php echo esc_url($add_url); ?>"><?php echo esc_html__('Add Budget Target', SIMAK_App_Simak::TEXT_DOMAIN); ?></a>
                <a class="button" href="<?php echo esc_url($budgets_url); ?>"><?php echo esc_html__('Budgets', SIMAK_App_Simak::TEXT_DOMAIN); ?></a>
            </div>
        </div>

        <div class="fl-card">
            <h2 style="margin-top:0;"><?php echo esc_html__('Overview', SIMAK_App_Simak::TEXT_DOMAIN); ?></h2>
            <div class="fl-kpis" style="grid-template-columns: repeat(2, 1fr);">
                <div class="fl-kpi">
                    <div class="fl-kpi-label"><?php echo esc_html__('Total Targets', SIMAK_App_Simak::TEXT_DOMAIN); ?></div>
                    <div class="fl-kpi-value"><?php echo esc_html(number_format_i18n((int)$total_targets)); ?></div>
                </div>
                <div class="fl-kpi">
                    <div class="fl-kpi-label"><?php echo esc_html__('Active Targets', SIMAK_App_Simak::TEXT_DOMAIN); ?></div>
                    <div class="fl-kpi-value"><?php echo esc_html(number_format_i18n((int)$active_targets)); ?></div>
                </div>
            </div>
            <div class="fl-help" style="margin-top:10px;">
                <?php echo esc_html__('Progress is calculated by Basis using: Income → Receive Date (Transactions), Savings → Saved at (Savings allocations). For Savings progress, allocate Savings entries to a Budget Target.', SIMAK_App_Simak::TEXT_DOMAIN); ?>
            </div>
        </div>
    </div>

    <div class="fl-grid" style="grid-template-columns: 1fr; gap: 16px; margin-top: 16px;">
        <div class="fl-card">
            <h2 style="margin-top:0;"><?php echo esc_html__('Filter', SIMAK_App_Simak::TEXT_DOMAIN); ?></h2>

            <form method="get">
                <input type="hidden" name="page" value="fl-budget-goals" />
                <div class="simku-report-filter">
                    <div class="simku-filter-field simku-filter-user">
                        <label><?php echo esc_html__('User Scope', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <select name="user">
                            <?php foreach ($user_logins as $u) : ?>
                                <option value="<?php echo esc_attr($u); ?>" <?php selected($user_scope, $u); ?>><?php echo esc_html($u); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="simku-filter-actions">
                        <button class="button button-primary" type="submit"><?php echo esc_html__('Apply', SIMAK_App_Simak::TEXT_DOMAIN); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <div class="fl-card">
            <h2 style="margin-top:0;"><?php echo esc_html__('Budget Targets', SIMAK_App_Simak::TEXT_DOMAIN); ?></h2>

            <?php if (empty($goals)) : ?>
                <p><?php echo esc_html__('No budget targets found for this scope.', SIMAK_App_Simak::TEXT_DOMAIN); ?></p>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Name', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Basis', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Target', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Start Date', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Target Date', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Actual', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Remaining', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('% Used', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Progress Period', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Actions', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($goals as $g) : ?>
                                <?php
                                    $pct = $g['pct'];
                                    $pct_text = ($pct === null) ? '-' : (number_format_i18n($pct, 2) . '%');
                                    $diff = (int)($g['diff'] ?? 0);
                                    $diff_style = ($diff < 0) ? 'color:#b32d2e;font-weight:600;' : 'color:#1e7e34;font-weight:600;';
                                    $edit_url = add_query_arg([
                                        'page' => 'fl-add-budgeting',
                                        'goal_id' => (int)($g['id'] ?? 0),
                                    ], admin_url('admin.php'));
                                    $goal_id = (int)($g['id'] ?? 0);
                                    $pct_val = ($pct === null) ? '' : (string)$pct;
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($g['goal_name'] ?? ''); ?></strong></td>
                                    <?php $basis_key = (string)($g['basis'] ?? ''); ?>
                                    <td><?php echo esc_html($categories[$basis_key] ?? ucfirst($basis_key)); ?><?php if ($basis_key === 'income' && !empty($g['tag_filter'])) : ?><div class="description" style="margin-top:2px;"><?php echo esc_html($g['tag_filter']); ?></div><?php endif; ?></td>
                                    <td><?php echo esc_html('Rp ' . number_format_i18n((float)($g['target_amount'] ?? 0))); ?></td>
                                    <td><?php echo esc_html(!empty($g['start_date']) ? wp_date('d/m/Y', strtotime((string)($g['start_date'] ?? ''))) : ''); ?></td>
                                    <td><?php echo esc_html(wp_date('d/m/Y', strtotime((string)($g['target_date'] ?? '')))); ?></td>
                                    <td><?php echo esc_html('Rp ' . number_format_i18n((float)($g['actual'] ?? 0))); ?></td>
                                    <td style="<?php echo esc_attr($diff_style); ?>"><?php echo esc_html('Rp ' . number_format_i18n((float)$diff)); ?></td>
                                    <td class="simku-pct-cell">
                                        <?php if ($pct === null) : ?>
                                            <span class="simku-pct-text">-</span>
                                        <?php else : ?>
                                            <div class="simku-pct-wrap">
                                                <div class="simku-pct-chart" id="simku_pct_<?php echo esc_attr($goal_id); ?>" data-pct="<?php echo esc_attr($pct_val); ?>"></div>
                                                <div class="simku-pct-text"><?php echo esc_html($pct_text); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(($g['progress_start'] ?? '') . ' → ' . ($g['progress_end'] ?? '')); ?></td>
                                    <td>
                                        <a class="button button-small" href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html__('Edit', SIMAK_App_Simak::TEXT_DOMAIN); ?></a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Delete this budget target?');">
                                            <input type="hidden" name="action" value="simku_delete_goal" />
                                            <?php wp_nonce_field('simku_delete_goal'); ?>
                                            <input type="hidden" name="goal_id" value="<?php echo esc_attr((int)($g['id'] ?? 0)); ?>" />
                                            <input type="hidden" name="user_scope" value="<?php echo esc_attr($user_scope); ?>" />
                                            <button class="button button-small" type="submit"><?php echo esc_html__('Delete', SIMAK_App_Simak::TEXT_DOMAIN); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
