/**
 * A labeled dropdown letting a viewer pick which of their own chronicles a tabbed page is currently showing.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { MyGame } from '../../types';
import './ChronicleSwitcher.css';

/**
 * One chronicle's own label in the switcher dropdown: its name and the viewer's role there, "- Demo" where it is
 * one, and a pending count where the chronicle isn't the one currently selected and has one.
 */
export function switcherOptionLabel(
	game: MyGame,
	gameSlug: string,
	pendingCounts: Record< string, number > = {}
): string {
	const base = game.demo
		? sprintf(
				/* translators: 1: chronicle name, 2: the viewer's role there */
				__( '%1$s (%2$s) - Demo', 'beyond-elysium' ),
				game.name,
				game.role
			)
		: sprintf(
				/* translators: 1: chronicle name, 2: the viewer's role there */
				__( '%1$s (%2$s)', 'beyond-elysium' ),
				game.name,
				game.role
			);
	const pending =
		game.slug !== gameSlug ? ( pendingCounts[ game.slug ] ?? 0 ) : 0;
	return pending > 0
		? sprintf(
				/* translators: 1: the chronicle's own label, 2: number of the player's own changes pending there */
				__( '%1$s - %2$d pending', 'beyond-elysium' ),
				base,
				pending
			)
		: base;
}

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
	/**
	 * How many of the player's own changes are pending in each chronicle, by slug - shown on any chronicle other than
	 * the one currently selected.
	 */
	pendingCounts?: Record< string, number >;
}

export function ChronicleSwitcher( {
	games,
	gameSlug,
	onChange,
	loading,
	failed = false,
	onRetry,
	pendingCounts = {},
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
						{ switcherOptionLabel( g, gameSlug, pendingCounts ) }
					</option>
				) ) }
			</select>
		</label>
	);
}

export default ChronicleSwitcher;
