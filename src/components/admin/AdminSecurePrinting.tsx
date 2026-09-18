/**
 * Secure printing settings (1.0.1 C2-C4): the site-wide opt-in, what is currently
 * configured, and a generator for sites that cannot mint a certificate themselves.
 *
 * The plugin never installs a certificate. This screen will hand one over exactly once - the
 * key is in that response and nowhere else, never on disk and never in the database - along
 * with the wp-config.php lines to paste. Everything after that is SFTP and a text editor,
 * deliberately.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import type { SigningStatus, GeneratedCertificate } from '../../api/client';
import './Admin.css';
import './AdminSecurePrinting.css';

import { errorMessage } from '../../lib/errorMessage';

export function AdminSecurePrinting() {
	const [ status, setStatus ] = useState< SigningStatus | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ generating, setGenerating ] = useState( false );
	const [ passphrase, setPassphrase ] = useState( '' );
	const [ commonName, setCommonName ] = useState( '' );
	const [ generated, setGenerated ] = useState< GeneratedCertificate | null >(
		null
	);

	function load() {
		api.signing
			.status()
			.then( setStatus )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			);
	}

	useEffect( load, [] );

	async function toggle( enabled: boolean ) {
		setSaving( true );
		setError( null );
		try {
			await api.signing.updateSettings( enabled );
			load();
		} catch ( err: unknown ) {
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

	async function generate( e: React.FormEvent ) {
		e.preventDefault();
		setGenerating( true );
		setError( null );
		try {
			setGenerated(
				await api.signing.generateCertificate( {
					passphrase,
					common_name: commonName || undefined,
				} )
			);
			// The passphrase is not kept here either - it is in wp-config.php or nowhere.
			setPassphrase( '' );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setGenerating( false );
		}
	}

	if ( ! status ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	const certRow = status.constants.BE_PDF_SIGNING_CERT;
	const keyRow = status.constants.BE_PDF_SIGNING_KEY;
	const passRow = status.constants.BE_PDF_SIGNING_PASSPHRASE;

	function describe( row: { defined: boolean; readable: boolean | null } ) {
		if ( ! row.defined ) {
			return __( 'Not set', 'beyond-elysium' );
		}
		if ( row.readable === false ) {
			return __( 'Set, but the file cannot be read', 'beyond-elysium' );
		}
		return __( 'Set', 'beyond-elysium' );
	}

	return (
		<div className="be-admin be-secure-printing">
			<div className="be-help-heading">
				<h2>{ __( 'Secure Printing', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="secure-printing" />
			</div>

			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			<p>
				{ __(
					'Printing always works. With secure printing off, or with no certificate installed, sheets and reports still print through the same typesetter - every page stamped UNSIGNED.',
					'beyond-elysium'
				) }
			</p>

			<h3>{ __( 'Status', 'beyond-elysium' ) }</h3>
			<table className="be-admin__table be-secure-printing__status">
				<tbody>
					<tr>
						<th>{ __( 'Signing right now', 'beyond-elysium' ) }</th>
						<td>
							{ status.signing_now
								? __(
										'Yes - prints are signed',
										'beyond-elysium'
								  )
								: __(
										'No - prints are stamped UNSIGNED',
										'beyond-elysium'
								  ) }
						</td>
					</tr>
					<tr>
						<th>
							<code>BE_PDF_SIGNING_CERT</code>
						</th>
						<td>{ describe( certRow ) }</td>
					</tr>
					<tr>
						<th>
							<code>BE_PDF_SIGNING_KEY</code>
						</th>
						<td>{ describe( keyRow ) }</td>
					</tr>
					<tr>
						<th>
							<code>BE_PDF_SIGNING_PASSPHRASE</code>
						</th>
						<td>
							{ passRow.defined
								? __( 'Set', 'beyond-elysium' )
								: __(
										'Not set - fine if the key has no passphrase',
										'beyond-elysium'
								  ) }
						</td>
					</tr>
				</tbody>
			</table>

			<h3>{ __( 'Secure printing', 'beyond-elysium' ) }</h3>
			<label className="be-secure-printing__toggle">
				<input
					type="checkbox"
					checked={ status.enabled }
					disabled={ saving || ! status.available }
					onChange={ ( e ) => toggle( e.target.checked ) }
				/>
				{ __( 'Sign printed sheets and reports', 'beyond-elysium' ) }
			</label>
			{ ! status.available && (
				<p className="be-secure-printing__note">
					{ __(
						'Switched off and unavailable until a certificate is installed below.',
						'beyond-elysium'
					) }
				</p>
			) }

			<h3>{ __( 'Installing a certificate', 'beyond-elysium' ) }</h3>
			<p>
				{ __(
					'The plugin never holds your private key. Put the two files somewhere outside the web root, then add these three lines to wp-config.php:',
					'beyond-elysium'
				) }
			</p>
			<pre className="be-secure-printing__code">
				{ `define( 'BE_PDF_SIGNING_CERT', '/home/you/private/be-signing.crt' );
define( 'BE_PDF_SIGNING_KEY', '/home/you/private/be-signing.key' );
define( 'BE_PDF_SIGNING_PASSPHRASE', 'your passphrase' );` }
			</pre>
			<p>
				{ __(
					'If you have shell access, this is the command both production chronicles used:',
					'beyond-elysium'
				) }
			</p>
			<pre className="be-secure-printing__code">
				{ `openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 \\
  -keyout be-signing.key -out be-signing.crt -cipher aes-256-cbc` }
			</pre>

			<h3>{ __( 'No shell access?', 'beyond-elysium' ) }</h3>
			{ ! status.can_generate ? (
				<p className="be-secure-printing__note">
					{ __(
						"This server's PHP has no openssl extension, so it cannot generate a certificate here - and cannot sign a PDF by any other route either. Printing still works, stamped UNSIGNED.",
						'beyond-elysium'
					) }
				</p>
			) : (
				<>
					<p>
						{ __(
							'Generate a self-signed pair below. It is built in memory and handed to you once - nothing is written to this server or saved in the database. Copy both files somewhere safe before leaving the page; asking again makes a different certificate.',
							'beyond-elysium'
						) }
					</p>
					<form onSubmit={ generate }>
						<label>
							{ __( 'Signer name', 'beyond-elysium' ) }
							<input
								type="text"
								value={ commonName }
								placeholder={ __(
									'Your chronicle or site name',
									'beyond-elysium'
								) }
								onChange={ ( e ) =>
									setCommonName( e.target.value )
								}
							/>
						</label>
						<label>
							{ __( 'Passphrase', 'beyond-elysium' ) }
							<input
								type="password"
								value={ passphrase }
								autoComplete="new-password"
								onChange={ ( e ) =>
									setPassphrase( e.target.value )
								}
							/>
						</label>
						<button
							type="submit"
							disabled={ generating || passphrase.length < 8 }
						>
							{ generating
								? __( 'Generating…', 'beyond-elysium' )
								: __(
										'Generate a certificate',
										'beyond-elysium'
								  ) }
						</button>
					</form>
				</>
			) }

			{ generated && (
				<div className="be-secure-printing__generated">
					<h3>{ __( 'Your certificate', 'beyond-elysium' ) }</h3>
					<p>
						{ sprintf(
							/* translators: 1: signer name, 2: expiry date. */
							__(
								'Signed as "%1$s", valid until %2$s. This is the only time these are shown.',
								'beyond-elysium'
							),
							generated.common_name,
							generated.expires
						) }
					</p>
					<h4>{ __( 'be-signing.crt', 'beyond-elysium' ) }</h4>
					<textarea
						readOnly
						rows={ 8 }
						value={ generated.certificate }
					/>
					<h4>{ __( 'be-signing.key', 'beyond-elysium' ) }</h4>
					<p className="be-secure-printing__note">
						{ __(
							'Keep this private. Put it outside the web root, readable only by the web server.',
							'beyond-elysium'
						) }
					</p>
					<textarea
						readOnly
						rows={ 8 }
						value={ generated.private_key }
					/>
					<p>
						{ __(
							'A PDF reader will say "signature valid, signer not trusted". That is expected for a self-signed certificate, and is what a chronicle attesting to its own sheets actually means.',
							'beyond-elysium'
						) }
					</p>
				</div>
			) }
		</div>
	);
}

export default AdminSecurePrinting;
