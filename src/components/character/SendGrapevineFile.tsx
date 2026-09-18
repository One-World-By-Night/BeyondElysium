/**
 * SendGrapevineFile (F-122): anyone signed in reads an uploaded Grapevine file, picks which
 * character is theirs when the file holds more than one, says whether they're joining the
 * chronicle or visiting for a game, and sends it. Nothing is added to the chronicle until a
 * Storyteller reviews and accepts it. Reachable with no chronicle membership at all - the
 * chronicle picker lists every chronicle on the site, not just the sender's own.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { readGameSlugFromUrl } from '../../lib/useChronicleSwitcher';
import { characterSheetUrl } from '../../lib/pluginPages';
import { otherChroniclesFor } from '../../lib/sendGrapevineFile';
import type { MyGame, Game } from '../../types';
import type {
	Submission,
	SubmissionPreviewResponse,
} from '../../types/submission';
import HelpButton from '../shared/HelpButton';
import './SendGrapevineFile.css';

import { errorMessage } from '../../lib/errorMessage';

const STATUS_LABEL: Record< Submission[ 'state' ], string > = {
	waiting: __( 'Waiting for review', 'beyond-elysium' ),
	accepted: __( 'Accepted', 'beyond-elysium' ),
	refused: __( 'Not accepted', 'beyond-elysium' ),
	withdrawn: __( 'Withdrawn', 'beyond-elysium' ),
	expired: __( 'No one reviewed it within 60 days', 'beyond-elysium' ),
};

export function SendGrapevineFile() {
	const [ myChronicles, setMyChronicles ] = useState< MyGame[] >( [] );
	const [ allChronicles, setAllChronicles ] = useState< Game[] >( [] );
	const [ gameSlug, setGameSlug ] = useState( readGameSlugFromUrl );
	const [ file, setFile ] = useState< File | null >( null );
	const [ preview, setPreview ] =
		useState< SubmissionPreviewResponse | null >( null );
	const [ characterIndex, setCharacterIndex ] = useState< number | null >(
		null
	);
	const [ arrival, setArrival ] = useState< 'joining' | 'visiting' >(
		'joining'
	);
	const [ homeChronicle, setHomeChronicle ] = useState( '' );
	const [ reading, setReading ] = useState( false );
	const [ sending, setSending ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ mySubmissions, setMySubmissions ] = useState< Submission[] >( [] );

	useEffect( () => {
		api.games
			.mine()
			.then( setMyChronicles )
			.catch( () => setMyChronicles( [] ) );
		api.games
			.list( { per_page: 100 } )
			.then( setAllChronicles )
			.catch( () => setAllChronicles( [] ) );
	}, [] );

	function loadMySubmissions() {
		api.mySubmissions()
			.list()
			.then( setMySubmissions )
			.catch( () => setMySubmissions( [] ) );
	}
	useEffect( loadMySubmissions, [] );

	function resetFile() {
		setFile( null );
		setPreview( null );
		setCharacterIndex( null );
	}

	async function readFile( picked: File ) {
		setFile( picked );
		setPreview( null );
		setCharacterIndex( null );
		setError( null );
		if ( ! gameSlug ) {
			setError(
				__(
					'Choose a chronicle before reading a file.',
					'beyond-elysium'
				)
			);
			return;
		}
		setReading( true );
		try {
			const result = await api.submissions( gameSlug ).preview( picked );
			setPreview( result );
			if ( result.characters.length === 1 ) {
				setCharacterIndex( result.characters[ 0 ].index );
			}
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setReading( false );
		}
	}

	async function send() {
		if ( ! file || characterIndex === null ) {
			return;
		}
		setSending( true );
		setError( null );
		setNotice( null );
		try {
			const chosenGameSlug = gameSlug;
			await api.submissions( chosenGameSlug ).create( file, arrival, {
				characterIndex,
				homeChronicle: homeChronicle || undefined,
			} );
			const chronicleName =
				myChronicles.find( ( g ) => g.slug === chosenGameSlug )?.name ??
				allChronicles.find( ( g ) => g.slug === chosenGameSlug )
					?.name ??
				chosenGameSlug;
			setNotice(
				sprintf(
					/* translators: %s: chronicle name */
					__(
						"Sent. %s's Storytellers will look it over, and you'll get an email when they do.",
						'beyond-elysium'
					),
					chronicleName
				)
			);
			resetFile();
			setHomeChronicle( '' );
			loadMySubmissions();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSending( false );
		}
	}

	async function withdraw( row: Submission ) {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				sprintf(
					/* translators: 1: character name, 2: chronicle name */
					__(
						"Withdraw %1$s? %2$s's Storytellers won't see it.",
						'beyond-elysium'
					),
					row.character_name,
					row.game_name ?? ''
				)
			)
		) {
			return;
		}
		try {
			await api.submissions( row.game_slug ?? '' ).withdraw( row.id );
			loadMySubmissions();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		}
	}

	const otherChronicles = otherChroniclesFor( myChronicles, allChronicles );

	const chosenCharacter = preview?.characters.find(
		( c ) => c.index === characterIndex
	);
	const canSend =
		! sending &&
		! reading &&
		file &&
		preview &&
		characterIndex !== null &&
		chosenCharacter?.allowed;

	return (
		<div className="be-send-grapevine-file">
			<div className="be-help-heading">
				<h2>{ __( 'Send a Grapevine File', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="send-grapevine-file" />
			</div>
			{ error && (
				<div className="be-import-tool__error" role="alert">
					{ error }
				</div>
			) }
			{ notice && (
				<div className="be-import-tool__notice" role="status">
					{ notice }
				</div>
			) }

			<label className="be-send-grapevine-file__field">
				{ __( 'Chronicle', 'beyond-elysium' ) }
				<select
					value={ gameSlug }
					onChange={ ( e ) => {
						setGameSlug( e.target.value );
						resetFile();
					} }
				>
					<option value="">
						{ __( 'Choose a chronicle', 'beyond-elysium' ) }
					</option>
					{ myChronicles.length > 0 && (
						<optgroup
							label={ __( 'Your chronicles', 'beyond-elysium' ) }
						>
							{ myChronicles.map( ( g ) => (
								<option key={ g.slug } value={ g.slug }>
									{ g.name }
								</option>
							) ) }
						</optgroup>
					) }
					{ otherChronicles.length > 0 && (
						<optgroup
							label={ __( 'Other chronicles', 'beyond-elysium' ) }
						>
							{ otherChronicles.map( ( g ) => (
								<option key={ g.slug } value={ g.slug }>
									{ g.name }
								</option>
							) ) }
						</optgroup>
					) }
				</select>
			</label>

			<label className="be-send-grapevine-file__field">
				{ __( 'Grapevine file', 'beyond-elysium' ) }
				<input
					type="file"
					accept=".gex"
					onChange={ ( e ) => {
						const picked = e.target.files?.[ 0 ];
						if ( picked ) {
							readFile( picked );
						}
					} }
				/>
			</label>
			{ reading && <p>{ __( 'Reading file…', 'beyond-elysium' ) }</p> }

			{ preview && preview.characters.length === 1 && (
				<p>
					{ chosenCharacter?.allowed
						? sprintf(
								/* translators: 1: character name, 2: creature type */
								__(
									'This file holds %1$s (%2$s).',
									'beyond-elysium'
								),
								chosenCharacter.name,
								chosenCharacter.stack_name ??
									chosenCharacter.stack_slug
						  )
						: chosenCharacter?.reason }
					{ chosenCharacter?.verifiable && (
						<>
							{ ' ' }
							{ __(
								'This file carries a verification code. The Storytellers will see whether it still matches.',
								'beyond-elysium'
							) }
						</>
					) }
				</p>
			) }

			{ preview && preview.characters.length > 1 && (
				<fieldset className="be-send-grapevine-file__characters">
					<legend>
						{ __(
							'This file holds several characters. Which one is yours?',
							'beyond-elysium'
						) }
					</legend>
					{ preview.characters.map( ( c ) => (
						<label key={ c.index }>
							<input
								type="radio"
								name="character"
								disabled={ ! c.allowed }
								checked={ characterIndex === c.index }
								onChange={ () => setCharacterIndex( c.index ) }
							/>{ ' ' }
							{ c.name } ({ c.stack_name ?? c.stack_slug })
							{ ! c.allowed && (
								<span className="be-send-grapevine-file__disallowed">
									{ ' - ' }
									{ c.reason }
								</span>
							) }
						</label>
					) ) }
				</fieldset>
			) }

			{ preview && (
				<fieldset className="be-send-grapevine-file__arrival">
					<legend>
						{ __(
							'Are you joining or visiting?',
							'beyond-elysium'
						) }
					</legend>
					<label>
						<input
							type="radio"
							name="arrival"
							checked={ arrival === 'joining' }
							onChange={ () => setArrival( 'joining' ) }
						/>{ ' ' }
						{ sprintf(
							/* translators: %s: chronicle name */
							__( 'Joining %s', 'beyond-elysium' ),
							myChronicles.find( ( g ) => g.slug === gameSlug )
								?.name ??
								allChronicles.find(
									( g ) => g.slug === gameSlug
								)?.name ??
								gameSlug
						) }
					</label>{ ' ' }
					<label>
						<input
							type="radio"
							name="arrival"
							checked={ arrival === 'visiting' }
							onChange={ () => setArrival( 'visiting' ) }
						/>{ ' ' }
						{ __( 'Visiting for a game', 'beyond-elysium' ) }
					</label>
					{ arrival === 'visiting' && (
						<label className="be-send-grapevine-file__field">
							{ __( 'Home chronicle', 'beyond-elysium' ) }
							<input
								type="text"
								value={ homeChronicle }
								placeholder={ __(
									'For example, Kings of New York',
									'beyond-elysium'
								) }
								onChange={ ( e ) =>
									setHomeChronicle( e.target.value )
								}
							/>
						</label>
					) }
				</fieldset>
			) }

			<p>
				<button type="button" disabled={ ! canSend } onClick={ send }>
					{ sending
						? __( 'Sending…', 'beyond-elysium' )
						: __( 'Send', 'beyond-elysium' ) }
				</button>
			</p>

			<h3>{ __( 'Your sent files', 'beyond-elysium' ) }</h3>
			{ mySubmissions.length === 0 ? (
				<p>
					{ __(
						"You haven't sent a Grapevine file to any chronicle yet.",
						'beyond-elysium'
					) }
				</p>
			) : (
				<div className="be-table-box">
					<table className="be-admin__table be-responsive-table">
						<thead>
							<tr>
								<th>{ __( 'Character', 'beyond-elysium' ) }</th>
								<th>{ __( 'Chronicle', 'beyond-elysium' ) }</th>
								<th>{ __( 'Sent', 'beyond-elysium' ) }</th>
								<th>{ __( 'Status', 'beyond-elysium' ) }</th>
								<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ mySubmissions.map( ( row ) => (
								<tr key={ row.id }>
									<td
										data-label={ __(
											'Character',
											'beyond-elysium'
										) }
									>
										{ row.character_name }
									</td>
									<td
										data-label={ __(
											'Chronicle',
											'beyond-elysium'
										) }
									>
										{ row.game_name }
									</td>
									<td
										data-label={ __(
											'Sent',
											'beyond-elysium'
										) }
									>
										{ row.created_at }
									</td>
									<td
										data-label={ __(
											'Status',
											'beyond-elysium'
										) }
									>
										{ STATUS_LABEL[ row.state ] }
										{ row.state === 'refused' &&
											row.answer_note && (
												<>
													<br />
													<small>
														{ row.answer_note }
													</small>
												</>
											) }
									</td>
									<td
										data-label={ __(
											'Actions',
											'beyond-elysium'
										) }
									>
										{ row.state === 'waiting' && (
											<button
												type="button"
												onClick={ () =>
													withdraw( row )
												}
											>
												{ __(
													'Withdraw',
													'beyond-elysium'
												) }
											</button>
										) }
										{ row.state === 'accepted' &&
											row.character_id && (
												<a
													href={ characterSheetUrl(
														row.character_id,
														row.game_slug ?? ''
													) }
												>
													{ __(
														'Open sheet',
														'beyond-elysium'
													) }
												</a>
											) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

export default SendGrapevineFile;
