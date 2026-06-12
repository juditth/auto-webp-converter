<?php
/**
 * Plugin Name: Auto WebP Converter
 * Plugin URI:  https://github.com/juditth/auto-webp-converter/
 * Description: Automatically converts uploaded images to WebP, resizes them, and optionally deletes originals.
 * Version:     1.1.0
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

		// Bulk re-compression (existing media library) AJAX endpoints
		add_action('wp_ajax_awc_bulk_scan', array($this, 'ajax_bulk_scan'));
		add_action('wp_ajax_awc_bulk_process', array($this, 'ajax_bulk_process'));

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

			<?php $this->render_bulk_section(); ?>
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

	/* -------------------------------------------------------------------------
	 * Bulk re-compression of the existing media library.
	 *
	 * Re-encodes existing JPEG/PNG files in-place: same path, same filename,
	 * same format and same pixel dimensions. Only the physical file size is
	 * reduced. The database, attachment metadata and generated sizes are never
	 * touched, so no links break.
	 * ---------------------------------------------------------------------- */

	private function bulk_compression_available()
	{
		if (class_exists('Imagick')) {
			return true;
		}
		return function_exists('imagecreatefromjpeg') && function_exists('imagejpeg');
	}

	public function render_bulk_section()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$available = $this->bulk_compression_available();
		$imagick = class_exists('Imagick');
		$nonce = wp_create_nonce('awc_bulk');
		?>
		<hr style="margin:36px 0 24px;" />
		<h2>Compress Existing Files</h2>
		<p class="description" style="max-width:820px;">
			Reduces the physical file size of images <strong>already in your media library</strong> &ndash; uploaded
			before this plugin was active, or by other tools. It walks the <code>uploads</code> folder and re-encodes
			each JPEG at the quality you choose below. <strong>Only JPEG files are processed &ndash; PNG and WebP are
			skipped</strong> (PNG is lossless, WebP is already compressed). This does <strong>not</strong> convert
			anything to WebP; it only makes the existing JPEGs smaller.
		</p>

		<div style="display:flex;gap:20px;flex-wrap:wrap;max-width:1000px;margin:18px 0;">
			<div style="flex:1;min-width:230px;background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;">
				<strong>What happens</strong>
				<ul style="margin:8px 0 0;list-style:disc;padding-left:18px;">
					<li>Only <strong>JPEG</strong> is processed &ndash; PNG and WebP are skipped.</li>
					<li>Each JPEG is re-compressed <em>in place</em>.</li>
					<li>A file is overwritten <strong>only if the result is actually smaller</strong>.</li>
					<li>Safe to run repeatedly &ndash; already-small files are skipped.</li>
				</ul>
			</div>
			<div style="flex:1;min-width:230px;background:#edfaef;border-left:4px solid #00a32a;padding:12px 16px;">
				<strong>What is preserved</strong>
				<ul style="margin:8px 0 0;list-style:disc;padding-left:18px;">
					<li>File name, format and pixel dimensions.</li>
					<li>EXIF metadata &ndash; date taken, GPS, camera (with ImageMagick).</li>
					<li>Colour profile and image orientation.</li>
				</ul>
			</div>
			<div style="flex:1;min-width:230px;background:#fcf9e8;border-left:4px solid #dba617;padding:12px 16px;">
				<strong>What never changes</strong>
				<ul style="margin:8px 0 0;list-style:disc;padding-left:18px;">
					<li>The database and attachment metadata.</li>
					<li>Image URLs &ndash; no links break.</li>
					<li>Generated thumbnail sizes.</li>
				</ul>
			</div>
		</div>

		<p style="max-width:820px;">
			<strong>Re-compression is lossy and cannot be undone &ndash; make a full backup of your
			<code>uploads</code> folder first.</strong>
		</p>

			<?php if (!$available): ?>
				<div class="notice notice-error inline">
					<p>Neither Imagick nor GD (with JPEG support) is available. Compression is disabled.</p>
				</div>
			<?php else: ?>
				<div class="notice notice-<?php echo $imagick ? 'success' : 'warning'; ?> inline">
					<p>
						<?php if ($imagick): ?>
							Engine: <strong>ImageMagick</strong> &ndash; EXIF metadata, colour profile and orientation are preserved.
						<?php else: ?>
							Engine: <strong>GD</strong> &ndash; EXIF metadata (date, GPS, camera) is <strong>not</strong> preserved
							and full-size camera originals that rely on an EXIF orientation flag could appear rotated. Pixels are unchanged.
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="awc-bulk-quality">JPEG quality (0&ndash;100)</label></th>
					<td>
						<input type="number" id="awc-bulk-quality" value="82" min="1" max="100" />
						<p class="description">Lower = smaller files. 80&ndash;82 is a good balance.</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="awc-bulk-min-gain">Only overwrite if smaller by at least (%)</label></th>
					<td><input type="number" id="awc-bulk-min-gain" value="5" min="0" max="90" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="awc-bulk-min-kb">Skip files smaller than (KB)</label></th>
					<td>
						<input type="number" id="awc-bulk-min-kb" value="50" min="0" max="100000" />
						<p class="description">Tiny thumbnails rarely shrink and aren't worth re-encoding.</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="awc-bulk-subfolder">Limit to subfolder (optional)</label></th>
					<td>
						<input type="text" id="awc-bulk-subfolder" value="" placeholder="e.g. 2025 or 2025/06" class="regular-text" />
						<p class="description">Relative to the uploads folder. Leave empty to process the whole library.</p>
					</td>
				</tr>
			</table>

			<p>
				<label><input type="checkbox" id="awc-bulk-confirm" /> I have a backup and understand this overwrites originals.</label>
			</p>

			<p>
				<button type="button" class="button button-primary" id="awc-bulk-start" disabled>Start compression</button>
				<button type="button" class="button" id="awc-bulk-stop" disabled>Stop</button>
			</p>

			<p id="awc-bulk-progress" style="font-weight:600;"></p>
			<pre id="awc-bulk-log" style="background:#1e1e1e;color:#d4d4d4;padding:12px;max-height:480px;overflow:auto;border-radius:4px;display:none;"></pre>

		<script>
		(function () {
			var ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
			var nonce = <?php echo wp_json_encode($nonce); ?>;
			var available = <?php echo $available ? 'true' : 'false'; ?>;

			var startBtn = document.getElementById('awc-bulk-start');
			var stopBtn = document.getElementById('awc-bulk-stop');
			var confirmBox = document.getElementById('awc-bulk-confirm');
			var logEl = document.getElementById('awc-bulk-log');
			var progressEl = document.getElementById('awc-bulk-progress');

			var stopRequested = false;
			var totals = { done: 0, compressed: 0, skipped: 0, errors: 0, savedBytes: 0, total: 0 };

			function fmtBytes(b) {
				if (b < 1024) return b + ' B';
				if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
				if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
				return (b / 1073741824).toFixed(2) + ' GB';
			}

			function log(line, color) {
				var span = document.createElement('span');
				if (color) span.style.color = color;
				span.textContent = line + '\n';
				logEl.appendChild(span);
				logEl.scrollTop = logEl.scrollHeight;
			}

			function logResult(symbol, file, url, detail, color) {
				var span = document.createElement('span');
				if (color) span.style.color = color;
				span.appendChild(document.createTextNode(symbol + ' '));
				if (url) {
					var a = document.createElement('a');
					a.href = url;
					a.target = '_blank';
					a.rel = 'noopener';
					a.textContent = file;
					a.style.color = 'inherit';
					a.style.textDecoration = 'underline';
					span.appendChild(a);
				} else {
					span.appendChild(document.createTextNode(file));
				}
				span.appendChild(document.createTextNode(detail + '\n'));
				logEl.appendChild(span);
				logEl.scrollTop = logEl.scrollHeight;
			}

			function updateProgress() {
				progressEl.textContent = totals.done + ' / ' + totals.total + ' processed – '
					+ totals.compressed + ' compressed, ' + totals.skipped + ' skipped, '
					+ totals.errors + ' errors – saved ' + fmtBytes(totals.savedBytes);
			}

			function setRunning(running) {
				startBtn.disabled = running || !confirmBox.checked || !available;
				stopBtn.disabled = !running;
				confirmBox.disabled = running;
			}

			confirmBox.addEventListener('change', function () {
				startBtn.disabled = !confirmBox.checked || !available;
			});

			stopBtn.addEventListener('click', function () {
				stopRequested = true;
				stopBtn.disabled = true;
				log('Stop requested – finishing current batch…', '#dcdcaa');
			});

			function post(action, params) {
				var body = new URLSearchParams();
				body.append('action', action);
				body.append('nonce', nonce);
				Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
				return fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); });
			}

			function processBatch(token, offset, opts) {
				if (stopRequested) {
					log('Stopped at ' + totals.done + ' / ' + totals.total + '.', '#dcdcaa');
					setRunning(false);
					return;
				}
				post('awc_bulk_process', {
					token: token, offset: offset, batch: 4,
					quality: opts.quality, min_gain: opts.minGain
				}).then(function (res) {
					if (!res || !res.success) {
						log('Error: ' + (res && res.data ? res.data : 'unknown'), '#f48771');
						setRunning(false);
						return;
					}
					res.data.results.forEach(function (r) {
						totals.done++;
						if (r.status === 'compressed') {
							totals.compressed++;
							totals.savedBytes += (r.old - r.new);
							var pct = r.old ? Math.round((1 - r.new / r.old) * 100) : 0;
							logResult('✓', r.file, r.url, '  ' + fmtBytes(r.old) + ' → ' + fmtBytes(r.new) + '  (−' + pct + '%)', '#9cdcfe');
						} else if (r.status === 'skipped') {
							totals.skipped++;
							logResult('– skipped', r.file, r.url, '  (' + r.reason + ')', '#808080');
						} else {
							totals.errors++;
							logResult('✗ error', r.file, r.url, '  (' + (r.reason || '') + ')', '#f48771');
						}
					});
					updateProgress();

					if (res.data.next >= totals.total) {
						log('Done. Saved a total of ' + fmtBytes(totals.savedBytes) + '.', '#6a9955');
						setRunning(false);
						return;
					}
					processBatch(token, res.data.next, opts);
				}).catch(function (e) {
					log('Network error: ' + e, '#f48771');
					setRunning(false);
				});
			}

			startBtn.addEventListener('click', function () {
				if (!confirmBox.checked) return;
				stopRequested = false;
				totals = { done: 0, compressed: 0, skipped: 0, errors: 0, savedBytes: 0, total: 0 };
				logEl.style.display = 'block';
				logEl.textContent = '';
				progressEl.textContent = 'Scanning files…';
				setRunning(true);

				var opts = {
					quality: document.getElementById('awc-bulk-quality').value,
					minGain: document.getElementById('awc-bulk-min-gain').value
				};

				post('awc_bulk_scan', {
					subfolder: document.getElementById('awc-bulk-subfolder').value,
					min_kb: document.getElementById('awc-bulk-min-kb').value
				}).then(function (res) {
					if (!res || !res.success) {
						log('Scan failed: ' + (res && res.data ? res.data : 'unknown'), '#f48771');
						setRunning(false);
						return;
					}
					totals.total = res.data.total;
					if (totals.total === 0) {
						log('No matching files found.', '#dcdcaa');
						setRunning(false);
						return;
					}
					log('Found ' + totals.total + ' candidate files.', '#6a9955');
					updateProgress();
					processBatch(res.data.token, 0, opts);
				}).catch(function (e) {
					log('Network error: ' + e, '#f48771');
					setRunning(false);
				});
			});
		})();
		</script>
		<?php
	}

	public function ajax_bulk_scan()
	{
		check_ajax_referer('awc_bulk', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('forbidden', 403);
		}
		if (!$this->bulk_compression_available()) {
			wp_send_json_error('no image library available');
		}

		$uploads = wp_upload_dir();
		$base = wp_normalize_path(realpath($uploads['basedir']));
		if (!$base) {
			wp_send_json_error('uploads directory not found');
		}

		$root = $base;
		$subfolder = isset($_POST['subfolder']) ? wp_unslash($_POST['subfolder']) : '';
		$subfolder = trim(str_replace('\\', '/', $subfolder), '/');
		if ($subfolder !== '') {
			if (strpos($subfolder, '..') !== false) {
				wp_send_json_error('invalid subfolder');
			}
			$candidate = wp_normalize_path(realpath($base . '/' . $subfolder));
			if (!$candidate || ($candidate !== $base && strpos($candidate, $base . '/') !== 0)) {
				wp_send_json_error('subfolder not found inside uploads');
			}
			$root = $candidate;
		}

		$min_bytes = isset($_POST['min_kb']) ? max(0, (int) $_POST['min_kb']) * 1024 : 0;

		$list = array();
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($it as $f) {
				if (!$f->isFile()) {
					continue;
				}
				$ext = strtolower($f->getExtension());
				// Only JPEG is re-compressed. PNG (lossless) and WebP are skipped
				// entirely, so they don't even appear in the list.
				if (!in_array($ext, array('jpg', 'jpeg'), true)) {
					continue;
				}
				if ($min_bytes > 0 && $f->getSize() < $min_bytes) {
					continue;
				}
				$list[] = wp_normalize_path($f->getPathname());
			}
		} catch (Exception $e) {
			$this->log('Bulk scan failed: ' . $e->getMessage());
			wp_send_json_error('Failed to scan the uploads directory.');
		}

		$token = wp_generate_password(12, false);
		set_transient('awc_bulk_' . $token, $list, HOUR_IN_SECONDS);

		wp_send_json_success(array('token' => $token, 'total' => count($list)));
	}

	public function ajax_bulk_process()
	{
		check_ajax_referer('awc_bulk', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('forbidden', 403);
		}

		$token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
		$list = get_transient('awc_bulk_' . $token);
		if (!is_array($list)) {
			wp_send_json_error('session expired, please start again');
		}

		$offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
		$batch = isset($_POST['batch']) ? min(20, max(1, (int) $_POST['batch'])) : 4;
		$quality = isset($_POST['quality']) ? max(1, min(100, (int) $_POST['quality'])) : 82;
		$min_gain = isset($_POST['min_gain']) ? max(0, min(90, (int) $_POST['min_gain'])) / 100 : 0.05;

		$uploads = wp_upload_dir();
		$base = wp_normalize_path(realpath($uploads['basedir']));
		$base_url = isset($uploads['baseurl']) ? $uploads['baseurl'] : '';

		$results = array();
		$slice = array_slice($list, $offset, $batch);
		foreach ($slice as $path) {
			$path = wp_normalize_path($path);

			// Hard guard: never touch anything outside the uploads directory,
			// regardless of what the stored list contains.
			if (!$base || strpos($path, $base . '/') !== 0) {
				$results[] = array('status' => 'error', 'reason' => 'outside uploads', 'file' => basename($path));
				continue;
			}

			$rel = ltrim(substr($path, strlen($base)), '/');
			$res = $this->compress_image_file($path, $quality, $min_gain);
			$res['file'] = $rel;
			if ($base_url !== '') {
				$res['url'] = trailingslashit($base_url) . $rel;
			}
			$results[] = $res;
		}

		wp_send_json_success(array(
			'results' => $results,
			'next' => $offset + count($slice),
		));
	}

	/**
	 * Re-encode a single JPEG/PNG in place at the given quality.
	 * Never changes format or pixel dimensions; only overwrites the original
	 * when the result is at least $min_gain smaller.
	 */
	private function compress_image_file($path, $quality, $min_gain)
	{
		if (!is_file($path) || !is_writable($path)) {
			return array('status' => 'error', 'reason' => 'not writable');
		}

		$info = @getimagesize($path);
		if (!$info) {
			return array('status' => 'error', 'reason' => 'not an image');
		}
		$mime = $info['mime'];
		if ($mime === 'image/png') {
			// PNG is lossless; the quality setting does not apply and re-encoding
			// rarely shrinks it. Skip it outright instead of attempting a re-encode.
			return array('status' => 'skipped', 'reason' => 'png (lossless)');
		}
		if ($mime !== 'image/jpeg') {
			return array('status' => 'skipped', 'reason' => 'unsupported format');
		}

		$old_size = filesize($path);
		if ($old_size === false || $old_size === 0) {
			return array('status' => 'error', 'reason' => 'unreadable');
		}

		$tmp = $path . '.awc-tmp';
		$ok = $this->reencode_image($path, $tmp, $mime, $quality);
		if (!$ok || !file_exists($tmp)) {
			@unlink($tmp);
			return array('status' => 'error', 'reason' => 'encode failed');
		}

		$new_size = filesize($tmp);

		// Guard: the re-encoded file must be a valid image of identical dimensions.
		$new_info = @getimagesize($tmp);
		if (!$new_info || $new_info[0] !== $info[0] || $new_info[1] !== $info[1]) {
			@unlink($tmp);
			return array('status' => 'error', 'reason' => 'dimension mismatch');
		}

		if ($new_size === false || $new_size <= 0 || $new_size >= $old_size * (1 - $min_gain)) {
			@unlink($tmp);
			return array('status' => 'skipped', 'reason' => 'no gain', 'old' => $old_size, 'new' => $new_size);
		}

		// Write bytes back into the original file to preserve ownership/permissions.
		$bytes = @file_get_contents($tmp);
		@unlink($tmp);
		if ($bytes === false || @file_put_contents($path, $bytes) === false) {
			return array('status' => 'error', 'reason' => 'write failed');
		}
		clearstatcache(true, $path);

		return array('status' => 'compressed', 'old' => $old_size, 'new' => $new_size);
	}

	private function reencode_image($src, $dst, $mime, $quality)
	{
		// Prefer Imagick: it preserves EXIF (dates, GPS), IPTC, XMP and the ICC
		// colour profile. When Imagick is available we use it exclusively and do
		// NOT silently fall back to GD for an individual file, because GD would
		// strip all of that metadata. A file Imagick can't handle is reported as
		// an error and left untouched instead.
		if (class_exists('Imagick')) {
			try {
				$im = new Imagick($src);
				if ($mime === 'image/jpeg') {
					$im->setImageFormat('jpeg');
					$im->setImageCompression(Imagick::COMPRESSION_JPEG);
					$im->setImageCompressionQuality($quality);
				} else {
					$im->setImageFormat('png');
					$im->setOption('png:compression-level', '9');
				}
				$result = $im->writeImage($dst);
				$im->clear();
				$im->destroy();
				return (bool) $result;
			} catch (Exception $e) {
				$this->log('Imagick re-encode failed for ' . basename($src) . ': ' . $e->getMessage());
				return false;
			}
		}

		// GD fallback. Only reached when Imagick is not installed at all. Note
		// that GD does not carry EXIF/IPTC/XMP, so metadata is not preserved here.
		if ($mime === 'image/jpeg') {
			if (!function_exists('imagecreatefromjpeg')) {
				return false;
			}
			$img = @imagecreatefromjpeg($src);
			if (!$img) {
				return false;
			}
			$result = imagejpeg($img, $dst, $quality);
			imagedestroy($img);
			return $result;
		}

		if (!function_exists('imagecreatefrompng')) {
			return false;
		}
		$img = @imagecreatefrompng($src);
		if (!$img) {
			return false;
		}
		imagealphablending($img, false);
		imagesavealpha($img, true);
		$result = imagepng($img, $dst, 9);
		imagedestroy($img);
		return $result;
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
