/**
 * Front-end display of the "Game Calendar" report.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';

interface CalendarDocument {
	title: string;
	shape: 'calendar';
	rows: Record< string, unknown >[];
	note: string;
	game: string;
}

export interface GameCalendarProps {
	gameSlug: string;
}

export function GameCalendar( { gameSlug }: GameCalendarProps ) {
	const [ data, setData ] = useState< CalendarDocument | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setLoading( true );
		setError( null );
		api.reports( gameSlug )
			.document( 'game-calendar' )
			.then( ( result ) => {
				setData( result as unknown as CalendarDocument );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load the calendar.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}, [ gameSlug ] );

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( error || ! data ) {
		return (
			<div className="be-game-calendar__error" role="alert">
				{ error ??
					__( 'Could not load the calendar.', 'beyond-elysium' ) }
			</div>
		);
	}

	// No real per-date schedule exists yet on any chronicle - rows is always empty.
	if ( data.rows.length === 0 ) {
		return <p className="be-game-calendar__empty">{ data.note }</p>;
	}

	return null;
}

export default GameCalendar;
