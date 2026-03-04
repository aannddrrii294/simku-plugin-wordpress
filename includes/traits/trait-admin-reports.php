<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Reports_Page {
public function page_reports() {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'daily';
        $user_param = isset($_GET['user']) ? sanitize_text_field(wp_unslash($_GET['user'])) : '';
        $type_param = isset($_GET['type']) ? sanitize_text_field(wp_unslash($_GET['type'])) : '';

        // Render via template for maintainability (behavior unchanged).
        $this->render_template('admin/reports/page.php', [
            'tab' => $tab,
            'user_param' => $user_param,
            'type_param' => $type_param,
        ]);
    }


}
