<?php
/**
 * Sends each Networking Profile link to the form for the attendee's event.
 *
 * Every link in a Cvent email points at the same page. The key in the link
 * (?attendee=A-NP-0000000041) names one attendee registration, so the router asks
 * Salesforce which event that is, finds the form whose Salesforce Event Code
 * matches, and sends the visitor to that form's page. An edit link
 * (?ep_token=...) names a saved entry, so Easy Passthrough is asked which form
 * the entry belongs to.
 *
 * It fails closed: if the event has no form, the key isn't found, or Salesforce
 * can't be reached and nothing is cached, the visitor sees a short notice and
 * never another event's form.
 *
 * It runs on the router page and on every page a form is shown on, so a key can't
 * be used on the wrong event's page either. A plain visit with no key or token,
 * a submission (POST), and anything in wp-admin are left alone. It ships switched
 * off (Settings → Networking Profile Router).
 *
 * @package SalesforceGravityForms
 */

// Declare our namespace.
namespace SalesforceGravityForms\Routing\Router;

// Set our aliases.
use SalesforceGravityForms\GravityForms\KeySanitizer;
use SalesforceGravityForms\Helpers\Templates;
use SalesforceGravityForms\Helpers\Utilities;
use SalesforceGravityForms\Routing\FormMap;
use SalesforceGravityForms\Routing\KeyLookup;
use SalesforceGravityForms\Routing\Settings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// Start our engines. Early, so a visitor is sent on before WordPress adds a slash to the address.
add_action( 'template_redirect', __NAMESPACE__ . '\maybe_route', 5 );

/**
 * Routes the current request if it is a Networking Profile link on a page the
 * router watches.
 *
 * @return void
 */
function maybe_route() {

	// Nothing happens until the router has been switched on.
	if ( ! Settings\is_enabled() ) {
		return;
	}

	// Only a visitor opening a page: not wp-admin, background requests, or a form being submitted.
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
		return;
	}

	// Only the router page and the pages forms are shown on.
	$current_page_id = is_page() ? (int) get_queried_object_id() : 0;
	if ( ! $current_page_id || ! in_array( $current_page_id, get_watched_page_ids(), true ) ) {
		return;
	}

	// A plain visit, with no key and no edit link, is left alone.
	$request = request_from_globals();
	if ( null === $request['attendee'] && '' === $request['token'] ) {
		return;
	}

	// From here the answer depends on who is asking, so the page must never be cached for the next visitor.
	no_cache();

	// Decide, note what was decided, and act on it.
	$decision = decide( $request, default_deps( $current_page_id ) );
	log_decision( $decision );
	apply_decision( $decision, $request );
}

/**
 * The pages the router watches: the router page, and every page chosen for a form.
 *
 * @return int[]
 */
function get_watched_page_ids() {

	// The router page first.
	$page_ids = [ Settings\get_router_page_id() ];

	// Then the page chosen for each form.
	foreach ( FormMap\get_routed_forms() as $form ) {
		$page_ids[] = (int) $form['page_id'];
	}

	return array_values( array_unique( array_filter( $page_ids ) ) );
}

/**
 * Reads what the router needs from the address.
 *
 * @return array{attendee: mixed, token: string, query: array} The attendee parameter as sent (null when absent), the edit-link token, and every other parameter.
 */
function request_from_globals() {

	// Opening a link in an email carries no nonce, and nothing here changes anything.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$get = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// Easy Passthrough lets the token's parameter name be changed, so ask it.
	$token_param = (string) apply_filters( 'gpep_token_param_name', 'ep_token' );
	$token       = isset( $get[ $token_param ] ) && is_string( $get[ $token_param ] ) ? $get[ $token_param ] : '';

	// Keep every parameter except this plugin's own.
	$query = [];
	foreach ( $get as $name => $value ) {
		if ( 0 !== strpos( (string) $name, 'sfgf_' ) ) {
			$query[ $name ] = $value;
		}
	}

	return [
		'attendee' => array_key_exists( KeySanitizer\KEY_PARAMETER_NAME, $get ) ? $get[ KeySanitizer\KEY_PARAMETER_NAME ] : null,
		'token'    => $token,
		'query'    => $query,
	];
}

