=== Auto WebP Converter ===
Contributors: jitka88
Tags: webp, convert, image optimization, resize, to webp
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically converts uploaded images to WebP, resizes them, and optionally deletes originals.

== Description ==

Auto WebP Converter is a lightweight, efficient plugin designed to streamline your image workflow. Upon upload, it automatically detects JPEG and PNG images, resizes them to your specified dimensions, and converts them to the next-gen WebP format.

**Key Features:**

*   **Automatic WebP Conversion:** Seamlessly converts uploaded JPG and PNG files to WebP.
*   **Smart Resizing:** Automatically resizes images that exceed your defined maximum width and height limits (default: 1920x1080).
*   **Original File Management:** You decide what happens to the source file – delete it to save disk space, or keep it renamed with an `_original` suffix.
*   **Quality Control:** Adjustable conversion quality setting (0-100).
*   **Debug Logging:** Includes a built-in logging system (`wp-content/uploads/awc_debug.log`) to track conversions and troubleshoot issues.

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
If the plugin encounters errors or if you just want to verify operations, check the log file located at: `wp-content/uploads/awc_debug.log`.

== Screenshots ==

1.  **Settings Page** - Easily configure dimensions, quality, and file handling preferences.

== Changelog ==

= 1.0.0 =
*   Initial release.
