<?php
/**
 * Plugin Name: Auto WebP Converter
 * Plugin URI:  https://github.com/juditth/auto-webp-converter/
 * Description: Automatically converts uploaded images to WebP, resizes them, and optionally deletes originals.
 * Version:     1.0.7
 * Author:      Jitka Klingenbergová
 * Author URI:  https://vyladeny-web.cz/
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) {
	exit;
}

class Auto_WebP_Converter
{

	public function __construct()
	{
		add_action('admin_menu', array($this, 'add_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_init', array($this, 'maybe_cleanup_legacy_log'));
		add_action('admin_notices', array($this, 'render_dependency_notice'));
		add_filter('wp_handle_upload', array($this, 'handle_upload'));

		// Settings link on plugins page
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

		// Initialize Plugin Update Checker (only if library exists)
		$puc_path = plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
		if (file_exists($puc_path)) {
			require_once $puc_path;
			$myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				'https://vyladeny-web.cz/plugins/auto-webp-converter/info.json',
				__FILE__,
				'auto-webp-converter'
			);
		}
	}

	private function log($message)
	{
		if (!defined('WP_DEBUG') || !WP_DEBUG) {
			return;
		}

		$log_dir = WP_CONTENT_DIR . '/uploads/auto-webp-converter';
		if (!$this->ensure_log_dir_protected($log_dir)) {
			return;
		}

		$log_file = $log_dir . '/awc_debug.log';
		$timestamp = current_time('mysql');
		$formatted_message = "[{$timestamp}] {$message}" . PHP_EOL;
		@file_put_contents($log_file, $formatted_message, FILE_APPEND);
	}

	private function ensure_log_dir_protected($log_dir)
	{
		if (!is_dir($log_dir) && !wp_mkdir_p($log_dir)) {
			return false;
		}

		$htaccess = $log_dir . '/.htaccess';
		if (!file_exists($htaccess)) {
			$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			@file_put_contents($htaccess, $rules);
		}

		$index = $log_dir . '/index.php';
		if (!file_exists($index)) {
			@file_put_contents($index, "<?php\n// Silence is golden.\n");
		}

		$webconfig = $log_dir . '/web.config';
		if (!file_exists($webconfig)) {
			$iis = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n\t<system.webServer>\n\t\t<authorization>\n"
				. "\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n"
				. "\t</system.webServer>\n</configuration>\n";
			@file_put_contents($webconfig, $iis);
		}

		return true;
	}

	public function add_settings_page()
	{
		add_options_page(
			'Auto WebP Converter',
			'Auto WebP',
			'manage_options',
			'auto-webp-converter',
			array($this, 'render_settings_page')
		);
	}

	public function register_settings()
	{
		register_setting('awc_settings_group', 'awc_max_width', array('sanitize_callback' => array($this, 'sanitize_dimension')));
		register_setting('awc_settings_group', 'awc_max_height', array('sanitize_callback' => array($this, 'sanitize_dimension')));
		register_setting('awc_settings_group', 'awc_quality', array('sanitize_callback' => array($this, 'sanitize_quality')));
		register_setting('awc_settings_group', 'awc_delete_originals', array('sanitize_callback' => 'absint'));
	}

	public function sanitize_quality($input)
	{
		$quality = absint($input);
		return max(0, min(100, $quality));
	}

	public function sanitize_dimension($input)
	{
		$value = absint($input);
		return min(10000, $value);
	}

	public function maybe_cleanup_legacy_log()
	{
		if (get_option('awc_legacy_log_cleaned')) {
			return;
		}

		$legacy_log = WP_CONTENT_DIR . '/uploads/awc_debug.log';
		if (file_exists($legacy_log)) {
			@unlink($legacy_log);
		}

		update_option('awc_legacy_log_cleaned', 1, false);
	}

	private function get_webp_support_status()
	{
		$gd_loaded = extension_loaded('gd');
		$gd_webp = function_exists('imagewebp');
		$imagick_loaded = class_exists('Imagick');
		$imagick_webp = false;

		if ($imagick_loaded) {
			try {
				$imagick_webp = !empty(Imagick::queryFormats('WEBP'));
			} catch (Exception $e) {
				$imagick_webp = false;
			}
		}

		$wp_supports_webp = function_exists('wp_image_editor_supports')
			? wp_image_editor_supports(array('mime_type' => 'image/webp'))
			: ($gd_webp || $imagick_webp);

		return array(
			'supported' => (bool) ($wp_supports_webp && ($gd_webp || $imagick_webp)),
			'gd_loaded' => $gd_loaded,
			'gd_webp' => $gd_webp,
			'imagick_loaded' => $imagick_loaded,
			'imagick_webp' => $imagick_webp,
			'wp_supports_webp' => (bool) $wp_supports_webp,
		);
	}

	private function get_webp_support_message($status)
	{
		if ($status['supported']) {
			if ($status['imagick_webp'] && $status['gd_webp']) {
				return 'WebP conversion is available through ImageMagick and GD.';
			}

			if ($status['imagick_webp']) {
				return 'WebP conversion is available through ImageMagick.';
			}

			return 'WebP conversion is available through GD.';
		}

		if (!$status['imagick_loaded'] && !$status['gd_loaded']) {
			return 'WebP conversion is not available because neither the Imagick nor GD PHP extension is loaded.';
		}

		if ($status['imagick_loaded'] && !$status['imagick_webp'] && $status['gd_loaded'] && !$status['gd_webp']) {
			return 'WebP conversion is not available because ImageMagick and GD are loaded without WebP support.';
		}

		if ($status['imagick_loaded'] && !$status['imagick_webp']) {
			return 'WebP conversion is not available because ImageMagick is loaded without WebP support.';
		}

		if ($status['gd_loaded'] && !$status['gd_webp']) {
			return 'WebP conversion is not available because GD is loaded without WebP support.';
		}

		return 'WebP conversion is not available. Ask your hosting provider to enable the Imagick extension with WebP support or GD with WebP support.';
	}

	public function render_dependency_notice()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$status = $this->get_webp_support_status();
		if ($status['supported']) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>Auto WebP Converter:</strong> '
			. esc_html($this->get_webp_support_message($status))
			. ' Conversion is disabled until server WebP support is enabled.</p></div>';
	}

	public function render_settings_page()
	{
		$webp_status = $this->get_webp_support_status();
		?>
		<div class="wrap">
			<h1>Auto WebP Converter Settings</h1>
			<div class="notice <?php echo $webp_status['supported'] ? 'notice-success' : 'notice-error'; ?> inline">
				<p><strong>Server WebP support:</strong> <?php echo esc_html($this->get_webp_support_message($webp_status)); ?></p>
				<ul>
					<li>ImageMagick / Imagick: <?php echo esc_html($webp_status['imagick_webp'] ? 'available with WebP' : ($webp_status['imagick_loaded'] ? 'loaded without WebP' : 'not loaded')); ?></li>
					<li>GD: <?php echo esc_html($webp_status['gd_webp'] ? 'available with WebP' : ($webp_status['gd_loaded'] ? 'loaded without WebP' : 'not loaded')); ?></li>
				</ul>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields('awc_settings_group'); ?>
				<?php do_settings_sections('awc_settings_group'); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">Max Width (px)</th>
						<td><input type="number" name="awc_max_width" min="1" max="10000"
								value="<?php echo esc_attr(get_option('awc_max_width', 2300)); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Max Height (px)</th>
						<td><input type="number" name="awc_max_height" min="1" max="10000"
								value="<?php echo esc_attr(get_option('awc_max_height', 2300)); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Quality (0-100)</th>
						<td><input type="number" name="awc_quality"
								value="<?php echo esc_attr(get_option('awc_quality', 95)); ?>" min="0" max="100" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Original Files</th>
						<td>
							<input type="checkbox" name="awc_delete_originals" value="1" <?php checked(1, get_option('awc_delete_originals', 1), true); ?> />
							<label for="awc_delete_originals">Delete original uploaded file? (If unchecked, original will be
								renamed to <code>_original</code>)</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function handle_upload($file)
	{
		// Only check valid uploads; let WordPress handle its own upload errors.
		if (!empty($file['error'])) {
			return $file;
		}

		// Check mime type
		$type = $file['type'];
		if (!in_array($type, array('image/jpeg', 'image/jpg', 'image/png'))) {
			return $file;
		}

		$webp_status = $this->get_webp_support_status();
		if (!$webp_status['supported']) {
			$this->log("WebP conversion skipped. " . $this->get_webp_support_message($webp_status));
			return $file;
		}

		$file_path = $file['file'];
		$this->log("Starting processing for image: " . basename($file_path));

		// Load image editor
		$editor = wp_get_image_editor($file_path);
		if (is_wp_error($editor)) {
			// Failed to load editor, just return original
			$this->log("Failed to load image editor for: " . basename($file_path) . ". Error: " . $editor->get_error_message());
			return $file;
		}

		$this->apply_exif_orientation($editor, $file_path, $type);

		// Get desired dimensions
		$max_w = (int) get_option('awc_max_width', 2300);
		$max_h = (int) get_option('awc_max_height', 2300);
		$quality = (int) get_option('awc_quality', 95);

		// Resize if needed
		$size = $editor->get_size();
		if ($size['width'] > $max_w || $size['height'] > $max_h) {
			$this->log("Resizing image. Original: {$size['width']}x{$size['height']}. Max: {$max_w}x{$max_h}.");
			$editor->resize($max_w, $max_h, false);
		} else {
			$this->log("No resizing needed. Dimensions: {$size['width']}x{$size['height']} are within limits.");
		}

		// Make sure we set quality
		$editor->set_quality($quality);

		// Save as WebP
		$path_info = pathinfo($file_path);
		$new_filename = wp_unique_filename($path_info['dirname'], $path_info['filename'] . '.webp');
		$new_path = trailingslashit($path_info['dirname']) . $new_filename;

		$saved = $editor->save($new_path, 'image/webp');

		if (is_wp_error($saved)) {
			// Failed to save webp, preserve original
			$this->log("Failed to save WebP to: " . basename($new_path) . ". Error: " . $saved->get_error_message());
			return $file;
		}

		$this->log("Successfully converted to WebP: " . basename($new_path));

		// Handle original files
		$delete_original = get_option('awc_delete_originals', 1);
		if ($delete_original) {
			wp_delete_file($file_path);
			$this->log("Deleted original file: " . basename($file_path));
		} else {
			// Rename original to _original (keep extension when present)
			$extension = isset($path_info['extension']) ? $path_info['extension'] : '';
			$original_basename = $path_info['filename'] . '_original' . ($extension !== '' ? '.' . $extension : '');
			$original_filename = wp_unique_filename($path_info['dirname'], $original_basename);
			$original_renamed = trailingslashit($path_info['dirname']) . $original_filename;

			global $wp_filesystem;
			if (empty($wp_filesystem)) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ($wp_filesystem) {
				$wp_filesystem->move($file_path, $original_renamed);
				$this->log("Renamed original file to: " . basename($original_renamed));
			} else {
				$this->log("WP_Filesystem not available. Failed to rename original file: " . basename($original_renamed));
			}
		}

		// Update return array to point to WebP. Rebuild URL from dirname to avoid
		// accidental substring matches if the old basename appears elsewhere in the URL.
		$file['file'] = $new_path;
		$file['url'] = trailingslashit(dirname($file['url'])) . $new_filename;
		$file['type'] = 'image/webp';

		return $file;
	}

	private function apply_exif_orientation($editor, $file_path, $mime_type)
	{
		if ($mime_type !== 'image/jpeg' && $mime_type !== 'image/jpg') {
			return;
		}

		if (!function_exists('exif_read_data')) {
			$this->log("EXIF extension is not available. Skipping orientation fix for: " . basename($file_path));
			return;
		}

		$exif = @exif_read_data($file_path);
		$orientation = isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

		if ($orientation === 1) {
			return;
		}

		$this->log("Applying EXIF orientation {$orientation} to: " . basename($file_path));

		$result = true;

		switch ($orientation) {
			case 2:
				$result = $editor->flip(true, false);
				break;
			case 3:
				$result = $editor->rotate(180);
				break;
			case 4:
				$result = $editor->flip(false, true);
				break;
			case 5:
				$result = $editor->flip(true, false);
				if (!is_wp_error($result)) {
					$result = $editor->rotate(-90);
				}
				break;
			case 6:
				$result = $editor->rotate(-90);
				break;
			case 7:
				$result = $editor->flip(true, false);
				if (!is_wp_error($result)) {
					$result = $editor->rotate(90);
				}
				break;
			case 8:
				$result = $editor->rotate(90);
				break;
			default:
				$this->log("Unsupported EXIF orientation {$orientation} for: " . basename($file_path));
				return;
		}

		if (is_wp_error($result)) {
			$this->log("Failed to apply EXIF orientation for: " . basename($file_path) . ". Error: " . $result->get_error_message());
			return;
		}

		$this->log("EXIF orientation fixed for: " . basename($file_path));
	}

	/**
	 * Add settings link to plugins page
	 */
	public function add_settings_link($links)
	{
		$settings_link = '<a href="' . admin_url('options-general.php?page=auto-webp-converter') . '">Settings</a>';
		array_unshift($links, $settings_link);
		return $links;
	}

}

new Auto_WebP_Converter();
