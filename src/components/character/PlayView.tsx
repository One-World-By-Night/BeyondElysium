/**
 * The Play view: what a player needs at the table. Pools with Spend and Regain (Gain and Lose for a count that builds
 * up, nothing for a pool with no temporary points), the health track with Take and Heal, what was lost tonight, and a
 * search of the character's own traits.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import Modal from '../shared/Modal';
import TraitPanel from './TraitPanel';
import { compendiumHref } from '../../lib/compendiumLinks';
import WithDots from '../shared/Dots';
import { displayTemper } from '../../lib/displayTemper';
import { shownPoolValue } from '../../lib/poolKind';
import { errorMessage } from '../../lib/errorMessage';
import { heldTraits, searchTraits, type HeldTrait } from '../../lib/heldTraits';
import { localizedItemName } from '../../lib/localizeName';
import { traitLabel } from '../../lib/traitPanel';
import {
	canRegain,
	canSpend,
	canSpendPermanent,
	healthLevels,
	healthTrack,
	nightLossesFrom,
	poolRows,
	temporaryControls,
	waitingSpends,
	woundsFrom,
	type PoolRow,
	type WoundType,
} from '../../lib/play';
import { resolveSectionTitle } from '../../lib/resolveCrossBlockRef';
import { sortedForFlow } from '../../lib/templateLayout';
import { withHeldBlockSections } from '../../lib/heldBlockSections';
import { useCharacterSheet } from '../../lib/useCharacterSheet';
import type { TraitListDefinition } from '../../types';
import './PlayView.css';

export interface PlayViewProps {
	characterId: number;
	gameSlug: string;
	canEdit: boolean;
	canManage: boolean;
	/**
	 * Opens another view of the Character section in place, from the trait panel.
	 */
	onView?: ( view: 'history' | 'edit' ) => void;
}

const WOUND_TYPES: WoundType[] = [ 'bashing', 'lethal', 'aggravated' ];

const DAMAGE_MARKS: Record< WoundType, string > = {
	bashing: '/',
	lethal: 'X',
	aggravated: '*',
};

function woundLabel( type: WoundType ): string {
	switch ( type ) {
		case 'bashing':
			return __( 'Bashing', 'beyond-elysium' );
		case 'lethal':
			return __( 'Lethal', 'beyond-elysium' );
		default:
			return __( 'Aggravated', 'beyond-elysium' );
	}
}

