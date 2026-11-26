/**
 * The app shell header's character picker: the player's own characters in the chronicle, the open one chosen.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { Character } from '../../types/character';

export interface AppCharacterPickerProps {
	characters: Character[];
	characterId?: number;
	typeName: ( slug: string ) => string;
	onPick: ( characterId: number ) => void;
}

export function AppCharacterPicker( {
	characters,
	characterId,
	typeName,
	onPick,
}: AppCharacterPickerProps ) {
	if ( characters.length === 0 ) {
		return null;
	}
	return (
		<select
			className="be-app-character"
			aria-label={ __( 'Character', 'beyond-elysium' ) }
			value={ characterId ?? '' }
			onChange={ ( e ) => {
				const picked = Number( e.target.value );
				if ( picked > 0 && picked !== characterId ) {
					onPick( picked );
				}
			} }
		>
			{ characterId === undefined && (
				<option value="">
					{ __( 'Choose a character', 'beyond-elysium' ) }
				</option>
			) }
			{ characters.map( ( character ) => (
				<option key={ character.id } value={ character.id }>
					{ sprintf(
						/* translators: 1: a character's name, 2: its creature type, such as Vampire */
						__( '%1$s · %2$s', 'beyond-elysium' ),
						character.name,
						typeName( character.stack_slug )
					) }
				</option>
			) ) }
		</select>
	);
}

export default AppCharacterPicker;