/**
 * Everything decide() needs from outside itself, so it can be tested with stand-ins.
 *
 * @param int $current_page_id The page being viewed.
 * @return array
 */
function default_deps( $current_page_id ) {

	$router_page_id = Settings\get_router_page_id();
	$overrides      = FormMap\get_overrides();

	return [
		'current_page_id'   => (int) $current_page_id,
		'overrides'         => $overrides,
		'clean_key'         => 'SalesforceGravityForms\Routing\KeyLookup\clean_key',
		'lookup'            => 'SalesforceGravityForms\Routing\KeyLookup\lookup',
		'form'              => 'SalesforceGravityForms\Routing\FormMap\get_form',
		'resolve_form'      => function ( $conference ) use ( $overrides ) {
			return FormMap\resolve_form( $conference, $overrides );
		},
		'effective_page_id' => function ( $form ) use ( $router_page_id ) {
			return FormMap\get_effective_page_id( $form, $router_page_id );
		},
		'token_form_id'     => __NAMESPACE__ . '\token_form_id',
	];
}

/**
 * Decides what to do with a request. Pure: it only reads what $deps hands it.
 *
 * @param array $request The request, from request_from_globals().
 * @param array $deps    The outside help, from default_deps().
 * @return array {
 *     @type string $action  'pass' (show the page as it is), 'redirect', or 'notice'.
 *     @type string $reason  Plain-English reason, for the log and the admin test.
 *     @type int    $form_id The form chosen, when there is one.
 *     @type int    $page_id The page to send the visitor to (redirect only).
 *     @type string $type    Which notice to show (notice only).
 * }
 */
function decide( array $request, array $deps ) {

	// An edit link names a saved entry, not an attendee, so it is looked at first.
	$token = (string) ( $request['token'] ?? '' );
	if ( '' !== $token ) {
		return decide_for_token( $token, $deps );
	}

	// A personal link carries the attendee's key.
	$raw_key = $request['attendee'] ?? null;
	if ( null !== $raw_key ) {
		return decide_for_key( $raw_key, $deps );
	}

	// Neither: an ordinary visit.
	return decision_pass( 'there is no key or edit link in the address' );
}

/**
 * Decides for a link carrying a key.
 *
 * @param mixed $raw_key The attendee parameter as sent.
 * @param array $deps    The outside help.
 * @return array
 */
function decide_for_key( $raw_key, array $deps ) {

	// A key has to be text.
	if ( ! is_string( $raw_key ) ) {
		return decision_notice( Settings\NOTICE_UNKNOWN, 'the key parameter is not text' );
	}

	// A blank key is treated as a plain visit, like it always was.
	if ( '' === trim( $raw_key ) ) {
		return decision_pass( 'the key is blank, so this is a plain visit' );
	}

	// Pull the key out of any stray characters around it.
	$key = (string) call_user_func( $deps['clean_key'], $raw_key );
	if ( '' === $key ) {
		return decision_notice( Settings\NOTICE_UNKNOWN, 'there is no valid key in the address' );
	}

	// An override for this one key needs no lookup.
	$overrides = $deps['overrides'];
	if ( isset( $overrides['keys'][ $key ] ) ) {
		return route_to_form( call_user_func( $deps['form'], $overrides['keys'][ $key ] ), 'key override', $deps );
	}

	// Ask Salesforce which event this key belongs to.
	$result = call_user_func( $deps['lookup'], $key );
	if ( is_wp_error( $result ) ) {
		return decision_notice( Settings\NOTICE_UNAVAILABLE, 'the Salesforce lookup failed (' . $result->get_error_code() . ')' );
	}
	if ( 'found' !== ( $result['status'] ?? '' ) ) {
		return decision_notice( Settings\NOTICE_UNKNOWN, 'no attendee has this key' );
	}

	// An event without a Conference code can't be matched to a form yet.
	$conference = strtoupper( trim( (string) ( $result['conference'] ?? '' ) ) );
	if ( '' === $conference ) {
		return decision_notice( Settings\NOTICE_NOT_OPEN, 'the attendee\'s event has no Conference code yet' );
	}

	// Find the form for that event.
	$resolved = call_user_func( $deps['resolve_form'], $conference );
	return route_to_form( $resolved['form'], $resolved['via'] . ' for ' . $conference, $deps );
}

