/**
 * Admin page for managing creature stack definitions.
 * Lists the system-seeded and chronicle-defined creature stacks, and
 * provides a form to create, edit, or delete a chronicle's own custom
 * stack definitions and character-creation rules.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import CreatureStackDefinitionEditor from './CreatureStackDefinitionEditor';
import type { CreationRules, CreatureStack, StackDefinition } from '../../types';
import './Admin.css';

interface RestError {
	message?: string;
}

/**
 * Extracts a human-readable message from a caught error value.
 * Falls back to a generic message when the error has no usable
 * `message` property.
 */
function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

const EMPTY_FORM = {
	slug: '',
	name: '',
	game_line: 'met',
	stackDefinition: { sections: [] } as StackDefinition,
	creationRules: {} as CreationRules,
};

/**
 * Renders the Creature Stacks admin screen.
 * Lists all creature stacks with a filter to hide system-seeded ones,
 * and provides a form for creating, editing, and deleting a chronicle's
 * own custom creature stack definitions and character-creation rules.
 */
export function AdminCreatureStacks() {
	const [ stacks, setStacks ] = useState<CreatureStack[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ editingSlug, setEditingSlug ] = useState<string | null>( null );
	const [ creating, setCreating ] = useState( false );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );
	// Whether system-seeded creature stacks are hidden from the list.
	const [ hideSystem, setHideSystem ] = useState( false );

	/**
	 * Fetches the list of creature stacks from the API.
	 * Populates the stack list on success and records the error message
	 * on failure, tracking a loading flag throughout.
	 */
	function load() {
		setLoading( true );
		api.creatureStacks
			.list()
			.then( ( result ) => {
				setStacks( result );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError( errorMessage( err ) );
				setLoading( false );
			} );
	}

	useEffect( load, [] );

	const visible = hideSystem ? stacks.filter( ( s ) => ! s.is_system ) : stacks;

	function startEdit( stack: CreatureStack ) {
		setEditingSlug( stack.slug );
		setCreating( false );
		setForm( {
			slug: stack.slug,
			name: stack.name,
			game_line: stack.game_line,
			stackDefinition: stack.stack_definition,
			creationRules: stack.creation_rules ?? {},
		} );
	}

	function startCreate() {
		setEditingSlug( null );
		setCreating( true );
		setForm( EMPTY_FORM );
	}

	function cancel() {
		setEditingSlug( null );
		setCreating( false );
		setForm( EMPTY_FORM );
	}

	/**
	 * Creates or updates a creature stack from the current form state.
	 * Validates that required fields are filled, calls the appropriate
	 * create or update API endpoint, then closes the form and reloads
	 * the list on success.
	 */
	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! form.name.trim() || ( creating && ! form.slug.trim() ) ) {
			return;
		}

		setSaving( true );
		setError( null );
		try {
			if ( creating ) {
				await api.creatureStacks.create( {
					slug: form.slug.trim(),
					name: form.name.trim(),
					game_line: form.game_line,
					stack_definition: form.stackDefinition,
					creation_rules: form.creationRules,
				} );
			} else if ( editingSlug ) {
				await api.creatureStacks.update( editingSlug, {
					name: form.name.trim(),
					game_line: form.game_line,
					stack_definition: form.stackDefinition,
					creation_rules: form.creationRules,
				} );
			}
			cancel();
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	/**
	 * Deletes a creature stack after confirmation.
	 * Prompts the viewer to confirm, then calls the API to delete the
	 * stack and reloads the list.
	 */
	async function remove( stack: CreatureStack ) {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				sprintf(
					// translators: %s: creature stack name.
					__( 'Delete "%s"? Existing characters of this type will no longer resolve.', 'beyond-elysium' ),
					stack.name
				)
			)
		) {
			return;
		}
		try {
			await api.creatureStacks.delete( stack.slug );
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	return (
		<div className="be-admin">
			<h1>{ __( 'Creature Stacks', 'beyond-elysium' ) }</h1>
			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			<div className="be-admin__filters">
				<label>
					<input type="checkbox" checked={ hideSystem } onChange={ ( e ) => setHideSystem( e.target.checked ) } />
					{ ' ' }
					{ sprintf(
						// translators: %d: number of system creature stacks.
						__( 'Hide system stacks (%d)', 'beyond-elysium' ),
						stacks.filter( ( s ) => s.is_system ).length
					) }
				</label>
			</div>

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
								<td colSpan={ 5 }>{ __( 'No custom creature stacks yet.', 'beyond-elysium' ) }</td>
							</tr>
						) }
						{ visible.map( ( stack ) => (
							<tr key={ stack.slug }>
								<td>{ stack.name }</td>
								<td>
									<code>{ stack.slug }</code>
								</td>
								<td>{ stack.game_line }</td>
								<td>{ stack.is_system ? __( 'Yes', 'beyond-elysium' ) : __( 'No', 'beyond-elysium' ) }</td>
								<td>
									<button type="button" onClick={ () => startEdit( stack ) }>
										{ __( 'Edit', 'beyond-elysium' ) }
									</button>
									{ ! stack.is_system && (
										<button type="button" onClick={ () => remove( stack ) }>
											{ __( 'Delete', 'beyond-elysium' ) }
										</button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ ! creating && editingSlug === null && (
				<button type="button" onClick={ startCreate }>
					{ __( '+ New Creature Stack', 'beyond-elysium' ) }
				</button>
			) }

			{ ( creating || editingSlug !== null ) && (
				<form className="be-admin__form be-admin__form--wide" onSubmit={ save }>
					<h2>{ creating ? __( 'New Creature Stack', 'beyond-elysium' ) : sprintf( __( 'Edit %s', 'beyond-elysium' ), editingSlug as string ) }</h2>
					<label>
						{ __( 'Name', 'beyond-elysium' ) }
						<input type="text" value={ form.name } onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) } required />
					</label>
					{ creating && (
						<label>
							{ __( 'Slug', 'beyond-elysium' ) }
							<input type="text" value={ form.slug } onChange={ ( e ) => setForm( { ...form, slug: e.target.value } ) } required />
						</label>
					) }
					<label>
						{ __( 'Game Line', 'beyond-elysium' ) }
						<input type="text" value={ form.game_line } onChange={ ( e ) => setForm( { ...form, game_line: e.target.value } ) } />
					</label>

					<CreatureStackDefinitionEditor
						stackDefinition={ form.stackDefinition }
						creationRules={ form.creationRules }
						onChangeStackDefinition={ ( stackDefinition ) => setForm( { ...form, stackDefinition } ) }
						onChangeCreationRules={ ( creationRules ) => setForm( { ...form, creationRules } ) }
					/>

					<div className="be-admin__form-actions">
						<button type="submit" disabled={ saving }>
							{ saving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
						</button>
						<button type="button" onClick={ cancel }>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					</div>
				</form>
			) }
		</div>
	);
}

export default AdminCreatureStacks;
