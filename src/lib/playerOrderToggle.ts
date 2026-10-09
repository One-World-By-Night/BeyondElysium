/**
 * Pure logic behind a schema block's "Players set their own order" admin checkbox, shared by the trait_list and
 * tiered_power sub-editors.
 */

export interface PlayerOrderCapable {
	player_order?: boolean;
}

export function setPlayerOrder< T extends PlayerOrderCapable >(
	definition: T,
	on: boolean
): T {
	return { ...definition, player_order: on };
}

export function showsReorderButton( definition: PlayerOrderCapable ): boolean {
	return !! definition.player_order;
}
