<?php
/**
 * Admin screen for the Networking Profile router: Settings → Networking Profile Router.
 *
 * Holds the router's switch and settings, shows every Networking Profile form with
 * the page it is shown on and the state of its feeds, lets an administrator paste a
 * link to see where the router would send it, and carries the two feed tools that
 * can't wait for a form to be duplicated or imported (copy a Salesforce feed, point
 * an Easy Passthrough feed at its own form).
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Admin\RouterSettings;

// Set our aliases.
use SalesforceGravityForms\GravityForms\FeedAutomation;
use SalesforceGravityForms\GravityForms\KeySanitizer;
use SalesforceGravityForms\Helpers\Templates;
use SalesforceGravityForms\Routing\FormMap;
use SalesforceGravityForms\Routing\KeyLookup;
use SalesforceGravityForms\Routing\Router;
use SalesforceGravityForms\Routing\Settings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Settings API group and page slug.
const SETTINGS_GROUP = 'sfgf_router';
const PAGE_SLUG      = 'sfgf-router';

// Nonce action/name for each separate tool, which post back to this page
// instead of to options.php.
const TEST_ACTION  = 'sfgf_router_test';
const TEST_NONCE   = 'sfgf_router_test_nonce';
const CACHE_ACTION = 'sfgf_router_clear_cache';
const CACHE_NONCE  = 'sfgf_router_clear_cache_nonce';
const COPY_ACTION  = 'sfgf_router_copy_feed';
const COPY_NONCE   = 'sfgf_router_copy_feed_nonce';
const FIX_ACTION   = 'sfgf_router_fix_passthrough';
const FIX_NONCE    = 'sfgf_router_fix_passthrough_nonce';

// Start our engines.
add_action( 'admin_menu', __NAMESPACE__ . '\add_settings_page' );
add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );

/**
 * Adds the screen under Settings.
 *
 * @return void
 */
function add_settings_page() {
	add_submenu_page(
		'options-general.php',
		__( 'Networking Profile Router', 'salesforce-gravity-forms' ),
		__( 'NP Router', 'salesforce-gravity-forms' ),
		'manage_options',
		PAGE_SLUG,
		__NAMESPACE__ . '\render_settings_page'
	);
}

/**
 * Registers the router's settings.
 *
 * @return void
 */
function register_settings() {

	// The switch and the page are read on every request to a watched page, so they load with the other options. The rest do not.
	add_option( Settings\OPTION_ENABLED, '0' );
	add_option( Settings\OPTION_PAGE_ID, 0 );
	add_option( Settings\OPTION_OVERRIDES, '', '', false );
	add_option( Settings\OPTION_TEMPLATE_FORM, 0, '', false );
	add_option( Settings\OPTION_MESSAGE_NOT_OPEN, '', '', false );
	add_option( Settings\OPTION_MESSAGE_UNKNOWN, '', '', false );
	add_option( Settings\OPTION_MESSAGE_UNAVAILABLE, '', '', false );

	register_setting( SETTINGS_GROUP, Settings\OPTION_ENABLED, [ 'type' => 'string', 'sanitize_callback' => __NAMESPACE__ . '\sanitize_enabled', 'default' => '0' ] );
	register_setting( SETTINGS_GROUP, Settings\OPTION_PAGE_ID, [ 'type' => 'integer', 'sanitize_callback' => __NAMESPACE__ . '\sanitize_page_id', 'default' => 0 ] );
	register_setting( SETTINGS_GROUP, Settings\OPTION_TEMPLATE_FORM, [ 'type' => 'integer', 'sanitize_callback' => __NAMESPACE__ . '\sanitize_template_form', 'default' => 0 ] );
	register_setting( SETTINGS_GROUP, Settings\OPTION_OVERRIDES, [ 'type' => 'string', 'sanitize_callback' => __NAMESPACE__ . '\sanitize_overrides', 'default' => '' ] );
	register_setting( SETTINGS_GROUP, Settings\OPTION_MESSAGE_NOT_OPEN, [ 'type' => 'string', 'sanitize_callback' => __NAMESPACE__ . '\sanitize_message', 'default' => '' ] );
	register_setting( SETTINGS_GROUP, Settings\OPTION_MESSAGE_UNKNOWN, [ 'type' => 'string', 'sanitize_callback' => __NAMESPACE__ . '\sanitize_message', 'default' => '' ] );
	register_setting( SETTINGS_GROUP, Settings\OPTION_MESSAGE_UNAVAILABLE, [ 'type' => 'string', 'sanitize_callback' => __NAMESPACE__ . '\sanitize_message', 'default' => '' ] );
}

