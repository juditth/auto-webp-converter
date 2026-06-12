# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in **Auto WebP Converter**, please report it
privately so that it can be addressed before public disclosure.

- **Contact:** tvorime@vyladeny-web.cz
- Please include a description of the issue, the affected version, and steps to
  reproduce it where possible.
- **Do not** open a public issue or disclose the vulnerability publicly until a
  fix has been released.

We aim to acknowledge reports within a reasonable time, investigate promptly, and
deliver a fix through the plugin's built-in update mechanism. Coordinated
disclosure is appreciated.

## Supported Versions

Security fixes are provided for the latest released version of the plugin.
Please keep the plugin up to date to receive security updates.

| Version | Supported |
| ------- | --------- |
| 1.1.x   | ✅        |
| < 1.1   | ❌        |

## Security Design Notes

- All administrative actions, including the **Compress Existing Files** bulk tool,
  require the `manage_options` capability.
- All AJAX endpoints are protected with WordPress nonces (CSRF protection) and are
  available to logged-in administrators only (no unauthenticated access).
- User-supplied input is validated and sanitized; numeric settings are clamped to
  safe ranges.
- File operations are restricted to the WordPress `uploads` directory using
  hardened path checks; the plugin never reads or writes outside it.
- Updates are retrieved over HTTPS from the author's update server.

## Third-Party Components

This plugin bundles the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
library (MIT License) to deliver updates. It is kept up to date as part of plugin
maintenance.
