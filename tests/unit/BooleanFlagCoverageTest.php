<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `tinyint(1)` column in the schema must be accounted for.
 *
 * `$wpdb` returns column values as strings regardless of SQL type, and `"0"` is truthy in
 * JavaScript. That is D51, and D53 is what happened when the same class was left on three
 * more columns: the Schema Blocks editor silently marked every block it saved
 * Storyteller-only, which hid Abilities from every player on a live chronicle.
 *
 * Each column's model casts it in its own decode (owner ruling 2026-09-16: to `bool`, so the
 * truthy-`"0"` shape stops being expressible at all - see `releases/1.0.1-workflow.md` B1).
 * `BooleanFlagTypesThreadTest` proves the real REST responses. This test is the backstop that
 * keeps that list honest: add a `tinyint(1)` column and the build fails here until you have
 * decided how it is cast and covered it there.
 */
class BooleanFlagCoverageTest extends TestCase {

	/**
	 * Every `tinyint(1)` column known to the schema, and the model that decodes it.
	 * Adding a row here is a deliberate act - see the class docblock.
	 */
	private const KNOWN = [
		'games.notifications_enabled'      => 'Game',
		'schema_blocks.is_system'          => 'Schema_Block',
		'schema_blocks.storyteller_only'   => 'Schema_Block',
		'creature_stacks.is_system'        => 'Creature_Stack',
		'characters.is_npc'                => 'Character',
		'templates.is_system'              => 'Template',
		'queries.match_all'                => 'Saved_Query',
		'queries.is_recent_search'         => 'Saved_Query',
		'plots.held'                       => 'Plot',
		'plot_entries.held'                => 'Plot_Entry',
		'secret_reveals.held'              => 'Secret_Reveal',
		'factions.created_via_proposal'    => 'Faction',
		'faction_members.is_leader'        => 'Faction_Member',
		'positions.holder_public'          => 'Position',
	];

	public function test_every_tinyint_column_in_the_schema_is_a_known_flag(): void {
		$found = $this->columns_in_schema();

		$this->assertSame(
			[],
			array_values( array_diff( $found, array_keys( self::KNOWN ) ) ),
			"A tinyint(1) column exists that this guard does not know about.\n"
			. "Decide how it is cast (bool, per the 1.0.1 ruling), cast it in its model's own\n"
			. "decode, cover it in BooleanFlagTypesThreadTest, then add it to KNOWN here."
		);
	}

	public function test_the_guard_has_not_gone_stale(): void {
		$found = $this->columns_in_schema();

		$this->assertSame(
			[],
			array_values( array_diff( array_keys( self::KNOWN ), $found ) ),
			'This guard lists a tinyint(1) column the schema no longer has - remove it.'
		);
	}

	public function test_each_flag_is_cast_to_bool_in_its_model(): void {
		$uncast = [];

		foreach ( self::KNOWN as $qualified => $model ) {
			[ , $column ] = explode( '.', $qualified );
			$source       = (string) file_get_contents(
				BE_PLUGIN_ROOT . "/beyond-elysium/includes/Models/{$model}.php"
			);

			// Either cast by name, or listed in a flag loop whose body casts to bool.
			$byName   = (bool) preg_match( '/\(bool\)[^\n]*' . preg_quote( $column, '/' ) . '|' . preg_quote( $column, '/' ) . '[^\n]*\(bool\)/', $source );
			$byLoop   = (bool) preg_match( "/'" . preg_quote( $column, '/' ) . "'/", $source )
				&& (bool) preg_match( '/\$row->\$flag\s*=\s*\(bool\)/', $source );

			if ( ! $byName && ! $byLoop ) {
				$uncast[] = "{$qualified} (expected a (bool) cast in Models/{$model}.php)";
			}
		}

		$this->assertSame( [], $uncast, "These flags reach the client as the raw database string:\n" . implode( "\n", $uncast ) );
	}

	/**
	 * Every `<table>.<column>` declared `tinyint(1)` in Schema.php's CREATE TABLE statements.
	 *
	 * @return string[]
	 */
	private function columns_in_schema(): array {
		$schema = (string) file_get_contents( BE_PLUGIN_ROOT . '/beyond-elysium/includes/Database/Schema.php' );
		$found  = [];
		$table  = null;

		foreach ( explode( "\n", $schema ) as $line ) {
			if ( preg_match( '/CREATE TABLE \{\$prefix\}([a-z_]+)/', $line, $m ) ) {
				$table = $m[1];
			}
			if ( $table && preg_match( '/^\s*([a-z_]+)\s+tinyint\(1\)/', $line, $m ) ) {
				$found[] = "{$table}.{$m[1]}";
			}
		}

		sort( $found );
		return $found;
	}
}
