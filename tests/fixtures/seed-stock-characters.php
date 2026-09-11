<?php
/**
 * Stock sheets for `kony`: 2 fully-complete example characters per creature stack (22
 * total), so every one of the 11 supported creature types has a real, playable example
 * sheet in the actual chronicle - not sparse/randomized filler like seed-500-characters.php.
 *
 * Every trait_list/tiered_power entry below is a real name pulled from that block's own
 * seeded catalog (verified against Creature_Stack::resolve() before writing this file, and
 * re-verified at run time by validate_sheet_data() below) - nothing here is invented.
 * Identity fields (Clan, Tribe, Tradition, ...) are not catalog-backed in this schema
 * (free text/unenforced select), so they use classic World of Darkness terminology instead.
 *
 * Idempotent: checks by character name before creating, so re-running never duplicates.
 * Does not touch any of kony's existing characters (Marcus Vitel, Sara Redhawk, or the
 * leftover test-debris rows).
 *
 * Usage: wp eval-file tests/fixtures/seed-stock-characters.php --path=/path/to/wordpress
 */

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;

const STOCK_GAME_SLUG = 'kony';

/**
 * Fails loudly (per project convention) if any trait_list/tiered_power name, resource_pool
 * key, or identity_field key in $sheet_data isn't real for $stack_slug - catches typos and
 * fabricated names before they ever reach the database.
 */
function validate_sheet_data( string $stack_slug, string $char_name, array $sheet_data ): void {
	$resolved = Creature_Stack::resolve( $stack_slug );
	if ( ! $resolved ) {
		throw new RuntimeException( "unknown stack '{$stack_slug}' for {$char_name}" );
	}

	foreach ( $sheet_data as $block_slug => $value ) {
		$block = $resolved['blocks'][ $block_slug ] ?? null;
		if ( ! $block ) {
			throw new RuntimeException( "{$char_name}: block '{$block_slug}' does not resolve for stack '{$stack_slug}'" );
		}

		$definition = $block->definition;

		switch ( $block->section_type ) {
			case 'trait_list':
				$catalog = array_map( static fn( $i ) => $i->name, $definition->items ?? [] );
				foreach ( $value as $entry ) {
					if ( ! in_array( $entry['name'], $catalog, true ) ) {
						throw new RuntimeException( "{$char_name}: '{$entry['name']}' is not a real item in trait_list block '{$block_slug}'" );
					}
				}
				break;

			case 'tiered_power':
				$catalog = array_map( static fn( $p ) => $p->name, $definition->powers ?? [] );
				foreach ( $value as $entry ) {
					if ( ! in_array( $entry['name'], $catalog, true ) ) {
						throw new RuntimeException( "{$char_name}: '{$entry['name']}' is not a real power in tiered_power block '{$block_slug}'" );
					}
				}
				break;

			case 'resource_pool':
				$catalog = array_map( static fn( $p ) => $p->name, $definition->pools ?? [] );
				foreach ( array_keys( $value ) as $pool_name ) {
					if ( ! in_array( $pool_name, $catalog, true ) ) {
						throw new RuntimeException( "{$char_name}: '{$pool_name}' is not a real pool in resource_pool block '{$block_slug}'" );
					}
				}
				break;

			case 'identity_field':
				$catalog = array_map( static fn( $f ) => $f->name, $definition->fields ?? [] );
				foreach ( array_keys( $value ) as $field_name ) {
					if ( ! in_array( $field_name, $catalog, true ) ) {
						throw new RuntimeException( "{$char_name}: '{$field_name}' is not a real field in identity_field block '{$block_slug}'" );
					}
				}
				break;
		}
	}
}

if ( ! Game::find_by_slug( STOCK_GAME_SLUG ) ) {
	throw new RuntimeException( 'kony must already exist - this script targets the real chronicle, it does not create it' );
}
$game = Game::find_by_slug( STOCK_GAME_SLUG );
printf( "game: %s (id %d)\n", $game->slug, $game->id );

