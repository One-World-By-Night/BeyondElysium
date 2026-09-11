/**
 * Read-only audit trail of every change submitted for one character, approved and
 * rejected alike. Renders each change as a human-readable description with its
 * status, XP cost, submission/review metadata, and any reviewer note.
 * Newest submission first.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { describeChange } from '../../lib/describeChange';
import type { CharacterChange } from '../../types/character';
import './ChangeHistory.css';

export interface ChangeHistoryProps {
	characterId: number;
	gameSlug: string;
}

const STATUS_LABEL: Record<CharacterChange[ 'status' ], string> = {
	pending: __( 'Pending', 'beyond-elysium' ),
	approved: __( 'Approved', 'beyond-elysium' ),
	rejected: __( 'Rejected', 'beyond-elysium' ),
};

/**
 * Renders the full change history for one character as a list, each entry showing its
 * status, a human-readable description of the change, its XP cost, who submitted and
 * reviewed it and when, and any reviewer note.
 */
export function ChangeHistory( { characterId, gameSlug }: ChangeHistoryProps ) {
	const [ items, setItems ] = useState<CharacterChange[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		setLoading( true );
		setError( null );
		api
			.changes( gameSlug )
			.list( characterId, { per_page: 100, order: 'DESC' } )
			.then( ( result ) => {
				setItems( result );
				setLoading( false );
			} )
			.catch( () => {
				// Sets an explicit error rather than leaving items empty, so a fetch failure isn't shown as an empty history.
				setError( __( 'Failed to load change history.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}, [ characterId, gameSlug ] );

	if ( loading ) {
		return <p>{ __( 'Loading history…', 'beyond-elysium' ) }</p>;
	}

	if ( error ) {
		return (
			<p className="be-change-history__error" role="alert">
				{ error }
			</p>
		);
	}

	if ( items.length === 0 ) {
		return <p>{ __( 'No changes yet.', 'beyond-elysium' ) }</p>;
	}

	return (
		<ul className="be-change-history">
			{ items.map( ( item ) => (
				<li key={ item.id } className={ `be-change-history__row be-change-history__row--${ item.status }` }>
					<span className="be-change-history__status">{ STATUS_LABEL[ item.status ] }</span>
					<span className="be-change-history__description">{ describeChange( item.change_type, item.change_data ) }</span>
					{ item.xp_cost !== 0 && (
						<span className="be-change-history__cost">
							{ item.xp_cost >= 0 ? '+' : '' }
							{ sprintf( __( '%d XP', 'beyond-elysium' ), item.xp_cost ) }
						</span>
					) }
					<span className="be-change-history__meta">
						{ sprintf( __( 'submitted %s', 'beyond-elysium' ), item.submitted_at ) }
						{ item.reviewed_at && sprintf( __( ' · reviewed %s', 'beyond-elysium' ), item.reviewed_at ) }
					</span>
					{ item.notes && <p className="be-change-history__notes">{ item.notes }</p> }
				</li>
			) ) }
		</ul>
	);
}

export default ChangeHistory;
