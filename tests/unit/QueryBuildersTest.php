<?php

use WP_Mock\Tools\TestCase;
use function BoardMC\SalesforceGravityForms\Salesforce\QueryBuilders\is_valid_event_code;
use function BoardMC\SalesforceGravityForms\Salesforce\QueryBuilders\build_sponsor_query;

/**
 * @covers \BoardMC\SalesforceGravityForms\Salesforce\QueryBuilders
 */
class QueryBuildersTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/** @dataProvider valid_event_code_provider */
	public function test_is_valid_event_code_accepts_expected_formats( $event_code ) {
		$this->assertTrue( is_valid_event_code( $event_code ) );
	}

	public function valid_event_code_provider() {
		return [
			'simple upper-case code' => [ 'NAMLS' ],
			'with digits'            => [ 'NAMLS2026' ],
			'with underscore'        => [ 'NAMLS_2026' ],
			'with period and hyphen' => [ 'NAMLS.2026-a' ],
		];
	}

	/** @dataProvider invalid_event_code_provider */
	public function test_is_valid_event_code_rejects_unexpected_characters( $event_code ) {
		$this->assertFalse( is_valid_event_code( $event_code ) );
	}

	public function invalid_event_code_provider() {
		return [
			'apostrophe (SOQL injection attempt)' => [ "NAMLS' OR '1'='1" ],
			'semicolon'                            => [ 'NAMLS;DROP' ],
			'space'                                 => [ 'NAM LS' ],
			'percent (SOQL LIKE wildcard)'          => [ 'NAM%LS' ],
			'empty string'                           => [ '' ],
			'non-string'                             => [ null ],
		];
	}

	public function test_build_sponsor_query_rejects_invalid_event_code() {
		$result = build_sponsor_query( "BAD'CODE" );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'boardmc_sfgf_invalid_event_code', $result->get_error_code() );
	}

	public function test_build_sponsor_query_retains_every_canonical_filter() {
		$soql = build_sponsor_query( 'NAMLS2026' );

		$this->assertIsString( $soql );

		// Minimal SELECT list only — identity fields, nothing extra.
		$this->assertStringContainsString( 'SELECT Id, Delegate__r.AccountId, Delegate__r.Account.Name FROM Attendee__c', $soql );

		// Shared filters (commonMeetingDataWhere() equivalent).
		$this->assertStringContainsString( 'Delegate__c != NULL', $soql );
		$this->assertStringContainsString( 'Delegate__r.AccountId != NULL', $soql );
		$this->assertStringContainsString( 'Registration__r.Active_Conference__c = TRUE', $soql );
		$this->assertStringContainsString( "Registration__r.Discount_Code__c NOT IN ('JUSTTESTING', 'DOLLARTEST', 'ONEDOLLARTEST')", $soql );
		$this->assertStringContainsString( "Registration__r.Conference__c = 'NAMLS2026'", $soql );
		$this->assertStringContainsString( "(NOT Delegate__r.Account.Name LIKE '%Test%')", $soql );
		$this->assertStringContainsString( "(NOT Delegate__r.Account.Name LIKE '%Testing%')", $soql );
		$this->assertStringContainsString( "(NOT Delegate__r.Account.Name LIKE '%SocialMedia%')", $soql );
		$this->assertStringContainsString( "(NOT Delegate__r.Account.Name LIKE '%Assemble%')", $soql );
		$this->assertStringContainsString( "(Status__c = NULL OR Status__c = 'Pending Replacement')", $soql );

		// Sponsor-specific filters.
		$this->assertStringContainsString( "Registration__r.RecordType.Name = 'Sponsor'", $soql );
		$this->assertStringContainsString( "Registration__r.StageName IN ('Closed-Won', 'Registered')", $soql );

		$this->assertStringContainsString( 'ORDER BY Delegate__r.Account.Name ASC', $soql );
	}

	public function test_build_sponsor_query_escapes_apostrophes_in_event_code() {
		// A validated-but-apostrophe-containing code shouldn't be possible
		// under the current pattern, but escape_soql_string() is exercised
		// directly here as defense-in-depth regardless of the validator.
		$safe = \BoardMC\SalesforceGravityForms\Salesforce\QueryBuilders\escape_soql_string( "O'Brien" );
		$this->assertSame( "O\\'Brien", $safe );
	}
}
