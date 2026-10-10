/**
 * The creation tally: what a build's steps cover, each pool's balance, every limit flag, and what is left for XP.
 * Live beside the creation form as the draft sheet changes, or read from a pending character's own saved sheet for a
 * Storyteller reviewing it.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { unbuyableLine } from '../../lib/raisedByPool';
import type {
	CreationTally as CreationTallyReport,
	CreationTallyLimitFlag,
	CreationTallyStep,
} from '../../types/character';
import './CreationTallyPanel.css';

export interface CreationTallyPanelProps {
	gameSlug: string;
	/**
	 * A pending character's own saved sheet, for a Storyteller reviewing it.
	 */
	characterId?: number;
	/**
	 * A draft build in progress, not yet saved.
	 */
	stackSlug?: string;
	sheetData?: Record< string, unknown >;
	/**
	 * Shows a player what the build holds that only play can raise; a Storyteller sets any rating, so never shown to one.
	 */
	warnsPlayer?: boolean;
}

const LIMIT_REASON_LABEL: Record< string, string > = {
	max_points: __( 'over its point limit', 'beyond-elysium' ),
	max_rating: __( 'above its maximum rating', 'beyond-elysium' ),
	min_rating: __( 'below its minimum rating', 'beyond-elysium' ),
	ceiling: __( 'above what it may not exceed', 'beyond-elysium' ),
};

function limitFlagText( flag: CreationTallyLimitFlag ): string {
	const reason = LIMIT_REASON_LABEL[ flag.reason ] ?? flag.reason;
	if ( flag.reason === 'max_rating' || flag.reason === 'max_points' ) {
		return sprintf(
			/* translators: 1: what is flagged, 2: why, 3: its value, 4: the limit it broke */
			__( '%1$s is %2$s (%3$s, limit %4$s).', 'beyond-elysium' ),
			flag.target,
			reason,
			String( flag.value ),
			String( flag.max ?? '' )
		);
	}
	if ( flag.reason === 'min_rating' ) {
		return sprintf(
			/* translators: 1: what is flagged, 2: why, 3: its value, 4: the minimum it fell below */
			__( '%1$s is %2$s (%3$s, minimum %4$s).', 'beyond-elysium' ),
			flag.target,
			reason,
			String( flag.value ),
			String( flag.min ?? '' )
		);
	}
	return sprintf(
		/* translators: 1: what is flagged, 2: why, 3: its value, 4: the entry it may not exceed */
		__( '%1$s is %2$s (%3$s, above %4$s).', 'beyond-elysium' ),
		flag.target,
		reason,
		String( flag.value ),
		String( flag.ceiling ?? '' )
	);
}

/**
 * Renders one step's own line, in the book's order.
 */
function StepLine( { step }: { step: CreationTallyStep } ) {
	if ( ! step.applies ) {
		return null;
	}

	if ( step.kind === 'prioritized' ) {
		return (
			<li className="be-creation-tally__step">
				<span className="be-creation-tally__step-label">
					{ step.label }
				</span>
				<ul className="be-creation-tally__sub">
					{ step.sections.map( ( section ) => (
						<li
							key={ section.section }
							className={
								section.over
									? 'be-creation-tally__sub-line be-creation-tally__sub-line--over'
									: 'be-creation-tally__sub-line'
							}
						>
							{ sprintf(
								/* translators: 1: section name, 2: dots used, 3: dots allowed */
								__( '%1$s: %2$d of %3$d', 'beyond-elysium' ),
								section.section,
								section.used,
								section.allowed
							) }
						</li>
					) ) }
				</ul>
			</li>
		);
	}

	if ( step.kind === 'budget' ) {
		return (
			<li className="be-creation-tally__step">
				<span
					className={
						step.over
							? 'be-creation-tally__step-label be-creation-tally__step-label--over'
							: 'be-creation-tally__step-label'
					}
				>
					{ sprintf(
						/* translators: 1: step label, 2: units used, 3: units allowed */
						__( '%1$s: %2$d of %3$d', 'beyond-elysium' ),
						step.label,
						step.used,
						step.allowed
					) }
				</span>
				{ step.quotas.length > 0 && (
					<ul className="be-creation-tally__sub">
						{ step.quotas.map( ( quota, i ) => (
							<li
								key={ `${ quota.label }-${ i }` }
								className={
									quota.ok
										? 'be-creation-tally__sub-line'
										: 'be-creation-tally__sub-line be-creation-tally__sub-line--over'
								}
							>
								{ sprintf(
									/* translators: 1: quota label, 2: how many met it, 3: how many it needs */
									__(
										'%1$s: %2$d of %3$d',
										'beyond-elysium'
									),
									quota.label,
									quota.met,
									quota.min
								) }
							</li>
						) ) }
					</ul>
				) }
			</li>
		);
	}

	if ( step.kind === 'earned' ) {
		return (
			<li className="be-creation-tally__step">
				{ sprintf(
					/* translators: 1: step label, 2: points added, 3: pool name */
					__( '%1$s: +%2$d to %3$s', 'beyond-elysium' ),
					step.label,
					step.points,
					step.pool
				) }
			</li>
		);
	}

	if ( step.kind === 'free' ) {
		return (
			<li className="be-creation-tally__step">
				{ sprintf(
					/* translators: 1: step label, 2: points spent, 3: pool name */
					__( '%1$s: %2$d spent from %3$s', 'beyond-elysium' ),
					step.label,
					step.spent,
					step.pool
				) }
			</li>
		);
	}

	if ( step.kind === 'start' ) {
		if ( step.value === null ) {
			return null;
		}
		return (
			<li className="be-creation-tally__step">
				{ sprintf(
					/* translators: 1: step label, 2: what it set, 3: its starting value */
					__( '%1$s: %2$s starts at %3$s', 'beyond-elysium' ),
					step.label,
					step.target,
					String( step.value )
				) }
			</li>
		);
	}

	if ( step.kind === 'grant' ) {
		if ( step.missing.length === 0 ) {
			return null;
		}
		return (
			<li className="be-creation-tally__step be-creation-tally__step--over">
				{ sprintf(
					/* translators: 1: step label, 2: how many entries the sheet doesn't hold yet */
					__( '%1$s: %2$d not on the sheet yet', 'beyond-elysium' ),
					step.label,
					step.missing.length
				) }
				<ul className="be-creation-tally__sub">
					{ step.missing.map( ( entry, i ) => (
						<li
							key={ `${ entry.name }-${ i }` }
							className="be-creation-tally__sub-line be-creation-tally__sub-line--over"
						>
							{ entry.name }
						</li>
					) ) }
				</ul>
			</li>
		);
	}

	if ( step.kind === 'limit' ) {
		if ( step.flags.length === 0 ) {
			return null;
		}
		return (
			<li className="be-creation-tally__step be-creation-tally__step--over">
				<span className="be-creation-tally__step-label">
					{ step.label }
				</span>
				<ul className="be-creation-tally__sub">
					{ step.flags.map( ( flag, i ) => (
						<li
							key={ `${ flag.target }-${ i }` }
							className="be-creation-tally__sub-line be-creation-tally__sub-line--over"
						>
							{ limitFlagText( flag ) }
						</li>
					) ) }
				</ul>
			</li>
		);
	}

	return null;
}

