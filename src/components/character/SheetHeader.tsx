/**
 * The sheet's header: portrait, name, type, status, player and XP.
 */
import { __, sprintf } from '@wordpress/i18n';
import { characterStatusLabel } from '../../lib/characterLabels';
import type { Character } from '../../types/character';

export interface SheetHeaderProps {
	character: Character;
	typeName: string;
}

export function SheetHeader( { character, typeName }: SheetHeaderProps ) {
	return (
		<div className="be-character-sheet__header-row">
			{ character.image_url && (
				<img
					className="be-character-sheet__portrait"
					src={ character.image_url }
					alt={ sprintf(
						/* translators: %s: the character's own name */
						__( '%s portrait', 'beyond-elysium' ),
						character.name
					) }
				/>
			) }
			<dl className="be-character-sheet__header">
				<div>
					<dt>{ __( 'Name', 'beyond-elysium' ) }</dt>
					<dd>{ character.name }</dd>
				</div>
				<div>
					<dt>{ __( 'Type', 'beyond-elysium' ) }</dt>
					<dd>{ typeName }</dd>
				</div>
				<div>
					<dt>{ __( 'Status', 'beyond-elysium' ) }</dt>
					<dd>{ characterStatusLabel( character.status ) }</dd>
				</div>
				{ character.player_name && (
					<div>
						<dt>{ __( 'Player', 'beyond-elysium' ) }</dt>
						<dd>{ character.player_name }</dd>
					</div>
				) }
				<div>
					<dt>{ __( 'XP Earned', 'beyond-elysium' ) }</dt>
					<dd>{ character.xp_earned }</dd>
				</div>
				<div>
					<dt>{ __( 'XP Unspent', 'beyond-elysium' ) }</dt>
					<dd>{ character.xp_unspent }</dd>
				</div>
			</dl>
		</div>
	);
}

export default SheetHeader;
