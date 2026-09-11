/**
 * ImportTool is the single-character exchange-file import wizard: upload a .gex
 * file, preview its contents, match players to WordPress accounts, resolve
 * flagged traits, and commit. Reuses ImportPreview for the shared preview and
 * resolution UI.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';
import api from '../../api/client';
import type {
	ImportPreview as ImportPreviewData,
	ImportCommitResult,
	ImportResolutions,
	DuplicateAction,
	TraitResolution,
} from '../../types/import';
import { ImportPreview, keyFor } from './ImportPreview';
import './ImportTool.css';

export interface ImportToolProps {
	gameSlug: string;
}

type Stage = 'upload' | 'preview' | 'players' | 'traits' | 'commit';

const STAGES: { key: Stage; label: string }[] = [
	{ key: 'upload', label: __( '1. Upload', 'beyond-elysium' ) },
	{ key: 'preview', label: __( '2. Preview', 'beyond-elysium' ) },
	{ key: 'players', label: __( '3. Match Players', 'beyond-elysium' ) },
	{ key: 'traits', label: __( '4. Resolve Traits', 'beyond-elysium' ) },
	{ key: 'commit', label: __( '5. Commit', 'beyond-elysium' ) },
];

/**
 * Renders the .gex import wizard: upload a file, then walk through preview
 * counts, player matching, flagged-trait review, and commit. Commit applies the
 * reviewed job in a single transaction and is blocked while any trait or
 * duplicate remains unresolved.
 */
