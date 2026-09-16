<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\Display\Change_Description;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-084 (Pass H intake `t2-rendering`). A change's description - "Added Occult x3",
 * "Blood: 10 perm / 8 temp" - is read on the Approval Queue, the dashboards, and the signed
 * sheet's XP history. Its words were bare English that never reached the translation file, so a
 * chronicle running in Portuguese read them in English however complete its translation was.
 */
class ChangeDescriptionTranslationThreadTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'gettext_beyond-elysium' );
		parent::tear_down();
	}

	public function test_a_translated_chronicle_reads_its_own_words_around_the_changes_names(): void {
		$portuguese = [
			'Added %1$s x%2$s%3$s'        => 'Adicionado %1$s x%2$s%3$s',
			'%1$s: %2$s perm / %3$s temp' => '%1$s: %2$s perm. / %3$s temp.',
			'Unknown change'              => 'Alteração desconhecida',
		];
		add_filter(
			'gettext_beyond-elysium',
			static fn( $translation, $text ) => $portuguese[ $text ] ?? $translation,
			10,
			2
		);

		$this->assertSame( 'Adicionado Occult x3', Change_Description::describe( 'add_trait', [ 'trait' => [ 'name' => 'Occult', 'count' => 3 ] ] ) );
		$this->assertSame( 'Blood: 10 perm. / 8 temp.', Change_Description::describe( 'modify_resource', [ 'values' => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 8 ] ] ] ) );
		$this->assertSame( 'Alteração desconhecida', Change_Description::describe( 'something_else', [] ) );
	}

	public function test_no_word_of_any_description_is_left_outside_translation(): void {
		// Marks every translated phrase; what is left once the marks are taken out was never translated.
		add_filter( 'gettext_beyond-elysium', static fn( $translation ) => '⟦' . $translation . '⟧' );

		$cases = json_decode( (string) file_get_contents( BE_PLUGIN_ROOT . '/tests/fixtures/change-description-input.json' ), true );
		$this->assertNotEmpty( $cases );

		foreach ( $cases as $case ) {
			$described = Change_Description::describe( $case['change_type'], $case['change_data'] );
			$left      = $described;
			do {
				$before = $left;
				$left   = (string) preg_replace( '/⟦[^⟦⟧]*⟧/u', '', $left );
			} while ( $left !== $before );

			// An imported note's reason is the importer's own text, never a phrase of ours.
			$own_text = $case['change_type'] === 'import_note' ? (string) ( $case['change_data']['reason'] ?? '' ) : '';
			$this->assertSame( $own_text, $left, sprintf( 'case "%s" read "%s"', $case['name'], $described ) );
		}
	}
}
