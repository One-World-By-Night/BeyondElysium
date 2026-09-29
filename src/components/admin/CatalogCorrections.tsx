/**
 * The book corrections a chronicle has to review, under Chronicle Setup's catalog row: each change it made where the
 * book has changed since, with Keep mine, Use the book's and Edit.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import api from '../../api/client';
import type { CatalogCorrection } from '../../types';
import { correctionMessage, correctionPlace } from '../../lib/correctionLabel';
import { errorMessage } from '../../lib/errorMessage';

/**
 * Where a correction's field is edited, or null where no editor opens on it.
 */
function editHref(
	gameSlug: string,
	correction: CatalogCorrection
): string | null {
	const base = `admin.php?page=beyond-elysium-system-config&game_slug=${ encodeURIComponent( gameSlug ) }`;
	if ( correction.kind === 'block' ) {
		return `${ base }&tab=schema-blocks&edit=${ encodeURIComponent( correction.target ) }`;
	}
	if ( correction.kind === 'template' ) {
		return `${ base }&tab=templates`;
	}
	return null;
}

export function CatalogCorrections( {
	gameSlug,
	onChanged,
}: {
	gameSlug: string;
	onChanged: () => void;
} ) {
	const [ corrections, setCorrections ] = useState<
		CatalogCorrection[] | null
	>( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const client = api.catalogCorrections( gameSlug );

	useEffect( () => {
		client
			.list()
			.then( ( result ) => setCorrections( result.corrections ) )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug ] );

	/**
	 * Runs one action and shows the list as the server now has it.
	 */
	async function act(
		run: () => Promise< { corrections: CatalogCorrection[] } >
	) {
		setBusy( true );
		setError( null );
		try {
			const result = await run();
			setCorrections( result.corrections );
			onChanged();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setBusy( false );
		}
	}

	if ( corrections === null ) {
		return error ? (
			<p role="alert">{ error }</p>
		) : (
			<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
		);
	}

	return (
		<div className="be-catalog-corrections">
			{ error && (
				<p className="be-admin__error" role="alert">
					{ error }
				</p>
			) }
			<p>
				{ corrections.length === 0
					? __( 'No book corrections to review.', 'beyond-elysium' )
					: sprintf(
							/* translators: %d: number of changes this chronicle made where the book has changed since */
							_n(
								"%d change you made is under a book correction. Keep yours, or take the book's.",
								"%d changes you made are under book corrections. Keep yours, or take the book's.",
								corrections.length,
								'beyond-elysium'
							),
							corrections.length
						) }
			</p>
			{ corrections.length > 1 && (
				<p>
					<button
						type="button"
						className="button"
						disabled={ busy }
						onClick={ () => act( () => client.keepAll() ) }
					>
						{ __( 'Keep all mine', 'beyond-elysium' ) }
					</button>
				</p>
			) }
			<ul className="be-catalog-corrections__list">
				{ corrections.map( ( correction ) => {
					const href = editHref( gameSlug, correction );
					return (
						<li
							key={ `${ correction.kind }:${ correction.target }:${ JSON.stringify( correction.path ) }` }
						>
							<strong>{ correctionPlace( correction ) }</strong>
							<span className="be-catalog-corrections__message">
								{ correctionMessage( correction ) }
							</span>
							<span className="be-catalog-corrections__actions">
								<button
									type="button"
									className="button"
									disabled={ busy }
									onClick={ () =>
										act( () =>
											client.keep(
												correction.kind,
												correction.target,
												correction.path
											)
										)
									}
								>
									{ __( 'Keep mine', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									className="button"
									disabled={ busy }
									onClick={ () =>
										act( () =>
											client.take(
												correction.kind,
												correction.target,
												correction.path
											)
										)
									}
								>
									{ __( "Use the book's", 'beyond-elysium' ) }
								</button>
								{ href && (
									<a className="button" href={ href }>
										{ __( 'Edit', 'beyond-elysium' ) }
									</a>
								) }
							</span>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

export default CatalogCorrections;
