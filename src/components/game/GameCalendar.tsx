/**
 * Front-end display of the "Game Calendar" report.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { gameNightHeading } from '../../lib/gameCalendar';
import type { CalendarRow } from '../../lib/gameCalendar';
import { highlightStMarkers } from '../../lib/highlightStMarkers';
import './GameCalendar.css';

interface CalendarDocument {
	title: string;
	shape: 'calendar';
	rows: CalendarRow[];
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

	if ( data.rows.length === 0 ) {
		return <p className="be-game-calendar__empty">{ data.note }</p>;
	}

	return (
		<ul className="be-game-calendar__list">
			{ data.rows.map( ( row ) => (
				<li
					key={ `${ row.date }|${ row.time ?? '' }|${ row.place ?? '' }` }
					className="be-game-calendar__item"
				>
					<p className="be-game-calendar__when">
						{ gameNightHeading( row ) }
					</p>
					{ row.place && (
						<p className="be-game-calendar__place">{ row.place }</p>
					) }
					{ row.notes && (
						<div
							className="be-game-calendar__notes"
							dangerouslySetInnerHTML={ {
								__html: highlightStMarkers( row.notes ),
							} }
						/>
					) }
				</li>
			) ) }
		</ul>
	);
}

export default GameCalendar;
