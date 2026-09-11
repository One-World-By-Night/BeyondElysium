/**
 * Admin page that displays the plugin's bundled documentation.
 * Renders the storyteller, admin, player, and REST API guides as tabbed
 * Markdown content, with in-page links between docs switching tabs
 * instead of navigating away.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { marked } from 'marked';
import type { MouseEvent } from 'react';
import api from '../../api/client';
import './Admin.css';
import './AdminDocs.css';

type DocSlug = 'st-guide' | 'admin-guide' | 'player-guide' | 'rest-api';

interface RestError {
	message?: string;
}

/**
 * Extracts a human-readable message from a caught error value.
 * Falls back to a generic message when the error has no usable
 * `message` property.
 */
function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Failed to load this document.', 'beyond-elysium' );
}

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
	const [ active, setActive ] = useState<DocSlug>( 'st-guide' );
	const [ cache, setCache ] = useState<Partial<Record<DocSlug, string>>>( {} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

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
				setCache( ( prev ) => ( { ...prev, [ active ]: result.content } ) );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError( errorMessage( err ) );
				setLoading( false );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ active ] );

	const html = cache[ active ] ? marked( cache[ active ] as string, { async: false } ) : '';

	/**
	 * Intercepts clicks on links inside the rendered document content.
	 * When a link points at another known doc, switches to that doc's
	 * tab instead of letting the browser navigate to a dead relative URL.
	 */
	function onContentClick( e: MouseEvent<HTMLDivElement> ) {
		const link = ( e.target as HTMLElement ).closest( 'a' );
		if ( ! link ) {
			return;
		}
		const href = link.getAttribute( 'href' ) ?? '';
		const slug = docLinkSlug( href );
		if ( slug ) {
			e.preventDefault();
			setActive( slug );
		}
	}

	return (
		<div className="be-admin be-admin-docs">
			<h1>{ __( 'Docs', 'beyond-elysium' ) }</h1>

			<div className="be-admin__tabs" role="tablist">
				{ TABS.map( ( tab ) => (
					<button
						key={ tab.slug }
						type="button"
						role="tab"
						aria-selected={ active === tab.slug }
						className={ 'be-admin__tab' + ( active === tab.slug ? ' be-admin__tab--active' : '' ) }
						onClick={ () => setActive( tab.slug ) }
					>
						{ tab.label }
					</button>
				) ) }
			</div>

			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<div
					className="be-admin-docs__content"
					onClick={ onContentClick }
					dangerouslySetInnerHTML={ { __html: html } }
				/>
			) }
		</div>
	);
}

export default AdminDocs;
