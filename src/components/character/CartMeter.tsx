/**
 * The cart's meter, pinned at the bottom of Edit: the XP free to spend, and with lines in the cart their size, price
 * and what they leave, with the button that opens Review.
 */
import { __ } from '@wordpress/i18n';
import useCharacterEditorStore from '../../store/characterEditorStore';
import { cartView, droppedMessage } from '../../lib/cart';
import './Cart.css';

export function CartMeter() {
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
	const dropped = droppedMessage(
		store.droppedBlocks.map(
			( slug ) => store.stack?.blocks[ slug ]?.name ?? slug
		)
	);

	return (
		<div className="be-cart-meter" id="character-editor-pending">
			{ dropped && (
				<p className="be-cart-meter__dropped" role="status">
					{ dropped }{ ' ' }
					<button
						type="button"
						className="be-cart-meter__dismiss"
						onClick={ () => store.dismissDropped() }
					>
						{ __( 'Dismiss', 'beyond-elysium' ) }
					</button>
				</p>
			) }
			<div className="be-cart-meter__bar">
				<p className="be-cart-meter__line" role="status">
					{ view.meter.lead }
					{ view.meter.tail && (
						<>
							{ ' · ' }
							<span
								className={
									view.meter.over
										? 'be-cart-meter__over'
										: undefined
								}
							>
								{ view.meter.tail }
							</span>
						</>
					) }
				</p>
				{ view.lines.length > 0 && (
					<button
						type="button"
						className="be-cart-meter__review"
						onClick={ () => store.openReview() }
					>
						{ __( 'Review', 'beyond-elysium' ) }
					</button>
				) }
			</div>
		</div>
	);
}

export default CartMeter;
