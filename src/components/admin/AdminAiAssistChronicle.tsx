/**
 * A chronicle's own AI Assist settings (ai-writing-assist-design.md): opt-in
 * toggle, provider choice, and an optional key of the chronicle's own that
 * overrides the site-wide default for its own content (character/plot/
 * rumor/world-object fields). be_manage_apr - the same access tier as, and
 * the same Chronicle Setup hub as, Action & Rumor Settings, so an HST can
 * configure this without needing site-administrator access.
 *
 * A clear three-way choice - OpenAI, Claude, or Self-Hosted (OpenAI-
 * compatible) - only one of which is ever shown at a time. See
 * AdminAiAssistSite.tsx's own docblock for why "Self-Hosted" stores as
 * provider `openai` with a base URL/model override rather than being a
 * fourth backend value.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { AiAssistChronicleSettings } from '../../api/client';
import type { Game } from '../../types';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

interface RestError {
	message?: string;
}

function errorMessage( error: unknown ): string {
	if (
		typeof error === 'object' &&
		error !== null &&
		( error as RestError ).message
	) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

type DisplayProvider = 'openai' | 'claude' | 'self_hosted';

export function AdminAiAssistChronicle() {
	const [ games, setGames ] = useState< Game[] >( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ settings, setSettings ] =
		useState< AiAssistChronicleSettings | null >( null );
	const [ display, setDisplay ] = useState< DisplayProvider >( 'openai' );
	const [ openaiKey, setOpenaiKey ] = useState( '' );
	const [ claudeKey, setClaudeKey ] = useState( '' );
	const [ selfHostedKey, setSelfHostedKey ] = useState( '' );
	const [ openaiBaseUrl, setOpenaiBaseUrl ] = useState( '' );
	const [ openaiModel, setOpenaiModel ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState< DisplayProvider | null >( null );
	const [ testResult, setTestResult ] = useState< {
		which: DisplayProvider;
		ok: boolean;
		message: string;
	} | null >( null );
	const [ message, setMessage ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		api.games
			.list()
			.then( ( found ) => {
				setGames( found );
				if ( ! gameSlug && found.length > 0 ) {
					setGameSlug( found[ 0 ].slug );
				}
			} )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		if ( ! gameSlug ) {
			return;
		}
		api.aiAssist( gameSlug )
			.getSettings()
			.then( ( result ) => {
				setSettings( result );
				setOpenaiBaseUrl( result.openai_base_url );
				setOpenaiModel( result.openai_model );
				const isSelfHosted =
					result.provider === 'openai' &&
					( result.openai_base_url !== '' ||
						result.openai_model !== '' );
				setDisplay( isSelfHosted ? 'self_hosted' : result.provider );
			} )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) );
	}, [ gameSlug ] );

	async function toggleEnabled( enabled: boolean ) {
		if ( ! settings ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			setSettings(
				await api.aiAssist( gameSlug ).updateSettings( { enabled } )
			);
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	async function chooseDisplay( next: DisplayProvider ) {
		setDisplay( next );
		setSaving( true );
		setError( null );
		try {
			setSettings(
				await api.aiAssist( gameSlug ).updateSettings( {
					provider: next === 'claude' ? 'claude' : 'openai',
				} )
			);
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	async function save( e: React.FormEvent ) {
		e.preventDefault();
		setSaving( true );
		setError( null );
		setMessage( null );
		try {
			const data: Partial< {
				openai_key: string;
				claude_key: string;
				openai_base_url: string;
				openai_model: string;
				claude_base_url: string;
				claude_model: string;
			} > = {
				openai_base_url: display === 'self_hosted' ? openaiBaseUrl : '',
				openai_model: display === 'self_hosted' ? openaiModel : '',
				claude_base_url: '',
				claude_model: '',
			};
			if ( display === 'openai' && openaiKey !== '' ) {
				data.openai_key = openaiKey;
			}
			if ( display === 'self_hosted' && selfHostedKey !== '' ) {
				data.openai_key = selfHostedKey;
			}
			if ( display === 'claude' && claudeKey !== '' ) {
				data.claude_key = claudeKey;
			}
			const updated = await api
				.aiAssist( gameSlug )
				.updateSettings( data );
			setSettings( updated );
			setOpenaiKey( '' );
			setClaudeKey( '' );
			setSelfHostedKey( '' );
			setMessage( __( 'Saved.', 'beyond-elysium' ) );
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	async function clearKey( which: 'openai' | 'claude' ) {
		setSaving( true );
		setError( null );
		try {
			setSettings(
				await api
					.aiAssist( gameSlug )
					.updateSettings(
						which === 'openai'
							? { openai_key: '' }
							: { claude_key: '' }
					)
			);
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	async function testConnection() {
		const key =
			display === 'claude'
				? claudeKey
				: display === 'self_hosted'
				? selfHostedKey
				: openaiKey;
		if ( key === '' ) {
			setTestResult( {
				which: display,
				ok: false,
				message: __(
					'Type the key above first - a saved key is never sent back to this page, so it has to be re-entered to test it.',
					'beyond-elysium'
				),
			} );
			return;
		}
		setTesting( display );
		setTestResult( null );
		try {
			const response = await api.aiAssist( gameSlug ).testConnection( {
				provider: display === 'claude' ? 'claude' : 'openai',
				key,
				base_url: display === 'self_hosted' ? openaiBaseUrl : '',
				model: display === 'self_hosted' ? openaiModel : '',
			} );
			setTestResult( {
				which: display,
				ok: true,
				message: response.message,
			} );
		} catch ( err ) {
			setTestResult( {
				which: display,
				ok: false,
				message: errorMessage( err ),
			} );
		} finally {
			setTesting( null );
		}
	}

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'AI Assist', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="writing-assist-chronicle" />
			</div>
			<p>
				{ __(
					'Opt this chronicle in to the AI writing-assist buttons on its own character, plot, rumor, and world-object fields. Falls back to the site-wide key when enabled with none of its own.',
					'beyond-elysium'
				) }
			</p>
			<p className="description">
				{ __(
					'If you give this chronicle its own key below, it must be a real API key from platform.openai.com or console.anthropic.com - not a ChatGPT Plus or Claude Pro login, which cannot be used here. Leave the key field blank to use the site-wide key instead.',
					'beyond-elysium'
				) }
			</p>

			{ error && (
				<p className="be-admin__error" role="alert">
					{ error }
				</p>
			) }

			<label>
				{ __( 'Chronicle', 'beyond-elysium' ) }
				<select
					value={ gameSlug }
					onChange={ ( e ) => setGameSlug( e.target.value ) }
				>
					{ games.map( ( g ) => (
						<option key={ g.slug } value={ g.slug }>
							{ g.name }
						</option>
					) ) }
				</select>
			</label>

			{ settings && (
				<>
					<label className="be-admin__block-label">
						<input
							type="checkbox"
							checked={ settings.enabled }
							disabled={ saving }
							onChange={ ( e ) =>
								toggleEnabled( e.target.checked )
							}
						/>{ ' ' }
						{ __(
							'Enable AI Assist for this chronicle',
							'beyond-elysium'
						) }
					</label>

					{ settings.enabled && (
						<>
							<label className="be-admin__block-label">
								{ __( 'Provider', 'beyond-elysium' ) }
								<select
									value={ display }
									onChange={ ( e ) =>
										chooseDisplay(
											e.target.value as DisplayProvider
										)
									}
								>
									<option value="openai">
										{ __(
											'OpenAI (ChatGPT)',
											'beyond-elysium'
										) }
									</option>
									<option value="claude">
										{ __( 'Claude', 'beyond-elysium' ) }
									</option>
									<option value="self_hosted">
										{ __(
											'Self-Hosted (OpenAI-compatible)',
											'beyond-elysium'
										) }
									</option>
								</select>
							</label>
							<p className="description">
								{ __(
									'Only the selected option’s settings are shown below.',
									'beyond-elysium'
								) }
							</p>

							{ message && <p role="status">{ message }</p> }

							<form className="be-admin__form" onSubmit={ save }>
								<p className="description">
									{ __(
										'Optional - leave the key blank to use the site-wide key instead.',
										'beyond-elysium'
									) }
								</p>

								{ display === 'openai' && (
									<fieldset className="be-admin__fieldset">
										<legend>
											{ __( 'OpenAI', 'beyond-elysium' ) }
										</legend>
										<label>
											{ __(
												"This chronicle's own API key",
												'beyond-elysium'
											) }
											<input
												type="password"
												value={ openaiKey }
												onChange={ ( e ) =>
													setOpenaiKey(
														e.target.value
													)
												}
												placeholder={
													settings.has_openai_key
														? __(
																'•••••••• (configured - leave blank to keep)',
																'beyond-elysium'
														  )
														: __(
																'sk-…',
																'beyond-elysium'
														  )
												}
											/>
											{ settings.has_openai_key && (
												<button
													type="button"
													onClick={ () =>
														clearKey( 'openai' )
													}
													disabled={ saving }
												>
													{ __(
														'Clear',
														'beyond-elysium'
													) }
												</button>
											) }
											<button
												type="button"
												onClick={ testConnection }
												disabled={ testing !== null }
											>
												{ testing === 'openai'
													? __(
															'Testing…',
															'beyond-elysium'
													  )
													: __(
															'Test Connection',
															'beyond-elysium'
													  ) }
											</button>
										</label>
										{ testResult?.which === 'openai' && (
											<p
												className={
													testResult.ok
														? undefined
														: 'be-admin__error'
												}
												role={
													testResult.ok
														? 'status'
														: 'alert'
												}
											>
												{ testResult.message }
											</p>
										) }
									</fieldset>
								) }

								{ display === 'claude' && (
									<fieldset className="be-admin__fieldset">
										<legend>
											{ __( 'Claude', 'beyond-elysium' ) }
										</legend>
										<label>
											{ __(
												"This chronicle's own API key",
												'beyond-elysium'
											) }
											<input
												type="password"
												value={ claudeKey }
												onChange={ ( e ) =>
													setClaudeKey(
														e.target.value
													)
												}
												placeholder={
													settings.has_claude_key
														? __(
																'•••••••• (configured - leave blank to keep)',
																'beyond-elysium'
														  )
														: __(
																'sk-ant-…',
																'beyond-elysium'
														  )
												}
											/>
											{ settings.has_claude_key && (
												<button
													type="button"
													onClick={ () =>
														clearKey( 'claude' )
													}
													disabled={ saving }
												>
													{ __(
														'Clear',
														'beyond-elysium'
													) }
												</button>
											) }
											<button
												type="button"
												onClick={ testConnection }
												disabled={ testing !== null }
											>
												{ testing === 'claude'
													? __(
															'Testing…',
															'beyond-elysium'
													  )
													: __(
															'Test Connection',
															'beyond-elysium'
													  ) }
											</button>
										</label>
										{ testResult?.which === 'claude' && (
											<p
												className={
													testResult.ok
														? undefined
														: 'be-admin__error'
												}
												role={
													testResult.ok
														? 'status'
														: 'alert'
												}
											>
												{ testResult.message }
											</p>
										) }
									</fieldset>
								) }

								{ display === 'self_hosted' && (
									<fieldset className="be-admin__fieldset">
										<legend>
											{ __(
												'Self-Hosted (OpenAI-compatible)',
												'beyond-elysium'
											) }
										</legend>
										<p className="description">
											{ __(
												'Anything that speaks the same Chat Completions request/response shape at its own URL - Ollama, LM Studio, vLLM, LocalAI, and similar.',
												'beyond-elysium'
											) }
										</p>
										<label>
											{ __(
												'API base URL',
												'beyond-elysium'
											) }
											<input
												type="text"
												value={ openaiBaseUrl }
												onChange={ ( e ) =>
													setOpenaiBaseUrl(
														e.target.value
													)
												}
												placeholder={ __(
													'Leave blank to use the site-wide setting',
													'beyond-elysium'
												) }
											/>
										</label>
										<label>
											{ __( 'Model', 'beyond-elysium' ) }
											<input
												type="text"
												value={ openaiModel }
												onChange={ ( e ) =>
													setOpenaiModel(
														e.target.value
													)
												}
												placeholder={ __(
													'Leave blank to use the site-wide setting',
													'beyond-elysium'
												) }
											/>
										</label>
										<label>
											{ __(
												"This chronicle's own API key",
												'beyond-elysium'
											) }
											<input
												type="password"
												value={ selfHostedKey }
												onChange={ ( e ) =>
													setSelfHostedKey(
														e.target.value
													)
												}
												placeholder={
													settings.has_openai_key
														? __(
																'•••••••• (configured - leave blank to keep)',
																'beyond-elysium'
														  )
														: __(
																'leave blank to use the site-wide key/server',
																'beyond-elysium'
														  )
												}
											/>
											{ settings.has_openai_key && (
												<button
													type="button"
													onClick={ () =>
														clearKey( 'openai' )
													}
													disabled={ saving }
												>
													{ __(
														'Clear',
														'beyond-elysium'
													) }
												</button>
											) }
											<button
												type="button"
												onClick={ testConnection }
												disabled={ testing !== null }
											>
												{ testing === 'self_hosted'
													? __(
															'Testing…',
															'beyond-elysium'
													  )
													: __(
															'Test Connection',
															'beyond-elysium'
													  ) }
											</button>
										</label>
										{ testResult?.which ===
											'self_hosted' && (
											<p
												className={
													testResult.ok
														? undefined
														: 'be-admin__error'
												}
												role={
													testResult.ok
														? 'status'
														: 'alert'
												}
											>
												{ testResult.message }
											</p>
										) }
									</fieldset>
								) }

								<div className="be-admin__form-actions">
									<button type="submit" disabled={ saving }>
										{ saving
											? __( 'Saving…', 'beyond-elysium' )
											: __( 'Save', 'beyond-elysium' ) }
									</button>
								</div>
							</form>
						</>
					) }
				</>
			) }
		</div>
	);
}

export default AdminAiAssistChronicle;
