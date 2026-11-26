/**
 * Beyond Elysium's own page frame on the app shell: a header that names the site first, the page's sections as a row
 * on a desktop and a bottom bar on a phone, More for the rest, and the footer.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import Modal from '../shared/Modal';
import HelpButton from '../shared/HelpButton';
import { AppFooter } from './AppFooter';
import { AppIcon } from './AppIcon';
import {
	barPlaces,
	moreSections,
	returningTo,
	sectionHref,
	type NavSection,
} from '../../lib/appNav';
import './AppFrame.css';

export interface AppFrameProps {
	/**
	 * The page's one heading.
	 */
	pageTitle: string;
	helpKey: string;
	sections: NavSection[];
	active: string;
	onSelect: ( key: string ) => void;
	/**
	 * The bottom bar's places in order, with their short labels.
	 */
	places: NavSection[];
	placeCount: number;
	switcher: ReactNode;
	character?: ReactNode;
	otherPage?: { href: string; label: string } | null;
	children: ReactNode;
}

/**
 * The body class that gives the page the bottom bar's clearance on a phone.
 */
export const BAR_BODY_CLASS = 'be-app--has-bar';

/**
 * The account: the viewer's name with Sign out, or Sign in for a visitor, each coming back to this page.
 */
function AccountLink() {
	const global = window.beyondElysium;
	const here = window.location.href;
	const signedIn = Number( global?.currentUserId ?? 0 ) > 0;
	if ( signedIn && global?.logoutUrl ) {
		return (
			<span className="be-app-header__account">
				{ global.currentUserName && (
					<span className="be-app-header__user">
						{ global.currentUserName }
					</span>
				) }
				<a href={ returningTo( global.logoutUrl, here ) }>
					{ __( 'Sign out', 'beyond-elysium' ) }
				</a>
			</span>
		);
	}
	if ( ! signedIn && global?.loginUrl ) {
		return (
			<a
				className="be-app-header__account"
				href={ returningTo( global.loginUrl, here ) }
			>
				{ __( 'Sign in', 'beyond-elysium' ) }
			</a>
		);
	}
	return null;
}

export function AppFrame( {
	pageTitle,
	helpKey,
	sections,
	active,
	onSelect,
	places,
	placeCount,
	switcher,
	character,
	otherPage,
	children,
}: AppFrameProps ) {
	const [ moreOpen, setMoreOpen ] = useState( false );
	const site = window.beyondElysium?.site;
	const here = window.location.href;
	const bar = barPlaces( sections, places, placeCount );
	const more = moreSections( sections, bar );
	const hasBar = bar.length > 0;

	useEffect( () => {
		document.body.classList.toggle( BAR_BODY_CLASS, hasBar );
		return () => document.body.classList.remove( BAR_BODY_CLASS );
	}, [ hasBar ] );

	function select( key: string ) {
		setMoreOpen( false );
		onSelect( key );
	}

	const link = ( section: NavSection, className: string, icon = false ) => (
		<a
			className={ className }
			href={ sectionHref( here, section.key ) }
			aria-current={ section.key === active ? 'page' : undefined }
			onClick={ ( e ) => {
				e.preventDefault();
				select( section.key );
			} }
		>
			{ icon && <AppIcon name={ section.key } /> }
			<span>{ section.label }</span>
		</a>
	);

	return (
		<div
			className={
				hasBar ? 'be-app-frame' : 'be-app-frame be-app-frame--no-bar'
			}
		>
			<header className="be-app-header">
				<div className="be-app-header__row">
					{ site && (
						<a
							className="be-app-header__site"
							href={ site.homeUrl }
						>
							{ site.logoUrl && (
								<img
									className="be-app-header__logo"
									src={ site.logoUrl }
									alt=""
								/>
							) }
							<span className="be-app-header__site-name">
								{ site.name }
							</span>
						</a>
					) }
					<h1 className="be-app-header__title">{ pageTitle }</h1>
					<div className="be-app-header__tools">
						{ otherPage && (
							<a
								className="be-app-header__other"
								href={ otherPage.href }
							>
								{ otherPage.label }
							</a>
						) }
						<HelpButton helpKey={ helpKey } />
						<AccountLink />
					</div>
				</div>
				<div className="be-app-header__context">
					{ switcher }
					{ character }
				</div>
			</header>

			{ sections.length > 0 && (
				<nav
					className="be-app-tabs"
					aria-label={ __( 'Sections', 'beyond-elysium' ) }
				>
					<ul>
						{ sections.map( ( section ) => (
							<li key={ section.key }>
								{ link( section, 'be-app-tabs__tab' ) }
							</li>
						) ) }
					</ul>
				</nav>
			) }

			<div className="be-app-frame__content">{ children }</div>

			<AppFooter />

			{ hasBar && (
				<nav
					className="be-app-bar"
					aria-label={ __( 'Main', 'beyond-elysium' ) }
				>
					<ul>
						{ bar.map( ( place ) => (
							<li key={ place.key }>
								{ link( place, 'be-app-bar__place', true ) }
							</li>
						) ) }
						<li>
							<button
								type="button"
								className="be-app-bar__place"
								aria-expanded={ moreOpen }
								onClick={ () => setMoreOpen( true ) }
							>
								<AppIcon name="more" />
								<span>{ __( 'More', 'beyond-elysium' ) }</span>
							</button>
						</li>
					</ul>
				</nav>
			) }

			{ moreOpen && (
				<Modal
					title={ __( 'More', 'beyond-elysium' ) }
					onClose={ () => setMoreOpen( false ) }
				>
					<ul className="be-app-more">
						{ more.map( ( section ) => (
							<li key={ section.key }>
								{ link( section, 'be-app-more__item' ) }
							</li>
						) ) }
						{ otherPage && (
							<li>
								<a
									className="be-app-more__item"
									href={ otherPage.href }
								>
									{ otherPage.label }
								</a>
							</li>
						) }
						<li className="be-app-more__tools">
							<HelpButton helpKey={ helpKey } />
							<AccountLink />
						</li>
					</ul>
				</Modal>
			) }
		</div>
	);
}

export default AppFrame;
