/**
 * The Players tab's Discord section: links waiting for a Storyteller's decision, and links already approved.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import {
	splitLinksByStatus,
	discordLinkCharacterNames,
} from '../../lib/discordLinksSection';
import type { DiscordLink } from '../../types';

export interface ChronicleDiscordLinksProps {
	gameSlug: string;
}

export function ChronicleDiscordLinks( {
	gameSlug,
}: ChronicleDiscordLinksProps ) {
	const [ links, setLinks ] = useState< DiscordLink[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState< number | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	function load() {
		setLoading( true );
		api.discordLinks( gameSlug )
			.list()
			.then( setLinks )
			.catch( () => setLinks( [] ) )
			.finally( () => setLoading( false ) );
	}

	useEffect( load, [ gameSlug ] );

	function act(
		link: DiscordLink,
		action: 'approve' | 'decline' | 'revoke'
	) {
		setBusy( link.id );
		setError( null );
		api.discordLinks( gameSlug )
			[ action ]( link.id )
			.then( load )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'That did not work.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setBusy( null ) );
	}

	const { waiting, approved } = splitLinksByStatus( links );

	function characterNames( link: DiscordLink ): string {
		return discordLinkCharacterNames(
			link,
			__( 'no characters', 'beyond-elysium' )
		);
	}

	return (
		<section className="be-chronicle-players__discord">
			<h3>
				{ sprintf(
					/* translators: %d: how many Discord links wait for a decision */
					__(
						'Discord, waiting for a decision (%d)',
						'beyond-elysium'
					),
					waiting.length
				) }
			</h3>
			{ error && (
				<p className="be-chronicle-players__hint" role="alert">
					{ error }
				</p>
			) }
			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : waiting.length === 0 ? (
				<p className="be-chronicle-players__hint">
					{ __( 'Nothing is waiting.', 'beyond-elysium' ) }
				</p>
			) : (
				<ul className="be-chronicle-players__list">
					{ waiting.map( ( link ) => (
						<li key={ link.id }>
							<span className="be-chronicle-players__name">
								{ link.discord_username }
								<span className="be-chronicle-players__email">
									{ link.wp_user?.display_name }
									{ ' · ' }
									{ characterNames( link ) }
								</span>
							</span>
							<button
								type="button"
								className="be-chronicle-players__button"
								disabled={ busy === link.id }
								onClick={ () => act( link, 'approve' ) }
							>
								{ __( 'Approve', 'beyond-elysium' ) }
							</button>{ ' ' }
							<button
								type="button"
								className="be-chronicle-players__button be-chronicle-players__button--remove"
								disabled={ busy === link.id }
								onClick={ () => act( link, 'decline' ) }
							>
								{ __( 'Decline', 'beyond-elysium' ) }
							</button>
						</li>
					) ) }
				</ul>
			) }

			<h3>
				{ sprintf(
					/* translators: %d: how many Discord accounts are linked */
					__( 'Discord, linked (%d)', 'beyond-elysium' ),
					approved.length
				) }
			</h3>
			{ ! loading && approved.length === 0 && (
				<p className="be-chronicle-players__hint">
					{ __(
						'No Discord account is linked yet.',
						'beyond-elysium'
					) }
				</p>
			) }
			{ approved.length > 0 && (
				<ul className="be-chronicle-players__list">
					{ approved.map( ( link ) => (
						<li key={ link.id }>
							<span className="be-chronicle-players__name">
								{ link.discord_username }
								<span className="be-chronicle-players__email">
									{ link.wp_user?.display_name }
									{ ' · ' }
									{ characterNames( link ) }
									{ ' · ' }
									{ link.room_channel_id
										? sprintf(
												/* translators: %s: a Discord channel id */
												__(
													'Room: %s',
													'beyond-elysium'
												),
												link.room_channel_id
											)
										: __(
												'No room set',
												'beyond-elysium'
											) }
								</span>
							</span>
							<button
								type="button"
								className="be-chronicle-players__button be-chronicle-players__button--remove"
								disabled={ busy === link.id }
								onClick={ () => act( link, 'revoke' ) }
							>
								{ __( 'Revoke', 'beyond-elysium' ) }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}

export default ChronicleDiscordLinks;
