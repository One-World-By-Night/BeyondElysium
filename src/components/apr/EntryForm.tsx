/**
 * Form for adding a new entry to a plot's timeline.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import HtmlEditor from '../shared/HtmlEditor';
import type {
	CharacterOption,
	EntryAudienceValue,
	EntryType,
} from '../../types/plot';
import { errorMessage } from '../../lib/errorMessage';
import {
	chargeComplete,
	chargePayload,
	type ChargeChoice,
} from '../../lib/answerCharge';
import type { SpendableBackground } from '../../types/apr';
import './EntryForm.css';

export interface EntryFormProps {
	gameSlug: string;
	plotId: number;
	/**
	 * Whether the viewer holds be_manage_plots.
	 */
	canManage: boolean;
	onCreated: () => void;
	/**
	 * Shows an optional Timeline date field when true.
	 */
	expandedEnabled?: boolean;
	/**
	 * The plot's game date and the character it belongs to, when it is a character's dated downtime: an answer to it
	 * says whether it cost the character an action.
	 */
	gameDate?: string | null;
	actorCharacterId?: number | null;
}

/**
 * Renders a form for posting a new entry to a plot.
 */
export function EntryForm( {
	gameSlug,
	plotId,
	canManage,
	onCreated,
	expandedEnabled,
	gameDate,
	actorCharacterId,
}: EntryFormProps ) {
	const availableTypes: EntryType[] = canManage
		? [ 'response', 'note', 'resolution', 'action' ]
		: [ 'action' ];

	const [ entryType, setEntryType ] = useState< EntryType >(
		availableTypes[ 0 ]
	);
	const contentDraft = useRef( '' );
	const contentId = `be-entry-content-${ plotId }`;
	// Drives the Post button's disabled state.
	const [ hasContent, setHasContent ] = useState( false );
	const [ eventDate, setEventDate ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	// A player may only choose plot (public) or storytellers (private to themself and staff).
	const [ audience, setAudience ] = useState< EntryAudienceValue >( 'plot' );
	const [ audienceCharacterIds, setAudienceCharacterIds ] = useState<
		number[]
	>( [] );
	const [ visibleCharacters, setVisibleCharacters ] = useState<
		CharacterOption[]
	>( [] );
	const [ charge, setCharge ] = useState< ChargeChoice | null >( null );
	const [ spendable, setSpendable ] = useState< SpendableBackground[] >( [] );
	const asksCharge =
		canManage &&
		entryType === 'response' &&
		!! gameDate &&
		!! actorCharacterId;

	useEffect( () => {
		if ( ! asksCharge || ! actorCharacterId ) {
			return;
		}
		let cancelled = false;
		api.apr( gameSlug )
			.spendable( actorCharacterId )
			.then( ( list ) => {
				if ( ! cancelled ) {
					setSpendable( list );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setSpendable( [] );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ asksCharge, gameSlug, actorCharacterId ] );

	useEffect( () => {
		if ( ! canManage ) {
			return;
		}
		let cancelled = false;
		api.plots( gameSlug )
			.visibleCharacters( plotId )
			.then( ( characters ) => {
				if ( ! cancelled ) {
					setVisibleCharacters( characters );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setVisibleCharacters( [] );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ canManage, gameSlug, plotId ] );

	function toggleAudienceCharacter( characterId: number ) {
		setAudienceCharacterIds( ( ids ) =>
			ids.includes( characterId )
				? ids.filter( ( id ) => id !== characterId )
				: [ ...ids, characterId ]
		);
	}

	/**
	 * Submits the entry form.
	 */
	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! contentDraft.current.trim() ) {
			return;
		}
		if ( audience === 'characters' && audienceCharacterIds.length === 0 ) {
			setError(
				__(
					'Choose at least one character to direct this post to.',
					'beyond-elysium'
				)
			);
			return;
		}
		setSubmitting( true );
		setError( null );
		try {
			await api.plotEntries( gameSlug ).create( plotId, {
				entry_type: entryType,
				content: contentDraft.current.trim(),
				event_date: eventDate || undefined,
				audience,
				audience_character_ids:
					audience === 'characters'
						? audienceCharacterIds
						: undefined,
				action_charge: asksCharge ? chargePayload( charge ) : undefined,
			} );
			contentDraft.current = '';
			setHasContent( false );
			// The form stays mounted for the next entry.
			tinymce?.get( contentId )?.setContent( '' );
			setEventDate( '' );
			setAudience( 'plot' );
			setAudienceCharacterIds( [] );
			setCharge( null );
			onCreated();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to add this entry.', 'beyond-elysium' )
				)
			);
		} finally {
			setSubmitting( false );
		}
	}

	return (
		<form className="be-entry-form" onSubmit={ submit }>
			{ error && (
				<div className="be-entry-form__error" role="alert">
					{ error }
				</div>
			) }
			<div className="be-entry-form__row">
				{ availableTypes.length > 1 && (
					<select
						value={ entryType }
						onChange={ ( e ) =>
							setEntryType( e.target.value as EntryType )
						}
					>
						{ availableTypes.map( ( t ) => (
							<option key={ t } value={ t }>
								{ t }
							</option>
						) ) }
					</select>
				) }
				{ expandedEnabled && (
					<input
						type="date"
						value={ eventDate }
						onChange={ ( e ) => setEventDate( e.target.value ) }
						aria-label={ __(
							'Timeline date (optional)',
							'beyond-elysium'
						) }
					/>
				) }
			</div>
			<div className="be-entry-form__audience">
				<select
					value={ audience }
					onChange={ ( e ) =>
						setAudience( e.target.value as EntryAudienceValue )
					}
					aria-label={ __(
						'Who can see this entry',
						'beyond-elysium'
					) }
				>
					<option value="plot">
						{ canManage
							? __(
									'Public - everyone who can see this plot',
									'beyond-elysium'
								)
							: __( 'Public', 'beyond-elysium' ) }
					</option>
					<option value="storytellers">
						{ canManage
							? __(
									'Storytellers and Narrators only',
									'beyond-elysium'
								)
							: __(
									'Private - Storytellers and me only',
									'beyond-elysium'
								) }
					</option>
					{ canManage && (
						<option value="characters">
							{ __(
								'Directed to specific characters',
								'beyond-elysium'
							) }
						</option>
					) }
				</select>
				{ canManage && audience === 'characters' && (
					<ul className="be-entry-form__character-picker">
						{ visibleCharacters.length === 0 && (
							<li className="be-entry-form__placeholder">
								{ __(
									'No characters can currently see this plot.',
									'beyond-elysium'
								) }
							</li>
						) }
						{ visibleCharacters.map( ( character ) => (
							<li key={ character.id }>
								<label>
									<input
										type="checkbox"
										checked={ audienceCharacterIds.includes(
											character.id
										) }
										onChange={ () =>
											toggleAudienceCharacter(
												character.id
											)
										}
									/>
									{ character.name }
								</label>
							</li>
						) ) }
					</ul>
				) }
			</div>
			<p className="be-entry-form__hint">
				{ entryType === 'action'
					? __( 'Describe your action…', 'beyond-elysium' )
					: sprintf(
							/* translators: %s: the entry type being written, e.g. "note" or "rumor" */
							__( 'Write a %s…', 'beyond-elysium' ),
							entryType
						) }
			</p>
			<HtmlEditor
				id={ contentId }
				defaultValue=""
				onChange={ ( html ) => {
					contentDraft.current = html;
					setHasContent( html.trim() !== '' );
				} }
				rows={ 3 }
				aiAssist={
					canManage && entryType !== 'action'
						? {
								capability: 'be_manage_plots',
								fieldContext: 'plot_entry',
								gameSlug,
							}
						: undefined
				}
			/>
			{ asksCharge && (
				<fieldset className="be-entry-form__charge">
					<legend>
						{ __(
							'Does this answer cost the character an action?',
							'beyond-elysium'
						) }
					</legend>
					<label>
						<input
							type="radio"
							name={ `be-entry-charge-${ plotId }` }
							checked={ charge?.mode === 'none' }
							onChange={ () => setCharge( { mode: 'none' } ) }
						/>
						{ __( 'No action charged', 'beyond-elysium' ) }
					</label>
					<label>
						<input
							type="radio"
							name={ `be-entry-charge-${ plotId }` }
							checked={ charge?.mode === 'charge' }
							onChange={ () =>
								setCharge( {
									mode: 'charge',
									name: spendable[ 0 ]?.name ?? '',
									cost: 1,
								} )
							}
						/>
						{ __( 'Charge an action', 'beyond-elysium' ) }
					</label>
					{ charge?.mode === 'charge' && (
						<span className="be-entry-form__charge-detail">
							<select
								value={ charge.name }
								onChange={ ( e ) =>
									setCharge( {
										...charge,
										name: e.target.value,
									} )
								}
								aria-label={ __(
									'Background to charge',
									'beyond-elysium'
								) }
							>
								{ spendable.map( ( background ) => (
									<option
										key={ background.name }
										value={ background.name }
									>
										{ background.budget_total === null
											? background.name
											: sprintf(
													/* translators: 1: a background's name, 2: how many actions it has left */
													__(
														'%1$s (%2$d left)',
														'beyond-elysium'
													),
													background.name,
													background.budget_total
												) }
									</option>
								) ) }
							</select>
							<input
								type="number"
								min={ 1 }
								value={ charge.cost }
								onChange={ ( e ) =>
									setCharge( {
										...charge,
										cost: Math.max(
											1,
											Number( e.target.value ) || 1
										),
									} )
								}
								aria-label={ __(
									'Actions to charge',
									'beyond-elysium'
								) }
							/>
						</span>
					) }
				</fieldset>
			) }
			<button
				type="submit"
				className="be-st-button"
				disabled={
					submitting ||
					! hasContent ||
					( asksCharge && ! chargeComplete( charge ) )
				}
			>
				{ submitting
					? __( 'Posting…', 'beyond-elysium' )
					: __( 'Post', 'beyond-elysium' ) }
			</button>
		</form>
	);
}

export default EntryForm;
