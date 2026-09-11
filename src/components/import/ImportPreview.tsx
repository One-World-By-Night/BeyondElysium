/**
 * ImportPreview renders the shared review content for both import wizards
 * (ImportTool's .gex flow and GameImportTool's .gv3 flow): record counts,
 * warnings, duplicate records, and flagged/unresolved trait lists, each with
 * its own resolution control.
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type {
	DuplicateAction,
	FlaggedTrait,
	ImportPreview as ImportPreviewData,
	TraitResolution,
	UnresolvedTrait,
} from '../../types/import';
import './ImportPreview.css';

export interface ImportPreviewProps {
	preview: ImportPreviewData;
	traitResolutions: Record<string, TraitResolution>;
	onTraitResolutionChange: ( key: string, resolution: TraitResolution | null ) => void;
	duplicateActions: Record<string, DuplicateAction>;
	onDuplicateActionChange: ( character: string, action: DuplicateAction | null ) => void;
	/** Keyed by "{type}:{name}", matching DuplicateWorldObject. */
	worldObjectActions: Record<string, DuplicateAction>;
	onWorldObjectActionChange: ( key: string, action: DuplicateAction | null ) => void;
}

const COUNT_LABELS: Record<string, string> = {
	players: __( 'Players', 'beyond-elysium' ),
	characters: __( 'Characters', 'beyond-elysium' ),
	queries: __( 'Queries', 'beyond-elysium' ),
	items: __( 'Items', 'beyond-elysium' ),
	rotes: __( 'Rotes', 'beyond-elysium' ),
	locations: __( 'Locations', 'beyond-elysium' ),
	actions: __( 'Actions', 'beyond-elysium' ),
	plots: __( 'Plots', 'beyond-elysium' ),
	rumors: __( 'Rumors', 'beyond-elysium' ),
};

/**
 * Builds a local lookup key for one flagged or unresolved trait row, combining its
 * character, block, and raw value with its row index. Used to key the resolution
 * maps that track which traits have been resolved in the UI.
 */
export function keyFor( trait: FlaggedTrait | UnresolvedTrait, index: number ): string {
	return `${ trait.character }|${ trait.block }|${ trait.raw }|${ index }`;
}

/**
 * Renders the shared preview content for both import wizards: record counts,
 * warnings, duplicate characters, duplicate items/locations/rotes, flagged and
 * unresolved traits, and players needing a match. Resolution choices are lifted
 * to the caller (ImportTool or GameImportTool) and passed back in as props.
 */
