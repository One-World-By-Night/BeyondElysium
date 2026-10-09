/**
 * Front-end display of a card-shaped report (Location Cards, Rote Cards - the same `Report_Document`/`Report_Writer`
 * shape the signed PDF and the admin Reports page both already use).
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import './ReportCards.css';

interface CardField {
	label: string;
	value: string;
	html: boolean;
}

interface ReportCard {
	name: string;
	picture: number | null;
	fields: CardField[];
	uses_max: number | null;
	uses_used: number;
	verify: string | null;
}

interface CardsDocument {
	title: string;
	shape: 'card';
	cards: ReportCard[];
	game: string;
}

export interface ReportCardsProps {
	gameSlug: string;
	/**
	 * A report-registry key whose shape is 'card'.
	 */
	reportKey: string;
	/**
	 * Scopes the report to one character.
	 */
	characterId?: number;
}

/**
 * Renders every card's fields as a label/value list.
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
				// A report card has no id of its own - its name is not unique across a report.
				<div className="be-report-cards__card" key={ i }>
					<h3 className="be-report-cards__name">{ card.name }</h3>
					{ card.picture !== null && (
						<img
							className="be-report-cards__picture"
							src={ api
								.attachments( gameSlug )
								.downloadUrl( card.picture ) }
							alt=""
						/>
					) }
					<dl>
						{ card.fields.map( ( field ) => (
							<div
								className="be-report-cards__field"
								key={ field.label }
							>
								<dt>{ field.label }</dt>
								{ field.html ? (
									<dd
										dangerouslySetInnerHTML={ {
											__html: field.value,
										} }
									/>
								) : (
									<dd>{ field.value }</dd>
								) }
							</div>
						) ) }
					</dl>
					{ card.uses_max !== null && (
						<p className="be-report-cards__uses">
							{ sprintf(
								/* translators: %1$d: total uses, %2$d: uses remaining */
								__(
									'Count: %1$d (%2$d left)',
									'beyond-elysium'
								),
								card.uses_max,
								card.uses_max - card.uses_used
							) }
						</p>
					) }
					{ card.verify && (
						<p className="be-report-cards__verify">
							{ card.verify }
						</p>
					) }
				</div>
			) ) }
		</div>
	);
}

export default ReportCards;
