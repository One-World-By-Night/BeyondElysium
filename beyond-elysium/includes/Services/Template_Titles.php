<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Gives an installed system template's sections the titles their declared template files name: a section whose title
 * still reads one of its declared section's `former_titles` takes the declared title. A chronicle's own template is
 * never changed.
 */
class Template_Titles {

	/**
	 * Renames the matching sections of every system template.
	 *
	 * @param string $root The declared catalog directory.
	 * @return array{templates:int,sections:int} How many templates and sections were renamed.
	 */
	public static function run( string $root = Catalog_Reader::DEFAULT_ROOT ): array {
		$renamed = [ 'templates' => 0, 'sections' => 0 ];

		foreach ( Catalog_Reader::load( $root )['templates'] as $slug => $file ) {
			[ $stack_slug, $template_type ] = explode( '.', (string) $slug, 2 ) + [ 1 => '' ];
			$renames                        = self::renames( (array) ( $file['definition']['sections'] ?? [] ) );
			if ( $renames === [] ) {
				continue;
			}

			foreach ( Template::globals( [ 'stack_slug' => $stack_slug, 'template_type' => $template_type ] ) as $template ) {
				/** @var object{id:int,is_system:int,layout:array} $template */
				if ( empty( $template->is_system ) ) {
					continue;
				}

				$layout  = $template->layout;
				$changed = 0;
				foreach ( (array) ( $layout['sections'] ?? [] ) as $index => $section ) {
					$rename = $renames[ (string) ( $section['block_slug'] ?? '' ) ] ?? null;
					if ( $rename !== null && in_array( $section['title'] ?? null, $rename['former'], true ) ) {
						$layout['sections'][ $index ]['title'] = $rename['title'];
						++$changed;
					}
				}

				if ( $changed > 0 && Template::update( (int) $template->id, [ 'layout' => $layout ] ) ) {
					++$renamed['templates'];
					$renamed['sections'] += $changed;
				}
			}
		}

		return $renamed;
	}

	/**
	 * The sections of a declared template that list former titles, by block slug.
	 *
	 * @param array<int|string,mixed> $sections
	 * @return array<string,array{title:string,former:string[]}>
	 */
	private static function renames( array $sections ): array {
		$renames = [];
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || empty( $section['block_slug'] ) || ! is_string( $section['title'] ?? null ) ) {
				continue;
			}
			$former = array_values( array_filter( (array) ( $section['former_titles'] ?? [] ), 'is_string' ) );
			if ( $former !== [] ) {
				$renames[ (string) $section['block_slug'] ] = [ 'title' => $section['title'], 'former' => $former ];
			}
		}
		return $renames;
	}
}
