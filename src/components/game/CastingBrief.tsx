/**
 * The read-only NPC casting brief: what a cast chronicle member reads about the NPC they're playing tonight.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import type {
	CastingBriefDocument,
	SheetDocumentRow,
} from '../../types/npcCasting';
import { sheetRow } from '../../lib/sheetDocument';
import './CastingBrief.css';

export interface CastingBriefProps {
	gameSlug: string;
	castingId: number;
	onClose: () => void;
}

/**
 * One row of the brief, indented when the document indents it.
 */
function BriefRow( { row }: { row: SheetDocumentRow } ) {
	const { text, indent } = sheetRow( row );
	return (
		<li
			className={
				indent > 0 ? 'be-casting-brief__row--indented' : undefined
			}
		>
			{ text }
		</li>
	);
}

export function CastingBrief( {
	gameSlug,
	castingId,
	onClose,
}: CastingBriefProps ) {
	const [ document_, setDocument ] = useState< CastingBriefDocument | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setDocument( null );
		setError( null );
		api.castings( gameSlug )
			.brief( castingId )
			.then( setDocument )
			.catch( () =>
				setError(
					__( 'Failed to load this casting brief.', 'beyond-elysium' )
				)
			);
	}, [ gameSlug, castingId ] );

	return (
		<div className="be-casting-brief">
			<div className="be-help-heading">
				<h2>{ __( 'Casting Brief', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="npc-casting" />
			</div>
			<div className="be-casting-brief__actions">
				<button type="button" onClick={ onClose }>
					{ __( '← Back', 'beyond-elysium' ) }
				</button>
				{ document_ && (
					<a
						className="be-casting-brief__print"
						href={ api
							.castings( gameSlug )
							.briefPdfUrl( castingId ) }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Print', 'beyond-elysium' ) }
					</a>
				) }
			</div>

			{ error && (
				<div className="be-casting-brief__error" role="alert">
					{ error }
				</div>
			) }

			{ ! error && ! document_ && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }

			{ document_ && (
				<>
					<h2>{ document_.title }</h2>
					<dl className="be-casting-brief__header">
						{ document_.header.map( ( [ label, value ] ) => (
							<div key={ label }>
								<dt>{ label }</dt>
								<dd>{ value }</dd>
							</div>
						) ) }
					</dl>

					{ document_.prose.map( ( [ label, value ] ) => (
						<div className="be-casting-brief__prose" key={ label }>
							<h3>{ label }</h3>
							<p>{ value }</p>
						</div>
					) ) }

					<div className="be-casting-brief__sections">
						{ document_.sections.map( ( section ) => (
							<div
								className="be-casting-brief__section"
								key={ section.block_slug }
							>
								<h3>{ section.title }</h3>
								{ section.rows && (
									<ul>
										{ section.rows.map( ( row, i ) => (
											// eslint-disable-next-line react/no-array-index-key
											<BriefRow key={ i } row={ row } />
										) ) }
									</ul>
								) }
								{ section.groups && (
									<>
										{ section.groups.map( ( group, i ) => (
											// eslint-disable-next-line react/no-array-index-key
											<div key={ i }>
												{ group.label && (
													<h4>{ group.label }</h4>
												) }
												<ul>
													{ group.rows.map(
														( row, j ) => (
															<BriefRow
																// eslint-disable-next-line react/no-array-index-key
																key={ j }
																row={ row }
															/>
														)
													) }
												</ul>
											</div>
										) ) }
									</>
								) }
							</div>
						) ) }
					</div>
				</>
			) }
		</div>
	);
}

export default CastingBrief;
