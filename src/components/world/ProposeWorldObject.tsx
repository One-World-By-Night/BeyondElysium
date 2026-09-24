/**
 * A player proposes an item, location or rote for their own character.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import HtmlEditor from '../shared/HtmlEditor';
import HelpButton from '../shared/HelpButton';
import { WORLD_OBJECT_SCHEMAS } from '../../types/world';
import type { Character } from '../../types/character';
import './ProposeWorldObject.css';

export interface ProposeWorldObjectProps {
	gameSlug: string;
	/**
	 * The character the proposal is attached.
	 */
	characterId: number;
}

type PropertyValue = string | number | boolean;

import { errorMessage } from '../../lib/errorMessage';

export function ProposeWorldObject( {
	gameSlug,
	characterId,
}: ProposeWorldObjectProps ) {
	const types = Object.keys( WORLD_OBJECT_SCHEMAS ).filter(
		// A boon is a transaction between two characters, recorded by a Harpy.
		( t ) => t !== 'boon'
	);

	const [ objectType, setObjectType ] = useState( types[ 0 ] ?? 'item' );
	const [ name, setName ] = useState( '' );
	const [ properties, setProperties ] = useState<
		Record< string, PropertyValue >
	>( {} );
	const [ description, setDescription ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ submitted, setSubmitted ] = useState( false );
	const [ character, setCharacter ] = useState< Character | null >( null );

	useEffect( () => {
		api.characters( gameSlug )
			.get( characterId )
			.then( setCharacter )
			.catch( () => setCharacter( null ) );
	}, [ gameSlug, characterId ] );

	// Properties belong to the chosen type.
	useEffect( () => {
		setProperties( {} );
	}, [ objectType ] );

	const schema =
		WORLD_OBJECT_SCHEMAS[
			objectType as keyof typeof WORLD_OBJECT_SCHEMAS
		] ?? {};

	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! name.trim() ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			await api.changes( gameSlug ).create( characterId, {
				change_type: 'propose_world_object',
				category: 'world_object',
				change_data: {
					object_type: objectType,
					name: name.trim(),
					description,
					properties,
				},
			} );
			setSubmitted( true );
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

	function reset() {
		setSubmitted( false );
		setName( '' );
		setDescription( '' );
		setProperties( {} );
	}

	if ( submitted ) {
		return (
			<div className="be-propose-object">
				<p role="status">
					{ __(
						'Sent to your Storytellers. It shows up in the catalog once someone approves it.',
						'beyond-elysium'
					) }
				</p>
				<button type="button" onClick={ reset }>
					{ __( 'Propose another', 'beyond-elysium' ) }
				</button>
			</div>
		);
	}

	return (
		<form className="be-propose-object" onSubmit={ submit }>
			<div className="be-help-heading">
				<h2>
					{ __( 'Propose an item or location', 'beyond-elysium' ) }
				</h2>
				<HelpButton helpKey="propose-item" />
			</div>
			<p>
				{ character
					? sprintf(
							/* translators: %s: character name. */
							__(
								'Something %s made, found, or holds. A Storyteller reviews it before it becomes real.',
								'beyond-elysium'
							),
							character.name
					  )
					: __(
							'Something your character made, found, or holds. A Storyteller reviews it before it becomes real.',
							'beyond-elysium'
					  ) }
			</p>

			{ error && (
				<div className="be-propose-object__error" role="alert">
					{ error }
				</div>
			) }

			<label>
				{ __( 'What is it?', 'beyond-elysium' ) }
				<select
					value={ objectType }
					onChange={ ( e ) => setObjectType( e.target.value ) }
				>
					{ types.map( ( t ) => (
						<option key={ t } value={ t }>
							{ t }
						</option>
					) ) }
				</select>
			</label>

			<label>
				{ __( 'Name', 'beyond-elysium' ) }
				<input
					type="text"
					value={ name }
					required
					onChange={ ( e ) => setName( e.target.value ) }
				/>
			</label>

			<div className="be-propose-object__field">
				<span>{ __( 'Description', 'beyond-elysium' ) }</span>
				<HtmlEditor
					id={ `be-propose-description-${ characterId }-${ objectType }` }
					defaultValue=""
					rows={ 6 }
					onChange={ setDescription }
				/>
			</div>

			{ Object.entries( schema )
				// A trait_list property is a Storyteller-side structure (availability lists, security traits).
				.filter( ( [ , kind ] ) => kind !== 'trait_list' )
				.map( ( [ key, kind ] ) => (
					<label key={ key }>
						{ key.replace( /_/g, ' ' ) }
						{ kind === 'text' ? (
							<textarea
								value={ String( properties[ key ] ?? '' ) }
								onChange={ ( e ) =>
									setProperties( {
										...properties,
										[ key ]: e.target.value,
									} )
								}
							/>
						) : (
							<input
								type={
									kind === 'int'
										? 'number'
										: kind === 'date'
										? 'date'
										: 'text'
								}
								value={ String( properties[ key ] ?? '' ) }
								onChange={ ( e ) =>
									setProperties( {
										...properties,
										[ key ]:
											kind === 'int'
												? Number( e.target.value )
												: e.target.value,
									} )
								}
							/>
						) }
					</label>
				) ) }

			<button type="submit" disabled={ saving || ! name.trim() }>
				{ saving
					? __( 'Sending…', 'beyond-elysium' )
					: __( 'Send for approval', 'beyond-elysium' ) }
			</button>
		</form>
	);
}

export default ProposeWorldObject;
