<?php
/**
 * Plugin Name: Auto WebP Converter
 * Plugin URI:  https://vyladeny-web.cz/
 * Description: Automatically converts uploaded images to WebP, resizes them, and optionally deletes originals.
 * Version:     1.0.0
 * Author:      Jitka Klingenbergova 
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
		add_filter('wp_handle_upload', array($this, 'handle_upload'));

		// Initialize Plugin Update Checker (only if library exists)
		$puc_path = plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
		if (file_exists($puc_path)) {
			require_once $puc_path;
			$myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				'https://github.com/juditth/auto-webp-converter', 
				__FILE__,
				'auto-webp-converter'
			);
			// Optional: Set the branch that contains the stable release.
			$myUpdateChecker->setBranch('main');
		}
	}

	private function log($message)
	{
		$log_file = WP_CONTENT_DIR . '/uploads/awc_debug.log';
		$timestamp = current_time('mysql');
		$formatted_message = "[{$timestamp}] {$message}" . PHP_EOL;
		// Ensure uploads directory exists if not already (mostly for very fresh installs) but wp-content should be writeable
		@file_put_contents($log_file, $formatted_message, FILE_APPEND);
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
		register_setting('awc_settings_group', 'awc_max_width', array('sanitize_callback' => 'absint'));
		register_setting('awc_settings_group', 'awc_max_height', array('sanitize_callback' => 'absint'));
		register_setting('awc_settings_group', 'awc_quality', array('sanitize_callback' => array($this, 'sanitize_quality')));
		register_setting('awc_settings_group', 'awc_delete_originals', array('sanitize_callback' => 'absint'));
	}

	public function sanitize_quality($input)
	{
		$quality = absint($input);
		return max(0, min(100, $quality));
	}

	public function render_settings_page()
	{
		?>
		<div class="wrap">
			<h1>Auto WebP Converter Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields('awc_settings_group'); ?>
				<?php do_settings_sections('awc_settings_group'); ?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">Max Width (px)</th>
						<td><input type="number" name="awc_max_width"
								value="<?php echo esc_attr(get_option('awc_max_width', 2300)); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Max Height (px)</th>
						<td><input type="number" name="awc_max_height"
								value="<?php echo esc_attr(get_option('awc_max_height', 2300)); ?>" /></td>
					</tr>
					<tr valign="top">
						<th scope="row">Quality (0-100)</th>
						<td><input type="number" name="awc_quality"
								value="<?php echo esc_attr(get_option('awc_quality', 90)); ?>" min="0" max="100" /></td>
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
		// Only check valid uploads
		if (isset($file['error']) && !empty($file['error'])) {
			$this->log("Upload error for file: " . ($file['file'] ?? 'unknown') . ". Error: " . $file['error']);
			return $file;
		}

		// Check mime type
		$type = $file['type'];
		if (!in_array($type, array('image/jpeg', 'image/jpg', 'image/png'))) {
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

		// Get desired dimensions
		$max_w = (int) get_option('awc_max_width', 1920);
		$max_h = (int) get_option('awc_max_height', 1080);
		$quality = (int) get_option('awc_quality', 80);

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
		$new_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';

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
			// Rename original to _original
			$original_renamed = $path_info['dirname'] . '/' . $path_info['filename'] . '_original.' . $path_info['extension'];

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

		// Update return array to point to WebP
		$file['file'] = $new_path;
		$file['url'] = str_replace(
			$path_info['basename'],
			$path_info['filename'] . '.webp',
			$file['url']
		);
		$file['type'] = 'image/webp';

		return $file;
	}

}

new Auto_WebP_Converter();
