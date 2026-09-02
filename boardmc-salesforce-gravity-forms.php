<?php
/**
 * Plugin Name: BoardMC Salesforce Gravity Forms
 * Plugin URI:  https://github.com/Boardorg/boardmc-salesforce-gravity-forms
 * Description: Populates Gravity Forms Checkbox fields with sponsor companies queried directly from Salesforce.
 * Version:     0.1.0
 * Requires PHP: 8.2
 * Requires at least: 6.5
 * Author:      Peter Wiley
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: boardmc-salesforce-gravity-forms
 *
 * @package BoardMCSalesforceGravityForms
 */

// Declare our namespace.
namespace BoardMC\SalesforceGravityForms;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Define our plugin version.
define( __NAMESPACE__ . '\VERS', '0.1.0' );

// Plugin root file.
define( __NAMESPACE__ . '\FILE', __FILE__ );

// Define our file base.
define( __NAMESPACE__ . '\BASE', plugin_basename( __FILE__ ) );

// Plugin folder URL.
define( __NAMESPACE__ . '\URL', plugin_dir_url( __FILE__ ) );

// Set our includes path constant.
define( __NAMESPACE__ . '\INCLUDES_PATH', __DIR__ . '/includes' );

// Slug used to register with Gravity Forms logging and as a cache-key prefix.
define( __NAMESPACE__ . '\SLUG', 'boardmc-salesforce-gravity-forms' );

// Minimum PHP version this plugin supports. Kept separate from the "Requires
// PHP" header so the runtime guard below can produce a friendly notice
// instead of a fatal error on older hosts.
define( __NAMESPACE__ . '\MIN_PHP_VERSION', '8.2' );

// Minimum Gravity Forms version this plugin has been checked against.
// @todo confirm the real production Gravity Forms version (handoff doc
// question #9) and adjust if the site runs an older release.
define( __NAMESPACE__ . '\MIN_GRAVITY_FORMS_VERSION', '2.7' );

// Defer file loading until plugins_loaded so Gravity Forms (an activation
// dependency) has had a chance to load its own classes first. Priority 20
// runs after Gravity Forms' own default plugins_loaded registration.
add_action( 'plugins_loaded', __NAMESPACE__ . '\boardmc_sfgf_bootstrap', 20 );

/**
 * Checks runtime dependencies and, if satisfied, loads the plugin's files.
 *
 * @return void
 */
function boardmc_sfgf_bootstrap() {

	// Guard: refuse to run on an unsupported PHP version rather than risk a
	// fatal parse/runtime error further down the require chain.
	if ( version_compare( PHP_VERSION, MIN_PHP_VERSION, '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\boardmc_sfgf_php_version_notice' );
		return;
	}

	// Guard: this plugin only has meaning alongside Gravity Forms.
	if ( ! class_exists( 'GFForms' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\boardmc_sfgf_missing_gravityforms_notice' );
		return;
	}

	// Guard: warn (but do not hard-block) on an unverified Gravity Forms version.
	if ( version_compare( \GFForms::$version, MIN_GRAVITY_FORMS_VERSION, '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\boardmc_sfgf_old_gravityforms_notice' );
	}

	// Dependencies satisfied; load the plugin's files.
	boardmc_sfgf_file_load();
}

/**
 * Requires every file that makes up the plugin. Kept explicit (no
 * autoloading) so the load order is easy to read and trace.
 *
 * @return void
 */
function boardmc_sfgf_file_load() {

	// Configuration readers for the server-side Salesforce credentials.
	require_once INCLUDES_PATH . '/config/config.php';

	// Shared logging and cache-locking helpers used by every other module.
	require_once INCLUDES_PATH . '/helpers/utilities.php';

	// Persisted caches: the Salesforce access token and the normalized choice lists.
	require_once INCLUDES_PATH . '/cache/token-cache.php';
	require_once INCLUDES_PATH . '/cache/choice-cache.php';

	// Salesforce transport: authentication, the authenticated/paginated query
	// client, and the query-builder module that owns the sponsor WHERE clause.
	require_once INCLUDES_PATH . '/salesforce/authentication.php';
	require_once INCLUDES_PATH . '/salesforce/client.php';
	require_once INCLUDES_PATH . '/salesforce/query-builders.php';
	require_once INCLUDES_PATH . '/salesforce/sponsor-records.php';

	// Providers: convert raw Salesforce rows into the normalized choice
	// contract that a future (e.g. Gravity Forms) layer will consume.
	require_once INCLUDES_PATH . '/providers/choice-provider.php';
	require_once INCLUDES_PATH . '/providers/salesforce-sponsor-provider.php';

	// Register this plugin with Gravity Forms' logging system now that
	// GFLogging is guaranteed to be loaded.
	add_filter( 'gform_logging_supported', __NAMESPACE__ . '\Helpers\Utilities\register_logging_support' );
}

/**
 * Prints an admin notice when the PHP version guard fails.
 *
 * @return void
 */
function boardmc_sfgf_php_version_notice() {
	// Guard: only administrators need to see infrastructure-level notices.
	if ( ! current_user_can( 'manage_options' ) ) return;
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'BoardMC Salesforce Gravity Forms requires PHP %1$s or higher. This server is running PHP %2$s.', 'boardmc-salesforce-gravity-forms' ),
				MIN_PHP_VERSION,
				PHP_VERSION
			)
		)
	);
}

/**
 * Prints an admin notice when Gravity Forms is missing or inactive.
 *
 * @return void
 */
function boardmc_sfgf_missing_gravityforms_notice() {
	// Guard: only administrators need to see infrastructure-level notices.
	if ( ! current_user_can( 'manage_options' ) ) return;
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'BoardMC Salesforce Gravity Forms requires Gravity Forms to be installed and active.', 'boardmc-salesforce-gravity-forms' )
	);
}

/**
 * Prints an admin notice when Gravity Forms is active but older than the
 * version this plugin has been verified against. Non-blocking.
 *
 * @return void
 */
function boardmc_sfgf_old_gravityforms_notice() {
	// Guard: only administrators need to see infrastructure-level notices.
	if ( ! current_user_can( 'manage_options' ) ) return;
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum verified Gravity Forms version. */
				__( 'BoardMC Salesforce Gravity Forms has only been verified against Gravity Forms %s and newer. Some features may not work as expected.', 'boardmc-salesforce-gravity-forms' ),
				MIN_GRAVITY_FORMS_VERSION
			)
		)
	);
}
