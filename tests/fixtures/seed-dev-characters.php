<?php
/** Test fixtures for phase 0.3 rendering work: one game, one Vampire, one Werewolf. */

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Character;

if ( ! Game::find_by_slug( 'kony' ) ) {
    Game::create( [
        'slug'           => 'kony',
        'name'           => 'Kingdom of Night',
        'chronicle_title'=> 'Kingdom of Night',
        'created_by'     => 1,
    ] );
}
$game = Game::find_by_slug( 'kony' );
printf( "game: %s (id %d)\n", $game->slug, $game->id );

$fixtures = [
    [
        'name'       => 'Marcus Vitel',
        'stack_slug' => 'vampire',
        'sheet_data' => [
            'vampire-identity'    => [ 'Clan' => 'Lasombra', 'Generation' => 9, 'Sect' => 'Sabbat', 'Nature' => 'Autocrat', 'Demeanor' => 'Director' ],
            'met-physical-traits' => [ [ 'name' => 'Brawny', 'count' => 3 ], [ 'name' => 'Tough', 'count' => 2 ], [ 'name' => 'Quick', 'count' => 2 ] ],
            'met-social-traits'   => [ [ 'name' => 'Commanding', 'count' => 3 ], [ 'name' => 'Intimidating', 'count' => 2 ] ],
            'met-mental-traits'   => [ [ 'name' => 'Determined', 'count' => 3 ], [ 'name' => 'Wily', 'count' => 2 ] ],
            'met-abilities'       => [ [ 'name' => 'Brawl', 'count' => 3, 'note' => 'street' ], [ 'name' => 'Melee', 'count' => 2 ], [ 'name' => 'Occult', 'count' => 1 ] ],
            'met-merits'          => [ [ 'name' => 'Acute Sense', 'cost' => '1 or 3' ] ],
            'met-flaws'           => [ [ 'name' => 'Enemy' ] ],
            'vampire-disciplines' => [ [ 'name' => 'Obtenebration', 'level' => 4 ], [ 'name' => 'Dominate', 'level' => 3 ], [ 'name' => 'Potence', 'level' => 2 ] ],
            'vampire-resources'   => [ 'Blood' => [ 'permanent' => 14, 'temporary' => 11 ], 'Willpower' => [ 'permanent' => 7, 'temporary' => 5 ] ],
            'vampire-virtues'     => [ 'Conscience' => [ 'permanent' => 2, 'temporary' => 2 ], 'Self-Control' => [ 'permanent' => 4, 'temporary' => 4 ], 'Courage' => [ 'permanent' => 5, 'temporary' => 3 ] ],
            'vampire-statuses'    => [ [ 'name' => 'Loyal' ], [ 'name' => 'Feared' ] ],
        ],
    ],
    [
        'name'       => 'Sara Redhawk',
        'stack_slug' => 'werewolf',
        'sheet_data' => [
            'werewolf-identity'   => [ 'Tribe' => 'Wendigo', 'Breed' => 'Homid', 'Auspice' => 'Ahroun', 'Rank' => 3 ],
            'met-physical-traits' => [ [ 'name' => 'Ferocious', 'count' => 4 ], [ 'name' => 'Rugged', 'count' => 2 ] ],
            'met-social-traits'   => [ [ 'name' => 'Intimidating', 'count' => 3 ] ],
            'met-mental-traits'   => [ [ 'name' => 'Alert', 'count' => 3 ] ],
            'met-abilities'       => [ [ 'name' => 'Brawl', 'count' => 4 ], [ 'name' => 'Survival', 'count' => 3 ] ],
            'werewolf-gifts'      => [ [ 'name' => 'Wendigo: Call the Breeze (basic)' ], [ 'name' => 'Wendigo: Cutting Wind (basic)' ] ],
            'werewolf-resources'  => [ 'Rage' => [ 'permanent' => 8, 'temporary' => 6 ], 'Gnosis' => [ 'permanent' => 4, 'temporary' => 4 ], 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ] ],
            'werewolf-renown'     => [ 'Glory' => [ 'permanent' => 7, 'temporary' => 7 ], 'Honor' => [ 'permanent' => 4, 'temporary' => 4 ], 'Wisdom' => [ 'permanent' => 2, 'temporary' => 2 ] ],
        ],
    ],
];

foreach ( $fixtures as $f ) {
    $existing = BeyondElysium\Database\Manager::get_row(
        'SELECT * FROM ' . BeyondElysium\Database\Manager::table( 'characters' ) . ' WHERE name = %s',
        $f['name']
    );
    if ( $existing ) {
        printf( "  %-16s already exists (uuid %s)\n", $f['name'], $existing->uuid );
        continue;
    }
    $id = Character::create( [
        'name'        => $f['name'],
        'stack_slug'  => $f['stack_slug'],
        'owner_type'  => 'chronicle',
        'owner_slug'  => 'kony',
        'status'      => 'active',
        'player_name' => 'Test Player',
        'xp_earned'   => 120,
        'xp_unspent'  => 15,
        'biography'   => 'A test fixture. [ST]This bracketed part is ST-only.[/ST] Public again.',
        'notes'       => 'Owner-visible notes.',
        'rp_notes'    => 'ST-only roleplay notes.',
        'sheet_data'  => $f['sheet_data'],
    ] );
    $c = Character::find( $id );
    printf( "  %-16s created id=%d uuid=%s stack=%s\n", $c->name, $c->id, $c->uuid, $c->stack_slug );
}
