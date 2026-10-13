<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Core\Secret_Box;
use BeyondElysium\Services\Ai_Assist;
use PHPUnit\Framework\TestCase;

/**
 * Secret_Box encrypts and decrypts short secrets, keyed from the site's own auth salt.
 */
class SecretBoxTest extends TestCase {

	public function test_a_value_round_trips(): void {
		$encrypted = Secret_Box::encrypt( 'a-plain-secret-value' );
		$this->assertNotSame( '', $encrypted );
		$this->assertNotSame( 'a-plain-secret-value', $encrypted );
		$this->assertSame( 'a-plain-secret-value', Secret_Box::decrypt( $encrypted ) );
	}

	public function test_an_empty_string_decrypts_to_null(): void {
		$this->assertNull( Secret_Box::decrypt( '' ) );
	}

	public function test_a_tampered_value_decrypts_to_null_or_garbage_never_the_original(): void {
		$encrypted = Secret_Box::encrypt( 'another-secret' );
		$tampered  = substr( $encrypted, 0, -4 ) . 'abcd';
		$this->assertNotSame( 'another-secret', Secret_Box::decrypt( $tampered ) );
	}

	public function test_a_value_encrypted_through_ai_assist_still_decrypts_through_secret_box(): void {
		$encrypted = Ai_Assist::encrypt( 'sk-still-works' );
		$this->assertSame( 'sk-still-works', Secret_Box::decrypt( $encrypted ) );
	}
}
