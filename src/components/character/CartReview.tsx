/**
 * Review: the cart's lines grouped by whether they apply now or wait for a Storyteller, each with its price and a way
 * to take it out, the sums, a note for the Storyteller and Submit. After Submit it reads as the order's confirmation.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ConfirmDialog from '../shared/ConfirmDialog';
import Modal from '../shared/Modal';
import useCharacterEditorStore from '../../store/characterEditorStore';
import {
	cartView,
	confirmationLine,
	droppedMessage,
	heldLine,
	type CartLine,
	type LineGroup,
} from '../../lib/cart';
import './Cart.css';

/**
 * The longest note a player may leave for the Storyteller.
 */
export const NOTE_LIMIT = 1000;

const GROUP_ORDER: LineGroup[] = [ 'applies', 'waits', 'refused', 'pricing' ];

function groupTitle( group: LineGroup ): string {
	switch ( group ) {
		case 'applies':
			return __( 'Applies now', 'beyond-elysium' );
		case 'waits':
			return __( 'Waits for a Storyteller', 'beyond-elysium' );
		case 'refused':
			return __( 'Cannot be submitted', 'beyond-elysium' );
		default:
			return __( 'Pricing…', 'beyond-elysium' );
	}
}

function priceText( line: CartLine ): string {
	if ( line.priceNote ) {
		return line.priceNote;
	}
	return sprintf(
		/* translators: %d: the XP a change in the cart costs */
		__( '%d XP', 'beyond-elysium' ),
		line.cost
	);
}

export interface CartReviewProps {
	/**
	 * Opens the character's History, where the order is tracked.
	 */
	onSeeHistory?: () => void;
}

