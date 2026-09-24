<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Sheet_Verification;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function coverage for Sheet_Verification::code_from(): whether a parsed character's `id` field is a real
 * Beyond Elysium verification URL.
 */
class SheetVerificationCodeTest extends TestCase {

	public function test_a_real_verification_url_resolves_to_its_base_and_code(): void {
		$result = Sheet_Verification::code_from( [ 'id' => 'https://kony-sabbat.net/be-verify/?code=K3F7-QM2P' ] );

		$this->assertSame( [ 'base' => 'https://kony-sabbat.net', 'code' => 'K3F7-QM2P' ], $result );
	}

	public function test_a_subdirectory_install_keeps_its_own_path_segment_in_base(): void {
		$result = Sheet_Verification::code_from( [ 'id' => 'https://example.com/owbn/be-verify/?code=ABCD-1234' ] );

		$this->assertSame( [ 'base' => 'https://example.com/owbn', 'code' => 'ABCD-1234' ], $result );
	}

	public function test_a_plain_http_site_is_accepted(): void {
		$result = Sheet_Verification::code_from( [ 'id' => 'http://boston.example/be-verify/?code=WXYZ-9876' ] );

		$this->assertSame( [ 'base' => 'http://boston.example', 'code' => 'WXYZ-9876' ], $result );
	}

	public function test_a_code_with_no_separating_hyphen_is_accepted(): void {
		$result = Sheet_Verification::code_from( [ 'id' => 'https://kony-sabbat.net/be-verify/?code=K3F7QM2P' ] );

		$this->assertSame( [ 'base' => 'https://kony-sabbat.net', 'code' => 'K3F7QM2P' ], $result );
	}

	public function test_grapevines_own_free_text_id_is_not_a_code(): void {
		$this->assertNull( Sheet_Verification::code_from( [ 'id' => 'Chase Ashford - Sheet 3' ] ) );
	}

	public function test_a_malformed_code_is_rejected(): void {
		$this->assertNull( Sheet_Verification::code_from( [ 'id' => 'https://kony-sabbat.net/be-verify/?code=short' ] ) );
	}

	public function test_the_wrong_path_is_rejected(): void {
		$this->assertNull( Sheet_Verification::code_from( [ 'id' => 'https://kony-sabbat.net/verify/?code=K3F7-QM2P' ] ) );
	}

	public function test_an_empty_id_is_not_a_code(): void {
		$this->assertNull( Sheet_Verification::code_from( [ 'id' => '' ] ) );
	}

	public function test_a_missing_id_field_is_not_a_code(): void {
		$this->assertNull( Sheet_Verification::code_from( [] ) );
	}

	public function test_trailing_garbage_after_the_code_is_rejected(): void {
		$this->assertNull( Sheet_Verification::code_from( [ 'id' => 'https://kony-sabbat.net/be-verify/?code=K3F7-QM2Pxyz' ] ) );
	}
}
