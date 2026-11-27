/**
 * The character builder: a guided path for a new character. The steps come from the creature type's own sections and
 * creation rules, a bar pinned at the top reads the build as it goes, the unfinished build is kept on the person's
 * account, and Review shows everything before the character is created.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import BlockEditor from '../editors/BlockEditor';
import ConfirmDialog from '../shared/ConfirmDialog';
import HelpButton from '../shared/HelpButton';
import HtmlEditor from '../shared/HtmlEditor';
import SaveStatus from '../character/SaveStatus';
import BuilderBar from './BuilderBar';
import BuilderReview from './BuilderReview';
import BuilderStepList from './BuilderStepList';
import {
	createAutosaver,
	type Autosaver,
	type AutosaveState,
} from '../../lib/autosave';
import { barModel, limitsForStep, type StepMark } from '../../lib/builderBar';
import {
	applyGivens,
	givenLines,
	NO_GIVENS,
	type GivenMemory,
} from '../../lib/builderGivens';
import { reviewModel } from '../../lib/builderReview';
import { deriveSteps, type BuilderStep } from '../../lib/builderSteps';
import { canIn } from '../../lib/chronicleCapabilities';
import { errorMessage } from '../../lib/errorMessage';
import { withHeldBlockSections } from '../../lib/heldBlockSections';
import { rememberCreatedReview } from '../../lib/createdReview';
import { characterSheetUrl } from '../../lib/pluginPages';
import { formatStoredDay } from '../../lib/storedTime';
import { resolveSectionTitle } from '../../lib/resolveCrossBlockRef';
import { limitFlagText } from '../../lib/tallyText';
import { sortedForFlow } from '../../lib/templateLayout';
import type {
	CreatureStack,
	MyCapabilities,
	ResolvedStack,
	TemplateResolveResponse,
} from '../../types';
import type { CreationTally } from '../../types/character';
import './CharacterBuilder.css';

export interface CharacterBuilderProps {
	gameSlug: string;
	capabilities?: MyCapabilities;
}

interface Snapshot {
	name: string;
	stack_slug: string;
	step: string;
	sheet_data: Record< string, unknown >;
	biography: string;
}

type Phase = 'loading' | 'offer' | 'building' | 'sent' | 'blocked';

function isEmpty( draft: Partial< Snapshot > ): boolean {
	return (
		! draft.name &&
		! draft.stack_slug &&
		Object.keys( draft.sheet_data ?? {} ).length === 0 &&
		! draft.biography
	);
}

export function CharacterBuilder( {
	gameSlug,
	capabilities,
}: CharacterBuilderProps ) {
	const isManager = canIn( 'be_manage_characters', capabilities );

	const [ phase, setPhase ] = useState< Phase >( 'loading' );
	const [ blockedWith, setBlockedWith ] = useState< string | null >( null );
	const [ offered, setOffered ] = useState< {
		draft: Partial< Snapshot >;
		savedAt: string;
	} | null >( null );
	const [ confirmingStartOver, setConfirmingStartOver ] = useState( false );
	const [ sentName, setSentName ] = useState( '' );

	const [ name, setName ] = useState( '' );
	const [ stackSlug, setStackSlug ] = useState( '' );
	const [ stepKey, setStepKey ] = useState( 'concept' );
	const [ sheet, setSheet ] = useState< Record< string, unknown > >( {} );
	const [ biography, setBiography ] = useState( '' );
	const [ givens, setGivens ] = useState< GivenMemory >( NO_GIVENS );

	const [ stacks, setStacks ] = useState< CreatureStack[] >( [] );
	const [ stack, setStack ] = useState< ResolvedStack | null >( null );
	const [ template, setTemplate ] =
		useState< TemplateResolveResponse | null >( null );
	const [ loadError, setLoadError ] = useState< string | null >( null );

	const [ tally, setTally ] = useState< CreationTally | null >( null );
	const [ tallyLoading, setTallyLoading ] = useState( false );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );

	const [ saveState, setSaveState ] = useState< AutosaveState >( 'idle' );
	const [ saveMessage, setSaveMessage ] = useState< string | undefined >();
	const saver = useRef< Autosaver< Snapshot > | null >( null );

	const sheetRef = useRef( sheet );
	sheetRef.current = sheet;
	const givensRef = useRef( givens );
	givensRef.current = givens;

	// ---- the saved draft ----
	useEffect( () => {
		let live = true;
		api.characterDraft( gameSlug )
			.get()
			.then( ( record ) => {
				if ( ! live ) {
					return;
				}
				if ( record.draft && ! isEmpty( record.draft ) ) {
					setOffered( {
						draft: record.draft as Partial< Snapshot >,
						savedAt: record.updated_at ?? '',
					} );
					setPhase( 'offer' );
					return;
				}
				setPhase( 'building' );
			} )
			.catch( ( error: unknown ) => {
				if ( live ) {
					setBlockedWith(
						errorMessage(
							error,
							__( 'Something went wrong.', 'beyond-elysium' )
						)
					);
					setPhase( 'blocked' );
				}
			} );
		return () => {
			live = false;
		};
	}, [ gameSlug ] );

	function startSaver( initial: Snapshot ) {
		saver.current?.dispose();
		saver.current = createAutosaver< Snapshot >( {
			initial,
			save: async ( value ) => {
				await api.characterDraft( gameSlug ).save( value );
			},
			onState: ( state, message ) => {
				setSaveState( state );
				setSaveMessage( message );
			},
		} );
	}

	useEffect( () => {
		const flush = () => {
			saver.current?.flush().catch( () => undefined );
		};
		window.addEventListener( 'pagehide', flush );
		return () => {
			window.removeEventListener( 'pagehide', flush );
			saver.current?.dispose();
		};
	}, [] );

	function loadDraft( draft: Partial< Snapshot > ) {
		const next: Snapshot = {
			name: draft.name ?? '',
			stack_slug: draft.stack_slug ?? '',
			step: draft.step ?? 'concept',
			sheet_data:
				draft.sheet_data && ! Array.isArray( draft.sheet_data )
					? draft.sheet_data
					: {},
			biography: draft.biography ?? '',
		};
		setName( next.name );
		setStackSlug( next.stack_slug );
		setStepKey( next.step );
		setSheet( next.sheet_data );
		setBiography( next.biography );
		startSaver( next );
		setPhase( 'building' );
	}

	function startFresh() {
		loadDraft( {} );
	}

	async function startOver() {
		setConfirmingStartOver( false );
		await api
			.characterDraft( gameSlug )
			.discard()
			.catch( () => undefined );
		setOffered( null );
		setGivens( NO_GIVENS );
		setTally( null );
		startFresh();
	}

	// A fresh build with no draft to offer.
	useEffect( () => {
		if ( phase === 'building' && saver.current === null ) {
			startFresh();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ phase ] );

	// ---- the creature types, the chosen one and its sheet layout ----
	useEffect( () => {
		api.creatureStacks
			.list( { game_slug: gameSlug } )
			.then( ( list ) => {
				setStacks( list );
				if ( list.length === 1 ) {
					setStackSlug( ( current ) => current || list[ 0 ].slug );
				}
			} )
			.catch( () =>
				setLoadError(
					__(
						'Failed to load the list of creature types. Try refreshing the page.',
						'beyond-elysium'
					)
				)
			);
	}, [ gameSlug ] );

	useEffect( () => {
		if ( ! stackSlug ) {
			setStack( null );
			setTemplate( null );
			return;
		}
		let live = true;
		api.creatureStacks
			.resolve( stackSlug, gameSlug, true )
			.then( ( resolved ) => live && setStack( resolved ) )
			.catch( () => {
				if ( live ) {
					setStack( null );
					setLoadError(
						__(
							'Failed to load that creature type. Try choosing it again.',
							'beyond-elysium'
						)
					);
				}
			} );
		api.templates( gameSlug )
			.resolve( stackSlug, 'sheet_full' )
			.then( ( result ) => live && setTemplate( result ) )
			.catch( () => {
				if ( live ) {
					setTemplate( null );
					setLoadError(
						__(
							'Failed to load the character sheet layout. Try refreshing the page.',
							'beyond-elysium'
						)
					);
				}
			} );
		return () => {
			live = false;
		};
	}, [ stackSlug, gameSlug ] );

	const sections = useMemo(
		() =>
			template
				? sortedForFlow(
						withHeldBlockSections(
							template.template.layout.sections,
							stack,
							sheet
						)
					)
				: [],
		[ template, stack, sheet ]
	);

	const rules = stack?.stack.creation_rules?.steps ?? [];

	const steps: BuilderStep[] = useMemo( () => {
		if ( ! stack || ! template ) {
			return [
				{
					key: 'concept',
					kind: 'concept',
					title: __( 'Concept', 'beyond-elysium' ),
					sections: [],
				},
			];
		}
		const labels: Record< string, string > = {};
		for ( const section of stack.stack.stack_definition.sections ) {
			labels[ section.block_slug ] = section.label;
		}
		const types: Record< string, string > = {};
		for ( const section of sections ) {
			types[ section.block_slug ] =
				stack.blocks[ section.block_slug ]?.section_type ?? '';
		}
		return deriveSteps( {
			sections: sections.filter( ( s ) => stack.blocks[ s.block_slug ] ),
			sectionTypes: types,
			sectionLabels: labels,
			rules,
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ stack, template, sections.map( ( s ) => s.block_slug ).join( '|' ) ] );

	const step =
		steps.find( ( candidate ) => candidate.key === stepKey ) ?? steps[ 0 ];
	const stepIndex = steps.indexOf( step );

	// ---- the live tally ----
	const tallyKey = JSON.stringify( [ stackSlug, sheet ] );
	const latest = useRef( 0 );
	useEffect( () => {
		if ( phase !== 'building' || ! stackSlug ) {
			setTally( null );
			return undefined;
		}
		const mine = ++latest.current;
		setTallyLoading( true );
		const timer = setTimeout( () => {
			api.creationTally( gameSlug )
				.draft( stackSlug, sheetRef.current )
				.then( ( result ) => {
					if ( mine !== latest.current ) {
						return;
					}
					setTally( result );
					setTallyLoading( false );
					const next = applyGivens(
						sheetRef.current,
						result,
						givensRef.current
					);
					if ( next.memory !== givensRef.current ) {
						setGivens( next.memory );
					}
					if ( next.sheet !== sheetRef.current ) {
						setSheet( next.sheet );
					}
				} )
				.catch( () => {
					if ( mine === latest.current ) {
						setTallyLoading( false );
					}
				} );
		}, 400 );
		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ phase, gameSlug, tallyKey ] );

	// ---- autosave ----
	useEffect( () => {
		if ( phase !== 'building' || ! saver.current ) {
			return;
		}
		saver.current.change( {
			name,
			stack_slug: stackSlug,
			step: step.key,
			sheet_data: sheet,
			biography,
		} );
	}, [ phase, name, stackSlug, step.key, sheet, biography ] );

	useEffect( () => {
		const guard = ( e: BeforeUnloadEvent ) => {
			if ( saver.current?.hasUnsaved() ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', guard );
		return () => window.removeEventListener( 'beforeunload', guard );
	}, [] );

	function chooseStack( slug: string ) {
		if ( slug === stackSlug ) {
			return;
		}
		setStackSlug( slug );
		setSheet( {} );
		setGivens( NO_GIVENS );
		setTally( null );
		setStepKey( 'concept' );
	}

	function openStep( key: string ) {
		setStepKey( key );
		window.scrollTo?.( { top: 0 } );
	}

	const marks: Record< string, StepMark > = {};
	for ( const candidate of steps ) {
		marks[ candidate.key ] = barModel( candidate, tally, rules ).mark;
	}
	const bar = barModel( step, tally, rules );

	const titles: Record< string, string > = {};
	for ( const section of sections ) {
		titles[ section.block_slug ] = resolveSectionTitle( section, sheet );
	}

	async function create() {
		if ( ! stackSlug || ! name.trim() ) {
			return;
		}
		setCreating( true );
		setCreateError( null );
		try {
			const character = await api.characters( gameSlug ).create( {
				name: name.trim(),
				stack_slug: stackSlug,
				sheet_data: sheet,
				biography,
			} );
			saver.current?.dispose();
			saver.current = null;
			if ( character.join_pending ) {
				setSentName( character.name );
				setPhase( 'sent' );
				return;
			}
			if ( character.status === 'pending' ) {
				rememberCreatedReview(
					character.id,
					( tally?.needs_storyteller ?? [] ).map( ( pick ) => ( {
						name: pick.name,
						reason: pick.reason,
					} ) )
				);
			}
			window.location.assign(
				characterSheetUrl( character.id, gameSlug )
			);
		} catch ( error ) {
			setCreateError(
				errorMessage(
					error,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
			setCreating( false );
		}
	}

	// ---- what shows ----
	if ( phase === 'loading' ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	if ( phase === 'blocked' ) {
		return (
			<div className="be-builder">
				<div className="be-builder__error" role="alert">
					{ blockedWith }
				</div>
			</div>
		);
	}

	if ( phase === 'sent' ) {
		return (
			<div className="be-builder">
				<h2 className="be-builder__title">
					{ __( 'Request Sent', 'beyond-elysium' ) }
				</h2>
				<p role="status">
					{ sprintf(
						// translators: %s: character name.
						__(
							"%s is waiting for this chronicle's Storytellers to approve it. You become a player here, and can open the sheet, once they do.",
							'beyond-elysium'
						),
						sentName
					) }
				</p>
			</div>
		);
	}

	if ( phase === 'offer' && offered ) {
		const when = formatStoredDay( offered.savedAt );
		const typeName =
			stacks.find(
				( candidate ) => candidate.slug === offered.draft.stack_slug
			)?.name ?? '';
		return (
			<div className="be-builder">
				<h2 className="be-builder__title">
					{ __( 'New character', 'beyond-elysium' ) }
				</h2>
				<div className="be-builder__offer" role="status">
					<p>
						{ sprintf(
							/* translators: 1: the creature type, 2: the character's name, 3: the day it was saved */
							__(
								'Your %1$s, %2$s, saved %3$s',
								'beyond-elysium'
							),
							typeName || __( 'character', 'beyond-elysium' ),
							offered.draft.name ||
								__( 'unnamed', 'beyond-elysium' ),
							when
						) }
					</p>
					<div className="be-builder__offer-actions">
						<button
							type="button"
							onClick={ () => loadDraft( offered.draft ) }
						>
							{ __( 'Continue', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ () => setConfirmingStartOver( true ) }
						>
							{ __( 'Start over', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
				<ConfirmDialog
					open={ confirmingStartOver }
					title={ __( 'Start over?', 'beyond-elysium' ) }
					message={ __(
						'The build you saved is thrown away and cannot be brought back.',
						'beyond-elysium'
					) }
					confirmLabel={ __( 'Start over', 'beyond-elysium' ) }
					onConfirm={ startOver }
					onCancel={ () => setConfirmingStartOver( false ) }
				/>
			</div>
		);
	}

	const review = reviewModel( {
		steps,
		sheet,
		blocks: stack?.blocks ?? {},
		titles,
		tally,
		name,
		stackSlug,
		biography,
		isManager,
	} );

	function renderSection( slug: string ) {
		const block = stack?.blocks[ slug ];
		if ( ! block ) {
			return null;
		}
		return (
			<section className="be-builder__section" key={ slug }>
				<h3>{ titles[ slug ] ?? block.name }</h3>
				<BlockEditor
					blockSlug={ slug }
					sectionType={ block.section_type }
					definition={ block.definition }
					data={ sheet[ slug ] }
					onChange={ ( blockSlug, data ) =>
						setSheet( ( prev ) => ( {
							...prev,
							[ blockSlug ]: data,
						} ) )
					}
					sheetData={ sheet }
					gameSlug={ gameSlug }
					isManager={ isManager }
					creating
				/>
			</section>
		);
	}

	const given = givenLines( givens, step.sections );
	const flags = limitsForStep( step, tally );

	return (
		<div className="be-builder">
			<div className="be-help-heading">
				<h2 className="be-builder__title">
					{ __( 'New character', 'beyond-elysium' ) }
				</h2>
				<HelpButton helpKey="character-builder" />
			</div>

			<BuilderBar
				model={ bar }
				tally={ tally }
				loading={ tallyLoading }
				gameSlug={ gameSlug }
				stackSlug={ stackSlug }
				sheetData={ sheet }
			/>

			<BuilderStepList
				steps={ steps }
				current={ step.key }
				marks={ marks }
				onOpen={ openStep }
			/>

			{ ( loadError || createError ) && step.kind !== 'review' && (
				<div className="be-builder__error" role="alert">
					{ loadError ?? createError }
				</div>
			) }

			<div className="be-builder__body">
				{ step.kind === 'concept' && (
					<>
						<div className="be-builder__field">
							<label htmlFor="be-builder-name">
								{ __( 'Name', 'beyond-elysium' ) }
							</label>
							<input
								id="be-builder-name"
								type="text"
								value={ name }
								onChange={ ( e ) => setName( e.target.value ) }
							/>
						</div>
						<div className="be-builder__field">
							{ stacks.length === 1 ? (
								<p>
									{ sprintf(
										/* translators: %s: a creature type's name */
										__(
											'Creature type: %s',
											'beyond-elysium'
										),
										stacks[ 0 ].name
									) }
								</p>
							) : (
								<>
									<label htmlFor="be-builder-stack">
										{ __(
											'Creature Type',
											'beyond-elysium'
										) }
									</label>
									<select
										id="be-builder-stack"
										value={ stackSlug }
										onChange={ ( e ) =>
											chooseStack( e.target.value )
										}
									>
										<option value="">
											{ __(
												'Choose one…',
												'beyond-elysium'
											) }
										</option>
										{ stacks.map( ( candidate ) => (
											<option
												key={ candidate.slug }
												value={ candidate.slug }
											>
												{ candidate.name }
											</option>
										) ) }
									</select>
								</>
							) }
						</div>
					</>
				) }

				{ given.length > 0 && (
					<div className="be-builder__given">
						<h4>
							{ __( 'Given by the rules', 'beyond-elysium' ) }
						</h4>
						<ul>
							{ given.map( ( line ) => (
								<li key={ line }>{ line }</li>
							) ) }
						</ul>
					</div>
				) }

				{ flags.length > 0 && (
					<ul className="be-builder__flags" role="alert">
						{ flags.map( ( flag, i ) => (
							<li key={ `${ flag.target }-${ i }` }>
								{ limitFlagText( flag ) }
							</li>
						) ) }
					</ul>
				) }

				{ step.sections.map( renderSection ) }

				{ step.kind === 'story' && (
					<section className="be-builder__section">
						<h3>{ __( 'Background', 'beyond-elysium' ) }</h3>
						<p className="be-builder__hint">
							{ __(
								'Optional. You can add a portrait once the character exists.',
								'beyond-elysium'
							) }
						</p>
						<HtmlEditor
							id="be-builder-biography"
							defaultValue={ biography }
							onChange={ setBiography }
						/>
					</section>
				) }

				{ step.kind === 'review' && (
					<BuilderReview
						model={ review }
						tally={ tally }
						creating={ creating }
						error={ createError }
						onCreate={ create }
					/>
				) }
			</div>

			<div className="be-builder__nav">
				<SaveStatus
					state={ saveState }
					message={ saveMessage }
					onRetry={ () => saver.current?.retry() }
				/>
				<div className="be-builder__nav-buttons">
					<button
						type="button"
						disabled={ stepIndex <= 0 }
						onClick={ () => openStep( steps[ stepIndex - 1 ].key ) }
					>
						{ __( 'Back', 'beyond-elysium' ) }
					</button>
					<button
						type="button"
						disabled={
							stepIndex >= steps.length - 1 ||
							( step.kind === 'concept' && ! stackSlug )
						}
						onClick={ () => openStep( steps[ stepIndex + 1 ].key ) }
					>
						{ __( 'Next', 'beyond-elysium' ) }
					</button>
				</div>
			</div>
		</div>
	);
}

export default CharacterBuilder;
