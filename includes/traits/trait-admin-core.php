<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Core {
    public function page_entry() {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Please login.', self::TEXT_DOMAIN));
        }
        if (current_user_can(self::CAP_VIEW_TX)) {
            $this->page_dashboard();
            return;
        }
        $this->page_charts_list();
    }


    

    private function render_template($rel_path, $vars = []) {
        // Templates live in <plugin-root>/templates/. This trait file is under includes/traits/.
        // dirname(__FILE__, 3) => <plugin-root>/
        $base = trailingslashit(dirname(__FILE__, 3)) . 'templates/';
        $path = $base . ltrim($rel_path, '/');
        if (!file_exists($path)) {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('Template missing.', self::TEXT_DOMAIN) . ' ' . esc_html($rel_path) .
                '</p></div>';
            return;
        }
        if (!empty($vars)) {
            extract($vars, EXTR_SKIP);
        }
        include $path;
    }

    


    private function page_header_html($title, $shortcode = '', $alt = '') {
        $actions = '';
        if ($shortcode) {
            $actions .= '<div class="fl-head-actions fl-sc-actions">';
            $actions .= '<button type="button" class="fl-kebab" aria-label="Shortcode actions"><span class="fl-kebab-dots">⋮</span></button>';
            $actions .= '<div class="fl-menu" hidden>';
            $actions .= '<button type="button" class="fl-menu-item fl-copy-shortcode" data-shortcode="' . esc_attr($shortcode) . '">Copy shortcode</button>';
            if ($alt) {
                $actions .= '<button type="button" class="fl-menu-item fl-copy-shortcode" data-shortcode="' . esc_attr($alt) . '">Copy alternative</button>';
            }
            $actions .= '</div></div>';
        }
        return '<div class="fl-page-head"><h1>' . esc_html($title) . '</h1>' . $actions . '</div>';
    }
}
