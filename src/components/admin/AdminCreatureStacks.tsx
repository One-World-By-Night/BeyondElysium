/**
 * Admin page showing the book's creature stack definitions, read-only, or one chronicle's own layer, editable.
 */
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import CreatureStackDefinitionEditor from './CreatureStackDefinitionEditor';
import type {
	CreationRules,
	CreatureStack,
	StackDefinition,
} from '../../types';
import { errorMessage } from '../../lib/errorMessage';
import { useRevealOnOpen } from '../../lib/revealEditor';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

/**
 * Renders the Creature Stacks admin screen: the book's creature types, read-only; or, with a chronicle chosen, that
 * chronicle's own layer, editable.
 */
export function AdminCreatureStacks() {
	const [ stacks, setStacks ] = useState< CreatureStack[] >( [] );
	const [ bookStacks, setBookStacks ] = useState< CreatureStack[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ viewing, setViewing ] = useState< CreatureStack | null >( null );
	const [ draftDefinition, setDraftDefinition ] =
		useState< StackDefinition | null >( null );
	const [ draftRules, setDraftRules ] = useState< CreationRules | null >(
		null
	);
	const [ saving, setSaving ] = useState( false );
	const viewerRef = useRef< HTMLDivElement >( null );
	useRevealOnOpen( viewerRef, viewing?.slug ?? null );
	// Whether system-seeded creature stacks are hidden from the list.
	const [ hideSystem, setHideSystem ] = useState( false );

	const [ creating, setCreating ] = useState( false );
	const [ newSlug, setNewSlug ] = useState( '' );
	const [ newName, setNewName ] = useState( '' );
	const [ newGameLine, setNewGameLine ] = useState( 'met' );
	const [ newDefinition, setNewDefinition ] = useState< StackDefinition >( {
		sections: [],
		display_preferences: {},
	} );
	const [ newRules, setNewRules ] = useState< CreationRules >( {} );
	const [ createSaving, setCreateSaving ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );
	const creatorRef = useRef< HTMLDivElement >( null );

	// Optional game_slug query param scopes the page to one chronicle, where its own layer is edited.
	const gameSlug =
		new URLSearchParams( window.location.search ).get( 'game_slug' ) ?? '';
	const canEdit = gameSlug !== '';
	useRevealOnOpen( creatorRef, creating ? gameSlug : null );

	/**
	 * Fetches the creature stack list for the current scope (the book, or one chronicle's own layers) and the book's
	 * own list, needed to tell a book section apart from one a chronicle added.
	 */
	function load() {
		setLoading( true );
		Promise.all( [
			api.creatureStacks.list( {
				...( gameSlug ? { game_slug: gameSlug } : {} ),
				per_page: 100,
			} ),
			gameSlug
				? api.creatureStacks.list( { per_page: 100 } )
				: Promise.resolve< CreatureStack[] >( [] ),
		] )
			.then( ( [ result, book ] ) => {
				setStacks( result );
				setBookStacks( book );
				setLoading( false );
				if ( viewing ) {
					const same = result.find(
						( s ) => s.slug === viewing.slug
					);
					if ( same ) {
						openStack( same );
					}
				}
			} )
			.catch( ( err: unknown ) => {
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				);
				setLoading( false );
			} );
	}

	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ gameSlug ] );

	const visible = hideSystem
		? stacks.filter( ( s ) => ! s.is_system )
		: stacks;

	function openStack( stack: CreatureStack ) {
		setViewing( stack );
		setDraftDefinition( stack.stack_definition );
		setDraftRules( stack.creation_rules ?? {} );
	}

	async function save() {
		if ( ! viewing || ! draftDefinition || ! draftRules ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			const saved = await api.creatureStacks.update(
				viewing.slug,
				{
					stack_definition: draftDefinition,
					creation_rules: draftRules,
				},
				gameSlug
			);
			openStack( saved );
			load();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSaving( false );
		}
	}

	function startCreate() {
		setViewing( null );
		setNewSlug( '' );
		setNewName( '' );
		setNewGameLine( 'met' );
		setNewDefinition( { sections: [], display_preferences: {} } );
		setNewRules( {} );
		setCreateError( null );
		setCreating( true );
	}

	async function create() {
		if ( ! newSlug || ! newName || newDefinition.sections.length === 0 ) {
			setCreateError(
				__(
					'A slug, a name and at least one section are required.',
					'beyond-elysium'
				)
			);
			return;
		}
		setCreateSaving( true );
		setCreateError( null );
		try {
			const saved = await api.creatureStacks.create( gameSlug, {
				slug: newSlug,
				name: newName,
				game_line: newGameLine,
				sections: newDefinition.sections,
				creation_rules: newRules,
			} );
			setCreating( false );
			load();
			openStack( saved );
		} catch ( err: unknown ) {
			setCreateError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setCreateSaving( false );
		}
	}

	async function resetToBook( stack: CreatureStack ) {
		if (
			! window.confirm(
				sprintf(
					// translators: %s: creature type name.
					__(
						'Reset "%s" to the book? This chronicle\'s own changes to it are lost.',
						'beyond-elysium'
					),
					stack.name
				)
			)
		) {
			return;
		}
		try {
			await api.creatureStacks.reset( stack.slug, gameSlug );
			if ( viewing?.slug === stack.slug ) {
				setViewing( null );
			}
			load();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		}
	}

	const bookSectionSlugs = viewing
		? (
				bookStacks.find( ( s ) => s.slug === viewing.slug )
					?.stack_definition.sections ?? []
			).map( ( s ) => s.block_slug )
		: [];

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Creature Stacks', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="creature-stacks" />
			</div>
			{ gameSlug && (
				<p className="be-admin__game-scope-notice">
					{ createInterpolateElement(
						__(
							'Editing for chronicle <slug/> - what is saved here belongs to that chronicle alone, over the book.',
							'beyond-elysium'
						),
						{ slug: <strong>{ gameSlug }</strong> }
					) }
				</p>
			) }
			{ ! canEdit && (
				<p className="be-admin__game-scope-notice">
					{ __(
						"This is the book: each creature type as released, read-only here. To change one for a chronicle you run, open that chronicle's Chronicle Setup.",
						'beyond-elysium'
					) }
				</p>
			) }
			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			<div className="be-admin__filters">
				<label>
					<input
						type="checkbox"
						checked={ hideSystem }
						onChange={ ( e ) => setHideSystem( e.target.checked ) }
					/>{ ' ' }
					{ sprintf(
						// translators: %d: number of system creature stacks.
						__( 'Hide system stacks (%d)', 'beyond-elysium' ),
						stacks.filter( ( s ) => s.is_system ).length
					) }
				</label>
				{ canEdit && (
					<button type="button" onClick={ startCreate }>
						{ __( '+ New Creature Stack', 'beyond-elysium' ) }
					</button>
				) }
			</div>

			{ creating && (
				<div
					ref={ creatorRef }
					className="be-admin__form be-admin__form--wide"
					tabIndex={ -1 }
				>
					<h2>{ __( 'New Creature Stack', 'beyond-elysium' ) }</h2>
					<p className="be-admin__game-scope-notice">
						{ __(
							'Built for this chronicle alone, with no book counterpart - a genuinely new creature type, not a layer over one the book already declares.',
							'beyond-elysium'
						) }
					</p>
					{ createError && (
						<div className="be-admin__error" role="alert">
							{ createError }
						</div>
					) }
					<label>
						{ __( 'Name', 'beyond-elysium' ) }
						<input
							type="text"
							value={ newName }
							onChange={ ( e ) => setNewName( e.target.value ) }
						/>
					</label>
					<label>
						{ __( 'Slug', 'beyond-elysium' ) }
						<input
							type="text"
							value={ newSlug }
							onChange={ ( e ) => setNewSlug( e.target.value ) }
						/>
					</label>
					<label>
						{ __( 'Game Line', 'beyond-elysium' ) }
						<input
							type="text"
							value={ newGameLine }
							onChange={ ( e ) =>
								setNewGameLine( e.target.value )
							}
						/>
					</label>

					<CreatureStackDefinitionEditor
						stackDefinition={ newDefinition }
						creationRules={ newRules }
						onChangeStackDefinition={ setNewDefinition }
						onChangeCreationRules={ setNewRules }
					/>

					<div className="be-admin__form-actions">
						<button
							type="button"
							onClick={ create }
							disabled={ createSaving }
						>
							{ createSaving
								? __( 'Creating…', 'beyond-elysium' )
								: __( 'Create', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ () => setCreating( false ) }
						>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-admin__table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'beyond-elysium' ) }</th>
							<th>{ __( 'Slug', 'beyond-elysium' ) }</th>
							<th>{ __( 'Game Line', 'beyond-elysium' ) }</th>
							<th>{ __( 'System?', 'beyond-elysium' ) }</th>
							<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ visible.length === 0 && (
							<tr>
								<td colSpan={ 5 }>
									{ __(
										'No custom creature stacks.',
										'beyond-elysium'
									) }
								</td>
							</tr>
						) }
						{ visible.map( ( stack ) => (
							<tr key={ stack.slug }>
								<td>{ stack.name }</td>
								<td>
									<code>{ stack.slug }</code>
								</td>
								<td>{ stack.game_line }</td>
								<td>
									{ stack.is_system
										? __( 'Yes', 'beyond-elysium' )
										: __( 'No', 'beyond-elysium' ) }
								</td>
								<td>
									<button
										type="button"
										onClick={ () => openStack( stack ) }
									>
										{ canEdit
											? __( 'Edit', 'beyond-elysium' )
											: __( 'View', 'beyond-elysium' ) }
									</button>
									{ canEdit &&
										stack.game_slug === gameSlug && (
											<button
												type="button"
												onClick={ () =>
													resetToBook( stack )
												}
											>
												{ __(
													'Reset to book',
													'beyond-elysium'
												) }
											</button>
										) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ viewing && draftDefinition && draftRules && (
				<div
					ref={ viewerRef }
					className="be-admin__form be-admin__form--wide"
					tabIndex={ -1 }
				>
					<h2>
						{ canEdit
							? viewing.name
							: sprintf(
									/* translators: %s: the creature stack's name */
									__( '%s (read-only)', 'beyond-elysium' ),
									viewing.name
								) }
					</h2>
					<fieldset
						disabled={ ! canEdit }
						className={ canEdit ? '' : 'be-admin__read-only' }
					>
						<label>
							{ __( 'Name', 'beyond-elysium' ) }
							<input
								type="text"
								value={ viewing.name }
								readOnly
							/>
						</label>
						<label>
							{ __( 'Game Line', 'beyond-elysium' ) }
							<input
								type="text"
								value={ viewing.game_line }
								readOnly
							/>
						</label>

						<CreatureStackDefinitionEditor
							stackDefinition={ draftDefinition }
							creationRules={ draftRules }
							onChangeStackDefinition={ setDraftDefinition }
							onChangeCreationRules={ setDraftRules }
							gameSlug={ canEdit ? gameSlug : undefined }
							stackSlug={ canEdit ? viewing.slug : undefined }
							bookSectionSlugs={ bookSectionSlugs }
							onSectionsPersisted={ load }
						/>
					</fieldset>

					<div className="be-admin__form-actions">
						{ canEdit && (
							<button
								type="button"
								onClick={ save }
								disabled={ saving }
							>
								{ saving
									? __( 'Saving…', 'beyond-elysium' )
									: __( 'Save', 'beyond-elysium' ) }
							</button>
						) }
						<button
							type="button"
							onClick={ () => setViewing( null ) }
						>
							{ __( 'Close', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) }
		</div>
	);
}

export default AdminCreatureStacks;
