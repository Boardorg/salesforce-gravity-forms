<?php

use WP_Mock\Tools\TestCase;
use BoardMC\SalesforceGravityForms\Salesforce\Authentication;
use BoardMC\SalesforceGravityForms\Salesforce\Client;
use BoardMC\SalesforceGravityForms\Cache\TokenCache;

/**
 * Covers Authentication + Client together, mocked at the WordPress HTTP API
 * boundary (wp_remote_*, transients, object cache) — the same boundary the
 * handoff doc's "mocked HTTP tests" instruction targets. The two modules
 * can't be usefully isolated from each other without a runtime function
 * -mocking extension, since Client calls Authentication's namespaced
 * functions directly.
 *
 * @covers \BoardMC\SalesforceGravityForms\Salesforce\Authentication
 * @covers \BoardMC\SalesforceGravityForms\Salesforce\Client
 */
class ClientTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_authenticate_reuses_a_still_fresh_cached_token() {
		\WP_Mock::userFunction( 'get_transient', [
			'args'   => [ TokenCache\TRANSIENT_KEY ],
			'return' => [
				'access_token' => 'cached-token',
				'instance_url' => 'https://cached.my.salesforce.com',
				'expires_at'   => time() + 999,
			],
		] );

		// wp_remote_post is intentionally NOT stubbed: if authenticate()
		// tried to fetch a new token despite the cache being fresh, WP_Mock
		// would fail this test on the unexpected call.
		$result = Authentication\authenticate();

