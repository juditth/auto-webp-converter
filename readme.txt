=== Auto WebP Converter ===
Contributors: jitka88
Tags: webp, convert, image optimization, resize, to webp
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.6
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
*   **Debug Logging:** Includes a built-in logging system (`wp-content/uploads/auto-webp-converter/awc_debug.log`) that writes only when `WP_DEBUG` is enabled. The directory is protected from direct HTTP access.

The plugin uses the native WordPress image editor API (`wp_get_image_editor`), ensuring compatibility with both GD and ImageMagick libraries depending on your server configuration.

== Installation ==

1.  Upload the `auto-webp-converter` folder to the `/wp-content/plugins/` directory (or install via the standard WordPress installer once available).
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to **Settings -> Auto WebP** to configure your preferences:
    *   Set **Max Width** & **Max Height**.
    *   Set **Quality**.
    *   Choose whether to **Delete original uploaded file**.

== Frequently Asked Questions ==

= Does this plugin affect images already in my Media Library? =
No. The plugin currently processes only *new* uploads that occur after the plugin is activated.

= What happens if I upload an image smaller than the Max Width/Height? =
The image will not be upscaled. It will simply be converted to WebP (if it's a JPG/PNG) and saved.

= Where can I find the log file? =
If the plugin encounters errors or if you just want to verify operations, check the log file located at: `wp-content/uploads/auto-webp-converter/awc_debug.log`. Logging only runs when `WP_DEBUG` is set to `true` in `wp-config.php`; the directory is protected against direct HTTP access.

= What happens to PNG transparency? =
WebP supports alpha channels, but the result depends on your server's image library. When WordPress uses ImageMagick, transparency is usually preserved; the GD backend may flatten transparent pixels to black in some edge cases. If you rely on transparent PNGs, verify the output looks correct on your server, or keep the originals (uncheck "Delete original uploaded file" in settings).

== Screenshots ==

1.  **Settings Page** - Easily configure dimensions, quality, and file handling preferences.

== Changelog ==

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