/**
 * The switch: on only when the checkbox was ticked.
 *
 * @param mixed $input The submitted value (null when the box was left unticked).
 * @return string '1' or '0'.
 */
function sanitize_enabled( $input ) {
	return '1' === (string) $input ? '1' : '0';
}

/**
 * The router page: a published page, or nothing.
 *
 * @param mixed $input The submitted page ID.
 * @return int
 */
function sanitize_page_id( $input ) {

	$page_id = absint( $input );

	// Blank is allowed; anything else has to be a published page.
	if ( $page_id && ! FormMap\is_published_page( $page_id ) ) {
		add_settings_error( SETTINGS_GROUP, 'sfgf_router_page', __( 'The router page has to be a published page, so it was not saved.', 'salesforce-gravity-forms' ), 'error' );
		return 0;
	}

	return $page_id;
}

/**
 * The template form: a form that has an Event Code, or nothing.
 *
 * @param mixed $input The submitted form ID.
 * @return int
 */
function sanitize_template_form( $input ) {

	$form_id = absint( $input );
	$routed  = FormMap\get_routed_forms( false );

	// Blank is allowed; anything else has to be a Networking Profile form.
	if ( $form_id && ! isset( $routed[ $form_id ] ) ) {
		add_settings_error( SETTINGS_GROUP, 'sfgf_router_template', __( 'The template form has to be a live form with a Salesforce Event Code, so it was not saved.', 'salesforce-gravity-forms' ), 'error' );
		return 0;
	}

	return $form_id;
}

/**
 * The overrides box: kept as typed, with a warning for any line that can't be read.
 *
 * @param mixed $input The submitted text.
 * @return string
 */
function sanitize_overrides( $input ) {

	$text = sanitize_textarea_field( (string) $input );

	// Say which lines won't be used.
	foreach ( FormMap\parse_overrides( $text )['errors'] as $error ) {
		add_settings_error( SETTINGS_GROUP, 'sfgf_router_overrides', $error, 'warning' );
	}

	return $text;
}

/**
 * A notice's wording: simple formatting only.
 *
 * @param mixed $input The submitted text.
 * @return string
 */
function sanitize_message( $input ) {
	return wp_kses_post( (string) $input );
}

/**
 * Renders the screen, after carrying out whichever tool was used.
 *
 * @return void
 */
function render_settings_page() {

	// Check user capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Carry out a tool, if one was used.
	$test_input  = '';
	$test_result = null;
	if ( isset( $_POST['sfgf_router_test'] ) && check_admin_referer( TEST_ACTION, TEST_NONCE ) ) {
		$test_input  = isset( $_POST['sfgf_router_test_link'] ) ? sanitize_text_field( wp_unslash( $_POST['sfgf_router_test_link'] ) ) : '';
		$test_result = run_link_test( $test_input );
	}
	if ( isset( $_POST['sfgf_router_clear_cache'] ) && check_admin_referer( CACHE_ACTION, CACHE_NONCE ) ) {
		handle_clear_cache();
	}
	if ( isset( $_POST['sfgf_router_copy_feed'] ) && check_admin_referer( COPY_ACTION, COPY_NONCE ) ) {
		handle_copy_feed();
	}
	if ( isset( $_POST['sfgf_router_fix_passthrough'] ) && check_admin_referer( FIX_ACTION, FIX_NONCE ) ) {
		handle_fix_passthrough();
	}

	// Show what the tools (and a save) had to say.
	settings_errors( SETTINGS_GROUP );

	// Everything the screen shows is read fresh: this is where changes get checked.
	$routed         = FormMap\get_routed_forms( false );
	$rows           = build_status_rows( $routed );
	$router_page_id = Settings\get_router_page_id();

	Templates\render(
		'admin/router-settings.php',
		[
			'settings_group'     => SETTINGS_GROUP,
			'enabled'            => Settings\is_enabled(),
			'router_page_id'     => $router_page_id,
			'router_page_url'    => $router_page_id ? (string) get_permalink( $router_page_id ) : '',
			'page_choices'       => get_page_choices(),
			'template_form_id'   => Settings\get_template_form_id(),
			'routed_forms'       => $routed,
			'overrides_text'     => Settings\get_overrides_text(),
			'options'            => [
				'enabled'   => Settings\OPTION_ENABLED,
				'page'      => Settings\OPTION_PAGE_ID,
				'template'  => Settings\OPTION_TEMPLATE_FORM,
				'overrides' => Settings\OPTION_OVERRIDES,
			],
			'messages'           => get_message_fields(),
			'rows'               => $rows,
			'copy_sources'       => array_filter( $routed, function ( $form ) { return FeedAutomation\has_salesforce_feed( $form['id'] ); } ),
			'copy_targets'       => array_filter( $routed, function ( $form ) { return ! FeedAutomation\has_salesforce_feed( $form['id'] ); } ),
			'test_input'         => $test_input,
			'test_result'        => $test_result,
			'actions'            => [
				'test'  => [ TEST_ACTION, TEST_NONCE ],
				'cache' => [ CACHE_ACTION, CACHE_NONCE ],
				'copy'  => [ COPY_ACTION, COPY_NONCE ],
				'fix'   => [ FIX_ACTION, FIX_NONCE ],
			],
		]
	);
}

