<?php
/**
 * Removes everything the Quriobot plugin stored.
 *
 * The FAQ has always told people that uninstalling removes Quriobot from their
 * site; until 2.9.3 the settings and cached embed codes were left behind in the
 * database. WordPress runs this file only on an explicit Delete, never on
 * deactivation, so upgrades and temporary deactivations keep their settings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-quriobot.php';

/**
 * Deletes the plugin's options and every transient it created, for one site.
 */
function quriobot_uninstall_site() {
	global $wpdb;

	// Read the paths BEFORE deleting the option: they are what the current
	// version's transient names are derived from.
	$paths = Quriobot::split_lines( get_option( 'quriobot_path' ) );

	delete_option( 'quriobot_path' );
	delete_option( 'quriobot_init' );
	delete_option( 'quriobot_version' );

	// Under an external object cache (Redis, Memcached — the norm on managed
	// hosts) transients never touch the options table, so the sweep below finds
	// nothing. Deleting the reconstructed names is the only thing that works
	// there, and it goes through delete_transient() so both backends are covered.
	Quriobot::forget_bot_paths( $paths );

	// Rows left by earlier plugin versions embed a different version number in
	// the name, so they cannot be reconstructed and have to be swept.
	//
	// Deleted as options, NOT via delete_transient(): that function short-circuits
	// to wp_cache_delete() whenever an object cache is active and would leave
	// every one of these rows in place — the exact situation this sweep exists
	// for, since the rows predate the cache being switched on. It also never
	// removes the companion _transient_timeout_ rows, which is why both name
	// patterns are matched here.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_quriobot.' ) . '%',
			$wpdb->esc_like( '_transient_timeout_quriobot.' ) . '%'
		)
	);

	foreach ( $names as $name ) {
		delete_option( $name );
	}
}

if ( is_multisite() ) {
	// Batched: a large network would otherwise load every site and switch into
	// each one in a single request, time out part-way, and leave the rest of the
	// network with orphaned rows.
	$quriobot_batch  = 100;
	$quriobot_offset = 0;
	do {
		$quriobot_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $quriobot_batch,
				'offset' => $quriobot_offset,
			)
		);

		foreach ( $quriobot_site_ids as $quriobot_site_id ) {
			switch_to_blog( $quriobot_site_id );
			quriobot_uninstall_site();
			restore_current_blog();
		}

		$quriobot_offset += $quriobot_batch;
	} while ( count( $quriobot_site_ids ) === $quriobot_batch );
} else {
	quriobot_uninstall_site();
}
