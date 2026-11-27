/**
 * The trait panel: what one held trait or power is. The next dot's price and whether a Storyteller must approve it,
 * the chronicle's own description, the source, what it needs, and this character's history with it. It rises from the
 * bottom on a phone and opens as a drawer on a desktop.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import useCharacterEditorStore from '../../store/characterEditorStore';
import { coordinatorNote } from '../../lib/coordinatorSend';
import { highlightStMarkers } from '../../lib/highlightStMarkers';
import type { HeldTrait } from '../../lib/heldTraits';
import { formatStoredTime } from '../../lib/storedTime';
import { localizedPowerName } from '../../lib/localizeName';
import { stripStSections } from '../../lib/stripStSections';
import {
	cartButtonLabel,
	cartStep,
	catalogEntry,
	changesNaming,
	priceText,
	visibleDescription,
	type HistoryLine,
} from '../../lib/traitPanel';
import type { SchemaBlock } from '../../types';
import type { ChangePreviewResult } from '../../types/character';
import './TraitPanel.css';

export interface TraitPanelProps {
	gameSlug: string;
	/**
	 * The character the panel prices and adds for; null reads the catalog entry alone.
	 */
	characterId: number | null;
	trait: HeldTrait;
	/**
	 * True when the entry is the catalog's and the character does not hold it.
	 */
	notHeld?: boolean;
	/**
	 * Where the compendium shows this entry, when the panel was not opened from there.
	 */
	compendiumHref?: string | null;
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
	notHeld = false,
	compendiumHref = null,
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

	// The cart's own sheet is the draft; what the server holds is the cart's baseline.
	const draftSheet = useCharacterEditorStore( ( state ) =>
		state.characterId === characterId && state.character
			? state.sheetData
			: null
	);
	const savedSheet = useCharacterEditorStore( ( state ) =>
		state.characterId === characterId && state.character
			? state.originalSheetData
			: null
	);
	const setBlockData = useCharacterEditorStore(
		( state ) => state.setBlockData
	);
	const openReview = useCharacterEditorStore( ( state ) => state.openReview );
	const inCart = draftSheet !== null && savedSheet !== null;

	const step = useMemo(
		() =>
			characterId !== null && block && ( canEdit || canManage )
				? cartStep(
						trait,
						block,
						( savedSheet ?? sheetData )[ trait.blockSlug ],
						( draftSheet ?? sheetData )[ trait.blockSlug ]
					)
				: null,
		[
			characterId,
			trait,
			block,
			sheetData,
			savedSheet,
			draftSheet,
			canEdit,
			canManage,
		]
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
		if ( ! step || characterId === null ) {
			setQuote( { state: 'none' } );
			return undefined;
		}
		let live = true;
		setQuote( { state: 'loading' } );
		// What the cart holds for this trait is priced alongside, so one more step is priced as that step alone.
		api.characters( gameSlug )
			.previewChanges(
				characterId,
				step.current ? [ step.next, step.current ] : [ step.next ]
			)
			.then( ( response ) => {
				const result = response.results[ 0 ];
				if ( live ) {
					setQuote(
						! result || result.invalid
							? { state: 'refused' }
							: {
									state: 'ready',
									result: {
										...result,
										xp_cost:
											result.xp_cost -
											( response.results[ 1 ]?.xp_cost ??
												0 ),
									},
								}
					);
				}
			} )
			.catch( () => live && setQuote( { state: 'refused' } ) );
		return () => {
			live = false;
		};
	}, [ gameSlug, characterId, step ] );

	useEffect( () => {
		if ( characterId === null ) {
			setHistory( [] );
			return undefined;
		}
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
							{ notHeld
								? trait.section
								: `${ trait.section } · ${ ratingText( trait ) }` }
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
										{ quote.result.approval_level ===
											'st' &&
										coordinatorNote(
											quote.result.coordinator
										)
											? ` ${ coordinatorNote( quote.result.coordinator ) }`
											: '' }
									</p>
								) }
							</>
						) }
						{ inCart && canEdit && step && (
							<div className="be-trait-panel__cart">
								<button
									type="button"
									className="be-trait-panel__add"
									disabled={ quote.state !== 'ready' }
									onClick={ () =>
										setBlockData(
											trait.blockSlug,
											step.rows
										)
									}
								>
									{ cartButtonLabel(
										step,
										label,
										trait.sectionType === 'tiered_power',
										quote.state === 'ready'
											? quote.result.xp_cost
											: null
									) }
								</button>
								{ step.current && (
									<button
										type="button"
										className="be-trait-panel__link"
										onClick={ () => {
											onClose();
											openReview();
										} }
									>
										{ __(
											'In your cart · Review',
											'beyond-elysium'
										) }
									</button>
								) }
							</div>
						) }
					</section>
				) }

				{ ( !! description ||
					!! citation ||
					!! entry?.book ||
					( entry?.ladder?.length ?? 0 ) > 0 ||
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
						{ entry?.book && (
							<p>
								{ sprintf(
									/* translators: %s: the title of the book a trait comes from */
									__( 'Book: %s', 'beyond-elysium' ),
									entry.book
								) }
							</p>
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
						{ ( entry?.ladder?.length ?? 0 ) > 0 && (
							<>
								<h5>{ __( 'Levels', 'beyond-elysium' ) }</h5>
								<ul className="be-trait-panel__ladder">
									{ entry?.ladder?.map( ( level ) => (
										<li key={ level.power_name }>
											<span>
												{ localizedPowerName( level ) }
											</span>
											{ level.cost && (
												<span className="be-trait-panel__when">
													{ sprintf(
														/* translators: 1: the tier of a power level, 2: what it costs in XP */
														__(
															'%1$s · %2$s XP',
															'beyond-elysium'
														),
														level.tier,
														level.cost
													) }
												</span>
											) }
										</li>
									) ) }
								</ul>
							</>
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

				{ characterId !== null && (
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
											{ formatStoredTime(
												line.createdAt,
												{
													time: false,
												}
											) }
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
				) }

				{ compendiumHref && (
					<a className="be-trait-panel__link" href={ compendiumHref }>
						{ __( 'See in the compendium', 'beyond-elysium' ) }
					</a>
				) }

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