/**
 * The published pages, as id => label, for the router page choice.
 *
 * @return array<int, string>
 */
function get_page_choices() {

	$choices = [];
	foreach ( FormMap\get_page_choices() as $choice ) {

		// The first choice stands for "none" in the form settings; here "none" is just blank.
		if ( '' === $choice['value'] ) {
			continue;
		}
		$choices[ (int) $choice['value'] ] = $choice['label'];
	}

	return $choices;
}

/**
 * The three notice texts, with their option names and defaults.
 *
 * @return array<string, array{label: string, option: string, value: string, default: string}>
 */
function get_message_fields() {

	$labels = [
		Settings\NOTICE_NOT_OPEN    => __( 'The event has no form yet', 'salesforce-gravity-forms' ),
		Settings\NOTICE_UNKNOWN     => __( 'The link isn\'t recognised', 'salesforce-gravity-forms' ),
		Settings\NOTICE_UNAVAILABLE => __( 'Salesforce can\'t be reached', 'salesforce-gravity-forms' ),
	];

	$fields = [];
	foreach ( $labels as $type => $label ) {
		$option          = Settings\get_message_option( $type );
		$fields[ $type ] = [
			'label'   => $label,
			'option'  => $option,
			'value'   => (string) get_option( $option, '' ),
			'default' => Settings\get_default_message( $type ),
		];
	}

	return $fields;
}

/**
 * One row for every Networking Profile form: its code, page and feeds, and anything wrong with them.
 *
 * @param array $routed The forms from FormMap\get_routed_forms().
 * @return array
 */
