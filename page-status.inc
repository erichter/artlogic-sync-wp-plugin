<?php

	$artist_stats = $artlogic->get_artist_stats();
	$last_update = $artlogic->get_last_update();

	$html = '';
	$has_incompletes = false;
	if($artist_stats) {
		foreach ($artist_stats as $idx => $artist) {

			$stat = '';
			$separator = '';

			$new = array_key_exists('new',$artist) ? $artist['new'] :'';
			$image_new = array_key_exists('image_new',$artist) ? $artist['image_new'] :'';
			$updated = array_key_exists('updated',$artist) ? $artist['updated'] :'';
			$image_updated = array_key_exists('image_updated',$artist) ? $artist['image_updated'] :'';
			$incomplete = array_key_exists('incomplete',$artist) ? $artist['incomplete'] :'';
			$deleted = array_key_exists('deleted',$artist) ? $artist['deleted'] :'';
			$displayed = array_key_exists('displayed',$artist) ? $artist['displayed'] :'';

			$has_updates = ($new || $image_new || $updated || $image_updated || $deleted);
			$color = $has_updates ? ' dk-blue' : ' grey';
			$incomplete_asterisk = '';
			$incomplete_tip = '';
			if($has_updates || $incomplete){
				$deleted = $artist['deleted'];
				if( $new ) $stat .= $new. ' new';
				if( $updated ) {
					if( strlen($stat) ) $stat .= ', ';
					$stat .= $artist['updated']. ' update';
				}
				if( $deleted ) {
					if( strlen($stat) ) $stat .= ', ';
					$stat .= '<span class="grey">'.$deleted.' delete</span>';
				}
				if( $incomplete ) {
					$sPlural = $incomplete>1?'s':'';
					$is_are = $incomplete>1?'are':'is';
					$incomplete_asterisk = '<span class="asterisk-incomplete">*</span>';
					$incomplete_tip = $incomplete.' incomplete item'.$sPlural;
					$has_incompletes = true;
				}
			}
			if( strlen($stat) ) $stat .= ', ';
			$stat .= $displayed;
	
			$links = $artlogic->artist_toolbar_links(	$artist['id'],
																	$artist['artlogic_artist_id'],
																	$artist['post_type'],
																	$artist['post_name'] );
	
			$update_url = $artlogic->plugin_url.'&preload='.$artist['id'];
			$html .= '<div class="grid-row" id="artist-'.$artist['id'].'"><span class="col-left links'.$color.'">'
				.'<a href="'.$update_url.'" class="name" title="'.$incomplete_tip.'"><strong>'
				.$artist['name'].$incomplete_asterisk. '</strong></a></span>'
				.'<span class="col-right"><span class="stat">'.$stat.'</span> '.$links.'</div>'.CHR(10);
		}
	}
?>
<div id="artlogic" class="page-status">

	<div class="toolbar"></div>

	<span class="artlogic-logo">
		<img src="<?=$artlogic->plugin_path;?>/images/artlogic_logo.svg" class="svg" alt="Art Logic Logo"/>
	</span>

	<div class="response-box">
		<form method="post" name="artlogic">
			<input type="hidden" name="json_url" value="<?=$artlogic->json_url; ?>">
			<input type="hidden" name="data_is_current" value="<?= ($artlogic->data_is_current ? 'true' : 'false'); ?>">
			<input type="hidden" name="cron_schedule_active" value="<?=$artlogic->cron_schedule_active?'true':'false'; ?>">
			<button type="submit" name="button_refresh" class="btn-small">Refresh Data</button>
		</form>
		<h1>Sync Status</h1>
		<h5>
			<?php if(strlen($last_update)): ?>
				<div id="last-update">ArtLogic data was last refreshed on: <span class="date"><?=$last_update;?></span></div>
			<?php endif; ?>
			Click on names to process updates. Items shown in grey are current.
		</h5>
		<div id="progressbar">
			<div class="progress-label">Checking for new data&hellip;</div>
		</div>
		<div class="result-grid sync-status odd-even"><?=$html;?></div>
		<?php if($has_incompletes): ?>
		<h5>
			* One or more items at ArtLogic don't include the minimum detail for website display.
			See <a href="<?=$_SERVER['SCRIPT_NAME'].'?page=artlogic-help-page';?>#sync_status">Help</a> for more information.
		</h5>
		<?php endif; ?>
	</div>
</div>

