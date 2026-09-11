/**
 * Editor for a template's section layout.
 *
 * Renders an add/edit/remove table for layout.sections[], each row
 * covering block, title, width, column, order, display-type override, and
 * collapsed state, plus a column-count control for the overall layout.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { DisplayType } from '../../lib/displayTrait';
import type { CrossBlockRef, SchemaBlock, TemplateLayout, TemplateLayoutSection } from '../../types';
import './Admin.css';

export interface TemplateLayoutEditorProps {
	layout: TemplateLayout;
	onChange: ( layout: TemplateLayout ) => void;
}

const DISPLAY_TYPES: DisplayType[] = [
	'simple',
	'multiplier',
	'multiplier_dot',
	'dot',
	'cost',
	'note_only',
	'cost_only',
	'dot_separate',
	'simple_dots',
	'simple_number',
	'simple_note',
];

const WIDTHS: Array<TemplateLayoutSection[ 'width' ]> = [ 'third', 'half', 'full' ];

/**
 * Renders the layout editor for one template. Each section row picks a
 * schema block (sourced from the live block list), a title with optional
 * cross-block title references, a column/order/width placement, a display
 * override, and a collapsed flag, with add/remove controls for both
 * sections and title references.
 */
export function TemplateLayoutEditor( { layout, onChange }: TemplateLayoutEditorProps ) {
	const [ blocks, setBlocks ] = useState<SchemaBlock[]>( [] );

	useEffect( () => {
		// Fetches up to 100 schema blocks (the REST route's max page size) for the picker.
		api.schemaBlocks.list( { per_page: 100 } ).then( setBlocks ).catch( () => setBlocks( [] ) );
	}, [] );

	const sections = layout.sections ?? [];
	const columns = layout.columns ?? 3;

	function updateSection( index: number, patch: Partial<TemplateLayoutSection> ) {
		const next = sections.map( ( s, i ) => ( i === index ? { ...s, ...patch } : s ) );
		onChange( { ...layout, sections: next } );
	}

	function addSection() {
		const nextOrder = sections.filter( ( s ) => s.column === 1 ).length + 1;
		onChange( {
			...layout,
			sections: [
				...sections,
				{ block_slug: '', column: 1, order: nextOrder, title: '', display: null, collapsed: false },
			],
		} );
	}

	function removeSection( index: number ) {
		onChange( { ...layout, sections: sections.filter( ( _, i ) => i !== index ) } );
	}

	function updateTitleRef( sectionIndex: number, refIndex: number, patch: Partial<CrossBlockRef> ) {
		const section = sections[ sectionIndex ];
		const refs = ( section.title_refs ?? [] ).map( ( r, i ) => ( i === refIndex ? { ...r, ...patch } : r ) );
		updateSection( sectionIndex, { title_refs: refs } );
	}

	function addTitleRef( sectionIndex: number ) {
		const section = sections[ sectionIndex ];
		updateSection( sectionIndex, {
			title_refs: [ ...( section.title_refs ?? [] ), { block_slug: '', field: '' } ],
		} );
	}

	function removeTitleRef( sectionIndex: number, refIndex: number ) {
		const section = sections[ sectionIndex ];
		const refs = ( section.title_refs ?? [] ).filter( ( _, i ) => i !== refIndex );
		updateSection( sectionIndex, { title_refs: refs.length > 0 ? refs : undefined } );
	}

	return (
		<div className="be-def-editor">
			<div className="be-def-editor__section">
				<h3>{ __( 'Global settings', 'beyond-elysium' ) }</h3>
				<div className="be-def-editor__flags">
					<label>
						{ __( 'Columns', 'beyond-elysium' ) }{ ' ' }
						<input
							type="number"
							min={ 1 }
							max={ 4 }
							value={ columns }
							onChange={ ( e ) => onChange( { ...layout, columns: Number( e.target.value ) } ) }
						/>
					</label>
				</div>

				<h3>{ sprintf( __( 'Sections (%d)', 'beyond-elysium' ), sections.length ) }</h3>
				<table className="be-def-editor__table">
					<thead>
						<tr>
							<th>{ __( 'Block', 'beyond-elysium' ) }</th>
							<th>{ __( 'Title', 'beyond-elysium' ) }</th>
							<th>{ __( 'Width', 'beyond-elysium' ) }</th>
							<th>{ __( 'Column', 'beyond-elysium' ) }</th>
							<th>{ __( 'Order', 'beyond-elysium' ) }</th>
							<th>{ __( 'Display override', 'beyond-elysium' ) }</th>
							<th>{ __( 'Collapsed', 'beyond-elysium' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ sections.map( ( section, i ) => (
							<tr key={ i }>
								<td>
									<select
										aria-label={ sprintf( __( 'Block for %s', 'beyond-elysium' ), section.title ) }
										value={ section.block_slug }
										onChange={ ( e ) => updateSection( i, { block_slug: e.target.value } ) }
									>
										<option value="">{ __( 'Select a block…', 'beyond-elysium' ) }</option>
										{ blocks.map( ( b ) => (
											<option key={ b.slug } value={ b.slug }>
												{ b.name } ({ b.slug })
											</option>
										) ) }
									</select>
								</td>
								<td>
									<input
										type="text"
										aria-label={ sprintf( __( 'Title for section %d', 'beyond-elysium' ), i + 1 ) }
										value={ section.title }
										onChange={ ( e ) => updateSection( i, { title: e.target.value } ) }
									/>

									{ /* Optional title references append resolved field values to the section title. */ }
									<div className="be-def-editor__title-refs">
										{ ( section.title_refs ?? [] ).map( ( ref, ri ) => (
											<div className="be-def-editor__inline-row" key={ ri }>
												<select
													value={ ref.block_slug }
													onChange={ ( e ) => updateTitleRef( i, ri, { block_slug: e.target.value } ) }
												>
													<option value="">{ __( 'Block…', 'beyond-elysium' ) }</option>
													{ blocks.map( ( b ) => (
														<option key={ b.slug } value={ b.slug }>
															{ b.slug }
														</option>
													) ) }
												</select>
												<input
													type="text"
													placeholder={ __( 'Field name', 'beyond-elysium' ) }
													value={ ref.field }
													onChange={ ( e ) => updateTitleRef( i, ri, { field: e.target.value } ) }
												/>
												<button type="button" onClick={ () => removeTitleRef( i, ri ) }>
													×
												</button>
											</div>
										) ) }
										<button type="button" onClick={ () => addTitleRef( i ) }>
											{ __( '+ Title reference', 'beyond-elysium' ) }
										</button>
									</div>
								</td>
								<td>
									<select
										aria-label={ sprintf( __( 'Width for %s', 'beyond-elysium' ), section.title ) }
										value={ section.width ?? 'third' }
										onChange={ ( e ) => updateSection( i, { width: e.target.value as TemplateLayoutSection[ 'width' ] } ) }
									>
										{ WIDTHS.map( ( w ) => (
											<option key={ w } value={ w }>
												{ w }
											</option>
										) ) }
									</select>
								</td>
								<td>
									<input
										type="number"
										aria-label={ sprintf( __( 'Column for %s', 'beyond-elysium' ), section.title ) }
										min={ 1 }
										max={ columns }
										value={ section.column }
										onChange={ ( e ) => updateSection( i, { column: Number( e.target.value ) } ) }
									/>
								</td>
								<td>
									<input
										type="number"
										aria-label={ sprintf( __( 'Order for %s', 'beyond-elysium' ), section.title ) }
										value={ section.order }
										onChange={ ( e ) => updateSection( i, { order: Number( e.target.value ) } ) }
									/>
								</td>
								<td>
									<select
										aria-label={ sprintf( __( 'Display override for %s', 'beyond-elysium' ), section.title ) }
										value={ section.display ?? '' }
										onChange={ ( e ) => updateSection( i, { display: ( e.target.value || null ) as DisplayType | null } ) }
									>
										<option value="">{ __( 'Block default', 'beyond-elysium' ) }</option>
										{ DISPLAY_TYPES.map( ( d ) => (
											<option key={ d } value={ d }>
												{ d }
											</option>
										) ) }
									</select>
								</td>
								<td>
									<input
										type="checkbox"
										aria-label={ sprintf( __( 'Collapsed for %s', 'beyond-elysium' ), section.title ) }
										checked={ section.collapsed }
										onChange={ ( e ) => updateSection( i, { collapsed: e.target.checked } ) }
									/>
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
		</div>
	);
}

export default TemplateLayoutEditor;
