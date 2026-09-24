/**
 * Site-wide AI Assist settings: which provider is active, and its API key.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { AiAssistSiteSettings } from '../../api/client';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

import { errorMessage } from '../../lib/errorMessage';

type DisplayProvider = 'openai' | 'claude' | 'self_hosted';

export function AdminAiAssistSite() {
	const [ settings, setSettings ] = useState< AiAssistSiteSettings | null >(
		null
	);
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
		api.aiAssistSite
			.getSettings()
			.then( ( result ) => {
				setSettings( result );
				setOpenaiBaseUrl( result.openai_base_url );
				setOpenaiModel( result.openai_model );
				// A saved OpenAI-slot base URL means this was configured as self-hosted.
				const isSelfHosted =
					result.provider === 'openai' &&
					( result.openai_base_url !== '' ||
						result.openai_model !== '' );
				setDisplay( isSelfHosted ? 'self_hosted' : result.provider );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			);
	}, [] );

	async function save( e: React.FormEvent ) {
		e.preventDefault();
		setSaving( true );
		setError( null );
		setMessage( null );
		try {
			// A blank key field is left untouched.
			const data: Partial< {
				provider: string;
				openai_key: string;
				claude_key: string;
				openai_base_url: string;
				openai_model: string;
				claude_base_url: string;
				claude_model: string;
			} > = {
				provider: display === 'claude' ? 'claude' : 'openai',
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
			const updated = await api.aiAssistSite.updateSettings( data );
			setSettings( updated );
			setOpenaiKey( '' );
			setClaudeKey( '' );
			setSelfHostedKey( '' );
			setMessage( __( 'Saved.', 'beyond-elysium' ) );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSaving( false );
		}
	}

	async function clearKey( which: 'openai' | 'claude' ) {
		setSaving( true );
		setError( null );
		try {
			const updated = await api.aiAssistSite.updateSettings(
				which === 'openai' ? { openai_key: '' } : { claude_key: '' }
			);
			setSettings( updated );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
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
			const response = await api.aiAssistSite.testConnection( {
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
				message: errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				),
			} );
		} finally {
			setTesting( null );
		}
	}

	if ( ! settings ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'AI Assist', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="writing-assist-site" />
			</div>
			<p>
				{ __(
					'A site-wide API key, used for catalog-level fields (Schema Block descriptions, Credits text) and as the default for any chronicle that opts in without supplying its own key. Never shown once saved - re-enter it to change it.',
					'beyond-elysium'
				) }
			</p>
			<p className="description">
				{ __(
					'This must be a real API key from platform.openai.com or console.anthropic.com - not a ChatGPT Plus or Claude Pro login. Those consumer subscriptions cannot be used here; the API is billed separately and only for what is actually used, with no monthly fee.',
					'beyond-elysium'
				) }
			</p>

			{ error && (
				<p className="be-admin__error" role="alert">
					{ error }
				</p>
			) }
			{ message && <p role="status">{ message }</p> }

			<form className="be-admin__form" onSubmit={ save }>
				<label>
					{ __( 'Provider', 'beyond-elysium' ) }
					<select
						value={ display }
						onChange={ ( e ) =>
							setDisplay( e.target.value as DisplayProvider )
						}
					>
						<option value="openai">
							{ __( 'OpenAI (ChatGPT)', 'beyond-elysium' ) }
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

				{ display === 'openai' && (
					<fieldset className="be-admin__fieldset">
						<legend>{ __( 'OpenAI', 'beyond-elysium' ) }</legend>
						<label>
							{ __( 'API key', 'beyond-elysium' ) }
							<input
								type="password"
								value={ openaiKey }
								onChange={ ( e ) =>
									setOpenaiKey( e.target.value )
								}
								placeholder={
									settings.has_openai_key
										? __(
												'•••••••• (configured - leave blank to keep)',
												'beyond-elysium'
										  )
										: __( 'sk-…', 'beyond-elysium' )
								}
							/>
							{ settings.has_openai_key && (
								<button
									type="button"
									onClick={ () => clearKey( 'openai' ) }
									disabled={ saving }
								>
									{ __( 'Clear', 'beyond-elysium' ) }
								</button>
							) }
							<button
								type="button"
								onClick={ testConnection }
								disabled={ testing !== null }
							>
								{ testing === 'openai'
									? __( 'Testing…', 'beyond-elysium' )
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
								role={ testResult.ok ? 'status' : 'alert' }
							>
								{ testResult.message }
							</p>
						) }
					</fieldset>
				) }

				{ display === 'claude' && (
					<fieldset className="be-admin__fieldset">
						<legend>{ __( 'Claude', 'beyond-elysium' ) }</legend>
						<label>
							{ __( 'API key', 'beyond-elysium' ) }
							<input
								type="password"
								value={ claudeKey }
								onChange={ ( e ) =>
									setClaudeKey( e.target.value )
								}
								placeholder={
									settings.has_claude_key
										? __(
												'•••••••• (configured - leave blank to keep)',
												'beyond-elysium'
										  )
										: __( 'sk-ant-…', 'beyond-elysium' )
								}
							/>
							{ settings.has_claude_key && (
								<button
									type="button"
									onClick={ () => clearKey( 'claude' ) }
									disabled={ saving }
								>
									{ __( 'Clear', 'beyond-elysium' ) }
								</button>
							) }
							<button
								type="button"
								onClick={ testConnection }
								disabled={ testing !== null }
							>
								{ testing === 'claude'
									? __( 'Testing…', 'beyond-elysium' )
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
								role={ testResult.ok ? 'status' : 'alert' }
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
							{ __( 'API base URL', 'beyond-elysium' ) }
							<input
								type="text"
								value={ openaiBaseUrl }
								onChange={ ( e ) =>
									setOpenaiBaseUrl( e.target.value )
								}
								placeholder="http://localhost:11434/v1/chat/completions"
							/>
						</label>
						<label>
							{ __( 'Model', 'beyond-elysium' ) }
							<input
								type="text"
								value={ openaiModel }
								onChange={ ( e ) =>
									setOpenaiModel( e.target.value )
								}
								placeholder="llama3"
							/>
						</label>
						<label>
							{ __( 'API key', 'beyond-elysium' ) }
							<input
								type="password"
								value={ selfHostedKey }
								onChange={ ( e ) =>
									setSelfHostedKey( e.target.value )
								}
								placeholder={
									settings.has_openai_key
										? __(
												'•••••••• (configured - leave blank to keep)',
												'beyond-elysium'
										  )
										: __(
												'many self-hosted servers accept any value here',
												'beyond-elysium'
										  )
								}
							/>
							{ settings.has_openai_key && (
								<button
									type="button"
									onClick={ () => clearKey( 'openai' ) }
									disabled={ saving }
								>
									{ __( 'Clear', 'beyond-elysium' ) }
								</button>
							) }
							<button
								type="button"
								onClick={ testConnection }
								disabled={ testing !== null }
							>
								{ testing === 'self_hosted'
									? __( 'Testing…', 'beyond-elysium' )
									: __(
											'Test Connection',
											'beyond-elysium'
									  ) }
							</button>
						</label>
						<p className="description">
							{ __(
								'A value is still required even if your server doesn\'t check it - check your server\'s own docs for whether any string works (e.g. "not-needed") or it expects a real token.',
								'beyond-elysium'
							) }
						</p>
						{ testResult?.which === 'self_hosted' && (
							<p
								className={
									testResult.ok
										? undefined
										: 'be-admin__error'
								}
								role={ testResult.ok ? 'status' : 'alert' }
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
		</div>
	);
}

export default AdminAiAssistSite;
