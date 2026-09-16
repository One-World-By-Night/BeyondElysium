<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Point_Audit;
use PHPUnit\Framework\TestCase;

/**
 * 1.0.0-review F-085 (Pass H intake `t1-audit-stats-setup`). The Point Audit names each unpriced
 * line's reason on screen from `PointAudit.tsx`'s own labels, and the server's summary sentence
 * names the most common one. Both read the same words, so one translation serves both.
 */
class PointAuditReasonLabelTest extends TestCase {

	/**
	 * @return array<string,string> Reason key => label, as `PointAudit.tsx` shows it.
	 */
	private function screen_labels(): array {
		$source = (string) file_get_contents( BE_PLUGIN_ROOT . '/src/components/character/PointAudit.tsx' );
		$this->assertSame( 1, preg_match( '/const UNPRICED_REASON_LABEL[^{]*\{(.*?)\n\};/s', $source, $table ) );
		preg_match_all( "/(\w+): __\(\s*'([^']+)',\s*'beyond-elysium'\s*\)/", $table[1], $rows, PREG_SET_ORDER );

		$labels = [];
		foreach ( $rows as $row ) {
			$labels[ $row[1] ] = $row[2];
		}
		$this->assertNotEmpty( $labels );
		return $labels;
	}

	public function test_the_summary_names_each_reason_as_the_screen_does(): void {
		foreach ( $this->screen_labels() as $reason => $label ) {
			$this->assertSame( $label, Point_Audit::reason_label( $reason ), $reason );
		}
	}

	public function test_every_reason_the_audit_can_give_has_a_label(): void {
		$labels = $this->screen_labels();
		$found  = [];
		foreach ( [ 'Cost_Engine.php', 'Point_Audit.php' ] as $file ) {
			$source = (string) file_get_contents( BE_PLUGIN_PATH . '/includes/Services/' . $file );
			preg_match_all( "/'unpriced_reason'\s*=>\s*'([a-z_]+)'/", $source, $matches );
			$found = array_merge( $found, $matches[1] );
		}

		$this->assertNotEmpty( $found );
		foreach ( array_unique( $found ) as $reason ) {
			$this->assertArrayHasKey( $reason, $labels, "{$reason} has no label on screen" );
		}
	}

	public function test_a_reason_with_no_label_still_reads_as_words(): void {
		$this->assertSame( 'something new', Point_Audit::reason_label( 'something_new' ) );
	}
}
