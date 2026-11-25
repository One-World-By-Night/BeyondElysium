/**
 * The pop-up that edits one catalog entry's description: its reference, description and source sections.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { CatalogDescription } from '../../types';
import { isLongDescription } from '../../lib/descriptionLength';
import Modal from './Modal';
import HtmlEditor from './HtmlEditor';
import HelpButton from './HelpButton';
import './CatalogDescriptionEditor.css';

/**
 * Empty check across all three CatalogDescription sections, for the trigger button's "(set)" indicator.
 */
export function hasAnyDescriptionSection(
	value?: CatalogDescription | null
): boolean {
	return !! ( value?.reference || value?.description || value?.source );
}

export interface DescriptionEditorButtonProps {
	label: string;
	value?: CatalogDescription | null;
	onSave: ( value: CatalogDescription ) => void;
	/**
	 * Told when the pop-up opens or closes, so a pop-up holding this button can ignore its own Escape meanwhile.
	 */
	onOpenChange?: ( open: boolean ) => void;
}

/**
 * A trigger that opens a modal rich-text editor for one catalog item's, power's or level's `description`.
 */
export function DescriptionEditorButton( {
	label,
	value,
	onSave,
	onOpenChange,
}: DescriptionEditorButtonProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ draft, setDraft ] = useState< CatalogDescription >( value ?? {} );

	function setOpen( next: boolean ) {
		setIsOpen( next );
		onOpenChange?.( next );
	}

	function open() {
		setDraft( value ?? {} );
		setOpen( true );
	}

	function save() {
		onSave( draft );
		setOpen( false );
	}

	return (
		<>
			<button
				type="button"
				className="be-def-editor__description-trigger"
				onClick={ open }
			>
				{ hasAnyDescriptionSection( value )
					? __( 'Description (set)', 'beyond-elysium' )
					: __( 'Description', 'beyond-elysium' ) }
			</button>
			{ isOpen && (
				<Modal
					title={ sprintf(
						/* translators: %s: the item, level, or field's label */
						__( 'Description - %s', 'beyond-elysium' ),
						label
					) }
					onClose={ () => setOpen( false ) }
					footer={
						<>
							<button
								type="button"
								onClick={ () => setOpen( false ) }
							>
								{ __( 'Cancel', 'beyond-elysium' ) }
							</button>
							<button type="button" onClick={ save }>
								{ __( 'Save', 'beyond-elysium' ) }
							</button>
						</>
					}
				>
					<div className="be-help-heading">
						<HelpButton helpKey="schema-block-notes" />
					</div>
					<p className="description">
						{ __(
							'Formatting, lists, and tables are kept in each section. Images and anything else are stripped when saved.',
							'beyond-elysium'
						) }
					</p>

					{ isLongDescription( draft ) && (
						<p
							className="be-def-editor__description-warning"
							role="status"
						>
							{ __(
								"This is long. Write your chronicle's own short note in your own words, with a page reference, and leave the book's wording out.",
								'beyond-elysium'
							) }
						</p>
					) }

					<div className="be-def-editor__description-section">
						<label htmlFor="be-def-editor-description-reference">
							{ __( 'Reference', 'beyond-elysium' ) }
						</label>
						<HtmlEditor
							id="be-def-editor-description-reference"
							defaultValue={ draft.reference ?? '' }
							onChange={ ( html ) =>
								setDraft( { ...draft, reference: html } )
							}
							tables
							rows={ 5 }
							aiAssist={ {
								capability: 'be_manage_schemas',
								fieldContext: 'schema_item_reference',
							} }
						/>
					</div>

					<div className="be-def-editor__description-section">
						<label htmlFor="be-def-editor-description-body">
							{ __( 'Description', 'beyond-elysium' ) }
						</label>
						<HtmlEditor
							id="be-def-editor-description-body"
							defaultValue={ draft.description ?? '' }
							onChange={ ( html ) =>
								setDraft( { ...draft, description: html } )
							}
							tables
							rows={ 5 }
							aiAssist={ {
								capability: 'be_manage_schemas',
								fieldContext: 'schema_item_description',
							} }
						/>
					</div>

					<div className="be-def-editor__description-section">
						<label htmlFor="be-def-editor-description-source">
							{ __( 'Source', 'beyond-elysium' ) }
						</label>
						<HtmlEditor
							id="be-def-editor-description-source"
							defaultValue={ draft.source ?? '' }
							onChange={ ( html ) =>
								setDraft( { ...draft, source: html } )
							}
							tables
							rows={ 5 }
							aiAssist={ {
								capability: 'be_manage_schemas',
								fieldContext: 'schema_item_source',
							} }
						/>
					</div>
				</Modal>
			) }
		</>
	);
}

export default DescriptionEditorButton;
