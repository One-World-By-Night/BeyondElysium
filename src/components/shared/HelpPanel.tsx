/**
 * The help side panel: a screen's help page slides in from the right and leaves the screen usable.
 */
import { createPortal, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Marked } from 'marked';
import type { KeyboardEvent, MouseEvent } from 'react';
import api from '../../api/client';
import {
	headingSlug,
	headingText,
	helpTarget,
	pageTitle,
	uniqueAnchor,
	withoutTitle,
	type GuideSlug,
} from '../../lib/helpPage';
import './HelpPanel.css';

export interface HelpPanelProps {
	helpKey: string;
	onClose: () => void;
}

type Page =
	| { kind: 'help'; key: string; anchor: string }
	| { kind: 'guide'; slug: GuideSlug; anchor: string };

/**
 * Heading ids carry this.
 */
const ANCHOR_PREFIX = 'be-help-';

function escapeAttribute( value: string ): string {
	return value
		.replace( /&/g, '&amp;' )
		.replace( /"/g, '&quot;' )
		.replace( /</g, '&lt;' );
}

function plainText( html: string ): string {
	return html
		.replace( /<[^>]*>/g, '' )
		.replace( /&quot;/g, '"' )
		.replace( /&#39;/g, "'" )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&amp;/g, '&' );
}

/**
 * A page's HTML: its title left to the panel header, its sections one heading level down under that title, each
 * heading given the anchor links use, and each table cell labelled with its column.
 */
function renderPage( markdown: string ): string {
	const seen = new Map< string, number >();
	const marked = new Marked( {
		renderer: {
			heading( { tokens, depth, text } ) {
				const level = Math.min( depth + 1, 6 );
				const id = uniqueAnchor(
					headingSlug( headingText( text ) ),
					seen
				);
				return `<h${ level } id="${ ANCHOR_PREFIX }${ escapeAttribute(
					id
				) }">${ this.parser.parseInline( tokens ) }</h${ level }>\n`;
			},
			table( { header, rows } ) {
				const labels = header.map( ( cell ) =>
					plainText( this.parser.parseInline( cell.tokens ) )
				);
				const head = header
					.map(
						( cell ) =>
							`<th>${ this.parser.parseInline(
								cell.tokens
							) }</th>`
					)
					.join( '' );
				const body = rows
					.map(
						( row ) =>
							`<tr>${ row
								.map(
									( cell, i ) =>
										`<td data-label="${ escapeAttribute(
											labels[ i ] ?? ''
										) }">${ this.parser.parseInline(
											cell.tokens
										) }</td>`
								)
								.join( '' ) }</tr>`
					)
					.join( '' );
				return `<table><thead><tr>${ head }</tr></thead><tbody>${ body }</tbody></table>\n`;
			},
			link( { href, title, tokens } ) {
				const external = helpTarget( href )?.kind === 'external';
				return `<a href="${ escapeAttribute( href ) }"${
					title ? ` title="${ escapeAttribute( title ) }"` : ''
				}${
					external ? ' target="_blank" rel="noopener noreferrer"' : ''
				}>${ this.parser.parseInline( tokens ) }</a>`;
			},
		},
	} );
	return marked.parse( withoutTitle( markdown ), { async: false } ) as string;
}

export function HelpPanel( { helpKey, onClose }: HelpPanelProps ) {
	const [ pages, setPages ] = useState< Page[] >( [
		{ kind: 'help', key: helpKey, anchor: '' },
	] );
	const [ loaded, setLoaded ] = useState< {
		title: string;
		html: string;
	} | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const titleRef = useRef< HTMLHeadingElement >( null );
	const bodyRef = useRef< HTMLDivElement >( null );
	const page = pages[ pages.length - 1 ];

	// Focus starts at the title, and returns there on each page the panel opens.
	useEffect( () => {
		titleRef.current?.focus();
	}, [ pages.length ] );

	useEffect( () => {
		let cancelled = false;
		setLoaded( null );
		setError( null );
		const request =
			page.kind === 'help'
				? api.docs.help( page.key )
				: api.docs.get( page.slug );
		request
			.then( ( result ) => {
				if ( ! cancelled ) {
					setLoaded( {
						title: pageTitle( result.content ),
						html: renderPage( result.content ),
					} );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__(
							'This help page could not be loaded.',
							'beyond-elysium'
						)
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ page ] );

	// Opens a page at the section its link named, or at its top.
	useEffect( () => {
		const body = bodyRef.current;
		if ( ! loaded || ! body ) {
			return;
		}
		const section = page.anchor
			? body.ownerDocument.getElementById( ANCHOR_PREFIX + page.anchor )
			: null;
		if ( section ) {
			section.scrollIntoView();
		} else {
			body.scrollTop = 0;
		}
	}, [ loaded, page ] );

	/**
	 * Follows a link inside the page: another help page or a guide opens in the panel, a section scrolls into view, the
	 * web opens in a new tab.
	 */
	function follow( event: MouseEvent< HTMLDivElement > ) {
		const href = ( event.target as HTMLElement )
			.closest( 'a' )
			?.getAttribute( 'href' );
		if ( ! href ) {
			return;
		}
		const target = helpTarget( href );
		if ( target?.kind === 'external' ) {
			return;
		}
		event.preventDefault();
		if ( ! target ) {
			return;
		}
		if ( target.kind === 'section' ) {
			bodyRef.current?.ownerDocument
				.getElementById( ANCHOR_PREFIX + target.anchor )
				?.scrollIntoView();
			return;
		}
		setPages( ( previous ) => [ ...previous, target ] );
	}

	function onKeyDown( event: KeyboardEvent< HTMLDivElement > ) {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			onClose();
		}
	}

	return createPortal(
		// Escape closes the panel from anywhere inside it.
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			className="be-help-panel"
			role="dialog"
			aria-modal="false"
			aria-labelledby="be-help-panel-title"
			onKeyDown={ onKeyDown }
		>
			<div className="be-help-panel__header">
				{ pages.length > 1 && (
					<button
						type="button"
						className="be-help-panel__back"
						onClick={ () =>
							setPages( ( previous ) => previous.slice( 0, -1 ) )
						}
					>
						{ __( 'Back', 'beyond-elysium' ) }
					</button>
				) }
				<h2
					id="be-help-panel-title"
					className="be-help-panel__title"
					tabIndex={ -1 }
					ref={ titleRef }
				>
					{ loaded?.title || __( 'Help', 'beyond-elysium' ) }
				</h2>
				<button
					type="button"
					className="be-help-panel__close"
					onClick={ onClose }
					aria-label={ __( 'Close help', 'beyond-elysium' ) }
				>
					×
				</button>
			</div>
			{ /* Links are followed here: each is an <a>, reached and opened from the keyboard as a link. */ }
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
			<div
				className="be-help-panel__body"
				ref={ bodyRef }
				onClick={ follow }
			>
				{ error && <p role="alert">{ error }</p> }
				{ ! error && ! loaded && (
					<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
				) }
				{ loaded && (
					<div
						className="be-help-panel__page"
						dangerouslySetInnerHTML={ { __html: loaded.html } }
					/>
				) }
			</div>
		</div>,
		document.body
	);
}

export default HelpPanel;
