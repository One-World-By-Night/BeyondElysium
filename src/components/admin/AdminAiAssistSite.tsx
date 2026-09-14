/**
 * Site-wide AI Assist settings (ai-writing-assist-design.md): which provider
 * is active, and its API key - used directly by every site-wide field
 * (Schema Block catalog descriptions, Credits text), and as the fallback for
 * any chronicle that opts in without supplying its own key. be_manage_games
 * only - an administrator's own call, since this key is billed to whoever
 * supplies it and used across every chronicle on the site by default.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { AiAssistSiteSettings } from '../../api/client';
import './Admin.css';

interface RestError {
	message?: string;
}

function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

type Provider = 'openai' | 'claude';

export function AdminAiAssistSite() {
	const [ settings, setSettings ] = useState<AiAssistSiteSettings | null>( null );
	const [ provider, setProvider ] = useState<Provider>( 'openai' );
	const [ openaiKey, setOpenaiKey ] = useState( '' );
	const [ claudeKey, setClaudeKey ] = useState( '' );
	const [ openaiBaseUrl, setOpenaiBaseUrl ] = useState( '' );
	const [ openaiModel, setOpenaiModel ] = useState( '' );
	const [ claudeBaseUrl, setClaudeBaseUrl ] = useState( '' );
	const [ claudeModel, setClaudeModel ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState<Provider | null>( null );
	const [ testResult, setTestResult ] = useState<{ which: Provider; ok: boolean; message: string } | null>( null );
	const [ message, setMessage ] = useState<string | null>( null );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		api.aiAssistSite
			.getSettings()
			.then( ( result ) => {
				setSettings( result );
				setProvider( result.provider );
				setOpenaiBaseUrl( result.openai_base_url );
				setOpenaiModel( result.openai_model );
				setClaudeBaseUrl( result.claude_base_url );
				setClaudeModel( result.claude_model );
			} )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) );
	}, [] );

	async function save( e: React.FormEvent ) {
		e.preventDefault();
		setSaving( true );
		setError( null );
		setMessage( null );
		try {
			// A blank key field is left untouched, not cleared - only an explicit "Clear" click
			// sends the empty string that actually removes a stored key.
			const data: Partial<{
				provider: string; openai_key: string; claude_key: string;
				openai_base_url: string; openai_model: string; claude_base_url: string; claude_model: string;
			}> = { provider, openai_base_url: openaiBaseUrl, openai_model: openaiModel, claude_base_url: claudeBaseUrl, claude_model: claudeModel };
			if ( openaiKey !== '' ) {
				data.openai_key = openaiKey;
			}
			if ( claudeKey !== '' ) {
				data.claude_key = claudeKey;
			}
			const updated = await api.aiAssistSite.updateSettings( data );
			setSettings( updated );
			setOpenaiKey( '' );
			setClaudeKey( '' );
			setMessage( __( 'Saved.', 'beyond-elysium' ) );
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	async function clearKey( which: Provider ) {
		setSaving( true );
		setError( null );
		try {
			const updated = await api.aiAssistSite.updateSettings(
				which === 'openai' ? { openai_key: '' } : { claude_key: '' }
			);
			setSettings( updated );
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	async function testConnection( which: Provider ) {
		const key = which === 'openai' ? openaiKey : claudeKey;
		if ( key === '' ) {
			setTestResult( { which, ok: false, message: __( 'Type the key above first - a saved key is never sent back to this page, so it has to be re-entered to test it.', 'beyond-elysium' ) } );
			return;
		}
		setTesting( which );
		setTestResult( null );
		try {
			const response = await api.aiAssistSite.testConnection( {
				provider: which,
				key,
				base_url: which === 'openai' ? openaiBaseUrl : claudeBaseUrl,
				model: which === 'openai' ? openaiModel : claudeModel,
			} );
			setTestResult( { which, ok: true, message: response.message } );
		} catch ( err ) {
			setTestResult( { which, ok: false, message: errorMessage( err ) } );
		} finally {
			setTesting( null );
		}
	}

	if ( ! settings ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	return (
		<div className="be-admin">
			<h1>{ __( 'AI Assist', 'beyond-elysium' ) }</h1>
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

			{ error && <p className="be-admin__error" role="alert">{ error }</p> }
			{ message && <p role="status">{ message }</p> }

			<form className="be-admin__form" onSubmit={ save }>
				<label>
					{ __( 'Default provider', 'beyond-elysium' ) }
					<select value={ provider } onChange={ ( e ) => setProvider( e.target.value as Provider ) }>
						<option value="openai">OpenAI</option>
						<option value="claude">Claude</option>
					</select>
				</label>

				<fieldset className="be-admin__fieldset">
					<legend>{ __( 'OpenAI', 'beyond-elysium' ) }</legend>
					<label>
						{ __( 'API key', 'beyond-elysium' ) }
						<input
							type="password"
							value={ openaiKey }
							onChange={ ( e ) => setOpenaiKey( e.target.value ) }
							placeholder={ settings.has_openai_key ? __( '•••••••• (configured - leave blank to keep)', 'beyond-elysium' ) : __( 'sk-…', 'beyond-elysium' ) }
						/>
						{ settings.has_openai_key && (
							<button type="button" onClick={ () => clearKey( 'openai' ) } disabled={ saving }>
								{ __( 'Clear', 'beyond-elysium' ) }
							</button>
						) }
						<button type="button" onClick={ () => testConnection( 'openai' ) } disabled={ testing !== null }>
							{ testing === 'openai' ? __( 'Testing…', 'beyond-elysium' ) : __( 'Test Connection', 'beyond-elysium' ) }
						</button>
					</label>
					{ testResult?.which === 'openai' && (
						<p className={ testResult.ok ? undefined : 'be-admin__error' } role={ testResult.ok ? 'status' : 'alert' }>
							{ testResult.message }
						</p>
					) }
					<label>
						{ __( 'Custom API base URL (optional)', 'beyond-elysium' ) }
						<input
							type="text"
							value={ openaiBaseUrl }
							onChange={ ( e ) => setOpenaiBaseUrl( e.target.value ) }
							placeholder="https://api.openai.com/v1/chat/completions"
						/>
					</label>
					<p className="description">
						{ __(
							'Leave blank for the real OpenAI API. Set this to point at a self-hosted or otherwise-compatible server instead (Ollama, LM Studio, vLLM, LocalAI, ...) - anything that speaks the same Chat Completions request/response shape at its own URL.',
							'beyond-elysium'
						) }
					</p>
					<label>
						{ __( 'Model override (optional)', 'beyond-elysium' ) }
						<input
							type="text"
							value={ openaiModel }
							onChange={ ( e ) => setOpenaiModel( e.target.value ) }
							placeholder="gpt-4o-mini"
						/>
					</label>
				</fieldset>

				<fieldset className="be-admin__fieldset">
					<legend>{ __( 'Claude', 'beyond-elysium' ) }</legend>
					<label>
						{ __( 'API key', 'beyond-elysium' ) }
						<input
							type="password"
							value={ claudeKey }
							onChange={ ( e ) => setClaudeKey( e.target.value ) }
							placeholder={ settings.has_claude_key ? __( '•••••••• (configured - leave blank to keep)', 'beyond-elysium' ) : __( 'sk-ant-…', 'beyond-elysium' ) }
						/>
						{ settings.has_claude_key && (
							<button type="button" onClick={ () => clearKey( 'claude' ) } disabled={ saving }>
								{ __( 'Clear', 'beyond-elysium' ) }
							</button>
						) }
						<button type="button" onClick={ () => testConnection( 'claude' ) } disabled={ testing !== null }>
							{ testing === 'claude' ? __( 'Testing…', 'beyond-elysium' ) : __( 'Test Connection', 'beyond-elysium' ) }
						</button>
					</label>
					{ testResult?.which === 'claude' && (
						<p className={ testResult.ok ? undefined : 'be-admin__error' } role={ testResult.ok ? 'status' : 'alert' }>
							{ testResult.message }
						</p>
					) }
					<label>
						{ __( 'Custom API base URL (optional)', 'beyond-elysium' ) }
						<input
							type="text"
							value={ claudeBaseUrl }
							onChange={ ( e ) => setClaudeBaseUrl( e.target.value ) }
							placeholder="https://api.anthropic.com/v1/messages"
						/>
					</label>
					<label>
						{ __( 'Model override (optional)', 'beyond-elysium' ) }
						<input
							type="text"
							value={ claudeModel }
							onChange={ ( e ) => setClaudeModel( e.target.value ) }
							placeholder="claude-haiku-4-5-20251001"
						/>
					</label>
				</fieldset>

				<div className="be-admin__form-actions">
					<button type="submit" disabled={ saving }>
						{ saving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
					</button>
				</div>
			</form>
		</div>
	);
}

export default AdminAiAssistSite;
