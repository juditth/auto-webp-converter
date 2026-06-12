=== Auto WebP Converter ===
Contributors: jitka88
Tags: webp, convert, image optimization, resize, to webp
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically converts uploaded images to WebP, resizes them, and optionally deletes originals.

== Description ==

Auto WebP Converter is a lightweight, efficient plugin designed to streamline your image workflow. Upon upload, it automatically detects JPEG and PNG images, resizes them to your specified dimensions, and converts them to the next-gen WebP format.

**Key Features:**

*   **Automatic WebP Conversion:** Seamlessly converts uploaded JPG and PNG files to WebP.
*   **Smart Resizing:** Automatically resizes images that exceed your defined maximum width and height limits (default: 2300x2300).
*   **Original File Management:** You decide what happens to the source file – delete it to save disk space, or keep it renamed with an `_original` suffix.
*   **Quality Control:** Adjustable conversion quality setting (0-100).
*   **Bulk Compress Existing Files:** A maintenance tool (Settings -> Auto WebP) that re-compresses JPEG files already in the media library in place. Same name, format and dimensions are kept; only the physical file size is reduced. PNG and WebP files are skipped. The database, attachment metadata, image URLs and generated thumbnail sizes are never touched.
*   **Server Compatibility Check:** Verifies that ImageMagick/Imagick or GD can save WebP files and warns administrators when conversion is unavailable.
*   **Debug Logging:** Includes a built-in logging system (`wp-content/uploads/auto-webp-converter/awc_debug.log`) that writes only when `WP_DEBUG` is enabled. The directory is protected from direct HTTP access.

The plugin uses the native WordPress image editor API (`wp_get_image_editor`), ensuring compatibility with both GD and ImageMagick libraries depending on your server configuration. WebP conversion requires either the Imagick PHP extension with WebP support or the GD PHP extension with WebP support.

== Installation ==

1.  Upload the `auto-webp-converter` folder to the `/wp-content/plugins/` directory (or install via the standard WordPress installer once available).
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to **Settings -> Auto WebP** to configure your preferences:
    *   Set **Max Width** & **Max Height**.
    *   Set **Quality**.
    *   Choose whether to **Delete original uploaded file**.
    *   Check the server WebP support status shown at the top of the settings page.

== Frequently Asked Questions ==

= Does this plugin affect images already in my Media Library? =
Automatic WebP conversion only applies to *new* uploads. For images already in the library there is an optional, manually triggered tool under **Settings -> Auto WebP -> Compress Existing Files**, which re-compresses existing JPEG files in place to reduce their file size without changing format, dimensions, file names or any database references. It is lossy and cannot be undone, so make a full backup of your `uploads` folder before running it.

= What happens if I upload an image smaller than the Max Width/Height? =
The image will not be upscaled. It will simply be converted to WebP (if it's a JPG/PNG) and saved.

= Where can I find the log file? =
If the plugin encounters errors or if you just want to verify operations, check the log file located at: `wp-content/uploads/auto-webp-converter/awc_debug.log`. Logging only runs when `WP_DEBUG` is set to `true` in `wp-config.php`; the directory is protected against direct HTTP access.

= What if my server does not support WebP conversion? =
The plugin shows an administrator notice and keeps uploaded JPG/PNG files unchanged. Ask your hosting provider to enable the Imagick PHP extension with WebP support or the GD PHP extension with WebP support.

= What happens to PNG transparency? =
WebP supports alpha channels, but the result depends on your server's image library. When WordPress uses ImageMagick, transparency is usually preserved; the GD backend may flatten transparent pixels to black in some edge cases. If you rely on transparent PNGs, verify the output looks correct on your server, or keep the originals (uncheck "Delete original uploaded file" in settings).

== Screenshots ==

1.  **Settings Page** - Easily configure dimensions, quality, and file handling preferences.

== Security ==

We take the security of this plugin seriously. If you discover a security vulnerability, please report it privately so it can be fixed before public disclosure.

*   **Contact:** tvorime@vyladeny-web.cz
*   **Please do not** open a public issue or disclose the vulnerability publicly until a fix has been released.
*   We aim to acknowledge reports within a reasonable time and to provide a fix through the plugin's built-in update mechanism.

The plugin's administrative actions (including the bulk "Compress Existing Files" tool) require the `manage_options` capability and are protected with WordPress nonces. File operations are restricted to the WordPress `uploads` directory.

== Third-party components ==

This plugin bundles the following third-party component:

*   **Plugin Update Checker** (YahnisElsts) – used to deliver plugin updates from the author's update server over HTTPS. Licensed under the MIT License. Located in the `plugin-update-checker/` directory. It is kept up to date as part of plugin maintenance.

== Privacy ==

The plugin processes images locally on your server and does not send image data to any external service. The built-in update checker contacts the author's update server (over HTTPS) only to check for new plugin versions.

Note on image metadata: when re-compressing existing JPEG files with the ImageMagick engine, embedded EXIF metadata (which can include the date taken, camera model and **GPS location**) is preserved. If you do not want location data embedded in publicly accessible images, strip metadata before publishing.

== Changelog ==

= 1.1.0 =
* Feature: Add "Compress Existing Files" tool (Settings -> Auto WebP) to re-compress existing JPEG files in the media library in place, with a live progress log and clickable links to each file.
* Privacy: With ImageMagick, EXIF metadata (date, GPS, camera) and the colour profile are preserved during bulk re-compression; PNG and WebP are skipped.
* Security: Restrict all bulk file operations to the uploads directory with hardened path checks; admin capability and nonce checks on all AJAX endpoints.
* Docs: Add Security (vulnerability disclosure), Third-party components and Privacy sections.

= 1.0.7 =
* Feature: Add server WebP support checks for ImageMagick/Imagick and GD.
* Fix: Skip conversion cleanly and keep originals unchanged when the server cannot save WebP files.

= 1.0.6 =
* Fix: Prevent WebP file name collisions when uploading images with the same original name.
* Security: Move debug log to a dedicated subdirectory (`uploads/auto-webp-converter/`) and drop `.htaccess`, `index.php` and `web.config` to block direct HTTP access.
* Fix: Only write to the debug log when `WP_DEBUG` is enabled.
* Fix: Clamp Max Width / Max Height settings to a maximum of 10000 px to prevent runaway memory usage.
* Fix: Rebuild the WebP URL from the upload directory instead of substring-replacing the basename.
* Fix: Handle uploads without a file extension when keeping the original (no more PHP notice and clean `_original` filename).
* Fix: Do not log WordPress-level upload errors; let WordPress surface them.
* Hygiene: Add `uninstall.php` that removes plugin options and log artifacts on plugin deletion.
* Hygiene: One-time cleanup of the pre-1.0.6 log file at `wp-content/uploads/awc_debug.log` after upgrade.

= 1.0.5 =
* Fix: Preserve correct orientation for portrait JPEG uploads based on EXIF metadata.

= 1.0.4 =
* Fix: Version in readme to update plugin properly

= 1.0.3 =
* Fix: Sjednoceny výchozí hodnoty na 2300x2300 px a kvalitu 95.
* Fix: Oprava logiky načítání výchozích rozměrů před uložením nastavení.

= 1.0.2 =
* Update info source to not overload repository.

= 1.0.1 =
* Update test.

= 1.0.0 =
* Initial release.
