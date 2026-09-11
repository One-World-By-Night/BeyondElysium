<?php
/**
 * Step 7a (workflow-0.6.md): 500 characters with realistic sheet_data, for measuring
 * Query_Engine's performance targets. Not hand-entered - randomized within realistic
 * bounds, run via `wp eval-file`.
 *
 * Usage: wp eval-file tests/fixtures/seed-500-characters.php --path=/path/to/wordpress
 */

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Character;

const PERF_GAME_SLUG = 'perf-test-500';

if ( ! Game::find_by_slug( PERF_GAME_SLUG ) ) {
	Game::create( [
		'slug'       => PERF_GAME_SLUG,
		'name'       => '0.6 Performance Fixture Game',
		'created_by' => 1,
	] );
}
$game = Game::find_by_slug( PERF_GAME_SLUG );
printf( "game: %s (id %d)\n", $game->slug, $game->id );

$stacks = [ 'vampire', 'werewolf', 'mage' ];

$vampire_disciplines = [ 'Celerity', 'Fortitude', 'Obtenebration', 'Dominate', 'Potence', 'Auspex', 'Presence', 'Obfuscate' ];
$werewolf_gifts      = [ "Silver Fangs: Falcon's Grasp (basic)", 'Philodox: King of the Beasts (basic)', "Red Talons: Predator's Leap (basic)", 'Red Talons: Eye of the Hunter (basic)', 'Wendigo: Call the Breeze (basic)' ];
$mage_spheres        = [ 'Forces', 'Life', 'Mind', 'Matter', 'Correspondence', 'Prime', 'Spirit', 'Time', 'Entropy' ];

$existing = (int) BeyondElysium\Database\Manager::get_row(
	'SELECT COUNT(*) AS c FROM ' . BeyondElysium\Database\Manager::table( 'characters' ) . ' WHERE owner_slug = %s',
	PERF_GAME_SLUG
)->c;

$to_create = 500 - $existing;
if ( $to_create <= 0 ) {
	printf( "already have %d characters in %s - nothing to do\n", $existing, PERF_GAME_SLUG );
	exit;
}

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
		'name'        => "Perf Character {$i}",
		'stack_slug'  => $stack,
		'owner_type'  => 'chronicle',
		'owner_slug'  => PERF_GAME_SLUG,
		'status'      => random_int( 0, 9 ) === 0 ? 'retired' : 'active',
		'player_name' => "Perf Player {$i}",
		'sheet_data'  => $sheet_data,
	] );

	// D27: xp_earned/xp_unspent are silently dropped by Character::create()'s own
	// allowlist - only update_xp() actually sets them, via deltas against a fresh row's
	// 0/0 default.
	Character::update_xp( $character_id, random_int( 0, 200 ), random_int( 0, 30 ) );

	if ( $i % 100 === 0 ) {
		printf( "  %d / %d\n", $i, $to_create );
	}
}

printf( "done: %d characters created in %s\n", $to_create, PERF_GAME_SLUG );
