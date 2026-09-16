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
	DuplicateCharacter,
	ImportPreview as ImportPreviewData,
	SheetChange,
	TraitResolution,
} from '../../types/import';
import { duplicateActionsFor } from '../../lib/duplicateActions';
import { keyFor } from '../../lib/importDecisions';
import { groupSheetChanges, sheetChangeKind } from '../../lib/sheetChanges';
import './ImportPreview.css';

export interface ImportPreviewProps {
	preview: ImportPreviewData;
	traitResolutions: Record< string, TraitResolution >;
	onTraitResolutionChange: (
		key: string,
		resolution: TraitResolution | null
	) => void;
	duplicateActions: Record< string, DuplicateAction >;
	onDuplicateActionChange: (
		character: string,
		action: DuplicateAction | null
	) => void;
	/** Keyed by "{type}:{name}", matching DuplicateWorldObject. */
	worldObjectActions: Record< string, DuplicateAction >;
	onWorldObjectActionChange: (
		key: string,
		action: DuplicateAction | null
	) => void;
	/** False when accepting a transfer, whose one character cannot be skipped. Default true. */
	allowSkip?: boolean;
}

const CHARACTER_ACTION_LABELS: Record< DuplicateAction, string > = {
	skip: __( 'Skip (keep the existing character as-is)', 'beyond-elysium' ),
	overwrite: __(
		'Overwrite (update the existing character in place)',
		'beyond-elysium'
	),
	import_as_new: __(
		'Import as a new, separate character',
		'beyond-elysium'
	),
};

const MATCH_LABELS: Record< DuplicateCharacter[ 'matched_by' ], string > = {
	uuid: __(
		'The same character, already in this chronicle',
		'beyond-elysium'
	),
	uuid_elsewhere: __(
		'The same character, in another chronicle on this site - it can be copied, not overwritten',
		'beyond-elysium'
	),
	name: __( 'Same name - possibly a different character', 'beyond-elysium' ),
};