/**
 * Renders the creation tally, fetched from a saved character or posted live from a draft build.
 */
export function CreationTallyPanel( {
	gameSlug,
	characterId,
	stackSlug,
	sheetData,
	warnsPlayer,
}: CreationTallyPanelProps ) {
	const [ report, setReport ] = useState< CreationTallyReport | null >(
		null
	);
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const debounceRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const sheetDataKey = JSON.stringify( sheetData ?? {} );

	useEffect( () => {
		if ( characterId ) {
			setLoading( true );
			setError( null );
			api.characters( gameSlug )
				.creationTally( characterId )
				.then( ( result ) => {
					setReport( result );
					setLoading( false );
				} )
				.catch( () => {
					setError(
						__(
							'Failed to load the creation tally.',
							'beyond-elysium'
						)
					);
					setLoading( false );
				} );
			return;
		}

		if ( ! stackSlug ) {
			setLoading( false );
			return;
		}

		setLoading( true );
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			setError( null );
			api.creationTally( gameSlug )
				.draft( stackSlug, sheetData ?? {} )
				.then( ( result ) => {
					setReport( result );
					setLoading( false );
				} )
				.catch( () => {
					setError(
						__(
							'Failed to load the creation tally.',
							'beyond-elysium'
						)
					);
					setLoading( false );
				} );
		}, 500 );

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug, characterId, stackSlug, sheetDataKey ] );

	if ( loading && ! report ) {
		return (
			<p className="be-creation-tally__status">
				{ __( 'Loading…', 'beyond-elysium' ) }
			</p>
		);
	}
	if ( error || ! report ) {
		return (
			<p className="be-creation-tally__status">
				{ error ?? __( 'No tally available.', 'beyond-elysium' ) }
			</p>
		);
	}

	return (
		<div className="be-creation-tally">
			<div className="be-creation-tally__xp">
				<span>
					{ __( 'Starting XP:', 'beyond-elysium' ) }{ ' ' }
					<strong>{ report.xp.starting }</strong>
				</span>
				<span>
					{ __( 'Build cost:', 'beyond-elysium' ) }{ ' ' }
					<strong>{ report.xp.needed }</strong>
				</span>
				<span
					className={
						report.xp.left < 0
							? 'be-creation-tally__xp-left be-creation-tally__xp-left--over'
							: 'be-creation-tally__xp-left'
					}
				>
					{ __( 'Left:', 'beyond-elysium' ) }{ ' ' }
					<strong>{ report.xp.left }</strong>
				</span>
			</div>

			{ Object.keys( report.pools ).length > 0 && (
				<ul className="be-creation-tally__pools">
					{ Object.entries( report.pools ).map(
						( [ name, pool ] ) => (
							<li key={ name }>
								{ sprintf(
									/* translators: 1: pool name, 2: points left, 3: points the pool holds in total */
									__(
										'%1$s: %2$d of %3$d left',
										'beyond-elysium'
									),
									name,
									pool.left,
									pool.own + pool.earned
								) }
							</li>
						)
					) }
				</ul>
			) }

			<ul className="be-creation-tally__steps">
				{ report.steps.map( ( step, i ) => (
					<StepLine key={ `${ step.kind }-${ i }` } step={ step } />
				) ) }
			</ul>

			{ warnsPlayer && ( report.unbuyable ?? [] ).length > 0 && (
				<ul className="be-creation-tally__unbuyable" role="alert">
					{ report.unbuyable.map( ( item ) => (
						<li
							key={ `${ item.kind }-${ item.section }-${ item.pool }` }
						>
							{ unbuyableLine( item ) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

export default CreationTallyPanel;
