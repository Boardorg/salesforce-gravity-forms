<?php
/**
 * PHPUnit bootstrap. Loads WP_Mock plus minimal WordPress stand-ins for the
 * handful of core functions/classes this plugin's unit tests touch but
 * WP_Mock does not provide out of the box, then requires the plugin's
 * includes directly (this plugin has no autoloader by design).
 */

require_once __DIR__ . '/../vendor/autoload.php';

\WP_Mock::bootstrap();

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Normally defined by the main plugin file, which unit tests deliberately
// never load (it registers real WordPress hooks). Stand in for the one
// constant the includes actually read at runtime.
if ( ! defined( 'BoardMC\SalesforceGravityForms\SLUG' ) ) {
	define( 'BoardMC\SalesforceGravityForms\SLUG', 'boardmc-salesforce-gravity-forms' );
}

// Minimal WP_Error stand-in — enough for get_error_code()/get_error_message(),
// which is all this plugin's error handling relies on.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $errors      = [];
		protected $error_data  = [];

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) return;
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) $this->error_data[ $code ] = $data;
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) $code = $this->get_error_code();
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) $code = $this->get_error_code();
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}

// Fake (non-secret) Salesforce credentials shared by every test, standing in
// for the wp-config.php constants this plugin normally reads at runtime.
define( 'BOARDMC_SFGF_SALESFORCE_LOGIN_URL', 'https://test.my.salesforce.com' );
define( 'BOARDMC_SFGF_SALESFORCE_CLIENT_ID', 'test-client-id' );
define( 'BOARDMC_SFGF_SALESFORCE_CLIENT_SECRET', 'test-client-secret-do-not-log' );
define( 'BOARDMC_SFGF_SALESFORCE_API_VERSION', '59.0' );

require_once __DIR__ . '/../includes/config/config.php';
require_once __DIR__ . '/../includes/helpers/utilities.php';
require_once __DIR__ . '/../includes/cache/token-cache.php';
require_once __DIR__ . '/../includes/cache/choice-cache.php';
require_once __DIR__ . '/../includes/salesforce/authentication.php';
require_once __DIR__ . '/../includes/salesforce/client.php';
require_once __DIR__ . '/../includes/salesforce/query-builders.php';
require_once __DIR__ . '/../includes/salesforce/sponsor-records.php';
require_once __DIR__ . '/../includes/providers/choice-provider.php';
require_once __DIR__ . '/../includes/providers/salesforce-sponsor-provider.php';
