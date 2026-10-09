<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parses one council.owbn.net bylaw clause post into a rule: a subject, a PC tier, an NPC tier, and the
 * coordinator(s) named. Pure - no network call, no database, no WordPress function.
 */
class Bylaw_Source {

	/**
	 * Parses one clause post (the shape `GET /wp-json/wp/v2/bylaw_clause` returns: `id`, `slug`, `link`, `content`
	 * as `{rendered}`, `modified`) into a rule, or null when the clause's own text carries no PC/NPC tier at all -
	 * a pure structural or category node, not a rule.
	 *
	 * @param array<string,mixed> $clause
	 * @param string|null         $parent_subject The nearest ancestor's own subject, used only when this clause's
	 *                                             own text has no subject before its first PC/NPC/Coordinator marker.
	 * @return array{clause_id:int,path:string,subject:string,pc:?string,npc:?string,coordinators:string[],modified:string}|null
	 */
	public static function parse_clause( array $clause, ?string $parent_subject = null ): ?array {
		$text = self::plain_text( (string) ( $clause['content']['rendered'] ?? '' ) );

		$markers = self::find_markers( $text );
		if ( $markers === [] ) {
			return null;
		}

		$subject = trim( substr( $text, 0, $markers[0]['start'] ), " \t\n\r\0\x0B-–—:" );
		if ( $subject === '' ) {
			$subject = $parent_subject ?? '';
		}

		$values = [];
		foreach ( $markers as $i => $marker ) {
			$segment_start = $marker['end'];
			$segment_end   = $markers[ $i + 1 ]['start'] ?? strlen( $text );
			// A dash separating this value from the next marker, when the next marker's own match didn't
			// consume it, is trimmed the same way a subject already is.
			$value = trim( substr( $text, $segment_start, $segment_end - $segment_start ), " \t\n\r\0\x0B-–—" );
			$key   = strtolower( $marker['label'] );
			if ( ! isset( $values[ $key ] ) ) {
				$values[ $key ] = $value;
			}
		}

		return [
			'clause_id'    => (int) ( $clause['id'] ?? 0 ),
			'path'         => self::path_from_link( (string) ( $clause['link'] ?? '' ) ),
			'subject'      => $subject,
			'pc'           => $values['pc'] ?? null,
			'npc'          => $values['npc'] ?? null,
			'coordinators' => self::split_coordinators( $values['coordinator'] ?? '' ),
			'modified'     => substr( (string) ( $clause['modified'] ?? '' ), 0, 10 ),
		];
	}

