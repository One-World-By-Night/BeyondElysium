/**
 * The app shell's footer: the Powered by line with its Credits, the Dark Pack logo, the line that this is a community
 * project, and Paradox's notice as the Dark Pack Agreement words it.
 */
import { __ } from '@wordpress/i18n';
import PoweredByFooter from '../shared/PoweredByFooter';
import { footerNotice } from '../../lib/darkPack';

export function AppFooter() {
	const footer = footerNotice( window.beyondElysium?.assetsUrl ?? '' );
	return (
		<footer className="be-app-footer">
			<PoweredByFooter />
			<div className="be-app-footer__dark-pack">
				{ footer.logoUrl !== '' && (
					<img
						className="be-app-footer__logo"
						src={ footer.logoUrl }
						alt={ __( 'Dark Pack logo', 'beyond-elysium' ) }
						width={ 64 }
						height={ 64 }
						loading="lazy"
					/>
				) }
				<div className="be-app-footer__notice">
					<p>{ footer.community }</p>
					<p lang="en">
						{ footer.notice }{ ' ' }
						<a href={ footer.linkUrl } rel="noopener noreferrer">
							{ footer.linkText }
						</a>
						.
					</p>
				</div>
			</div>
		</footer>
	);
}

export default AppFooter;
