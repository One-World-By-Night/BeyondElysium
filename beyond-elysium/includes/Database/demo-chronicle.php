<?php
/**
 * The declared content of a demo chronicle and its companion. `Services\Demo_Chronicle::reset()` walks this in the
 * order it appears, keyed so every reference between one entry and another is a plain string rather than a database
 * id. A character reference is its exact fixture or declared name; everything else is the key named beside it here.
 */

defined( 'ABSPATH' ) || exit;

return [

	'chronicles' => [
		'primary' => [
			'name'        => 'Beyond Elysium Demo',
			'game_type'   => 'met',
			'description' => 'Ships with the plugin so you can see it working immediately - real characters across every supported creature type. Resets on its own schedule.',
			'accounts'    => [ 'storyteller', 'player' ],
		],
		'companion' => [
			'name'        => 'Elsewhere',
			'game_type'   => 'met',
			'description' => 'A second demo chronicle, for seeing what joining a chronicle looks like from the outside.',
			'accounts'    => [ 'storyteller' ],
		],
	],

	// Every demo-fixtures() character goes into the primary chronicle. Each value names which demo account (by role)
	// owns it; a name absent from this map leaves its character chronicle-owned.
	'character_owners' => [
		'Isolde Marchetti'        => 'player',
		'Ezra Stormcrow'          => 'player',
		'Dr. Adrian Voss'         => 'player',
		'Detective Rosa Alvarez'  => 'player',
	],

	// A character's own Who's Who profile - shown/hidden, and whether its player's name shows alongside it.
	'character_profiles' => [
		[ 'character' => 'Isolde Marchetti', 'profile_audience' => 'everyone', 'profile_show_player' => true ],
		[ 'character' => 'Ezra Stormcrow', 'profile_audience' => 'everyone', 'profile_show_player' => false ],
		[ 'character' => 'Dr. Adrian Voss', 'profile_audience' => 'everyone', 'profile_show_player' => false ],
		// Radu's membership of the Primogen Council is hidden from profiles, so his own public profile lists no faction.
		[ 'character' => 'Radu Bathory', 'profile_audience' => 'everyone', 'profile_show_player' => false,
			'public_description' => '<p>The Justicar who came to watch the Prince govern. Few in the city have heard him speak.</p>' ],
		// Detective Rosa Alvarez is deliberately left on the storytellers-only default - hidden from Who's Who.
	],

	// Characters declared here directly, rather than drawn from demo_fixtures(), each tied to a 'chronicle' key.
	'extra_characters' => [
		[
			'chronicle'   => 'companion',
			'name'        => 'Mira Castellan',
			'stack_slug'  => 'mage',
			'player_name' => 'Storyteller-Run',
			'xp_earned'   => 40,
			'xp_unspent'  => 10,
			'sheet_data'  => [
				'mage-identity'  => [ 'Tradition' => 'Order of Hermes', 'Affiliation' => 'Traditions' ],
				'met-archetypes' => [ 'Nature' => 'Visionary', 'Demeanor' => 'Architect' ],
			],
		],
		[
			'chronicle'   => 'companion',
			'name'        => 'Declan Wyeth',
			'stack_slug'  => 'werewolf',
			'player_name' => 'Storyteller-Run',
			'xp_earned'   => 25,
			'xp_unspent'  => 5,
			'sheet_data'  => [
				'werewolf-identity' => [ 'Tribe' => 'Silver Fangs', 'Auspice' => 'Ahroun', 'Rank' => 1 ],
				'met-archetypes'    => [ 'Nature' => 'Monster', 'Demeanor' => 'Judge' ],
			],
		],
	],

	'pending_changes' => [
		[
			'character' => 'Isolde Marchetti', 'account' => 'player',
			'change_type' => 'modify_trait', 'category' => 'vampire-abilities',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 5 ] ],
			'xp_cost' => 5, 'notes' => 'Studying Tremere thaumaturgical theory with my Regent.',
		],
		[
			'character' => 'Ezra Stormcrow', 'account' => 'player',
			'change_type' => 'modify_trait', 'category' => 'werewolf-merits',
			'change_data' => [ 'block_slug' => 'werewolf-merits', 'trait' => [ 'name' => 'Natural Leader', 'count' => 1 ] ],
			'xp_cost' => 0, 'notes' => 'Already have this - correcting a missing rating from character creation.',
		],
		[
			'character' => 'Dr. Adrian Voss', 'account' => 'player',
			'change_type' => 'modify_power', 'category' => 'mage-spheres',
			'change_data' => [ 'block_slug' => 'mage-spheres', 'power' => [ 'name' => 'Forces', 'level' => 3 ] ],
			'xp_cost' => 20, 'notes' => 'Ready to push past Forces 2 after the ritual at the Chantry last game.',
			'review' => 'approve',
		],
		[
			'character' => 'Naomi Two Rivers', 'account' => null,
			'change_type' => 'modify_identity', 'category' => 'werewolf-identity',
			'change_data' => [ 'block_slug' => 'werewolf-identity', 'field' => 'Rank', 'value' => 2 ],
			'xp_cost' => 0, 'notes' => 'Promoted to Rank 2 after the Red Talon moot.',
		],
		[
			'character' => 'Isolde Marchetti', 'account' => 'player',
			'change_type' => 'modify_identity', 'category' => 'vampire-identity',
			'change_data' => [ 'block_slug' => 'vampire-identity', 'field' => 'Notes', 'value' => 'Regent of the Chantry' ],
			'xp_cost' => 0, 'notes' => 'Noting the Regent title on my own sheet.',
			'review' => 'auto',
		],
	],

	'sessions' => [
		'past-1' => [ 'chronicle' => 'primary', 'days_ago' => 14, 'place' => 'The Wax Museum, downtown', 'notes' => 'Regular monthly game night.' ],
		'past-2' => [ 'chronicle' => 'primary', 'days_ago' => 3, 'place' => 'The Wax Museum, downtown', 'notes' => "Elysium was called early after the Sheriff's announcement.",
			'recap' => [
				'key_events'       => "The Sheriff announced a string of unexplained disappearances along the docks and called Elysium early. Radu Bathory, a visiting Justicar, sat in on the whole thing without a word.",
				'player_decisions' => "Isolde began quietly compiling an alibi for every Primogen Council member rather than waiting to be asked for one. Ezra kept his pack well clear of the docks until the Sheriff's investigation runs its course.",
				'npcs_involved'    => [ [ 'name' => 'Radu Bathory', 'status' => 'alive' ] ],
				'cliffhanger'      => "Who - or what - is actually behind the disappearances, and why is a Justicar really here to watch it unfold?",
				'prep'             => "Decide what the Sheriff's investigation turns up next session, and whether Radu breaks his silence.",
			],
		],
	],

	// Six more upcoming sessions, roughly monthly, relative to the reset time.
	'upcoming_days_out' => [ 27, 58, 89, 120, 151, 182 ],
	'upcoming_place'     => 'The Wax Museum, downtown',
	'upcoming_notes'     => 'Regular monthly game night.',

	'attendance' => [
		[ 'session' => 'past-1', 'characters' => [ 'Isolde Marchetti', 'Konstantin Drake', 'Ezra Stormcrow', 'Naomi Two Rivers', 'Dr. Adrian Voss' ] ],
		[ 'session' => 'past-2', 'characters' => [ 'Isolde Marchetti', 'Konstantin Drake', 'Ezra Stormcrow', 'Ashkelon' ] ],
	],

	'downtime_actions' => [
		[
			'character'   => 'Ezra Stormcrow', 'session' => 'past-2', 'assigned_to' => 'storyteller',
			'answer'      => [ 'text' => '<p>The pack finds the trail cold, and nothing is spent.</p>', 'charge' => null ],
		],
		[
			'character'   => 'Naomi Two Rivers', 'session' => 'past-2', 'assigned_to' => 'player',
			'answer'      => [ 'text' => '<p>Your kin come through with a name and a place to look.</p>', 'charge' => [ 'name' => 'Kinfolk', 'cost' => 1 ] ],
		],
	],

	'factions' => [
		'primogen-council' => [
			'chronicle' => 'primary', 'name' => 'The Primogen Council', 'faction_type' => 'court',
			'description' => 'The Camarilla elders who advise (and quietly steer) the Prince.',
			'audience' => 'everyone',
			'members' => [
				[ 'character' => 'Isolde Marchetti', 'is_leader' => true, 'rank' => 'Tremere Primogen' ],
				[ 'character' => 'Konstantin Drake', 'is_leader' => false, 'rank' => 'Nosferatu Primogen' ],
				[ 'character' => 'Radu Bathory', 'is_leader' => false, 'rank' => 'Unseen hand', 'is_public' => false ],
			],
		],
	],

	'positions' => [
		'prince'  => [ 'chronicle' => 'primary', 'title' => 'Prince of the City', 'holder_public' => true, 'holder' => 'Konstantin Drake' ],
		'sheriff' => [ 'chronicle' => 'primary', 'faction' => 'primogen-council', 'title' => 'Sheriff', 'holder_public' => true, 'holder' => 'Isolde Marchetti' ],
	],

	'plots' => [
		'blood-in-the-water' => [
			'chronicle' => 'primary', 'title' => 'Blood in the Water',
			'description' => 'A string of unexplained disappearances near the docks has drawn Camarilla attention - and something is hunting in the old warehouse district.',
			'status' => 'active', 'initiated_by' => 'st', 'plot_category' => 'arc',
			'days_ago_introduced' => 14, 'days_ago_start' => 14,
		],
		'sheriffs-ultimatum' => [
			'chronicle' => 'primary', 'title' => "The Sheriff's Ultimatum",
			'description' => 'Konstantin Drake has given every Kindred in the city seven nights to account for their whereabouts the night of the docks incident.',
			'status' => 'active', 'initiated_by' => 'st', 'plot_category' => 'subplot', 'parent' => 'blood-in-the-water',
			'days_ago_introduced' => 3, 'days_ago_start' => 3,
		],
	],

	'plot_entries' => [
		[ 'plot' => 'blood-in-the-water', 'entry_type' => 'note', 'audience' => 'plot', 'days_ago' => 14,
			'content' => 'Three dockworkers have gone missing over the past two weeks. The Nosferatu have their own theories, none of them comforting.' ],
		[ 'plot' => 'blood-in-the-water', 'entry_type' => 'note', 'audience' => 'plot', 'days_ago' => 3,
			'content' => 'A fourth disappearance last night - this one a known Kindred herd contact. This is no longer a mortal problem.' ],
		[ 'plot' => 'sheriffs-ultimatum', 'entry_type' => 'note', 'audience' => 'plot', 'days_ago' => 3,
			'content' => 'The Sheriff has announced his ultimatum at Elysium. Every Kindred must report their whereabouts to the Primogen Council within seven nights.' ],
	],

	'secrets' => [
		[ 'entity_type' => 'plot', 'entity' => 'blood-in-the-water', 'title' => "What's really taking the dockworkers",
			'content' => 'Not a Kindred at all - a starving Nosferatu elder long thought destroyed, feeding in secret rather than surface and be hunted.',
			'audience' => 'storytellers' ],
		[ 'entity_type' => 'plot', 'entity' => 'sheriffs-ultimatum', 'title' => "The Sheriff's real motive",
			'content' => "Konstantin already suspects the truth and is using the ultimatum to flush out anyone protecting the elder, not to find a killer he can't yet name.",
			'audience' => 'storytellers' ],
		[ 'entity_type' => 'item', 'entity' => 'locket', 'title' => 'What the locket really does',
			'content' => '<p>The locket keeps its wearer calm under pressure. <b>[ST]It is also binding - whoever wears it for a full night owes</b> Radu a favor,[/ST] though they will not remember agreeing to anything.</p>',
			'audience' => 'storytellers' ],
		[ 'entity_type' => 'location', 'entity' => 'tremere-chantry', 'title' => 'Why the wards never fail',
			'content' => "Isolde renews the Chantry's warding every new moon herself, in person - she trusts no one else with the ritual, not even her own childer.",
			'audience' => 'restricted' ],
	],

	'world_objects' => [
		'wax-museum' => [ 'chronicle' => 'primary', 'object_type' => 'location', 'name' => 'The Wax Museum',
			'description' => "This chronicle's Elysium - neutral ground under the Prince's own decree." ],
		'signet' => [ 'chronicle' => 'primary', 'object_type' => 'item', 'name' => "The Sheriff's Signet",
			'description' => 'A heavy iron ring marking the office of Sheriff. Carried, never worn - it burns anyone who is not.' ],
		'old-town' => [ 'chronicle' => 'primary', 'object_type' => 'location', 'name' => 'Old Town',
			'description' => 'The oldest quarter of the city, mostly warehouses and townhouses converted a century ago. Kindred territory, unofficially.' ],
		'tremere-chantry' => [ 'chronicle' => 'primary', 'object_type' => 'location', 'name' => 'The Tremere Chantry',
			'description' => "A converted townhouse behind an unremarkable brick face. Isolde Marchetti's own domain, warded floor to roof - nothing crosses the threshold uninvited and leaves the same shape it came in.",
			'parent' => 'old-town',
			'properties' => [
				'location_type' => 'Chantry', 'owner' => 'Isolde Marchetti, Regent',
				'access' => 'Warded - members and invited guests only',
				'security' => 'Thaumaturgical wards, blood-bound guardians',
			] ],
		'locket' => [ 'chronicle' => 'primary', 'object_type' => 'item', 'name' => 'A Tarnished Silver Locket',
			'description' => 'An old locket, scratched and tarnished, on a thin chain. It calms whoever wears it almost immediately.',
			'properties' => [ 'item_type' => 'Trinket', 'uses_max' => 5, 'uses_left' => 2 ],
			'picture' => true ],
		'club-stake' => [ 'chronicle' => 'primary', 'object_type' => 'item', 'name' => 'Club/Stake',
			'properties' => [
				'item_type' => 'Melee', 'bonus' => 2, 'negatives' => [ [ 'name' => 'Clumsy' ] ],
				'concealability' => 'Jacket', 'damage_amount' => 1, 'availability' => [ [ 'name' => 'Any' ] ],
				'powers' => 'Staking (if wooden)', 'book_ref' => 'dark-epics:club-stake',
			] ],
		'rat-mask' => [ 'chronicle' => 'primary', 'object_type' => 'item', 'name' => 'Rat Mask',
			'properties' => [
				'item_type' => 'Fetish', 'level' => 3, 'tempers' => [ [ 'name' => 'Gnosis', 'count' => 6 ] ],
				'powers' => 'When activated, a Ratkin wearing the mask can pass herself off as its archetype so long as she can manage at least a minimally sufficient impersonation, and none will question her identity.',
				'book_ref' => 'changing-breeds-3:rat-mask',
			] ],
	],

	'item_events' => [
		[ 'object' => 'signet', 'event' => 'given', 'character' => 'Konstantin Drake',
			'note' => 'Passed to the new Sheriff upon his appointment by the Prince.' ],
	],

	'npcs' => [
		'radu-bathory' => [
			'chronicle' => 'primary', 'name' => 'Radu Bathory', 'stack_slug' => 'vampire', 'npc_detail' => 'full',
			'sheet_data' => [
				'vampire-identity' => [ 'Clan' => 'Tremere', 'Sect' => 'Camarilla', 'Generation' => 6, 'Title' => 'Justicar' ],
				'met-archetypes'   => [ 'Nature' => 'Director', 'Demeanor' => 'Judge' ],
			],
		],
		'dockside-hunger' => [
			'chronicle' => 'primary', 'name' => 'The Dockside Hunger', 'stack_slug' => 'various', 'npc_detail' => 'full',
			'sheet_data' => [
				'various-identity'    => [ 'Class' => 'Hungry Ghost', 'Subclass' => 'Drowned', 'Plane' => 'The Umbra, off the old docks' ],
				'various-tempers'     => [ [ 'name' => 'Essence', 'count' => 8 ] ],
				'various-powers'      => [
					[ 'name' => 'Tide Call', 'custom' => true, 'note' => 'Raises black water over the pier and drags one victim under.' ],
					[ 'name' => 'Borrowed Faces', 'custom' => true, 'note' => 'Wears the voice of anyone it has drowned.' ],
				],
				'vampire-disciplines' => [ [ 'name' => 'Obtenebration', 'level' => 3 ], [ 'name' => 'Fortitude', 'level' => 2 ] ],
				'werewolf-gifts'      => [ [ 'name' => 'Red Talons', 'power_name' => "Predator's Leap" ] ],
			],
		],
	],

	'npc_castings' => [
		[ 'session' => 'past-2', 'npc' => 'radu-bathory', 'account' => 'storyteller',
			'brief' => "Radu is here to observe, not to act. He speaks rarely and never raises his voice. He already knows more about the docks than he's letting on." ],
	],

	// Character-to-character connections, each `a`/`b` a name already created above - a PC, an extra_character, or an npc.
	'connections' => [
		[ 'a' => 'Isolde Marchetti', 'b' => 'Konstantin Drake', 'label' => 'Primogen Council',
			'notes' => 'Fellow Primogen - an uneasy alliance of elders who agree on little besides the Masquerade.' ],
		[ 'a' => 'Isolde Marchetti', 'b' => 'Selene Marchetti-Cole', 'label' => 'Family',
			'notes' => "Estranged kin on the mortal side of the family - Selene has no idea what Isolde really is." ],
		[ 'a' => 'Ezra Stormcrow', 'b' => 'Naomi Two Rivers', 'label' => 'Packmates',
			'notes' => 'Run together in the same pack; Ezra outranks her but trusts her instincts over his own.' ],
		[ 'a' => 'Konstantin Drake', 'b' => 'Radu Bathory', 'label' => "Justicar's Visit",
			'notes' => "Officially here to observe Konstantin's rule. Konstantin doesn't believe that for a second." ],
		[ 'a' => 'Konstantin Drake', 'b' => 'The Dockside Hunger', 'label' => 'The Disappearances',
			'notes' => "The Sheriff's missing mortals all vanished within sight of the old docks - and he has stopped saying so out loud." ],
		[ 'a' => 'Dr. Adrian Voss', 'b' => 'Isolde Marchetti', 'label' => 'Research Partners',
			'notes' => "Trading occult research - Voss doesn't fully grasp what he's really trading with." ],
	],

	'after_game_reports' => [
		[ 'session' => 'past-2', 'character' => 'Isolde Marchetti', 'account' => 'player',
			'did' => "Attended Elysium, heard the Sheriff's ultimatum firsthand, and quietly began compiling an alibi for every Primogen Council member.",
			'wants' => 'To find out what Konstantin actually knows before he announces it publicly.',
			'to_staff' => "Isolde suspects this is bigger than a missing-persons case. Happy to have her dig into it next session if that's where the story is going." ],
	],

	// The emails this chronicle would have sent. A demo chronicle sends none, so each one reads as not sent.
	'mail_log' => [
		[ 'account' => 'storyteller', 'kind' => 'plot_post', 'plot' => 'blood-in-the-water', 'hours_ago' => 2,
			'subject' => '[Beyond Elysium] New post on Blood in the Water in Beyond Elysium Demo' ],
		[ 'account' => 'storyteller', 'kind' => 'plot_post', 'plot' => 'sheriffs-ultimatum', 'hours_ago' => 26,
			'subject' => "[Beyond Elysium] New post on The Sheriff's Ultimatum in Beyond Elysium Demo" ],
		[ 'account' => 'player', 'kind' => 'change_outcome', 'hours_ago' => 27,
			'subject' => '[Beyond Elysium] Your character change was approved' ],
		[ 'account' => 'player', 'kind' => 'visible', 'plot' => 'blood-in-the-water', 'hours_ago' => 50,
			'subject' => '[Beyond Elysium] New Plot you can see: Blood in the Water' ],
		[ 'account' => 'storyteller', 'kind' => 'join_requested', 'hours_ago' => 74,
			'subject' => '[Beyond Elysium] Request to join Beyond Elysium Demo' ],
	],

	'release_batches' => [
		[ 'chronicle' => 'primary', 'name' => "This Week's Rumors" ],
	],

	'secret_reveals' => [
		[ 'secret_entity_type' => 'plot', 'secret_entity' => 'blood-in-the-water', 'character' => 'Isolde Marchetti', 'how' => 'game',
			'note' => "Pieced together from the Nosferatu's own rumors and a very careful conversation with Konstantin." ],
		[ 'secret_entity_type' => 'location', 'secret_entity' => 'tremere-chantry', 'character' => 'Isolde Marchetti', 'how' => 'game',
			'note' => 'Her own domain, her own ritual - nobody told her, she just knows.' ],
	],

	'saved_queries' => [
		[ 'chronicle' => 'primary', 'name' => 'All Vampires', 'inventory' => 'char',
			'conditions' => [ [ 'field' => 'stack_slug', 'operator' => 'equals', 'value' => 'vampire' ] ] ],
		[ 'chronicle' => 'primary', 'name' => 'Werewolves and Fera', 'inventory' => 'char', 'match_all' => false,
			'conditions' => [
				[ 'field' => 'stack_slug', 'operator' => 'equals', 'value' => 'werewolf' ],
				[ 'field' => 'stack_slug', 'operator' => 'equals', 'value' => 'fera' ],
			] ],
	],

	'sheet_styles' => [
		[ 'character' => 'Radu Bathory', 'font_family' => "'Trajan Pro', 'Cinzel', serif", 'accent_color' => '#e0b84a',
			'background_color' => '#1D2033', 'text_color' => '#F3F5F7' ],
		[ 'character' => 'Konstantin Drake', 'font_family' => 'Georgia, serif', 'accent_color' => '#77B2FF',
			'background_color' => '#11131F', 'text_color' => '#F8FAFC' ],
	],

];
