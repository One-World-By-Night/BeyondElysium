<?php

namespace BeyondElysium\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Repairs every stored reference to a chronicle's slug after Game::rename()
 * has already changed the slug on be_games, be_characters and
 * be_schema_blocks. Covers the three other places a slug is written into
 * content rather than a plugin table: a provisioned page's data-be-config
 * attribute, an Elementor-built page's _elementor_data widget settings, and
 * a House Rules shortcode typed onto a page or into an Elementor widget.
 * Idempotent - re-running against content that has already
 * been repaired (or never referenced the old slug at all) finds nothing
 * to change and reports zero. Never touches asc_role_path, which is
 * operator-entered text keyed to an external role tree and has no
 * relationship to the chronicle's own slug.
 */
class Game_Slug_References {

	/**
	 * Rewrites every gameSlug value inside a data-be-config attribute, and
	 * every game_slug Elementor widget setting, from $old to $new. Called
	 * after Game::rename()'s own transaction has already committed - this
	 * runs outside it deliberately, since wp_update_post() fires save_post
	 * and touches caches other plugins may react to, and none of that
	 * should happen underneath a transaction that might still roll back.
	 *
	 * @param string $old
	 * @param string $new
	 * @return array{pages:int,elementor:int}
	 */
	public static function repair( string $old, string $new ): array {
		return [
			'pages'     => self::repair_pages( $old, $new ) + self::repair_shortcodes( $old, $new ),
			'elementor' => self::repair_elementor( $old, $new ),
		];
	}

	/**
	 * Finds every post whose content holds a House Rules shortcode for the
	 * old slug - `[be_house_rules game="kony"]`, typed onto any page - and
	 * rewrites its `game` attribute. The shortcode stores the slug as plain
	 * text, not in the widget markup `repair_pages()` reads, so a rename left
	 * it asking for a chronicle that no longer exists (1.0.0-review F-083).
	 *
	 * @param string $old
	 * @param string $new
	 * @return int Number of posts updated.
	 */
	private static function repair_shortcodes( string $old, string $new ): int {
		global $wpdb;

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_type != 'revision'
				   AND post_status != 'trash'
				   AND post_content LIKE %s
				   AND post_content LIKE %s",
				'%[be_house_rules%',
				'%' . $wpdb->esc_like( $old ) . '%'
			)
		);

		$updated = 0;
		foreach ( $candidates as $post ) {
			$content = self::rewrite_shortcodes( (string) $post->post_content, $old, $new );
			if ( $content !== $post->post_content ) {
				wp_update_post( [ 'ID' => $post->ID, 'post_content' => $content ] );
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Rewrites the `game` attribute of every House Rules shortcode in a piece
	 * of text whose value is exactly the old slug - quoted either way or not
	 * at all. A slug that merely starts with the old one, and the old slug in
	 * ordinary prose, are left as they are.
	 *
	 * @param string $text
	 * @param string $old
	 * @param string $new
	 * @return string
	 */
	private static function rewrite_shortcodes( string $text, string $old, string $new ): string {
		return (string) preg_replace_callback(
			'/\[be_house_rules\b[^\]]*\]/',
			static function ( array $tag ) use ( $old, $new ) {
				return (string) preg_replace_callback(
					'/(\bgame\s*=\s*)(["\']?)' . preg_quote( $old, '/' ) . '\2(?=[\s\]\/])/',
					static fn( array $m ) => $m[1] . $m[2] . $new . $m[2],
					$tag[0]
				);
			},
			$text
		);
	}

	/**
	 * Finds every page whose content carries a data-be-widget block
	 * referencing the old slug, and rewrites the gameSlug value inside its
	 * JSON-encoded, HTML-attribute-escaped data-be-config attribute.
	 * Operates on the decoded JSON value, never a blind string replace
	 * across the whole post content - the config blob can carry other
	 * keys (e.g. sheetPageUrl) that must survive untouched, and the old
	 * slug could in principle appear in unrelated prose on the same page.
	 *
	 * @param string $old
	 * @param string $new
	 * @return int Number of pages updated.
	 */
	private static function repair_pages( string $old, string $new ): int {
		global $wpdb;

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_type = 'page'
				   AND post_status != 'trash'
				   AND post_content LIKE %s
				   AND post_content LIKE %s",
				'%data-be-widget%',
				'%' . $wpdb->esc_like( $old ) . '%'
			)
		);

		$updated = 0;
		foreach ( $candidates as $post ) {
			$content = $post->post_content;
			$changed = false;

			$content = preg_replace_callback(
				'/data-be-config="([^"]*)"/',
				static function ( array $m ) use ( $old, $new, &$changed ) {
					$decoded = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
					if ( ! is_array( $decoded ) ) {
						return $m[0];
					}

					$this_changed = false;
					foreach ( $decoded as $key => $value ) {
						if ( is_string( $value ) && $value === $old ) {
							$decoded[ $key ] = $new;
							$this_changed     = true;
						}
					}
					if ( ! $this_changed ) {
						return $m[0];
					}

					$changed = true;
					return 'data-be-config="' . esc_attr( (string) wp_json_encode( $decoded ) ) . '"';
				},
				$content
			);

			if ( $changed && $content !== null ) {
				wp_update_post( [ 'ID' => $post->ID, 'post_content' => $content ] );
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Finds every post carrying Elementor page-builder data that names the
	 * old slug, and rewrites any widget's own game_slug setting from $old
	 * to $new. Elementor stores its page tree as one JSON-encoded array of
	 * nested elements per post, in the _elementor_data postmeta - walked
	 * recursively here since a widget can be nested inside sections and
	 * columns at any depth.
	 *
	 * @param string $old
	 * @param string $new
	 * @return int Number of postmeta rows updated.
	 */
	private static function repair_elementor( string $old, string $new ): int {
		global $wpdb;

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $old ) . '%'
			)
		);

		$updated = 0;
		foreach ( $candidates as $row ) {
			$tree = json_decode( $row->meta_value, true );
			if ( ! is_array( $tree ) ) {
				continue;
			}

			$changed = false;
			$tree    = self::rewrite_elements( $tree, $old, $new, $changed );

			if ( $changed ) {
				update_post_meta( (int) $row->post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $tree ) ) );
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Recursively walks an Elementor element tree, rewriting any
	 * settings.game_slug value that equals $old, in place, at any nesting
	 * depth (sections contain columns contain widgets). Sets $changed by
	 * reference so the caller only writes back a post that actually needed it.
	 *
	 * @param array $elements
	 * @param string $old
	 * @param string $new
	 * @param bool $changed
	 * @return array
	 */
	private static function rewrite_elements( array $elements, string $old, string $new, bool &$changed ): array {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['settings']['game_slug'] ) && $element['settings']['game_slug'] === $old ) {
				$element['settings']['game_slug'] = $new;
				$changed                          = true;
			}
			// A House Rules shortcode placed in a shortcode or text widget (F-083).
			foreach ( ( is_array( $element['settings'] ?? null ) ? $element['settings'] : [] ) as $key => $value ) {
				if ( is_string( $value ) && str_contains( $value, '[be_house_rules' ) ) {
					$rewritten = self::rewrite_shortcodes( $value, $old, $new );
					if ( $rewritten !== $value ) {
						$element['settings'][ $key ] = $rewritten;
						$changed                     = true;
					}
				}
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::rewrite_elements( $element['elements'], $old, $new, $changed );
			}
		}
		return $elements;
	}
}