export function PlayView( {
	characterId,
	gameSlug,
	canEdit,
	canManage,
	onView,
}: PlayViewProps ) {
	const { state } = useCharacterSheet( characterId, gameSlug, 'sheet_full' );
	const [ sheetData, setSheetData ] = useState< Record<
		string,
		unknown
	> | null >( null );
	const [ busy, setBusy ] = useState( '' );
	const [ problems, setProblems ] = useState< Record< string, string > >(
		{}
	);
	const [ query, setQuery ] = useState( '' );
	const [ openTrait, setOpenTrait ] = useState< HeldTrait | null >( null );
	const [ spending, setSpending ] = useState< PoolRow | null >( null );
	const [ spendReason, setSpendReason ] = useState( '' );
	const [ waiting, setWaiting ] = useState< Record< string, number > >( {} );

	useEffect( () => {
		let live = true;
		api.changes( gameSlug )
			.list( characterId, { status: 'pending', per_page: 100 } )
			.then( ( rows ) => live && setWaiting( waitingSpends( rows ) ) )
			.catch( () => live && setWaiting( {} ) );
		return () => {
			live = false;
		};
	}, [ gameSlug, characterId ] );

	useEffect( () => {
		setSheetData(
			state.status === 'ready' ? state.character.sheet_data : null
		);
	}, [ state ] );

	const view = useMemo( () => {
		if ( state.status !== 'ready' || sheetData === null ) {
			return null;
		}
		const sections = sortedForFlow(
			withHeldBlockSections(
				state.resolved.template.layout.sections,
				state.stack,
				sheetData
			)
		);
		const healthSection = state.stack.stack.stack_definition.sections.find(
			( section ) => section.health_track
		);
		const healthSlug = healthSection?.block_slug ?? '';
		const healthBlock = healthSlug
			? state.stack.blocks[ healthSlug ]
			: null;
		const stored = healthLevels( sheetData[ healthSlug ] );
		const levels =
			stored.length > 0
				? stored
				: healthLevels(
						( healthBlock?.definition as TraitListDefinition )
							?.default_held
					);
		const shownName = ( blockSlug: string, name: string ) => {
			const items =
				(
					state.stack.blocks[ blockSlug ]
						?.definition as TraitListDefinition
				 )?.items ?? [];
			const item = items.find( ( entry ) => entry.name === name );
			return item ? localizedItemName( item ) : name;
		};
		return {
			pools: poolRows(
				sheetData,
				state.stack.blocks,
				sections.map( ( section ) => section.block_slug )
			),
			hasTrack: healthSlug !== '' && levels.length > 0,
			track: healthTrack( levels, woundsFrom( sheetData ) ),
			healthSlug,
			shownName,
			held: heldTraits(
				sections.map( ( section ) => ( {
					blockSlug: section.block_slug,
					title: resolveSectionTitle( section, sheetData ),
					sectionType:
						state.stack.blocks[ section.block_slug ]
							?.section_type ?? '',
				} ) ),
				sheetData,
				healthSlug ? [ healthSlug ] : [],
				shownName
			),
		};
	}, [ state, sheetData ] );

	if (
		state.status === 'loading' ||
		( state.status === 'ready' && ! view )
	) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( state.status === 'error' || ! view || sheetData === null ) {
		return (
			<p role="alert">
				{ state.status === 'error'
					? state.message
					: __(
							'Failed to load the character sheet.',
							'beyond-elysium'
						) }
			</p>
		);
	}

	const wounds = woundsFrom( sheetData );
	const losses = nightLossesFrom( sheetData );
	const results = searchTraits( view.held, query );

	async function run( key: string, action: () => Promise< void > ) {
		setBusy( key );
		setProblems( ( prev ) => ( { ...prev, [ key ]: '' } ) );
		try {
			await action();
		} catch ( error: unknown ) {
			setProblems( ( prev ) => ( {
				...prev,
				[ key ]: errorMessage(
					error,
					__( 'That could not be saved.', 'beyond-elysium' )
				),
			} ) );
		} finally {
			setBusy( '' );
		}
	}

	function adjust( row: PoolRow, delta: number ) {
		const key = `${ row.blockSlug }.${ row.poolName }`;
		return run( key, async () => {
			const result = await api
				.temporaryState( gameSlug, characterId )
				.adjustPool( row.blockSlug, row.poolName, delta );
			setSheetData( ( prev ) => {
				const block = ( prev?.[ row.blockSlug ] ?? {} ) as Record<
					string,
					unknown
				>;
				return {
					...prev,
					[ row.blockSlug ]: {
						...block,
						[ row.poolName ]: {
							...( block[ row.poolName ] as object ),
							permanent: result.permanent,
							temporary: result.temporary,
						},
					},
				};
			} );
		} );
	}

	function spendPermanent( row: PoolRow, reason: string ) {
		const key = `${ row.blockSlug }.${ row.poolName }`;
		return run( key, async () => {
			const result = await api
				.temporaryState( gameSlug, characterId )
				.spendPermanent( row.blockSlug, row.poolName, reason );
			if ( result.waiting ) {
				setWaiting( ( prev ) => ( {
					...prev,
					[ key ]: ( prev[ key ] ?? 0 ) + 1,
				} ) );
			} else {
				setSheetData( ( prev ) => {
					const block = ( prev?.[ row.blockSlug ] ?? {} ) as Record<
						string,
						unknown
					>;
					return {
						...prev,
						[ row.blockSlug ]: {
							...block,
							[ row.poolName ]: {
								...( block[ row.poolName ] as object ),
								permanent: result.permanent,
								temporary: result.temporary,
							},
						},
					};
				} );
			}
			setSpending( null );
			setSpendReason( '' );
		} );
	}

	function mark( type: WoundType, heal: boolean ) {
		return run( `wound.${ type }`, async () => {
			const calls = api.temporaryState( gameSlug, characterId );
			const result = await ( heal
				? calls.heal( type )
				: calls.wound( type ) );
			setSheetData( ( prev ) => ( {
				...prev,
				_wound_state: result.wounds,
			} ) );
		} );
	}

	const track = view.track;

	return (
		<div className="be-play">
			<div className="be-help-heading">
				<h3>{ __( 'Play', 'beyond-elysium' ) }</h3>
				<HelpButton helpKey="character-sheet" />
			</div>
			{ view.pools.length > 0 && (
				<section className="be-play__section">
					<h3>{ __( 'Pools', 'beyond-elysium' ) }</h3>
					<ul className="be-play__pools">
						{ view.pools.map( ( row ) => {
							const key = `${ row.blockSlug }.${ row.poolName }`;
							return (
								<li className="be-play__pool" key={ key }>
									<div className="be-play__pool-line">
										<span className="be-play__pool-name">
											{ row.label }
										</span>
										<span className="be-play__dots">
											{ row.kind === 'accumulates' ? (
												sprintf(
													/* translators: 1: a pool's temporary points, 2: its rating */
													__(
														'Temporary %1$s · Rating %2$s',
														'beyond-elysium'
													),
													String( row.temporary ),
													String( row.permanent )
												)
											) : (
												<WithDots
													text={ displayTemper(
														shownPoolValue(
															row.kind,
															{
																permanent:
																	row.permanent,
																temporary:
																	row.temporary,
															}
														)
													) }
												/>
											) }
										</span>
									</div>
									<div className="be-play__buttons">
										{ temporaryControls( row ) ===
											'spend' && (
											<>
												<button
													type="button"
													className="be-play__button"
													disabled={
														busy !== '' ||
														! canSpend( row )
													}
													aria-label={ sprintf(
														/* translators: %s: a pool's name, such as Blood */
														__(
															'Spend a point of %s',
															'beyond-elysium'
														),
														row.label
													) }
													onClick={ () =>
														adjust( row, -1 )
													}
												>
													{ __(
														'Spend',
														'beyond-elysium'
													) }
												</button>
												<button
													type="button"
													className="be-play__button"
													disabled={
														busy !== '' ||
														! canRegain( row )
													}
													aria-label={ sprintf(
														/* translators: %s: a pool's name, such as Blood */
														__(
															'Regain a point of %s',
															'beyond-elysium'
														),
														row.label
													) }
													onClick={ () =>
														adjust( row, 1 )
													}
												>
													{ __(
														'Regain',
														'beyond-elysium'
													) }
												</button>
											</>
										) }
										{ temporaryControls( row ) ===
											'build' && (
											<>
												<button
													type="button"
													className="be-play__button"
													disabled={
														busy !== '' ||
														! canRegain( row )
													}
													aria-label={ sprintf(
														/* translators: %s: a pool's name, such as Banality */
														__(
															'Gain a point of %s',
															'beyond-elysium'
														),
														row.label
													) }
													onClick={ () =>
														adjust( row, 1 )
													}
												>
													{ __(
														'Gain',
														'beyond-elysium'
													) }
												</button>
												<button
													type="button"
													className="be-play__button"
													disabled={
														busy !== '' ||
														! canSpend( row )
													}
													aria-label={ sprintf(
														/* translators: %s: a pool's name, such as Banality */
														__(
															'Lose a point of %s',
															'beyond-elysium'
														),
														row.label
													) }
													onClick={ () =>
														adjust( row, -1 )
													}
												>
													{ __(
														'Lose',
														'beyond-elysium'
													) }
												</button>
											</>
										) }
										{ canSpendPermanent( row ) && (
											<button
												type="button"
												className="be-play__button"
												disabled={ busy !== '' }
												aria-label={ sprintf(
													/* translators: %s: a pool's name, such as Willpower */
													__(
														'Spend a permanent point of %s',
														'beyond-elysium'
													),
													row.label
												) }
												onClick={ () =>
													setSpending( row )
												}
											>
												{ __(
													'Spend permanent',
													'beyond-elysium'
												) }
											</button>
										) }
									</div>
									{ waiting[ key ] > 0 && (
										<p className="be-play__note">
											{ sprintf(
												/* translators: %d: how many permanent spends of one pool wait for a Storyteller */
												_n(
													'%d permanent spend waiting',
													'%d permanent spends waiting',
													waiting[ key ],
													'beyond-elysium'
												),
												waiting[ key ]
											) }
										</p>
									) }
									{ problems[ key ] && (
										<p
											className="be-play__problem"
											role="alert"
										>
											{ problems[ key ] }
										</p>
									) }
								</li>
							);
						} ) }
					</ul>
				</section>
			) }

			<section className="be-play__section">
				<h3>{ __( 'Health', 'beyond-elysium' ) }</h3>
				{ view.hasTrack && (
					<>
						<p className="be-play__level">
							{ sprintf(
								/* translators: %s: the health level a character stands at, such as Bruised */
								__( 'Level: %s', 'beyond-elysium' ),
								view.shownName( view.healthSlug, track.reached )
							) }
						</p>
						<ul className="be-play__track">
							{ track.boxes.map( ( box, index ) => (
								<li
									key={ index }
									className={ `be-play__box${
										box.damage
											? ` be-play__box--${ box.damage }`
											: ''
									}` }
								>
									<span aria-hidden="true">
										{ box.damage
											? DAMAGE_MARKS[ box.damage ]
											: '' }
									</span>
									<span className="screen-reader-text">
										{ sprintf(
											/* translators: 1: a health level's name, 2: the damage in its box, or Undamaged */
											__(
												'%1$s: %2$s',
												'beyond-elysium'
											),
											view.shownName(
												view.healthSlug,
												box.level
											),
											box.damage
												? woundLabel( box.damage )
												: __(
														'Undamaged',
														'beyond-elysium'
													)
										) }
									</span>
								</li>
							) ) }
						</ul>
						{ track.beyond > 0 && (
							<p className="be-play__beyond">
								{ sprintf(
									/* translators: %d: levels of damage past the last box of the health track */
									__(
										'+%d beyond the track',
										'beyond-elysium'
									),
									track.beyond
								) }
							</p>
						) }
					</>
				) }
				<ul className="be-play__wounds">
					{ WOUND_TYPES.map( ( type ) => (
						<li className="be-play__wound" key={ type }>
							<span className="be-play__pool-name">
								{ woundLabel( type ) }
							</span>
							<span className="be-play__count">
								{ wounds[ type ] }
							</span>
							<div className="be-play__buttons">
								<button
									type="button"
									className="be-play__button"
									disabled={ busy !== '' }
									aria-label={ sprintf(
										/* translators: %s: a kind of damage, such as Lethal */
										__(
											'Take a level of %s damage',
											'beyond-elysium'
										),
										woundLabel( type )
									) }
									onClick={ () => mark( type, false ) }
								>
									{ __( 'Take', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									className="be-play__button"
									disabled={
										busy !== '' || wounds[ type ] <= 0
									}
									aria-label={ sprintf(
										/* translators: %s: a kind of damage, such as Lethal */
										__(
											'Heal a level of %s damage',
											'beyond-elysium'
										),
										woundLabel( type )
									) }
									onClick={ () => mark( type, true ) }
								>
									{ __( 'Heal', 'beyond-elysium' ) }
								</button>
							</div>
							{ problems[ `wound.${ type }` ] && (
								<p className="be-play__problem" role="alert">
									{ problems[ `wound.${ type }` ] }
								</p>
							) }
						</li>
					) ) }
				</ul>
			</section>

			{ ( losses.lostTraits.length > 0 ||
				losses.expended.length > 0 ) && (
				<section className="be-play__section">
					<h3>{ __( 'Tonight', 'beyond-elysium' ) }</h3>
					<ul className="be-play__tonight">
						{ losses.lostTraits.map( ( name, index ) => (
							<li key={ `lost-${ index }` }>
								{ sprintf(
									/* translators: %s: the name of a Trait lost tonight */
									__( 'Lost: %s', 'beyond-elysium' ),
									name
								) }
							</li>
						) ) }
						{ losses.expended.map( ( entry, index ) => (
							<li key={ `expended-${ index }` }>
								{ sprintf(
									/* translators: 1: an Ability's name, 2: how many of its levels were expended tonight */
									__(
										'Expended: %1$s x%2$d',
										'beyond-elysium'
									),
									entry.name,
									entry.levels
								) }
							</li>
						) ) }
					</ul>
					<p className="be-play__note">
						{ __(
							'Cleared when the Storyteller closes the night.',
							'beyond-elysium'
						) }
					</p>
				</section>
			) }

			{ spending && (
				<Modal
					title={ sprintf(
						/* translators: %s: a pool's name, such as Willpower */
						__( 'Spend a permanent point of %s', 'beyond-elysium' ),
						spending.label
					) }
					onClose={ () => {
						setSpending( null );
						setSpendReason( '' );
					} }
					footer={
						<>
							<button
								type="button"
								onClick={ () => {
									setSpending( null );
									setSpendReason( '' );
								} }
							>
								{ __( 'Cancel', 'beyond-elysium' ) }
							</button>
							<button
								type="button"
								disabled={ busy !== '' }
								onClick={ () =>
									spendPermanent( spending, spendReason )
								}
							>
								{ __( 'Spend', 'beyond-elysium' ) }
							</button>
						</>
					}
				>
					<p>
						{ sprintf(
							/* translators: 1: a pool's name, such as Willpower, 2: its permanent rating now, 3: its rating after the spend */
							__(
								'Spend 1 permanent %1$s? Your rating goes from %2$d to %3$d, and raising it again costs XP.',
								'beyond-elysium'
							),
							spending.label,
							spending.permanent,
							spending.permanent - 1
						) }
					</p>
					<label className="be-play__search">
						<span>{ __( 'What for?', 'beyond-elysium' ) }</span>
						<input
							type="text"
							value={ spendReason }
							onChange={ ( e ) =>
								setSpendReason( e.target.value )
							}
						/>
					</label>
					{ problems[
						`${ spending.blockSlug }.${ spending.poolName }`
					] && (
						<p className="be-play__problem" role="alert">
							{
								problems[
									`${ spending.blockSlug }.${ spending.poolName }`
								]
							}
						</p>
					) }
				</Modal>
			) }

			<section className="be-play__section">
				<label className="be-play__search">
					<span>{ __( 'Find a trait', 'beyond-elysium' ) }</span>
					<input
						type="search"
						value={ query }
						onChange={ ( e ) => setQuery( e.target.value ) }
					/>
				</label>
				{ query.trim() !== '' && (
					<ul className="be-play__results">
						{ results.length === 0 && (
							<li>
								{ __( 'Nothing found.', 'beyond-elysium' ) }
							</li>
						) }
						{ results.map( ( trait ) => (
							<li
								key={ `${ trait.blockSlug }.${ trait.name }.${ trait.powerName ?? '' }` }
							>
								<button
									type="button"
									className="be-play__result"
									onClick={ () => setOpenTrait( trait ) }
								>
									{ trait.shown }
								</button>{ ' ' }
								<span className="be-play__result-section">
									{ trait.section }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</section>
			{ openTrait && state.status === 'ready' && (
				<TraitPanel
					gameSlug={ gameSlug }
					characterId={ characterId }
					trait={ openTrait }
					compendiumHref={ compendiumHref(
						gameSlug,
						state.stack.stack.slug,
						openTrait.blockSlug,
						openTrait.name,
						characterId
					) }
					block={ state.stack.blocks[ openTrait.blockSlug ] }
					sheetData={ sheetData }
					label={ traitLabel(
						state.stack.blocks[ openTrait.blockSlug ],
						openTrait.name
					) }
					canEdit={ canEdit }
					canManage={ canManage }
					onClose={ () => setOpenTrait( null ) }
					onView={
						onView
							? ( target ) => {
									setOpenTrait( null );
									onView( target );
								}
							: undefined
					}
				/>
			) }
		</div>
	);
}

export default PlayView;
