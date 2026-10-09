/**
 * ST tool for drafting a new plot from a one-line premise: one call drafts and creates the plot, its Storyteller-only
 * beats and its held rumors together, then opens it. There is no preview step; nothing waits for a confirmation
 * before writing.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import HelpButton from '../shared/HelpButton';
import { AiDemoNotice } from '../shared/AiDemoNotice';
import { useIsDemo } from '../shared/useIsDemo';
import type { Character } from '../../types/character';
import type { Faction } from '../../types/faction';
import './DraftPlotPanel.css';

export interface DraftPlotPanelProps {
	gameSlug: string;
	onCreated: ( plotId: number ) => void;
}

/**
 * Splits a flat list of checked-off ids into the characters and NPCs the draft route takes as two separate
 * fields, reading each one's own `is_npc` rather than trusting which list it came from.
 */
export function splitSelectedCharacters(
	characters: Character[],
	selectedIds: number[]
): { characterIds: number[]; npcIds: number[] } {
	const characterIds = characters
		.filter( ( c ) => selectedIds.includes( c.id ) && ! c.is_npc )
		.map( ( c ) => c.id );
	const npcIds = characters
		.filter( ( c ) => selectedIds.includes( c.id ) && c.is_npc )
		.map( ( c ) => c.id );
	return { characterIds, npcIds };
}

export function DraftPlotPanel( { gameSlug, onCreated }: DraftPlotPanelProps ) {
	const [ premise, setPremise ] = useState( '' );
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ factions, setFactions ] = useState< Faction[] >( [] );
	const [ selectedCharacterIds, setSelectedCharacterIds ] = useState<
		number[]
	>( [] );
	const [ selectedFactionIds, setSelectedFactionIds ] = useState< number[] >(
		[]
	);
	const [ creating, setCreating ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ demoNotice, setDemoNotice ] = useState( false );
	const isDemo = useIsDemo( gameSlug );

	useEffect( () => {
		everyPage( ( page ) =>
			api.characters( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setCharacters )
			.catch( () => setCharacters( [] ) );
		api.factions( gameSlug )
			.list()
			.then( setFactions )
			.catch( () => setFactions( [] ) );
	}, [ gameSlug ] );

	function toggle(
		id: number,
		selected: number[],
		setSelected: ( ids: number[] ) => void
	) {
		setSelected(
			selected.includes( id )
				? selected.filter( ( x ) => x !== id )
				: [ ...selected, id ]
		);
	}

	async function draft( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! premise.trim() ) {
			return;
		}
		if ( isDemo ) {
			setDemoNotice( true );
			return;
		}
		setCreating( true );
		setError( null );
		try {
			const { characterIds, npcIds } = splitSelectedCharacters(
				characters,
				selectedCharacterIds
			);
			const response = await api.aiAssist( gameSlug ).draftPlot( {
				premise: premise.trim(),
				character_ids: characterIds,
				npc_ids: npcIds,
				faction_ids: selectedFactionIds,
			} );
			onCreated( response.plot_id );
		} catch ( err: unknown ) {
			const message =
				typeof err === 'object' && err !== null && 'message' in err
					? String( ( err as { message?: unknown } ).message )
					: __( 'Something went wrong.', 'beyond-elysium' );
			setError( message );
		} finally {
			setCreating( false );
		}
	}

	return (
		<div className="be-draft-plot-panel be-st-modal-body">
			<div className="be-help-heading">
				<HelpButton helpKey="draft-plot" />
			</div>
			<form onSubmit={ draft } className="be-draft-plot-panel__form">
				<label>
					{ __( 'Premise', 'beyond-elysium' ) }
					<textarea
						value={ premise }
						onChange={ ( e ) => setPremise( e.target.value ) }
						placeholder={ __(
							'e.g. A rival faction tests an old peace.',
							'beyond-elysium'
						) }
						rows={ 2 }
					/>
				</label>

				{ characters.length > 0 && (
					<fieldset>
						<legend>
							{ __(
								'Characters and NPCs (optional)',
								'beyond-elysium'
							) }
						</legend>
						<div className="be-draft-plot-panel__picker">
							{ characters.map( ( c ) => (
								<label key={ c.id }>
									<input
										type="checkbox"
										checked={ selectedCharacterIds.includes(
											c.id
										) }
										onChange={ () =>
											toggle(
												c.id,
												selectedCharacterIds,
												setSelectedCharacterIds
											)
										}
									/>
									{ c.name }
									{ c.is_npc
										? ` ${ __( '(NPC)', 'beyond-elysium' ) }`
										: '' }
								</label>
							) ) }
						</div>
					</fieldset>
				) }

				{ factions.length > 0 && (
					<fieldset>
						<legend>
							{ __( 'Factions (optional)', 'beyond-elysium' ) }
						</legend>
						<div className="be-draft-plot-panel__picker">
							{ factions.map( ( f ) => (
								<label key={ f.id }>
									<input
										type="checkbox"
										checked={ selectedFactionIds.includes(
											f.id
										) }
										onChange={ () =>
											toggle(
												f.id,
												selectedFactionIds,
												setSelectedFactionIds
											)
										}
									/>
									{ f.name }
								</label>
							) ) }
						</div>
					</fieldset>
				) }

				<p className="description">
					{ __(
						'Picked characters and NPCs send their public profile and identity fields only - nothing Storyteller-only from another character is sent. The plot is created and opened immediately - there is no preview step.',
						'beyond-elysium'
					) }
				</p>

				{ error && (
					<div className="be-plot-manager__error" role="alert">
						{ error }
					</div>
				) }

				<button
					type="submit"
					className="be-st-button"
					disabled={ creating || ! premise.trim() }
				>
					{ creating
						? __( 'Drafting…', 'beyond-elysium' )
						: __( 'Draft and create', 'beyond-elysium' ) }
				</button>
				{ demoNotice && <AiDemoNotice /> }
			</form>
		</div>
	);
}

export default DraftPlotPanel;