function build_status_rows( array $routed ) {

	$router_page_id = Settings\get_router_page_id();
	$duplicates     = FormMap\find_duplicate_codes( $routed );
	$overrides      = FormMap\get_overrides();

	// Work out each form's page first, so two forms on one page can be spotted.
	$pages = [];
	foreach ( $routed as $form_id => $form ) {
		$pages[ $form_id ] = FormMap\get_effective_page_id( $form, $router_page_id );
	}
	$page_counts = array_count_values( array_filter( $pages ) );

	$rows = [];
	foreach ( $routed as $form_id => $form ) {

		$page_id = $pages[ $form_id ];
		$feeds   = FeedAutomation\describe_feeds( $form_id );
		$notes   = [];
		$errors  = false;

		// Where the page came from, and whether it really shows the form.
		$source = 'none';
		if ( $form['page_id'] && FormMap\is_published_page( $form['page_id'] ) ) {
			$source = 'setting';
		} elseif ( $page_id ) {
			$source = 'detected';
		}
		$shows = $page_id ? FormMap\page_shows_form( $page_id, $form_id ) : null;

		// What is wrong, if anything.
		if ( ! $page_id ) {
			$notes[] = __( 'No page: choose one under Form Settings → Networking Profile Page. Until then its attendees see the "no form yet" notice.', 'salesforce-gravity-forms' );
			$errors  = true;
		} elseif ( false === $shows ) {
			$notes[] = __( 'That page shows a different form, not this one.', 'salesforce-gravity-forms' );
			$errors  = true;
		} elseif ( null === $shows ) {
			$notes[] = __( 'Can\'t tell whether that page shows this form (no form shortcode or block was found in it). Open it and check.', 'salesforce-gravity-forms' );
		}
		if ( $page_id && $page_counts[ $page_id ] > 1 ) {
			$notes[] = __( 'Another form uses the same page.', 'salesforce-gravity-forms' );
			$errors  = true;
		}
		if ( isset( $duplicates[ $form['code'] ] ) ) {
			/* translators: %s: the form IDs that share the code. */
			$notes[] = sprintf( __( 'Forms %s share this Event Code; the lowest form ID is used.', 'salesforce-gravity-forms' ), implode( ', ', $duplicates[ $form['code'] ] ) );
			$errors  = true;
		}
		if ( 0 === $feeds['salesforce'] ) {
			$notes[] = __( 'No Salesforce feed: submissions won\'t reach Salesforce. Copy one below.', 'salesforce-gravity-forms' );
			$errors  = true;
		}

		// Each Easy Passthrough feed should take its answers from this form.
		$passthrough = [];
		foreach ( $feeds['passthrough'] as $feed ) {
			$feed['ok']    = $feed['source'] === $form_id;
			$passthrough[] = $feed;
			if ( ! $feed['ok'] ) {
				$notes[] = __( 'An Easy Passthrough feed takes its answers from another form, so edit links for this form load nothing.', 'salesforce-gravity-forms' );
				$errors  = true;
			}
		}
		if ( ! $passthrough ) {
			$notes[] = __( 'No Easy Passthrough feed: edit links for this form won\'t fill it in.', 'salesforce-gravity-forms' );
		}

		$rows[] = [
			'form'        => $form,
			'page_id'     => $page_id,
			'page_title'  => $page_id ? get_the_title( $page_id ) : '',
			'page_url'    => $page_id ? (string) get_permalink( $page_id ) : '',
			'page_source' => $source,
			'shows'       => $shows,
			'salesforce'  => $feeds['salesforce'],
			'passthrough' => $passthrough,
			'notes'       => $notes,
			'has_errors'  => $errors,
			'is_override' => in_array( $form_id, $overrides['events'], true ) || in_array( $form_id, $overrides['keys'], true ),
		];
	}

	return $rows;
}

/**
 * Shows where the router would send a pasted link, the way a visitor opening it would be sent.
 * Always asks Salesforce afresh. Changes nothing.
 *
 * @param string $input A whole link, a key, or an edit token.
 * @return array
 */
function run_link_test( $input ) {

	// Work out what was pasted.
	$request = read_pasted_link( $input );

	// Nothing recognisable in it.
	if ( null === $request['attendee'] && '' === $request['token'] ) {
		return [ 'kind' => 'none' ];
	}

	// Pretend to be a visit to the router page, with the lookup always live.
	$deps           = Router\default_deps( Settings\get_router_page_id() );
	$deps['lookup'] = function ( $key ) {
		return KeyLookup\lookup( $key, true );
	};
	$decision       = Router\decide( $request, $deps );

	$result = [
		'kind'        => '' !== $request['token'] ? 'token' : 'key',
		'decision'    => $decision,
		'destination' => 'redirect' === $decision['action'] ? (string) get_permalink( (int) $decision['page_id'] ) : '',
		'message'     => 'notice' === $decision['action'] ? wp_strip_all_tags( Settings\get_message( $decision['type'] ) ) : '',
	];

	// For a key, show what Salesforce says about it.
	if ( 'key' === $result['kind'] ) {
		$key               = KeyLookup\clean_key( (string) $request['attendee'] );
		$result['key']     = $key;
		$result['details'] = '' === $key ? null : KeyLookup\lookup_details( $key );
	}

	// For an edit link, show which form it belongs to (never the token itself).
	if ( 'token' === $result['kind'] ) {
		$form_id           = Router\token_form_id( $request['token'] );
		$routed            = FormMap\get_routed_forms( false );
		$result['form_id'] = $form_id;
		$result['form']    = $form_id && isset( $routed[ $form_id ] ) ? $routed[ $form_id ] : null;
	}

	return $result;
}

