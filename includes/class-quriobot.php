<?php
if (!defined('ABSPATH')) {
    exit;
}

class Quriobot
{

    public function __construct()
    {
    }

    const VERSION = '2.9.2';

    public function init()
    {
        $this->init_admin();

        // Deferred to `wp` rather than run here at `plugins_loaded`: amp_is_request()
        // must not be called before the query is parsed — the AMP plugin raises
        // _doing_it_wrong() naming this plugin, and its answer is only a guess that
        // early. This also keeps the API call off admin, cron, REST and login
        // requests, which never render the widget.
        add_action('wp', array($this, 'enqueue_script'));

        $this->enqueue_admin_styles();
    }

    public function init_admin()
    {
        // Registered on admin_init, not here: the sanitize callbacks are only
        // meaningful for the settings screen, and one of them calls
        // add_settings_error(), which lives in wp-admin and does not exist on
        // front-end, REST or cron requests.
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'create_nav_page'));
    }

    public function register_settings()
    {
        register_setting('quriobot', 'quriobot_path', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_bot_paths'),
            'default' => '',
        ));
        register_setting('quriobot', 'quriobot_init', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_init_code'),
            'default' => '',
        ));
    }

    /**
     * Splits a textarea value into trimmed, non-empty lines.
     *
     * Browsers submit textareas with CRLF line endings regardless of the server
     * platform, so this cannot rely on PHP_EOL.
     */
    private static function split_lines($value)
    {
        if (!is_string($value) || '' === trim($value)) {
            return array();
        }
        $lines = preg_split('/\R/', $value);

        return array_values(array_filter(array_map('trim', $lines), 'strlen'));
    }

    /**
     * Sanitizes the bot paths textarea: one path per line, no markup.
     */
    public function sanitize_bot_paths($value)
    {
        return implode("\n", array_map('wp_strip_all_tags', self::split_lines($value)));
    }

    /**
     * Sanitizes the custom initialization snippet.
     *
     * This field is deliberately raw JavaScript — it exists so administrators can
     * fully customize the widget options — so its contents cannot be escaped
     * without destroying the feature. Writing it therefore requires
     * `unfiltered_html`, the capability WordPress uses for exactly this kind of
     * unescaped input. On a single site that is every administrator; on
     * multisite it is super admins only, which is the point: `manage_options`
     * alone is held by site admins precisely where `unfiltered_html` is withheld.
     */
    public function sanitize_init_code($value)
    {
        $value = is_string($value) ? trim($value) : '';
        $stored = (string) get_option('quriobot_init');

        // Browsers submit textareas with CRLF, so a snippet stored with bare LF
        // would never compare equal and the site would be stuck unable to save.
        $normalise = function ($code) {
            return preg_replace('/\R/', "\n", $code);
        };

        // The settings form submits both fields on every save, so only an actual
        // attempt to introduce new code is refused. Leaving the field untouched,
        // or clearing it, must always work — otherwise an install where nobody
        // holds unfiltered_html could neither save the bot path nor remove a
        // snippet, and the suppressed "Settings saved." notice would make it
        // look as though saving had failed outright.
        if ('' === $value || $normalise($value) === $normalise($stored)) {
            return $value;
        }

        if (!current_user_can('unfiltered_html')) {
            if (function_exists('add_settings_error')) {
                add_settings_error(
                    'quriobot_init',
                    'quriobot_init_forbidden',
                    __('You do not have permission to change the bot initialization code, so it was left unchanged.', 'quriobot')
                );
            }
            return $stored;
        }

        return $value;
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
        $quriobot_path = trim((string) get_option('quriobot_path'));
        $quriobot_init = trim((string) get_option('quriobot_init'));

        if (!$quriobot_path && !$quriobot_init) {
            return;
        }

        if (is_admin()) {
            return;
        }

        if ($quriobot_init) {
            return $quriobot_init;
        }

        $current_lang = get_locale();
        if (isset($_SERVER['HTTP_X_GT_LANG'])) {
            $current_lang = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_GT_LANG']));
        }
        $current_user_data = null;
        if (is_user_logged_in()) {
            // The payload below embeds this visitor's email, name and avatar in
            // <head>. If a full-page cache stores that response it would be served
            // to whoever requests the page next, so opt this render out of caching.
            // DONOTCACHEPAGE is the shared convention understood by WP Super Cache,
            // W3 Total Cache, WP Rocket and Batcache, so it deliberately carries no
            // plugin prefix.
            if (!defined('DONOTCACHEPAGE')) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
                define('DONOTCACHEPAGE', true);
            }

            $current_user = wp_get_current_user();
            $current_user_data = array(
                "email" => $current_user->user_email,
                "firstName" => $current_user->get('first_name'),
                "lastName" => $current_user->get('last_name'),
                "id" => $current_user->ID,
                "avatar" => get_avatar_url($current_user->ID),
            );
        }
        $prepareValue = function ($item) use ($current_lang, $current_user_data) {
            $res = array(
                "use" => $item,
                "language" => strtolower(str_replace('_', '-', $current_lang)),
            );
            if ($current_user_data) {
                $res["visitor"] = $current_user_data;
            }
            return $res;
        };
        $qbOptions = array_values(
            array_unique(array_map($prepareValue, self::split_lines($quriobot_path)), SORT_REGULAR)
        );

        // JSON_HEX_TAG keeps a "</script>" sequence in any value from breaking out
        // of the inline <script> block this is printed into; JSON_HEX_AMP does the
        // same for HTML entities that a filter might otherwise re-decode.
        return 'window.qbOptions = ' . wp_json_encode($qbOptions, JSON_HEX_TAG | JSON_HEX_AMP) . ';';
    }

    /**
     * Fetches the bot's frontend payload from the Quriobot API.
     *
     * @return array Two keys: 'body', the decoded response (mixed; null when the
     *               request failed) and 'status', the HTTP status (0 on transport error).
     */
    private static function fetch_bot($quriobot_path)
    {
        // The route is /frontend/bots/{organisation}/{bot}, so the separator has
        // to stay a real slash — encode each segment, not the whole path.
        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $quriobot_path)));
        $url = sprintf('https://api.botsrv2.com/0.0.1/frontend/bots/%s', $encoded_path);
        $res = wp_remote_get($url, array(
            'headers' => array('X-For-Embed-Code' => 'true'),
            'timeout' => 10,
        ));

        if (is_wp_error($res)) {
            return array('body' => null, 'status' => 0);
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        if ($status < 200 || $status >= 300) {
            return array('body' => null, 'status' => $status);
        }

        return array('body' => json_decode(wp_remote_retrieve_body($res)), 'status' => $status);
    }

    /**
     * Statuses for which a bot is known-missing, so the empty result is worth caching.
     */
    private static function is_permanent_failure($status)
    {
        return in_array($status, array(404, 530, 531, 532), true);
    }

    /**
     * Cache sentinel meaning "the API told us this bot has no embed code".
     *
     * It has to be distinguishable from both a cache miss (get_transient()
     * returns false) and a real embed code. Earlier versions stored a single
     * space here, which is truthy, so the next page load treated it as an embed
     * code and printed it.
     */
    const NO_EMBED = '__quriobot_no_embed__';

    /**
     * How long to remember a successful response that carried no embed code.
     * Short, because unlike a 404 it may just mean the API is misbehaving.
     */
    const EMPTY_RESULT_EXPIRATION = 3600; // 1 hour

    /**
     * How long to wait before retrying after a timeout or a 5xx.
     *
     * Without this, an outage at the API turns every single page load into a
     * fresh blocking request, which saturates PHP workers on any busy site. The
     * widget still recovers within a minute of the API coming back.
     */
    const RETRY_INTERVAL = 60; // 1 minute

    /** Suffix for the companion transient that holds the retry guard. */
    const RETRY_SUFFIX = '.retry';

    /**
     * Whether an embed payload is complete enough to render.
     *
     * This is the single definition of "usable": callers must not apply their own,
     * or a partial payload gets cached as a success and then silently dropped at
     * render time, pinning the widget off for the full cache period.
     */
    private static function is_usable_embed($field, $value)
    {
        if ('embed_code_amp' === $field) {
            // Both halves are printed directly, so both must be strings — casting
            // here would turn a malformed payload into a fatal at render time.
            // An empty body is fine: head is what loads the widget.
            return is_object($value)
                && isset($value->head, $value->body)
                && is_string($value->head)
                && is_string($value->body)
                && '' !== trim($value->head);
        }

        return is_string($value) && '' !== trim($value);
    }

    /**
     * Returns the named embed code for a bot, going to the API only on a cache miss.
     *
     * @return mixed The embed code, or null when the bot has no usable one.
     */
    private static function get_embed_code($cache_key, $quriobot_path, $field, $cache_expiration)
    {
        $cached = get_transient($cache_key);
        if (self::NO_EMBED === $cached) {
            return null;
        }
        if (false !== $cached && self::is_usable_embed($field, $cached)) {
            return $cached;
        }

        // Back off after a failed fetch. Without this an API outage would make
        // every page load issue its own blocking request.
        $retry_key = $cache_key . self::RETRY_SUFFIX;
        if (get_transient($retry_key)) {
            return null;
        }

        // Armed BEFORE the request, not after: otherwise every request that
        // arrives while the first one is still blocked issues its own call, and
        // the outage still saturates the worker pool once per interval.
        set_transient($retry_key, 1, self::RETRY_INTERVAL);

        $res = self::fetch_bot($quriobot_path);
        if (isset($res['body']->frontend->$field)
            && self::is_usable_embed($field, $res['body']->frontend->$field)) {
            delete_transient($retry_key);
            set_transient($cache_key, $res['body']->frontend->$field, $cache_expiration);
            return $res['body']->frontend->$field;
        }

        if (self::is_permanent_failure($res['status'])) {
            // A definitive "no such bot" is worth remembering for the full period.
            delete_transient($retry_key);
            set_transient($cache_key, self::NO_EMBED, $cache_expiration);
        } else if ($res['status'] >= 200 && $res['status'] < 300) {
            delete_transient($retry_key);
            // The API answered successfully but carried no usable embed code for
            // this bot — a bot with no AMP variant, say. Without caching that, every
            // single page load would re-issue a blocking request. Cache it briefly
            // rather than for ten days, so a malformed response self-heals.
            set_transient($cache_key, self::NO_EMBED, self::EMPTY_RESULT_EXPIRATION);
        }
        // A timeout or a 5xx falls through: the embed code stays uncached so the
        // widget is not blanked, and the retry guard armed above is left in place
        // so the next attempt waits out the interval.

        return null;
    }

    public function enqueue_script()
    {
        $cache_expiration = 3600 * 24 * 10; // 10 days
        $paths = self::split_lines(get_option('quriobot_path'));
        if (!$paths) {
            return;
        }
        $quriobot_path = $paths[0];

        if (function_exists('amp_is_request') && amp_is_request()) {
            $embed_code_amp = self::get_embed_code(
                sprintf('quriobot.%s.bot.frontend.embed_code_amp.%s', static::VERSION, $quriobot_path),
                $quriobot_path,
                'embed_code_amp',
                $cache_expiration
            );
            if (null !== $embed_code_amp) {
                $quriobot_amp_body = function () use (&$embed_code_amp)
                {
                    // The AMP embed code is markup generated by the Quriobot API for
                    // this specific bot. Escaping it would destroy the tags it exists
                    // to emit, so it is printed as-is.
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    print($embed_code_amp->body);
                };
                $quriobot_amp_head = function() use (&$embed_code_amp)
                {
                    // See the note above: API-generated AMP markup, printed verbatim.
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    print($embed_code_amp->head);
                };
                add_action('wp_head', $quriobot_amp_head, 1000);
                add_action('amp_print_analytics', $quriobot_amp_body, 1000);
            }
        } else {
            $embed_code_2 = self::get_embed_code(
                sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', static::VERSION, $quriobot_path),
                $quriobot_path,
                'embed_code_2',
                $cache_expiration
            );
            if (null !== $embed_code_2) {
                $code = self::quriobot_script();
                $quriobot_head = function () use (&$embed_code_2, &$code) {
                    $html = sprintf('
    %s
    <script type="text/javascript">
    %s
    </script>', $embed_code_2, $code);

                    // Both halves are markup by design and cannot be escaped without
                    // breaking the widget. $embed_code_2 is the <script> snippet the
                    // Quriobot API generates for this bot. $code is either the
                    // generated qbOptions assignment (JSON-encoded with JSON_HEX_TAG,
                    // so no stored value can close the tag early) or the raw
                    // quriobot_init snippet, which only a user with `unfiltered_html`
                    // is allowed to save — see sanitize_init_code().
                    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
        wp_register_style('quriobot_custom_admin_style', plugins_url('../admin/static/quriobot-admin.css', __FILE__), array(), self::VERSION, 'all');
        wp_enqueue_style('quriobot_custom_admin_style');
    }
}