export function ImportTool( { gameSlug }: ImportToolProps ) {
	const [ stage, setStage ] = useState<Stage>( 'upload' );
	const [ preview, setPreview ] = useState<ImportPreviewData | null>( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );
	const [ committing, setCommitting ] = useState( false );
	const [ result, setResult ] = useState<ImportCommitResult | null>( null );
	const fileInputRef = useRef<HTMLInputElement>( null );

	// Lifted here, not local to ImportPreview, so every stage that reads it sees the same resolution choices.
	const [ traitResolutions, setTraitResolutions ] = useState<Record<string, TraitResolution>>( {} );
	const [ duplicateActions, setDuplicateActions ] = useState<Record<string, DuplicateAction>>( {} );
	const [ worldObjectActions, setWorldObjectActions ] = useState<Record<string, DuplicateAction>>( {} );

	function onTraitResolutionChange( key: string, resolution: TraitResolution | null ) {
		setTraitResolutions( ( prev ) => {
			const next = { ...prev };
			if ( resolution ) {
				next[ key ] = resolution;
			} else {
				delete next[ key ];
			}
			return next;
		} );
	}

	function onDuplicateActionChange( character: string, action: DuplicateAction | null ) {
		setDuplicateActions( ( prev ) => {
			const next = { ...prev };
			if ( action ) {
				next[ character ] = action;
			} else {
				delete next[ character ];
			}
			return next;
		} );
	}

	function onWorldObjectActionChange( key: string, action: DuplicateAction | null ) {
		setWorldObjectActions( ( prev ) => {
			const next = { ...prev };
			if ( action ) {
				next[ key ] = action;
			} else {
				delete next[ key ];
			}
			return next;
		} );
	}

	/** How many trait/duplicate decisions still block a commit, after local resolutions. */
	function blockingCount(): number {
		if ( ! preview ) {
			return 0;
		}
		const stillFlagged = preview.flagged_traits.filter( ( t, i ) => ! traitResolutions[ keyFor( t, i ) ] ).length;
		// An unresolved entry marked "keep as written" is no longer blocking, same as a resolved flagged trait.
		const stillUnresolved = preview.unresolved.filter( ( t, i ) => ! traitResolutions[ keyFor( t, i ) ] ).length;
		const stillDuplicate = preview.duplicates.filter( ( d ) => ! duplicateActions[ d.character ] ).length;
		const stillWorldObject = preview.world_object_duplicates.filter(
			( d ) => ! worldObjectActions[ `${ d.type }:${ d.name }` ]
		).length;
		return stillUnresolved + stillFlagged + stillDuplicate + stillWorldObject;
	}

	async function doCommit() {
		if ( ! preview ) {
			return;
		}
		setCommitting( true );
		setError( null );
		try {
			const resolutions: ImportResolutions = {
				duplicates: duplicateActions,
				world_objects: worldObjectActions,
				traits: Object.values( traitResolutions ),
			};
			const committed = await api.gexImport( gameSlug ).commit( preview.job_id, resolutions );
			setResult( committed );
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setCommitting( false );
		}
	}

	async function upload( e: React.FormEvent ) {
		e.preventDefault();
		const file = fileInputRef.current?.files?.[ 0 ];
		if ( ! file ) {
			return;
		}

		setLoading( true );
		setError( null );
		try {
			const result = await api.gexImport( gameSlug ).parse( file );
			setPreview( result );
			setStage( 'preview' );
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setLoading( false );
		}
	}

	function currentStageIndex(): number {
		return STAGES.findIndex( ( s ) => s.key === stage );
	}

	return (
		<div className="be-import-tool">
			<nav className="be-import-tool__steps">
				{ STAGES.map( ( s, i ) => (
					<span
						key={ s.key }
						className={
							'be-import-tool__step' +
							( s.key === stage ? ' is-active' : '' ) +
							( i < currentStageIndex() ? ' is-done' : '' )
						}
					>
						{ s.label }
					</span>
				) ) }
			</nav>

			{ error && (
				<div className="be-import-tool__error" role="alert">
					{ error }
				</div>
			) }

			{ stage === 'upload' && (
				<form className="be-import-tool__upload" onSubmit={ upload }>
					<p>
						{ __( 'Upload a Grapevine exchange file (', 'beyond-elysium' ) }<code>.gex</code>
						{ __( ', binary or XML).', 'beyond-elysium' ) }
					</p>
					<input type="file" ref={ fileInputRef } accept=".gex" required />
					<button type="submit" disabled={ loading }>
						{ loading ? __( 'Parsing…', 'beyond-elysium' ) : __( 'Parse File', 'beyond-elysium' ) }
					</button>
				</form>
			) }

			{ stage === 'preview' && preview && (
				<>
					<ImportPreview
						preview={ preview }
						traitResolutions={ traitResolutions }
						onTraitResolutionChange={ onTraitResolutionChange }
						duplicateActions={ duplicateActions }
						onDuplicateActionChange={ onDuplicateActionChange }
						worldObjectActions={ worldObjectActions }
						onWorldObjectActionChange={ onWorldObjectActionChange }
					/>
					<div className="be-import-tool__nav-row">
						<button type="button" onClick={ () => setStage( 'upload' ) }>
							{ __( 'Start Over', 'beyond-elysium' ) }
						</button>
						<button type="button" onClick={ () => setStage( 'players' ) }>
							{ __( 'Next: Match Players', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) }

			{ stage === 'players' && preview && (
				<>
					<h4>{ __( 'Player Matching', 'beyond-elysium' ) }</h4>
					{ /* XML exchange files carry no player identity data at all, unlike binary GVBE files. */ }
					{ preview.players_needing_match.length === 0 ? (
						preview.format === 'XML' ? (
							<p>
								{ __( "This file format doesn't include player email addresses, so none could be matched automatically. Assign each character to its real player after import, from the character roster.", 'beyond-elysium' ) }
							</p>
						) : (
							<p>{ __( 'Every player in this file matched an existing WordPress user by email.', 'beyond-elysium' ) }</p>
						)
					) : (
						<ul>
							{ preview.players_needing_match.map( ( player, i ) => (
								<li key={ i }>
									<strong>{ player.gv_name }</strong>
									{ player.gv_email && <span> ({ player.gv_email })</span> }
									{ player.suggestions.length > 0 && (
										<span> { sprintf( __( '— closest match: %s', 'beyond-elysium' ), player.suggestions[ 0 ].display_name ) }</span>
									) }
								</li>
							) ) }
						</ul>
					) }
					<div className="be-import-tool__nav-row">
						<button type="button" onClick={ () => setStage( 'preview' ) }>
							{ __( 'Back', 'beyond-elysium' ) }
						</button>
						<button type="button" onClick={ () => setStage( 'traits' ) }>
							{ __( 'Next: Resolve Traits', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) }

			{ stage === 'traits' && preview && (
				<>
					<h4>{ __( 'Trait Resolution', 'beyond-elysium' ) }</h4>
					<ImportPreview
						preview={ preview }
						traitResolutions={ traitResolutions }
						onTraitResolutionChange={ onTraitResolutionChange }
						duplicateActions={ duplicateActions }
						onDuplicateActionChange={ onDuplicateActionChange }
						worldObjectActions={ worldObjectActions }
						onWorldObjectActionChange={ onWorldObjectActionChange }
					/>
					<div className="be-import-tool__nav-row">
						<button type="button" onClick={ () => setStage( 'players' ) }>
							{ __( 'Back', 'beyond-elysium' ) }
						</button>
						<button type="button" onClick={ () => setStage( 'commit' ) }>
							{ __( 'Next: Commit', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) }

			{ stage === 'commit' && preview && ! result && (
				<>
					<h4>{ __( 'Commit', 'beyond-elysium' ) }</h4>
					{ blockingCount() > 0 ? (
						<p className="be-import-tool__blocking-note">
							{ sprintf(
								_n(
									'%d item (trait or duplicate character) must be resolved before this import can be committed (Step 6h) - go back and review them above.',
									'%d items (trait or duplicate character) must be resolved before this import can be committed (Step 6h) - go back and review them above.',
									blockingCount(),
									'beyond-elysium'
								),
								blockingCount()
							) }
						</p>
					) : (
						<p>
							{ __( 'Ready to commit job', 'beyond-elysium' ) } <code>{ preview.job_id }</code>
							{ __( '. This creates every item, location, rote and character in one transaction - nothing is written unless all of it succeeds.', 'beyond-elysium' ) }
						</p>
					) }
					<div className="be-import-tool__nav-row">
						<button type="button" onClick={ () => setStage( 'traits' ) }>
							{ __( 'Back', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ committing || blockingCount() > 0 }
							onClick={ doCommit }
						>
							{ committing ? __( 'Committing…', 'beyond-elysium' ) : __( 'Commit', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) }

			{ stage === 'commit' && result && (
				<>
					<h4>{ __( 'Import Complete', 'beyond-elysium' ) }</h4>
					<ul className="be-import-tool__result">
						<li>
							{ sprintf(
								_n( '%d item processed', '%d items processed', result.items.length, 'beyond-elysium' ),
								result.items.length
							) }
						</li>
						<li>
							{ sprintf(
								_n( '%d location processed', '%d locations processed', result.locations.length, 'beyond-elysium' ),
								result.locations.length
							) }
						</li>
						<li>
							{ sprintf(
								_n( '%d rote processed', '%d rotes processed', result.rotes.length, 'beyond-elysium' ),
								result.rotes.length
							) }
						</li>
						<li>
							{ sprintf(
								_n( '%d character processed', '%d characters processed', result.characters.length, 'beyond-elysium' ),
								result.characters.length
							) }
						</li>
					</ul>
					{ [
						[ __( 'Items', 'beyond-elysium' ), result.items ],
						[ __( 'Locations', 'beyond-elysium' ), result.locations ],
						[ __( 'Rotes', 'beyond-elysium' ), result.rotes ],
						[ __( 'Characters', 'beyond-elysium' ), result.characters ],
					].map( ( [ label, entities ] ) =>
						( entities as typeof result.characters ).length > 0 ? (
							<div key={ label as string }>
								<h5>{ label as string }</h5>
								<ul>
									{ ( entities as typeof result.characters ).map( ( e ) => (
										<li key={ `${ label }-${ e.id }` }>
											{ e.name }
											{ e.action !== 'created' && <span> ({ e.action })</span> }
										</li>
									) ) }
								</ul>
							</div>
						) : null
					) }
					{ ( result.skipped_actions || result.skipped_plots || result.skipped_rumors || result.skipped_queries ) && (
						<p className="be-import-tool__blocking-note">
							{ __( 'This file also carried action, plot, rumor and/or query records - none of those have an import destination yet, so they were left out rather than guessed at.', 'beyond-elysium' ) }
						</p>
					) }
				</>
			) }
		</div>
	);
}

interface RestError {
	message?: string;
}

function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Failed to parse this file.', 'beyond-elysium' );
}

export default ImportTool;
