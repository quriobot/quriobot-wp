<?php
/**
 * Plugin Name: Quriobot
 * Description: Increase conversion with an easy to use chatbot.
 * Author: Quriobot
 * Author URI: https://quriobot.com/
 * Version: 2.9.2
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Text Domain: quriobot
 * Requires at least: 4.7
 * Requires PHP: 5.6
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'quriobot_plugin_init' );

function quriobot_plugin_init() {

	if ( ! class_exists( 'WP_Quriobot' ) ) :

		class WP_Quriobot {
			/**
			 * @var Const Plugin Version Number
			 */
			const VERSION = '2.9.2';

			/**
			 * @var Singleton The reference the *Singleton* instance of this class
			 */
			private static $instance;

			/**
			 * Returns the *Singleton* instance of this class.
			 *
			 * @return Singleton The *Singleton* instance.
			 */
			public static function get_instance() {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}

			public function __clone() {}

			public function __wakeup() {}

			/**
			 * Protected constructor to prevent creating a new instance of the
			 * *Singleton* via the `new` operator from outside of this class.
			 */
			private function __construct() {
				add_action( 'admin_init', array( $this, 'install' ) );
				add_filter(
					'plugin_action_links_' . plugin_basename( __FILE__ ),
					array( $this, 'plugin_action_links' )
				);
				$this->init();
			}

			/**
			 * Init the plugin after plugins_loaded so environment variables are set.
			 *
			 * @since 1.0.0
			 */
			public function init() {
				require_once( dirname( __FILE__ ) . '/includes/class-quriobot.php' );
				$quriobot = new Quriobot();
				$quriobot->init();
			}

			/**
			 * Updates the plugin version in db
			 *
			 * @since 1.0.0
			 */
			public function update_plugin_version() {
				delete_option( 'quriobot_version' );
				update_option( 'quriobot_version', self::VERSION );
			}

			/**
			 * Handles upgrade routines.
			 *
			 * @since 1.0.0
			 */
			public function install() {
				if ( ! function_exists( 'is_plugin_active' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				if ( ! is_plugin_active( plugin_basename( __FILE__ ) ) ) {
					return;
				}

				if ( ( self::VERSION !== get_option( 'quriobot_version' ) ) ) {

					$this->update_plugin_version();
				}
			}

			/**
			 * Adds plugin action links.
			 *
			 * @since 1.0.0
			 */
			public function plugin_action_links( $links ) {
				$plugin_links = array(
					sprintf(
						'<a href="%s">%s</a>',
						esc_url( admin_url( 'options-general.php?page=quriobot_settings' ) ),
						esc_html__( 'Settings', 'quriobot' )
					),
					sprintf(
						'<a href="%s">%s</a>',
						esc_url( 'https://quriobot.com/' ),
						esc_html__( 'Support', 'quriobot' )
					),
				);
				return array_merge( $plugin_links, $links );
			}
		}

		WP_Quriobot::get_instance();
	endif;
}
