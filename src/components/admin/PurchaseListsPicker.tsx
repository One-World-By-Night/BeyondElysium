/**
 * The three purchase-list switches on the Chronicle Setup screen: Abilities, Backgrounds, and Merits and Flaws, each
 * on its own.
 */
import { __ } from '@wordpress/i18n';
import {
	PURCHASE_AREAS,
	readPurchaseScope,
	type PurchaseArea,
} from '../../lib/purchaseScope';

export interface PurchaseListsPickerProps {
	/**
	 * The chronicle's stored `settings.purchase_scope`, whatever shape it arrived in.
	 */
	scope: unknown;
	/**
	 * The area being saved right now, if any.
	 */
	savingArea: PurchaseArea | null;
	onChange: ( area: PurchaseArea, on: boolean ) => void;
}

export default function PurchaseListsPicker( {
	scope,
	savingArea,
	onChange,
}: PurchaseListsPickerProps ) {
	const current = readPurchaseScope( scope );
	// Read at render, not module load.
	const labels: Record< PurchaseArea, string > = {
		abilities: __( 'Abilities from every creature type', 'beyond-elysium' ),
		backgrounds: __(
			'Backgrounds from every creature type',
			'beyond-elysium'
		),
		merits_flaws: __(
			'Merits and Flaws from every creature type',
			'beyond-elysium'
		),
	};

	return (
		<div className="be-chronicle-setup__purchase-lists">
			{ PURCHASE_AREAS.map( ( area ) => (
				<label key={ area } className="be-chronicle-setup__checkbox">
					<input
						type="checkbox"
						checked={ current[ area ] }
						disabled={ savingArea !== null }
						onChange={ ( e ) => onChange( area, e.target.checked ) }
					/>
					{ labels[ area ] }
				</label>
			) ) }
		</div>
	);
}
