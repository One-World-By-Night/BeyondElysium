/**
 * The trait panel: what one held trait or power is. The next dot's price and whether a Storyteller must approve it,
 * the chronicle's own description, the source, what it needs, and this character's history with it. It rises from the
 * bottom on a phone and opens as a drawer on a desktop.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { highlightStMarkers } from '../../lib/highlightStMarkers';
import type { HeldTrait } from '../../lib/heldTraits';
import { formatStoredTime } from '../../lib/storedTime';
import { stripStSections } from '../../lib/stripStSections';
import {
	catalogEntry,
	changesNaming,
	nextChange,
	priceText,
	visibleDescription,
	type HistoryLine,
} from '../../lib/traitPanel';
import type { SchemaBlock } from '../../types';
import type { ChangePreviewResult } from '../../types/character';
import './TraitPanel.css';

export interface TraitPanelProps {
	gameSlug: string;
	characterId: number;
	trait: HeldTrait;
	/**
	 * The trait's section's block, as the character's creature type has it.
	 */
	block: SchemaBlock | undefined;
	sheetData: Record< string, unknown >;
	/**
	 * The name as the sheet shows it, translated where the catalog is.
	 */
	label: string;
	canEdit: boolean;
	canManage: boolean;
	onClose: () => void;
	/**
	 * Opens another view of the character's section in place.
	 */
	onView?: ( view: 'history' | 'edit' ) => void;
}

type Quote =
	| { state: 'none' }
	| { state: 'loading' }
	| { state: 'refused' }
	| { state: 'ready'; result: ChangePreviewResult };

function ratingText( trait: HeldTrait ): string {
	if ( trait.rating === null ) {
		return __( 'Held', 'beyond-elysium' );
	}
	return trait.sectionType === 'tiered_power'
		? sprintf(
				/* translators: %d: the level a character holds a power at */
				__( 'Level %d', 'beyond-elysium' ),
				trait.rating
			)
		: sprintf(
				/* translators: %d: how many dots or points a character holds of a trait */
				__( 'Held: %d', 'beyond-elysium' ),
				trait.rating
			);
}

