/**
 * Reading and clearing My Chronicle's own `discord_link`/`discord_slug` query parameters.
 */

export interface DiscordLinkTarget {
	token: string;
	slug: string;
}

/**
 * Reads the panel's own token and target chronicle from a location's search string.
 *
 * @param search A `location.search` string, leading `?` optional.
 */
export function readDiscordLinkParams(
	search: string
): DiscordLinkTarget | null {
	const params = new URLSearchParams( search );
	const token = params.get( 'discord_link' );
	const slug = params.get( 'discord_slug' );
	return token && slug ? { token, slug } : null;
}

/**
 * Removes the panel's own parameters from a URL, along with the `auth` parameter the SSO redirect may have added.
 *
 * @param href The current full URL.
 */
export function clearedDiscordLinkUrl( href: string ): string {
	const url = new URL( href );
	url.searchParams.delete( 'discord_link' );
	url.searchParams.delete( 'discord_slug' );
	url.searchParams.delete( 'auth' );
	return url.toString();
}