		$this->assertSame( 'cached-token', $result['access_token'] );
		$this->assertSame( 'https://cached.my.salesforce.com', $result['instance_url'] );
	}

	public function test_fetch_token_sends_client_credentials_grant() {
		\WP_Mock::userFunction( 'get_transient', [ 'return' => false ] );
		\WP_Mock::userFunction( 'wp_cache_add', [ 'return' => true ] );
		\WP_Mock::userFunction( 'wp_cache_delete', [ 'return' => true ] );
		\WP_Mock::userFunction( 'set_transient', [ 'return' => true ] );

		\WP_Mock::userFunction( 'wp_remote_post', [
			'times'  => 1,
			'return' => function ( $url, $args ) {
				$this->assertSame( 'https://test.my.salesforce.com/services/oauth2/token', $url );
				$this->assertSame( 'client_credentials', $args['body']['grant_type'] );
				$this->assertSame( 'test-client-id', $args['body']['client_id'] );
				$this->assertSame( 'test-client-secret-do-not-log', $args['body']['client_secret'] );
				return [ '__fake_response' => true ];
			},
		] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [ 'return' => 200 ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body', [
			'return' => json_encode( [ 'access_token' => 'brand-new-token', 'instance_url' => 'https://inst.my.salesforce.com' ] ),
		] );

		$result = Authentication\authenticate();

		$this->assertSame( 'brand-new-token', $result['access_token'] );
		$this->assertSame( 'https://inst.my.salesforce.com', $result['instance_url'] );
	}

	public function test_auth_failure_never_leaks_the_client_secret_in_the_returned_error() {
		\WP_Mock::userFunction( 'get_transient', [ 'return' => false ] );
		\WP_Mock::userFunction( 'wp_cache_add', [ 'return' => true ] );
		\WP_Mock::userFunction( 'wp_cache_delete', [ 'return' => true ] );

		\WP_Mock::userFunction( 'wp_remote_post', [ 'return' => [ '__fake_response' => true ] ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [ 'return' => 400 ] );
		// A hostile/careless Salesforce error response might echo the
		// submitted secret back — our code must not forward that into the
		// error it returns to callers (which may end up in a form-facing
		// message or a less-careful log call upstream).
		\WP_Mock::userFunction( 'wp_remote_retrieve_body', [
			'return' => json_encode( [ 'error' => 'invalid_client', 'error_description' => 'client secret test-client-secret-do-not-log is wrong' ] ),
		] );

		$result = Authentication\authenticate();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertStringNotContainsString( 'test-client-secret-do-not-log', $result->get_error_message() );
	}

	public function test_query_combines_every_paginated_page() {
		\WP_Mock::userFunction( 'get_transient', [
			'return' => [ 'access_token' => 'tok', 'instance_url' => 'https://inst.my.salesforce.com', 'expires_at' => time() + 999 ],
		] );

		\WP_Mock::userFunction( 'wp_remote_get', [
			'return' => function ( $url ) {
				return [ 'page' => ( false !== strpos( $url, '/query/next-page-token' ) ) ? 2 : 1 ];
			},
		] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [ 'return' => 200 ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body', [
			'return' => function ( $response ) {
				if ( 2 === $response['page'] ) {
					return json_encode( [
						'records' => [ [ 'Id' => 'a2', 'Delegate__r' => [ 'AccountId' => '001B' ] ] ],
						'done'    => true,
					] );
				}
				return json_encode( [
					'records'        => [ [ 'Id' => 'a1', 'Delegate__r' => [ 'AccountId' => '001A' ] ] ],
					'done'           => false,
					'nextRecordsUrl' => '/services/data/v59.0/query/next-page-token',
				] );
			},
		] );

		$records = Client\query( 'SELECT Id FROM Attendee__c' );

		$this->assertIsArray( $records );
		$this->assertCount( 2, $records );
		$this->assertSame( 'a1', $records[0]['Id'] );
		$this->assertSame( 'a2', $records[1]['Id'] );
	}

	public function test_invalid_session_clears_cache_and_retries_exactly_once() {
		$get_transient_calls = 0;
		\WP_Mock::userFunction( 'get_transient', [
			'return' => function () use ( &$get_transient_calls ) {
				$get_transient_calls++;
				// First lookup: the stale token that's about to be rejected.
				// Second lookup (post-invalidate): a different, working token.
				$token = 1 === $get_transient_calls ? 'stale-token' : 'fresh-token';
				return [ 'access_token' => $token, 'instance_url' => 'https://inst.my.salesforce.com', 'expires_at' => time() + 999 ];
			},
		] );
		\WP_Mock::userFunction( 'delete_transient', [ 'times' => 1, 'args' => [ TokenCache\TRANSIENT_KEY ] ] );

		\WP_Mock::userFunction( 'wp_remote_get', [
			'times'  => 2,
			'return' => function ( $url, $args ) {
				return [ 'authorized' => ( 'Bearer fresh-token' === $args['headers']['Authorization'] ) ];
			},
		] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [
			'return' => function ( $response ) {
				return $response['authorized'] ? 200 : 401;
			},
		] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body', [
			'return' => function ( $response ) {
				if ( ! $response['authorized'] ) {
					return json_encode( [ [ 'errorCode' => 'INVALID_SESSION_ID', 'message' => 'Session expired or invalid' ] ] );
				}
				return json_encode( [ 'records' => [ [ 'Id' => 'a1' ] ], 'done' => true ] );
			},
		] );

		$records = Client\query( 'SELECT Id FROM Attendee__c' );

		$this->assertIsArray( $records );
		$this->assertCount( 1, $records );
	}

	public function test_a_second_invalid_session_response_stops_instead_of_looping() {
		\WP_Mock::userFunction( 'get_transient', [
			'return' => [ 'access_token' => 'always-stale', 'instance_url' => 'https://inst.my.salesforce.com', 'expires_at' => time() + 999 ],
		] );
		\WP_Mock::userFunction( 'delete_transient', [ 'times' => 1 ] );

		\WP_Mock::userFunction( 'wp_remote_get', [ 'times' => 2, 'return' => [ '__unauthorized' => true ] ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [ 'return' => 401 ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body', [
			'return' => json_encode( [ [ 'errorCode' => 'INVALID_SESSION_ID', 'message' => 'Session expired or invalid' ] ] ),
		] );

		$result = Client\query( 'SELECT Id FROM Attendee__c' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'boardmc_sfgf_query_failed', $result->get_error_code() );
	}
}
