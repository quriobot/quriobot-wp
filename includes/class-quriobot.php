<?php
if (!defined('ABSPATH')) {
    exit;
}

class Quriobot
{

    public function __construct()
    {
    }

    const VERSION = '2.9.1';

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
        return $quriobot_init ? $quriobot_init : 'window.qbOptions = '. json_encode($qbOptions) . ';';
    }

    private function enqueue_script()
    {
        $cache_expiration = 3600 * 24 * 10; // 10 days
        $quriobot_path = trim(explode(PHP_EOL, get_option('quriobot_path'))[0]);
        if (!$quriobot_path || empty($quriobot_path)) {
            return;
        }
        $url = sprintf('https://api.botsrv2.com/0.0.1/frontend/bots/%s', $quriobot_path);
        $headers = ['X-For-Embed-Code' => 'true'];
        if (function_exists('amp_is_request') && amp_is_request()) {
            $cache_key = sprintf('quriobot.%s.bot.frontend.embed_code_amp.%s', static::VERSION, $quriobot_path);
            $embed_code_amp = get_transient($cache_key);
            if (!$embed_code_amp) {
                $res = Requests::get($url, $headers, array('verify' => false));
                if ($res->success) {
                    $bot = json_decode($res->body);
                    if ($bot) {
                        $embed_code_amp = $bot->frontend->embed_code_amp;
                        set_transient($cache_key, $embed_code_amp, $cache_expiration);
                    }
                } else if ($res->status_code == 404 || $res->status_code == 530 || $res->status_code == 531 || $res->status_code == 532) {
                    set_transient($cache_key, ' ', $cache_expiration);
                }
            }
            if ($embed_code_amp) {
                $quriobot_amp_body = function () use (&$embed_code_amp)
                {
                    print($embed_code_amp->body);
                };
                $quriobot_amp_head = function() use (&$embed_code_amp)
                {
                    print($embed_code_amp->head);
                };
                add_action('wp_head', $quriobot_amp_head, 1000);
                add_action('amp_print_analytics', $quriobot_amp_body, 1000);
            }
        } else {
            $cache_key = sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', static::VERSION, $quriobot_path);
            $embed_code_2 = get_transient($cache_key);
            if (!$embed_code_2) {
                $res = Requests::get($url, $headers);
                if ($res->success) {
                    $bot = json_decode($res->body);
                    if ($bot) {
                        $embed_code_2 = $bot->frontend->embed_code_2;
                        set_transient($cache_key, $embed_code_2, $cache_expiration);
                    }
                } else if ($res->status_code == 404 || $res->status_code == 530 || $res->status_code == 531 || $res->status_code == 532) {
                    set_transient($cache_key, ' ', $cache_expiration);
                }
            }
            if ($embed_code_2) {
                $code = $this->quriobot_script();
                $quriobot_head = function () use (&$embed_code_2, &$code) {
                    printf('
    %s
    <script type="text/javascript">
    %s
    </script>', $embed_code_2, $code);
                };
                add_action('wp_head', $quriobot_head, 1000);
            }
        }
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
