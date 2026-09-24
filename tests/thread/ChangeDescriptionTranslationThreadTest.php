<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\Display\Change_Description;
use WP_UnitTestCase;

/**
 * A change's description reads in the chronicle's own translation, plural forms included, with no word of any
 * description left outside translation.
 */
class ChangeDescriptionTranslationThreadTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'gettext_beyond-elysium' );
		remove_all_filters( 'ngettext_beyond-elysium' );
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

	public function test_a_translated_chronicle_reads_its_own_plural_forms(): void {
		add_filter(
			'gettext_beyond-elysium',
			static fn( $translation, $text ) => [ 'Catalog update: %s' => 'Atualização do catálogo: %s' ][ $text ] ?? $translation,
			10,
			2
		);
		// Portuguese counts zero as singular; the point is that the plural form is looked up by number.
		add_filter(
			'ngettext_beyond-elysium',
			static fn( $translation, $single, $plural, $number ) => [
				'%d row moved to its new catalog section'     => $number > 1 ? '%d linhas movidas para as novas seções do catálogo' : '%d linha movida para a nova seção do catálogo',
				'%d custom entry matched to the catalog'      => $number > 1 ? '%d itens personalizados associados ao catálogo' : '%d item personalizado associado ao catálogo',
			][ $single ] ?? $translation,
			10,
			4
		);

		$this->assertSame(
			'Atualização do catálogo: 24 linhas movidas para as novas seções do catálogo, 1 item personalizado associado ao catálogo',
			Change_Description::describe( 'catalog_rekey', [ 'counts' => [ 'moved_rows' => 24, 'rekeyed' => 1 ] ] )
		);
	}

	public function test_no_word_of_any_description_is_left_outside_translation(): void {
		// Marks every translated phrase; what is left once the marks are taken out was never translated.
		add_filter( 'gettext_beyond-elysium', static fn( $translation ) => '⟦' . $translation . '⟧' );
		add_filter( 'ngettext_beyond-elysium', static fn( $translation ) => '⟦' . $translation . '⟧' );

		$cases = json_decode( (string) file_get_contents( BE_PLUGIN_ROOT . '/tests/fixtures/change-description-input.json' ), true );
		$this->assertNotEmpty( $cases );

		foreach ( $cases as $case ) {
			$described = Change_Description::describe( $case['change_type'], $case['change_data'] );
			$left      = $described;
			do {
				$before = $left;
				$left   = (string) preg_replace( '/⟦[^⟦⟧]*⟧/u', '', $left );
			} while ( $left !== $before );

			$own_text = $case['change_type'] === 'import_note' ? (string) ( $case['change_data']['reason'] ?? '' ) : '';
			$this->assertSame( $own_text, $left, sprintf( 'case "%s" read "%s"', $case['name'], $described ) );
		}
	}
}
