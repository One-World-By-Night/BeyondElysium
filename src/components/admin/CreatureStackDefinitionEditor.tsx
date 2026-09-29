/**
 * Editor for a creature stack's section list and creation rules.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import { errorMessage } from '../../lib/errorMessage';
import { InTypeTestEditor } from './InTypeTestEditor';
import { CreationRulesEditor } from './CreationRulesEditor';
import type {
	CreationRules,
	SchemaBlock,
	StackDefinition,
	StackSection,
} from '../../types';
import './Admin.css';

export interface CreatureStackDefinitionEditorProps {
	stackDefinition: StackDefinition;
	creationRules: CreationRules;
	onChangeStackDefinition: ( d: StackDefinition ) => void;
	onChangeCreationRules: ( r: CreationRules ) => void;
	/**
	 * Present only when this stack is being edited for a chronicle: a section hidden, shown, added or removed is
	 * saved at once, through that chronicle's own route.
	 */
	gameSlug?: string;
	stackSlug?: string;
	/**
	 * The book's own section slugs: a section named here can be hidden but never removed.
	 */
	bookSectionSlugs?: string[];
	/**
	 * Called after a section is hidden, shown, added or removed, so the parent can reload.
	 */
	onSectionsPersisted?: () => void;
}

/**
 * Renders the section list and creation-rules editor for one creature stack.
 */