const COUNT_LABELS: Record< string, string > = {
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
 * What differs between a sheet already in this chronicle and the same character arriving, so the
 * Storyteller can see what Overwrite would change before choosing it (1.0.0-review F-044).
 */
function SheetChanges( {
	character,
	changes,
}: {
	character: string;
	changes: SheetChange[];
} ) {
	const absent = (
		<span title={ __( 'Not on this sheet', 'beyond-elysium' ) }>—</span>
	);
	return (
		<div className="be-import-preview__changes">
			<h5>
				{ sprintf(
					// translators: %s: character name.
					__(
						'%s: what differs from the sheet already here',
						'beyond-elysium'
					),
					character
				) }
			</h5>
			{ changes.length === 0 ? (
				<p>
					{ __(
						'Nothing - the arriving sheet matches the one already here.',
						'beyond-elysium'
					) }
				</p>
			) : (
				<div className="be-table-box">
					<table className="be-import-preview__traits be-responsive-table">
						<thead>
							<tr>
								<th>{ __( 'Section', 'beyond-elysium' ) }</th>
								<th>{ __( 'Entry', 'beyond-elysium' ) }</th>
								<th>{ __( 'Here now', 'beyond-elysium' ) }</th>
								<th>{ __( 'Arriving', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ groupSheetChanges( changes ).map( ( group ) =>
								group.changes.map( ( change, index ) => (
									<tr
										key={ `${ group.section }|${ change.entry }` }
										className={ `be-import-preview__change--${ sheetChangeKind(
											change
										) }` }
									>
										<td
											data-label={ __(
												'Section',
												'beyond-elysium'
											) }
										>
											{ index === 0 ? group.section : '' }
										</td>
										<td
											data-label={ __(
												'Entry',
												'beyond-elysium'
											) }
										>
											{ change.entry }
										</td>
										<td
											data-label={ __(
												'Here now',
												'beyond-elysium'
											) }
										>
											{ change.here ?? absent }
										</td>
										<td
											data-label={ __(
												'Arriving',
												'beyond-elysium'
											) }
										>
											{ change.arriving ?? absent }
										</td>
									</tr>
								) )
							) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
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
	allowSkip = true,
}: ImportPreviewProps ) {
	return (
		<div className="be-import-preview">
			<h4>{ __( 'Counts', 'beyond-elysium' ) }</h4>
			<div className="be-table-box">
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
			</div>
			{ Object.values( preview.counts ).every( ( c ) => c === 0 ) && (
				<p>
					{ __(
						'This file contains no importable records.',
						'beyond-elysium'
					) }
				</p>
			) }

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

			<h4>
				{ sprintf(
					/* translators: %d: number of duplicate characters found */
					__( 'Duplicate Characters (%d)', 'beyond-elysium' ),
					preview.duplicates.length
				) }
			</h4>
			{ preview.duplicates.length === 0 ? (
				<p>
					{ __(
						'None - no character in this file is already here.',
						'beyond-elysium'
					) }
				</p>
			) : (
				<>
					<p className="be-import-preview__blocking-note">
						{ __(
							'Each of these must be resolved before a commit can proceed.',
							'beyond-elysium'
						) }
					</p>
					<div className="be-table-box">
						<table className="be-import-preview__duplicates be-responsive-table">
							<thead>
								<tr>
									<th>
										{ __( 'Character', 'beyond-elysium' ) }
									</th>
									<th>
										{ __(
											'Already here as',
											'beyond-elysium'
										) }
									</th>
									<th>
										{ __( 'Decision', 'beyond-elysium' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ preview.duplicates.map( ( dup ) => (
									<tr key={ dup.character }>
										<td
											data-label={ __(
												'Character',
												'beyond-elysium'
											) }
										>
											{ dup.character }
										</td>
										<td
											data-label={ __(
												'Already here as',
												'beyond-elysium'
											) }
										>
											{ MATCH_LABELS[ dup.matched_by ] }
										</td>
										<td
											data-label={ __(
												'Decision',
												'beyond-elysium'
											) }
										>
											<select
												value={
													duplicateActions[
														dup.character
													] ?? ''
												}
												aria-label={ sprintf(
													/* translators: %s: the duplicate character's own name */
													__(
														'Decision for duplicate character %s',
														'beyond-elysium'
													),
													dup.character
												) }
												onChange={ ( e ) =>
													onDuplicateActionChange(
														dup.character,
														e.target.value
															? ( e.target
																	.value as DuplicateAction )
															: null
													)
												}
											>
												<option value="">
													{ __(
														'Choose…',
														'beyond-elysium'
													) }
												</option>
												{ duplicateActionsFor(
													dup.matched_by,
													allowSkip,
													dup.overwrite_allowed ??
														true
												).map( ( action ) => (
													<option
														key={ action }
														value={ action }
													>
														{
															CHARACTER_ACTION_LABELS[
																action
															]
														}
													</option>
												) ) }
											</select>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					{ preview.duplicates.map(
						( dup ) =>
							dup.changes && (
								<SheetChanges
									key={ `changes-${ dup.character }` }
									character={ dup.character }
									changes={ dup.changes }
								/>
							)
					) }
					{ preview.duplicates.map(
						( dup ) =>
							dup.overwrite_allowed === false && (
								<p
									key={ `owner-${ dup.character }` }
									className="be-import-tool__blocking-note"
								>
									{ sprintf(
										/* translators: 1: character name, 2: who the existing character belongs to */
										__(
											'%1$s is already here, and it belongs to %2$s. Import it as a new character, or refuse the sheet.',
											'beyond-elysium'
										),
										dup.character,
										dup.existing_owner ??
											__(
												'someone else',
												'beyond-elysium'
											)
									) }
								</p>
							)
					) }
				</>
			) }

			<h4>
				{ sprintf(
					/* translators: %d: number of duplicate items, locations, or rotes found */
					__(
						'Duplicate Items/Locations/Rotes (%d)',
						'beyond-elysium'
					),
					preview.world_object_duplicates.length
				) }
			</h4>
			{ preview.world_object_duplicates.length === 0 ? (
				<p>
					{ __(
						'None - nothing in this file already exists in this chronicle by name.',
						'beyond-elysium'
					) }
				</p>
			) : (
				<>
					<p className="be-import-preview__blocking-note">
						{ __(
							'Each of these must be resolved before a commit can proceed.',
							'beyond-elysium'
						) }
					</p>
					<div className="be-table-box">
						<table className="be-import-preview__duplicates be-responsive-table">
							<thead>
								<tr>
									<th>{ __( 'Type', 'beyond-elysium' ) }</th>
									<th>{ __( 'Name', 'beyond-elysium' ) }</th>
									<th>
										{ __( 'Decision', 'beyond-elysium' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ preview.world_object_duplicates.map(
									( dup ) => {
										const key = `${ dup.type }:${ dup.name }`;
										return (
											<tr key={ key }>
												<td
													data-label={ __(
														'Type',
														'beyond-elysium'
													) }
												>
													{ dup.type }
												</td>
												<td
													data-label={ __(
														'Name',
														'beyond-elysium'
													) }
												>
													{ dup.name }
												</td>
												<td
													data-label={ __(
														'Decision',
														'beyond-elysium'
													) }
												>
													<select
														value={
															worldObjectActions[
																key
															] ?? ''
														}
														aria-label={ sprintf(
															/* translators: 1: object type (item, location, or rote), 2: object name */
															__(
																'Decision for duplicate %1$s "%2$s"',
																'beyond-elysium'
															),
															dup.type,
															dup.name
														) }
														onChange={ ( e ) =>
															onWorldObjectActionChange(
																key,
																e.target.value
																	? ( e.target
																			.value as DuplicateAction )
																	: null
															)
														}
													>
														<option value="">
															{ __(
																'Choose…',
																'beyond-elysium'
															) }
														</option>
														<option value="skip">
															{ __(
																'Skip (keep the existing one as-is)',
																'beyond-elysium'
															) }
														</option>
														<option value="overwrite">
															{ __(
																'Overwrite (update the existing one in place)',
																'beyond-elysium'
															) }
														</option>
														<option value="import_as_new">
															{ __(
																'Import as new, a separate entry',
																'beyond-elysium'
															) }
														</option>
													</select>
												</td>
											</tr>
										);
									}
								) }
							</tbody>
						</table>
					</div>
				</>
			) }

			<h4>
				{ sprintf(
					/* translators: %d: number of flagged traits needing review */
					__( 'Flagged Traits (%d)', 'beyond-elysium' ),
					preview.flagged_traits.length
				) }
			</h4>
			{ preview.flagged_traits.length === 0 ? (
				<p>
					{ __(
						'None - every trait matched exactly or by normalization.',
						'beyond-elysium'
					) }
				</p>
			) : (
				<div className="be-table-box">
					<table className="be-import-preview__traits be-responsive-table">
						<thead>
							<tr>
								<th>{ __( 'Character', 'beyond-elysium' ) }</th>
								<th>{ __( 'Block', 'beyond-elysium' ) }</th>
								<th>{ __( 'Raw Value', 'beyond-elysium' ) }</th>
								<th>
									{ __( 'Suggestion', 'beyond-elysium' ) }
								</th>
								<th>{ __( 'Or', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ preview.flagged_traits.map( ( trait, i ) => {
								const key = keyFor( trait, i );
								const resolution = traitResolutions[ key ];
								const keptCustom =
									resolution?.action === 'keep_custom';
								const addToCatalog =
									resolution?.add_to_catalog === true;
								const chosen =
									resolution?.suggestion_name ?? '';
								// UI-only gate; the REST layer re-checks be_manage_schemas server-side.
								const canManageSchemas =
									window.beyondElysium?.capabilities
										?.be_manage_schemas ?? false;
								return (
									<tr key={ key }>
										<td
											data-label={ __(
												'Character',
												'beyond-elysium'
											) }
										>
											{ trait.character }
										</td>
										<td
											data-label={ __(
												'Block',
												'beyond-elysium'
											) }
										>
											{ trait.block }
										</td>
										<td
											data-label={ __(
												'Raw Value',
												'beyond-elysium'
											) }
										>
											{ trait.raw }
										</td>
										<td
											data-label={ __(
												'Suggestion',
												'beyond-elysium'
											) }
										>
											<select
												value={ chosen }
												aria-label={ sprintf(
													/* translators: 1: character name, 2: raw value from the imported file, 3: schema block name */
													__(
														'Suggested replacement for %1$s: "%2$s" in %3$s',
														'beyond-elysium'
													),
													trait.character,
													trait.raw,
													trait.block
												) }
												disabled={ keptCustom }
												onChange={ ( e ) => {
													const value =
														e.target.value;
													onTraitResolutionChange(
														key,
														value
															? {
																	character:
																		trait.character,
																	block: trait.block,
																	raw: trait.raw,
																	action: 'apply_suggestion',
																	suggestion_name:
																		value,
															  }
															: null
													);
												} }
											>
												<option value="">
													{ __(
														'Choose…',
														'beyond-elysium'
													) }
												</option>
												{ trait.suggestions.map(
													( s ) => (
														<option
															key={ s }
															value={ s }
														>
															{ s }
														</option>
													)
												) }
											</select>
										</td>
										<td
											data-label={ __(
												'Or',
												'beyond-elysium'
											) }
										>
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
																		character:
																			trait.character,
																		block: trait.block,
																		raw: trait.raw,
																		action: 'keep_custom',
																  }
																: null
														)
													}
												/>{ ' ' }
												{ __(
													'Keep as written',
													'beyond-elysium'
												) }
											</label>
											{ /* Writes to this chronicle's own schema block fork, never the shared global catalog. */ }
											{ keptCustom &&
												canManageSchemas && (
													<label className="be-import-preview__keep-custom">
														<input
															type="checkbox"
															checked={
																addToCatalog
															}
															onChange={ ( e ) =>
																onTraitResolutionChange(
																	key,
																	{
																		character:
																			trait.character,
																		block: trait.block,
																		raw: trait.raw,
																		action: 'keep_custom',
																		add_to_catalog:
																			e
																				.target
																				.checked,
																	}
																)
															}
														/>{ ' ' }
														{ __(
															'Also add to catalog',
															'beyond-elysium'
														) }
													</label>
												) }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			<h4>
				{ sprintf(
					/* translators: %d: number of unresolved traits blocking the commit */
					__( 'Unresolved (%d)', 'beyond-elysium' ),
					preview.unresolved.length
				) }
			</h4>
			{ preview.unresolved.length === 0 ? (
				<p>
					{ __(
						'None - nothing is blocking a commit on trait resolution grounds.',
						'beyond-elysium'
					) }
				</p>
			) : (
				<>
					<p className="be-import-preview__blocking-note">
						{ __(
							'These must be resolved before a commit can proceed (Step 6h).',
							'beyond-elysium'
						) }
					</p>
					<ul className="be-import-preview__unresolved">
						{ preview.unresolved.map( ( trait, i ) => {
							const key = keyFor( trait, i );
							const resolution = traitResolutions[ key ];
							const keptCustom =
								resolution?.action === 'keep_custom';
							const addToCatalog =
								resolution?.add_to_catalog === true;
							// UI-only gate; a request without real capability is ignored server-side, not trusted.
							const canManageSchemas =
								window.beyondElysium?.capabilities
									?.be_manage_schemas ?? false;
							return (
								<li key={ i }>
									{ createInterpolateElement(
										sprintf(
											/* translators: 1: raw value from the imported file, 2: schema block name */
											__(
												'<name/>: "%1$s" in %2$s',
												'beyond-elysium'
											),
											trait.raw,
											trait.block
										),
										{
											name: (
												<strong>
													{ trait.character }
												</strong>
											),
										}
									) }
									{ trait.reason === 'ambiguous' && (
										<span>
											{ ' ' }
											{ __(
												'(matches more than one block)',
												'beyond-elysium'
											) }
										</span>
									) }
									{ /* No catalog match exists at all, so the only choice offered is keeping it as written. */ }{ ' ' }
									<label className="be-import-preview__keep-custom">
										<input
											type="checkbox"
											checked={ keptCustom }
											onChange={ ( e ) =>
												onTraitResolutionChange(
													key,
													e.target.checked
														? {
																character:
																	trait.character,
																block: trait.block,
																raw: trait.raw,
																action: 'keep_custom',
														  }
														: null
												)
											}
										/>{ ' ' }
										{ __(
											'Keep as written, unmatched',
											'beyond-elysium'
										) }
									</label>
									{ /* Only offered once "keep as written" is checked, and only to someone who can manage schemas. */ }
									{ keptCustom && canManageSchemas && (
										<label className="be-import-preview__keep-custom">
											<input
												type="checkbox"
												checked={ addToCatalog }
												onChange={ ( e ) =>
													onTraitResolutionChange(
														key,
														{
															character:
																trait.character,
															block: trait.block,
															raw: trait.raw,
															action: 'keep_custom',
															add_to_catalog:
																e.target
																	.checked,
														}
													)
												}
											/>{ ' ' }
											{ __(
												'Also add to catalog (so future imports match it automatically)',
												'beyond-elysium'
											) }
										</label>
									) }
								</li>
							);
						} ) }
					</ul>
				</>
			) }

			<h4>
				{ sprintf(
					/* translators: %d: number of players needing an account match */
					__( 'Players Needing a Match (%d)', 'beyond-elysium' ),
					preview.players_needing_match.length
				) }
			</h4>
			{ preview.players_needing_match.length === 0 ? (
				<p>
					{ __(
						'Every player matched an existing WordPress user by email.',
						'beyond-elysium'
					) }
				</p>
			) : (
				<ul className="be-import-preview__players">
					{ preview.players_needing_match.map( ( player, i ) => (
						<li key={ i }>
							<strong>{ player.gv_name }</strong>
							{ player.gv_email && (
								<span> ({ player.gv_email })</span>
							) }
							{ player.suggestions.length > 0 ? (
								<span>
									{ ' ' }
									{ sprintf(
										/* translators: %s: comma-separated list of suggested matching player names */
										__( '— possibly %s', 'beyond-elysium' ),
										player.suggestions
											.map( ( s ) => s.display_name )
											.join( ', ' )
									) }
								</span>
							) : (
								<span>
									{ ' ' }
									{ __(
										'— no match found',
										'beyond-elysium'
									) }
								</span>
							) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

export default ImportPreview;
