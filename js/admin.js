
jQuery(document).ready(function($){

	// Requires progressBar
	$.fn.artlogicDownloader = function(){

		let
		artlogic = $(this),
		artlogicForm = null,

		// Admin page
		artistSelect = null,
		downloadCursor = null,
		cronToggle = true,
		cronToggleInput = null,
		cronToggleMsg = null,
		btnArtistSubmit = null,
		forceRefresh = null,
		dataIsCurrent = null,
		downloadTimer = 0,

		// Admin and Sync page
		artistStats = null,
		artistStatsArr = [],
		lastUpdate = '',
		pluginUrl = '',
		progBar = null,
		progLabel = null,
		btnRefresh = null,
		progLabelDefault = '',
		progTimer = 0,
		progBarRunning = false,
		downloadRunning = false,
		currPage = 0,
		totPages = 0,

		refreshData = function(refresh=0){

			artistID = getArtistId();
			progLabel.html(progLabelDefault);
			resetProgressBar();
			currPage = 0;

			if(dataIsCurrent.val() == 'false' || refresh){

				// Start polling WP to find out where we are in this download.
				progTimer = setTimeout( function(){ checkDownloadProgress(); }, 1000);
				progBar.addClass('showbar');
console.log('refreshData')
				// Submit the sync request
				$.getJSON( pluginUrl, { 'json': true, 'manual_refresh': (refresh?true:false) } )
					.done(function(data) {
console.log('refreshData DONE')
						if(data.artist_stats) {
							lastUpdate = data.last_update;
							artistStatsArr = data.artist_stats;
							dataIsCurrent.val('true');
							if(currPage==-1 && progBarRunning) {
								setProgressBar(100);
							}
						}
					})
					.fail(function( jqxhr, textStatus, error ) {
						var err = textStatus + ', ' + error;
						progLabel.html('An internal plug-in error occurred. Check the ArtLogic plugin config.ini settings for errors.');
						clearTimeout(progTimer);
					});
			}
			else {
				downloadImages(artistID);
			}
		},

		checkDownloadProgress = function(delay){

			let currPage, totPages, segmentSize, targetVal;
			progBarRunning = true;

			// If there is no response for 30 seconds cancel the task.
			failsafeTimer = setTimeout( resetProgressBar, 30000);

			$.getJSON( pluginUrl, { 'json':true, 'get_cursor':true } )
				.done(function(data) {
					if(data && data.no_of_pages >= data.cursor){

						// In the first few calls data.cursor will be zero because refreshData() hasn't
						// had enough time to process anything. Rather than spin at zero and then jump to 
						// page 2 set it to page 1 to start.
						currPage = data.cursor==0?1:data.cursor;
						if(currPage != -1){
							segmentSize = getFloat(100/data.no_of_pages);
  							targetVal = getFloat(currPage*segmentSize);
							if(progBarRunning){
								setProgressBar(targetVal);
								dataIsCurrent.val('true');
								progTimer = setTimeout( checkDownloadProgress, 500);
							}
						}
						else if(progBarRunning && dataIsCurrent.val('true')) {
							setProgressBar(100);
						}
						if(currPage>1) clearTimeout(failsafeTimer);
						console.log( data.no_of_pages+' >= '+data.cursor);
					}
				})
				.fail(function( jqxhr, textStatus, error ) {
					progLabel.html(textStatus+' '+error);
					progBar.addClass('artlogic-error artlogic-progbar-auto-height');
				});
		},

		setProgressBar = function(targetVal,delay) {
			if(!progBarRunning) return;
      	let val = progBar.progressbar('value') || 0;
			delay = delay>0?delay:40;
			progBar.progressbar('value', val+1);
			progLabel.html( progBar.progressbar('value') + '%' );
			if (val < targetVal-1) setTimeout( function(){ setProgressBar(targetVal) }, delay);
		},

		reloadShowProgressBar = function(){
			progBar.progressbar({ value: false });
			progBar.addClass('showbar');
			progLabel.html('Updating Images');
		},

		stopProgressBar = function(){
			progBarRunning = false;
			clearTimeout(progTimer);
			clearTimeout(downloadTimer);
		},

		resetProgressBar = function(){
			progBarRunning = false;
			downloadRunning = false;
			clearTimeout(progTimer);
			clearTimeout(downloadTimer);
			stopProgressBar();
			progBar.removeClass('showbar');
			progBar.progressbar({ value: false });
			if(btnRefresh) btnRefresh.prop('disabled',false);
		},

		progBarComplete = function(){
			stopProgressBar();
			if(btnArtistSubmit) btnArtistSubmit.prop('disabled',false);
			if(btnRefresh) btnRefresh.prop('disabled',false);
			if(artistID>0){
				dataIsCurrent.val('true');
				setTimeout(function(){ downloadImages(artistID); },1000);
			}
			else {
				setTimeout(function(){ progLabel.html( 'Import Complete' )} ,1);
				setTimeout(resetProgressBar,1000);
				repopulateStats();
			}
		},

		// status page
		repopulateStats = function(){

			// lastUpdate is returned by refreshData when successful.
			$('#last-update .date').text(lastUpdate);

			let lines, stat, separator, line, selector, id, artist, has_updates, ctNew, ctUpdated, ctDeleted;
			lines =	$('.grid-row');
			lines.each(function(i,o){

				line = lines.eq(i);
				selector = line.attr('id');
				id = selector.replace('artist-','');
				artist = artistStatsArr[id];

				if(artist){
					stat = '';
					ctNew = parseInt(artist.new,10);
					ctUpdated = parseInt(artist.updated,10);
					ctDeleted = parseInt(artist.deleted,10);
					ctIncomplete = parseInt(artist.incomplete,10);
					has_updates = (ctNew || ctUpdated || ctDeleted || ctIncomplete);

					if(has_updates){
						if( ctNew > 0 ) stat += ctNew + ' new';
						if( ctUpdated > 0 ) {
							if( stat.length ) stat += ', ';
							stat += ctUpdated + ' update';
						}
						if( ctDeleted > 0 ) {
							if( stat.length ) stat += ', ';
							stat += ctDeleted + ' delete';
						}
						if( ctIncomplete > 0 ) {
							sPlural = ctIncomplete>1?'s':'';
							is_are = ctIncomplete>1?'are':'is';
							incomplete_asterisk = '<span class="asterisk-incomplete">*</span>';
							incomplete_tip = ctIncomplete+'Incomplete item'+sPlural;
						}
					}
					if( stat.length ) stat += ', ';
					stat += artist.displayed + ' ';
					line.find('.stat').html(stat);
				}
			});
		},

		// Restart the progress bar but without the bar, just let it spin until the page refreshes.
		downloadImages = function(artistID){

			if(!artistID) {
				resetProgressBar();
				return;
			}
			downloadRunning = true;
			clearTimeout(progTimer);

			let refresh = forceRefresh.prop('checked'),
				delay = (dataIsCurrent.val() == 'false') ? 1500 : 100,
				stats = artistStatsArr[artistID],
				imageCt = '',
				sPlural = '',
				label = '';

			if(stats) {
				if(refresh) imageCt = stats.all;
				else imageCt = stats.new + stats.updated - stats.incomplete;
			}
			if(imageCt==0 || imageCt=='') label = 'Updating Images';
			else label = 'Updating '+imageCt+' Images';

			downloadTimer = setTimeout( function(){
				progBar.progressbar({ value: false });
				progBar.addClass('showbar');
				progLabel.html(label);
				artlogicForm.submit();
			}, delay);

		},

		getArtistId = function(){
			if(!artistSelect) return 0;
			let artistOpt = artistSelect.find(':selected');
			return artistOpt ? parseInt(artistOpt.val()) : 0;
		},

		setArtistStatsData = function(){
			artistStats = artlogicForm.find('#artist_stats');
			with(artistStats) if(length && text()){
				obj = JSON.parse(text());
				artistStatsArr = obj.artist_stats ? obj.artist_stats : [];
			}
			else artistStats = [];
		},

		getFloat = function(num){
			return num.toPrecision(4)*1;
		},

		// Toggle sync scheduler on/off.
		toggleSync = function(){

			if(!cronToggle.length) return false;
			let txt = cronToggleMsg.html(),
				errMsg = '<span title="Script Error: Unable to change Scheduled Sync setting.">'
					+'Error: '+cronToggleMsg.data('origMsg')+'</span>',
				cronToggleValue = (cronToggleInput.prop('checked')? true: false),
				artistSelectDiv = $('.artist-select');

			// Show/hide the admin form.
			// PHP won't fire if the scheduler is off anyway but this makes the interface more usable.
			if(cronToggleValue) {
				msg = cronToggleMsg.data('origMsg');
				cronToggleMsg.html(msg);
			}
			else {
				msg = cronToggleMsg.data('origMsg');
				cronToggleMsg.html(msg);
			}

			// Disable the switcher toggle if the AJAX call below fails for any reason.
			toggleErr = function(msg,className){
				cronToggleMsg.addClass(className);
				if(msg) cronToggleMsg.html(msg);
				cronToggleInput.click(function(e){ e.preventDefault; return false; });
			}
			$.getJSON( pluginUrl, { 'json':true, 'cron_schedule_active':cronToggleValue } )
				.done(function(data) {
					if(!data || data.cron_schedule_active == undefined) toggleErr(errMsg,'red');
				})
				.fail(function( jqxhr, textStatus, error ) {
					// Note: if this fails it likely due to stray debugging output.
					console.log('toggleSync Error: '+textStatus+' '+error);
					toggleErr(errMsg,'red');
				});
		},

		autoReload = function(){

			var delayTimer,
				reloadConfirm = artlogic.find('#reload-confirm'),
				reloadCancelButton = reloadConfirm.find('.reload-cancel'),
				reloadContinueButton = reloadConfirm.find('.reload-continue'),
				reloadDelay = reloadConfirm.find('.delay'),
				linkReload = artlogic.find('a.reload'),
				countdownSec = reloadConfirm.data('delay-sec'),
				maxMb = artlogic.find('.max-mb');

			function closeConfirmDialog(){
				reloadConfirm.removeClass('show');
				clearInterval(delayTimer);
			}
			function runTimer(){
				if(countdownSec > 1) {
					countdownSec--;
					let s = countdownSec!=1? 's' : '';
					reloadDelay.text(countdownSec+' second'+s);
				}
				else closeConfirmDialog();
			}
			function submitForm(){
				reloadShowProgressBar();
				clearInterval(delayTimer);
				reloadConfirm.removeClass('show');
				artlogicForm.submit();
			}
			function runTimer(){
				if(countdownSec > 1) {
					countdownSec--;
					let s = countdownSec!=1? 's' : '';
					reloadDelay.text(countdownSec+' second'+s);
				}
				else {
					submitForm();
				}
			}

			reloadCancelButton.click(function(){
				closeConfirmDialog();
			});

			reloadContinueButton.click(function(){
				closeConfirmDialog();
				maxMb.removeClass('show');
			});

			// Display the download progress bar when reload link is clicked.
			linkReload.click(function(){
				reloadShowProgressBar();
			});

			if( getArtistId() ){
				reloadConfirm.addClass('show');
				delayTimer = setInterval(runTimer, 1000);
				reloadContinueButton.click(submitForm);
			}
		},

		getSelectMenu = function(){
			artistSelect.selectmenu({
				position: {
					my: 'left top',
					at: 'left bottom',
					collision: 'flip'
				},
				create: function(){
					self = $(this);
					ht = $(window).height() - $(this).parent().offset().top - $('.ui-selectmenu-text').height() - 20;
					$('.ui-selectmenu-menu ul').css('height',ht);
				},
				open: function(){
					if( $(this).data('loaded')!=1 ){
						$('.ui-selectmenu-menu li').each(function(i,o){
							if(i>0){
								let optVal = self.find('option').eq(i),
								name = (optVal.text() ? optVal.text() : ''),
								updateCt = (optVal.data('update-ct') ? optVal.data('update-ct') : 0),
								displayCt = (optVal.data('display-ct') ? optVal.data('display-ct') : 0),
								classEmpty = (updateCt==0? ' empty': ''),
								finalCt = (updateCt>0? updateCt: displayCt);
								$(o).html( '<span class="name">'+name+'</span><span class="count'+classEmpty+'">'+finalCt+'</span>' );
							}
						});
						$(this).data('loaded',1);
					}
				},
			});
		},

		cronCycler = function(){
			delayMin = 5;
			url = pluginUrl+'&json=true&cron_cycler=true';
			tag = '<iframe id="cron-cycler" src="'+url+'" frameborder=1 scrolling=no width="200" height="24"></iframe>'
			$('#artlogic').append(tag);
			iFrame = $('#cron-cycler');
			cronCyclerTimer = setInterval(function(){ iFrame.attr('src',url); }, delayMin*60000);
		},

		initAdminPage = function (){

			forceRefresh = artlogicForm.find('input[name=force_refresh]');

			// IOS style slider button
			cronToggle = artlogic.find('.cron-schedule-toggle');
			cronToggleInput = cronToggle.find('input[name=cron_schedule_active]');
			cronToggleMsg = cronToggle.find('.msg');
			$.switcher(cronToggleInput);
			if(cronToggleInput.prop('disabled')==true) cronToggle.addClass('disabled');
			cronToggleInput.on('click', toggleSync );
			cronToggleMsg.data('origMsg',cronToggleMsg.html());

			btnArtistSubmit = artlogicForm.find('button[name=artist_submit]');
			btnArtistSubmit.click(function(e){
				e.preventDefault();
				if(!getArtistId()) return false;
				$(this).prop('disabled', 'disabled');

				// Auto refresh (plugin won't run this if cron is turned off).
				let refresh = (dataIsCurrent.val() == 'false') ? true : false;
				refreshData(refresh);
				return false;
			}).focus();

			artistSelect = artlogicForm.find('select[name=artist_id]');
			getSelectMenu();
			artistSelect.change(resetProgressBar);

			downloadCursor = artlogicForm.find('input[name=download_cursor]');
			if(downloadCursor.val()>0) autoReload();

			setArtistStatsData();

		},

		initStatusPage = function() {

			// Auto refresh (plugin won't run this if cron is turned off).
			if(dataIsCurrent.val() == 'false') refreshData();

			// Manual refresh.
			btnRefresh = artlogic.find('[name=button_refresh]');
			btnRefresh.click(function(e){
				e.preventDefault;
				$(this).prop('disabled',true);
				dataIsCurrent.val('false');
				refreshData(true);
				return false;
			});

		},

		init = function(){

			// Common
			artlogicForm = artlogic.find('form[name=artlogic]');
			pluginUrl = artlogicForm.find('input[name=json_url]').val();
			dataIsCurrent = artlogicForm.find('input[name=data_is_current]');
			progBar = artlogic.find('#progressbar'); /* Jquery progressbar */
			progBar.progressbar({
				value: false,
				complete: progBarComplete
			});
			progLabel = progBar.find('.progress-label');
			progLabelDefault = progLabel.html();

			cronCycler();

			if ($('.page-admin').length) initAdminPage();
			if ($('.page-status').length) initStatusPage();

		}
		init();
	}

	/* Jquery accordion */
	$('.accordion').accordion({
		collapsible: true,
		active: false,
		heightStyle: 'content',
	});

	$('#artlogic').artlogicDownloader();

});


/***** IOS style toggle button *****/
/* https://www.jqueryscript.net/form/ON-OFF-Toggle-Switches-Switcher.html */
(function(a){a.switcher=function(c){var b=a("input[type=checkbox],input[type=radio]");if(c!==undefined&&c.length){b=b.filter(c)}b.each(function(){var e=a(this).hide(),d=a(document.createElement("div")).addClass("ui-switcher").attr("aria-checked",e.is(":checked"));if("radio"===e.attr("type")){d.attr("data-name",e.attr("name"))}toggleSwitch=function(f){if(f.target.type===undefined){e.trigger(f.type)}d.attr("aria-checked",e.is(":checked"));if("radio"===e.attr("type")){a(".ui-switcher[data-name="+e.attr("name")+"]").not(d.get(0)).attr("aria-checked",false)}};d.on("click",toggleSwitch);e.on("click",toggleSwitch);d.insertBefore(e)})}})(jQuery);
