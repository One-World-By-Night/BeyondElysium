/**
 * The `?` that opens a screen's help page in the side panel (1.0.0-help.md H-1). One panel is
 * open at a time: opening another screen's help closes this one, and closing the panel puts
 * focus back on the `?` that opened it.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useHelpStore } from '../../store/helpStore';
import HelpPanel from './HelpPanel';
import './HelpPanel.css';

export interface HelpButtonProps {
	/** A file name in `docs/help/`, without `.md`. */
	helpKey: string;
}

export function HelpButton( { helpKey }: HelpButtonProps ) {
	const owner = useRef( Symbol( helpKey ) ).current;
	const isOpen = useHelpStore( ( state ) => state.owner === owner );
	const open = useHelpStore( ( state ) => state.open );
	const close = useHelpStore( ( state ) => state.close );
	const buttonRef = useRef< HTMLButtonElement >( null );

	// The panel goes with the screen that opened it.
	useEffect( () => () => close( owner ), [ close, owner ] );

	const label = __( 'Help for this screen', 'beyond-elysium' );

	return (
		<>
			<button
				ref={ buttonRef }
				type="button"
				className="be-help-button"
				aria-expanded={ isOpen }
				aria-label={ label }
				title={ label }
				onClick={ () => ( isOpen ? close( owner ) : open( owner ) ) }
			>
				?
			</button>
			{ isOpen && (
				<HelpPanel
					helpKey={ helpKey }
					onClose={ () => {
						close( owner );
						buttonRef.current?.focus();
					} }
				/>
			) }
		</>
	);
}

export default HelpButton;
