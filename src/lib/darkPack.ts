/**
 * What the app shell's footer says about the Dark Pack: the logo's address, the line that this is a community
 * project, and Paradox's notice in the agreement's own English words.
 */
import { __ } from '@wordpress/i18n';

export interface FooterNotice {
	/**
	 * The Dark Pack logo the plugin ships; empty when the plugin's address is unknown.
	 */
	logoUrl: string;
	community: string;
	/**
	 * The notice up to its link, never translated.
	 */
	notice: string;
	linkText: string;
	linkUrl: string;
}

export function footerNotice( assetsUrl: string ): FooterNotice {
	return {
		logoUrl: assetsUrl ? `${ assetsUrl }dark-pack-logo.png` : '',
		community: __(
			'Beyond Elysium is a non-commercial community project, not official World of Darkness material.',
			'beyond-elysium'
		),
		notice: 'Portions of the materials are the copyrights and trademarks of Paradox Interactive AB, and are used with permission. All rights reserved. For more information please visit',
		linkText: 'worldofdarkness.com',
		linkUrl: 'https://www.worldofdarkness.com/',
	};
}
