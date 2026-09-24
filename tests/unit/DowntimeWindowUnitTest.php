<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Downtime_Window;
use PHPUnit\Framework\TestCase;

/**
 * `Downtime_Window::state_for()` is the pure core every other downtime check.
 */
class DowntimeWindowUnitTest extends TestCase {

	public function test_neither_field_set_means_no_window(): void {
		$this->assertSame(
			Downtime_Window::NONE,
			Downtime_Window::state_for( null, null, null, '2026-09-16 12:00:00' )
		);
	}

	public function test_before_the_open_time_is_not_open(): void {
		$this->assertSame(
			Downtime_Window::NOT_OPEN,
			Downtime_Window::state_for( '2026-09-16 18:00:00', null, null, '2026-09-16 17:59:59' )
		);
	}

	public function test_at_the_exact_open_time_is_open(): void {
		$this->assertSame(
			Downtime_Window::OPEN,
			Downtime_Window::state_for( '2026-09-16 18:00:00', null, null, '2026-09-16 18:00:00' )
		);
	}

	public function test_with_no_open_time_at_all_and_a_future_deadline_is_open(): void {
		$this->assertSame(
			Downtime_Window::OPEN,
			Downtime_Window::state_for( null, '2026-09-20 23:59:00', null, '2026-09-16 12:00:00' )
		);
	}

	public function test_with_no_deadline_at_all_never_closes(): void {
		$this->assertSame(
			Downtime_Window::OPEN,
			Downtime_Window::state_for( '2026-09-16 18:00:00', null, null, '2099-01-01 00:00:00' )
		);
	}

	public function test_after_the_deadline_is_closed(): void {
		$this->assertSame(
			Downtime_Window::CLOSED,
			Downtime_Window::state_for( null, '2026-09-20 23:59:00', null, '2026-09-21 00:00:01' )
		);
	}

	public function test_at_the_exact_deadline_is_still_open(): void {
		$this->assertSame(
			Downtime_Window::OPEN,
			Downtime_Window::state_for( null, '2026-09-20 23:59:00', null, '2026-09-20 23:59:00' )
		);
	}

	public function test_an_extension_replaces_the_deadline_not_the_open_time(): void {
		// Past the original deadline, but the extension covers it - open.
		$this->assertSame(
			Downtime_Window::OPEN,
			Downtime_Window::state_for(
				'2026-09-16 18:00:00',
				'2026-09-20 23:59:00',
				'2026-09-25 23:59:00',
				'2026-09-21 12:00:00'
			)
		);
	}

	public function test_an_extension_never_moves_the_open_time_earlier_or_later(): void {
		$this->assertSame(
			Downtime_Window::NOT_OPEN,
			Downtime_Window::state_for(
				'2026-09-16 18:00:00',
				'2026-09-20 23:59:00',
				'2026-09-25 23:59:00',
				'2026-09-16 17:00:00'
			)
		);
	}

	public function test_a_past_extension_closes_it_even_though_the_original_deadline_has_not_passed(): void {
		$this->assertSame(
			Downtime_Window::CLOSED,
			Downtime_Window::state_for(
				null,
				'2026-09-25 23:59:00',
				'2026-09-16 12:00:00',
				'2026-09-16 13:00:00'
			)
		);
	}
}
