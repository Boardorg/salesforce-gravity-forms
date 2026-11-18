<?php
/**
 * Works out which form, and which page, belongs to an event.
 *
 * There is no table to keep up to date: every Networking Profile form already
 * carries a Salesforce Event Code in its Form Settings, so the form for an event
 * is simply the form whose code matches the event's Conference code. A second
 * setting on the form names the page that shows it.
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Routing\FormMap;

// Set our aliases.
use SalesforceGravityForms\GravityForms\FormSettings;
use SalesforceGravityForms\Routing\Settings;
use SalesforceGravityForms\Salesforce\QueryBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Transient holding the list of forms that have an Event Code, and how long it is kept.
const CACHE_KEY         = 'sfgf_form_map';
const CACHE_TTL_SECONDS = HOUR_IN_SECONDS;

// Start our engines. Any change to a form, or to whether it is live, empties the cache.
add_action( 'gform_post_update_form_meta', __NAMESPACE__ . '\clear_cache' );
add_action( 'gform_post_form_trashed', __NAMESPACE__ . '\clear_cache' );
add_action( 'gform_post_form_restored', __NAMESPACE__ . '\clear_cache' );
add_action( 'gform_post_form_activated', __NAMESPACE__ . '\clear_cache' );
add_action( 'gform_post_form_deactivated', __NAMESPACE__ . '\clear_cache' );

/**
 * Forgets the cached list of forms.
 *
 * @return void
 */
function clear_cache() {
	delete_transient( CACHE_KEY );
}

/**
 * Every live form that has an Event Code, by form ID.
 *
 * @param bool $use_cache False to read the forms afresh (the admin screen does).
 * @return array<int, array{id: int, title: string, code: string, page_id: int}> Event Codes are upper case; page_id is the page chosen in Form Settings, or 0.
 */