export function CartReview( { onSeeHistory }: CartReviewProps ) {
	const store = useCharacterEditorStore();
	const [ note, setNote ] = useState( '' );
	const [ emptying, setEmptying ] = useState( false );

	if ( ! store.reviewOpen || ! store.character ) {
		return null;
	}

	const view = cartView(
		store.pendingChanges,
		store.previewCosts,
		store.character,
		!! store.character.can_manage,
		store.stack?.blocks
	);
	const sent =
		store.lastSubmit && store.lastSubmit.failed.length === 0
			? store.lastSubmit
			: null;
	const droppedNames = store.droppedBlocks.map(
		( slug ) => store.stack?.blocks[ slug ]?.name ?? slug
	);
	const dropped = droppedMessage( droppedNames );
	const held = heldLine( view.totals.waitsCost );
	const canSubmit =
		! store.saving &&
		view.lines.length > 0 &&
		view.totals.refused === 0 &&
		view.totals.pricing === 0 &&
		! view.over;

	async function submit() {
		const result = await store.submitChanges( note );
		if ( result.failed.length === 0 ) {
			setNote( '' );
		}
	}

	const close = () => {
		setNote( '' );
		store.closeReview();
	};

	return (
		<>
			<Modal
				title={ __( 'Review your cart', 'beyond-elysium' ) }
				onClose={ close }
				className="be-cart-review"
				footer={
					sent && view.lines.length === 0 ? (
						<>
							{ onSeeHistory && (
								<button
									type="button"
									onClick={ () => {
										close();
										onSeeHistory();
									} }
								>
									{ __(
										'See it in History',
										'beyond-elysium'
									) }
								</button>
							) }
							<button type="button" onClick={ close }>
								{ __( 'Close', 'beyond-elysium' ) }
							</button>
						</>
					) : (
						<>
							<button
								type="button"
								disabled={
									store.saving || view.lines.length === 0
								}
								onClick={ () => setEmptying( true ) }
							>
								{ __( 'Empty cart', 'beyond-elysium' ) }
							</button>
							<button
								type="button"
								className="be-cart-review__submit"
								disabled={ ! canSubmit }
								onClick={ submit }
							>
								{ store.saving
									? __( 'Submitting…', 'beyond-elysium' )
									: __( 'Submit', 'beyond-elysium' ) }
							</button>
						</>
					)
				}
			>
				{ sent && (
					<p className="be-cart-review__sent" role="status">
						{ confirmationLine(
							sent.applied,
							sent.waiting,
							sent.held
						) }
					</p>
				) }
				{ store.error && (
					<p className="be-cart-review__error" role="alert">
						{ store.error }
					</p>
				) }
				{ dropped && (
					<p className="be-cart-review__dropped" role="status">
						{ dropped }{ ' ' }
						<button
							type="button"
							onClick={ () => store.dismissDropped() }
						>
							{ __( 'Dismiss', 'beyond-elysium' ) }
						</button>
					</p>
				) }
				{ ! sent && view.lines.length === 0 && (
					<p>{ __( 'Your cart is empty.', 'beyond-elysium' ) }</p>
				) }
				{ GROUP_ORDER.map( ( group ) => {
					const lines = view.lines.filter(
						( line ) => line.group === group
					);
					if ( lines.length === 0 ) {
						return null;
					}
					return (
						<section
							className="be-cart-review__group"
							key={ group }
						>
							<h4>{ groupTitle( group ) }</h4>
							<ul>
								{ lines.map( ( line ) => (
									<li
										key={ line.index }
										className={
											group === 'refused'
												? 'be-cart-review__line be-cart-review__line--refused'
												: 'be-cart-review__line'
										}
									>
										<span className="be-cart-review__text">
											{ line.text }
										</span>
										<span className="be-cart-review__price">
											{ group === 'pricing'
												? ''
												: priceText( line ) }
										</span>
										<button
											type="button"
											className="be-cart-review__remove"
											disabled={ store.saving }
											aria-label={ sprintf(
												/* translators: %s: what the cart line does, such as "Occult x3 → x4" */
												__(
													'Remove %s',
													'beyond-elysium'
												),
												line.text
											) }
											onClick={ () =>
												store.removeLine( line.index )
											}
										>
											{ __( 'Remove', 'beyond-elysium' ) }
										</button>
										{ line.reason && (
											<span className="be-cart-review__reason">
												{ line.reason }
											</span>
										) }
										{ line.coordinatorNote && (
											<span className="be-cart-review__reason">
												{ line.coordinatorNote }
											</span>
										) }
									</li>
								) ) }
							</ul>
						</section>
					);
				} ) }
				{ view.lines.length > 0 && (
					<dl className="be-cart-review__sums">
						<div>
							<dt>{ __( 'Total', 'beyond-elysium' ) }</dt>
							<dd>
								{ sprintf(
									/* translators: %d: the XP the whole cart costs */
									__( '%d XP', 'beyond-elysium' ),
									view.totals.cost
								) }
							</dd>
						</div>
						<div>
							<dt>{ __( 'Spent now', 'beyond-elysium' ) }</dt>
							<dd>
								{ sprintf(
									/* translators: %d: the XP the lines that apply now cost */
									__( '%d XP', 'beyond-elysium' ),
									view.totals.appliesCost
								) }
							</dd>
						</div>
						<div>
							<dt>
								{ view.over
									? __( 'Over', 'beyond-elysium' )
									: __( 'Left after', 'beyond-elysium' ) }
							</dt>
							<dd
								className={
									view.over
										? 'be-cart-review__over'
										: undefined
								}
							>
								{ sprintf(
									/* translators: %d: XP left, or how far over the cart is, once it is paid for */
									__( '%d XP', 'beyond-elysium' ),
									Math.abs( view.left )
								) }
							</dd>
						</div>
					</dl>
				) }
				{ held && <p className="be-cart-review__held">{ held }</p> }
				{ view.totals.waitsCost > 0 ||
				view.lines.some( ( line ) => line.group === 'waits' ) ? (
					<label className="be-cart-review__note">
						<span>
							{ __(
								'Note to your Storyteller',
								'beyond-elysium'
							) }
						</span>
						<textarea
							value={ note }
							maxLength={ NOTE_LIMIT }
							rows={ 3 }
							onChange={ ( e ) => setNote( e.target.value ) }
						/>
						<span className="be-cart-review__count">
							{ sprintf(
								/* translators: 1: characters written, 2: the most allowed */
								__( '%1$d of %2$d', 'beyond-elysium' ),
								note.length,
								NOTE_LIMIT
							) }
						</span>
					</label>
				) : null }
			</Modal>
			<ConfirmDialog
				open={ emptying }
				title={ __( 'Empty the cart?', 'beyond-elysium' ) }
				message={ __(
					'Every line goes back as the sheet has it.',
					'beyond-elysium'
				) }
				confirmLabel={ __( 'Empty cart', 'beyond-elysium' ) }
				onConfirm={ () => {
					setEmptying( false );
					setNote( '' );
					store.reset();
				} }
				onCancel={ () => setEmptying( false ) }
			/>
		</>
	);
}

export default CartReview;