	/**
	 * Every Character Bylaw rule in a flat dump of clause posts (any group, any order) - Blood Magic clauses (path
	 * `6.*`) left out, every other group left out, an empty-subject clause filled from its own nearest ancestor
	 * by path, deduplicated by clause id.
	 *
	 * @param array<int,array<string,mixed>> $raw_clauses
	 * @return array<int,array{clause_id:int,path:string,subject:string,pc:?string,npc:?string,coordinators:string[],modified:string}>
	 */
	public static function build_rules( array $raw_clauses ): array {
		$subject_by_path = [];
		foreach ( $raw_clauses as $raw ) {
			if ( self::section_of( $raw ) !== 'character' ) {
				continue;
			}
			$path = self::path_from_link( (string) ( $raw['link'] ?? '' ) );
			$text = self::plain_text( (string) ( $raw['content']['rendered'] ?? '' ) );
			if ( self::find_markers( $text ) === [] ) {
				// A pure label/category node - its whole text stands as the subject a descendant can inherit.
				$subject_by_path[ $path ] = $text;
			}
		}

		$rules = [];
		$seen  = [];
		foreach ( $raw_clauses as $raw ) {
			if ( self::section_of( $raw ) !== 'character' ) {
				continue;
			}
			$path = self::path_from_link( (string) ( $raw['link'] ?? '' ) );
			if ( $path === '6' || str_starts_with( $path, '6.' ) ) {
				continue;
			}

			$rule = self::parse_clause( $raw, self::nearest_ancestor_subject( $path, $subject_by_path ) );
			if ( $rule === null ) {
				continue;
			}
			$id = $rule['clause_id'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$rules[]     = $rule;
		}
		return $rules;
	}

	/**
	 * The nearest ancestor (by dotted path, walking up one segment at a time) that has its own plain-text subject,
	 * or null when none does.
	 *
	 * @param array<string,string> $subject_by_path
	 */
	private static function nearest_ancestor_subject( string $path, array $subject_by_path ): ?string {
		$segments = explode( '.', $path );
		while ( count( $segments ) > 1 ) {
			array_pop( $segments );
			$ancestor = implode( '.', $segments );
			if ( isset( $subject_by_path[ $ancestor ] ) ) {
				return $subject_by_path[ $ancestor ];
			}
		}
		return null;
	}

	/**
	 * The bylaw group a clause belongs to, read from its own permalink's path segment.
	 * `https://council.owbn.net/en/bylaw-clause/character/10_g_iv_a/` reads as `character`.
	 *
	 * @param array<string,mixed> $clause
	 * @return string
	 */
	public static function section_of( array $clause ): string {
		$link  = (string) ( $clause['link'] ?? '' );
		$parts = array_values( array_filter( explode( '/', rtrim( $link, '/' ) ) ) );
		return $parts[ count( $parts ) - 2 ] ?? '';
	}

	/**
	 * HTML stripped, entities decoded, every dash variant and run of whitespace folded to plain ASCII.
	 */
	private static function plain_text( string $html ): string {
		$stripped = function_exists( '\\wp_strip_all_tags' ) ? \wp_strip_all_tags( $html ) : strip_tags( $html );
		$text     = html_entity_decode( $stripped, ENT_QUOTES, 'UTF-8' );
		$text     = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Every `PC:`/`NPC:`/`Coordinator:` marker in the text, each with its own label and the byte offset where its
	 * value begins. A marker is one of these three words with either a dash just before it ("- NPC Coordinator
	 * Approval", no colon needed) or a colon just after ("Organizations PC: Coordinator Approval", no dash needed); a
	 * bare word boundary is not a marker.
	 *
	 * @return array<int,array{label:string,start:int,end:int}>
	 */
	private static function find_markers( string $text ): array {
		$markers = [];
		$pattern = '/[-\x{2012}-\x{2015}]\s*(PC|NPC|Coordinator)\s*:?\s*|\b(PC|NPC|Coordinator)\s*:\s*/iu';
		if ( preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $i => $whole ) {
				$label = $matches[1][ $i ][0] !== '' ? $matches[1][ $i ][0] : $matches[2][ $i ][0];
				$markers[] = [
					'label' => $label,
					'start' => $whole[1],
					'end'   => $whole[1] + strlen( $whole[0] ),
				];
			}
		}
		return $markers;
	}

	/**
	 * `coordinators` split on a comma, a slash, or the word "and"; empty names dropped.
	 *
	 * @return string[]
	 */
	private static function split_coordinators( string $raw ): array {
		if ( trim( $raw ) === '' ) {
			return [];
		}
		$parts = preg_split( '/\s*(?:,|\/|\band\b)\s*/i', $raw ) ?: [];
		return array_values( array_filter( array_map( 'trim', $parts ), static fn( $name ) => $name !== '' ) );
	}

	/**
	 * A clause's dotted bylaw path, read from its own permalink rather than its `slug`.
	 * `https://council.owbn.net/en/bylaw-clause/character/10_g_iv_a/` reads as `10.g.iv.a`.
	 */
	private static function path_from_link( string $link ): string {
		$parts = array_values( array_filter( explode( '/', rtrim( $link, '/' ) ) ) );
		$last  = $parts[ count( $parts ) - 1 ] ?? '';
		return str_replace( '_', '.', $last );
	}
}
