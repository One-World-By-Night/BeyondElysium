<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Remote_Site;
use PHPUnit\Framework\TestCase;

/**
 * `Remote_Site::is_link_local()`, the address check made before fetching another chronicle's site.
 */
class RemoteSiteTest extends TestCase {

	public function test_a_link_local_address_is_caught(): void {
		$this->assertTrue( Remote_Site::is_link_local( 'http://169.254.169.254/latest/meta-data' ) );
		$this->assertTrue( Remote_Site::is_link_local( 'https://169.254.0.1:8080/wp-json/be/v1/verify/X' ) );
	}

	public function test_an_unspecified_or_shared_address_is_caught(): void {
		$this->assertTrue( Remote_Site::is_link_local( 'http://0.0.0.0/' ) );
		$this->assertTrue( Remote_Site::is_link_local( 'http://100.64.0.1/' ) );
	}

	public function test_an_ipv6_link_local_address_is_caught(): void {
		$this->assertTrue( Remote_Site::is_link_local( 'http://[fe80::1]/' ) );
	}

	public function test_an_ordinary_public_address_is_not(): void {
		$this->assertFalse( Remote_Site::is_link_local( 'https://35.212.9.83/wp-json/' ) );
		$this->assertFalse( Remote_Site::is_link_local( 'https://8.8.8.8' ) );
	}

	public function test_a_url_with_no_host_is_caught(): void {
		$this->assertTrue( Remote_Site::is_link_local( 'not a url' ) );
	}
}
