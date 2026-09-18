/**
 * Admin page that displays the plugin's bundled documentation.
 * Renders the storyteller, admin, player, and REST API guides as tabbed
 * Markdown content, with in-page links between docs switching tabs
 * instead of navigating away.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { marked } from 'marked';
import type { MouseEvent } from 'react';
import api from '../../api/client';
import { helpTarget } from '../../lib/helpPage';
import { useHelpStore } from '../../store/helpStore';
import { errorMessage } from '../../lib/errorMessage';
import HelpButton from '../shared/HelpButton';
import HelpPanel from '../shared/HelpPanel';
import TabStrip from '../shared/TabStrip';
import './Admin.css';
import './AdminDocs.css';

type DocSlug = 'st-guide' | 'admin-guide' | 'player-guide' | 'rest-api';

const TABS: { slug: DocSlug; label: string }[] = [
	{ slug: 'st-guide', label: __( 'Storyteller Guide', 'beyond-elysium' ) },
	{ slug: 'admin-guide', label: __( 'Admin Guide', 'beyond-elysium' ) },
	{ slug: 'player-guide', label: __( 'Player Guide', 'beyond-elysium' ) },
	{ slug: 'rest-api', label: __( 'REST API Reference', 'beyond-elysium' ) },
];

const TAB_SLUGS: string[] = TABS.map( ( tab ) => tab.slug );

/**
 * Resolves a link's href to a known doc tab slug, if it matches one.
 * Strips any hash fragment and a trailing `.md` extension, then checks
 * the result against the set of known tab slugs.
 */
function docLinkSlug( href: string ): DocSlug | null {
	const base = href.split( '#' )[ 0 ].replace( /\.md$/, '' );
	return TAB_SLUGS.includes( base ) ? ( base as DocSlug ) : null;
}

/**
 * Renders the Docs admin screen.
 * Shows the plugin's shipped Markdown documentation across four tabs
 * (storyteller, admin, player, and REST API guides), fetching and
 * caching each document's content the first time its tab is opened.
 */
export function AdminDocs() {
	const [ active, setActive ] = useState< DocSlug >( 'st-guide' );
	const [ cache, setCache ] = useState<
		Partial< Record< DocSlug, string > >
	>( {} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	// A guide can link down into one help page (e.g. "Send a Grapevine File" from the Player
	// Guide); it opens in the same side panel every screen's own `?` button uses.
	const linkedHelpOwner = useRef(
		Symbol( 'admin-docs-linked-help' )
	).current;
	const [ linkedHelpKey, setLinkedHelpKey ] = useState< string | null >(
		null
	);
	const linkedHelpOpen = useHelpStore(
		( state ) => state.owner === linkedHelpOwner
	);
	const openLinkedHelp = useHelpStore( ( state ) => state.open );
	const closeLinkedHelp = useHelpStore( ( state ) => state.close );

	useEffect(
		() => () => closeLinkedHelp( linkedHelpOwner ),
		[ closeLinkedHelp, linkedHelpOwner ]
	);

	useEffect( () => {
		if ( cache[ active ] !== undefined ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		api.docs
			.get( active )
			.then( ( result ) => {
				setCache( ( prev ) => ( {
					...prev,
					[ active ]: result.content,
				} ) );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError(
					errorMessage(
						err,
						__( 'Failed to load this document.', 'beyond-elysium' )
					)
				);
				setLoading( false );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ active ] );

	const html = cache[ active ]
		? marked( cache[ active ] as string, { async: false } )
		: '';

	/**
	 * Intercepts clicks on links inside the rendered document content. A link to another
	 * known doc switches to that doc's tab; a link down into a help page opens it in the
	 * side panel instead of letting the browser navigate to a dead relative URL.
	 */
	function onContentClick( e: MouseEvent< HTMLDivElement > ) {
		const link = ( e.target as HTMLElement ).closest( 'a' );
		if ( ! link ) {
			return;
		}
		const href = link.getAttribute( 'href' ) ?? '';
		const slug = docLinkSlug( href );
		if ( slug ) {
			e.preventDefault();
			setActive( slug );
			return;
		}
		const target = helpTarget( href );
		if ( target?.kind === 'help' ) {
			e.preventDefault();
			setLinkedHelpKey( target.key );
			openLinkedHelp( linkedHelpOwner );
		}
	}

	return (
		<div className="be-admin be-admin-docs">
			<div className="be-help-heading">
				<h1>{ __( 'Docs', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="admin-docs" />
			</div>

			<TabStrip
				tabs={ TABS.map( ( tab ) => ( {
					key: tab.slug,
					label: tab.label,
				} ) ) }
				active={ active }
				onChange={ ( key ) => setActive( key as DocSlug ) }
			/>

			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				// Catches clicks on the document's own links, which a keyboard reaches and follows as links.
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<div
					className="be-admin-docs__content"
					onClick={ onContentClick }
					dangerouslySetInnerHTML={ { __html: html } }
				/>
			) }

			{ linkedHelpOpen && linkedHelpKey && (
				<HelpPanel
					key={ linkedHelpKey }
					helpKey={ linkedHelpKey }
					onClose={ () => closeLinkedHelp( linkedHelpOwner ) }
				/>
			) }
		</div>
	);
}

export default AdminDocs;
