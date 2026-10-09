/**
 * A Storyteller's way to put a block from any creature type onto a character whose creature type holds any block.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import {
	blockChoices,
	creatureTypesOfBlock,
	groupBlocksByCreatureType,
} from '../../lib/blockGroups';
import { SearchableSelect } from '../shared/SearchableSelect';
import { errorMessage } from '../../lib/errorMessage';
import { everyPage } from '../../lib/everyPage';
import type { CreatureStack, SchemaBlock } from '../../types';
import './AddSectionPicker.css';

const SHEET_SECTION_TYPES = [
	'trait_list',
	'tiered_power',
	'resource_pool',
	'identity_field',
];

export interface AddSectionPickerProps {
	gameSlug: string;
	/**
	 * Slugs of the sections the sheet already shows.
	 */
	shown: string[];
	/**
	 * Receives the chosen block as the chronicle has it.
	 */
	onAdd: ( block: SchemaBlock ) => void;
}

interface Catalog {
	blocks: SchemaBlock[];
	stacks: CreatureStack[];
}

/**
 * A folded "Add a section" control. The catalog loads when it is first opened, then offers every block a sheet can
 * hold, grouped under the creature type that owns it.
 */
export default function AddSectionPicker( {
	gameSlug,
	shown,
	onAdd,
}: AddSectionPickerProps ) {
	const [ open, setOpen ] = useState( false );
	const [ catalog, setCatalog ] = useState< Catalog | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ adding, setAdding ] = useState( false );
	const [ choice, setChoice ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );

	async function show() {
		setOpen( true );
		if ( catalog || loading ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const [ blocks, stacks ] = await Promise.all( [
				everyPage( ( page ) =>
					api.schemaBlocks.listPaginated( {
						page,
						per_page: 100,
						game_slug: gameSlug,
					} )
				),
				api.creatureStacks.list( {
					game_slug: gameSlug,
					include_disabled: true,
					per_page: 100,
				} ),
			] );
			setCatalog( {
				blocks: blocks.filter( ( block ) =>
					SHEET_SECTION_TYPES.includes( block.section_type )
				),
				stacks,
			} );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__(
						'Could not load the sections. Try again.',
						'beyond-elysium'
					)
				)
			);
		} finally {
			setLoading( false );
		}
	}

	async function add() {
		if ( ! choice ) {
			return;
		}
		setAdding( true );
		setError( null );
		try {
			onAdd( await api.schemaBlocks.get( choice, gameSlug ) );
			setChoice( '' );
			setOpen( false );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__(
						'Could not add that section. Try again.',
						'beyond-elysium'
					)
				)
			);
		} finally {
			setAdding( false );
		}
	}

	if ( ! open ) {
		return (
			<div className="be-add-section">
				<button type="button" onClick={ show }>
					{ __( 'Add a section', 'beyond-elysium' ) }
				</button>
			</div>
		);
	}

	const offered = catalog
		? catalog.blocks.filter( ( block ) => ! shown.includes( block.slug ) )
		: [];
	const owners = catalog
		? creatureTypesOfBlock( catalog.stacks, catalog.blocks )
		: new Map< string, string[] >();
	const groups = catalog
		? groupBlocksByCreatureType(
				offered,
				catalog.stacks,
				new Set( catalog.stacks.map( ( stack ) => stack.slug ) ),
				{
					shared: __(
						'Used by several creature types',
						'beyond-elysium'
					),
					other: __(
						'Not part of a creature type',
						'beyond-elysium'
					),
					notEnabled: ( name: string ) => name,
				}
			)
		: [];
	const pick = blockChoices( groups, owners, offered );

	return (
		<div className="be-add-section be-add-section--open">
			<label htmlFor="be-add-section-select">
				{ __( 'Add a section', 'beyond-elysium' ) }
			</label>
			{ loading && (
				<span className="be-add-section__status">
					{ __( 'Loading sections…', 'beyond-elysium' ) }
				</span>
			) }
			{ catalog && (
				<SearchableSelect
					id="be-add-section-select"
					groups={ pick.groups }
					value={ pick.labelOf( choice ) }
					placeholder={ __( 'Choose a section…', 'beyond-elysium' ) }
					onChange={ ( label ) =>
						setChoice( pick.slugOf( label ) ?? '' )
					}
				/>
			) }
			<button
				type="button"
				disabled={ ! choice || adding }
				onClick={ add }
			>
				{ adding
					? __( 'Adding…', 'beyond-elysium' )
					: __( 'Add', 'beyond-elysium' ) }
			</button>
			<button type="button" onClick={ () => setOpen( false ) }>
				{ __( 'Cancel', 'beyond-elysium' ) }
			</button>
			<p className="be-add-section__hint">
				{ __(
					'Any block from any creature type. The section stays on the sheet once it holds an entry.',
					'beyond-elysium'
				) }
			</p>
			{ error && (
				<p className="be-add-section__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
}
