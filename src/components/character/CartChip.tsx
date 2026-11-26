/**
 * The cart chip in the Character header: the cart's size and price, opening Review. Absent with an empty cart.
 */
import useCharacterEditorStore from '../../store/characterEditorStore';
import { cartView } from '../../lib/cart';
import './Cart.css';

export function CartChip() {
	const store = useCharacterEditorStore();
	if ( ! store.character ) {
		return null;
	}
	const view = cartView(
		store.pendingChanges,
		store.previewCosts,
		store.character,
		!! store.character.can_manage,
		store.stack?.blocks
	);
	if ( view.chip === null ) {
		return null;
	}
	return (
		<button
			type="button"
			className="be-cart-chip"
			onClick={ () => store.openReview() }
		>
			{ view.chip }
		</button>
	);
}

export default CartChip;
