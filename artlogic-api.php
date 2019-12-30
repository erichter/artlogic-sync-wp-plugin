<?php
/*
Plugin Name: Art Logic Integration
Description: Import and syncronize and artworks from ArtLogic to WordPress.
Author: Erich Richter (based on original version written by Distill)
Version: 2.0
*/

// DEV ONLY
// Note also, setting too loosely this may break JSON responses.
ini_set('display_errors', 0);
error_reporting(E_ERROR); 

/* Settings to allow program to run uninterrupted. These are more reliable on .htaccess */
ini_set('wait_timeout', 300);
ini_set('max_execution_time', 300);
ini_set('max_allowed_packet', 524288000);

// Is this really needed for the live server?
add_action('http_api_curl', 'sar_custom_curl_timeout', 9999, 1);
function sar_custom_curl_timeout( $handle ){
	curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 30 );
	curl_setopt( $handle, CURLOPT_TIMEOUT, 30 );
}

include 'artlogic-class.php';
$artlogic = new ArtLogicApi();

/***** AJAX FUNCTIONS *****/

if( $artlogic->json ) {

	// Update cron_schedule_active, sets a db flag to pause or resume cron initiated updates.
	if( isset($_REQUEST['cron_schedule_active']) ){
		print json_encode( $artlogic->toggle_cron_schedule($_REQUEST['cron_schedule_active']) );
	}
	elseif( isset($_REQUEST['get_cursor']) ){
		print json_encode($artlogic->get_cursor());
	}
	elseif( isset($_REQUEST['cron_cycler']) ){
		// This loads a blank page an 5 minute intervals to bump WP Cron into action.
		// That way a user can leave a browser open and WP Cron will behave like a real cron. 
		print time();
	}
	else {
		// Test: http://hosfelt/wp/wp-admin/admin-ajax.php?page=artlogic-plugin&json=true
		print json_encode($artlogic->sync());
	}
	die;
}


/***** WP ADMIN *****/
// When the API is called without a (boolean) json parameter it is assumed to be a display function.

else {

	// Add styles and scripts
	function add_plugin_stylesheet(){
		wp_enqueue_style( 'artlogic-progress', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
		wp_enqueue_style( 'artlogic-api', plugins_url( '/css/style.css', __FILE__ ) );
	}
	add_action('admin_print_styles', 'add_plugin_stylesheet');
	wp_enqueue_script('jquery');
	wp_enqueue_script('jquery-ui-progressbar');
	wp_enqueue_script('jquery-ui-accordion');
	wp_enqueue_script('jquery-ui-selectmenu');
	wp_enqueue_script('artlogic-api', plugins_url('/js/admin.js', __FILE__ ) );


	// Make the plugin available to editors in WP admin.
	function add_artlogic_plugin_to_wp_admin(){
		global $artlogic;
		$icon_url = $artlogic->plugin_path.'/images/artlogic_menu_logo.svg';
		add_menu_page( 'ArtLogic Sync', 'ArtLogic Sync', 'edit_posts', 'artlogic-plugin', 'admin_page', $icon_url, 1);
		add_submenu_page('artlogic-plugin', 'Sync Status', 'Sync Status', 'edit_posts', 'artlogic-status-page', 'status_page' );
		add_submenu_page('artlogic-plugin', 'Help', 'Help', 'edit_posts', 'artlogic-help-page', 'help_page' );
	}
	add_action('admin_menu', 'add_artlogic_plugin_to_wp_admin');

	// Silent cron download, runs in segments limited to total megabyte blocks (config.ini: max_mb_per_request).
	function artlogic_cron_schedule() {
		global $artlogic;

		// Because cron runs outside the WordPress admin area.
		require_once($_SERVER['DOCUMENT_ROOT'].'/wp/wp-admin/includes/media.php');
		require_once($_SERVER['DOCUMENT_ROOT'].'/wp/wp-admin/includes/file.php');
		require_once($_SERVER['DOCUMENT_ROOT'].'/wp/wp-admin/includes/image.php');

		$cron = true;
		$data = $artlogic->sync($cron);
	}
	add_action( 'artlogic_sync', 'artlogic_cron_schedule' );


	// DISPLAY PAGES

	function admin_page() {
		global $artlogic;
		include 'page-admin.inc';
	}

	function status_page() {
		global $artlogic;
		include 'page-status.inc';
	}

	function help_page(){
		global $artlogic;
		include 'page-help.inc';
	}

}

?>
