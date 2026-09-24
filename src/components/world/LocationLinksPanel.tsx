/**
 * A location's four named links.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import type { Character } from '../../types/character';
import type { LocationLink, LocationLinkLabel } from '../../types/world';
import './LocationLinksPanel.css';

export interface LocationLinksPanelProps {
	gameSlug: string;
	locationId: number;
}

const LABELS: { value: LocationLinkLabel; label: string }[] = [
	{ value: 'owner', label: __( 'Owner', 'beyond-elysium' ) },
	{ value: 'domain', label: __( 'Domain', 'beyond-elysium' ) },
	{ value: 'haven', label: __( 'Haven', 'beyond-elysium' ) },
	{ value: 'based_at', label: __( 'Based here', 'beyond-elysium' ) },
];

export function LocationLinksPanel( {
	gameSlug,
	locationId,
}: LocationLinksPanelProps ) {
	const [ links, setLinks ] = useState< LocationLink[] >( [] );
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ label, setLabel ] = useState< LocationLinkLabel >( 'owner' );
	const [ characterId, setCharacterId ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	function load() {
		api.locations( gameSlug )
			.links( locationId )
			.then( setLinks )
			.catch( () =>
				setError( __( 'Failed to load links.', 'beyond-elysium' ) )
			);
	}

	useEffect( load, [ gameSlug, locationId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		everyPage( ( page ) =>
			api.characters( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setCharacters )
			.catch( () => setCharacters( [] ) );
	}, [ gameSlug ] );

	async function addLink( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! characterId ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			await api.locations( gameSlug ).createLink( locationId, {
				label,
				source_type: 'character',
				source_id: Number( characterId ),
			} );
			setCharacterId( '' );
			load();
		} catch {
			setError( __( 'Failed to create this link.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	async function removeLink( linkId: number ) {
		try {
			await api.locations( gameSlug ).deleteLink( locationId, linkId );
			load();
		} catch {
			setError( __( 'Failed to remove this link.', 'beyond-elysium' ) );
		}
	}

	return (
		<div className="be-location-links">
			<h4>{ __( 'Links', 'beyond-elysium' ) }</h4>
			{ error && (
				<div className="be-location-links__error" role="alert">
					{ error }
				</div>
			) }

			{ links.length === 0 ? (
				<p>{ __( 'No links yet.', 'beyond-elysium' ) }</p>
			) : (
				<ul className="be-location-links__list">
					{ links.map( ( link ) => (
						<li key={ link.id }>
							<span className="be-st-badge">
								{ LABELS.find( ( l ) => l.value === link.label )
									?.label ?? link.label }
							</span>
							{ link.name }
							<button
								type="button"
								onClick={ () => removeLink( link.id ) }
							>
								{ __( 'Remove', 'beyond-elysium' ) }
							</button>
						</li>
					) ) }
				</ul>
			) }

			<form className="be-location-links__form" onSubmit={ addLink }>
				<select
					value={ label }
					onChange={ ( e ) =>
						setLabel( e.target.value as LocationLinkLabel )
					}
				>
					{ LABELS.map( ( l ) => (
						<option key={ l.value } value={ l.value }>
							{ l.label }
						</option>
					) ) }
				</select>
				<select
					value={ characterId }
					onChange={ ( e ) => setCharacterId( e.target.value ) }
				>
					<option value="">
						{ __( 'Choose a character…', 'beyond-elysium' ) }
					</option>
					{ characters.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ c.name }
						</option>
					) ) }
				</select>
				<button type="submit" disabled={ saving || ! characterId }>
					{ __( 'Add Link', 'beyond-elysium' ) }
				</button>
			</form>
		</div>
	);
}

export default LocationLinksPanel;