$fixtures = [

	// -- Vampire ---------------------------------------------------------------------
	[
		'name'        => 'Isolde Marchetti',
		'stack_slug'  => 'vampire',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 90,
		'xp_unspent'  => 10,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Analyst', 'Demeanor' => 'Bureaucrat' ],
			'vampire-identity'    => [ 'Clan' => 'Tremere', 'Sect' => 'Camarilla', 'Generation' => 9, 'Sire' => 'Radu Bathory', 'Title' => "Regent's Envoy", 'Morality Path' => 'Humanity' ],
			'met-physical-traits' => [ [ 'name' => 'Graceful', 'count' => 2 ], [ 'name' => 'Quick', 'count' => 1 ] ],
			'met-social-traits'   => [ [ 'name' => 'Elegant', 'count' => 2 ], [ 'name' => 'Persuasive', 'count' => 1 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Analytical', 'count' => 3 ], [ 'name' => 'Focused', 'count' => 1 ] ],
			'met-abilities'       => [ [ 'name' => 'Occult', 'count' => 4 ], [ 'name' => 'Academics', 'count' => 3 ], [ 'name' => 'Investigation', 'count' => 2 ], [ 'name' => 'Etiquette', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Iron Will', 'count' => 5 ] ],
			'met-flaws'           => [ [ 'name' => 'Curiosity', 'count' => 2 ] ],
			'vampire-backgrounds' => [ [ 'name' => 'Resources', 'count' => 2 ], [ 'name' => 'Retainers', 'count' => 1 ] ],
			'vampire-disciplines' => [ [ 'name' => 'Path of Blood', 'level' => 2 ], [ 'name' => 'Auspex', 'level' => 1 ] ],
			'vampire-rituals'     => [ [ 'name' => 'Thaumaturgy: Blood Mastery (basic)' ] ],
			'vampire-resources'   => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 8 ], 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ], 'Morality' => [ 'permanent' => 7, 'temporary' => 7 ] ],
			'vampire-virtues'     => [ 'Conscience' => [ 'permanent' => 3, 'temporary' => 3 ], 'Self-Control' => [ 'permanent' => 4, 'temporary' => 4 ], 'Courage' => [ 'permanent' => 3, 'temporary' => 3 ] ],
		],
	],
	[
		'name'        => 'Konstantin Drake',
		'stack_slug'  => 'vampire',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 70,
		'xp_unspent'  => 5,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Loner', 'Demeanor' => 'Guru' ],
			'vampire-identity'    => [ 'Clan' => 'Nosferatu', 'Sect' => 'Sabbat', 'Generation' => 8, 'Sire' => 'Blind Simon', 'Title' => 'Pack Priest', 'Morality Path' => 'Road of the Beast' ],
			'met-physical-traits' => [ [ 'name' => 'Vigorous', 'count' => 3 ], [ 'name' => 'Tenacious', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Fearsome', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Cunning', 'count' => 3 ], [ 'name' => 'Vigilant', 'count' => 2 ] ],
			'met-abilities'       => [ [ 'name' => 'Streetwise', 'count' => 4 ], [ 'name' => 'Stealth', 'count' => 3 ], [ 'name' => 'Awareness', 'count' => 3 ], [ 'name' => 'Brawl', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Danger Sense', 'count' => 2 ] ],
			'met-flaws'           => [ [ 'name' => 'Dark Secret' ] ],
			'vampire-backgrounds' => [ [ 'name' => 'Contacts', 'count' => 3 ], [ 'name' => 'Herd', 'count' => 2 ] ],
			'vampire-disciplines' => [ [ 'name' => 'Animalism', 'level' => 2 ], [ 'name' => 'Obfuscate', 'level' => 3 ], [ 'name' => 'Potence', 'level' => 1 ] ],
			'vampire-resources'   => [ 'Blood' => [ 'permanent' => 9, 'temporary' => 7 ], 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ], 'Morality' => [ 'permanent' => 5, 'temporary' => 5 ] ],
			'vampire-virtues'     => [ 'Conscience' => [ 'permanent' => 2, 'temporary' => 2 ], 'Self-Control' => [ 'permanent' => 3, 'temporary' => 3 ], 'Courage' => [ 'permanent' => 4, 'temporary' => 4 ] ],
		],
	],

	// -- Werewolf --------------------------------------------------------------------
	[
		'name'        => 'Ezra Stormcrow',
		'stack_slug'  => 'werewolf',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 80,
		'xp_unspent'  => 10,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Judge', 'Demeanor' => 'Leader' ],
			'werewolf-identity'   => [ 'Tribe' => 'Silver Fangs', 'Breed' => 'Homid', 'Auspice' => 'Philodox', 'Rank' => 2, 'Pack' => 'The Ember Circle', 'Totem' => 'Falcon' ],
			'met-physical-traits' => [ [ 'name' => 'Enduring', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Commanding', 'count' => 3 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Disciplined', 'count' => 3 ], [ 'name' => 'Wise', 'count' => 2 ] ],
			'met-abilities'       => [ [ 'name' => 'Leadership', 'count' => 3 ], [ 'name' => 'Politics', 'count' => 2 ], [ 'name' => 'Occult', 'count' => 1 ], [ 'name' => 'Brawl', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Natural Leader' ] ],
			'met-flaws'           => [ [ 'name' => 'Overconfident', 'count' => 1 ] ],
			'werewolf-backgrounds' => [ [ 'name' => 'Ancestors', 'count' => 2 ] ],
			'werewolf-gifts'      => [ [ 'name' => "Silver Fangs: Falcon's Grasp (basic)" ], [ 'name' => 'Philodox: King of the Beasts (basic)' ] ],
			'werewolf-rites'      => [ [ 'name' => 'Rite of Passage' ] ],
			'werewolf-resources'  => [ 'Rage' => [ 'permanent' => 5, 'temporary' => 5 ], 'Gnosis' => [ 'permanent' => 3, 'temporary' => 3 ], 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ] ],
			'werewolf-renown'     => [ 'Honor' => [ 'permanent' => 4, 'temporary' => 4 ], 'Glory' => [ 'permanent' => 2, 'temporary' => 2 ], 'Wisdom' => [ 'permanent' => 5, 'temporary' => 5 ] ],
		],
	],
	[
		'name'        => 'Naomi Two Rivers',
		'stack_slug'  => 'werewolf',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 60,
		'xp_unspent'  => 8,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Explorer', 'Demeanor' => 'Rebel' ],
			'werewolf-identity'   => [ 'Tribe' => 'Red Talons', 'Breed' => 'Lupus', 'Auspice' => 'Galliard', 'Rank' => 1, 'Pack' => 'Broken Fence', 'Totem' => 'Owl' ],
			'met-physical-traits' => [ [ 'name' => 'Athletic', 'count' => 3 ], [ 'name' => 'Fierce', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Expressive', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Creative', 'count' => 2 ], [ 'name' => 'Observant', 'count' => 2 ] ],
			'met-abilities'       => [ [ 'name' => 'Survival', 'count' => 4 ], [ 'name' => 'Expression', 'count' => 3 ], [ 'name' => 'Awareness', 'count' => 2 ], [ 'name' => 'Athletics', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Common Sense' ] ],
			'met-flaws'           => [ [ 'name' => 'Territorial', 'count' => 2 ] ],
			'werewolf-backgrounds' => [ [ 'name' => 'Kinfolk', 'count' => 2 ], [ 'name' => 'Rites', 'count' => 1 ] ],
			'werewolf-gifts'      => [ [ 'name' => "Red Talons: Predator's Leap (basic)" ], [ 'name' => 'Red Talons: Eye of the Hunter (basic)' ] ],
			'werewolf-rites'      => [ [ 'name' => 'Moot Rite' ] ],
			'werewolf-resources'  => [ 'Rage' => [ 'permanent' => 4, 'temporary' => 4 ], 'Gnosis' => [ 'permanent' => 4, 'temporary' => 4 ], 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ],
			'werewolf-renown'     => [ 'Glory' => [ 'permanent' => 3, 'temporary' => 3 ], 'Honor' => [ 'permanent' => 2, 'temporary' => 2 ], 'Wisdom' => [ 'permanent' => 3, 'temporary' => 3 ] ],
		],
	],

	// -- Mage ----------------------------------------------------------------------
	[
		'name'        => 'Dr. Adrian Voss',
		'stack_slug'  => 'mage',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 100,
		'xp_unspent'  => 12,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Sage', 'Demeanor' => 'Pedagogue' ],
			'mage-identity'       => [ 'Tradition' => 'Order of Hermes', 'Essence' => 'Questing', 'Cabal' => 'The Silver Ladder Circle', 'Rank' => 2 ],
			'met-physical-traits' => [ [ 'name' => 'Enduring', 'count' => 1 ] ],
			'met-social-traits'   => [ [ 'name' => 'Diplomatic', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Knowledgeable', 'count' => 4 ], [ 'name' => 'Analytical', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Occult', 'count' => 4 ], [ 'name' => 'Academics', 'count' => 3 ], [ 'name' => 'Science', 'count' => 2 ], [ 'name' => 'Investigation', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Eidetic Memory', 'count' => 5 ] ],
			'met-flaws'           => [ [ 'name' => 'Absent-Minded', 'count' => 2 ] ],
			'mage-backgrounds'    => [ [ 'name' => 'Library', 'count' => 3 ], [ 'name' => 'Node', 'count' => 1 ] ],
			'mage-spheres'        => [ [ 'name' => 'Forces', 'level' => 2 ], [ 'name' => 'Correspondence', 'level' => 1 ] ],
			'mage-resources'      => [ 'Arete' => [ 'permanent' => 3, 'temporary' => 3 ], 'Quintessence' => [ 'permanent' => 5, 'temporary' => 5 ], 'Paradox' => [ 'permanent' => 0, 'temporary' => 0 ], 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ] ],
		],
	],
	[
		'name'        => 'Selene Marchetti-Cole',
		'stack_slug'  => 'mage',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 55,
		'xp_unspent'  => 6,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Caregiver', 'Demeanor' => 'Idealist' ],
			'mage-identity'       => [ 'Tradition' => 'Verbena', 'Essence' => 'Primordial', 'Cabal' => 'The Green Circle', 'Rank' => 1 ],
			'met-physical-traits' => [ [ 'name' => 'Graceful', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Empathetic', 'count' => 3 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Intuitive', 'count' => 3 ], [ 'name' => 'Insightful', 'count' => 2 ] ],
			'met-abilities'       => [ [ 'name' => 'Medicine', 'count' => 3 ], [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Empathy', 'count' => 2 ], [ 'name' => 'Survival', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Higher Purpose' ] ],
			'met-flaws'           => [ [ 'name' => 'Nightmares', 'count' => 4 ] ],
			'mage-backgrounds'    => [ [ 'name' => 'Backup', 'count' => 1 ], [ 'name' => 'Library', 'count' => 1 ] ],
			'mage-spheres'        => [ [ 'name' => 'Life', 'level' => 3 ], [ 'name' => 'Prime', 'level' => 1 ] ],
			'mage-resources'      => [ 'Arete' => [ 'permanent' => 2, 'temporary' => 2 ], 'Quintessence' => [ 'permanent' => 4, 'temporary' => 4 ], 'Paradox' => [ 'permanent' => 1, 'temporary' => 1 ], 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ],
		],
	],

	// -- Changeling ------------------------------------------------------------------
	[
		'name'        => 'Fennick Larkspur',
		'stack_slug'  => 'changeling',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 45,
		'xp_unspent'  => 6,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Trickster', 'Demeanor' => 'Jester' ],
			'changeling-identity'   => [ 'Kith' => 'Pooka', 'Seeming' => 'Wilder', 'Court' => 'Seelie', 'Seelie Legacy' => 'Bumpkin', 'Unseelie Legacy' => 'Beast' ],
			'met-physical-traits'   => [ [ 'name' => 'Nimble', 'count' => 2 ] ],
			'met-social-traits'     => [ [ 'name' => 'Charming', 'count' => 3 ] ],
			'met-mental-traits'     => [ [ 'name' => 'Creative', 'count' => 3 ] ],
			'met-abilities'         => [ [ 'name' => 'Performance', 'count' => 3 ], [ 'name' => 'Subterfuge', 'count' => 2 ], [ 'name' => 'Streetwise', 'count' => 2 ] ],
			'met-merits'            => [ [ 'name' => 'Daredevil', 'count' => 3 ] ],
			'met-flaws'             => [ [ 'name' => 'Curiosity', 'count' => 2 ] ],
			'changeling-backgrounds' => [ [ 'name' => 'Chimera', 'count' => 2 ], [ 'name' => 'Holdings', 'count' => 1 ] ],
			'changeling-arts'       => [ [ 'name' => 'Chicanery', 'level' => 2 ], [ 'name' => 'Legerdemain', 'level' => 1 ] ],
			'changeling-realms'     => [ [ 'name' => 'Actor', 'level' => 2 ], [ 'name' => 'Prop', 'level' => 1 ] ],
			'changeling-resources'  => [ 'Glamour' => [ 'permanent' => 5, 'temporary' => 5 ], 'Banality' => [ 'permanent' => 3, 'temporary' => 3 ], 'Willpower' => [ 'permanent' => 4, 'temporary' => 4 ] ],
		],
	],
	[
		'name'        => 'Rosalind Thistledown',
		'stack_slug'  => 'changeling',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 50,
		'xp_unspent'  => 5,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Hedonist', 'Demeanor' => 'Sensualist' ],
			'changeling-identity'   => [ 'Kith' => 'Satyr', 'Seeming' => 'Grump', 'Court' => 'Unseelie', 'Seelie Legacy' => 'Fool', 'Unseelie Legacy' => 'Knave' ],
			'met-physical-traits'   => [ [ 'name' => 'Athletic', 'count' => 2 ] ],
			'met-social-traits'     => [ [ 'name' => 'Seductive', 'count' => 2 ] ],
			'met-mental-traits'     => [ [ 'name' => 'Cunning', 'count' => 3 ] ],
			'met-abilities'         => [ [ 'name' => 'Etiquette', 'count' => 3 ], [ 'name' => 'Occult', 'count' => 2 ], [ 'name' => 'Subterfuge', 'count' => 2 ] ],
			'met-merits'            => [ [ 'name' => 'Enchanting Voice', 'count' => 2 ] ],
			'met-flaws'             => [ [ 'name' => 'Compulsion', 'count' => 2 ] ],
			'changeling-backgrounds' => [ [ 'name' => 'Dross', 'count' => 2 ], [ 'name' => 'Title', 'count' => 1 ] ],
			'changeling-arts'       => [ [ 'name' => 'Wayfare', 'level' => 2 ], [ 'name' => 'Soothsay', 'level' => 1 ] ],
			'changeling-realms'     => [ [ 'name' => 'Fae', 'level' => 2 ], [ 'name' => 'Nature', 'level' => 1 ] ],
			'changeling-resources'  => [ 'Glamour' => [ 'permanent' => 4, 'temporary' => 4 ], 'Banality' => [ 'permanent' => 4, 'temporary' => 4 ], 'Willpower' => [ 'permanent' => 4, 'temporary' => 4 ] ],
		],
	],

	// -- Demon -----------------------------------------------------------------------
	[
		'name'        => 'Meridian',
		'stack_slug'  => 'demon',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 65,
		'xp_unspent'  => 8,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Visionary', 'Demeanor' => 'Manipulator' ],
			'demon-identity'      => [ 'House' => 'Devil', 'Faction' => 'Faustian' ],
			'met-physical-traits' => [ [ 'name' => 'Vigorous', 'count' => 3 ] ],
			'met-social-traits'   => [ [ 'name' => 'Commanding', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Insidious', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Intimidation', 'count' => 3 ], [ 'name' => 'Politics', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Celestial Attunement' ] ],
			'met-flaws'           => [ [ 'name' => 'Dark Fate', 'count' => 5 ] ],
			'demon-backgrounds'   => [ [ 'name' => 'Legacy', 'count' => 2 ], [ 'name' => 'Followers', 'count' => 1 ] ],
			'demon-lores'         => [ [ 'name' => 'Vampire' ], [ 'name' => 'Werewolf' ] ],
			'demon-resources'     => [ 'Faith' => [ 'permanent' => 4, 'temporary' => 4 ], 'Torment' => [ 'permanent' => 3, 'temporary' => 3 ], 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ] ],
		],
	],
	[
		'name'        => 'Ashkelon',
		'stack_slug'  => 'demon',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 55,
		'xp_unspent'  => 5,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Predator', 'Demeanor' => 'Bravo' ],
			'demon-identity'      => [ 'House' => 'Scourge', 'Faction' => 'Luciferian' ],
			'met-physical-traits' => [ [ 'name' => 'Ferocious', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Threatening', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Wily', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Melee', 'count' => 3 ], [ 'name' => 'Intimidation', 'count' => 2 ], [ 'name' => 'Occult', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Luck', 'count' => 3 ] ],
			'met-flaws'           => [ [ 'name' => 'Hatred', 'count' => 2 ] ],
			'demon-backgrounds'   => [ [ 'name' => 'Paragon', 'count' => 1 ], [ 'name' => 'Eminence', 'count' => 1 ] ],
			'demon-lores'         => [ [ 'name' => 'Mage' ], [ 'name' => 'Spirit' ] ],
			'demon-resources'     => [ 'Faith' => [ 'permanent' => 3, 'temporary' => 3 ], 'Torment' => [ 'permanent' => 5, 'temporary' => 5 ], 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ],
		],
	],

	// -- Fera (Bastet, Ajaba, Gurahl, Nagah, Kitsune... every non-Garou shapeshifter) ----
	[
		'name'        => 'Kesuk Frostpaw',
		'stack_slug'  => 'fera',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 60,
		'xp_unspent'  => 7,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Caregiver', 'Demeanor' => 'Defender' ],
			'fera-identity'       => [ 'Fera Type' => 'Forest Walkers', 'Breed' => 'Homid', 'Rank' => 2, 'Pack' => 'The Long Winter Den', 'Totem' => 'Bear' ],
			'met-physical-traits' => [ [ 'name' => 'Enduring', 'count' => 3 ], [ 'name' => 'Vigorous', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Compassionate', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Patient', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Medicine', 'count' => 3 ], [ 'name' => 'Survival', 'count' => 3 ], [ 'name' => 'Brawl', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Guardian Angel', 'count' => 6 ] ],
			'met-flaws'           => [ [ 'name' => 'Deep Sleeper' ] ],
			'fera-backgrounds'    => [ [ 'name' => 'Kinfolk', 'count' => 2 ], [ 'name' => 'Pure Breed', 'count' => 1 ] ],
			'fera-gifts'          => [ [ 'name' => 'Ananasi: Resist Pain (basic)' ], [ 'name' => 'Gurahl (Ursine): Heightened Senses (basic)' ] ],
			'werewolf-rites'      => [ [ 'name' => 'Rite of Motherhood' ] ],
			'werewolf-resources'  => [ 'Rage' => [ 'permanent' => 3, 'temporary' => 3 ], 'Gnosis' => [ 'permanent' => 5, 'temporary' => 5 ], 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ] ],
			'werewolf-renown'     => [ 'Honor' => [ 'permanent' => 3, 'temporary' => 3 ], 'Glory' => [ 'permanent' => 2, 'temporary' => 2 ], 'Wisdom' => [ 'permanent' => 4, 'temporary' => 4 ] ],
		],
	],
	[
		'name'        => 'Chitsa Nine-Tails',
		'stack_slug'  => 'fera',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 50,
		'xp_unspent'  => 6,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Enigma', 'Demeanor' => 'Confidant' ],
			'fera-identity'       => [ 'Fera Type' => 'Kitsune', 'Breed' => 'Kojin', 'Rank' => 1, 'Totem' => 'Fox' ],
			'met-physical-traits' => [ [ 'name' => 'Agile', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Beguiling', 'count' => 3 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Insightful', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Subterfuge', 'count' => 3 ], [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Stealth', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Oracular Ability', 'count' => 3 ] ],
			'met-flaws'           => [ [ 'name' => 'Dangerous Secret' ] ],
			'fera-backgrounds'    => [ [ 'name' => 'Mnesis', 'count' => 2 ], [ 'name' => 'Secrets', 'count' => 1 ] ],
			'fera-gifts'          => [ [ 'name' => 'Kitsune (Shinju): Sense Wyrm (basic)' ], [ 'name' => 'Kitsune (Eji): Falling Touch (basic)' ] ],
			'werewolf-rites'      => [ [ 'name' => 'Fertility Rite' ] ],
			'werewolf-resources'  => [ 'Rage' => [ 'permanent' => 2, 'temporary' => 2 ], 'Gnosis' => [ 'permanent' => 6, 'temporary' => 6 ], 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ],
			'werewolf-renown'     => [ 'Wisdom' => [ 'permanent' => 5, 'temporary' => 5 ], 'Glory' => [ 'permanent' => 2, 'temporary' => 2 ], 'Honor' => [ 'permanent' => 2, 'temporary' => 2 ] ],
		],
	],

	// -- Bete (the plugin's other non-Garou-shapeshifter stack slug) -------------------
	[
		'name'        => 'Skitter',
		'stack_slug'  => 'bete',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 45,
		'xp_unspent'  => 5,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Conniver', 'Demeanor' => 'Loner' ],
			'fera-identity'       => [ 'Fera Type' => 'Ananasi', 'Breed' => 'Homid', 'Rank' => 1 ],
			'met-physical-traits' => [ [ 'name' => 'Dexterous', 'count' => 3 ] ],
			'met-social-traits'   => [ [ 'name' => 'Manipulative', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Analytical', 'count' => 2 ], [ 'name' => 'Cunning', 'count' => 2 ] ],
			'met-abilities'       => [ [ 'name' => 'Stealth', 'count' => 3 ], [ 'name' => 'Crafts', 'count' => 2 ], [ 'name' => 'Occult', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Danger Sense', 'count' => 2 ] ],
			'met-flaws'           => [ [ 'name' => 'Phobia', 'count' => 3 ] ],
			'fera-backgrounds'    => [ [ 'name' => 'Freak Factor', 'count' => 2 ], [ 'name' => 'Secrets', 'count' => 1 ] ],
			'fera-gifts'          => [ [ 'name' => 'Gurahl (Ursine): Heightened Senses (basic)' ], [ 'name' => 'Kitsune (Shinju): Sense Wyrm (basic)' ] ],
			'werewolf-rites'      => [ [ 'name' => 'Rite of Passage' ] ],
			'werewolf-resources'  => [ 'Rage' => [ 'permanent' => 3, 'temporary' => 3 ], 'Gnosis' => [ 'permanent' => 4, 'temporary' => 4 ], 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ],
			'werewolf-renown'     => [ 'Wisdom' => [ 'permanent' => 3, 'temporary' => 3 ], 'Glory' => [ 'permanent' => 2, 'temporary' => 2 ], 'Honor' => [ 'permanent' => 2, 'temporary' => 2 ] ],
		],
	],
	[
		'name'        => 'Grimtooth Nibblewick',
		'stack_slug'  => 'bete',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 40,
		'xp_unspent'  => 5,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Survivor', 'Demeanor' => 'Follower' ],
			'fera-identity'       => [ 'Fera Type' => 'Ratkin', 'Breed' => 'Metis', 'Rank' => 1 ],
			'met-physical-traits' => [ [ 'name' => 'Wiry', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Friendly', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Determined', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Scrounge', 'count' => 3 ], [ 'name' => 'Crafts', 'count' => 2 ], [ 'name' => 'Brawl', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Ambidextrous' ] ],
			'met-flaws'           => [ [ 'name' => 'Short', 'count' => 1 ] ],
			'fera-backgrounds'    => [ [ 'name' => 'Colony', 'count' => 2 ], [ 'name' => 'Go-en', 'count' => 1 ] ],
			'fera-gifts'          => [ [ 'name' => 'Ananasi: Resist Pain (basic)' ], [ 'name' => 'Bastet (Khan): Razor Claws (basic)' ] ],
			'werewolf-rites'      => [ [ 'name' => 'Rite of Passage' ] ],
			'werewolf-resources'  => [ 'Rage' => [ 'permanent' => 4, 'temporary' => 4 ], 'Gnosis' => [ 'permanent' => 3, 'temporary' => 3 ], 'Willpower' => [ 'permanent' => 4, 'temporary' => 4 ] ],
			'werewolf-renown'     => [ 'Glory' => [ 'permanent' => 2, 'temporary' => 2 ], 'Honor' => [ 'permanent' => 3, 'temporary' => 3 ], 'Wisdom' => [ 'permanent' => 2, 'temporary' => 2 ] ],
		],
	],

	// -- Kuei-Jin ----------------------------------------------------------------------
	[
		'name'        => 'Mei Fen Zhao',
		'stack_slug'  => 'kueijin',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 70,
		'xp_unspent'  => 8,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Sage', 'Demeanor' => 'Mediator' ],
			'kueijin-identity'    => [ 'Dharma' => 'Resplendent Crane', 'Direction' => 'Yang', 'Balance' => 'Balanced', 'Station' => 'Wu Xing Envoy' ],
			'met-physical-traits' => [ [ 'name' => 'Graceful', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Diplomatic', 'count' => 3 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Wise', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Etiquette', 'count' => 3 ], [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Meditation', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Sanctity', 'count' => 2 ] ],
			'met-flaws'           => [ [ 'name' => 'Obsession', 'count' => 3 ] ],
			'kueijin-backgrounds' => [ [ 'name' => 'Herd', 'count' => 2 ], [ 'name' => 'Rites', 'count' => 1 ] ],
			'kueijin-disciplines' => [ [ 'name' => 'Cultivation', 'level' => 2 ], [ 'name' => 'Yin Prana', 'level' => 1 ] ],
			'kueijin-resources'   => [ 'Hun' => [ 'permanent' => 5, 'temporary' => 5 ], 'Po' => [ 'permanent' => 3, 'temporary' => 3 ], 'Yin Chi' => [ 'permanent' => 4, 'temporary' => 4 ], 'Yang Chi' => [ 'permanent' => 3, 'temporary' => 3 ], 'Demon Chi' => [ 'permanent' => 0, 'temporary' => 0 ] ],
		],
	],
	[
		'name'        => 'Wei Long Tan',
		'stack_slug'  => 'kueijin',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 60,
		'xp_unspent'  => 6,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Predator', 'Demeanor' => 'Soldier' ],
			'kueijin-identity'    => [ 'Dharma' => 'Devil-Tiger', 'Direction' => 'Yin', 'Balance' => 'Wrathful', 'Station' => 'Enforcer' ],
			'met-physical-traits' => [ [ 'name' => 'Ferocious', 'count' => 3 ] ],
			'met-social-traits'   => [ [ 'name' => 'Intimidating', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Determined', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Melee', 'count' => 3 ], [ 'name' => 'Intimidation', 'count' => 2 ], [ 'name' => 'Brawl', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Iron Will', 'count' => 5 ] ],
			'met-flaws'           => [ [ 'name' => 'Vengeance', 'count' => 2 ] ],
			'kueijin-backgrounds' => [ [ 'name' => 'Nushi', 'count' => 1 ], [ 'name' => 'Jade Talisman', 'count' => 1 ] ],
			'kueijin-disciplines' => [ [ 'name' => 'Black Wind', 'level' => 2 ], [ 'name' => 'Bone Shintai', 'level' => 1 ] ],
			'kueijin-resources'   => [ 'Hun' => [ 'permanent' => 3, 'temporary' => 3 ], 'Po' => [ 'permanent' => 5, 'temporary' => 5 ], 'Yin Chi' => [ 'permanent' => 3, 'temporary' => 3 ], 'Yang Chi' => [ 'permanent' => 4, 'temporary' => 4 ], 'Demon Chi' => [ 'permanent' => 1, 'temporary' => 1 ] ],
		],
	],

	// -- Mortal --------------------------------------------------------------------
	[
		'name'        => 'Detective Rosa Alvarez',
		'stack_slug'  => 'mortal',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 30,
		'xp_unspent'  => 4,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Crusader', 'Demeanor' => 'Director' ],
			'mortal-identity'     => [ 'Motivation' => 'Justice for the voiceless', 'Association' => 'Chicago PD' ],
			'met-physical-traits' => [ [ 'name' => 'Athletic', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Persuasive', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Observant', 'count' => 3 ], [ 'name' => 'Analytical', 'count' => 2 ] ],
			'met-abilities'       => [ [ 'name' => 'Investigation', 'count' => 4 ], [ 'name' => 'Firearms', 'count' => 3 ], [ 'name' => 'Streetwise', 'count' => 2 ], [ 'name' => 'Empathy', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Common Sense' ] ],
			'met-flaws'           => [ [ 'name' => 'Insomnia', 'count' => 2 ] ],
			'mortal-backgrounds'  => [ [ 'name' => 'Contacts', 'count' => 3 ], [ 'name' => 'Allies', 'count' => 1 ] ],
			'mortal-resources'    => [ 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ], 'Humanity' => [ 'permanent' => 8, 'temporary' => 8 ], 'True Faith' => [ 'permanent' => 0, 'temporary' => 0 ] ],
		],
	],
	[
		'name'        => 'Samuel Ostrowski',
		'stack_slug'  => 'mortal',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 35,
		'xp_unspent'  => 4,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Deviant', 'Demeanor' => 'Monster' ],
			'mortal-identity'     => [ 'Motivation' => 'Feed the hunger', 'Association' => "The Wyrm's Chosen" ],
			'met-physical-traits' => [ [ 'name' => 'Brutal', 'count' => 3 ] ],
			'met-social-traits'   => [ [ 'name' => 'Cruel', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Depraved', 'count' => 2 ] ],
			'met-abilities'       => [ [ 'name' => 'Brawl', 'count' => 3 ], [ 'name' => 'Intimidation', 'count' => 2 ], [ 'name' => 'Occult', 'count' => 1 ] ],
			'met-merits'          => [ [ 'name' => 'Concentration' ] ],
			'met-flaws'           => [ [ 'name' => 'Addiction', 'count' => 2 ] ],
			'mortal-backgrounds'  => [ [ 'name' => 'Backers', 'count' => 1 ], [ 'name' => 'Equipment', 'count' => 1 ] ],
			'mortal-numina'       => [ [ 'name' => 'Fomori Powers', 'level' => 1 ] ],
			'mortal-resources'    => [ 'Willpower' => [ 'permanent' => 4, 'temporary' => 4 ], 'Humanity' => [ 'permanent' => 4, 'temporary' => 4 ], 'True Faith' => [ 'permanent' => 0, 'temporary' => 0 ] ],
		],
	],

	// -- Mummy -----------------------------------------------------------------------
	[
		'name'        => 'Ashotep Ra-Nefer',
		'stack_slug'  => 'mummy',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 85,
		'xp_unspent'  => 9,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Traditionalist', 'Demeanor' => 'Sage' ],
			'mummy-identity'      => [ 'Amenti' => 'Aided of Anpu' ],
			'met-physical-traits' => [ [ 'name' => 'Enduring', 'count' => 3 ] ],
			'met-social-traits'   => [ [ 'name' => 'Dignified', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Knowledgeable', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Occult', 'count' => 4 ], [ 'name' => 'Academics', 'count' => 3 ], [ 'name' => 'Medicine', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Destiny', 'count' => 4 ] ],
			'met-flaws'           => [ [ 'name' => 'Haunted', 'count' => 2 ] ],
			'mummy-backgrounds'   => [ [ 'name' => 'Tomb', 'count' => 2 ], [ 'name' => 'Artifact', 'count' => 1 ] ],
			'mummy-hekau'         => [ [ 'name' => 'Alchemy', 'level' => 2 ], [ 'name' => 'Necromancy', 'level' => 1 ] ],
			'mummy-resources'     => [ 'Sekhem' => [ 'permanent' => 5, 'temporary' => 5 ], 'Balance' => [ 'permanent' => 6, 'temporary' => 6 ], 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ] ],
		],
	],
	[
		'name'        => 'Nebet-Hotep',
		'stack_slug'  => 'mummy',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 60,
		'xp_unspent'  => 6,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Fanatic', 'Demeanor' => 'Autocrat' ],
			'mummy-identity'      => [ 'Amenti' => 'Aided of Sekhmet' ],
			'met-physical-traits' => [ [ 'name' => 'Vigorous', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Commanding', 'count' => 2 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Focused', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Medicine', 'count' => 3 ], [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Leadership', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Higher Purpose' ] ],
			'met-flaws'           => [ [ 'name' => 'Driving Goal', 'count' => 3 ] ],
			'mummy-backgrounds'   => [ [ 'name' => 'Ayllu', 'count' => 2 ], [ 'name' => 'Journal', 'count' => 1 ] ],
			'mummy-hekau'         => [ [ 'name' => 'Amulets', 'level' => 1 ], [ 'name' => 'Effigy', 'level' => 1 ] ],
			'mummy-resources'     => [ 'Sekhem' => [ 'permanent' => 4, 'temporary' => 4 ], 'Balance' => [ 'permanent' => 5, 'temporary' => 5 ], 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ],
		],
	],

	// -- Wraith ----------------------------------------------------------------------
	[
		'name'        => 'Corwin Ashe',
		'stack_slug'  => 'wraith',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 55,
		'xp_unspent'  => 6,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Manipulator', 'Demeanor' => 'Rogue' ],
			'wraith-identity'     => [ 'Ethnos' => 'Wraith', 'Guild' => 'Puppeteer', 'Faction' => 'Renegade', 'Legion' => 'The Skeletal Legion', 'Shadow' => 'The Malfean Whisper' ],
			'met-physical-traits' => [ [ 'name' => 'Steady', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Manipulative', 'count' => 3 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Shrewd', 'count' => 2 ] ],
			'met-abilities'       => [ [ 'name' => 'Subterfuge', 'count' => 3 ], [ 'name' => 'Stealth', 'count' => 2 ], [ 'name' => 'Occult', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Medium', 'count' => 2 ] ],
			'met-flaws'           => [ [ 'name' => 'Haunted', 'count' => 2 ] ],
			'wraith-backgrounds'  => [ [ 'name' => 'Eidolon', 'count' => 2 ], [ 'name' => 'Relic', 'count' => 1 ] ],
			'wraith-arcanoi'      => [ [ 'name' => 'Argos', 'level' => 2 ], [ 'name' => 'Keening', 'level' => 1 ] ],
			'wraith-resources'    => [ 'Pathos' => [ 'permanent' => 4, 'temporary' => 4 ], 'Corpus' => [ 'permanent' => 6, 'temporary' => 6 ], 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ], 'Angst' => [ 'permanent' => 2, 'temporary' => 2 ] ],
		],
	],
	[
		'name'        => 'Delphine Voss',
		'stack_slug'  => 'wraith',
		'player_name' => 'Stock Sheet',
		'xp_earned'   => 50,
		'xp_unspent'  => 5,
		'sheet_data'  => [
			'met-archetypes'      => [ 'Nature' => 'Martyr', 'Demeanor' => 'Confidant' ],
			'wraith-identity'     => [ 'Ethnos' => 'Wraith', 'Guild' => 'Mnemoi', 'Faction' => 'Hierarchy', 'Legion' => 'The Emerald Legion', 'Shadow' => 'The Gilded Liar' ],
			'met-physical-traits' => [ [ 'name' => 'Lithe', 'count' => 2 ] ],
			'met-social-traits'   => [ [ 'name' => 'Eloquent', 'count' => 3 ] ],
			'met-mental-traits'   => [ [ 'name' => 'Reflective', 'count' => 3 ] ],
			'met-abilities'       => [ [ 'name' => 'Academics', 'count' => 3 ], [ 'name' => 'Expression', 'count' => 2 ], [ 'name' => 'Empathy', 'count' => 2 ] ],
			'met-merits'          => [ [ 'name' => 'Eidetic Memory', 'count' => 5 ] ],
			'met-flaws'           => [ [ 'name' => 'Low Self-Image', 'count' => 2 ] ],
			'wraith-backgrounds'  => [ [ 'name' => 'Living Family', 'count' => 2 ], [ 'name' => 'Haunt', 'count' => 1 ] ],
			'wraith-arcanoi'      => [ [ 'name' => 'Fatalism', 'level' => 2 ], [ 'name' => 'Outrage', 'level' => 1 ] ],
			'wraith-resources'    => [ 'Pathos' => [ 'permanent' => 5, 'temporary' => 5 ], 'Corpus' => [ 'permanent' => 5, 'temporary' => 5 ], 'Willpower' => [ 'permanent' => 4, 'temporary' => 4 ], 'Angst' => [ 'permanent' => 3, 'temporary' => 3 ] ],
		],
	],
];

$created = 0;
foreach ( $fixtures as $f ) {
	$existing = BeyondElysium\Database\Manager::get_row(
		'SELECT id, uuid FROM ' . BeyondElysium\Database\Manager::table( 'characters' ) . ' WHERE name = %s AND owner_slug = %s',
		$f['name'],
		STOCK_GAME_SLUG
	);
	if ( $existing ) {
		printf( "  %-24s already exists (id %d)\n", $f['name'], $existing->id );
		continue;
	}

	validate_sheet_data( $f['stack_slug'], $f['name'], $f['sheet_data'] );

	// D27: Character::create()'s field allowlist does not include xp_earned/xp_unspent -
	// passing them in the create() data array silently drops them, no error, character
	// created with XP 0/0 regardless of what was requested. XP is set via update_xp()'s
	// deltas after creation instead (a fresh row is 0/0 per the schema default, so the
	// delta from zero is just the intended value).
	$id = Character::create( [
		'name'        => $f['name'],
		'stack_slug'  => $f['stack_slug'],
		'owner_type'  => 'chronicle',
		'owner_slug'  => STOCK_GAME_SLUG,
		'is_npc'      => 0,
		'status'      => 'active',
		'player_name' => $f['player_name'],
		'sheet_data'  => $f['sheet_data'],
	] );
	Character::update_xp( $id, $f['xp_earned'], $f['xp_unspent'] );

	$c = Character::find( $id );
	printf( "  %-24s created id=%-4d stack=%s\n", $c->name, $c->id, $c->stack_slug );
	$created++;
}

printf( "done: %d created, %d already existed\n", $created, count( $fixtures ) - $created );
