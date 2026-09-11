/**
 * TieredPowerEditor renders the held-power list for a tiered_power block - leveled
 * catalogs such as Disciplines, Arcanoi, or Gifts. Lets a player add a power, raise
 * or lower its level with a stepper, set its tradition, or mark it removed. Accepts
 * a catalog entry or, when the block allows it, a free-text custom power name.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import SearchableSelect from '../shared/SearchableSelect';
import type { HeldPower } from '../renderers/TieredPowerRenderer';
import type { PowerLevel, TieredPower, TieredPowerDefinition } from '../../types';
import './TieredPowerEditor.css';

/** Marked for removal rather than deleted outright; removal is applied when changes are submitted. */
export interface EditableHeldPower extends HeldPower {
	_removed?: boolean;
	/** Set when this row was entered as free text rather than chosen from the catalog. */
	custom?: boolean;
}

export interface TieredPowerEditorProps {
	blockSlug: string;
	data: EditableHeldPower[];
	definition: TieredPowerDefinition;
	onChange: ( blockSlug: string, nextData: EditableHeldPower[] ) => void;
	/** Returns the XP cost for a held power at its current level; omitted callers show no cost. */
	costFor?: ( power: EditableHeldPower ) => number | null;
	/** Overrides the stepper's maximum level for a named power; undefined falls back to the default. */
	trueMaxFor?: ( name: string ) => number | undefined;
	readOnly?: boolean;
}

/** The stepper's default maximum level when no per-power override applies. */
const DEFAULT_TRUE_MAX = 5;

/**
 * Returns the highest level a named power can be raised to: an explicit trueMaxFor
 * override when given, otherwise DEFAULT_TRUE_MAX clamped to the number of real
 * numbered levels the power's definition actually has. Levels with a null `level`
 * (Elder-and-above entries) are excluded from this count.
 */
export function maxLevel( definition: TieredPowerDefinition, name: string, trueMaxFor?: ( name: string ) => number | undefined ): number {
	const override = trueMaxFor?.( name );
	if ( override != null ) {
		return override;
	}

	const power = definition.powers.find( ( p ) => p.name === name );
	const numbered = ( power?.levels ?? [] ).filter( ( l ): l is typeof l & { level: number } => l.level != null );
	if ( numbered.length === 0 ) {
		// Guards against an empty ladder; falls back to the default max instead of pinning at 1.
		return DEFAULT_TRUE_MAX;
	}
	return Math.min( DEFAULT_TRUE_MAX, Math.max( ...numbered.map( ( l ) => l.level ) ) );
}

const CUSTOM_LEVEL_NAMES = [ 'One', 'Two', 'Three', 'Four', 'Five' ];
/** Tier for each of the five custom levels: 1-2 basic, 3-4 intermediate, 5 advanced. */
const CUSTOM_LEVEL_TIERS: PowerLevel[ 'tier' ][] = [ 'basic', 'basic', 'intermediate', 'intermediate', 'advanced' ];

/**
 * Returns a copy of the block definition with a synthetic five-level ladder added
 * for every custom (free-text) power held in data, using placeholder names "One"
 * through "Five". Computed fresh from the held rows on each call rather than stored,
 * so maxLevel() can look up a real ladder for a custom power the same way it does
 * for any catalog power.
 */
export function withCustomLadders( definition: TieredPowerDefinition, data: EditableHeldPower[] ): TieredPowerDefinition {
	const customNames = new Set( data.filter( ( row ) => row.custom ).map( ( row ) => row.name ) );
	if ( customNames.size === 0 ) {
		return definition;
	}
	const synthetic: TieredPower[] = Array.from( customNames ).map( ( name ) => ( {
		name,
		levels: CUSTOM_LEVEL_NAMES.map(
			( power_name, i ): PowerLevel => ( { level: i + 1, tier: CUSTOM_LEVEL_TIERS[ i ], power_name } )
		),
	} ) );
	return { ...definition, powers: [ ...definition.powers, ...synthetic ] };
}

/**
 * Renders the held-power list for a tiered_power block: add a power, raise or lower
 * its level with a stepper, set its tradition, or mark it removed. A sequential block
 * treats level n as holding every level from 1 to n, so each power gets a single
 * stepper rather than a row of individual checkboxes.
 */
