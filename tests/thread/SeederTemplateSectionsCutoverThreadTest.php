<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * C5: `Seeder::default_template_sections()` maps every tuple's own slug, and every
 * `title_refs[].block_slug`, through `Catalog_Cutover::live_slug()`.
 *
 * The regression this exists to close: `Schema::repair_stale_default_layouts()` treats
 * any stored section slug missing from the *current* default layout as a stale rename and
 * overwrites it (`VampireTemplateRepairTest.php` establishes that mechanism). Without this
 * mapping, "current" on a declared install would still mean the legacy slug - so a
 * template already rewritten onto the live block (C6) would look stale on every single
 * upgrade and get reverted straight back to the retired one, forever.
 */
class SeederTemplateSectionsCutoverThreadTest extends WP_UnitTestCase {

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	public function test_declared_default_layout_uses_the_live_slug_and_keeps_title_refs_intact(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		$legacy = Seeder::rebuild_default_layout_for_stack( 'vampire' );
		$this->assertContains( 'met-abilities', array_column( $legacy['sections'], 'block_slug' ) );

		update_option( Catalog_Cutover::OPTION, 'declared' );
		Catalog_Cutover::reset_cache();

		$declared     = Seeder::rebuild_default_layout_for_stack( 'vampire' );
		$declared_by  = array_column( $declared['sections'], null, 'block_slug' );
		$this->assertArrayHasKey( 'vampire-abilities', $declared_by );
		$this->assertArrayNotHasKey( 'met-abilities', $declared_by );

		// Neither real title_refs entry (vampire-identity / vampire-resources, on the
		// Virtues row) is itself retired - the mapping must leave both refs intact rather
		// than dropping or corrupting them while touching unrelated slugs.
		$virtues_refs = $declared_by['vampire-virtues']['title_refs'] ?? null;
		$this->assertNotNull( $virtues_refs );
		$this->assertCount( 2, $virtues_refs );
		$this->assertSame( 'vampire-identity', $virtues_refs[0]['block_slug'] );
		$this->assertSame( 'Morality Path', $virtues_refs[0]['field'] );
	}

	public function test_a_cutover_rewritten_template_is_not_reverted_by_the_repair(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		// The legacy default layout, with the Abilities row hand-moved onto the live
		// declared slug - standing in for C6's own rewrite_templates(), not yet built.
		$layout = Seeder::rebuild_default_layout_for_stack( 'vampire' );
		foreach ( $layout['sections'] as &$section ) {
			if ( $section['block_slug'] === 'met-abilities' ) {
				$section['block_slug'] = 'vampire-abilities';
			}
		}
		unset( $section );

		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => $layout,
			'is_system'     => 1,
		] );

		update_option( Catalog_Cutover::OPTION, 'declared' );
		Catalog_Cutover::reset_cache();

		Schema::repair_stale_default_layouts();

		$slugs = array_column( Template::find( $id )->layout['sections'], 'block_slug' );
		$this->assertContains( 'vampire-abilities', $slugs );
		$this->assertNotContains( 'met-abilities', $slugs, 'the repair must not revert a cutover-rewritten template back to the retired slug' );
	}
}
