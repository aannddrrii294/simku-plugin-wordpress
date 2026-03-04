<?php
/**
 * Admin page handlers (composed from feature modules).
 */
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Pages {
    use SIMKU_Trait_Admin_Core;
    use SIMKU_Trait_Admin_Dashboard;
    use SIMKU_Trait_Admin_Transactions;
    use SIMKU_Trait_Admin_Savings;
    use SIMKU_Trait_Admin_Reminders;
    use SIMKU_Trait_Admin_Scan;
    use SIMKU_Trait_Admin_Reports_Page;
    use SIMKU_Trait_Admin_Logs_Page;
    use SIMKU_Trait_Admin_Settings_Page;
    use SIMKU_Trait_Admin_Charts_Page;
}
