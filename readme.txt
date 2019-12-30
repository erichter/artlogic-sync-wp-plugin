Artlogic API
***

UPGRADES (from ArtLogic-Api written by Distill):
Fixed vulnerability in change of artists name, which was used to correlate records between ArtLogic and WP.
Fixed problem of duplicate images when updates were run. The plugin now checks images by ArtLogic file id rather than filename.
Plugin returns better feedback during sync process.
Improved performance, long jobs are now broken up into smaller parts. Only images that are flagged by ArtLogic as modified get reimported.
Better filtering for incomplete records: missing title, description, or image.


