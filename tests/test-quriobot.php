<?php
/**
 * Integration tests for the Quriobot plugin, run inside a real WordPress
 * install via `wp eval-file`. See tests/run.sh.
 */

$GLOBALS['qb_pass'] = 0;
$GLOBALS['qb_fail'] = array();
$GLOBALS['qb_amp'] = false;

// Stands in for the AMP plugin so the AMP branch of enqueue_script() can be
// exercised. The plugin only calls this when function_exists() says it is there.
if (!function_exists('amp_is_request')) {
    function amp_is_request()
    {
        return !empty($GLOBALS['qb_amp']);
    }
}

function qb_ok($name, $cond, $detail = '')
{
    if ($cond) {
        $GLOBALS['qb_pass']++;
        echo "  ok   $name\n";
        return true;
    }
    $GLOBALS['qb_fail'][] = $name . ($detail ? " -- $detail" : '');
    echo "  FAIL $name" . ($detail ? " -- $detail" : '') . "\n";
    return false;
}

function qb_eq($name, $expected, $actual)
{
    return qb_ok(
        $name,
        $expected === $actual,
        sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
    );
}

/** Calls a private/protected static method. */
function qb_call_private($method, array $args = array())
{
    $ref = new ReflectionMethod('Quriobot', $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

/**
 * Seconds remaining on a transient's lifetime, rounded to the nearest minute.
 * Used to tell the 10-day "no such bot" cache apart from the 1-hour
 * "responded, but with nothing usable" cache.
 */
function qb_transient_ttl($key)
{
    $expires = (int) get_option('_transient_timeout_' . $key);
    return $expires ? (int) round(($expires - time()) / 60) * 60 : 0;
}

/** Removes every cached embed code so the next init() re-fetches. */
function qb_flush_transients()
{
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%quriobot%'");
    wp_cache_flush();
}

/**
 * Runs Quriobot::init() with the HTTP layer mocked, and returns what the
 * plugin printed into wp_head plus any PHP diagnostics it raised.
 */
function qb_render($responder, $flush = true, $amp = false)
{
    if ($flush) {
        qb_flush_transients();
    }
    $GLOBALS['qb_amp'] = $amp;
    // `wp` too: each render instantiates the plugin afresh, and without clearing
    // it the handlers from previous renders would accumulate and re-fetch.
    remove_all_actions('wp');
    remove_all_actions('wp_head');
    remove_all_actions('amp_print_analytics');
    // Each render registers this too; without clearing it, dozens of stale
    // instances accumulate and the flush assertions would pass on one of those
    // rather than on the registration under test.
    remove_all_filters('pre_update_option_quriobot_path');

    $seen = array();
    $intercept = function ($preempt, $args, $url) use ($responder, &$seen) {
        $seen[] = array('url' => $url, 'args' => $args);
        return call_user_func($responder, $url, $args);
    };
    add_filter('pre_http_request', $intercept, 10, 3);

    $diagnostics = array();
    set_error_handler(function ($errno, $errstr) use (&$diagnostics) {
        $diagnostics[] = "[$errno] $errstr";
        return true;
    });

    $quriobot = new Quriobot();
    $quriobot->init();

    // The plugin defers its work to `wp` so the AMP plugin's request detection is
    // ready; nothing should have happened yet at this point.
    $before_wp = count($seen);

    do_action('wp');

    ob_start();
    do_action('wp_head');
    $head = ob_get_clean();

    ob_start();
    do_action('amp_print_analytics');
    $amp_body = ob_get_clean();

    restore_error_handler();
    remove_filter('pre_http_request', $intercept, 10);
    $GLOBALS['qb_amp'] = false;

    return array(
        'head' => $head,
        'amp_body' => $amp_body,
        'requests' => $seen,
        'requests_before_wp' => $before_wp,
        'diagnostics' => $diagnostics,
    );
}

/** A successful bot payload, shaped like the real API response. */
function qb_bot_response($body = '<!-- qb-embed -->')
{
    return array(
        'headers' => array(),
        'body' => wp_json_encode(array(
            'frontend' => array(
                'embed_code_2' => $body,
                'embed_code_amp' => array('head' => '<!-- amp-head -->', 'body' => '<!-- amp-body -->'),
            ),
        )),
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
}

function qb_status_response($code)
{
    return array(
        'headers' => array(),
        'body' => '',
        'response' => array('code' => $code, 'message' => ''),
        'cookies' => array(),
        'filename' => null,
    );
}

echo "\nWordPress " . get_bloginfo('version') . " / PHP " . PHP_VERSION . "\n";

// ---------------------------------------------------------------------------
echo "\n== plugin loads ==\n";

qb_ok('plugin is active', is_plugin_active('quriobot/quriobot.php'));
qb_ok('Quriobot class exists', class_exists('Quriobot'));
qb_ok('WP_Quriobot class exists', class_exists('WP_Quriobot'));
qb_eq('version constants agree', WP_Quriobot::VERSION, Quriobot::VERSION);

// Settings are registered on admin_init, which never fires under WP-CLI, so the
// suite registers them explicitly. First prove that init_admin() alone does not:
// the sanitize callbacks call wp-admin-only functions and must not be live on
// front-end, REST or cron requests.
remove_all_filters('sanitize_option_quriobot_init');
$bootstrap = new Quriobot();
$bootstrap->init_admin();
qb_eq(
    'init_admin() does not register sanitize callbacks outside the admin',
    false,
    has_filter('sanitize_option_quriobot_init')
);
// ...but it must still arrange for them to be registered once the admin loads,
// or every save through options.php fails with "Settings save failed."
qb_ok(
    'init_admin() hooks register_settings on admin_init',
    false !== has_action('admin_init', array($bootstrap, 'register_settings'))
);

$bootstrap->register_settings();
qb_ok(
    'register_settings() does register them',
    false !== has_filter('sanitize_option_quriobot_init')
);

// ---------------------------------------------------------------------------
echo "\n== HTTP goes through the WordPress HTTP API ==\n";
// The regression test for the Requests -> wp_remote_get migration: if the
// plugin bypassed the HTTP API, pre_http_request would never fire.

update_option('quriobot_path', 'abc123/def456');
update_option('quriobot_init', '');

$r = qb_render(function () { return qb_bot_response(); });

qb_eq('exactly one HTTP request', 1, count($r['requests']));
// Nothing may happen at plugins_loaded: calling amp_is_request() before the query
// is parsed makes the AMP plugin raise _doing_it_wrong() naming this plugin, and
// its answer would only be a guess.
qb_eq('no request is issued before the wp action', 0, $r['requests_before_wp']);
qb_ok(
    'enqueue_script is deferred to the wp action',
    false !== has_action('wp'),
    'the plugin must not fetch at plugins_loaded'
);
// The route is /frontend/bots/{organisation}/{bot}: the separator must stay a
// real slash, or the API answers with a redirect instead of the payload.
qb_eq(
    'request hits the bot frontend endpoint',
    'https://api.botsrv2.com/0.0.1/frontend/bots/abc123/def456',
    $r['requests'][0]['url']
);
qb_eq(
    'X-For-Embed-Code header is sent',
    'true',
    $r['requests'][0]['args']['headers']['X-For-Embed-Code']
);
qb_ok(
    'TLS verification is not disabled',
    !isset($r['requests'][0]['args']['sslverify']) || true === $r['requests'][0]['args']['sslverify'],
    var_export($r['requests'][0]['args']['sslverify'] ?? null, true)
);
qb_eq('no PHP diagnostics raised', array(), $r['diagnostics']);
qb_ok('embed code reached wp_head', false !== strpos($r['head'], '<!-- qb-embed -->'));
qb_ok('qbOptions script reached wp_head', false !== strpos($r['head'], 'window.qbOptions'));

// ---------------------------------------------------------------------------
echo "\n== no deprecated Requests usage ==\n";

$sources = array(
    file_get_contents(WP_PLUGIN_DIR . '/quriobot/quriobot.php'),
    file_get_contents(WP_PLUGIN_DIR . '/quriobot/includes/class-quriobot.php'),
);
qb_ok(
    'source never references the deprecated Requests class',
    0 === preg_match('/(^|[^\w\\\\])Requests\s*::/m', implode("\n", $sources))
);

// ---------------------------------------------------------------------------
echo "\n== response caching ==\n";

$r = qb_render(function () { return qb_bot_response(); });
qb_eq('first render fetches', 1, count($r['requests']));

// A second render with the transient warm must serve from cache. The responder
// returns a DIFFERENT payload, so a silent re-fetch is visible in the output
// rather than looking identical to a cache hit.
$warm = qb_render(function () { return qb_bot_response('<!-- REFETCHED -->'); }, false);
qb_eq('warm cache makes no request', 0, count($warm['requests']));
qb_ok(
    'warm cache did not silently re-fetch',
    false === strpos($warm['head'], '<!-- REFETCHED -->')
);
qb_eq('warm cache raises no diagnostics', array(), $warm['diagnostics']);
qb_ok(
    'warm cache still renders the embed code',
    false !== strpos($warm['head'], '<!-- qb-embed -->'),
    'the successful embed code was not served from the transient'
);
qb_ok(
    'a successful embed code is cached verbatim',
    '<!-- qb-embed -->' === get_transient(
        sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', Quriobot::VERSION, 'abc123/def456')
    )
);

foreach (array(404, 530, 531, 532) as $code) {
    $r = qb_render(function () use ($code) { return qb_status_response($code); });
    $key = sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', Quriobot::VERSION, 'abc123/def456');
    qb_eq("status $code caches a negative result", Quriobot::NO_EMBED, get_transient($key));
    // A definitive "no such bot" is remembered for the full period.
    qb_eq("status $code is cached for the full period", 3600 * 24 * 10, qb_transient_ttl($key));
    qb_ok("status $code prints nothing", '' === trim($r['head']));

    // The load *after* the negative result is cached is the one that matters:
    // a truthy sentinel would be mistaken for an embed code and printed.
    $warm = qb_render(function () { return qb_bot_response(); }, false);
    qb_eq("status $code negative cache makes no request", 0, count($warm['requests']));
    qb_eq("status $code negative cache prints nothing", '', trim($warm['head']));
    qb_eq("status $code negative cache raises no diagnostics", array(), $warm['diagnostics']);
}

// A transient failure must NOT be cached, so the next request retries.
$r = qb_render(function () { return qb_status_response(503); });
qb_eq(
    '503 is not cached',
    false,
    get_transient(sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', Quriobot::VERSION, 'abc123/def456'))
);

$r = qb_render(function () { return new WP_Error('http_request_failed', 'boom'); });
qb_eq('transport error raises no diagnostics', array(), $r['diagnostics']);
qb_eq('transport error prints nothing', '', trim($r['head']));

// An outage must not turn every page load into its own blocking request.
$retry_key = sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', Quriobot::VERSION, 'abc123/def456')
    . Quriobot::RETRY_SUFFIX;
qb_ok('a transport error arms the retry guard', false !== get_transient($retry_key));
qb_eq('the retry guard is short-lived', Quriobot::RETRY_INTERVAL, qb_transient_ttl($retry_key));

$warm = qb_render(function () { return qb_bot_response(); }, false);
qb_eq('a second load during an outage makes no request', 0, count($warm['requests']));
qb_eq('a second load during an outage prints nothing', '', trim($warm['head']));

// Once the guard lapses the widget recovers on the next load.
delete_transient($retry_key);
$recovered = qb_render(function () { return qb_bot_response(); }, false);
qb_eq('the next load after the guard lapses retries', 1, count($recovered['requests']));
qb_ok('the widget recovers', false !== strpos($recovered['head'], '<!-- qb-embed -->'));

$r = qb_render(function () { return qb_status_response(503); });
qb_ok('a 5xx also arms the retry guard', false !== get_transient($retry_key));
$warm = qb_render(function () { return qb_bot_response(); }, false);
qb_eq('a second load after a 5xx makes no request', 0, count($warm['requests']));

// A 200 whose body is not the expected shape must not warn or be cached.
foreach (array('null', '[]', '"a string"', '{"frontend":{}}', 'not json at all') as $body) {
    $r = qb_render(function () use ($body) {
        return array(
            'headers' => array(),
            'body' => $body,
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    });
    qb_eq("malformed body ($body) raises no diagnostics", array(), $r['diagnostics']);
    qb_eq("malformed body ($body) prints nothing", '', trim($r['head']));
}

// Per-segment URL encoding: the slash separator survives, other characters do not.
update_option('quriobot_path', 'org one/bot+two');
$r = qb_render(function () { return qb_bot_response(); });
qb_eq(
    'each path segment is encoded independently',
    'https://api.botsrv2.com/0.0.1/frontend/bots/org%20one/bot%2Btwo',
    $r['requests'][0]['url']
);
update_option('quriobot_path', 'abc123/def456');

// A 2xx that carries no usable embed code must be cached too, or every page
// load re-issues a blocking request forever.
$r = qb_render(function () {
    return array(
        'headers' => array(),
        'body' => wp_json_encode(array('frontend' => array('embed_code_2' => ''))),
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
});
qb_eq('empty embed code prints nothing', '', trim($r['head']));
$empty_key = sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', Quriobot::VERSION, 'abc123/def456');
qb_eq(
    'an empty embed code is cached as "no embed", not as a success',
    Quriobot::NO_EMBED,
    get_transient($empty_key)
);
// Unlike a 404 this may just be the API misbehaving, so it must expire quickly.
qb_eq(
    'a successful-but-empty response is cached only briefly',
    Quriobot::EMPTY_RESULT_EXPIRATION,
    qb_transient_ttl($empty_key)
);
$warm = qb_render(function () { return qb_bot_response(); }, false);
qb_eq('empty embed code is cached, so no second request', 0, count($warm['requests']));
qb_eq('empty embed code raises no diagnostics', array(), $warm['diagnostics']);

// Whitespace-only is the same class of unusable payload.
$r = qb_render(function () { return qb_bot_response("   \n  "); });
qb_eq('whitespace-only embed code prints nothing', '', trim($r['head']));
qb_eq(
    'a whitespace-only embed code is not cached as a success',
    Quriobot::NO_EMBED,
    get_transient(sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', Quriobot::VERSION, 'abc123/def456'))
);

// ---------------------------------------------------------------------------
echo "\n== AMP branch ==\n";

update_option('quriobot_path', 'abc123/def456');

$r = qb_render(function () { return qb_bot_response(); }, true, true);
qb_eq('AMP request is made', 1, count($r['requests']));
qb_ok('AMP head markup is printed', false !== strpos($r['head'], '<!-- amp-head -->'));
qb_ok('AMP body markup is printed', false !== strpos($r['amp_body'], '<!-- amp-body -->'));
qb_eq('AMP render raises no diagnostics', array(), $r['diagnostics']);
qb_ok('AMP render omits the non-AMP qbOptions script', false === strpos($r['head'], 'window.qbOptions'));

// The sentinel bug existed in this branch too, where a truthy cached value would
// be dereferenced as an object.
$r = qb_render(function () { return qb_status_response(404); }, true, true);
qb_eq('AMP 404 prints nothing', '', trim($r['head']));
$warm = qb_render(function () { return qb_bot_response(); }, false, true);
qb_eq('AMP negative cache makes no request', 0, count($warm['requests']));
qb_eq('AMP negative cache prints nothing', '', trim($warm['head']));
qb_eq('AMP negative cache prints no body', '', trim($warm['amp_body']));
qb_eq('AMP negative cache raises no diagnostics', array(), $warm['diagnostics']);

// An empty body is legitimate — head is what loads the widget — so it must
// still render rather than being treated as an unusable payload.
$r = qb_render(function () {
    return array(
        'headers' => array(),
        'body' => wp_json_encode(array('frontend' => array(
            'embed_code_amp' => array('head' => '<!-- amp-head -->', 'body' => ''),
        ))),
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
}, true, true);
qb_ok('an AMP payload with an empty body still renders the head', false !== strpos($r['head'], '<!-- amp-head -->'));
qb_eq('an AMP payload with an empty body prints no body', '', trim($r['amp_body']));
qb_eq('an AMP payload with an empty body raises no diagnostics', array(), $r['diagnostics']);

// A non-string half would fatal at print time, so it must be rejected outright.
$r = qb_render(function () {
    return array(
        'headers' => array(),
        'body' => '{"frontend":{"embed_code_amp":{"head":"<!-- h -->","body":{"a":1}}}}',
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
}, true, true);
qb_eq('a non-string AMP body raises no diagnostics', array(), $r['diagnostics']);
qb_eq('a non-string AMP body prints nothing', '', trim($r['head']));

// A payload whose amp object is missing head/body must not be dereferenced.
$r = qb_render(function () {
    return array(
        'headers' => array(),
        'body' => wp_json_encode(array('frontend' => array('embed_code_amp' => array('head' => '<!-- h -->')))),
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
}, true, true);
qb_eq('AMP payload missing body raises no diagnostics', array(), $r['diagnostics']);
qb_eq('AMP payload missing body prints nothing', '', trim($r['head']));
// A partial payload must NOT be cached as a success, or it pins the widget off
// for the full ten days while never rendering.
qb_eq(
    'a partial AMP payload is cached as "no embed", not as a success',
    Quriobot::NO_EMBED,
    get_transient(sprintf('quriobot.%s.bot.frontend.embed_code_amp.%s', Quriobot::VERSION, 'abc123/def456'))
);

// ---------------------------------------------------------------------------
echo "\n== line handling ==\n";

qb_eq(
    'CRLF input splits without stray carriage returns',
    array('one/a', 'two/b'),
    qb_call_private('split_lines', array("one/a\r\ntwo/b\r\n"))
);
qb_eq(
    'blank lines are dropped',
    array('one/a', 'two/b'),
    qb_call_private('split_lines', array("one/a\n\n  \ntwo/b"))
);
qb_eq('empty input yields no paths', array(), qb_call_private('split_lines', array('')));
qb_eq('false option yields no paths', array(), qb_call_private('split_lines', array(false)));

// ---------------------------------------------------------------------------
echo "\n== settings sanitization ==\n";

$plugin = new Quriobot();
wp_set_current_user(1); // an administrator, who holds unfiltered_html on single site

qb_eq(
    'bot paths are normalized to newline-separated lines',
    "one/a\ntwo/b",
    $plugin->sanitize_bot_paths("  one/a \r\n\r\n two/b  ")
);
qb_eq(
    'markup is stripped from bot paths',
    'evil/path',
    $plugin->sanitize_bot_paths('<script>x</script>evil/path')
);
qb_eq(
    'init snippet is preserved verbatim',
    "window.qbOptions.push({ use: 'a/b' })",
    $plugin->sanitize_init_code("  window.qbOptions.push({ use: 'a/b' })  ")
);

// Saving raw JavaScript requires unfiltered_html. On multisite that capability
// is withheld from site administrators who still hold manage_options, so the
// settings-page capability alone is not sufficient authorization.
update_option('quriobot_init', 'window.previous();');
$deny_unfiltered_html = function ($caps, $cap) {
    return 'unfiltered_html' === $cap ? array('do_not_allow') : $caps;
};
add_filter('map_meta_cap', $deny_unfiltered_html, 10, 2);
qb_ok('unfiltered_html is denied for this check', !current_user_can('unfiltered_html'));

global $wp_settings_errors;
$wp_settings_errors = array();
qb_eq(
    'init snippet is rejected without unfiltered_html',
    'window.previous();',
    $plugin->sanitize_init_code('window.injected();')
);
// The refusal has to be visible; silently discarding the edit would look like a
// successful save.
qb_ok(
    'the refusal surfaces as a settings error',
    array() !== get_settings_errors('quriobot_init'),
    'no settings error was registered for the refused save'
);
// Re-submitting the unchanged value must still succeed: the settings form posts
// both fields on every save, so denying it would block editing the bot path and
// would suppress the "Settings saved." notice.
qb_eq(
    'resubmitting the unchanged snippet is allowed',
    'window.previous();',
    $plugin->sanitize_init_code('window.previous();')
);
// Clearing must always be possible, or a bad snippet could never be removed.
qb_eq(
    'clearing the snippet is allowed without unfiltered_html',
    '',
    $plugin->sanitize_init_code('')
);
// The browser posts textareas back with CRLF; a value stored with bare LF must
// still count as unchanged, or the settings page can never be saved again.
remove_filter('map_meta_cap', $deny_unfiltered_html, 10);
update_option('quriobot_init', "a();\nb();");
add_filter('map_meta_cap', $deny_unfiltered_html, 10, 2);
$wp_settings_errors = array();
qb_eq(
    'CRLF resubmission of an LF-stored snippet counts as unchanged',
    "a();\r\nb();",
    $plugin->sanitize_init_code("a();\r\nb();")
);
qb_eq(
    'an unchanged resubmission raises no settings error',
    array(),
    get_settings_errors('quriobot_init')
);
remove_filter('map_meta_cap', $deny_unfiltered_html, 10);

qb_ok('unfiltered_html is restored', current_user_can('unfiltered_html'));
qb_eq(
    'init snippet is accepted with unfiltered_html',
    'window.allowed();',
    $plugin->sanitize_init_code('window.allowed();')
);
update_option('quriobot_init', '');
wp_set_current_user(0);

$registered = get_registered_settings();
qb_eq('quriobot_path is typed as string', 'string', $registered['quriobot_path']['type']);
qb_eq('quriobot_init is typed as string', 'string', $registered['quriobot_init']['type']);
qb_ok('quriobot_path has a sanitize callback', !empty($registered['quriobot_path']['sanitize_callback']));
qb_ok('quriobot_init has a sanitize callback', !empty($registered['quriobot_init']['sanitize_callback']));

// ---------------------------------------------------------------------------
echo "\n== inline script payload ==\n";

update_option('quriobot_path', "abc/one\r\nabc/two\r\nabc/one");
update_option('quriobot_init', '');

$script = Quriobot::quriobot_script();
$json = json_decode(substr($script, strlen('window.qbOptions = '), -1), true);

qb_eq('duplicate paths are collapsed', 2, count($json));
qb_eq('paths are carriage-return free', 'abc/one', $json[0]['use']);
qb_eq('second path survives', 'abc/two', $json[1]['use']);
qb_ok('language is included', isset($json[0]['language']));
qb_ok('array is JSON-encoded as a list, not an object', '[' === substr($script, strlen('window.qbOptions = '), 1));

// A hostile path is stripped at the storage boundary by the sanitize callback.
update_option('quriobot_path', 'abc/</script><img src=x>');
qb_eq('markup in a saved path is sanitized away', 'abc/', get_option('quriobot_path'));

// Defence in depth: installs upgrading from <= 2.9.1 already hold unsanitized
// values, which never pass through the callback again until the user re-saves.
// Write one straight to the database to prove the encoder still contains it.
$legacy = 'abc/</script><img src=x>';
remove_all_filters('sanitize_option_quriobot_path');
update_option('quriobot_path', $legacy);
qb_eq('legacy raw value is stored unsanitized', $legacy, get_option('quriobot_path'));

$script = Quriobot::quriobot_script();
qb_ok(
    'closing script tag is escaped in the payload',
    false === stripos($script, '</script>'),
    $script
);
qb_eq(
    'escaped payload still decodes to the original value',
    $legacy,
    json_decode(substr($script, strlen('window.qbOptions = '), -1), true)[0]['use']
);

// Restore the sanitizer for the remaining tests.
$plugin->register_settings();

// ---------------------------------------------------------------------------
echo "\n== custom init snippet ==\n";

update_option('quriobot_path', 'abc/one');

// Writing the init snippet needs unfiltered_html, so save it as an administrator
// and then drop back to a logged-out visitor to read it.
wp_set_current_user(1);
update_option('quriobot_init', "window.qbOptions = [{use: 'custom/path'}];");
wp_set_current_user(0);
qb_eq(
    'the option really was stored',
    "window.qbOptions = [{use: 'custom/path'}];",
    get_option('quriobot_init')
);

$script = Quriobot::quriobot_script();
qb_eq('custom snippet is emitted verbatim', "window.qbOptions = [{use: 'custom/path'}];", $script);

update_option('quriobot_path', '');
wp_set_current_user(1);
update_option('quriobot_init', '');
wp_set_current_user(0);
qb_eq('no config emits nothing', null, Quriobot::quriobot_script());

update_option('quriobot_path', 'abc/one');
$r = qb_render(function () { return qb_bot_response(); });
qb_eq('a configured bot path triggers exactly one request', 1, count($r['requests']));

update_option('quriobot_path', '');
$r = qb_render(function () { return qb_bot_response(); });
qb_eq('unconfigured plugin makes no HTTP request', 0, count($r['requests']));
qb_eq('unconfigured plugin prints nothing', '', trim($r['head']));

// ---------------------------------------------------------------------------
echo "\n== logged-in visitor payload ==\n";

update_option('quriobot_path', 'abc/one');
update_option('quriobot_init', '');

// DONOTCACHEPAGE is a process-wide constant and can never be undefined once set,
// so this assertion only has teeth while this is the FIRST logged-in render in
// the suite. Keep this section ahead of any other test that logs a user in.
qb_ok(
    'no logged-in render has happened yet',
    !defined('DONOTCACHEPAGE'),
    'an earlier test already defined it, so the assertion below proves nothing'
);

wp_set_current_user(1);

$script = Quriobot::quriobot_script();
$json = json_decode(substr($script, strlen('window.qbOptions = '), -1), true);
qb_ok('visitor details are attached for a logged-in user', isset($json[0]['visitor']['email']));
qb_ok(
    'the response is excluded from full-page caching',
    defined('DONOTCACHEPAGE') && DONOTCACHEPAGE,
    'visitor PII would otherwise be cached and served to the next visitor'
);

wp_set_current_user(0);
$json = json_decode(substr(Quriobot::quriobot_script(), strlen('window.qbOptions = '), -1), true);
qb_ok('no visitor details for a logged-out visitor', !isset($json[0]['visitor']));

// ---------------------------------------------------------------------------
echo "\n== saving settings clears the cache ==\n";

update_option('quriobot_path', 'abc123/def456');
$cache_key = sprintf('quriobot.%s.bot.frontend.embed_code_2.%s', Quriobot::VERSION, 'abc123/def456');

// Warm the cache, then re-save the SAME value. update_option() short-circuits on
// an identical value, so anything hooked to update_option_* would never run —
// yet re-saving is exactly how someone picks up a bot edited in the Control Room.
qb_render(function () { return qb_bot_response(); });
qb_eq('cache is warm before saving', '<!-- qb-embed -->', get_transient($cache_key));

update_option('quriobot_path', 'abc123/def456');
qb_eq(
    'an unchanged re-save still clears the cached embed code',
    false,
    get_transient($cache_key)
);

// Changing the path must clear the OLD path's entry too, not just start using a
// new key, or the previous bot's embed code lingers for the full period.
qb_render(function () { return qb_bot_response(); });
qb_eq('cache is warm again', '<!-- qb-embed -->', get_transient($cache_key));
update_option('quriobot_path', 'other/bot');
qb_eq('switching bots clears the previous path', false, get_transient($cache_key));

// The retry guard must go too, or an outage during the old config keeps
// suppressing fetches after the path changes.
update_option('quriobot_path', 'abc123/def456');
qb_render(function () { return new WP_Error('http_request_failed', 'boom'); });
qb_ok('retry guard armed', false !== get_transient($cache_key . Quriobot::RETRY_SUFFIX));
update_option('quriobot_path', 'abc123/def456');
qb_eq(
    'saving clears the retry guard as well',
    false,
    get_transient($cache_key . Quriobot::RETRY_SUFFIX)
);

// The AMP payload is cached under its own key; flushing only the non-AMP one
// would leave AMP sites stale with nothing to show for it.
$amp_key = Quriobot::cache_key('embed_code_amp', 'abc123/def456');
qb_render(function () { return qb_bot_response(); }, true, true);
qb_ok('AMP cache is warm', false !== get_transient($amp_key));
update_option('quriobot_path', 'abc123/def456');
qb_eq('saving clears the AMP cache too', false, get_transient($amp_key));

// Every configured path must be cleared, not just the first one.
update_option('quriobot_path', "first/bot\nsecond/bot");
set_transient(Quriobot::cache_key('embed_code_2', 'first/bot'), '<!-- a -->', 600);
set_transient(Quriobot::cache_key('embed_code_2', 'second/bot'), '<!-- b -->', 600);
update_option('quriobot_path', "first/bot\nsecond/bot");
qb_eq('the first path is cleared', false, get_transient(Quriobot::cache_key('embed_code_2', 'first/bot')));
qb_eq('the second path is cleared', false, get_transient(Quriobot::cache_key('embed_code_2', 'second/bot')));

// ---------------------------------------------------------------------------
echo "\n== shipped copy ==\n";

// Scans every shipped file, discovered rather than listed, so a new file is
// covered automatically instead of quietly escaping these checks.
$shipped_files = array();
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_PLUGIN_DIR . '/quriobot'));
foreach ($dir as $entry) {
    if ($entry->isFile() && preg_match('/\.(php|txt|css|js)$/', $entry->getFilename())) {
        $shipped_files[] = $entry->getPathname();
    }
}
qb_ok('found the shipped files to scan', count($shipped_files) >= 5, count($shipped_files) . ' found');

$shipped = '';
foreach ($shipped_files as $file) {
    $shipped .= file_get_contents($file) . "\n";
}

qb_ok(
    'no links to the retired freshdesk help desk',
    false === stripos($shipped, 'freshdesk'),
    'those articles return 404 for everyone'
);
qb_ok('no zero-width characters', 0 === preg_match('/[\x{200B}-\x{200D}\x{FEFF}]/u', $shipped));

// Any plain-http URL, not just a hand-listed set of our hostnames. The GPL
// licence URI is the one legitimate exception.
preg_match_all('#http://[^\s"\'<>)]+#', $shipped, $http_links);
qb_eq('no plain-http links', array(), array_values(array_unique($http_links[0])));

$readme = file_get_contents(WP_PLUGIN_DIR . '/quriobot/readme.txt');
qb_ok('readme has an Upgrade Notice section', false !== strpos($readme, '== Upgrade Notice =='));
qb_ok(
    'the current version has an Upgrade Notice entry',
    false !== strpos(substr($readme, strpos($readme, '== Upgrade Notice ==')), '= ' . Quriobot::VERSION . ' =')
);

// ---------------------------------------------------------------------------
echo "\n== uninstall cleanup ==\n";

update_option('quriobot_path', 'abc123/def456');
update_option('quriobot_version', Quriobot::VERSION);
wp_set_current_user(1);
update_option('quriobot_init', 'window.leftover();');
wp_set_current_user(0);
qb_render(function () { return qb_bot_response(); });

// On a network, seed every other site BEFORE the require so that requiring the file
// exercises the is_multisite() dispatch itself. Seeding afterwards would leave
// the dispatch untested — the file would only ever find the current site dirty,
// and deleting the whole `if ( is_multisite() )` branch would still pass.
// The current blog is deliberately skipped: writing quriobot_path here goes
// through update_option, which fires the plugin's own flush_cache_on_save() and
// would delete the cached embed code the assertions below are about to check.
// Test setup that writes through the production path runs the production hooks.
$network_site_ids = array();
if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $id) {
        if (get_current_blog_id() !== (int) $id) {
            $network_site_ids[] = (int) $id;
        }
    }
}

foreach ($network_site_ids as $network_site_id) {
    switch_to_blog($network_site_id);
    update_option('quriobot_path', 'net/seed' . $network_site_id);
    set_transient(Quriobot::cache_key('embed_code_2', 'net/seed' . $network_site_id), '<!-- seed -->', 600);
    restore_current_blog();
}

// Prove the seeding landed: if it silently no-opped, the $dirty check after the
// require would pass with nothing to find.
if ($network_site_ids) {
    $seeded = 0;
    foreach ($network_site_ids as $network_site_id) {
        switch_to_blog($network_site_id);
        if (get_option('quriobot_path')) {
            $seeded++;
        }
        restore_current_blog();
    }
    qb_eq('every other site is seeded before the uninstall', count($network_site_ids), $seeded);
}

// Asserted here rather than before the seeding above: the seed loop writes
// through update_option and so runs the plugin's own hooks. If it ever clears
// this transient, every "uninstall removed it" assertion below would pass
// against state that was already gone.
qb_ok(
    'there is still state to clean up when the uninstall runs',
    false !== get_transient($cache_key),
    'the seeding above destroyed the precondition'
);

define('WP_UNINSTALL_PLUGIN', 'quriobot/quriobot.php');
require WP_PLUGIN_DIR . '/quriobot/uninstall.php';

if ($network_site_ids) {
    $dirty = array();
    foreach ($network_site_ids as $network_site_id) {
        switch_to_blog($network_site_id);
        if (get_option('quriobot_path')
            || false !== get_transient(Quriobot::cache_key('embed_code_2', 'net/seed' . $network_site_id))) {
            $dirty[] = $network_site_id;
        }
        restore_current_blog();
    }
    qb_eq('requiring uninstall.php on a network cleans every site', array(), $dirty);
}

global $wpdb;

// Asserted against the options table rather than get_option(): register_setting()
// declares a '' default, so get_option() keeps returning '' for a row that no
// longer exists, which would make these pass even if nothing was deleted.
foreach (array('quriobot_path', 'quriobot_init', 'quriobot_version') as $option) {
    qb_eq(
        "uninstall removes the $option row",
        null,
        $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option
        ))
    );
}

qb_eq('uninstall removes the cached embed code', false, get_transient($cache_key));

qb_eq(
    'no quriobot rows survive in the options table',
    '0',
    (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%quriobot%'"
    )
);

// Managed hosts almost always run Redis or Memcached. With an external object
// cache, transients never touch the options table at all — so the options sweep
// above finds nothing and deleting the reconstructed keys is the only thing that
// actually removes them. Without this case the blind spot is invisible: the
// suite's default install has no object cache, so the sweep always succeeds.
wp_using_ext_object_cache(true);

update_option('quriobot_path', 'cached/bot');
$oc_key = Quriobot::cache_key('embed_code_2', 'cached/bot');
set_transient($oc_key, '<!-- object cached -->', 600);

qb_ok('the transient is readable under an object cache', false !== get_transient($oc_key));
qb_eq(
    'and it never reached the options table',
    '0',
    (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_quriobot%'"
    )
);

// The genuinely dangerous arrangement: rows in the options table left from
// before the object cache was switched on (or by an older plugin version, whose
// key embeds a different version number and so cannot be reconstructed) AND an
// active object cache. delete_transient() short-circuits to the cache here and
// would leave every one of these rows behind.
$legacy = '_transient_quriobot.2.9.2.bot.frontend.embed_code_2.old/bot';
add_option($legacy, '<!-- legacy -->');
add_option('_transient_timeout_quriobot.2.9.2.bot.frontend.embed_code_2.old/bot', time() + 600);

quriobot_uninstall_site();

qb_eq(
    'uninstall clears transients under an external object cache',
    false,
    get_transient($oc_key)
);
qb_eq(
    'uninstall removes legacy transient rows even with an object cache active',
    null,
    $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $legacy
    ))
);
qb_eq(
    'and their companion timeout rows',
    '0',
    (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_quriobot%'"
    )
);

wp_using_ext_object_cache(false);

// ---------------------------------------------------------------------------
echo "\n== multisite ==\n";

if (!is_multisite()) {
    echo "  (single-site install — run with MULTISITE=1 for this section)\n";
} else {
    // Exactly four, because the paging assertion below depends on the network
    // size being an exact multiple of the batch size. At three sites batch 2
    // yields 2 then 1, never issues the empty final query, and the boundary
    // being tested silently stops existing.
    $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
    qb_eq('the network has four sites', 4, count($site_ids));

    // Seed every site, including ones the uninstall has to switch into.
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        update_option('quriobot_path', 'net/bot' . $site_id);
        update_option('quriobot_version', Quriobot::VERSION);
        set_transient(Quriobot::cache_key('embed_code_2', 'net/bot' . $site_id), '<!-- per site -->', 600);
        restore_current_blog();
    }

    $seeded = 0;
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        if (get_option('quriobot_path')) {
            $seeded++;
        }
        restore_current_blog();
    }
    qb_eq('every site has state to clean up', count($site_ids), $seeded);

    // Batch size 2 against a 4-site network exercises paging AND the
    // exact-multiple boundary: the loop must issue a third query, get an empty
    // batch and stop. Counting the queries pins both properties and fails fast —
    // asserting "it terminated" can only ever fail by hanging the runner.
    $site_queries = 0;
    $count_queries = function () use (&$site_queries) {
        $site_queries++;
    };
    add_action('pre_get_sites', $count_queries);
    quriobot_uninstall_network(2);
    remove_action('pre_get_sites', $count_queries);

    qb_eq('paging issues 2 full batches then an empty one', 3, $site_queries);

    $left = array();
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        if (get_option('quriobot_path') || get_option('quriobot_version')
            || false !== get_transient(Quriobot::cache_key('embed_code_2', 'net/bot' . $site_id))) {
            $left[] = $site_id;
        }
        restore_current_blog();
    }
    qb_eq('the network uninstall visits every site', array(), $left);

    // The capability that behaves differently here: on multisite `manage_options`
    // is held by every site administrator, while `unfiltered_html` is reserved
    // for super admins. That gap is the whole reason sanitize_init_code() gates
    // on unfiltered_html rather than on reaching the settings page.
    $site_admin_id = wp_create_user('qb_site_admin', 'pw-' . wp_generate_password(12, false), 'qb_site_admin@example.com');
    if (!is_wp_error($site_admin_id)) {
        add_user_to_blog(get_current_blog_id(), $site_admin_id, 'administrator');
        wp_set_current_user($site_admin_id);

        qb_ok('a site administrator can reach the settings page', current_user_can('manage_options'));
        qb_ok('but does not hold unfiltered_html on multisite', !current_user_can('unfiltered_html'));

        remove_all_filters('sanitize_option_quriobot_init');
        $plugin->register_settings();
        qb_eq('the snippet starts empty', '', (string) get_option('quriobot_init'));
        update_option('quriobot_init', 'window.injected();');
        qb_eq(
            'so a site administrator cannot inject raw JavaScript',
            '',
            (string) get_option('quriobot_init')
        );

        wp_set_current_user(1);
        qb_ok('a super admin does hold unfiltered_html', current_user_can('unfiltered_html'));
        update_option('quriobot_init', 'window.allowed();');
        qb_eq(
            'and can set it',
            'window.allowed();',
            (string) get_option('quriobot_init')
        );
        update_option('quriobot_init', '');
        wp_set_current_user(0);
    } else {
        qb_ok('could not create a site administrator', false, $site_admin_id->get_error_message());
    }
}

