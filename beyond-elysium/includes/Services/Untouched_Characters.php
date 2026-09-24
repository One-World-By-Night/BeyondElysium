<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Which characters are exactly as they were imported and have nothing of the site's own attached:
 * the ones that could be deleted and re-imported from their source file after a cutover, instead
 * of being re-keyed (1.3.3, "A second path for untouched characters"). Read-only, and deliberately
 * conservative - a character is counted only when nothing at all says it has lived on this site
 * since its file went in, so the number is a floor, never an estimate.
 *
 * Untouched means every one of these:
 *  - it has an `import_note` change, so there is a source file to go back to;
 *  - every change it has is an `import_note` or a cutover record, never somebody's edit;
 *  - its row was last written no later than its newest change plus a short allowance (the import
 *    writes the row and then records the change) - a direct repair writes no change, and this is
 *    what catches it;
 *  - nothing that a delete leaves behind, or takes away without a re-import restoring it, refers
 *    to it: a sheet style, a printed-sheet code, a transfer, a submission, attendance, a casting,
 *    a revealed secret, an item event, an after-game report, a faction membership, a court
 *    position, a rumor aimed at it - or a connection, with two exceptions that a re-import makes
 *    again by itself: the plot every chronicle character is given when it is created, while that
 *    plot is empty, and the items and places the import connected in the same breath.
 */
class Untouched_Characters {

	/** Change types the site writes about itself; anything else is somebody's edit. */
	private const OWN_CHANGE_TYPES = [ 'import_note', 'catalog_rekey', 'catalog_rekey_revert' ];

	/** Seconds a row may have been written after its newest change and still count as the import's own writes. */
	private const WRITE_ALLOWANCE = 120;

	/** Tables that name a character by id, and the column(s) that do. */
	private const BY_ID = [
		'character_sheet_styles' => [ 'character_id' ],
		'character_submissions'  => [ 'character_id' ],
		'attendance'             => [ 'character_id' ],
		'npc_castings'           => [ 'character_id' ],
		'secret_reveals'         => [ 'character_id' ],
		'item_events'            => [ 'character_id', 'from_character_id' ],
		'item_attestations'      => [ 'character_id' ],
		'after_game_reports'     => [ 'character_id' ],
		'faction_members'        => [ 'character_id' ],
		'positions'              => [ 'character_id' ],
		'position_history'       => [ 'character_id' ],
	];

	/** Tables that name a character by its uuid - a code printed on a sheet, a transfer in motion. */
	private const BY_UUID = [ 'character_attestations', 'character_transfers' ];

	/**
	 * @param string|null $game_slug One chronicle, or every one on the install.
	 * @return array<int,bool> character id => whether a player is assigned to it (a re-import loses that, and it has to be redone).
	 */
	public static function find( ?string $game_slug = null ): array {
		global $wpdb;

		$characters = Manager::table( 'characters' );
		$changes    = Manager::table( 'character_changes' );
		$own        = "'" . implode( "','", self::OWN_CHANGE_TYPES ) . "'";
		// The moment of the character's newest change, plus the allowance: "as of the import".
		$last_change = "( SELECT DATE_ADD( MAX( l.submitted_at ), INTERVAL " . self::WRITE_ALLOWANCE . " SECOND ) FROM {$changes} l WHERE l.character_id = c.id )";

		$where = [
			"EXISTS ( SELECT 1 FROM {$changes} i WHERE i.character_id = c.id AND i.change_type = 'import_note' )",
			"NOT EXISTS ( SELECT 1 FROM {$changes} o WHERE o.character_id = c.id AND o.change_type NOT IN ( {$own} ) )",
			"c.updated_at <= {$last_change}",
		];

		foreach ( self::BY_ID as $table => $columns ) {
			$name    = Manager::table( $table );
			$matches = implode( ' OR ', array_map( static fn( $column ) => "x.{$column} = c.id", $columns ) );
			$where[] = "NOT EXISTS ( SELECT 1 FROM {$name} x WHERE {$matches} )";
		}
		foreach ( self::BY_UUID as $table ) {
			$name    = Manager::table( $table );
			$where[] = "NOT EXISTS ( SELECT 1 FROM {$name} x WHERE x.character_uuid = c.uuid )";
		}
		// A connection means somebody did something - except the two an import makes for itself. The
		// character's own plot (`apr_actor`, no game date, no entries in it) is created with the
		// character, so every one has it; the items and places an import connects carry the import's
		// own moment, so a connection made after it is a Storyteller's.
		$connections = Manager::table( 'connections' );
		$plots       = Manager::table( 'plots' );
		$entries     = Manager::table( 'plot_entries' );
		$where[]     = "NOT EXISTS ( SELECT 1 FROM {$connections} x WHERE"
			. " ( x.source_type = 'character' AND x.source_id = c.id AND x.created_at > {$last_change} )"
			. " OR ( x.target_type = 'character' AND x.target_id = c.id AND NOT ( x.source_type = 'plot' AND COALESCE( x.label, '' ) = 'apr_actor'"
			. " AND EXISTS ( SELECT 1 FROM {$plots} p WHERE p.id = x.source_id AND p.game_date IS NULL )"
			. " AND NOT EXISTS ( SELECT 1 FROM {$entries} e WHERE e.plot_id = x.source_id ) ) ) )";

		// A rumor aimed at specific characters keeps a list of their ids, as numbers or as strings.
		$where[] = "NOT EXISTS ( SELECT 1 FROM {$entries} x WHERE JSON_CONTAINS( x.audience_character_ids, CAST( c.id AS JSON ) ) OR JSON_CONTAINS( x.audience_character_ids, JSON_QUOTE( CAST( c.id AS CHAR ) ) ) )";

		$sql = "SELECT c.id, c.wp_user_id FROM {$characters} c WHERE " . implode( ' AND ', $where );
		if ( $game_slug !== null ) {
			$sql = $wpdb->prepare( $sql . ' AND c.owner_slug = %s', $game_slug );
		}

		$found = [];
		foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
			$found[ (int) $row->id ] = (int) $row->wp_user_id > 0;
		}
		return $found;
	}
}