export function TraitPanel( {
	gameSlug,
	characterId,
	trait,
	block,
	sheetData,
	label,
	canEdit,
	canManage,
	onClose,
	onView,
}: TraitPanelProps ) {
	const panel = useRef< HTMLElement >( null );
	const [ quote, setQuote ] = useState< Quote >( { state: 'none' } );
	const [ history, setHistory ] = useState< HistoryLine[] | null >( null );

	const change = useMemo(
		() =>
			block && ( canEdit || canManage )
				? nextChange( trait, block, sheetData[ trait.blockSlug ] )
				: null,
		[ trait, block, sheetData, canEdit, canManage ]
	);
	const entry = useMemo(
		() => catalogEntry( block, trait ),
		[ block, trait ]
	);

	useEffect( () => {
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', onKey );
		const before = panel.current?.ownerDocument
			.activeElement as HTMLElement | null;
		panel.current?.focus();
		return () => {
			document.removeEventListener( 'keydown', onKey );
			before?.focus?.();
		};
	}, [ onClose ] );

	useEffect( () => {
		if ( ! change ) {
			setQuote( { state: 'none' } );
			return undefined;
		}
		let live = true;
		setQuote( { state: 'loading' } );
		api.characters( gameSlug )
			.previewChanges( characterId, [ change ] )
			.then( ( response ) => {
				const result = response.results[ 0 ];
				if ( live ) {
					setQuote(
						! result || result.invalid
							? { state: 'refused' }
							: { state: 'ready', result }
					);
				}
			} )
			.catch( () => live && setQuote( { state: 'refused' } ) );
		return () => {
			live = false;
		};
	}, [ gameSlug, characterId, change ] );

	useEffect( () => {
		let live = true;
		api.changes( gameSlug )
			.list( characterId, { per_page: 100, order: 'DESC' } )
			.then(
				( rows ) => live && setHistory( changesNaming( rows, trait ) )
			)
			.catch( () => live && setHistory( [] ) );
		return () => {
			live = false;
		};
	}, [ gameSlug, characterId, trait ] );

	const description = entry?.description?.description
		? visibleDescription(
				entry.description.description,
				canManage,
				stripStSections,
				highlightStMarkers
			)
		: '';
	const citation = entry?.citation;

	return (
		<div
			className="be-trait-panel__backdrop"
			role="presentation"
			onClick={ onClose }
		>
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-noninteractive-element-interactions */ }
			<aside
				ref={ panel }
				className="be-trait-panel"
				role="dialog"
				aria-modal="true"
				aria-label={ label }
				tabIndex={ -1 }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<header className="be-trait-panel__header">
					<div>
						<h3 className="be-trait-panel__name">{ label }</h3>
						<p className="be-trait-panel__meta">
							{ trait.section } · { ratingText( trait ) }
						</p>
					</div>
					<button
						type="button"
						className="be-trait-panel__close"
						onClick={ onClose }
					>
						{ __( 'Close', 'beyond-elysium' ) }
					</button>
				</header>

				{ quote.state !== 'none' && (
					<section className="be-trait-panel__section">
						<h4>{ __( 'Next', 'beyond-elysium' ) }</h4>
						{ quote.state === 'loading' && (
							<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
						) }
						{ quote.state === 'refused' && (
							<p>
								{ __(
									'Already at the most this can be.',
									'beyond-elysium'
								) }
							</p>
						) }
						{ quote.state === 'ready' && (
							<>
								<p>{ priceText( quote.result ) }</p>
								{ ! canManage && (
									<p>
										{ quote.result.approval_level === 'st'
											? __(
													'Needs a Storyteller.',
													'beyond-elysium'
												)
											: __(
													'No Storyteller needed.',
													'beyond-elysium'
												) }
										{ quote.result.approval_reason
											? ` ${ quote.result.approval_reason }`
											: '' }
									</p>
								) }
							</>
						) }
					</section>
				) }

				{ ( !! description ||
					!! citation ||
					( entry?.prerequisites?.length ?? 0 ) > 0 ) && (
					<section className="be-trait-panel__section">
						<h4>{ __( 'About', 'beyond-elysium' ) }</h4>
						{ description && (
							<div
								className="be-trait-panel__description"
								dangerouslySetInnerHTML={ {
									__html: description,
								} }
							/>
						) }
						{ citation && (
							<p>
								{ sprintf(
									/* translators: %s: where a trait is described, such as a book and page */
									__( 'Source: %s', 'beyond-elysium' ),
									citation
								) }
							</p>
						) }
						{ entry?.prerequisites?.map( ( need ) => (
							<p key={ `${ need.block_slug }.${ need.power }` }>
								{ sprintf(
									/* translators: 1: the power a trait needs, 2: the level of it */
									__( 'Needs %1$s %2$d', 'beyond-elysium' ),
									need.power,
									need.min_level
								) }
							</p>
						) ) }
					</section>
				) }

				<section className="be-trait-panel__section">
					<h4>{ __( 'History', 'beyond-elysium' ) }</h4>
					{ history === null && (
						<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
					) }
					{ history !== null && history.length === 0 && (
						<p>
							{ __(
								'Nothing recorded for this yet.',
								'beyond-elysium'
							) }
						</p>
					) }
					{ history !== null && history.length > 0 && (
						<ul className="be-trait-panel__history">
							{ history.map( ( line ) => (
								<li key={ line.id }>
									<span>{ line.description }</span>{ ' ' }
									<span className="be-trait-panel__when">
										{ formatStoredTime( line.createdAt, {
											time: false,
										} ) }
									</span>
								</li>
							) ) }
						</ul>
					) }
					{ onView && (
						<button
							type="button"
							className="be-trait-panel__link"
							onClick={ () => onView( 'history' ) }
						>
							{ __( 'See all in History', 'beyond-elysium' ) }
						</button>
					) }
				</section>

				{ canEdit && onView && (
					<button
						type="button"
						className="be-trait-panel__link"
						onClick={ () => onView( 'edit' ) }
					>
						{ __( 'Change it in Edit', 'beyond-elysium' ) }
					</button>
				) }
			</aside>
		</div>
	);
}

export default TraitPanel;