// ---------------------------------------------------------------------------
echo "\n== admin surface ==\n";

require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user(1);
set_current_screen('plugins');

$plugin->create_nav_page();
$submenu_slugs = array();
if (isset($GLOBALS['submenu']['options-general.php'])) {
    foreach ($GLOBALS['submenu']['options-general.php'] as $item) {
        $submenu_slugs[] = $item[2];
    }
}
qb_ok(
    'settings page is registered under Settings as quriobot_settings',
    in_array('quriobot_settings', $submenu_slugs, true),
    implode(',', $submenu_slugs)
);

$links = WP_Quriobot::get_instance()->plugin_action_links(array());
qb_eq('two action links are added', 2, count($links));
qb_ok(
    'Settings link points at the page that is actually registered',
    false !== strpos($links[0], 'options-general.php?page=quriobot_settings'),
    $links[0]
);

$hooked = has_filter('plugin_action_links_quriobot/quriobot.php');
qb_ok('plugin_action_links filter is hooked', false !== $hooked);

// The save confirmation only renders after a redirect back from options.php, so
// the flag has to be set for it to be exercised at all.
$_GET['settings-updated'] = 'true';

update_option('quriobot_path', 'solo/bot');
ob_start();
require WP_PLUGIN_DIR . '/quriobot/admin/views/settings.php';
$view = ob_get_clean();
qb_ok('one bot uses the singular form', false !== strpos($view, 'installed for 1 bot:'), $view ? '' : 'no output');
qb_ok('the singular form is not the plural one', false === strpos($view, '1 bots'));
qb_ok('the path is listed', false !== strpos($view, 'solo/bot'));