export function CreatureStackDefinitionEditor( {
	stackDefinition,
	creationRules,
	onChangeStackDefinition,
	onChangeCreationRules,
	gameSlug,
	stackSlug,
	bookSectionSlugs,
	onSectionsPersisted,
}: CreatureStackDefinitionEditorProps ) {
	const [ blocks, setBlocks ] = useState< SchemaBlock[] >( [] );
	const [ savingSection, setSavingSection ] = useState< string | null >(
		null
	);
	const [ sectionError, setSectionError ] = useState< string | null >( null );
	const [ addingBlockSlug, setAddingBlockSlug ] = useState( '' );
	const [ addingLabel, setAddingLabel ] = useState( '' );

	useEffect( () => {
		// Fetches every schema block for the picker.
		everyPage( ( page ) =>
			api.schemaBlocks.listPaginated( { page, per_page: 100 } )
		)
			.then( setBlocks )
			.catch( () => setBlocks( [] ) );
	}, [] );

	const sections = stackDefinition.sections ?? [];
	const canPersist = !! ( gameSlug && stackSlug );

	function updateSection( index: number, patch: Partial< StackSection > ) {
		const next = sections.map( ( s, i ) =>
			i === index ? { ...s, ...patch } : s
		);
		onChangeStackDefinition( { ...stackDefinition, sections: next } );
	}

	function addSectionLocally() {
		const nextOrder =
			sections.length > 0
				? Math.max( ...sections.map( ( s ) => s.display_order ) ) + 1
				: 1;
		onChangeStackDefinition( {
			...stackDefinition,
			sections: [
				...sections,
				{
					block_slug: '',
					label: '',
					display_order: nextOrder,
					required: false,
				},
			],
		} );
	}

	function removeSectionLocally( index: number ) {
		onChangeStackDefinition( {
			...stackDefinition,
			sections: sections.filter( ( _, i ) => i !== index ),
		} );
	}

	async function toggleHidden( blockSlug: string, hidden: boolean ) {
		if ( ! gameSlug || ! stackSlug ) {
			return;
		}
		setSavingSection( blockSlug );
		setSectionError( null );
		try {
			await api.creatureStacks.updateSection(
				stackSlug,
				gameSlug,
				blockSlug,
				hidden
			);
			onSectionsPersisted?.();
		} catch ( err: unknown ) {
			setSectionError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingSection( null );
		}
	}

	async function addSectionRemotely() {
		if ( ! gameSlug || ! stackSlug || ! addingBlockSlug ) {
			return;
		}
		setSavingSection( addingBlockSlug );
		setSectionError( null );
		try {
			await api.creatureStacks.addSection(
				stackSlug,
				gameSlug,
				addingBlockSlug,
				addingLabel
			);
			setAddingBlockSlug( '' );
			setAddingLabel( '' );
			onSectionsPersisted?.();
		} catch ( err: unknown ) {
			setSectionError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingSection( null );
		}
	}

	async function removeSectionRemotely( blockSlug: string ) {
		if ( ! gameSlug || ! stackSlug ) {
			return;
		}
		setSavingSection( blockSlug );
		setSectionError( null );
		try {
			await api.creatureStacks.removeSection(
				stackSlug,
				gameSlug,
				blockSlug
			);
			onSectionsPersisted?.();
		} catch ( err: unknown ) {
			setSectionError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingSection( null );
		}
	}

	return (
		<div className="be-def-editor">
			<div className="be-def-editor__section">
				<h3>
					{ sprintf(
						/* translators: %d: number of sections in this creature stack */
						__( 'Sections (%d)', 'beyond-elysium' ),
						sections.length
					) }
				</h3>
				{ sectionError && (
					<p className="be-admin__json-error">{ sectionError }</p>
				) }
				<table className="be-def-editor__table">
					<thead>
						<tr>
							<th>{ __( 'Block', 'beyond-elysium' ) }</th>
							<th>{ __( 'Label', 'beyond-elysium' ) }</th>
							<th>{ __( 'Order', 'beyond-elysium' ) }</th>
							<th>{ __( 'Required', 'beyond-elysium' ) }</th>
							<th>
								{ __(
									'Negative block (optional)',
									'beyond-elysium'
								) }
							</th>
							<th>{ __( 'In-type', 'beyond-elysium' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ sections.map( ( section, i ) => {
							const isBookSection = (
								bookSectionSlugs ?? []
							).includes( section.block_slug );
							return (
								<tr key={ i }>
									<td>
										<select
											value={ section.block_slug }
											onChange={ ( e ) =>
												updateSection( i, {
													block_slug: e.target.value,
												} )
											}
										>
											<option value="">
												{ __(
													'Select a block…',
													'beyond-elysium'
												) }
											</option>
											{ blocks.map( ( b ) => (
												<option
													key={ b.slug }
													value={ b.slug }
												>
													{ b.name } ({ b.slug })
												</option>
											) ) }
										</select>
									</td>
									<td>
										<input
											type="text"
											value={ section.label }
											onChange={ ( e ) =>
												updateSection( i, {
													label: e.target.value,
												} )
											}
										/>
									</td>
									<td>
										<input
											type="number"
											value={ section.display_order }
											onChange={ ( e ) =>
												updateSection( i, {
													display_order: Number(
														e.target.value
													),
												} )
											}
										/>
									</td>
									<td>
										<input
											type="checkbox"
											checked={ section.required }
											onChange={ ( e ) =>
												updateSection( i, {
													required: e.target.checked,
												} )
											}
										/>
									</td>
									<td>
										<select
											value={
												section.negative_block_slug ??
												''
											}
											onChange={ ( e ) =>
												updateSection( i, {
													negative_block_slug:
														e.target.value ||
														undefined,
												} )
											}
										>
											<option value="">
												{ __(
													'None',
													'beyond-elysium'
												) }
											</option>
											{ blocks.map( ( b ) => (
												<option
													key={ b.slug }
													value={ b.slug }
												>
													{ b.name } ({ b.slug })
												</option>
											) ) }
										</select>
									</td>
									<td>
										<InTypeTestEditor
											label={
												section.label ||
												section.block_slug
											}
											value={ section.in_type }
											onSave={ ( tests ) =>
												updateSection( i, {
													in_type:
														tests.length > 0
															? tests
															: undefined,
												} )
											}
										/>
									</td>
									<td>
										{ canPersist ? (
											<>
												<label>
													<input
														type="checkbox"
														checked={
															!! section.hidden
														}
														disabled={
															savingSection ===
															section.block_slug
														}
														onChange={ ( e ) =>
															toggleHidden(
																section.block_slug,
																e.target.checked
															)
														}
													/>{ ' ' }
													{ __(
														'Hidden',
														'beyond-elysium'
													) }
												</label>
												{ ! isBookSection && (
													<button
														type="button"
														disabled={
															savingSection ===
															section.block_slug
														}
														onClick={ () =>
															removeSectionRemotely(
																section.block_slug
															)
														}
													>
														{ __(
															'Remove',
															'beyond-elysium'
														) }
													</button>
												) }
											</>
										) : (
											<button
												type="button"
												onClick={ () =>
													removeSectionLocally( i )
												}
											>
												{ __(
													'Remove',
													'beyond-elysium'
												) }
											</button>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
				{ canPersist ? (
					<div className="be-in-type-editor__test-row">
						<select
							aria-label={ __(
								'Block to add',
								'beyond-elysium'
							) }
							value={ addingBlockSlug }
							onChange={ ( e ) =>
								setAddingBlockSlug( e.target.value )
							}
						>
							<option value="">
								{ __( 'Select a block…', 'beyond-elysium' ) }
							</option>
							{ blocks.map( ( b ) => (
								<option key={ b.slug } value={ b.slug }>
									{ b.name } ({ b.slug })
								</option>
							) ) }
						</select>
						<input
							type="text"
							aria-label={ __(
								'Label (optional, the block’s own name otherwise)',
								'beyond-elysium'
							) }
							placeholder={ __( 'Label', 'beyond-elysium' ) }
							value={ addingLabel }
							onChange={ ( e ) =>
								setAddingLabel( e.target.value )
							}
						/>
						<button
							type="button"
							disabled={ ! addingBlockSlug }
							onClick={ addSectionRemotely }
						>
							{ __( '+ Add section', 'beyond-elysium' ) }
						</button>
					</div>
				) : (
					<button type="button" onClick={ addSectionLocally }>
						{ __( '+ Add section', 'beyond-elysium' ) }
					</button>
				) }
			</div>

			<CreationRulesEditor
				value={ creationRules ?? {} }
				onChange={ onChangeCreationRules }
			/>
		</div>
	);
}

export default CreatureStackDefinitionEditor;