export function ImportPreview( {
	preview,
	traitResolutions,
	onTraitResolutionChange,
	duplicateActions,
	onDuplicateActionChange,
	worldObjectActions,
	onWorldObjectActionChange,
}: ImportPreviewProps ) {
	return (
		<div className="be-import-preview">
			<h4>{ __( 'Counts', 'beyond-elysium' ) }</h4>
			<table className="be-import-preview__counts">
				<tbody>
					{ Object.entries( preview.counts )
						.filter( ( [ , count ] ) => count > 0 )
						.map( ( [ key, count ] ) => (
							<tr key={ key }>
								<td>{ COUNT_LABELS[ key ] ?? key }</td>
								<td>{ count }</td>
							</tr>
						) ) }
				</tbody>
			</table>
			{ Object.values( preview.counts ).every( ( c ) => c === 0 ) && <p>{ __( 'This file contains no importable records.', 'beyond-elysium' ) }</p> }

			{ preview.warnings.length > 0 && (
				<div className="be-import-preview__warnings">
					<h4>{ __( 'Warnings', 'beyond-elysium' ) }</h4>
					<ul>
						{ preview.warnings.map( ( warning, i ) => (
							<li key={ i }>{ warning }</li>
						) ) }
					</ul>
				</div>
			) }

			<h4>{ sprintf( __( 'Duplicate Characters (%d)', 'beyond-elysium' ), preview.duplicates.length ) }</h4>
			{ preview.duplicates.length === 0 ? (
				<p>{ __( 'None - no character in this file already exists in this chronicle by name.', 'beyond-elysium' ) }</p>
			) : (
				<>
					<p className="be-import-preview__blocking-note">
						{ __( 'Each of these must be resolved before a commit can proceed.', 'beyond-elysium' ) }
					</p>
					<table className="be-import-preview__duplicates">
						<thead>
							<tr>
								<th>{ __( 'Character', 'beyond-elysium' ) }</th>
								<th>{ __( 'Decision', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ preview.duplicates.map( ( dup ) => (
								<tr key={ dup.character }>
									<td>{ dup.character }</td>
									<td>
										<select
											value={ duplicateActions[ dup.character ] ?? '' }
											aria-label={ sprintf( __( 'Decision for duplicate character %s', 'beyond-elysium' ), dup.character ) }
											onChange={ ( e ) =>
												onDuplicateActionChange(
													dup.character,
													e.target.value ? ( e.target.value as DuplicateAction ) : null
												)
											}
										>
											<option value="">{ __( 'Choose…', 'beyond-elysium' ) }</option>
											<option value="skip">{ __( 'Skip (keep the existing character as-is)', 'beyond-elysium' ) }</option>
											<option value="overwrite">{ __( 'Overwrite (update the existing character in place)', 'beyond-elysium' ) }</option>
											<option value="import_as_new">{ __( 'Import as a new, separate character', 'beyond-elysium' ) }</option>
										</select>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }

			<h4>{ sprintf( __( 'Duplicate Items/Locations/Rotes (%d)', 'beyond-elysium' ), preview.world_object_duplicates.length ) }</h4>
			{ preview.world_object_duplicates.length === 0 ? (
				<p>{ __( 'None - nothing in this file already exists in this chronicle by name.', 'beyond-elysium' ) }</p>
			) : (
				<>
					<p className="be-import-preview__blocking-note">
						{ __( 'Each of these must be resolved before a commit can proceed.', 'beyond-elysium' ) }
					</p>
					<table className="be-import-preview__duplicates">
						<thead>
							<tr>
								<th>{ __( 'Type', 'beyond-elysium' ) }</th>
								<th>{ __( 'Name', 'beyond-elysium' ) }</th>
								<th>{ __( 'Decision', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ preview.world_object_duplicates.map( ( dup ) => {
								const key = `${ dup.type }:${ dup.name }`;
								return (
									<tr key={ key }>
										<td>{ dup.type }</td>
										<td>{ dup.name }</td>
										<td>
											<select
												value={ worldObjectActions[ key ] ?? '' }
												aria-label={ sprintf(
													/* translators: 1: object type (item, location, or rote), 2: object name */
													__( 'Decision for duplicate %1$s "%2$s"', 'beyond-elysium' ),
													dup.type,
													dup.name
												) }
												onChange={ ( e ) =>
													onWorldObjectActionChange(
														key,
														e.target.value ? ( e.target.value as DuplicateAction ) : null
													)
												}
											>
												<option value="">{ __( 'Choose…', 'beyond-elysium' ) }</option>
												<option value="skip">{ __( 'Skip (keep the existing one as-is)', 'beyond-elysium' ) }</option>
												<option value="overwrite">{ __( 'Overwrite (update the existing one in place)', 'beyond-elysium' ) }</option>
												<option value="import_as_new">{ __( 'Import as new, a separate entry', 'beyond-elysium' ) }</option>
											</select>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</>
			) }

			<h4>{ sprintf( __( 'Flagged Traits (%d)', 'beyond-elysium' ), preview.flagged_traits.length ) }</h4>
			{ preview.flagged_traits.length === 0 ? (
				<p>{ __( 'None - every trait matched exactly or by normalization.', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-import-preview__traits">
					<thead>
						<tr>
							<th>{ __( 'Character', 'beyond-elysium' ) }</th>
							<th>{ __( 'Block', 'beyond-elysium' ) }</th>
							<th>{ __( 'Raw Value', 'beyond-elysium' ) }</th>
							<th>{ __( 'Suggestion', 'beyond-elysium' ) }</th>
							<th>{ __( 'Or', 'beyond-elysium' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ preview.flagged_traits.map( ( trait, i ) => {
							const key = keyFor( trait, i );
							const resolution = traitResolutions[ key ];
							const keptCustom = resolution?.action === 'keep_custom';
							const addToCatalog = resolution?.add_to_catalog === true;
							const chosen = resolution?.suggestion_name ?? '';
							// UI-only gate; the REST layer re-checks be_manage_schemas server-side.
							const canManageSchemas = window.beyondElysium?.capabilities?.be_manage_schemas ?? false;
							return (
								<tr key={ key }>
									<td>{ trait.character }</td>
									<td>{ trait.block }</td>
									<td>{ trait.raw }</td>
									<td>
										<select
											value={ chosen }
											aria-label={ sprintf(
												/* translators: 1: character name, 2: raw value from the imported file, 3: schema block name */
												__( 'Suggested replacement for %1$s: "%2$s" in %3$s', 'beyond-elysium' ),
												trait.character,
												trait.raw,
												trait.block
											) }
											disabled={ keptCustom }
											onChange={ ( e ) => {
												const value = e.target.value;
												onTraitResolutionChange(
													key,
													value
														? {
																character: trait.character,
																block: trait.block,
																raw: trait.raw,
																action: 'apply_suggestion',
																suggestion_name: value,
														  }
														: null
												);
											} }
										>
											<option value="">{ __( 'Choose…', 'beyond-elysium' ) }</option>
											{ trait.suggestions.map( ( s ) => (
												<option key={ s } value={ s }>
													{ s }
												</option>
											) ) }
										</select>
									</td>
									<td>
										{ /* Escape hatch when the fuzzy suggestion is wrong: keeps the raw value instead of forcing a match. */ }
										<label className="be-import-preview__keep-custom">
											<input
												type="checkbox"
												checked={ keptCustom }
												onChange={ ( e ) =>
													onTraitResolutionChange(
														key,
														e.target.checked
															? {
																	character: trait.character,
																	block: trait.block,
																	raw: trait.raw,
																	action: 'keep_custom',
															  }
															: null
													)
												}
											/>
											{ ' ' }{ __( 'Keep as written', 'beyond-elysium' ) }
										</label>
										{ /* Writes to this chronicle's own schema block fork, never the shared global catalog. */ }
										{ keptCustom && canManageSchemas && (
											<label className="be-import-preview__keep-custom">
												<input
													type="checkbox"
													checked={ addToCatalog }
													onChange={ ( e ) =>
														onTraitResolutionChange( key, {
															character: trait.character,
															block: trait.block,
															raw: trait.raw,
															action: 'keep_custom',
															add_to_catalog: e.target.checked,
														} )
													}
												/>
												{ ' ' }{ __( 'Also add to catalog', 'beyond-elysium' ) }
											</label>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }

			<h4>{ sprintf( __( 'Unresolved (%d)', 'beyond-elysium' ), preview.unresolved.length ) }</h4>
			{ preview.unresolved.length === 0 ? (
				<p>{ __( 'None - nothing is blocking a commit on trait resolution grounds.', 'beyond-elysium' ) }</p>
			) : (
				<>
					<p className="be-import-preview__blocking-note">
						{ __( 'These must be resolved before a commit can proceed (Step 6h).', 'beyond-elysium' ) }
					</p>
					<ul className="be-import-preview__unresolved">
						{ preview.unresolved.map( ( trait, i ) => {
							const key = keyFor( trait, i );
							const resolution = traitResolutions[ key ];
							const keptCustom = resolution?.action === 'keep_custom';
							const addToCatalog = resolution?.add_to_catalog === true;
							// UI-only gate; a request without real capability is ignored server-side, not trusted.
							const canManageSchemas = window.beyondElysium?.capabilities?.be_manage_schemas ?? false;
							return (
								<li key={ i }>
									{ createInterpolateElement(
										sprintf(
											/* translators: 1: raw value from the imported file, 2: schema block name */
											__( '<name/>: "%1$s" in %2$s', 'beyond-elysium' ),
											trait.raw,
											trait.block
										),
										{ name: <strong>{ trait.character }</strong> }
									) }
									{ trait.reason === 'ambiguous' && <span> { __( '(matches more than one block)', 'beyond-elysium' ) }</span> }
									{ /* No catalog match exists at all, so the only choice offered is keeping it as written. */ }
									{ ' ' }
									<label className="be-import-preview__keep-custom">
										<input
											type="checkbox"
											checked={ keptCustom }
											onChange={ ( e ) =>
												onTraitResolutionChange(
													key,
													e.target.checked
														? {
																character: trait.character,
																block: trait.block,
																raw: trait.raw,
																action: 'keep_custom',
														  }
														: null
												)
											}
										/>
										{ ' ' }{ __( 'Keep as written, unmatched', 'beyond-elysium' ) }
									</label>
									{ /* Only offered once "keep as written" is checked, and only to someone who can manage schemas. */ }
									{ keptCustom && canManageSchemas && (
										<label className="be-import-preview__keep-custom">
											<input
												type="checkbox"
												checked={ addToCatalog }
												onChange={ ( e ) =>
													onTraitResolutionChange( key, {
														character: trait.character,
														block: trait.block,
														raw: trait.raw,
														action: 'keep_custom',
														add_to_catalog: e.target.checked,
													} )
												}
											/>
											{ ' ' }{ __( 'Also add to catalog (so future imports match it automatically)', 'beyond-elysium' ) }
										</label>
									) }
								</li>
							);
						} ) }
					</ul>
				</>
			) }

			<h4>{ sprintf( __( 'Players Needing a Match (%d)', 'beyond-elysium' ), preview.players_needing_match.length ) }</h4>
			{ preview.players_needing_match.length === 0 ? (
				<p>{ __( 'Every player matched an existing WordPress user by email.', 'beyond-elysium' ) }</p>
			) : (
				<ul className="be-import-preview__players">
					{ preview.players_needing_match.map( ( player, i ) => (
						<li key={ i }>
							<strong>{ player.gv_name }</strong>
							{ player.gv_email && <span> ({ player.gv_email })</span> }
							{ player.suggestions.length > 0 ? (
								<span> { sprintf( __( '— possibly %s', 'beyond-elysium' ), player.suggestions.map( ( s ) => s.display_name ).join( ', ' ) ) }</span>
							) : (
								<span> { __( '— no match found', 'beyond-elysium' ) }</span>
							) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

export default ImportPreview;
