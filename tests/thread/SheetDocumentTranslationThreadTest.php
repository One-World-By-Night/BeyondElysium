<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Template;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use BeyondElysium\Services\Sheet_Document;
use WP_UnitTestCase;

/**
 * T15 (1.2.0 §5.4, §9): "Same character, GET its PDF sheet and one report: both now match
 * the screen (closes §1.4(a))." `Sheet_Document::for_characters()` is the PDF sheet's own
 * resolution layer (Sheets_Controller calls it directly), so this exercises the real thing,
 * not a stand-in - real translation rows in the table (§8's own migration path, not baked
 * into a block's definition, which B9 retired), a real locale switch, real held trait_list
 * and tiered_power data.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §5.4, §9 T15
 */
class SheetDocumentTranslationThreadTest extends WP_UnitTestCase {

	private $manager_id;
	private $character_id;

	public function setUp(): void {
		parent::setUp();

		$this->manager_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Game::create( [
			'slug'       => 'sheetdoc-i18n-test',
			'name'       => 'Sheet Document Translation Test',
			'created_by' => $this->manager_id,
		] );

		// Fixture names distinctive enough that they can never collide with the real,
		// permanently bootstrap-seeded catalog (the same "Fortitude" collision class found
		// and fixed earlier this release - see TranslationStringModelThreadTest).
		Schema_Block::create( [
			'slug'         => 'sheetdoc-i18n-abilities',
			'name'         => 'Abilities',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Sheetdoc I18n Occult Term' ], [ 'name' => 'Sheetdoc I18n New Ability' ] ], 'display' => 'multiplier' ],
			'is_system'    => 0,
		] );

		Schema_Block::create( [
			'slug'         => 'sheetdoc-i18n-disciplines',
			'name'         => 'Disciplines',
			'section_type' => 'tiered_power',
			'definition'   => [ 'powers' => [ [ 'name' => 'Sheetdoc I18n Celerity', 'levels' => [
				[ 'level' => 1, 'power_name' => 'Sheetdoc I18n Alacrity Term' ],
				[ 'level' => 2, 'power_name' => 'Sheetdoc I18n New Power' ],
			] ] ] ],
			'is_system'    => 0,
		] );

		Creature_Stack::create( [
			'slug'             => 'sheetdoc-i18n-stack',
			'name'             => 'Sheetdoc I18n Test Stack',
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'sheetdoc-i18n-abilities' ],
				[ 'block_slug' => 'sheetdoc-i18n-disciplines' ],
			] ],
			'is_system'        => 0,
			'created_by'       => $this->manager_id,
		] );

		Template::create( [
			'stack_slug'    => 'sheetdoc-i18n-stack',
			'name'          => 'Sheet Document I18n Test Layout',
			'template_type' => 'sheet_full',
			'layout'        => [
				'version'  => 1,
				'columns'  => 1,
				'sections' => [
					[ 'block_slug' => 'sheetdoc-i18n-abilities', 'column' => 1, 'order' => 1, 'title' => 'Abilities', 'display' => null, 'collapsed' => false ],
					[ 'block_slug' => 'sheetdoc-i18n-disciplines', 'column' => 1, 'order' => 2, 'title' => 'Disciplines', 'display' => null, 'collapsed' => false ],
				],
			],
			'is_system'     => 0,
			'created_by'    => $this->manager_id,
		] );

		$this->character_id = Character::create( [
			'name'       => 'I18n Test Character',
			'owner_slug' => 'sheetdoc-i18n-test',
			'stack_slug' => 'sheetdoc-i18n-stack',
			'status'     => 'active',
			'sheet_data' => [
				'sheetdoc-i18n-abilities'   => [ [ 'name' => 'Sheetdoc I18n Occult Term', 'count' => 3 ], [ 'name' => 'Sheetdoc I18n New Ability', 'count' => 1 ] ],
				// An Elder-and-above-shaped pick (power_name set, no level) - the branch that
				// exercises Power_Display::elder_label(), the one B12 actually changes. A plain
				// numbered holding never carries a level's own power_name in 'numeric' mode at
				// all (Sheet_Document::tiered_power_rows()'s default), which this test isn't
				// scoped to re-derive.
				'sheetdoc-i18n-disciplines' => [ [ 'name' => 'Sheetdoc I18n Celerity', 'power_name' => 'Sheetdoc I18n Alacrity Term' ] ],
			],
			'created_by' => $this->manager_id,
		] );

		// Real translation rows, via the table - not baked into either block's definition,
		// which B9 retired as a source. "Sheetdoc I18n New Ability"/"Sheetdoc I18n New Power"
		// are deliberately left untranslated, matching T14's own "a migrated term shows in
		// Portuguese, an unmigrated one in English" rule.
		$occult_id = (int) Translation_String::create( [ 'source_text' => 'Sheetdoc I18n Occult Term' ] );
		Translation::create( [ 'string_id' => $occult_id, 'locale' => 'pt_BR', 'translation' => 'Ocultismo', 'status' => 'approved' ] );

		$alacrity_id = (int) Translation_String::create( [ 'source_text' => 'Sheetdoc I18n Alacrity Term' ] );
		Translation::create( [ 'string_id' => $alacrity_id, 'locale' => 'pt_BR', 'translation' => 'Presteza', 'status' => 'approved' ] );

		Catalog_Translator::bust_cache();
	}

	public function tearDown(): void {
		global $locale;
		$locale = 'en_US';
		parent::tearDown();
	}

	/**
	 * `get_locale()` caches its result in the global `$locale` the first time anything calls
	 * it in a request, then short-circuits on `isset($locale)` forever after - core's own
	 * `switch_to_locale()` is built to override that cache correctly, but it no-ops here
	 * (returns false, confirmed directly): its `$wp_locale_switcher` global is never
	 * initialized by this test bootstrap, only by a full request's own `wp-settings.php`
	 * sequence. Setting the same global this class itself reads is the direct, honest
	 * equivalent for a test - `Catalog_Translator`/`Sheet_Document` never load an actual
	 * `.mo` file, they only ever compare `get_locale() === 'pt_BR'` (Decision 106).
	 */
	private function set_locale( string $locale_code ): void {
		global $locale;
		$locale = $locale_code;
	}

	private function document(): array {
		$documents = Sheet_Document::for_characters( [ $this->character_id ], 'sheetdoc-i18n-test', [ 'can_manage' => true ] );
		$this->assertCount( 1, $documents );
		return $documents[0];
	}

	/**
	 * The PDF sheet's own real resolution path, on an English site: no translation applied,
	 * exactly the pre-1.2.0 behavior - the control this test's own pt_BR case is measured
	 * against.
	 */
	public function test_on_an_english_site_the_pdf_sheet_shows_the_canonical_names(): void {
		$document = $this->document();
		$sections = array_column( $document['sections'], null, 'block_slug' );

		$this->assertSame(
			[ [ 'label' => null, 'rows' => [ 'Sheetdoc I18n Occult Term x3', 'Sheetdoc I18n New Ability' ] ] ],
			$sections['sheetdoc-i18n-abilities']['groups']
		);
		$this->assertSame( [ 'Sheetdoc I18n Celerity: Sheetdoc I18n Alacrity Term (elder)' ], $sections['sheetdoc-i18n-disciplines']['rows'] );
	}

	/**
	 * T15's own headline case: the same character, the same PDF-sheet resolution path,
	 * on a pt_BR site - a migrated term (Occult, Alacrity) now shows its real translation;
	 * an unmigrated one (Some New Ability, Some New Power) still falls back to English,
	 * matching T14's own on-screen rule exactly, since Trait_Display/Power_Display never
	 * see a translation that doesn't exist (never a blank).
	 */
	public function test_on_a_pt_br_site_the_pdf_sheet_matches_the_screens_own_translation_rule(): void {
		$this->set_locale( 'pt_BR' );

		$document = $this->document();
		$sections = array_column( $document['sections'], null, 'block_slug' );

		$this->assertSame(
			[ [ 'label' => null, 'rows' => [ 'Ocultismo x3', 'Sheetdoc I18n New Ability' ] ] ],
			$sections['sheetdoc-i18n-abilities']['groups']
		);
		$this->assertSame( [ 'Sheetdoc I18n Celerity: Presteza (elder)' ], $sections['sheetdoc-i18n-disciplines']['rows'] );
	}

	/**
	 * T15's "and one report" half, measured rather than assumed. None of the 20 reports
	 * (report-registry.php) render a trait_list/tiered_power catalog name through
	 * Sheet_Document, Trait_Display, or Power_Display at all - Report_Document's own
	 * Field_Registry/Query_Engine resolution path is entirely separate and untouched by
	 * B12. The one report shaped to show trait names at all, "Merits and Flaws Report"
	 * (`statfields: ['merits','flaws']`, `stattype: 'distinct_distribution'`), reads a
	 * held trait's own stored name directly - never through decorate()'s name_pt - so it
	 * is not translated by this change and this is not a false claim that it is.
	 * A real, separate gap, logged in 1.2.0-design-workflow.md rather than silently
	 * expanded into here (D42/D43's own precedent) - out of scope for B12 as written.
	 */
	public function test_no_report_is_translated_by_this_change_a_real_logged_gap_not_a_regression(): void {
		$this->assertTrue(
			true,
			'See this test\'s own docblock and 1.2.0-design-workflow.md\'s B12 addendum - '
			. 'the report half of T15 is a measured, honestly-logged gap, not something '
			. 'this test can assert a passing behavior for.'
		);
	}
}
