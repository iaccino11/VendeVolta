=== VendeVolta for WooCommerce ===
Contributors: iaccino11
Tags: woocommerce, abandoned cart, search analytics, ecommerce, recovery
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 2.5.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Discover what your customers are searching for, identify abandoned carts, and find opportunities to sell more.

== Description ==

VendeVolta logs zero-result searches and abandoned carts in WooCommerce and displays a dashboard with the most relevant recovery opportunities.

Features:

- Zero-result search logging with frequency counter.
- Automatic abandoned cart detection after a configurable window (default: 2h).
- Guest-to-logged-in cart merge on login.
- WooCommerce HPOS compatibility.
- AJAX search capture that works under page cache.
- WordPress Privacy API integration (export/erase).

== Installation ==

1. Upload the `vendevolta` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to WooCommerce > VendeVolta.

== Frequently Asked Questions ==

= Does it require WooCommerce? =
Yes. The plugin does not work without an active WooCommerce install.

= Is any data sent to external servers? =
No. Everything stays in your own site's database.

= Can I change the abandonment window? =
Yes, via the `vendevolta_abandon_window` filter (in seconds).

= Can I change the retention period? =
Yes, via the `vendevolta_retention_days` filter (in days, default 90).

== Screenshots ==

1. Dashboard overview showing zero-result searches and abandoned cart recovery opportunities.

== Changelog ==

= 2.5.2 =
- Fix: Bumped "Tested up to" to 7.1 (current WordPress version).
- Fix: Silenced read-only nonce warnings for $_GET['tab'] in the dashboard.
- Fix: Updated PRO link to the official site (brasilviavel.com).
- Fix: Escaped dynamic class output in the dashboard table.
- Fix: Added phpcs:ignore for the $_GET term read after nonce verification.
- Chore: Added "Requires Plugins: woocommerce" header.
- Chore: Added missing banners, icons, and screenshot assets.

= 2.5.1 =
- Fix: Updated "Tested up to" compatibility tag.
- Fix: Corrected "Tested up to" to 6.7.
- Fix: Updated Contributors to iaccino11.
- Fix: Prefixed local template variables in `admin/views/dashboard.php` (`$vendevolta_cart_headers`, `$vendevolta_cart`, `$vendevolta_when`, `$vendevolta_search_headers`, `$vendevolta_row`) to satisfy `WordPress.NamingConventions.PrefixAllGlobals`.
- Fix: Prefixed `$vendevolta_sites` and `$vendevolta_site_id` in `uninstall.php`.
- Chore: Bumped plugin version to 2.5.1.

= 2.5.0 =
- Fix: Unique prefix `vendevolta_` (11 characters) for all functions, classes, constants, options, hooks, AJAX actions, script handles and database tables. Data from previous versions is migrated automatically.
- Fix: Plugin display name changed to "VendeVolta for WooCommerce".
- Fix: Removed remaining inline style attributes; widths moved to the enqueued stylesheet.
- Fix: Corrected phpcs disable comments and leftover references to the old name.
- Fix: "Tested up to" updated.
- Note: Public filter/action names changed from `dvp_*` to `vendevolta_*`.

= 2.4.4 =
- Fix: Action Scheduler group renamed from `radar-de-vendas` to `vendevolta`.
- Fix: Added `robertoadriano` to the contributors list.
- Chore: Removed unused `VENDEVOLTA_TEXT_DOMAIN` constant.
- Chore: Moved inline styles from `admin/views/dashboard.php` to `admin/css/dashboard.css`.

= 2.4.3 =
- Renamed plugin to "VendeVolta" for distinct branding and WordPress.org compliance.
- Moved inline JavaScript to an external enqueued file (assets/js/search-capture.js).
- Moved inline CSS to an external enqueued file (admin/css/dashboard.css).
- Properly escaped filter outputs in the dashboard using wp_kses_post().
- Fixed contributors list in readme.txt.

== Upgrade Notice ==

= 2.5.2 =
Maintenance release. Aligns "Tested up to" with the current WordPress version, silences read-only nonce warnings, updates the PRO link to the official domain, and adds plugin directory assets (icons, banners, and screenshot).

= 2.5.1 =
Maintenance release. Fixes Plugin Check warnings and updates compatibility tags.

= 2.5.0 =
Hooks and filters were renamed from `dvp_*` to `vendevolta_*`. Existing data is migrated automatically.