/**
 * Reads a pasted link, key or token into the shape the router works with.
 *
 * @param string $input What was pasted.
 * @return array{attendee: string|null, token: string, query: array}
 */
function read_pasted_link( $input ) {

	$input = trim( (string) $input );
	$query = [];

	// A whole link, or just its "name=value&name=value" part.
	$parts = wp_parse_url( $input );
	if ( is_array( $parts ) && isset( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	} elseif ( false !== strpos( $input, '=' ) ) {
		parse_str( ltrim( $input, '?' ), $query );
	}

	$token_param = (string) apply_filters( 'gpep_token_param_name', 'ep_token' );
	$request     = [
		'attendee' => null,
		'token'    => '',
		'query'    => [],
	];

	if ( isset( $query[ $token_param ] ) && is_string( $query[ $token_param ] ) ) {
		$request['token'] = sanitize_text_field( $query[ $token_param ] );
	} elseif ( isset( $query[ KeySanitizer\KEY_PARAMETER_NAME ] ) && is_string( $query[ KeySanitizer\KEY_PARAMETER_NAME ] ) ) {
		$request['attendee'] = sanitize_text_field( $query[ KeySanitizer\KEY_PARAMETER_NAME ] );
	} elseif ( '' !== KeyLookup\clean_key( $input ) ) {
		// Just a key.
		$request['attendee'] = $input;
	}

	return $request;
}

/**
 * Forgets every cached key lookup and the cached list of forms.
 *
 * @return void
 */
function handle_clear_cache() {

	KeyLookup\clear_cache();
	FormMap\clear_cache();

	add_settings_error(
		SETTINGS_GROUP,
		'sfgf_router_cache',
		__( 'Cleared. The next visit to each link asks Salesforce again, and the forms are read afresh.', 'salesforce-gravity-forms' ),
		'success'
	);
}

/**
 * Copies one form's Salesforce feed onto another.
 *
 * @return void
 */
function handle_copy_feed() {

	// Nonce checked by the caller.
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$from_id = isset( $_POST['sfgf_router_copy_from'] ) ? absint( $_POST['sfgf_router_copy_from'] ) : 0;
	$to_id   = isset( $_POST['sfgf_router_copy_to'] ) ? absint( $_POST['sfgf_router_copy_to'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$result = FeedAutomation\copy_salesforce_feeds( $from_id, $to_id );

	if ( is_wp_error( $result ) ) {
		add_settings_error( SETTINGS_GROUP, 'sfgf_router_copy', $result->get_error_message(), 'error' );
		return;
	}

	add_settings_error(
		SETTINGS_GROUP,
		'sfgf_router_copy',
		/* translators: 1: number of feeds, 2: the source form ID, 3: the target form ID. */
		sprintf( _n( 'Copied %1$d Salesforce feed from form %2$d to form %3$d.', 'Copied %1$d Salesforce feeds from form %2$d to form %3$d.', $result, 'salesforce-gravity-forms' ), $result, $from_id, $to_id ),
		'success'
	);
}

/**
 * Points one form's Easy Passthrough feeds at the form itself.
 *
 * @return void
 */
function handle_fix_passthrough() {

	// Nonce checked by the caller.
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$form_id = isset( $_POST['sfgf_router_fix_form'] ) ? absint( $_POST['sfgf_router_fix_form'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// Any other Networking Profile form is a wrong place to take answers from.
	$routed = FormMap\get_routed_forms( false );
	if ( ! isset( $routed[ $form_id ] ) ) {
		add_settings_error( SETTINGS_GROUP, 'sfgf_router_fix', __( 'That form has no Salesforce Event Code, so it was left alone.', 'salesforce-gravity-forms' ), 'error' );
		return;
	}

	$stale   = array_map( 'intval', array_diff( array_keys( $routed ), [ $form_id ] ) );
	$changed = FeedAutomation\repoint_easy_passthrough( $form_id, $stale );

	add_settings_error(
		SETTINGS_GROUP,
		'sfgf_router_fix',
		$changed
			? __( 'The Easy Passthrough feed now takes its answers from this form.', 'salesforce-gravity-forms' )
			: __( 'Nothing needed changing.', 'salesforce-gravity-forms' ),
		$changed ? 'success' : 'info'
	);
}
