<?php
if (!defined('ABSPATH')) {
    exit;
}

class Quriobot
{

    public function __construct()
    {
    }

    const VERSION = '2.5.6';

    public function init()
    {
        $this->init_admin();
        $this->enqueue_script();
        $this->enqueue_admin_styles();
    }

    public function init_admin()
    {
        $args = array(
            'type' => 'array',
        );
        register_setting('quriobot', 'quriobot_path', $args);
        register_setting('quriobot', 'quriobot_init', $args);
        add_action('admin_menu', array($this, 'create_nav_page'));
    }

    public function create_nav_page()
    {
        add_options_page(
            esc_html__('Quriobot', 'quriobot'),
            esc_html__('Quriobot', 'quriobot'),
            'manage_options',
            'quriobot_settings',
            array($this, 'admin_view')
        );
    }

    public static function admin_view()
    {
        require_once plugin_dir_path(__FILE__) . '/../admin/views/settings.php';
    }

    public static function quriobot_script()
    {
        $quriobot_path = get_option('quriobot_path');
        $quriobot_init = get_option('quriobot_init');
        $is_admin = is_admin();

        $quriobot_path = trim($quriobot_path);
        if (!$quriobot_path && !$quriobot_init) {
            return;
        }

        if ($is_admin) {
            return;
        }
        $current_lang = get_locale();
        if (isset($_SERVER['HTTP_X_GT_LANG'])) {
            $current_lang = $_SERVER['HTTP_X_GT_LANG'];
        }
        $current_user_data = null;
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            /*
            'user_firstname'             => 'first_name',
                    'user_lastname'              => 'last_name',
                    'user_description'           => 'description',
            user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name, spam (multisite only), deleted
            */
            $current_user_data = [
                "email" => $current_user->user_email,
                "firstName" => $current_user->get('first_name'),
                "lastName" => $current_user->get('last_name'),
                "id" => $current_user->ID,
                "avatar" => get_avatar_url($current_user->ID),
            ];
        };
        $prepareValue = function ($item) use ($current_lang, $current_user_data) {
            $item = trim($item);
            $res = [
                "use" => $item,
                "language" => strtolower(str_replace('_', '-', $current_lang)),
            ];
            if ($current_user_data) {
                $res["visitor"] = $current_user_data;
            };
            return $res;
        };
        $qbOptions = array_unique(array_map($prepareValue, explode(PHP_EOL, $quriobot_path)), SORT_REGULAR);
        $code = $quriobot_init ? $quriobot_init : 'window.qbOptions = window.qbOptions.concat(' . json_encode($qbOptions) . ');';
        echo '
<script type="text/javascript">
    if (!Array.isArray(window.qbOptions)) {
        window.qbOptions = []
    }
    ' . $code . '
</script>
<script type="text/javascript" src="https://static.botsrv2.com/website/js/widget2.b5d28c6c.js" integrity="sha384-Yj0d2SPKt+lIc2iXzEESMQLEtMrYiL4PANrbckbRlmufhv7aCYShLzsDqQ1LdbKf" crossorigin="anonymous" defer></script>
';
    }

    private function enqueue_script()
    {
        add_action('wp_head', array($this, 'quriobot_script'), 1000);
    }

    private function enqueue_admin_styles()
    {
        add_action('admin_enqueue_scripts', array($this, 'quriobot_admin_styles'));
    }

    public static function quriobot_admin_styles()
    {
        wp_register_style('quriobot_custom_admin_style', plugins_url('../admin/static/quriobot-admin.css', __FILE__), array(), '20190701', 'all');
        wp_enqueue_style('quriobot_custom_admin_style');
    }
}