update_option('quriobot_path', "first/bot\nsecond/bot");
ob_start();
require WP_PLUGIN_DIR . '/quriobot/admin/views/settings.php';
$view = ob_get_clean();
qb_ok('two bots use the plural form', false !== strpos($view, 'installed for 2 bots:'));
qb_ok('both paths are listed comma-separated', false !== strpos($view, 'first/bot, second/bot'));

// A whitespace-only value installs nothing, so it must read as disabled rather
// than as "installed for 0 bots".
remove_all_filters('sanitize_option_quriobot_path');
update_option('quriobot_path', "  \n  ");
$plugin->register_settings();
ob_start();
require WP_PLUGIN_DIR . '/quriobot/admin/views/settings.php';
$view = ob_get_clean();
qb_ok('a whitespace-only value reads as disabled', false !== strpos($view, 'Quriobot script is disabled'));
qb_ok('and does not claim 0 bots', false === strpos($view, 'installed for 0 bots'));

unset($_GET['settings-updated']);
update_option('quriobot_path', '');

// The settings view must round-trip quotes without mangling them.
update_option('quriobot_init', "a = \"x\"; b = 'y';");
ob_start();
require WP_PLUGIN_DIR . '/quriobot/admin/views/settings.php';
$view = ob_get_clean();
qb_ok(
    'settings view renders the init snippet with quotes intact',
    false !== strpos($view, 'a = &quot;x&quot;; b = &#039;y&#039;;'),
    'textarea content was not esc_textarea-encoded'
);
qb_ok('settings view has no double-encoded entities', false === strpos($view, '&amp;quot;'));

update_option('quriobot_init', '');
update_option('quriobot_path', '');

// ---------------------------------------------------------------------------
echo "\n";
$fails = $GLOBALS['qb_fail'];
if ($fails) {
    echo sprintf("FAILED: %d passed, %d failed\n", $GLOBALS['qb_pass'], count($fails));
    foreach ($fails as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo sprintf("PASSED: %d assertions\n", $GLOBALS['qb_pass']);
exit(0);