function get_routed_forms( $use_cache = true ) {

	// Use the cached list when there is one.
	if ( $use_cache ) {
		$cached = get_transient( CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	// Read every live form and keep the ones with a usable Event Code.
	$routed = [];
	foreach ( (array) \GFAPI::get_forms( true, false ) as $form ) {

		// Skip anything that isn't a form.
		if ( ! is_array( $form ) ) {
			continue;
		}

		// A form without a valid Event Code isn't a Networking Profile form.
		$code = strtoupper( trim( (string) rgar( $form, FormSettings\EVENT_CODE_PROPERTY ) ) );
		if ( '' === $code || ! QueryBuilders\is_valid_event_code( $code ) ) {
			continue;
		}

		$form_id            = (int) rgar( $form, 'id' );
		$routed[ $form_id ] = [
			'id'      => $form_id,
			'title'   => (string) rgar( $form, 'title' ),
			'code'    => $code,
			'page_id' => absint( rgar( $form, FormSettings\PAGE_ID_PROPERTY ) ),
		];
	}

	// Keep it for next time.
	set_transient( CACHE_KEY, $routed, CACHE_TTL_SECONDS );

	return $routed;
}

/**
 * One routed form.
 *
 * @param int $form_id Gravity Forms form ID.
 * @return array|null The form's entry from get_routed_forms(), or null when it isn't a live form with an Event Code.
 */
function get_form( $form_id ) {
	$routed = get_routed_forms();
	return $routed[ (int) $form_id ] ?? null;
}

/**
 * The form for an Event Code. When two forms claim the same code the oldest one
 * wins, so a stray copy can never take over from the form already in use.
 *
 * @param string $code A Conference code, e.g. NALES.
 * @return array|null
 */
function find_by_code( $code ) {

	$code  = strtoupper( trim( (string) $code ) );
	$match = null;

	// Take the lowest form ID with that code.
	foreach ( get_routed_forms() as $form ) {
		if ( $form['code'] === $code && ( null === $match || $form['id'] < $match['id'] ) ) {
			$match = $form;
		}
	}

	return $match;
}

/**
 * Event Codes that more than one form claims.
 *
 * @param array|null $routed The list from get_routed_forms(), or null to read it.
 * @return array<string, int[]> Code => the IDs of the forms using it.
 */
function find_duplicate_codes( $routed = null ) {

	$routed = null === $routed ? get_routed_forms() : $routed;
	$by_code = [];
	foreach ( $routed as $form ) {
		$by_code[ $form['code'] ][] = $form['id'];
	}

	// Keep only the codes with more than one form.
	return array_filter(
		$by_code,
		function ( $ids ) {
			return count( $ids ) > 1;
		}
	);
}

/**
 * The page a form's visitors should be sent to.
 *
 * The page chosen in Form Settings, when it is a published page. When none is
 * chosen, the router page itself counts, but only if it really shows this form.
 * Otherwise the form has no page, and nobody is sent anywhere.
 *
 * @param array $form           An entry from get_routed_forms().
 * @param int   $router_page_id The router page's ID.
 * @return int The page's ID, or 0 when the form has no page.
 */
function get_effective_page_id( $form, $router_page_id ) {

	// A page chosen in Form Settings wins.
	$page_id = (int) $form['page_id'];
	if ( $page_id && is_published_page( $page_id ) ) {
		return $page_id;
	}

	// Otherwise the router page, if it shows this form.
	if ( $router_page_id && true === page_shows_form( $router_page_id, $form['id'] ) ) {
		return (int) $router_page_id;
	}

	return 0;
}

/**
 * Whether a page exists and is published.
 *
 * @param int $page_id A page's ID.
 * @return bool
 */
function is_published_page( $page_id ) {
	return 'page' === get_post_type( $page_id ) && 'publish' === get_post_status( $page_id );
}

/**
 * Whether a page's content shows a given form.
 *
 * @param int $page_id A page's ID.
 * @param int $form_id A form's ID.
 * @return bool|null True when the page shows the form, false when it shows other
 *                   forms but not this one, null when no form can be found in it
 *                   (a page builder keeps its content elsewhere, so we can't tell).
 */
function page_shows_form( $page_id, $form_id ) {

	// Find the forms the page's content refers to.
	$form_ids = content_form_ids( (string) get_post_field( 'post_content', $page_id ) );
	if ( empty( $form_ids ) ) {
		return null;
	}

	return in_array( (int) $form_id, $form_ids, true );
}

/**
 * The IDs of the forms a piece of content shows, by shortcode or by block.
 *
 * @param string $content A page's content.
 * @return int[]
 */
function content_form_ids( $content ) {

	$form_ids = [];

	// The form shortcode, in either spelling, with an id.
	if ( preg_match_all( '/\[gravityforms?\b[^\]]*?\bid\s*=\s*["\']?(\d+)/i', $content, $matches ) ) {
		$form_ids = array_merge( $form_ids, array_map( 'intval', $matches[1] ) );
	}

	// The Gravity Forms form block, with its formId.
	if ( preg_match_all( '/<!--\s*wp:gravityforms\/form\s+\{[^}]*"formId"\s*:\s*"?(\d+)"?/i', $content, $matches ) ) {
		$form_ids = array_merge( $form_ids, array_map( 'intval', $matches[1] ) );
	}

	return array_values( array_unique( $form_ids ) );
}

/**
 * The published pages, as choices for the "page that shows this form" setting.
 *
 * @return array<int, array{label: string, value: string}>
 */
function get_page_choices() {

	// The first choice means "no page chosen".
	$choices = [
		[
			'label' => __( '— The router page itself —', 'salesforce-gravity-forms' ),
			'value' => '',
		],
	];

	// Then every published page, by title.
	$pages = get_pages(
		[
			'post_status' => 'publish',
			'sort_column' => 'post_title',
			'sort_order'  => 'ASC',
		]
	);

	foreach ( (array) $pages as $page ) {
		$choices[] = [
			'label' => sprintf( '%s (/%s/)', $page->post_title, get_page_uri( $page ) ),
			'value' => (string) $page->ID,
		];
	}

	return $choices;
}

/**
 * Reads the overrides box. Each line is "name = form ID", where the name is an
 * Event Code (send everyone of that event to that form) or a single key (send
 * just that attendee). A line starting with # is a comment.
 *
 * @param string $text The overrides text.
 * @return array{keys: array<string, int>, events: array<string, int>, errors: string[]}
 */
function parse_overrides( $text ) {

	$overrides = [
		'keys'   => [],
		'events' => [],
		'errors' => [],
	];

	foreach ( preg_split( '/\R/', (string) $text ) as $index => $line ) {

		// Skip blank lines and comments.
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}

		// Each line is "name = number".
		if ( 1 !== preg_match( '/^([A-Za-z0-9_.\-]+)\s*=\s*(\d+)$/', $line, $parts ) ) {
			/* translators: 1: line number, 2: the line's text. */
			$overrides['errors'][] = sprintf( __( 'Line %1$d is not "name = form ID": %2$s', 'salesforce-gravity-forms' ), $index + 1, $line );
			continue;
		}

		$name    = strtoupper( $parts[1] );
		$form_id = (int) $parts[2];

		// A key is checked first, because a key also looks like an Event Code.
		if ( 1 === preg_match( '/^A-NP-\d{1,12}$/', $name ) ) {
			$overrides['keys'][ $name ] = $form_id;
		} elseif ( QueryBuilders\is_valid_event_code( $name ) ) {
			$overrides['events'][ $name ] = $form_id;
		} else {
			/* translators: 1: line number, 2: the name that isn't an Event Code or key. */
			$overrides['errors'][] = sprintf( __( 'Line %1$d: "%2$s" is neither an Event Code nor a key.', 'salesforce-gravity-forms' ), $index + 1, $parts[1] );
		}
	}

	return $overrides;
}

/**
 * The saved overrides, read.
 *
 * @return array{keys: array<string, int>, events: array<string, int>, errors: string[]}
 */
function get_overrides() {
	return parse_overrides( Settings\get_overrides_text() );
}

/**
 * The form an event's attendees should see: an override for the event if there
 * is one, otherwise the form whose Event Code matches.
 *
 * @param string     $conference An event's Conference code.
 * @param array|null $overrides  The overrides from get_overrides(), or null to read them.
 * @return array{form: array|null, via: string} The form (null when there isn't one) and how it was chosen.
 */
function resolve_form( $conference, $overrides = null ) {

	$conference = strtoupper( trim( (string) $conference ) );
	$overrides  = null === $overrides ? get_overrides() : $overrides;

	// An override for the whole event.
	if ( isset( $overrides['events'][ $conference ] ) ) {
		return [
			'form' => get_form( $overrides['events'][ $conference ] ),
			'via'  => 'event override',
		];
	}

	// Otherwise the form with that Event Code.
	return [
		'form' => find_by_code( $conference ),
		'via'  => 'event code',
	];
}
