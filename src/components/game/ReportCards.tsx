/**
 * Front-end display of a card-shaped report (Location Cards, Rote Cards - the same
 * `Report_Document`/`Report_Writer` shape the signed PDF and the admin Reports page both
 * already use). Fetches the plain JSON form (`api.reports(gameSlug).document(reportKey)`)
 * and renders one card per row, exactly the columns the report registry declares.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import './ReportCards.css';

/** One [label, value] pair; `Report_Document::resolve_one()` always resolves to a plain string. */
type CardField = [ string, string ];

interface CardsDocument {
	title: string;
	shape: 'card';
	cards: CardField[][];
	game: string;
}

export interface ReportCardsProps {
	gameSlug: string;
	/** A report-registry key whose shape is 'card' - 'location-cards' or 'rote-cards' here. */
	reportKey: string;
	/** Scopes the report to one character (1.1.0 §3.15) - required for Rote Cards when the viewer isn't a manager. */
	characterId?: number;
}

/**
 * Renders every card's fields as a label/value list. A field's value is rendered as HTML,
 * not escaped plain text: a `text`-typed property (Appearance, Security, Description, ...)
 * is real `wp_kses_post()`-sanitized rich text by the time it reaches here, the same trust
 * boundary `WorldObjectCard.tsx`/`HouseRules.tsx` already rely on for the same data: a plain
 * `string`/`int` field carries no markup to begin with, so rendering it as HTML is a no-op.
 */
export function ReportCards( {
	gameSlug,
	reportKey,
	characterId,
}: ReportCardsProps ) {
	const [ data, setData ] = useState< CardsDocument | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setLoading( true );
		setError( null );
		api.reports( gameSlug )
			.document( reportKey, { characterId } )
			.then( ( result ) => {
				setData( result as unknown as CardsDocument );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load this report.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}, [ gameSlug, reportKey, characterId ] );

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( error || ! data ) {
		return (
			<div className="be-report-cards__error" role="alert">
				{ error ??
					__( 'Could not load this report.', 'beyond-elysium' ) }
			</div>
		);
	}
	if ( data.cards.length === 0 ) {
		return (
			<p className="be-report-cards__empty">
				{ __( 'Nothing to show yet.', 'beyond-elysium' ) }
			</p>
		);
	}

	return (
		<div className="be-report-cards">
			{ data.cards.map( ( card, i ) => (
				// A report card has no id of its own - resolve_one() names the row, not a key.
				// eslint-disable-next-line react/no-array-index-key
				<dl className="be-report-cards__card" key={ i }>
					{ card.map( ( [ label, value ] ) => (
						<div className="be-report-cards__field" key={ label }>
							<dt>{ label }</dt>
							{ /* eslint-disable-next-line react/no-danger */ }
							<dd dangerouslySetInnerHTML={ { __html: value } } />
						</div>
					) ) }
				</dl>
			) ) }
		</div>
	);
}

export default ReportCards;
