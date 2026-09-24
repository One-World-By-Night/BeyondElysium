/**
 * GameImportTool is the full game-file import wizard: upload a.gv3 chronicle file, preview its contents, choose a
 * target chronicle, resolve collisions, and commit.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useRef, useState } from '@wordpress/element';
import api from '../../api/client';
import type {
	GameImportPreview,
	GameImportCommitResult,
	GameImportTarget,
	ImportResolutions,
} from '../../types/import';
import { ImportPreview } from './ImportPreview';
import { useImportDecisions } from '../../lib/useImportDecisions';
import './ImportTool.css';

type Stage = 'upload' | 'preview' | 'target' | 'resolve' | 'commit';

const STAGES: { key: Stage; label: string }[] = [
	{ key: 'upload', label: __( '1. Upload', 'beyond-elysium' ) },
	{ key: 'preview', label: __( '2. Preview', 'beyond-elysium' ) },
	{ key: 'target', label: __( '3. Choose Target', 'beyond-elysium' ) },
	{ key: 'resolve', label: __( '4. Resolve', 'beyond-elysium' ) },
	{ key: 'commit', label: __( '5. Commit', 'beyond-elysium' ) },
];

/**
 * Renders the full game-file import wizard: upload a.gv3 file, preview the chronicle it carries, choose whether to
 * create a new chronicle or merge into an existing one, resolve anything that collides.
 */
