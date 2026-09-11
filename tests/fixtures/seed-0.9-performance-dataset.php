<?php
/**
 * workflow-0.9.md Step 2a: a realistic dataset for performance measurement - 200+
 * characters, 50+ plots, 100+ world objects, across at least two games. Randomized
 * within realistic bounds, not hand-entered, run via `wp eval-file`.
 *
 * Reuses `seed-500-characters.php`'s character-generation shape rather than
 * reinventing it, but spans two games and adds plots/world objects, which that
 * Phase 0.6 fixture never needed.
 *
 * Usage: wp eval-file tests/fixtures/seed-0.9-performance-dataset.php --path=/path/to/wordpress
 */

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Database\Manager;

// No periods - Game::create() runs the slug through sanitize_title(), which turns
// "0.9" into "0-9", so a slug containing one would never match this constant again on
// a re-run (find_by_slug() would search for the un-sanitized string forever).
const PERF9_GAME_SLUGS = [ 'perf-test-09-a', 'perf-test-09-b' ];
const PERF9_CHARACTERS_PER_GAME = 110; // 220 total, comfortably over the 200 floor.
const PERF9_PLOTS_PER_GAME      = 30;  // 60 total, over the 50 floor.
const PERF9_WORLD_OBJECTS_PER_GAME = 60; // 120 total, over the 100 floor.

$stacks = [ 'vampire', 'werewolf', 'mage' ];

$vampire_disciplines = [ 'Celerity', 'Fortitude', 'Obtenebration', 'Dominate', 'Potence', 'Auspex', 'Presence', 'Obfuscate' ];
$werewolf_gifts      = [ "Silver Fangs: Falcon's Grasp (basic)", 'Philodox: King of the Beasts (basic)', "Red Talons: Predator's Leap (basic)", 'Red Talons: Eye of the Hunter (basic)', 'Wendigo: Call the Breeze (basic)' ];
$mage_spheres        = [ 'Forces', 'Life', 'Mind', 'Matter', 'Correspondence', 'Prime', 'Spirit', 'Time', 'Entropy' ];

$item_types = [ 'Weapon', 'Armor', 'Tool', 'Artifact', 'Vehicle' ];
$item_subtypes = [ 'Melee', 'Ranged', 'Wearable', 'Mundane', 'Supernatural' ];

