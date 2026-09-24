/**
 * A labeled dropdown letting a viewer pick which of their own chronicles a tabbed page is currently showing.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { MyGame } from '../../types';
import './ChronicleSwitcher.css';

export interface ChronicleSwitcherProps {
	games: MyGame[];
	gameSlug: string;
	onChange: ( slug: string ) => void;
	loading: boolean;
	/**
	 * The membership request failed.
	 */
	failed?: boolean;
	onRetry?: () => void;
}

export function ChronicleSwitcher( {
	games,
	gameSlug,
	onChange,
	loading,
	failed = false,
	onRetry,
}: ChronicleSwitcherProps ) {
	if ( loading ) {
		return (
			<p className="be-chronicle-switcher__status">
				{ __( 'Loading your chronicles…', 'beyond-elysium' ) }
			</p>
		);
	}

	// A failed request is not an empty membership list.
	if ( failed ) {
		return (
			<p className="be-chronicle-switcher__status" role="alert">
				{ __(
					"Your chronicles couldn't be loaded.",
					'beyond-elysium'
				) }{ ' ' }
				{ onRetry && (
					<button
						type="button"
						className="be-chronicle-switcher__retry"
						onClick={ onRetry }
					>
						{ __( 'Try again', 'beyond-elysium' ) }
					</button>
				) }
			</p>
		);
	}

	if ( games.length === 0 ) {
		return (
			<p className="be-chronicle-switcher__status">
				{ __(
					"You don't belong to any chronicle yet.",
					'beyond-elysium'
				) }
			</p>
		);
	}

	return (
		<label className="be-chronicle-switcher">
			{ __( 'Chronicle', 'beyond-elysium' ) }
			<select
				className="be-chronicle-switcher__select"
				value={ gameSlug }
				onChange={ ( e ) => onChange( e.target.value ) }
				aria-label={ __( 'Chronicle', 'beyond-elysium' ) }
			>
				{ games.map( ( g ) => (
					<option key={ g.slug } value={ g.slug }>
						{ sprintf(
							/* translators: 1: chronicle name, 2: the viewer's role there */
							__( '%1$s (%2$s)', 'beyond-elysium' ),
							g.name,
							g.role
						) }
					</option>
				) ) }
			</select>
		</label>
	);
}

export default ChronicleSwitcher;
