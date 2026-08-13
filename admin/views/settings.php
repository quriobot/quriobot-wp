<?php

    // No direct access to this file
    defined( 'ABSPATH' ) or die();

?>

<?php
// Reading the flag WordPress itself appends after redirecting back from
// options.php. Nothing is processed from the request, so there is no nonce.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_GET['settings-updated'] ) ) : ?>

    <?php
    // Uses the same splitter as the widget, so a whitespace-only stored value is
    // reported as disabled here rather than as "installed for 0 bots".
    $quriobot_saved_paths = Quriobot::split_lines( get_option('quriobot_path') );
    ?>
    <?php if ( ! $quriobot_saved_paths ): ?>
        <div id="message" class="notice notice-warning is-dismissible">
            <p><strong><?php esc_html_e('Quriobot script is disabled.', 'quriobot'); ?></strong></p>
        </div>
    <?php else: ?>
        <div id="message" class="notice notice-success is-dismissible">
                <p><strong><?php
                    // Several paths render as a run-on when interpolated raw, so
                    // they are listed comma-separated.
                    echo esc_html( sprintf(
                        /* translators: %1$d: number of bots. %2$s: comma-separated list of bot paths. */
                        _n(
                            'Quriobot script installed for %1$d bot: %2$s',
                            'Quriobot script installed for %1$d bots: %2$s',
                            count( $quriobot_saved_paths ),
                            'quriobot'
                        ),
                        count( $quriobot_saved_paths ),
                        implode( ', ', $quriobot_saved_paths )
                    ) );
                ?></strong></p>
        </div>
    <?php endif; ?>

<?php endif; ?>


<div id="business-info-wrap" class="wrap">

    <div class="wp-header">
        <img src="<?php echo esc_url( plugins_url( '../static/quriobot_logo_2x.png', __FILE__ ) ); ?>" alt="Quriobot" class="quriobot-logo">
        <p class="quriobot-tagline">
            <?php esc_html_e('Increase conversion with an easy-to-use chatbot.', 'quriobot'); ?>
        </p>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( 'quriobot' );
        do_settings_sections('quriobot'); ?>

        <div id="quriobot-form-area">
            <p><?php
            $quriobot_control_room_url = 'https://control.quriobot.com';
            echo wp_kses(
                sprintf(
                    /* translators: %s: URL of the Quriobot Control Room. */
                    __( 'Visit <a href="%s" target="_blank">Quriobot Control Room</a> to find your unique bot path.', 'quriobot'),
                    esc_url( $quriobot_control_room_url )
                ),
                array( 'a' => array( 'href' => array(), 'target' => array() ) )
            );
            ?>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: URL of the help centre article explaining how to find a bot path. */
                        __( 'Not sure where to find it? <a target="_blank" href="%s">Read the walkthrough</a>.', 'quriobot'),
                        esc_url( 'https://academy.quriobot.com/publishing-your-bot-embedding-and-dedicated-pages/add-your-quriobot-to-your-wordpress-page/' )
                    ),
                    array( 'a' => array( 'href' => array(), 'target' => array() ) )
                );
                ?>
            </p>
            <p><?php esc_html_e('Enter your bot path below to install your bot on your WordPress website.', 'quriobot'); ?></p>

            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                        <label for="quriobot_path"><?php esc_html_e( 'Bot paths', 'quriobot'); ?></label>
                        </th>

                        <td>
                        <textarea name="quriobot_path" id="quriobot_path" rows="10" cols="100"><?php echo esc_textarea( get_option('quriobot_path') ); ?></textarea>
                        <p class="description" id="wp_quriobot_path_description">
                            <?php esc_html_e( '(Leave blank to disable)', 'quriobot' ); ?>
                            <br />
                            <?php esc_html_e( 'To put multiple bots on your website, add each bot path on a separate line.', 'quriobot' ); ?>
                        </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                        <label for="quriobot_init"><?php esc_html_e( 'Customize bot initialization', 'quriobot'); ?></label>
                        </th>

                        <td>
                        <textarea
                            name="quriobot_init" id="quriobot_init" rows="10" cols="100"
                            placeholder="window.qbOptions.push({
    use: 'asdfasdfasfasd/adfadfasdfasd',
    lang: document.getElementsByTagName('html')[0].getAttribute('lang').toLowerCase()
})"
                            ><?php echo esc_textarea( get_option('quriobot_init') ); ?></textarea>
                        <p class="description" id="wp_quriobot_init_description">
                            <?php esc_html_e( '(Leave blank to disable)', 'quriobot' ); ?>
                            <br />
                            <?php esc_html_e( 'To fully customize the options used to initialize the widget, provide the code that will be placed in a <script> tag before the widget <script> tag.', 'quriobot' ); ?>
                            <br />
                            <?php esc_html_e( 'This replaces the generated options, but it still needs at least one bot path above: the path is what loads the widget itself.', 'quriobot' ); ?>
                        </p>
                        </td>
                    </tr>
                </tbody>

            </table>
        </div>

        <?php submit_button(); ?>

    </form>
</div>
