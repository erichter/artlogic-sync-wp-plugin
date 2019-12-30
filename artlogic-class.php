<?php
/*
TO DO:
Organize functions ad isolte those that are required by interface.
Document class output and public is.
Write utility for help resolving duplicate images.
Write function for full image refresh which breaks the job down into smaller parts.
Rewrite sync so it doesn't process pages one at a time.
Add better support for testing JSON output, use the config debug_mode flag.

DEPENDENCIES:

Anything in the WP database prefixed with "artlogic_" is part of this plugin and not directly used on the site elsewhere.

wp_postmeta.meta-key[artist]: (can't find where this custom post type is being created
	content-single-artist.php
	content-single-attachment.php

wp_postmeta.meta-key[artworks]:
	Used internally only, to determine changes to artworks and 

wp_postmeta.meta-key[artist_works]:
	This is stored as acf_artist_sort_field in config.ini .
	The theme uses this field for maintaining sort order for artist images.
	See: content-single-artist.php

wp_postmeta.meta-key[artlogic_artist_id]:
	A pivot table for binding WP 'artist' posts (a custom post type) to ArtLogic images.
	meta-key[artlogic_artist_id] > meta-value[ArtLogic artist ID]

*/


Class ArtLogicApi {

	public $config;
	public $reload;
	public $artist_id;
	public $manual_refresh;
	public $data_is_current;
	public $download_cursor;
	public $max_bytes_per_request;
	public $max_mb;
	public $artist_stats;
	public $no_of_pages;
	public $last_update;
	public $json;
	public $json_url;
	public $doc_root;
	public $home_url;
	public $admin_url;
	public $plugin_url;
	public $plugin_path;
	public $plugin_abs_path;
	public $cron_schedule_active;
	public $cron_schedule_name;

	/* private */
	public $wp_db_prefix;
	public $artlogic_fields;
	public $max_mb_per_request;

	public function __construct() {

		$this->config = parse_ini_file('artlogic.ini', true);
		date_default_timezone_set($this->config['timezone_str']);
		$this->wp_db_prefix = 'artlogic_';
		$this->artlogic_fields = $this->get_import_fields();

		// $_REQUEST variables
		$this->artist_id = isset($_REQUEST['artist_id']) ? intval($_REQUEST['artist_id']) : 0;
		$this->json = isset($_REQUEST['json']) && $_REQUEST['json']=='true' ? true : false;
		$this->manual_refresh = isset($_REQUEST['manual_refresh']) && $_REQUEST['manual_refresh']=='true' ? true : false;
		$this->force_download = isset($_REQUEST['force_download']) && $_REQUEST['force_download']=='true' ? true : false;
		$this->data_is_current = $this->is_feed_data_fresh();

		// Useful WP paths.
		$this->home_url = $this->getProtocol().$_SERVER['HTTP_HOST'];
		$this->doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
		$this->plugin_url = $_SERVER['SCRIPT_NAME']. '?page=artlogic-plugin';
		$this->plugin_path = $this->get_plugin_path(); // used for image paths
		$this->plugin_abs_path = $this->doc_root .$this->plugin_path. '/';
		$this->admin_url = get_admin_url().'admin.php?page=artlogic-plugin';
		$this->json_url = get_admin_url().'admin-ajax.php?page=artlogic-plugin';

		// Other global return values used by admin forms and cron jobs.
		$this->cron_schedule_name = $this->get_cron($this->config['cron_hook']);
		$this->cron_schedule_active = $this->cron_schedule_name ? get_option($this->wp_db_prefix.'cron_schedule_active') : false;
		$this->max_bytes_per_request = $this->set_max_bytes();
		$this->max_mb = $this->pretty_filesize($this->max_bytes_per_request,0);
	}


	/***** PUBLIC API FUNCTIONS *****/

	// This returns ArtLogic fields needed by this plugin plus any additional fields set
	// in config.ini as may be needed for theme development.
	function get_import_fields(){

		$required_fields = [	'artist',
									'artist_id',
									'description2',
									'dimensions',
									'has_changed_hash',
									'height',
									'id',
									'image_last_modified',
									'img_url',
									'medium,',
									'title',
									'video_embed_code',
									'width',
									'year'
									];

		$str_fields = str_replace(' ','', $this->config['fields_to_import']);
		$optional_fields = explode(',', $str_fields);
		$all_fields = array_merge($required_fields,$optional_fields);

		// Filter for duplicates and return the fields.
		$fields = [];
		foreach($all_fields as $idx=>$key) $fields[$key] = $key;
		return $fields;
	}

	// admin_page() builds the admin user interface for downloading new data.
	// It loads an HTML form for choosing and then updating an artist's data specified in the form post.
	// Output: last_update, no_of_pages, cron_schedule_name, json_url
	function admin_page(){

		// Retrieve the artists' names for the drop-down menu.
      $artist_posts = $this->get_all_artist_posts();
		$this->artist_stats = $this->get_artist_stats($artist_posts);

		// $reload is a flag used on the admin form to recycle the user choices for the next run.
		$this->reload = isset($_REQUEST['reload']) && $_REQUEST['reload']=='true' ? true : false;

		// If the admin form was called with an artist's ID or a download is in progress
		// skip the refresh and just do the downloads.
		$this->download_cursor = get_option($this->wp_db_prefix.'download_cursor');
		if($this->artist_id) {
			return $this->download_images($artist_posts, $this->artist_id);
		}
		return [	'str_response' => '',
					'str_message' => ''
					];
	}

	// Download data from ArtLogic feed page by page and update local db with new data.
	// This gets run from the admin form via AJAX, on the debugging page, and also by cron.
	// It has three modes of operation:
	// 1) Call with $cron_callback=true: run full sync
	// 2) Call with $_REQUEST['artist_id']: run sync for specified artist
	// 3) Call without parameters: download and store sync cache data only.
	function sync($cron=false) {

		$rows = [];
		$cache = [];
		$artist_stats = [];
		$str_resp = '';

		// Get the first page of the feed.
		$this->data_is_current = $this->is_feed_data_fresh($cron);
		update_option($this->wp_db_prefix.'page_cursor', 0);
		$last_update = get_option($this->wp_db_prefix.'last_update');
		$artist_posts = $this->get_all_artist_posts();

		if(!$this->data_is_current) {

			// Start by clearing files from the data cache directory.
			$pattern = $this->wp_db_prefix.'page_[0-9][0-9][0-9][0-9].json';
			$path = $this->plugin_abs_path.'data/';
			$this->delete_files( $path, $pattern );

			// $save=2 overrides $cron_schedule_active and saves anyway.
			$save = $last_update ? 1: 2;
			$page = $this->get_page(1, $this->config['feed_url'], $save);
			if($page && $page->rows) {

				$rows = array_merge($rows, $page->rows);
				$feed_data = $page->feed_data;
				$curr_page = intval($page->feed_data->page)+1;
				$no_of_pages = $page->feed_data->no_of_pages;
				update_option($this->wp_db_prefix.'no_of_pages', $no_of_pages);

				while($no_of_pages >= $curr_page ) {

					// ArtLogic.net rules, no more than one per second.
					if(!$this->config['debug_mode']) sleep(1);

					update_option($this->wp_db_prefix.'page_cursor', $curr_page);
					$page = $this->get_page($curr_page, $page->feed_data->next_page_link, $save);

					if(!$page || !$page->rows) break;
					else {
						$rows = array_merge($rows, $page->rows);
						$curr_page = intval($page->feed_data->page)+1;
					}
				}

				// Set cursor to -1 so AJAX scripts know when the job complete.
				update_option($this->wp_db_prefix.'page_cursor', -1);
				$this->set_timestamp();
				$artist_stats = $this->sort_sync_data_by_artist($rows, $artist_posts );
				$this->delete_old_cron_logs();
			}
			else {
				$str_resp = 'Data import empty. Check the ArtLogic data source '
					.'<a href="'. $this->config['feed_url'] .'" target="_blank">here</a>.';
			}
		}
		// No sync, return stats only.
		else if(!$cron) {
			$artist_stats = $this->get_artist_stats($artist_posts);
		}

		// Proceed to edits if this is a cron request, or if an artist_id has been provided.
		$do_downloads = ( ($cron && $this->cron_schedule_active) || ($this->artist_id > 0) );
		if( $do_downloads ) {
			// This typically runs for cron but it can also run for cron-test and send a specific artist_id.
			$str_resp = $this->download_images($artist_posts, $this->artist_id, true);
		}
		else if($cron==true) {
			$str_resp = 'Cron is temporarily turned off.';
		}

		// Return JSON response
		$data = [
			'artist_stats' => $artist_stats,
			'str_response' => $str_resp
			];

		return $data;
	}

	// This function compiles unsorted ArtLogic feed data into the artist's wp_options record.
	// It overwrites all data there (image URLs, details, title, etc.) except gallery sort order.
	function sort_sync_data_by_artist($cache, $artist_posts) {

		$artist_stats = [];
		foreach ($artist_posts as $post) {

			$artist_id = $post->ID;
			$artist_name = $post->post_title;
			$artlogic_artist_id = $this->get_artlogic_artist_id($artist_id);

			// Filter raw data and compile only works by this artist.
			if($artlogic_artist_id) {
				$rows = array_filter($cache, function($row) use ($artlogic_artist_id) {
					return ($artlogic_artist_id == $row->artist_id);
				});
			}
			else {
				// If the ArtLogic artist_id is not yet known we have to sort on the artist's name.
				// This is only true the first time a new artists is imported. Once the ArtLogic ID
				// has been stored it will always sync between ArtLogic artist_id to wp_post.ID
				$rows = array_filter($cache, function($row) use ($artist_name) {
					return ($row->artist==$artist_name);
				});

				if(count($rows)) {
					$first_row = reset($rows);
					$artlogic_artist_id = $first_row->artist_id;
					update_post_meta($artist_id, $this->wp_db_prefix.'artist_id', $artlogic_artist_id);
				}
			}

			// Condense the incoming data to only the fields we need.
			$works = [];
			foreach ($rows as $item) {
				$row = [];
				foreach ($this->artlogic_fields as $idx=>$key) {
					if( property_exists($item,$key) ) $row[$key] = $item->$key;
				}
				array_push($works, $row);
			};
			update_option($this->wp_db_prefix.'wp_artist_'.$artist_id, $works);
			$stats = $this->compile_artworks($post,$works);
			$artist_stats[$artist_id] = $stats;
		}
		$artist_stats = $this->sort_array_by_column($artist_stats, 'name');
		$artist_stats = $this->index_stats_by_id($artist_stats);
		return $artist_stats;
	}

	// Compiles details for all works by a single artist, used for tracking changes.
	function compile_artworks($post,$works){

		$artist_stats = [];
		$artist_id = $post->ID;
		$artlogic_artist_id = $this->get_artlogic_artist_id($artist_id);

		// Get existing WP gallery details for this artist.
		$postmeta = get_post_meta($artist_id, 'artworks', true);
		$display_order = get_post_meta($artist_id, $this->config['acf_artist_sort_field'], true);
		$media_library_ids = $this->get_media_library_ids($postmeta, $artist_id);

		$stats = [
			'id' => $artist_id,
			'artlogic_artist_id' => $artlogic_artist_id,
			'name' => $post->post_title,
			'post_name' => $post->post_name,
			'post_type' => $post->post_type,
			'new' => 0,
			'updated' => 0,
			'image_new' => 0,
			'deleted' => 0,
			'incomplete' => 0,
			'all' => 0,
			'displayed' => count($display_order)
			];

		// Flag the status of each item.
		foreach ($works as $idx => $item) {

			$artlogic_id = $item['id'];
			$meta = $postmeta[$artlogic_id];
			$attach_id = $meta['id']>0 ? $meta['id'] : 0;

			// This test corrects corrupt records, keeps data clean if users (or programmers) accidentally muck it up.
			if(!$meta || count($meta)!=6){
				$meta = [
					'id' => $attach_id,
					'hash' => '',
					'image_last_modified' => '',
					'year' => '',
					'import_status' => '',
					'image_status' => ''
					];
			}

			$new_artlogic_id = $item['id'];
			$new_hash = $item['has_changed_hash'];
			$new_image_last_modified = $item['image_last_modified'];
			$hash = $meta['hash'];
			$image_last_modified = $meta['image_last_modified'];

			// Is this image missing from the Media Library or Artists Gallery? Test here for both.
			$in_display_order = ( array_search( $attach_id, $display_order ) === false) ? false: true;
			$in_media_library = ( array_search( $attach_id, $media_library_ids ) === false) ? false: true;

			// This block sets: new, updated, image_new.
			$import_status = $this->is_record_changed (	$hash,
																		$new_hash,
																		$artlogic_id,
																		$new_artlogic_id,
																		$in_display_order );
			if($import_status == 'deleted') $stats['deleted']++;

			$image_status = $this->is_image_new (	$image_last_modified,
																$new_image_last_modified,
																$in_media_library );

			// This exception happens when an item is deleted from the Media Library directly.
			if($image_status=='image_new' && $import_status=='') $import_status='updated';

			 // Tally images of each type
			if($import_status) $stats[$import_status]++;
			if($image_status) $stats[$image_status]++;
			if($import_status != 'deleted') $stats['all']++;

			if(!$this->item_is_displayable($item)){
				$import_status = 'incomplete';
				$image_status = '';
				$stats['incomplete']++;
			}
			$meta['id'] = $attach_id;
			$meta['year'] = $item['year'];
			$meta['hash'] = $item['has_changed_hash'];
			$meta['image_last_modified'] = $item['image_last_modified'];
			$meta['import_status'] = $import_status;
			$meta['image_status'] = $image_status;
			$postmeta[$artlogic_id] = $meta;
		}

		// Now that we have the items sorted, mark retired items for deletion.
		foreach ($postmeta as $id => $item) {
			$item_exists = array_search($id, array_column($works, 'id'));
			if($item_exists===false) {
				$postmeta[$id] = [ id => $attach_id, 'import_status' => 'deleted' ];
			}				
		}

		update_post_meta($artist_id, 'artworks', $postmeta);
		return $stats;
	}

	// Returns update stats for all artists.
	function get_artist_stats($artist_posts=null){

		$artist_stats = [];
		if(!$artist_posts) $artist_posts = $this->get_all_artist_posts();
		foreach($artist_posts as $post){

			$image_new = 0;
			$new = 0;
			$updated = 0;
			$incomplete = 0;
			$deleted = 0;
			$all = 0;
			$artist_id = $post->ID;
			$meta = get_post_meta($artist_id, 'artworks', true);
			$display_order = get_post_meta($artist_id, $this->config['acf_artist_sort_field'], true);

			foreach($meta as $idx => $item){
				$import_status = array_key_exists('import_status',$item)?$item['import_status']:'';
				$image_status = array_key_exists('image_status',$item)?$item['image_status']:'';
				if($image_status=='image_new' && $import_status=='') $import_status = 'updated';
				$image_new += ($image_status=='image_new'?1:0);
				$new += ($import_status=='new'?1:0);
				$updated += ($import_status=='updated'?1:0);
				$incomplete += ($import_status=='incomplete'?1:0);
				if($import_status=='deleted'){
					$deleted += ($import_status=='deleted'?1:0);
				}
				else {
					$all++;
				}
			}

			$artist_stats[$artist_id] = [
				'id' => $artist_id,
				'artlogic_artist_id' => $this->get_artlogic_artist_id($artist_id),
				'name' => $post->post_title,
				'post_name' => $post->post_name,
				'post_type' => $post->post_type,
				'image_new' => $image_new,
				'new' => $new,
				'updated' => $updated,
				'incomplete' => $incomplete,
				'deleted' => $deleted,
				'all' => $all,
				'displayed' => count($display_order)
				];
		}
		$artist_stats = $this->sort_array_by_column($artist_stats, 'name');
		$artist_stats = $this->index_stats_by_id($artist_stats);
		return $artist_stats;
	}

	// Basically sort on column artist id.
	function index_stats_by_id($artist_stats){
		$arr = [];
		foreach($artist_stats as $idx => $stats){
			$arr[$stats['id']] = $stats;
		}
		return $arr;
	}

	function sort_array_by_column($arr, $col){
		$arrTmp = [];
		foreach ($arr as $key => $val) $arrTmp[$val[$col]] = $val;
		ksort($arrTmp);
		$arrSort = [];
		foreach ($arrTmp as $key => $val) $arrSort[] = $val;
		return $arrSort;
	}

	function get_media_library_ids ($postmeta, $artist_id){
		$attach_ids = array_column($postmeta, 'id');
		$args = array( 'post_type' => 'attachment', 'numberposts' => -1, 'include' => $attach_ids );
		$media_lib = get_posts($args);
		return array_column($media_lib, 'ID');
	}

	// Compare incoming hash to existing item. 
	function is_record_changed( $hash, $new_hash, $artlogic_id, $new_artlogic_id, $in_display_order ) {

		// If no hash or artlogic_id doesn't match what we have this is a new record.
		if( !$hash || $artlogic_id != $new_artlogic_id ){
			$status = 'new';
		}
		else {
			if ( $new_hash != $hash || !$in_display_order ) $status = 'updated';
			else $status = '';
		}

		return $status;
	}

	// Compare image timestamp to existing image.
	function is_image_new ( $image_last_modified, $new_image_last_modified, $in_media_library ) {
		if ( strtotime($image_last_modified) != strtotime($new_image_last_modified) || !$in_media_library ) $status = 'image_new';
		else $status = '';
		return $status;
	}

	// Filter for excluding works that don't meet minimum website display requirements: title and image/video.
	function item_is_displayable($item) {
		$has_title = ($item['title'] || $item['year']);
		$has_desc = ($item['artist'] || $item['medium'] || $item['dimensions'] || $item['description2']);
		$has_image = ($item['img_url'] || $item['video_embed_code']);
		$is_displayable = ($has_title && $has_desc && $has_image);
		return $is_displayable;
	}

	// Toggle cron_schedule_active on Admin toolbar.
	function toggle_cron_schedule($onoff){
		$status = $onoff=='true' ? true : false;
		update_option($this->wp_db_prefix.'cron_schedule_active', $status);
		return [ cron_schedule_active=>$status ];
	}

	// Returns a JSON encoded value for current page progress.
	// Use by AJAX scripts for showing download progress.
	function get_cursor(){
		$cursor = intval( get_option($this->wp_db_prefix.'page_cursor') );
		$no_of_pages = intval( get_option($this->wp_db_prefix.'no_of_pages') );
		return [ cursor=>$cursor, no_of_pages=>$no_of_pages ];
	}



	/***** PRIVATE FUNCTIONS - UTILITY *****/

	private function get_timestamp() {
		return date("Y-m-d H:i:s", time());
	}

	function set_timestamp() {
		update_option($this->wp_db_prefix.'last_update', $this->get_timestamp() );
	}

	function php_date_from_sec($sec){
		return date("Y-m-d H:i:s", round($sec));
	}

	function date_pretty ($timestamp_sec){
		return ( is_numeric($timestamp_sec) && $timestamp_sec>0 ) ? date("m/d/Y g:i A", round($timestamp_sec)) : '';
	}

	function getProtocol(){
		if (isset($_SERVER['HTTPS']) &&
			($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1)
			|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') ) {
  			return 'https://';
		}
		else return 'http://';
	}

	function get_plugin_path() {
		$home_url = $this->getProtocol().$_SERVER['HTTP_HOST'];
		$url = plugins_url().'/'.basename(  dirname( __FILE__ ));
		return str_replace( $home_url, '', $url);
	}

	function parse_interval($sec){
		$int = [];
		$int['sec'] = gmdate('s', $sec);
		$int['min'] = gmdate('I', $sec);
		$int['hour'] = gmdate('H', $sec);
		$int['day'] = (gmdate('d', $sec)-1);
		return $int;
	}

	function set_max_bytes(){
		$max_mb = $this->config['max_mb_per_request'];
		$max_mb = ($max_mb< .25) ? .25 : $max_mb;
		$max_mb = ($max_mb>100) ? 100 : $max_mb;
		return $max_mb * 1000000;
	}

	function pretty_filesize($bytes, $decimals = 2) {
		$sz = ['bytes','KB', 'MB', 'GB', 'TB', 'PB'];
		$factor = floor((strlen($bytes) - 1) / 3);
		return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) .' '.$sz[$factor];
	}

	function is_online(){
		$url = $this->config['artlogic_home_url'];
		return @fsockopen($url,80) ? true: false;
	}


	/***** PRIVATE FUNCTIONS - PROCESSING *****/

	// Skip the feed_data download if it has been updated recently.
	// There are two values, one for manual updates and one for cron sync.
	// These can also be overridden by $_REQUEST['refresh_data'] = true;
	function is_feed_data_fresh ($cron = false){

		// force refresh
		if( $this->manual_refresh ) return false;

		// Don't automatically refresh when cron schedule is toggled off.
		if( $cron && $this->cron_schedule_active==false ) return true;

		$last_update = get_option($this->wp_db_prefix.'last_update');
		$age_in_minutes = $this->config['cache_expiration_minutes_manual'];
		if($cron) $age_in_minutes = $this->config['cache_expiration_minutes_cron'];
		$age_in_sec = $age_in_minutes*60;
		$now = $this->get_timestamp();
		$diff_min = (strtotime($now)-strtotime($last_update))/60;
		$feed_data_is_fresh = ($diff_min < $age_in_minutes);
		return $feed_data_is_fresh;
	}

	function get_last_update(){
		$last_update = get_option($this->wp_db_prefix.'last_update');
		$last_update_sec = $last_update? strtotime($last_update) : 0;
		return $this->date_pretty( $last_update_sec );
	}

	function get_no_of_pages(){
		$page = $this->get_page(1, $this->config['feed_url'], 0);
		if($page->feed_data) return intval($page->feed_data->no_of_pages);
		return 0;
	}

	function get_all_artist_posts() {
		$args = array(
			'numberposts' => -1,
			'post_type'   => 'artist'
		);
		return get_posts($args);
	}

	// Retrieve a cron schedule by it's WP slug.
	function get_cron($slug){

		// Built-in WP cron display names.
		$display = [	'hourly' => 'Once Hourly',
							'twicedaily' => 'Twice Daily',
							'daily' => 'Once Daily',
							'weekly' => 'Once Weekly',
							'monthly' => 'Monthly'
							];

		// Custom cron cron display names.
		$cron_custom = get_option('crontrol_schedules');

		// WP cron schedule.
		$crons = get_option('cron');

		foreach( $crons as $id => $item ) {
			if(is_array($item) && array_key_exists($slug,$item)){
				foreach( $item[$slug] as $idx => $cron ) {
					$schedule = $cron['schedule'];
					$interval = $cron['interval'];
					$int = $this->parse_interval($interval);
					if($cron_custom[$schedule] && $cron_custom[$schedule]['display']) {
						$name = $cron_custom[$schedule]['display'];
					}
					else if($display[$schedule]) {
						$name = $display[$schedule];
					}
					return $name;
				}
			}
		}
		return '';
	}

	// Download a single page of ArtLogic feed_data.
	function get_page($page_num, $next_page_link, $save=0){

		// Use sample data when in debug mode.
		$page_name = $this->wp_db_prefix.'page_' . sprintf('%04d', $page_num);
		if($this->config['debug_mode']) {
			$next_page_link = $this->home_url.$this->plugin_path.'/data/'. $page_name .'.json';
		}
		$req = wp_remote_get($next_page_link, array('timeout' => 5));
		if( is_wp_error($req) ) return false;
		$body = wp_remote_retrieve_body($req);

		// Save the page for sample data.
		if( ($this->config['debug_mode']==false && $save==1) || $save==2) {
			$filename = __DIR__. '/data/' .$page_name.'.json';
			file_put_contents($filename, $body);
		}

		$data = json_decode($body);
		if($data) return $data;
		return false;
	}

	// Deletes all files in a given directory.
	function delete_files($dir,$pattern='*') {
		if(!$dir) return false;
		$files = glob($dir.$pattern);
		foreach($files as $file){
			if(is_file($file)) unlink($file);
		}
	}

	function html_link($url,$link){
		return '<a href="'.$url.'" target="blank">'.$link.'</a>';
	}

	function get_video_url($embed_code) {
		$doc = new DOMDocument();
		$d = $doc->loadHtml($embed_code);
		$a = $doc->getElementsByTagName('iframe');
		$youtube_url = $a->item(0)->getAttribute("src");
		$yt_link = '<br><a class="bold" href="'. $youtube_url . '" target="_blank">VIEW VIDEO</a>';
		return $yt_link;
	}

	// This returns artlogic_artist_id if one exists.
	function get_artlogic_artist_id($artist_id){
		$id = get_post_meta($artist_id, $this->wp_db_prefix.'artist_id', true);
		return $id ? $id : 0;
	}

	// Attach image downloads an image, saves it as a post attachment.
	// Returns an attachment ID and error message if the download failed.
	function attach_image($artist_id, $url, $title, $desc) {

		// Download the image and save it to a server temp location for preprocessing.
		$err_str = '';
		$tmp = download_url($url);
		$download_err = is_wp_error($tmp);
		if($download_err) $err_str = $tmp->get_error_message().': '.$url;

		// Format the filename for query strings.
		$file_array = array();
		preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $url, $matches);
		$file_array['name'] = basename($matches[0]);
		$file_array['tmp_name'] = $tmp;
	
		// If there was an error storing temporarily, unlink
		if($download_err){
			@unlink($file_array['tmp_name']);
			$file_array['tmp_name'] = '';
		}

		// Create the image attachment.
		$attach_id = media_handle_sideload( $file_array, $artist_id, $title, ['post_excerpt' => $desc]);

		// If error storing permanently, unlink
		if ( is_wp_error($attach_id) ) {
			@unlink($file_array['tmp_name']);
			$err_str = 'Unable to upload image to file system: '.$url;
		}

		return [	'id' => $attach_id,
					'err_str' => $err_str
					];
	}

	function format_description($item) {

		$title = '<em>' . $item['title'] . '</em>, ' . $item['year'];
		$dimensions = trim(preg_replace('/\s+/', ' ', $item['dimensions']));
		$desc = $item['artist']. ', <em>' .$item['title']. '</em>, ' .$item['year']. ', ' . $item['medium']. ', ' .$dimensions;
	
		// description2 = SOLD
		if (!empty($item['description2'])) $desc = $desc . ' - <strong>' . $item['description2'] . '</strong>';
	
		$video = $item['video_embed_code'];
		if (!empty($video)) $desc = $desc . ' ' . $this->get_video_url($video);
	
		$details = [
			'title' => $title,
			'description' => $desc,
		];
		if ($this->item_is_displayable($item)) return $details;
		return false;
	}

	function get_thumbnail( $meta, $rel_path, $bytes=0 ) {
		$source_file = $meta['file'];
		$dir = dirname($source_file);
		$source_filename = basename($meta['file']);
		$thumb_filename = $meta['sizes']['thumbnail']['file'];
		$url = wp_get_upload_dir()['baseurl'].'/'.$dir.'/'.$thumb_filename;
		$size = $this->pretty_filesize($bytes,1);
		return '<div class="thumbnail"><img class="img" src="'.$url.'" title="'.$source_filename.' '.$size.'"></div>';
	}

	// Links for mini-toolbar for a given item: view, edit, ArtLogic.
	function artist_toolbar_links( $artist_id, $artlogic_artist_id, $artist_post_type, $artist_name ){

		$artist_url = $this->getProtocol().$_SERVER['HTTP_HOST'].'/'.$artist_post_type.'/'.$artist_name;
		$artist_edit_url = get_admin_url().'post.php?action=edit&post='.$artist_id;
		$artlogic_edit_url = $this->config['artlogic_artist_page_base_url'] . $artlogic_artist_id;

		$html = '<span class="links"><a href="'.$artist_url.'" class="view" target="_blank">View</a> '
			.'<a href="'.$artist_edit_url.'" class="edit" target="_blank">Edit</a> '
			.'<a href="'.$artlogic_edit_url.'" class="artlogic" target="artlogic" class="offsite">ArtLogic</a></span>';
		return $html;
	}

	function update_attachment_meta($attach_id, $item, $descr) {

		$fav_btn = get_favorites_button($attach_id);
		$descr_with_btn = $fav_btn . '&nbsp;' . $descr['description'];

		// Update the attachment description.
		$uploaded_image = [
			'ID' => $attach_id,
			'post_excerpt' => $descr_with_btn
		];
		wp_update_post($uploaded_image,true);

		// Format the image width and height (this has something to do with the theme).
		$work_size = [
			'width' => $item['width'],
			'height' => $item['height']
		];
		$work_size_string = json_encode($work_size);
		update_post_meta($attach_id, '_wp_attachment_image_alt', $work_size_string);

		// Update the image year, save the hash and last modified date for file change comparisons.
		return [
			'id' => $attach_id,
			'year' => $item['year'],
			'hash' => $item['has_changed_hash'],
			'image_last_modified' => $item['image_last_modified']
			];
	}

	// Replace old attach_ids with updated ones, preserving the original sort order. New ones go to the end.
	function update_display_order( $artist_id, $artworks, $new_artworks ) {

		$display_order = get_post_meta($artist_id, $this->config['acf_artist_sort_field'], true);
		$end = count($new_artworks); // So we can add new items exactly at the end of the $sort array.
		$sort = [];
		foreach($new_artworks as $artlogic_id => $new_meta){

			// Find the existing attach_id by its artlogic id (if any).
			$new_id = $new_meta['id'];
			$old_id = $artworks[$artlogic_id] ? strval($artworks[$artlogic_id]['id']): 0;
			$sort_index = array_search($old_id, $display_order);
			if ($sort_index!==false) $sort[$sort_index] = strval($new_id);
			else {
				$end++;
				$sort[$end] = strval($new_id);
				$sort_index = $end;
			}
		}

		// Sort on key and remove any missing elements.
		ksort($sort);

		$sort_order = [];
		foreach($sort as $i=>$id) {
			if(strlen($id)) array_push($sort_order, $id);
		}
		update_post_meta($artist_id, $this->config['acf_artist_sort_field'], $sort_order);
	}

	// Deletes an image, and removes it from artworks and display_order.
	function delete_items($artist_id,$artworks,$display_order){
		// Before we doing updates get rid of deleted items.
		$deleteCt = 0;
		$ids = [];
		$thumbnails = '';
		foreach ($artworks as $artlogic_id => $row) {
			if($row['import_status']=='deleted') {
				$deleted = wp_delete_attachment($row['id'], true);
				if($row['id']) array_push($ids,$row['id']);
				unset($artworks[$artlogic_id]);
				$idx = array_search($row['id'], $display_order);
				if($idx!==false) unset($display_order[$idx]);
				$thumbnails .= '<div class="thumbnail deleted"><img src="'.$this->plugin_path.'/images/thumbnail-placeholder-deleted-100.png"></div>';
				$deleteCt++;
			}
		}
		if($deleteCt) {
			update_post_meta( $artist_id, 'artworks', $artworks );
			update_post_meta($artist_id, $this->config['acf_artist_sort_field'], $display_order);
		}
		return [ 'artworks' => $artworks, 'thumbnails' => $thumbnails, 'ids' => $ids];
	}

	// Saves entries to a monthly log.
	function cron_log($str_entry) {
		if($str_entry){
			$str_entry = mb_convert_encoding($str_entry, 'UTF-8', 'OLD-ENCODING'); // This makes the characters friendly to a browser.
			$str_entry = date('m.d-H:i:s', time()).CHR(9).$str_entry;
			$filename = $this->plugin_abs_path.'logs/'.date('Y.m', time()).'-sync.txt';
			if(!$file_exists($filename)) {
				$str_header = '// This is an auto-generated file.';
				file_put_contents($filename, $str_header.CHR(10), FILE_APPEND);
			}
			file_put_contents($filename, $str_entry.CHR(10), FILE_APPEND);
		}
	}

	// Delete old cron logs to prevent them from accumulating on the server.
	function delete_old_cron_logs(){
		$logdir = $this->plugin_abs_path.'logs/';
		$log_lifespan_days = $this->config['cron_log_lifespan_days'];
		$log_lifespan_days = $log_lifespan_days < 1 ? 1 : $log_lifespan_days;
		$log_lifespan_days = $log_lifespan_days > 365 ? 365: $log_lifespan_days;
		$day_sec = 86400;
		$lifespan_sec = $log_lifespan_days * $day_sec;
		foreach (glob($logdir. '*sync.log') as $file) {
			$file_age_secs = time()-filemtime($file);
			if( $file_age_secs > $lifespan_sec) unlink($file);
		}
	}

	// Generates a list of HTML links to cron logs.
	function list_cron_logs(){
		$list = '';
		$logdir = $this->plugin_abs_path.'logs/';
		foreach (glob($logdir. '*sync.txt') as $file) {
			$filename = str_replace($this->doc_root,'',$file);
			$url = $this->home_url.$filename;
			$list .= '<a href="'.$url.'" target="_blank">'.str_replace('.txt', '', basename($filename)).'</a><br>';
		}
		if(!strlen($list)) $list = '- empty -';
		return $list;
	}

	// Updates image details and image files as needed.
	// If an artist_id is provided it processes only that artist, otherwise it runs through all artists.
	// Batches are run in segments as set in config.ini max_mb_per_request.
	function download_images( $artist_posts, $selected_artist_id=0, $cron=false ) {

		$str_message = '';
		$str_response = '';
		$resp_html = '';
		$loop_ct = 0;
		$req_download_bytes = 0;
		$this->reload = false;

		// For formatting thumbnail links and filesize.
		$uploads_url = wp_get_upload_dir()['baseurl'];
		$uploads_path = $this->doc_root . str_replace($this->home_url, '', $uploads_url).'/';

		$stats = $this->artist_stats;
		$download_cursor = get_option($this->wp_db_prefix.'download_cursor');
		$start_time = time();
		//if($cron) $this->cron_log('Start');
		
		foreach ($artist_posts as $post) {

			// This array stores log counts for the current artist's items.
			$cronlog = [ 'new' => [], 'updated' => [], 'deleted' => [] ];

			// If an artist_id was provided skip all other records.
			// If cron_callback do all that have updates.
			$process_downloads = ( $selected_artist_id == $post->ID || (!$selected_artist_id && $cron) );
			if($process_downloads) {

				$artist_id = $post->ID;
				$artlogic_artist_id = $this->get_artlogic_artist_id($artist_id);
				$artist_name = $post->post_title;
				$ct_new = 0;
				$ct_updated = 0;
				$ct_deleted = 0;
				$ct_items_processed = 0;
				$resp = [];
				$thumbnails = '';

				// Current artlogic data from cache.
				$artlogic_items = get_option($this->wp_db_prefix.'wp_artist_'.$artist_id);

				// Process any items that need to be deleted.
				$artworks = get_post_meta($artist_id, 'artworks', true);
				$display_order = get_post_meta($artist_id, $this->config['acf_artist_sort_field'], true);
				$del = $this->delete_items( $artist_id, $artworks, $display_order );
				$cronlog['deleted'] = $del['ids'];
				$ct_deleted = count($del['ids']);
				array_push( $resp, $del['thumbnails'] );

				$info = $artist_name.' '.$artist_id.'|'.$artlogic_artist_id.' ct:'.count($artlogic_items).' ct-old:'.count($artworks);
				//if($cron) $this->cron_log($info);

				// delete_items returns existing artworks less the deleted items.
				$orig_artworks = $artworks = $del['artworks'];

				// Loop over the ArtLogic cache and update only those items that are changed or new.
				foreach ($artlogic_items as $idx => $row) {

					$artlogic_id = $row['id'];

					// Flag item for update if missing from artist's gallery.
					// This messes up stats and update flags
					if(0) if( array_search( $artlogic_id, $display_order ) === false ) {
						$artworks[$artlogic_id]['import_status'] = 'updated';
						$artworks[$artlogic_id]['image_status'] = 'new_image';
					}

					$import_status = $artworks[$artlogic_id]['import_status'];
					$image_status = $artworks[$artlogic_id]['image_status'];
					$bytes = 0;

					// This flags items are not in need of updating.
					$item_has_updates = (	$import_status == 'new' ||
													$import_status == 'updated' ||
													$this->force_download );
					$image_has_updates = (	$image_status == 'image_new' ||
													$this->force_download );

					// This flag skips images that were done on a previous request.
					$start_here = ($loop_ct >= $download_cursor);

					// The cursor count increases whether they were downloaded or not.
					// It tells us where to begin downloads on the next page load.
					$loop_ct++;

					$info = 'orig_attach_id: '.$artworks[$artlogic_id]['id'].' item_has_updates:'.$item_has_updates
						.' image_has_updates:'.$image_has_updates.' status:'.$import_status.' '.$image_status;
					//if($cron) $this->cron_log($info);

					if ( ($item_has_updates || $image_has_updates) && $start_here) {

						$descr = $this->format_description($row);
						$orig_attach_id = $artworks[$artlogic_id] ? $artworks[$artlogic_id]['id'] : 0;

						$info = 'item:'.$artlogic_id.' orig_attach_id: '.$artworks[$artlogic_id]['id'].' status:'.$import_status.' '.$image_status;
						//if($cron) $this->cron_log($info);

						if( $image_has_updates ) {

							// Delete any existing attachment.
							if( $orig_attach_id ) {
								$delete = wp_delete_attachment($orig_attach_id, true);
								$info = 'deleted: '.$orig_attach_id.' del status:'. (is_object($delete)?'true':'false');
								//if($cron) $this->cron_log($info);
							}

							// Download and save the new image as a post attachment.
							$attach = $this->attach_image (	$artist_id,
																		$row['img_url'],
																		$descr['title'],
																		$descr['description']
																		);
							$attach_id = $attach['id'];

							if($attach_id) {

								array_push($cronlog['new'],$attach_id);

								$attach_meta = get_post_meta($attach_id, '_wp_attachment_metadata', true);
								if($attach_meta){

									$source_file = $attach_meta['file'];

									// If the original attachment has been deleted from the Media Library the source file will be missing.
									$exists = file_exists($uploads_path.$source_file);
									if( $exists ){
										$bytes = filesize( $uploads_path.$source_file );
										$thumbnails .= $this->get_thumbnail( $attach_meta, $uploads_path, $bytes );
										$req_download_bytes += $bytes;
									}
									else if(!$cron){
										$thumbnails .= '<div class="thumbnail" style="width:50px;">'
											.'<img data-src="'.$source_file.'" data_url="'.$row['img_url'].'" '
											.'src="'.$this->plugin_path.'/images/thumbnail-placeholder-deleted-100.png"></div>';
									}
								}

								// Save the new item details: title, descr, dimentsion, SOLD, etc
								$this->update_attachment_meta($attach_id, $row, $descr);
								$artworks[$artlogic_id]['id'] = $attach_id;
							}
						}
						else {

							array_push($cronlog['updated'], $orig_attach_id);

							// Get the current image file size and thumbnail.
							$meta = get_post_meta($orig_attach_id, '_wp_attached_file', true);
							$source_file = $meta['meta-value'];
							$exists = file_exists($uploads_path.$source_file);
							if( $exists ){
								$bytes = filesize( $uploads_path.$source_file );
								if(!$cron) $thumbnails .= $this->get_thumbnail( $attach_meta, $uploads_path, $bytes );
							}
							else if(!$cron) {
								$thumbnails .= '<div class="thumbnail" style="width:50px;">'
									.'<img data-src="'.$source_file.'" data_url="'.$row['img_url'].'" '
									.'src="'.$this->plugin_path.'/images/thumbnail-placeholder-deleted-100.png"></div>';
							}
						}

						// Reset the item status.

						$artworks[$artlogic_id]['import_status'] = '';
						$artworks[$artlogic_id]['image_status'] = '';
						// print 'stat: '.print_r($artworks[$artlogic_id],true).'<br>';

						$ct_items_processed++;
						if ($import_status=='new') $ct_new++;
						else $ct_updated++;

					} // END if ($item_has_updates...

					// Break up the job here if it is running too long.
					$runtime_sec = time() - $start_time;
					$is_overtime = ($runtime_sec > $this->config['max_cron_runtime_seconds']);
					$is_oversize = ($req_download_bytes > $this->max_bytes_per_request);

					if ( $is_overtime || $is_oversize ) {

						$info = 'BREAK - is_overtime:'.$is_overtime.' is_oversize:'.$is_oversize.' loop_ct:'.$loop_ct;
						//if($cron) $this->cron_log($info);

						$this->download_cursor = $loop_ct; // Admin form uses this for it's 'download continue' function.
						update_option($this->wp_db_prefix.'download_cursor', $loop_ct);

						if(!$cron){
							$this->reload = true;
							$force_download = $this->force_download ? '&force_download=true' : '';
							$data_is_current = '&data_is_current=true';
							$reload_url = $this->plugin_url.'&artist_id='. $artist_id. $force_download. $data_is_current;
							$str_message = '<p class="center max-mb show">'
								.'<span class="alert">Downloads exceeded the '.$this->max_mb.' per page limit.</span><br>'
								.'<a href="'.$reload_url.'" class="reload">Click here to continue.</a>'
								.'</p>';
						}

						// Stop both loops here. Downloads will continue on another page load.
						break;
					}
					else {
						$this->download_cursor = 0;
						update_option($this->wp_db_prefix.'download_cursor', 0);
						$str_message = '<h2 class="center">Update Complete</h2>';
					}

				} // END foreach($artlogic_items...

				if($ct_items_processed) {

					$this->update_display_order( $artist_id, $orig_artworks, $artworks );
					update_post_meta( $artist_id, 'artworks', $artworks );

					if($cron){
						$new = implode(',',$cronlog['new']);
						$upd = implode(',',$cronlog['updated']);
						$del = implode(',',$cronlog['deleted']);
						$entry = [
							( $artist_name.'('.$artist_id.')' ),
							('new:'. ($new?$new:0) ),
							('upd:'. ($upd?$upd:0) ),
							('del:'. ($del?$del:0) ),
							( $this->pretty_filesize($req_download_bytes) )
							];
						$this->cron_log( implode(CHR(9),$entry) );
					}

					// Running tally of updates.
					$stats[$artist_id]['new'] = ($stats[$artist_id]['new']-$ct_new);
					$stats[$artist_id]['updated'] = ($stats[$artist_id]['updated']-$ct_updated);
					$stats[$artist_id]['deleted'] = ($stats[$artist_id]['deleted']-$ct_deleted);

					// Return thumbnails of updated works.
					array_push($resp,$thumbnails);
				}
				else {
					$str_msg = $this->download_cursor == 0 ? 'Update complete.' : '';
					array_push($resp,$str_msg);
					$this->reload = false;
					$this->force_download = false;
				}

				if(!$cron){
					// Format the html response.
					$links = $this->artist_toolbar_links(	$artist_id,
																		$artlogic_artist_id,
																		$post->post_type,
																		$post->post_name );

					$resp_block = '<div>'. implode('</div><div>',$resp) .'</div>';
					$resp_html .= '<header><span class="col-left"><strong>'.$artist_name.'</strong></span>'
						.'<span class="col-right">'.$links.'</span></header><section>'.$resp_block.'</section>';
				}
			}

		} // END foreach($artist...

		if(!$cron){

			$this->artist_stats = $stats;
			$str_response = '<div class="result-grid">'.$resp_html.'</div>';

			return [	'str_response' => $str_response,
						'str_message' => $str_message
						];
		}
	}

}

