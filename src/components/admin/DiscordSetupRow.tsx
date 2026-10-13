/**
 * Chronicle Setup's Discord row panel: connecting a server with a one-time code, or disconnecting one already
 * connected. The HST sees the controls; an AST sees the status alone.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import type { BotConnectCode, BotConnectionStatus } from '../../types';
import './DiscordSetupRow.css';

export interface DiscordSetupRowProps {
	gameSlug: string;
}

export function DiscordSetupRow( { gameSlug }: DiscordSetupRowProps ) {
	const [ connection, setConnection ] =
		useState< BotConnectionStatus | null >( null );
	const [ canConnect, setCanConnect ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ code, setCode ] = useState< BotConnectCode | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	function load() {
		setLoading( true );
		Promise.all( [
			api.bot( gameSlug ).connection(),
			api.games.myCapabilities( gameSlug ),
		] )
			.then( ( [ connectionStatus, caps ] ) => {
				setConnection( connectionStatus );
				setCanConnect( caps.capabilities.be_manage_chronicle_setup );
			} )
			.catch( () => setConnection( null ) )
			.finally( () => setLoading( false ) );
	}

	useEffect( load, [ gameSlug ] );

	function generateCode() {
		setBusy( true );
		setError( null );
		api.bot( gameSlug )
			.generateCode()
			.then( setCode )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'Could not generate a code.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setBusy( false ) );
	}

	function disconnect() {
		if (
			! window.confirm(
				__(
					'Disconnect this chronicle from its Discord server?',
					'beyond-elysium'
				)
			)
		) {
			return;
		}
		setBusy( true );
		api.bot( gameSlug )
			.disconnect()
			.then( () => {
				setCode( null );
				load();
			} )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'Could not disconnect.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setBusy( false ) );
	}

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	if ( connection?.connected ) {
		return (
			<div className="be-discord-setup-row">
				<p>
					{ sprintf(
						/* translators: %s: the connected Discord server's name */
						__( 'Connected to %s.', 'beyond-elysium' ),
						connection.guild_name ?? ''
					) }
				</p>
				{ connection.last_seen_at && (
					<p className="be-discord-setup-row__hint">
						{ sprintf(
							/* translators: %s: when the bot was last heard from */
							__( 'Last heard from at %s.', 'beyond-elysium' ),
							connection.last_seen_at
						) }
					</p>
				) }
				{ error && (
					<p className="be-discord-setup-row__error" role="alert">
						{ error }
					</p>
				) }
				{ canConnect ? (
					<button
						type="button"
						className="button"
						disabled={ busy }
						onClick={ disconnect }
					>
						{ __( 'Disconnect', 'beyond-elysium' ) }
					</button>
				) : (
					<p className="be-discord-setup-row__hint">
						{ __(
							'Only the Head Storyteller can disconnect it.',
							'beyond-elysium'
						) }
					</p>
				) }
			</div>
		);
	}

	if ( ! canConnect ) {
		return (
			<p className="be-discord-setup-row__hint">
				{ __(
					'Ask your Head Storyteller to connect this chronicle to a Discord server.',
					'beyond-elysium'
				) }
			</p>
		);
	}

	return (
		<div className="be-discord-setup-row">
			{ error && (
				<p className="be-discord-setup-row__error" role="alert">
					{ error }
				</p>
			) }
			{ code ? (
				<div>
					<p>
						{ __(
							'Run this in the Discord server you want to connect:',
							'beyond-elysium'
						) }
					</p>
					<code className="be-discord-setup-row__command">
						{ code.connect_command }
					</code>
					<p className="be-discord-setup-row__hint">
						{ sprintf(
							/* translators: %d: how many minutes the code lasts */
							__(
								'This works once and lasts %d minutes.',
								'beyond-elysium'
							),
							Math.round( code.expires_in / 60 )
						) }
					</p>
					{ code.invite_url ? (
						<p>
							<a
								href={ code.invite_url }
								target="_blank"
								rel="noreferrer"
							>
								{ __(
									'Invite the bot to a server',
									'beyond-elysium'
								) }
							</a>
						</p>
					) : (
						<p className="be-discord-setup-row__hint">
							{ __(
								'Ask OWbN for the bot’s invite link.',
								'beyond-elysium'
							) }
						</p>
					) }
				</div>
			) : (
				<button
					type="button"
					className="button"
					disabled={ busy }
					onClick={ generateCode }
				>
					{ __( 'Generate a connect code', 'beyond-elysium' ) }
				</button>
			) }
		</div>
	);
}

export default DiscordSetupRow;