export function GameImportTool() {
	const [ stage, setStage ] = useState< Stage >( 'upload' );
	const [ preview, setPreview ] = useState< GameImportPreview | null >(
		null
	);
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ committing, setCommitting ] = useState( false );
	const [ result, setResult ] = useState< GameImportCommitResult | null >(
		null
	);
	const fileInputRef = useRef< HTMLInputElement >( null );

	const [ targetAction, setTargetAction ] = useState<
		'create_new' | 'merge' | ''
	>( '' );
	const [ newChronicleName, setNewChronicleName ] = useState( '' );
	const [ mergeGameSlug, setMergeGameSlug ] = useState( '' );

	// The chronicle the current preview was checked against ('' until a merge target is confirmed).
	const [ previewTarget, setPreviewTarget ] = useState( '' );

	const {
		traitResolutions,
		duplicateActions,
		worldObjectActions,
		onTraitResolutionChange,
		onDuplicateActionChange,
		onWorldObjectActionChange,
		blockingCount,
	} = useImportDecisions( preview, previewTarget );

	async function upload( e: React.FormEvent ) {
		e.preventDefault();
		const file = fileInputRef.current?.files?.[ 0 ];
		if ( ! file ) {
			return;
		}

		setLoading( true );
		setError( null );
		try {
			const parsed = await api.gameImport().parse( file );
			setPreview( parsed );
			setPreviewTarget( '' );
			setNewChronicleName( parsed.chronicle_title );
			setStage( 'preview' );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to parse this file.', 'beyond-elysium' )
				)
			);
		} finally {
			setLoading( false );
		}
	}

	/**
	 * Moving past the Target stage re-fetches the preview against the chosen target.
	 */
	async function confirmTarget() {
		if ( ! preview ) {
			return;
		}
		const target = targetAction === 'merge' ? mergeGameSlug : '';
		if ( target !== previewTarget ) {
			setLoading( true );
			setError( null );
			try {
				const refreshed = await api
					.gameImport()
					.getJob( preview.job_id, target || undefined );
				setPreview( refreshed );
				setPreviewTarget( target );
			} catch ( err: unknown ) {
				setError(
					errorMessage(
						err,
						__( 'Failed to parse this file.', 'beyond-elysium' )
					)
				);
				return;
			} finally {
				setLoading( false );
			}
		}
		setStage( 'resolve' );
	}

	async function doCommit() {
		if ( ! preview || ! targetAction ) {
			return;
		}
		setCommitting( true );
		setError( null );
		try {
			const target: GameImportTarget =
				targetAction === 'create_new'
					? {
							action: 'create_new',
							name: newChronicleName || preview.chronicle_title,
					  }
					: { action: 'merge', game_slug: mergeGameSlug };
			const resolutions: ImportResolutions = {
				duplicates: duplicateActions,
				world_objects: worldObjectActions,
				traits: Object.values( traitResolutions ),
			};
			const committed = await api
				.gameImport()
				.commit( preview.job_id, target, resolutions );
			setResult( committed );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to parse this file.', 'beyond-elysium' )
				)
			);
		} finally {
			setCommitting( false );
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
						{ __(
							'Upload a full Grapevine game file (',
							'beyond-elysium'
						) }
						<code>.gv3</code>
						{ __(
							", binary). This is a whole chronicle - characters, items, locations, and more - not a single character's exchange file.",
							'beyond-elysium'
						) }
					</p>
					<input
						type="file"
						ref={ fileInputRef }
						accept=".gv3"
						required
						aria-label={ __(
							'Grapevine game file to upload (.gv3)',
							'beyond-elysium'
						) }
					/>
					<button type="submit" disabled={ loading }>
						{ loading
							? __( 'Parsing…', 'beyond-elysium' )
							: __( 'Parse File', 'beyond-elysium' ) }
					</button>
				</form>
			) }

			{ stage === 'preview' && preview && (
				<>
					<h4>
						{ preview.chronicle_title ||
							__( '(untitled chronicle)', 'beyond-elysium' ) }
					</h4>
					<ImportPreview
						preview={ preview }
						traitResolutions={ traitResolutions }
						onTraitResolutionChange={ onTraitResolutionChange }
						duplicateActions={ duplicateActions }
						onDuplicateActionChange={ onDuplicateActionChange }
						worldObjectActions={ worldObjectActions }
						onWorldObjectActionChange={ onWorldObjectActionChange }
					/>
					<h4>
						{ __( 'Not Imported by This Tool', 'beyond-elysium' ) }
					</h4>
					<p className="be-import-tool__blocking-note">
						{ __( 'This file also carries', 'beyond-elysium' ) }{ ' ' }
						{ preview.skipped.queries }{ ' ' }
						{ _n(
							'query',
							'queries',
							preview.skipped.queries,
							'beyond-elysium'
						) }
						, { preview.skipped.actions }{ ' ' }
						{ _n(
							'action',
							'actions',
							preview.skipped.actions,
							'beyond-elysium'
						) }
						, { preview.skipped.plots }{ ' ' }
						{ _n(
							'plot',
							'plots',
							preview.skipped.plots,
							'beyond-elysium'
						) }
						, { preview.skipped.rumors }{ ' ' }
						{ _n(
							'rumor',
							'rumors',
							preview.skipped.rumors,
							'beyond-elysium'
						) }
						, { preview.skipped.xp_awards }{ ' ' }
						{ _n(
							'XP award',
							'XP awards',
							preview.skipped.xp_awards,
							'beyond-elysium'
						) }
						, { preview.skipped.templates }{ ' ' }
						{ _n(
							'template',
							'templates',
							preview.skipped.templates,
							'beyond-elysium'
						) }
						, { preview.skipped.calendar_entries }{ ' ' }
						{ _n(
							'calendar entry',
							'calendar entries',
							preview.skipped.calendar_entries,
							'beyond-elysium'
						) }
						{ preview.skipped.apr_engine
							? __(
									', and action/rumor allocation settings',
									'beyond-elysium'
							  )
							: '' }{ ' ' }
						{ __(
							'- none of these have an import destination yet, so they are left out rather than guessed at.',
							'beyond-elysium'
						) }
					</p>
					<div className="be-import-tool__nav-row">
						<button
							type="button"
							onClick={ () => setStage( 'upload' ) }
						>
							{ __( 'Start Over', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ () => setStage( 'target' ) }
						>
							{ __( 'Next: Choose Target', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) }

			{ stage === 'target' && preview && (
				<>
					<h4>{ __( 'Where does this go?', 'beyond-elysium' ) }</h4>
					<div className="be-import-tool__target">
						<label>
							<input
								type="radio"
								name="target-action"
								value="create_new"
								checked={ targetAction === 'create_new' }
								onChange={ () =>
									setTargetAction( 'create_new' )
								}
							/>{ ' ' }
							{ __( 'Create a new chronicle', 'beyond-elysium' ) }
						</label>
						{ targetAction === 'create_new' && (
							<p>
								<label>
									{ __( 'Name', 'beyond-elysium' ) }{ ' ' }
									<input
										type="text"
										value={ newChronicleName }
										onChange={ ( e ) =>
											setNewChronicleName(
												e.target.value
											)
										}
									/>
								</label>
							</p>
						) }

						<label>
							<input
								type="radio"
								name="target-action"
								value="merge"
								checked={ targetAction === 'merge' }
								onChange={ () => setTargetAction( 'merge' ) }
							/>{ ' ' }
							{ __(
								'Merge into an existing chronicle',
								'beyond-elysium'
							) }
						</label>
						{ targetAction === 'merge' && (
							<p>
								<label>
									{ __( 'Chronicle', 'beyond-elysium' ) }{ ' ' }
									<select
										value={ mergeGameSlug }
										onChange={ ( e ) =>
											setMergeGameSlug( e.target.value )
										}
									>
										<option value="">
											{ __(
												'Choose…',
												'beyond-elysium'
											) }
										</option>
										{ preview.existing_games.map( ( g ) => (
											<option
												key={ g.slug }
												value={ g.slug }
											>
												{ g.name }
											</option>
										) ) }
									</select>
								</label>
								<span className="be-import-tool__blocking-note">
									{ ' ' }
									{ __(
										'The existing chronicle is never overwritten wholesale - only records that collide by name need a decision, on the next step.',
										'beyond-elysium'
									) }
								</span>
							</p>
						) }
					</div>
					<div className="be-import-tool__nav-row">
						<button
							type="button"
							onClick={ () => setStage( 'preview' ) }
						>
							{ __( 'Back', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={
								loading ||
								! targetAction ||
								( targetAction === 'create_new' &&
									! newChronicleName.trim() ) ||
								( targetAction === 'merge' && ! mergeGameSlug )
							}
							onClick={ confirmTarget }
						>
							{ loading
								? __( 'Checking…', 'beyond-elysium' )
								: __( 'Next: Resolve', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) }

			{ stage === 'resolve' && preview && (
				<>
					<h4>
						{ targetAction === 'merge'
							? sprintf(
									/* translators: %s: chronicle name being merged into */
									__(
										'Resolve — merging into %s',
										'beyond-elysium'
									),
									preview.existing_games.find(
										( g ) => g.slug === mergeGameSlug
									)?.name ?? mergeGameSlug
							  )
							: __(
									'Resolve — new chronicle',
									'beyond-elysium'
							  ) }
					</h4>
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
						<button
							type="button"
							onClick={ () => setStage( 'target' ) }
						>
							{ __( 'Back', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ () => setStage( 'commit' ) }
						>
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
								/* translators: %d: number of items still needing resolution */
								_n(
									'%d item must be resolved before this import can be committed - go back and review them above.',
									'%d items must be resolved before this import can be committed - go back and review them above.',
									blockingCount(),
									'beyond-elysium'
								),
								blockingCount()
							) }
						</p>
					) : (
						<p>
							{ __( 'Ready to commit job', 'beyond-elysium' ) }{ ' ' }
							<code>{ preview.job_id }</code> -{ ' ' }
							{ targetAction === 'create_new'
								? sprintf(
										/* translators: %s: name of the new chronicle being created */
										__(
											'creating a new chronicle, "%s."',
											'beyond-elysium'
										),
										newChronicleName
								  )
								: sprintf(
										/* translators: %s: name of the existing chronicle being merged into */
										__(
											'merging into "%s."',
											'beyond-elysium'
										),
										preview.existing_games.find(
											( g ) => g.slug === mergeGameSlug
										)?.name ?? mergeGameSlug
								  ) }{ ' ' }
							{ __(
								'This creates or updates every record in one transaction - nothing is written unless all of it succeeds.',
								'beyond-elysium'
							) }
						</p>
					) }
					<div className="be-import-tool__nav-row">
						<button
							type="button"
							onClick={ () => setStage( 'resolve' ) }
						>
							{ __( 'Back', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ committing || blockingCount() > 0 }
							onClick={ doCommit }
						>
							{ committing
								? __( 'Committing…', 'beyond-elysium' )
								: __( 'Commit', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) }

			{ stage === 'commit' && result && (
				<>
					<h4>{ __( 'Import Complete', 'beyond-elysium' ) }</h4>
					<p>
						{ result.game.created
							? __( 'Created', 'beyond-elysium' )
							: __( 'Merged into', 'beyond-elysium' ) }{ ' ' }
						{ __( 'chronicle', 'beyond-elysium' ) }{ ' ' }
						<strong>{ result.game.name }</strong>.
					</p>
					<ul className="be-import-tool__result">
						<li>
							{ sprintf(
								/* translators: %d: number of characters processed by the import */
								_n(
									'%d character processed',
									'%d characters processed',
									result.characters.length,
									'beyond-elysium'
								),
								result.characters.length
							) }
						</li>
						<li>
							{ sprintf(
								/* translators: %d: number of items processed by the import */
								_n(
									'%d item processed',
									'%d items processed',
									result.items.length,
									'beyond-elysium'
								),
								result.items.length
							) }
						</li>
						<li>
							{ sprintf(
								/* translators: %d: number of locations processed by the import */
								_n(
									'%d location processed',
									'%d locations processed',
									result.locations.length,
									'beyond-elysium'
								),
								result.locations.length
							) }
						</li>
						<li>
							{ sprintf(
								/* translators: %d: number of rotes processed by the import */
								_n(
									'%d rote processed',
									'%d rotes processed',
									result.rotes.length,
									'beyond-elysium'
								),
								result.rotes.length
							) }
						</li>
					</ul>
				</>
			) }
		</div>
	);
}

import { errorMessage } from '../../lib/errorMessage';

export default GameImportTool;
