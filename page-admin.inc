<?php
	$data = $artlogic->admin_page();
	$last_update = $artlogic->get_last_update();
?>
<div id="artlogic" class="center page-admin">
	<form method="post" name="artlogic">
		<input type="hidden" name="json_url" value="<?=$artlogic->json_url; ?>">
		<input type="hidden" name="data_is_current" value="<?= ($artlogic->data_is_current ? 'true' : 'false'); ?>">
		<input type="hidden" name="page_loaded" value="true">
		<input type="hidden" name="download_cursor" value="<?=$artlogic->download_cursor; ?>">
		<div style="display:none;" id="artist_stats"><?=json_encode($artlogic->artist_stats);?></div>

		<div class="toolbar">
			<div class="cron-schedule-toggle">
				<?php
					if($artlogic->cron_schedule_name) $label = 'Scheduled Sync';
					else $label = '<a href="' .$artogic->plugin_path. '?page=artlogic-help-page#schedule" '
						.'title="Click here for help with scheduling.">Scheduled Sync is not setup.</a>';
				?>
				<label for="sync-running" class="msg"><?=$label;?></label>
				<input type="checkbox" name="cron_schedule_active" value="true"
					<?=(!strlen($artlogic->cron_schedule_name)?' disabled':'');?>
					<?=($artlogic->cron_schedule_active==true?' checked':'');?>>
			</div>
		</div>

		<span class="artlogic-logo">
			<img src="<?=$artlogic->plugin_path;?>/images/artlogic_logo.svg" class="svg" alt="Art Logic Logo"/>
		</span>

		<div class="stats">
			<h4>
				<?php if(strlen($last_update)): ?>
					<div id="last-update">ArtLogic data was last refreshed on: <span class="date"><?=$last_update;?></span></div>
				<?php endif; ?>
				<?php if(strlen($artlogic->cron_schedule_name) && $artlogic->cron_schedule_active): ?>
					<div>Sync is scheduled to run <em><?= $artlogic->cron_schedule_name;?></em>.</div>
				<?php endif; ?>
			</h4>
			<h4>
				To update an artist's works now choose a name and click <em>update</em>.<br>
				Images are downloaded in batches of up to <?=$artlogic->max_mb;?> per page.
				<?php if(!$artlogic->cron_schedule_active): ?>
				<p class="cron-schedule-active-msg">
					The scheduler is is temporarily turned off but you can still push manual updates here.<br>
				</p>
				<?php endif; ?>
			</h4>
		</div>
		<div id="progressbar">
			<div class="progress-label">Checking for new data&hellip;</div>
		</div>
		<div class="form-block">
			<div class="form-container">
				<div id="artist-select">
					<select name="artist_id" >
						<option disabled selected value="0">select an artist</option>
						<?php

							// External links use preload to pre-select an artist in the select menu.
							$preload = isset($_REQUEST['preload']) ? $_REQUEST['preload'] : '';

							$stats = $artlogic->artist_stats;
							foreach($stats as $idx => $artist) {
								$update_ct = strval( $artist['new'] + $artist['updated'] ); // + $artist['deleted']
								$display_ct = strval($artist['displayed']);
								$sel = ($artlogic->artist_id==$artist['id'] && $artlogic->reload==true) || $preload==$artist['id'];
								$selected = $sel ? ' selected' : '';
								?>
								<option value="<?=$artist['id'];?>"<?=$selected;?>
									data-display-ct="<?=$display_ct;?>" data-update-ct="<?=$update_ct;?>"><?=$artist['name'];?></option>
						<?php } ?>
					</select>
					<button type="submit" name="artist_submit" value="update_artist">Update</button>
					<div class="checkbox-download" title="Check here to refresh all images for this artist.">
						Refresh all images
						<input type="checkbox" name="force_download" value="true"
							<?= ($artlogic->force_download==true && $artlogic->reload==true) ? ' checked' : ''; ?>
							title="Check here to refresh all images for this artist"> 
					</div>
				</div>
			</div>
		</div>

		<?php if($artlogic->download_cursor > 0){ ?>
			<div id="reload-confirm" data-delay-sec="5">
				Downloads exceeded the <?=$artlogic->max_mb;?> per page limit.<br>
				Sync will automatically continue in <span class="delay">5 seconds</span>.<br>
				<button type="button" class="reload-cancel">Cancel</button>
				<button type="button" class="reload-continue">Continue</button>
			</div>
		<?php } ?>

		<?php if($data && ($data['str_message'] || $data['str_response'])): ?>
			<div class="response-box">
				<?php if($data['str_message']) print $data['str_message']; ?>
				<?php if($data['str_response']) print $data['str_response']; ?>
			<div>
		<?php endif; ?>

		</div>
	</form>
</div>