/**
 * Decides for an edit link.
 *
 * @param string $token The edit-link token.
 * @param array  $deps  The outside help.
 * @return array
 */
function decide_for_token( $token, array $deps ) {

	// Which form does the saved entry behind this token belong to?
	$form_id = call_user_func( $deps['token_form_id'], $token );
	if ( ! $form_id ) {
		return decision_pass( 'the edit link is not recognised, so Easy Passthrough is left to deal with it' );
	}

	// A token for a form that isn't a Networking Profile form is none of the router's business.
	$form = call_user_func( $deps['form'], $form_id );
	if ( ! $form ) {
		return decision_pass( 'the edit link belongs to a form the router does not manage' );
	}

	// If that form's page can't be worked out, or is this one, leave the visitor here.
	$page_id = (int) call_user_func( $deps['effective_page_id'], $form );
	if ( ! $page_id || $page_id === (int) $deps['current_page_id'] ) {
		return decision_pass( 'the edit link is for this page\'s form', (int) $form['id'] );
	}

	return decision_redirect( $page_id, (int) $form['id'], 'edit link for form ' . (int) $form['id'] );
}

/**
 * Turns a chosen form into a decision: show this page, go to the form's page, or a notice.
 *
 * @param array|null $form A form from FormMap\get_form(), or null when there isn't one.
 * @param string     $why  How the form was chosen, for the reason.
 * @param array      $deps The outside help.
 * @return array
 */
function route_to_form( $form, $why, array $deps ) {

	// No form for this event yet.
	if ( ! $form ) {
		return decision_notice( Settings\NOTICE_NOT_OPEN, 'no form is set up for it (' . $why . ')' );
	}

	// A form with no page can't be shown to anyone.
	$page_id = (int) call_user_func( $deps['effective_page_id'], $form );
	if ( ! $page_id ) {
		return decision_notice( Settings\NOTICE_NOT_OPEN, 'form ' . (int) $form['id'] . ' has no page (' . $why . ')', (int) $form['id'] );
	}

	// Already on the right page.
	if ( $page_id === (int) $deps['current_page_id'] ) {
		return decision_pass( 'this page shows form ' . (int) $form['id'] . ' (' . $why . ')', (int) $form['id'] );
	}

	return decision_redirect( $page_id, (int) $form['id'], $why );
}

/**
 * A decision to show the page as it is.
 *
 * @param string   $reason  Why.
 * @param int|null $form_id The form the page shows, when known.
 * @return array
 */
function decision_pass( $reason, $form_id = null ) {
	return [
		'action'  => 'pass',
		'reason'  => $reason,
		'form_id' => $form_id,
	];
}

/**
 * A decision to send the visitor to another page.
 *
 * @param int    $page_id The page to send them to.
 * @param int    $form_id The form that page shows.
 * @param string $reason  Why.
 * @return array
 */
function decision_redirect( $page_id, $form_id, $reason ) {
	return [
		'action'  => 'redirect',
		'reason'  => $reason,
		'form_id' => $form_id,
		'page_id' => $page_id,
	];
}

/**
 * A decision to show a notice instead of a form.
 *
 * @param string   $type    One of the Settings\NOTICE_* constants.
 * @param string   $reason  Why.
 * @param int|null $form_id The form involved, when there is one.
 * @return array
 */
function decision_notice( $type, $reason, $form_id = null ) {
	return [
		'action'  => 'notice',
		'reason'  => $reason,
		'form_id' => $form_id,
		'type'    => $type,
	];
}

/**
 * Asks Easy Passthrough which form the entry behind an edit-link token belongs to.
 *
 * @param string $token The token from the link.
 * @return int|null The form's ID, or null when the token matches no entry (or Easy Passthrough isn't active).
 */
