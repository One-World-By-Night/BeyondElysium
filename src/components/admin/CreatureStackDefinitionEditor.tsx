/**
 * Editor for a creature stack's section list and creation rules.
 *
 * Renders an add/edit/remove table for stack_definition.sections[], each
 * row picking a schema block, label, display order, required flag, and
 * optional negative block. Creation rules remain editable as raw JSON
 * behind a collapsible toggle.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { CreationRules, SchemaBlock, StackDefinition, StackSection } from '../../types';
import './Admin.css';

export interface CreatureStackDefinitionEditorProps {
	stackDefinition: StackDefinition;
	creationRules: CreationRules;
	onChangeStackDefinition: ( d: StackDefinition ) => void;
	onChangeCreationRules: ( r: CreationRules ) => void;
}

/**
 * Renders the section list and creation-rules editor for one creature
 * stack. Section rows let staff choose a schema block (sourced from the
 * live block list), a label, display order, required flag, and optional
 * negative block, with add/remove controls. Creation rules are edited as
 * raw JSON behind a collapsible "advanced" panel that validates on apply.
 */
export function CreatureStackDefinitionEditor( {
	stackDefinition,
	creationRules,
	onChangeStackDefinition,
	onChangeCreationRules,
}: CreatureStackDefinitionEditorProps ) {
	const [ blocks, setBlocks ] = useState<SchemaBlock[]>( [] );
	const [ showRules, setShowRules ] = useState( false );
	const [ rulesJson, setRulesJson ] = useState( () => JSON.stringify( creationRules ?? {}, null, 2 ) );
	const [ rulesError, setRulesError ] = useState<string | null>( null );

	useEffect( () => {
		// Fetches up to 100 schema blocks (the REST route's max page size) for the picker.
		api.schemaBlocks.list( { per_page: 100 } ).then( setBlocks ).catch( () => setBlocks( [] ) );
	}, [] );

	const sections = stackDefinition.sections ?? [];

	function updateSection( index: number, patch: Partial<StackSection> ) {
		const next = sections.map( ( s, i ) => ( i === index ? { ...s, ...patch } : s ) );
		onChangeStackDefinition( { ...stackDefinition, sections: next } );
	}

	function addSection() {
		const nextOrder = sections.length > 0 ? Math.max( ...sections.map( ( s ) => s.display_order ) ) + 1 : 1;
		onChangeStackDefinition( {
			...stackDefinition,
			sections: [ ...sections, { block_slug: '', label: '', display_order: nextOrder, required: false } ],
		} );
	}

	function removeSection( index: number ) {
		onChangeStackDefinition( { ...stackDefinition, sections: sections.filter( ( _, i ) => i !== index ) } );
	}

	/**
	 * Parses the raw JSON textarea and, if valid, applies it as the new
	 * creation rules. Leaves the existing rules untouched and shows an error
	 * message when the input isn't valid JSON.
	 */
	function applyRules() {
		try {
			const parsed = JSON.parse( rulesJson );
			setRulesError( null );
			onChangeCreationRules( parsed );
		} catch {
			setRulesError( __( 'Not valid JSON - not applied.', 'beyond-elysium' ) );
		}
	}

	return (
		<div className="be-def-editor">
			<div className="be-def-editor__section">
				<h3>{ sprintf( __( 'Sections (%d)', 'beyond-elysium' ), sections.length ) }</h3>
				<table className="be-def-editor__table">
					<thead>
						<tr>
							<th>{ __( 'Block', 'beyond-elysium' ) }</th>
							<th>{ __( 'Label', 'beyond-elysium' ) }</th>
							<th>{ __( 'Order', 'beyond-elysium' ) }</th>
							<th>{ __( 'Required', 'beyond-elysium' ) }</th>
							<th>{ __( 'Negative block (optional)', 'beyond-elysium' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ sections.map( ( section, i ) => (
							<tr key={ i }>
								<td>
									<select value={ section.block_slug } onChange={ ( e ) => updateSection( i, { block_slug: e.target.value } ) }>
										<option value="">{ __( 'Select a block…', 'beyond-elysium' ) }</option>
										{ blocks.map( ( b ) => (
											<option key={ b.slug } value={ b.slug }>
												{ b.name } ({ b.slug })
											</option>
										) ) }
									</select>
								</td>
								<td>
									<input type="text" value={ section.label } onChange={ ( e ) => updateSection( i, { label: e.target.value } ) } />
								</td>
								<td>
									<input
										type="number"
										value={ section.display_order }
										onChange={ ( e ) => updateSection( i, { display_order: Number( e.target.value ) } ) }
									/>
								</td>
								<td>
									<input
										type="checkbox"
										checked={ section.required }
										onChange={ ( e ) => updateSection( i, { required: e.target.checked } ) }
									/>
								</td>
								<td>
									<select
										value={ section.negative_block_slug ?? '' }
										onChange={ ( e ) => updateSection( i, { negative_block_slug: e.target.value || undefined } ) }
									>
										<option value="">{ __( 'None', 'beyond-elysium' ) }</option>
										{ blocks.map( ( b ) => (
											<option key={ b.slug } value={ b.slug }>
												{ b.name } ({ b.slug })
											</option>
										) ) }
									</select>
								</td>
								<td>
									<button type="button" onClick={ () => removeSection( i ) }>
										{ __( 'Remove', 'beyond-elysium' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				<button type="button" onClick={ addSection }>
					{ __( '+ Add section', 'beyond-elysium' ) }
				</button>
			</div>

			<button type="button" className="be-def-editor__raw-toggle" onClick={ () => setShowRules( ! showRules ) }>
				{ sprintf(
					// translators: %s: "Hide" or "Show".
					__( '%s creation rules (advanced, JSON)', 'beyond-elysium' ),
					showRules ? __( 'Hide', 'beyond-elysium' ) : __( 'Show', 'beyond-elysium' )
				) }
			</button>
			{ showRules && (
				<div className="be-def-editor__raw">
					<textarea rows={ 10 } value={ rulesJson } onChange={ ( e ) => setRulesJson( e.target.value ) } />
					{ rulesError && <p className="be-admin__json-error">{ rulesError }</p> }
					<button type="button" onClick={ applyRules }>
						{ __( 'Apply JSON', 'beyond-elysium' ) }
					</button>
				</div>
			) }
		</div>
	);
}

export default CreatureStackDefinitionEditor;
