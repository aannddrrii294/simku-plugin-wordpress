<?php
/**
 * Admin UI: Budgeting (Budget vs Actual).
 * Variables provided:
 *  - $ym (YYYY-MM)
 *  - $user_scope ('all' or user_login)
 *  - $rows (budgets with actual)
 *  - $user_logins
 *  - $tag_options (distinct tags from transactions - income/expense)
 *  - $notices
 */
if (!defined('ABSPATH')) { exit; }

$categories = [
    'expense' => __('Expense', SIMAK_App_Simak::TEXT_DOMAIN),
    'income'  => __('Income', SIMAK_App_Simak::TEXT_DOMAIN),
    'saving'  => __('Savings', SIMAK_App_Simak::TEXT_DOMAIN),
    'invest'  => __('Invest', SIMAK_App_Simak::TEXT_DOMAIN),
];

$tag_options = is_array($tag_options ?? null) ? $tag_options : [];
$can_manage = current_user_can(SIMAK_App_Simak::CAP_MANAGE_BUDGETS) || current_user_can(SIMAK_App_Simak::CAP_MANAGE_SETTINGS);
?>
<div class="wrap fl-wrap">
    <h1><?php echo esc_html__('Budgets - Budget vs Actual', SIMAK_App_Simak::TEXT_DOMAIN); ?></h1>

    <?php if (!empty($notices)) : ?>
        <?php foreach ($notices as $n) : ?>
            <div class="notice notice-<?php echo esc_attr($n['type'] ?? 'info'); ?> is-dismissible"><p><?php echo esc_html($n['msg'] ?? ''); ?></p></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="fl-grid" style="grid-template-columns: 1fr; gap: 16px;">
        <div class="fl-card">
            <h2 style="margin-top:0;"><?php echo esc_html__('Filter', SIMAK_App_Simak::TEXT_DOMAIN); ?></h2>
            <form method="get">
                <input type="hidden" name="page" value="fl-budgets" />
                <div class="simku-report-filter simku-budget-filter">
                    <div class="simku-filter-field simku-filter-month">
                        <label><?php echo esc_html__('Month', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <input type="month" name="ym" value="<?php echo esc_attr($ym); ?>" />
                    </div>

                    <div class="simku-filter-field simku-filter-user">
                        <label><?php echo esc_html__('User', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
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
            <h2 style="margin-top:0;"><?php echo esc_html__('Add / Update Budget', SIMAK_App_Simak::TEXT_DOMAIN); ?></h2>

            <?php if (!$can_manage) : ?>
                <p class="fl-muted"><?php echo esc_html__('You have view-only access. Ask an admin to update budgets.', SIMAK_App_Simak::TEXT_DOMAIN); ?></p>
            <?php else : ?>
                <div id="simku-budget-editing" class="notice notice-info inline" style="display:none;"><p style="margin:.4em 0;"></p></div>
            <?php endif; ?>

            <form method="post" id="simku-budget-form">
                <?php wp_nonce_field('fl_save_budget'); ?>
                <input type="hidden" name="fl_save_budget" value="1" />
                <input type="hidden" name="budget_id" id="simku_budget_id" value="0" />

                <div class="simku-report-filter simku-budget-form">
                    <div class="simku-filter-field simku-filter-month">
                        <label><?php echo esc_html__('Month', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <input type="month" name="budget_ym" id="simku_budget_ym" value="<?php echo esc_attr($ym); ?>" required <?php echo $can_manage ? '' : 'disabled'; ?> />
                    </div>

                    <div class="simku-filter-field simku-filter-category">
                        <label><?php echo esc_html__('Category', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <select name="budget_category" id="simku_budget_category" required <?php echo $can_manage ? '' : 'disabled'; ?>>
                            <?php foreach ($categories as $k => $label) : ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="simku-filter-field simku-filter-user">
                        <label><?php echo esc_html__('User Scope', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <select name="budget_user" id="simku_budget_user" <?php echo $can_manage ? '' : 'disabled'; ?>>
                            <?php foreach ($user_logins as $u) : ?>
                                <option value="<?php echo esc_attr($u); ?>" <?php selected($user_scope, $u); ?>><?php echo esc_html($u); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="simku-filter-field simku-filter-amount">
                        <label><?php echo esc_html__('Budget Amount (Rp)', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <input type="number" name="budget_amount" id="simku_budget_amount" min="0" step="1" placeholder="500000" required <?php echo $can_manage ? '' : 'disabled'; ?> />
                    </div>

                    <div class="simku-filter-field simku-filter-tags" id="simku_budget_tags_wrap" style="display:none;">
                        <label class="simku-label-with-help">
                            <?php echo esc_html__('Tags (optional)', SIMAK_App_Simak::TEXT_DOMAIN); ?>
                            <span class="simku-help">
                                <button type="button" class="simku-help-icon" aria-expanded="false" aria-label="Info">
                                    <span class="dashicons dashicons-info-outline"></span>
                                </button>
                                <div class="simku-help-pop" aria-hidden="true">
                                    <p><?php echo esc_html__('Only for Income/Expense budgets. Choose one or more tags from Transactions.', SIMAK_App_Simak::TEXT_DOMAIN); ?></p>
                                    <p><?php echo esc_html__('If a tag is not in the list, type it here. It will be merged with selected tags.', SIMAK_App_Simak::TEXT_DOMAIN); ?></p>
                                    <p><?php echo esc_html__('Use all for global budget, or pick a specific user_login. Tags apply only to Income/Expense budgets.', SIMAK_App_Simak::TEXT_DOMAIN); ?></p>
                                </div>
                            </span>
                        </label>
                        <select name="budget_tags[]" id="simku_budget_tags" multiple size="8" <?php echo $can_manage ? '' : 'disabled'; ?>>
                            <?php foreach ($tag_options as $t) : ?>
                                <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="simku-filter-field simku-filter-tags-manual" id="simku_budget_tags_manual_wrap">
                        <label class="simku-tags-manual-label"><?php echo esc_html__('Or manual tags (comma separated)', SIMAK_App_Simak::TEXT_DOMAIN); ?></label>
                        <div class="simku-inline-row">
                            <input type="text" name="budget_tags_manual" id="simku_budget_tags_manual" class="simku-tags-manual-input" placeholder="salary,bonus" <?php echo $can_manage ? '' : 'disabled'; ?> />
                            <div class="simku-inline-actions">
                                <button class="button button-primary" type="submit" <?php echo $can_manage ? '' : 'disabled'; ?>><?php echo esc_html__('Save Budget', SIMAK_App_Simak::TEXT_DOMAIN); ?></button>
                                <button class="button" type="button" id="simku-budget-cancel" style="display:none;" <?php echo $can_manage ? '' : 'disabled'; ?>><?php echo esc_html__('Cancel Edit', SIMAK_App_Simak::TEXT_DOMAIN); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="fl-card">
            <h2 style="margin-top:0;"><?php echo esc_html__('Budget vs Actual', SIMAK_App_Simak::TEXT_DOMAIN); ?></h2>

            <?php if (empty($rows)) : ?>
                <p><?php echo esc_html__('No budgets set for this month/scope yet.', SIMAK_App_Simak::TEXT_DOMAIN); ?></p>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Category', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Tags', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Budget', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Actual', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Diff (Budget-Actual)', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('% Used', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                                <th><?php echo esc_html__('Actions', SIMAK_App_Simak::TEXT_DOMAIN); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r) : ?>
                                <?php
                                    $pct = $r['pct'];
                                    $pct_text = ($pct === null) ? '-' : (number_format_i18n($pct, 2) . '%');
                                    $diff = (int)($r['diff'] ?? 0);
                                    $diff_style = ($diff < 0) ? 'color:#b32d2e;font-weight:600;' : 'color:#1e7e34;font-weight:600;';
                                    $tag_filter = (string)($r['tag_filter'] ?? '');
                                    $payload = [
                                        'id' => (int)($r['id'] ?? 0),
                                        'ym' => (string)($r['ym'] ?? $ym),
                                        'category' => (string)($r['category'] ?? ''),
                                        'user' => (string)($r['user'] ?? $user_scope),
                                        'budget' => (int)($r['budget'] ?? 0),
                                        'tag_filter' => $tag_filter,
                                    ];
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($categories[$r['category']] ?? $r['category']); ?></strong></td>
                                    <td><?php echo $tag_filter !== '' ? '<code>' . esc_html($tag_filter) . '</code>' : '<span class="fl-muted">—</span>'; ?></td>
                                    <td><?php echo esc_html('Rp ' . number_format_i18n((float)($r['budget'] ?? 0))); ?></td>
                                    <td><?php echo esc_html('Rp ' . number_format_i18n((float)($r['actual'] ?? 0))); ?></td>
                                    <td style="<?php echo esc_attr($diff_style); ?>"><?php echo esc_html('Rp ' . number_format_i18n((float)$diff)); ?></td>
                                    <td><?php echo esc_html($pct_text); ?></td>
                                    <td>
                                        <?php if ($can_manage) : ?>
                                            <button type="button" class="button button-small simku-budget-edit" data-budget="<?php echo esc_attr(wp_json_encode($payload)); ?>"><?php echo esc_html__('Edit', SIMAK_App_Simak::TEXT_DOMAIN); ?></button>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this budget?');">
                                                <?php wp_nonce_field('fl_delete_budget'); ?>
                                                <input type="hidden" name="fl_delete_budget" value="1" />
                                                <input type="hidden" name="budget_id" value="<?php echo esc_attr((int)$r['id']); ?>" />
                                                <button class="button button-small" type="submit"><?php echo esc_html__('Delete', SIMAK_App_Simak::TEXT_DOMAIN); ?></button>
                                            </form>
                                        <?php else : ?>
                                            <span class="fl-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="fl-help" style="margin-top:10px;">
                <?php echo esc_html__('Actual is calculated from Transactions using Receipt Date basis for the selected month.', SIMAK_App_Simak::TEXT_DOMAIN); ?>
            </div>
        </div>

    </div>
</div>

