/**
 * The budget bar pinned at the top of the builder: the rule lines of the step in view and the experience left, and
 * the whole tally one tap away.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Modal from '../shared/Modal';
import CreationTallyPanel from '../character/CreationTallyPanel';
import type { BarModel } from '../../lib/builderBar';
import type { CreationTally } from '../../types/character';

export interface BuilderBarProps {
	model: BarModel;
	tally: CreationTally | null;
	loading: boolean;
	gameSlug: string;
	stackSlug: string;
	sheetData: Record< string, unknown >;
}

export function BuilderBar( {
	model,
	tally,
	loading,
	gameSlug,
	stackSlug,
	sheetData,
}: BuilderBarProps ) {
	const [ open, setOpen ] = useState( false );

	if ( ! tally && ! loading ) {
		return null;
	}

	return (
		<div className="be-builder-bar" role="status" aria-live="polite">
			<button
				type="button"
				className="be-builder-bar__open"
				aria-haspopup="dialog"
				onClick={ () => setOpen( true ) }
			>
				<span className="be-builder-bar__lines">
					{ model.lines.length === 0 && loading && (
						<span className="be-builder-bar__line">
							{ __( 'Loading…', 'beyond-elysium' ) }
						</span>
					) }
					{ model.lines.map( ( line ) => (
						<span
							key={ line.key }
							className={ `be-builder-bar__line be-builder-bar__line--${ line.state }` }
						>
							{ line.text }
						</span>
					) ) }
				</span>
			</button>
			{ model.note && (
				<p className="be-builder-bar__note">{ model.note }</p>
			) }
			{ open && (
				<Modal
					title={ __( 'Build Tally', 'beyond-elysium' ) }
					onClose={ () => setOpen( false ) }
					className="be-builder-tally"
				>
					<CreationTallyPanel
						gameSlug={ gameSlug }
						stackSlug={ stackSlug }
						sheetData={ sheetData }
						warnsPlayer
					/>
				</Modal>
			) }
		</div>
	);
}

export default BuilderBar;
