/**
 * A small "AI Assist" button that sits next to an existing free-text field
 * (ai-writing-assist-design.md) - never wraps or replaces the field itself,
 * so no existing layout needs restructuring. Gated by whichever
 * management-tier capability already governs that field, resolved
 * server-side against the request's own field_context on every call - the
 * `capability` prop here only controls this button's own visibility, it is
 * never trusted as the real access check.
 *
 * Never auto-publishes anything: a suggestion always renders in a preview
 * popover with explicit Accept/Discard actions. Accept calls onAccept with
 * the suggestion text - the field's own existing save button is what
 * actually persists it, exactly like any other edit to that field.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import Modal from './Modal';
import './AiAssistButton.css';

export interface AiAssistButtonProps {
	/** Which be_manage_* capability gates this field - checked client-side only for visibility; the server re-checks authoritatively against fieldContext. */
	capability: string;
	/** One of Services/Ai_Assist.php's FIELD_CONTEXTS keys. */
	fieldContext: string;
	/** Omit for a site-wide field (Schema Block descriptions, Credits). */
	gameSlug?: string;
	/** The field's current value. A function is required for an otherwise-uncontrolled field (HtmlEditor/TinyMCE) so the button always reads the live content, not a stale render-time snapshot. */
	currentValue: string | ( () => string );
	onAccept: ( suggestion: string ) => void;
}

/**
 * Renders nothing when the viewer doesn't hold the given capability - the
 * ordinary consequence of capability-gating this button per field, which is
 * also what keeps the feature invisible to anyone it isn't meant for (a
 * player never sees this next to their own character's biography, since
 * that field is gated on be_manage_characters here, not the plain
 * edit-tier capability that field's own save route accepts).
 */
export function AiAssistButton( { capability, fieldContext, gameSlug, currentValue, onAccept }: AiAssistButtonProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ instruction, setInstruction ] = useState( '' );
	const [ suggestion, setSuggestion ] = useState<string | null>( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

	const canUse = !! window.beyondElysium?.capabilities?.[ capability ];
	if ( ! canUse ) {
		return null;
	}

	const resolvedValue = typeof currentValue === 'function' ? currentValue() : currentValue;
	const hasExistingText = resolvedValue.trim() !== '';

	function open() {
		setSuggestion( null );
		setError( null );
		setInstruction( '' );
		setIsOpen( true );
	}

	async function generate() {
		setLoading( true );
		setError( null );
		try {
			const client = gameSlug ? api.aiAssist( gameSlug ) : api.aiAssistSite;
			const response = await client.generate( {
				field_context: fieldContext,
				current_text: typeof currentValue === 'function' ? currentValue() : currentValue,
				instruction,
			} );
			setSuggestion( response.suggestion );
		} catch ( err: unknown ) {
			const message =
				typeof err === 'object' && err !== null && 'message' in err
					? String( ( err as { message?: unknown } ).message )
					: __( 'Something went wrong.', 'beyond-elysium' );
			setError( message );
		} finally {
			setLoading( false );
		}
	}

	function accept() {
		if ( suggestion ) {
			onAccept( suggestion );
		}
		setIsOpen( false );
	}

	return (
		<>
			<button type="button" className="be-ai-assist-button" onClick={ open }>
				{ __( 'AI Assist', 'beyond-elysium' ) }
			</button>
			{ isOpen && (
				<Modal
					title={ __( 'AI Assist', 'beyond-elysium' ) }
					onClose={ () => setIsOpen( false ) }
					footer={
						suggestion ? (
							<>
								<button type="button" onClick={ () => setSuggestion( null ) }>
									{ __( 'Back', 'beyond-elysium' ) }
								</button>
								<button type="button" onClick={ generate } disabled={ loading }>
									{ __( 'Regenerate', 'beyond-elysium' ) }
								</button>
								<button type="button" className="button-primary" onClick={ accept }>
									{ __( 'Accept', 'beyond-elysium' ) }
								</button>
							</>
						) : (
							<>
								<button type="button" onClick={ () => setIsOpen( false ) }>
									{ __( 'Cancel', 'beyond-elysium' ) }
								</button>
								<button type="button" className="button-primary" onClick={ generate } disabled={ loading }>
									{ loading
										? __( 'Generating…', 'beyond-elysium' )
										: hasExistingText
										? __( 'Polish this', 'beyond-elysium' )
										: __( 'Generate', 'beyond-elysium' ) }
								</button>
							</>
						)
					}
				>
					{ error && (
						<p className="be-ai-assist-button__error" role="alert">
							{ error }
						</p>
					) }
					{ suggestion ? (
						<div className="be-ai-assist-button__suggestion">
							<p className="description">{ __( 'Review before accepting - nothing is saved until you click Accept, and the field itself still needs its own Save afterward.', 'beyond-elysium' ) }</p>
							<div className="be-ai-assist-button__suggestion-text">{ suggestion }</div>
						</div>
					) : hasExistingText ? (
						<p className="description">
							{ __( 'This will ask the AI to improve the field\'s current text, keeping its meaning and details.', 'beyond-elysium' ) }
						</p>
					) : (
						<label>
							{ __( "What should this be about?", 'beyond-elysium' ) }
							<textarea
								value={ instruction }
								onChange={ ( e ) => setInstruction( e.target.value ) }
								placeholder={ __( 'e.g. a stoic Brujah who lost everything in the Anarch Revolt', 'beyond-elysium' ) }
								rows={ 3 }
							/>
						</label>
					) }
				</Modal>
			) }
		</>
	);
}

export default AiAssistButton;
