<div id="artlogic" class="page-help">

	<h1>ArtLogic Sync</h1>

	<p class="summary">
		<strong>ArtLogic Sync</strong> is a custom plugin that synchronises images from an ArtLogic Database
		account to WordPress. It downloads and imports image files and data. Image formatting and display are
		not managed by the plugin. The WordPress theme and gallery plugins does the presentation work. The sync
		function can be scheduled to run automatically at specified intervals and it can also be run on demand
		when immediate updates are needed.
	</p>
	<p>
		<a href="#sync_artist">Updating an Artist</a><br>
		<a href="#sync_all">Updating All Artists</a><br>
		<a href="#new_artist">Adding a New Artist</a><br>
		<a href="#sync_postpone">Postponing Updates</a><br>
		<a href="#schedule">Managing Sync Schedules</a><br>
		<a href="#sync_status">Sync Status Page</a><br>
		<a href="#tips">Best Practices</a><br>
		<a href="#advanced">Advanced Settings</a><br>
	</p>
	<hr>

	<a name="sync_artist" class="anchor"></a>
	<h2>Updating an Artist</h2>
	<p>
		The Artlogic Sync plugin runs updates on a schedule (i.e. every 10 minutes), but you can also update a
		specific artist at any time on the main <em>ArtLogic Sync</em> page. When you click update the plugin
		refreshes ArtLogic data and downloads new or updated images for the selected artist. Changes appear
		immediately on the website.
	</p>

	<a name="new_artist" class="anchor"></a>
	<h2>Adding a New Artist</h2>
	<p>
		Artist posts are linked to ArtLogic via a unique ID provided by ArtLogic. To link a new artist:
	</p>
	<ol>
		<li>Go to <a href="<?=get_admin_url()?>edit.php?post_type=artist" target="_blank">
				<strong>Artists > Add New</strong></a> and create a new post.</li>
		<li>Go to <a href="<?=get_admin_url()?>admin.php?page=artlogic-plugin">
				<strong>ArtLogic Sync</strong></a>, choose the artist's name from the drop-down and click <em>Update</em>.</li>
	</ol>
	<p>
		<span>Important:</span> The first time an artist is synced the spelling of the artist's name (title field) on their 
		<em>WordPress</em> post must match the spelling of their record at ArtLogic. Once the first update
		has been done the two accounts are linked by a hidden ID number that never changes and you can update
		the name spelling on either account any time.
	</p>


	<a name="sync_all" class="anchor"></a>
	<h2>Updating all Artists</h2>
	<p>
		Artists' works are updated automatically via WordPress Cron. The Artlogic Sync plugin imports
		images and image details that have been altered on the
		<a href="http://ArtLogic.net" target="_blank">ArtLogic.net</a> account.
		In order for this feature to work the ArtLogic Sync task must be set up as a Cron Event in WordPress.
		See <a href="#schedule">Managing Sync Schedules</a> below for help setting up this WordPress schedule.
	</p>

	<a name="sync_postpone" class="anchor"></a>
	<h2>Postponing Updates</h2>
	<p>
		There are two ways to postpone the automatic sync schedule.
	</p>

	<h4>Pause Syncing indefinitely:</h4>
	<p>
		Simply click the <strong>Scheduled Sync</strong> button
		<span class="ui-switcher dummy-button"></span>
		in the upper right corner of the plugin page. 
		Sync will not run again until you turn it back on.
	</p>

	<h4>Set Sync to resume at a specific time:</h4>
	<p>
		Automatic updates can be postponed until a specific date simply by setting a future date for the WordPress cron event.
	</p>

	<blockquote><em>
		Example:<br>
		I just uploaded some new works on ArtLogic.net but I don't want them to show up on the website until the
		artist's reception on Thursday. But sync is going to run automatically tonight and show them anyway. I go to
		WordPress cron and set a <em>Next Run</em> date for Thursday morning. Sync will be on hold and resume it's
		normal schedule starting on Friday morning.
	</em></blockquote>
	<ol>
		<li>Go to <a href="<?=get_admin_url()?>tools.php?page=crontrol_admin_manage_page" target="_blank">
				<strong>Tools > Cron Events</strong></a></li>
		<li>Choose <em><?=$artlogic->config['cron_hook']?></em> from the list of scheduled tasks and click <em>Edit</em> on the right.</li>
		<li>In the field named <em>Next Run</em> enter a date in the format YYYY-MM-DD HH:MM:SS.</li>
		<li>If the date format is entered incorrectly the task simply continue on it's normal schedule.</li>
		<li>This date is set for your timezone. Time must be entered in 24 hour format (eg: 21:00 for 9:00 pm).</li>
		<li>Do not change the <em>Hook Name</em>. This is how WordPress knows which task to run.</li>
		<li>Leave the <em>Arguments</em> field blank. The ArtLogic Sync plugin doesn't take any arguments.</li>
		<li>Leave the <em>Recurrence</em> schedule as is. You don't want to change the entire schedule, only postpone the next run.</li>
		<li>Click <em>Modify Cron Event</em> to save.</li>
		<li>When the page refreshes check to confirm that <em>Next Run</em> is set for the date and time desired.</li>
	</ol>

	<a name="schedule" class="anchor"></a>
	<h2>Managing Sync Schedules</h2>

	<?php if( !strlen($artlogic->config['cron_hook']) ):?>
		<p class="alert">
		The ArtLogic Sync plugin is not properly configured to run as a WordPress cron event.<br>
		See <a href="#advanced">Advanced Settings</a> for troubleshooting help.<br>
		</p>
	<?php elseif( strlen($artlogic->cron_schedule_name) ): ?>
		<p>
		Sync is currently scheduled to run <em><?= $artlogic->cron_schedule_name; ?></em>.
		</p>
	<?php endif; ?>

	<a name="create_schedule"></a>
	<h4>Edit the Update Schedule</h4>
	<ol class="<?= (!strlen($artlogic->config['cron_hook']) ? ' text-disabled' : ''); ?>">
		<li>Go to <a href="<?=get_admin_url()?>tools.php?page=crontrol_admin_manage_page" target="_blank">
			<strong>Tools > Cron Events</strong></a></li>
		<li>Choose <em><?=$artlogic->config['cron_hook']?></em> from the list of scheduled tasks and click <em>Edit</em> on the right.</li>
		<li>Choose a new <em>Recurrence</em> inerval from the drop-down option at the bottom (i.e. daily, monthly, etc).</li>
		<li>Do not change the <em>Hook Name</em>. This is how WordPress knows which task to run.</li>
		<li>Leave the <em>Arguments</em> field blank. The ArtLogic Sync plugin doesn't take any arguments.</li>
		<li>If you don't want this sync to run right away change the <em>Next Run</em> setting.</li>
		<li>Click <em>Modify Cron Event</em> to save.</li>
	</ol>

	<a name="create_schedule"><br></a>
	<h4>Set up a new Schedule</h4>
	<ol class="<?= (!strlen($artlogic->config['cron_hook']) ? ' text-disabled' : ''); ?>">
		<li>Go to <a href="<?=get_admin_url()?>tools.php?page=crontrol_admin_manage_page" target="_blank">
			<strong>Tools > Cron Events</strong></a></li>
		<li>Go to the <em>Add Cron Event</em> tab halfway down the page.</li>
		<li>Choose a <em>Recurrence</em> interval from the drop-down option at the bottom (i.e. daily, monthly, etc).</li>
		<li>In the <em>Hook Name</em> field, type <strong class="monospace hilite"><?=$artlogic->config['cron_hook']?></strong>.
			This tells WordPress which task to run.</li>
		<li>Leave the <em>Arguments</em> field blank. The ArtLogic Sync plugin doesn't take any arguments.</li>
		<li>If you don't want this sync to run right away change the <em>Next Run</em> setting.</li>
		<li>Click <em>Modify Cron Event</em> to save.</li>
		<li>When the page refreshes check to confirm that the task is set to run at the times desired.</li>
	</ol>

	<a name="sync_status" class="anchor"></a>
	<h2>Sync Status Page</h2>
	<p>
		This page shows which artists have updates pending: new, changed, or incomplete.
		<em>Incomplete</em>, noted by an asterisk beside the artist's name, indicates that one or more items
		at ArtLogic don't meet the minimum website display requirements. To remedy this edit the record
		at Artlogic and include the following fields:
	</p>
	<ul>
		<li>Artist's name</li>
		<li>Title of work</li>
		<li>Description, Medium, Dimensions, or Sold</li>
		<li>Image or Video URL</li>
	</ul>


	<a name="tips" class="anchor"></a>
	<h2>Best Practices</h2>
	<ol class="<?= (!strlen($artlogic->config['cron_hook']) ? ' text-disabled' : ''); ?>">
		<li>Let the plugin manage the galleries. Any changes you make to the description or title in the 
			page editor will be overwritten at the next sync. The only exception to this is the sort order
			which will be preserved during sync.</li>
		<li>Avoid uploading files larger than 1920x1080 (HD resolution). Anything larger incurs unnecessary
			server and storage overhead. Remember, the downloaded file is only a source file that never gets
			viewed on the live website. WordPress automatically creates optimized versions in several sizes 
			for web display.</li>
		<li>Use JPGs rather than PNGs. The PNG format is great for archive images because it is not
			considered 'lossy' compression, but the file size is much larger than even the highest quality
			JPG settings in PhotoShop. 80-90% JPG quality will render a clean image at an acceptable web file size.</li>
	</ol>


	<a name="advanced" class="anchor"></a>
	<div class="accordion">
		<h3>Advanced Settings</h3>
		<div>
			<ul>
				<li>
					<strong>Time Zone:</strong><br>
					See: www.php.net/manual/en/timezones.php for details on this PHP setting.<br>
					Example: America/Los_Angeles<br>
					Current setting: <?=$artlogic->config['timezone_str'];?>
				</li>
				<li>
					<strong>Artlogic Home Url:</strong><br>
					URL to ArtLogic.net, used to determine network connectivity.<br>
					Example: www.artlogic.net<br>
					Current setting: <?=$artlogic->config['artlogic_home_url'];?>
				</li>
				<li>
					<strong>Feed Url:</strong><br>
					This RSS feed is specific to each <a href="http://ArtLogic.net" target="_blank">ArtLogic.net</a> account.
					It tells the plugin where to download ArtLogic data.<br>
					Example: http://feeds.artlogic.net/hosfeltgallery/websites/2.0/artworks/json<br>
					Current setting: <?=$artlogic->config['feed_url'];?>
				</li>
				<li>
					<strong>ArtLogic Artist's Page Base URL:</strong><br>
					Appending a valid ArtLogic artist_id to this URL loads their image collection in ArtLogic.<br>
					Example: https://app.artlogic.net/hosfeltgallery/records/artworks/artist/<br>
					Current setting: <?=$artlogic->config['artlogic_artist_page_base_url'];?>
				</li>
				<li>
					<strong>Cron Event Hook:</strong><br>
					This tells the plugin the name of the WordPress cron event used to schedule syncing.<br>
					Is must match the <em>Hook Name</em> used on the WordPress WP-Cron Events page.<br>
					Example: artlogic_sync<br>
					Current setting: <?=$artlogic->config['cron_hook'];?>
				</li>
				<li>
					<strong>Max MB Download Per Request:</strong><br>
					This sets a limit on how much data can be downloaded in one request.
					This should be no more than 20MB to prevent server slowness or timeouts.<br>
					Example: 10<br>
					Current setting: <?=$artlogic->config['max_mb_per_request'];?>
				</li>
				<li>
					<strong>Max Cron Runtime in Seconds:</strong><br>
					Another throttle similar to the one above, sets a time limit on the request.
					This should be no more than 120 seconds to prevent server timeouts.<br>
					Example: 90<br>
					Current setting: <?=$artlogic->config['max_cron_runtime_seconds'];?>
				</li>
				<li>
					<strong>Cache Expiration Minutes for Manual Updates:</strong><br>
					The time in minutes before reimporting fresh Artlogic data when doing  manual updates.
					This is simply a convenience feature to prevent the sync admin page from redundantly downloading
					new data every time the admin page loads.<br>
					Example: 60<br>
					Current setting: <?=$artlogic->config['cache_expiration_minutes_manual'];?>
				</li>
				<li>
					<strong>Cache Expiration Minutes for Manual Updates:</strong><br>
					The time in minutes before reimporting fresh Artlogic data.
					This is simply a convenience feature to prevent the sync admin page from redundantly downloading
					new data every time the Artlogic Sync loads.<br>
					Example: 60<br>
					Current setting: <?=$artlogic->config['cache_expiration_minutes_manual'];?>
				</li>
				<li>
					<strong>Cache Expiration Minutes for Cron:</strong><br>
					Same as above but for cron jobs, which benefit from a longer interval between refresh.<br>
					Example: 120<br>
					Current setting: <?=$artlogic->config['cache_expiration_minutes_cron'];?>
				</li>
				<li>
					<strong>Cron Log Lifespan in Days:</strong><br>
					The number of days to keep plugin cron logs on the server.
					The plugin logs each cron run for the purposes of error checking.
					A new log is created each month. Logs older than this timespan are automatically
					deleted to prevent them from accumulating on the server.<br>
					Example: 180<br>
					Current setting: <?=$artlogic->config['cron_log_lifespan_days'];?>
				</li>
				<li>
					<strong>Sort Field:</strong><br>
					This tells the plugin the name of the <em>Advanced Custom Field</em> being used by the WordPress theme to
					store the image sort order. Is must be a valid WordPress ACF field slug. Without this field name ArtLogic Sync
					cannot maintain the sort order created in the WordPress Artists' pages.<br>
					Example: artist_works<br>
					Current setting: <?=$artlogic->config['acf_artist_sort_field'];?>
				</li>
				<li>
					<strong>Fields to Import:</strong><br>
					A comma separated list of ArtLogic fields.<br>
					Rather than save all ArtLogic data to the WordPress database, we can limit import to only the fields we need
					for theming. The plugin automatically includes fields required for its internal automation but any
					other fields needed, for example by a new theme function, can be added here.<br>
					Example: preview_image, _title, artist, artist_id, artist_sort_title_year, artist_title_year&hellip;<br>
					Current setting: <?=$artlogic->config['fields_to_import'];?>
				</li>
				<li>
					<strong>Debug Mode:</strong><br>
					This setting is for DEV use only. It allows the plugin to run in dev mode and use
					local temp data instead of live ArtLogic data.<br>
					Example: false<br>
					Current setting: <?=($artlogic->config['debug_mode']?'true':'false');?>
				</li>
			</ul>
			<p>
				Advanced settings for this plugin cannot be updated via the WordPress admin.
				To make changes you must have server file system access to this file:<br>
				<blockquote><?=$artlogic->plugin_path; ?>/artlogic.ini</blockquote>
			</p>
			<p>
				<strong>View scheduled update history:</strong><br>
				<blockquote><?=$artlogic->list_cron_logs()?></blockquote>
			</p>
		</div>
	</div>

</div>
