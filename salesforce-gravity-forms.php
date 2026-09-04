<?php
/**
 * Plugin Name:       Salesforce Gravity Forms
 * Plugin URI:        https://github.com/Boardorg/salesforce-gravity-forms
 * Description:       Populates Gravity Forms Checkbox fields with sponsor companies queried directly from Salesforce.
 * Version:           0.1.0
 * Requires PHP:      8.2
 * Requires at least: 6.5
 * Author:            Peter Wiley
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       salesforce-gravity-forms
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Define our plugin version.
define( __NAMESPACE__ . '\VERS', '0.0.1' );

// Plugin root file.
define( __NAMESPACE__ . '\FILE', __FILE__ );

// Define our file base.
define( __NAMESPACE__ . '\BASE', plugin_basename( __FILE__ ) );

// Plugin folder URL.
define( __NAMESPACE__ . '\URL', plugin_dir_url( __FILE__ ) );

// Set our includes path constant.
define( __NAMESPACE__ . '\INCLUDES_PATH', __DIR__ . '/includes' );

// Set our templates path constant.
define( __NAMESPACE__ . '\TEMPLATES_PATH', __DIR__ . '/templates' );

// Slug used to register with Gravity Forms logging and as a cache-key prefix.
define( __NAMESPACE__ . '\SLUG', 'salesforce-gravity-forms' );

// Minimum PHP version this plugin supports.
define( __NAMESPACE__ . '\MIN_PHP_VERSION', '8.2' );

// Minimum Gravity Forms version this plugin has been checked against.
define( __NAMESPACE__ . '\MIN_GRAVITY_FORMS_VERSION', '2.7' );

// Defer file loading until plugins_loaded so Gravity Forms can load first.
add_action( 'plugins_loaded', __NAMESPACE__ . '\sfgf_bootstrap', 20 );

/**
 * Checks runtime dependencies and, if satisfied, loads the plugin's files.
 *
 * @return void
 */
function sfgf_bootstrap() {

	// Guard: refuse to run on an unsupported PHP version rather than risk a
	// fatal parse/runtime error further down the require chain.
	if ( version_compare( PHP_VERSION, MIN_PHP_VERSION, '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\sfgf_php_version_notice' );
		return;
	}

	// Guard: this plugin only has meaning alongside Gravity Forms.
	if ( ! class_exists( 'GFForms' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\sfgf_missing_gravityforms_notice' );
		return;
	}

	// Guard: warn (but do not hard-block) on an unverified Gravity Forms version.
	if ( version_compare( \GFForms::$version, MIN_GRAVITY_FORMS_VERSION, '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\sfgf_old_gravityforms_notice' );
	}

	// Dependencies satisfied so load the plugin's files.
	sfgf_file_load();
}

/**
 * Requires every file that makes up the plugin.
 *
 * @return void
 */
function sfgf_file_load() {

	// Configuration readers for the server-side Salesforce credentials.
	require_once INCLUDES_PATH . '/config/config.php';

	// Shared logging, cache-locking, and template-loading helpers used by
	// every other module.
	require_once INCLUDES_PATH . '/helpers/utilities.php';
	require_once INCLUDES_PATH . '/helpers/template.php';

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

	// Gravity Forms form/field settings.
	require_once INCLUDES_PATH . '/gravity-forms/form-settings.php';
	require_once INCLUDES_PATH . '/gravity-forms/field-settings.php';

	// Populates Checkbox fields using the Salesforce sponsors source, backed
	// by the stable checkbox-input registry.
	require_once INCLUDES_PATH . '/cache/checkbox-registry.php';
	require_once INCLUDES_PATH . '/gravity-forms/dynamic-choices.php';

	// Admin-only screens.
	if ( is_admin() ) {
		require_once INCLUDES_PATH . '/admin/credentials-settings.php';
	}

	// Register this plugin with Gravity Forms' logging system now that
	// GFLogging is guaranteed to be loaded.
	add_filter( 'gform_logging_supported', __NAMESPACE__ . '\Helpers\Utilities\register_logging_support' );
}

/**
 * Prints an admin notice when the PHP version guard fails.
 *
 * @return void
 */
function sfgf_php_version_notice() {

	// Guard: only administrators need to see infrastructure-level notices.
	if ( ! current_user_can( 'manage_options' ) ) return;
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Salesforce Gravity Forms requires PHP %1$s or higher. This server is running PHP %2$s.', 'salesforce-gravity-forms' ),
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
function sfgf_missing_gravityforms_notice() {

	// Guard: only administrators need to see infrastructure-level notices.
	if ( ! current_user_can( 'manage_options' ) ) return;
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Salesforce Gravity Forms requires Gravity Forms to be installed and active.', 'salesforce-gravity-forms' )
	);
}

/**
 * Prints an admin notice when Gravity Forms is active but older than the
 * version this plugin has been verified against. Non-blocking.
 *
 * @return void
 */
function sfgf_old_gravityforms_notice() {
	
	// Guard: only administrators need to see infrastructure-level notices.
	if ( ! current_user_can( 'manage_options' ) ) return;
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum verified Gravity Forms version. */
				__( 'Salesforce Gravity Forms has only been verified against Gravity Forms %s and newer. Some features may not work as expected.', 'salesforce-gravity-forms' ),
				MIN_GRAVITY_FORMS_VERSION
			)
		)
	);
}