function token_form_id( $token ) {

	// Without Easy Passthrough there are no edit links to route.
	if ( ! function_exists( 'gp_easy_passthrough' ) ) {
		return null;
	}

	// Its own lookup, a plain database query.
	$entry = gp_easy_passthrough()->get_entry_for_token( $token );
	if ( empty( $entry ) || is_wp_error( $entry ) ) {
		return null;
	}

	return (int) rgar( $entry, 'form_id' ) ?: null;
}

/**
 * Carries out a decision.
 *
 * @param array $decision From decide().
 * @param array $request  The request, from request_from_globals().
 * @return void
 */
function apply_decision( $decision, $request ) {

	switch ( $decision['action'] ) {

		case 'redirect':
			// Build the address on the form's page, keeping everything that was in the link.
			$url = build_redirect_url( (int) $decision['page_id'], $request );
			if ( '' === $url ) {
				render_notice( Settings\NOTICE_NOT_OPEN );
				return;
			}
			header( 'X-SFGF-Route: redirect:' . (int) $decision['form_id'] );
			wp_safe_redirect( $url, 302 );
			exit;

		case 'notice':
			render_notice( $decision['type'] );
			return;

		default:
			// Show the page as it is.
			header( 'X-SFGF-Route: pass' );
	}
}

/**
 * The address of a form's page with the link's parameters carried over.
 *
 * @param int   $page_id The page to go to.
 * @param array $request The request, from request_from_globals().
 * @return string The address, or '' when the page has none.
 */
function build_redirect_url( $page_id, $request ) {

	// The page's own address.
	$url = get_permalink( $page_id );
	if ( ! $url ) {
		return '';
	}

	// Carry every parameter over, with the key cleaned of any stray characters.
	$args = $request['query'];
	if ( isset( $args[ KeySanitizer\KEY_PARAMETER_NAME ] ) && is_string( $args[ KeySanitizer\KEY_PARAMETER_NAME ] ) ) {
		$key = KeyLookup\clean_key( $args[ KeySanitizer\KEY_PARAMETER_NAME ] );
		if ( '' !== $key ) {
			$args[ KeySanitizer\KEY_PARAMETER_NAME ] = $key;
		}
	}

	return add_query_arg( urlencode_deep( $args ), $url );
}

/**
 * Shows a notice in place of the form, and stops.
 *
 * @param string $type One of the Settings\NOTICE_* constants.
 * @return void
 */
function render_notice( $type ) {

	// An outage is a 503 that says when to come back; the rest are ordinary pages.
	$is_outage = Settings\NOTICE_UNAVAILABLE === $type;
	status_header( $is_outage ? 503 : 200 );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-SFGF-Route: notice:' . $type );
	if ( $is_outage ) {
		header( 'Retry-After: 120' );
	}

	// The wording is an administrator's, with only simple formatting allowed.
	$title   = __( 'Networking Profile', 'salesforce-gravity-forms' );
	$message = wp_kses_post( wpautop( Settings\get_message( $type ) ) );

	// Inside the site's own header and footer when the theme has them.
	if ( locate_template( 'header.php' ) && locate_template( 'footer.php' ) ) {
		get_header();
		Templates\render(
			'public/router-notice.php',
			[
				'title'        => $title,
				'message_html' => $message,
			]
		);
		get_footer();
		exit;
	}

	// Otherwise a plain page.
	wp_die( $message, esc_html( $title ), [ 'response' => $is_outage ? 503 : 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already passed through wp_kses_post() above.
}

/**
 * Keeps this page out of every cache, because who sees what depends on the key.
 *
 * @return void
 */
function no_cache() {

	// The signal page caches (WP Engine's among them) look for.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	nocache_headers();
}

/**
 * Notes a decision in the log. Never the key or the token.
 *
 * @param array $decision From decide().
 * @return void
 */
function log_decision( $decision ) {
	Utilities\log(
		'debug',
		'Networking Profile link routed.',
		[
			'action'  => $decision['action'],
			'form_id' => $decision['form_id'] ?? null,
			'type'    => $decision['type'] ?? null,
			'reason'  => $decision['reason'],
		]
	);
}
