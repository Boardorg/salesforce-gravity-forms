<?php

use WP_Mock\Tools\TestCase;
use BoardMC\SalesforceGravityForms\Providers\ChoiceProvider;
use BoardMC\SalesforceGravityForms\Cache\ChoiceCache;
use BoardMC\SalesforceGravityForms\Cache\TokenCache;

/**
 * Covers the fresh/stale caching and failure-fallback policy described in
 * the handoff doc's "Choice caching and failure behavior" section, driven
 * end-to-end through the real 'sponsors' provider with Salesforce itself
 * mocked at the WordPress HTTP API boundary.
 *
 * @covers \BoardMC\SalesforceGravityForms\Providers\ChoiceProvider
 */
class ChoiceProviderTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_a_fresh_cache_hit_is_returned_without_calling_salesforce() {
		$cached_choices = [ [ 'value' => '001A', 'label' => 'Acme Corp', 'source_id' => '001A', 'active' => true, 'metadata' => [ 'attendee_ids' => [ 'a1' ] ] ] ];
		$fresh_key      = ChoiceCache\build_key( 'fresh', 'sponsors', 'EVT1' );

		\WP_Mock::userFunction( 'get_transient', [
			'args'   => [ $fresh_key ],
			'return' => $cached_choices,
		] );

		// wp_remote_get/wp_remote_post are intentionally NOT stubbed: a
		// fresh-cache hit must never reach Salesforce.
		$result = ChoiceProvider\get_choices( 'sponsors', 'EVT1' );

		$this->assertSame( $cached_choices, $result );
	}

	public function test_a_salesforce_failure_falls_back_to_the_stale_cache() {
		$stale_choices = [ [ 'value' => '001A', 'label' => 'Acme Corp (stale)', 'source_id' => '001A', 'active' => true, 'metadata' => [ 'attendee_ids' => [ 'a1' ] ] ] ];
		$fresh_key      = ChoiceCache\build_key( 'fresh', 'sponsors', 'EVT1' );
		$stale_key      = ChoiceCache\build_key( 'stale', 'sponsors', 'EVT1' );

		\WP_Mock::userFunction( 'get_transient', [
			'return' => function ( $key ) use ( $fresh_key, $stale_key, $stale_choices ) {
				if ( $key === $stale_key ) return $stale_choices;
				if ( $key === TokenCache\TRANSIENT_KEY ) {
					return [ 'access_token' => 'tok', 'instance_url' => 'https://inst.my.salesforce.com', 'expires_at' => time() + 999 ];
				}
				// Fresh-cache miss.
				return false;
			},
		] );

		// Salesforce itself is down/erroring for this request.
		\WP_Mock::userFunction( 'wp_remote_get', [ 'return' => [] ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [ 'return' => 500 ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body', [ 'return' => json_encode( [ 'error' => 'Internal Server Error' ] ) ] );

		$result = ChoiceProvider\get_choices( 'sponsors', 'EVT1' );

		$this->assertSame( $stale_choices, $result );
	}

	public function test_a_salesforce_failure_with_no_stale_cache_returns_a_controlled_error() {
		\WP_Mock::userFunction( 'get_transient', [
			'return' => function ( $key ) {
				if ( $key === TokenCache\TRANSIENT_KEY ) {
					return [ 'access_token' => 'tok', 'instance_url' => 'https://inst.my.salesforce.com', 'expires_at' => time() + 999 ];
				}
				// Both the fresh and the stale choice caches are empty.
				return false;
			},
		] );

		\WP_Mock::userFunction( 'wp_remote_get', [ 'return' => [] ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [ 'return' => 500 ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body', [ 'return' => json_encode( [ 'error' => 'Internal Server Error' ] ) ] );

		$result = ChoiceProvider\get_choices( 'sponsors', 'EVT1' );

		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_a_successful_query_populates_both_the_fresh_and_stale_layers() {
		\WP_Mock::userFunction( 'get_transient', [
			'return' => function ( $key ) {
				if ( $key === TokenCache\TRANSIENT_KEY ) {
					return [ 'access_token' => 'tok', 'instance_url' => 'https://inst.my.salesforce.com', 'expires_at' => time() + 999 ];
				}
				return false;
			},
		] );

		\WP_Mock::userFunction( 'wp_remote_get', [ 'return' => [] ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [ 'return' => 200 ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body', [
			'return' => json_encode( [
				'records' => [ [ 'Id' => 'a1', 'Delegate__r' => [ 'AccountId' => '001A', 'Account' => [ 'Name' => 'Acme Corp' ] ] ] ],
				'done'    => true,
			] ),
		] );

		\WP_Mock::userFunction( 'set_transient', [ 'times' => 2 ] );

		$result = ChoiceProvider\get_choices( 'sponsors', 'EVT1' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( '001A', $result[0]['value'] );
		$this->assertSame( 'Acme Corp', $result[0]['label'] );
	}
}