export function TieredPowerEditor( { blockSlug, data, definition, onChange, costFor, trueMaxFor, readOnly }: TieredPowerEditorProps ) {
	const emit = ( next: EditableHeldPower[] ) => onChange( blockSlug, next );

	// Memoized: large power catalogs make this expensive to recompute on every keystroke.
	const heldNames = useMemo(
		() => new Set( data.filter( ( row ) => ! row._removed ).map( ( row ) => row.name ) ),
		[ data ]
	);
	const available = useMemo(
		() => definition.powers.map( ( p ) => p.name ).filter( ( name ) => ! heldNames.has( name ) ),
		[ definition.powers, heldNames ]
	);
	// Used only for level-capping below; suggestions still use the real catalog, not placeholder names.
	const definitionWithCustomLadders = useMemo( () => withCustomLadders( definition, data ), [ definition, data ] );

	const addPower = ( name: string, isCustom: boolean ) => {
		const trimmed = name.trim();
		if ( ! trimmed || heldNames.has( trimmed ) ) {
			return;
		}
		emit( [ ...data, isCustom ? { name: trimmed, level: 1, custom: true } : { name: trimmed, level: 1 } ] );
	};

	const setLevel = ( index: number, level: number ) => {
		const clamped = Math.max( 1, Math.min( level, maxLevel( definitionWithCustomLadders, data[ index ].name, trueMaxFor ) ) );
		const next = [ ...data ];
		next[ index ] = { ...next[ index ], level: clamped };
		emit( next );
	};

	const toggleRemoved = ( index: number ) => {
		const next = [ ...data ];
		next[ index ] = { ...next[ index ], _removed: ! next[ index ]._removed };
		emit( next );
	};

	const setTradition = ( index: number, tradition: string ) => {
		const next = [ ...data ];
		const trimmed = tradition.trim();
		if ( trimmed === '' ) {
			const { tradition: _drop, ...rest } = next[ index ];
			next[ index ] = rest;
		} else {
			next[ index ] = { ...next[ index ], tradition: trimmed };
		}
		emit( next );
	};

	// Tradition suggestions are the distinct name prefixes before ": " in the power catalog.
	const traditionSuggestions = Array.from(
		new Set(
			( definition.powers ?? [] )
				.map( ( power ) => power.name )
				.filter( ( name ) => name.includes( ': ' ) )
				.map( ( name ) => name.slice( 0, name.indexOf( ': ' ) ) )
		)
	).sort();
	const traditionListId = `be-tradition-${ blockSlug }`;

	return (
		<div className="be-tiered-power-editor" data-block-slug={ blockSlug }>
			<datalist id={ traditionListId }>
				{ traditionSuggestions.map( ( t ) => (
					<option key={ t } value={ t } />
				) ) }
			</datalist>

			<ul className="be-tiered-power-editor__rows">
				{ data.map( ( row, index ) => {
					const cost = costFor?.( row ) ?? null;
					// Rows created here always have a numeric level; this default is only type narrowing.
					const level = row.level ?? 1;
					return (
						<li
							key={ `${ row.name }-${ index }` }
							className={
								'be-tiered-power-editor__row' +
								( row._removed ? ' be-tiered-power-editor__row--removed' : '' )
							}
						>
							<span className="be-tiered-power-editor__name">{ row.name }</span>

							<div className="be-tiered-power-editor__stepper">
								<button
									type="button"
									className="be-tiered-power-editor__stepper-button"
									disabled={ readOnly || row._removed || level <= 1 }
									onClick={ () => setLevel( index, level - 1 ) }
									aria-label={ sprintf( __( 'Decrease %s', 'beyond-elysium' ), row.name ) }
								>
									{ __( '−', 'beyond-elysium' ) }
								</button>
								<span className="be-tiered-power-editor__level">{ level }</span>
								<button
									type="button"
									className="be-tiered-power-editor__stepper-button"
									disabled={ readOnly || row._removed || level >= maxLevel( definitionWithCustomLadders, row.name, trueMaxFor ) }
									onClick={ () => setLevel( index, level + 1 ) }
									aria-label={ sprintf( __( 'Increase %s', 'beyond-elysium' ), row.name ) }
								>
									{ __( '+', 'beyond-elysium' ) }
								</button>
							</div>

							{ ! readOnly && (
								<input
									type="text"
									className="be-tiered-power-editor__tradition"
									list={ traditionListId }
									value={ row.tradition ?? '' }
									placeholder={ __( 'Tradition', 'beyond-elysium' ) }
									aria-label={ sprintf( __( 'Tradition for %s', 'beyond-elysium' ), row.name ) }
									disabled={ row._removed }
									onChange={ ( e ) => setTradition( index, e.target.value ) }
								/>
							) }
							{ readOnly && row.tradition && (
								<span className="be-tiered-power-editor__tradition-text">{ row.tradition }</span>
							) }

							{ cost !== null && (
								<span className="be-tiered-power-editor__cost">
									{ sprintf(
										/* translators: %1$d: XP cost */
										__( '%1$d XP', 'beyond-elysium' ),
										cost
									) }
								</span>
							) }

							{ ! readOnly && (
								<button
									type="button"
									className="be-tiered-power-editor__remove"
									onClick={ () => toggleRemoved( index ) }
									aria-label={
										row._removed
											? sprintf( __( 'Undo removing %s', 'beyond-elysium' ), row.name )
											: sprintf( __( 'Remove %s', 'beyond-elysium' ), row.name )
									}
								>
									{ row._removed ? __( 'Undo', 'beyond-elysium' ) : __( 'Remove', 'beyond-elysium' ) }
								</button>
							) }
						</li>
					);
				} ) }
			</ul>

			{ ! readOnly && (
				<div className="be-tiered-power-editor__add">
					<SearchableSelect
						options={ available }
						value=""
						placeholder={ __( 'Add power…', 'beyond-elysium' ) }
						ariaLabel={ __( 'Add power', 'beyond-elysium' ) }
						allowCustom={ definition.allow_custom ?? false }
						onChange={ addPower }
					/>
				</div>
			) }
		</div>
	);
}

export default TieredPowerEditor;