foreach ( PERF9_GAME_SLUGS as $game_index => $slug ) {
	if ( ! Game::find_by_slug( $slug ) ) {
		Game::create( [ 'slug' => $slug, 'name' => "0.9 Performance Fixture Game " . ( $game_index + 1 ), 'created_by' => 1 ] );
	}
	$game = Game::find_by_slug( $slug );
	printf( "game: %s (id %d)\n", $game->slug, $game->id );

	// -- Characters --
	$existing = (int) Manager::get_row(
		'SELECT COUNT(*) AS c FROM ' . Manager::table( 'characters' ) . ' WHERE owner_slug = %s', $slug
	)->c;
	$to_create = PERF9_CHARACTERS_PER_GAME - $existing;

	for ( $i = 1; $i <= $to_create; $i++ ) {
		$stack = $stacks[ array_rand( $stacks ) ];

		$sheet_data = [
			'met-physical-traits' => [ [ 'name' => 'Brawny', 'count' => random_int( 1, 5 ) ] ],
			'met-social-traits'   => [ [ 'name' => 'Persuasive', 'count' => random_int( 1, 5 ) ] ],
			'met-mental-traits'   => [ [ 'name' => 'Clever', 'count' => random_int( 1, 5 ) ] ],
			'met-abilities'       => [ [ 'name' => 'Brawl', 'count' => random_int( 1, 5 ) ], [ 'name' => 'Occult', 'count' => random_int( 0, 4 ) ] ],
			'met-merits'          => random_int( 0, 1 ) ? [ [ 'name' => 'Acute Sense' ] ] : [],
			'met-flaws'           => random_int( 0, 3 ) === 0 ? [ [ 'name' => 'Enemy' ] ] : [],
		];

		switch ( $stack ) {
			case 'vampire':
				$held = [];
				foreach ( (array) array_rand( array_flip( $vampire_disciplines ), random_int( 1, 4 ) ) as $name ) {
					$held[] = [ 'name' => $name, 'level' => random_int( 1, 5 ) ];
				}
				$sheet_data['vampire-disciplines'] = $held;
				$sheet_data['vampire-identity']    = [ 'Clan' => [ 'Lasombra', 'Toreador', 'Brujah', 'Nosferatu' ][ array_rand( range( 0, 3 ) ) ], 'Generation' => random_int( 6, 13 ) ];
				break;
			case 'werewolf':
				$held = [];
				foreach ( (array) array_rand( array_flip( $werewolf_gifts ), random_int( 1, 3 ) ) as $name ) {
					$held[] = [ 'name' => $name, 'level' => random_int( 1, 5 ) ];
				}
				$sheet_data['werewolf-gifts']    = $held;
				$sheet_data['werewolf-identity'] = [ 'Tribe' => [ 'Wendigo', 'Silver Fang', 'Bone Gnawer' ][ array_rand( range( 0, 2 ) ) ], 'Rank' => random_int( 1, 5 ) ];
				break;
			case 'mage':
				$held = [];
				foreach ( (array) array_rand( array_flip( $mage_spheres ), random_int( 1, 5 ) ) as $name ) {
					$held[] = [ 'name' => $name, 'level' => random_int( 1, 5 ) ];
				}
				$sheet_data['mage-spheres']  = $held;
				$sheet_data['mage-identity'] = [ 'Tradition' => [ 'Order of Hermes', 'Verbena', 'Akashic Brotherhood' ][ array_rand( range( 0, 2 ) ) ] ];
				break;
		}

		$character_id = Character::create( [
			'name'        => "Perf9 Character {$game_index}-{$i}",
			'stack_slug'  => $stack,
			'owner_type'  => 'chronicle',
			'owner_slug'  => $slug,
			'status'      => random_int( 0, 9 ) === 0 ? 'retired' : 'active',
			'player_name' => "Perf9 Player {$game_index}-{$i}",
			'sheet_data'  => $sheet_data,
		] );
		Character::update_xp( $character_id, random_int( 0, 200 ), random_int( 0, 30 ) );
	}
	printf( "  characters: %d created (now %d total)\n", $to_create, $existing + $to_create );

	// -- Plots --
	$existing_plots = (int) Manager::get_row(
		'SELECT COUNT(*) AS c FROM ' . Manager::table( 'plots' ) . ' WHERE game_id = %d', $game->id
	)->c;
	// Plot::STATUSES is exactly ['active', 'resolved', 'archived'] - create() returns
	// false (silently) for anything else, so this counts real successes, not loop
	// iterations, and keeps trying until the target is actually met.
	$plot_statuses = [ 'active', 'active', 'active', 'resolved', 'archived' ];
	$created_plots = 0;
	$attempt       = 0;
	while ( $existing_plots + $created_plots < PERF9_PLOTS_PER_GAME && $attempt < PERF9_PLOTS_PER_GAME * 2 ) {
		++$attempt;
		$id = Plot::create( [
			'game_id'      => (int) $game->id,
			'title'        => "Perf9 Plot {$game_index}-{$attempt}",
			'description'  => 'A randomly generated plot for performance measurement.',
			'status'       => $plot_statuses[ array_rand( $plot_statuses ) ],
			'initiated_by' => random_int( 0, 1 ) ? 'st' : 'player',
		] );
		if ( $id ) {
			++$created_plots;
		}
	}
	printf( "  plots: %d created (now %d total)\n", $created_plots, $existing_plots + $created_plots );

	// -- World objects --
	$existing_wo = (int) Manager::get_row(
		'SELECT COUNT(*) AS c FROM ' . Manager::table( 'world_objects' ) . ' WHERE game_id = %d', $game->id
	)->c;
	$wo_to_create = PERF9_WORLD_OBJECTS_PER_GAME - $existing_wo;
	for ( $i = 1; $i <= $wo_to_create; $i++ ) {
		World_Object::create( [
			'game_id'     => (int) $game->id,
			'object_type' => 'item',
			'name'        => "Perf9 Item {$game_index}-{$i}",
			'description' => 'A randomly generated item for performance measurement.',
			'properties'  => [
				'item_type'    => $item_types[ array_rand( $item_types ) ],
				'item_subtype' => $item_subtypes[ array_rand( $item_subtypes ) ],
				'level'        => random_int( 0, 5 ),
			],
		] );
	}
	printf( "  world objects: %d created (now %d total)\n", $wo_to_create, $existing_wo + $wo_to_create );
}

printf( "done.\n" );
