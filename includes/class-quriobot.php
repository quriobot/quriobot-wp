<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quriobot {

	public function __construct()
	{
		
	}

	public function init() 
	{
		$this->init_admin();
    	$this->enqueue_script();
    	$this->enqueue_admin_styles();
	}

	public function init_admin() {
		register_setting( 'quriobot', 'quriobot_path' );
    	add_action( 'admin_menu', array( $this, 'create_nav_page' ) );
	}

	public function create_nav_page() {
		add_options_page(
		  esc_html__( 'Quriobot', 'quriobot' ), 
		  esc_html__( 'Quriobot', 'quriobot' ), 
		  'manage_options',
		  'quriobot_settings',
		  array($this,'admin_view')
		);
	}

	public static function admin_view()
	{
		require_once plugin_dir_path( __FILE__ ) . '/../admin/views/settings.php';
	}

	public static function quriobot_script()
	{
		$quriobot_path = get_option( 'quriobot_path' );
		$is_admin = is_admin();

		$quriobot_path = trim($quriobot_path);
		if (!$quriobot_path) {
			return;
		}

		if ( $is_admin ) {
			return;
		}

		echo '
		<script type="text/javascript" src="https://quriobot.com/qb/widget/' . $quriobot_path . '" async defer></script>
		';
	}

	private function enqueue_script() {
		add_action( 'wp_footer', array($this, 'quriobot_script') );
	}

    private function enqueue_admin_styles() {
        add_action( 'admin_enqueue_scripts', array($this, 'quriobot_admin_styles' ) );
    }

    public static function quriobot_admin_styles() {
        wp_register_style( 'quriobot_custom_admin_style', plugins_url( '../admin/static/quriobot-admin.css', __FILE__ ), array(), '20190701', 'all' );
        wp_enqueue_style( 'quriobot_custom_admin_style' );
    }

}

?>
