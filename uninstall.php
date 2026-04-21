<?php
/**
 * Auto WebP Converter uninstall handler.
 * Removes plugin options and debug log artifacts when the plugin is deleted.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('awc_max_width');
delete_option('awc_max_height');
delete_option('awc_quality');
delete_option('awc_delete_originals');
delete_option('awc_legacy_log_cleaned');

// Remove legacy log from pre-1.0.6 installs.
$legacy_log = WP_CONTENT_DIR . '/uploads/awc_debug.log';
if (file_exists($legacy_log)) {
	@unlink($legacy_log);
}

$log_dir = WP_CONTENT_DIR . '/uploads/auto-webp-converter';
if (is_dir($log_dir)) {
	$files = array('awc_debug.log', '.htaccess', 'index.php', 'web.config');
	foreach ($files as $f) {
		$path = $log_dir . '/' . $f;
		if (file_exists($path)) {
			@unlink($path);
		}
	}
	@rmdir($log_dir);
}
