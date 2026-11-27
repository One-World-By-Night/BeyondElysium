/**
 * My Chronicle's Character section: the chosen character's header over a row of views (Play, Sheet, Story, History,
 * Edit), each its own address.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import CharacterBuilder from '../builder/CharacterBuilder';
import ChangeHistory from '../changes/ChangeHistory';
import HelpButton from '../shared/HelpButton';
import CartChip from './CartChip';
import CartReview from './CartReview';
import CharacterEditor from './CharacterEditor';
import CharacterSheet from './CharacterSheet';
import CharacterStory from './CharacterStory';
import PlayView from './PlayView';
import { sectionHref } from '../../lib/appNav';
import { creatureTypeName } from '../../lib/characterLabels';
import {
	readLastView,
	rememberView,
	resolveView,
	viewsFor,
	type CharacterView,
} from '../../lib/characterViews';
import { canIn } from '../../lib/chronicleCapabilities';
import { readCreatedReview } from '../../lib/createdReview';
import { opensBuilder, readNewNpcFromUrl } from '../../lib/pluginPages';
import { standingLine } from '../../lib/standing';
import useCharacterEditorStore from '../../store/characterEditorStore';
import { useCreatureTypeNames } from '../../lib/useCreatureTypeNames';
import type { MyCapabilities } from '../../types';
import type { Character } from '../../types/character';
import './CharacterSection.css';

/**
 * The views this build can draw.
 */
const DRAWN: CharacterView[] = [ 'play', 'sheet', 'story', 'history', 'edit' ];

export interface CharacterSectionProps {
	gameSlug: string;
	/**
	 * The character chosen in the address, when there is one.
	 */
	characterId?: number;
	/**
	 * The page's open tab: the section's own key or one of its views.
	 */
	tab: string;
	capabilities?: MyCapabilities;
	/**
	 * Opens another view in place.
	 */
	onView: ( view: CharacterView ) => void;
	/**
	 * Goes to the Characters list to choose one.
	 */
	onPickCharacter: () => void;
}

function viewLabel( view: CharacterView ): string {
	switch ( view ) {
		case 'play':
			return __( 'Play', 'beyond-elysium' );
		case 'sheet':
			return __( 'Sheet', 'beyond-elysium' );
		case 'story':
			return __( 'Story', 'beyond-elysium' );
		case 'history':
			return __( 'History', 'beyond-elysium' );
		default:
			return __( 'Edit', 'beyond-elysium' );
	}
}

export function CharacterSection( {
	gameSlug,
	characterId,
	tab,
	capabilities,
	onView,
	onPickCharacter,
}: CharacterSectionProps ) {
	const [ character, setCharacter ] = useState< Character | null >( null );
	const [ waiting, setWaiting ] = useState( 0 );
	const [ settled, setSettled ] = useState( false );
	const creatureTypes = useCreatureTypeNames( characterId ? gameSlug : '' );
	const cartCharacter = useCharacterEditorStore( ( state ) =>
		state.characterId === characterId ? state.character : null
	);
	const lastSubmit = useCharacterEditorStore( ( state ) => state.lastSubmit );

	useEffect( () => {
		if ( ! characterId ) {
			return undefined;
		}
		let live = true;
		setSettled( false );
		api.characters( gameSlug )
			.get( characterId )
			.then( ( loaded ) => live && setCharacter( loaded ) )
			.catch( () => live && setCharacter( null ) )
			.finally( () => live && setSettled( true ) );
		api.changes( gameSlug )
			.list( characterId, { status: 'pending', per_page: 100 } )
			.then( ( rows ) => live && setWaiting( rows.length ) )
			.catch( () => live && setWaiting( 0 ) );
		return () => {
			live = false;
		};
	}, [ gameSlug, characterId ] );

	// What the header says is read again after a change is sent or withdrawn. The cart's store is read again only
	// when it has not just rebased on the server's copy, which is what sending does.
	const refresh = ( reloadStore = true ) => {
		if ( ! characterId ) {
			return;
		}
		api.characters( gameSlug )
			.get( characterId )
			.then( setCharacter )
			.catch( () => undefined );
		api.changes( gameSlug )
			.list( characterId, { status: 'pending', per_page: 100 } )
			.then( ( rows ) => setWaiting( rows.length ) )
			.catch( () => undefined );
		if ( reloadStore && character?.can_edit ) {
			useCharacterEditorStore
				.getState()
				.loadCharacter( characterId, gameSlug );
		}
	};

	// The cart lives in the editor's store, kept for every view of a character the person can edit.
	useEffect( () => {
		if ( characterId && character?.can_edit ) {
			useCharacterEditorStore
				.getState()
				.loadCharacter( characterId, gameSlug );
		}
	}, [ gameSlug, characterId, character?.can_edit ] );

	useEffect( () => {
		if ( lastSubmit ) {
			refresh( false );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ lastSubmit ] );

	const allowed = viewsFor( !! character?.can_edit ).filter( ( view ) =>
		DRAWN.includes( view )
	);
	const view = resolveView( tab, readLastView(), allowed );

	useEffect( () => {
		if ( ! settled || ! characterId ) {
			return;
		}
		rememberView( view );
		if ( view !== tab ) {
			onView( view );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ settled, view, tab, characterId ] );

	if ( ! characterId ) {
		if (
			opensBuilder( tab, canIn( 'be_manage_characters', capabilities ) )
		) {
			return (
				<CharacterBuilder
					key={ `${ gameSlug }-builder` }
					gameSlug={ gameSlug }
					capabilities={ capabilities }
				/>
			);
		}
		if ( tab === 'edit' ) {
			return (
				<CharacterEditor
					key={ `${ gameSlug }-new` }
					gameSlug={ gameSlug }
					capabilities={ capabilities }
					startAsNpc={ readNewNpcFromUrl() }
				/>
			);
		}
		return (
			<p>
				<a
					href={ sectionHref( window.location.href, 'characters' ) }
					onClick={ ( e ) => {
						e.preventDefault();
						onPickCharacter();
					} }
				>
					{ __(
						'Pick a character on the Characters tab to open it.',
						'beyond-elysium'
					) }
				</a>
			</p>
		);
	}

	if ( ! settled ) {
		return <p>{ __( 'Loading character…', 'beyond-elysium' ) }</p>;
	}

	// A character that would not load shows its own error on the sheet.
	if ( ! character ) {
		return (
			<CharacterSheet
				key={ `${ gameSlug }-${ characterId }` }
				characterId={ characterId }
				gameSlug={ gameSlug }
				capabilities={ capabilities }
			/>
		);
	}

	const waitingReasons =
		character.status === 'pending' && character.can_edit
			? readCreatedReview( character.id )
			: [];
	const standingOf = cartCharacter ?? character;
	const standing = standingLine(
		Number( standingOf.xp_unspent ),
		Number( standingOf.xp_held ?? 0 ),
		waiting
	);

	return (
		<div className="be-character-section">
			<div className="be-character-section__header">
				{ character.image_url && (
					<img
						className="be-character-section__portrait"
						src={ character.image_url }
						alt=""
					/>
				) }
				<div className="be-character-section__identity">
					<h2 className="be-character-section__name">
						{ character.name }
					</h2>
					<p className="be-character-section__standing">
						{ [
							creatureTypeName(
								character.stack_slug,
								creatureTypes
							),
							standing,
						]
							.filter( Boolean )
							.join( ' · ' ) }
						{ character.can_edit && <CartChip /> }
					</p>
				</div>
			</div>

			{ character.status === 'pending' && character.can_edit && (
				<div className="be-character-section__waiting" role="status">
					<p>
						{ __( 'Waiting for a Storyteller', 'beyond-elysium' ) }
					</p>
					{ waitingReasons.length > 0 && (
						<ul>
							{ waitingReasons.map( ( reason, i ) => (
								<li key={ `${ reason.name }-${ i }` }>
									<strong>{ reason.name }</strong>
									{ reason.reason
										? `: ${ reason.reason }`
										: '' }
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }

			<nav
				className="be-character-views"
				aria-label={ __( 'Views', 'beyond-elysium' ) }
			>
				<ul>
					{ allowed.map( ( key ) => (
						<li key={ key }>
							<a
								className="be-character-views__view"
								href={ sectionHref(
									window.location.href,
									key
								) }
								aria-current={
									key === view ? 'page' : undefined
								}
								onClick={ ( e ) => {
									e.preventDefault();
									onView( key );
								} }
							>
								{ viewLabel( key ) }
							</a>
						</li>
					) ) }
				</ul>
			</nav>

			<div className="be-character-section__view">
				{ view === 'play' && (
					<PlayView
						key={ `${ gameSlug }-${ characterId }` }
						characterId={ characterId }
						gameSlug={ gameSlug }
						canEdit={ !! character.can_edit }
						canManage={ !! character.can_manage }
						onView={ onView }
					/>
				) }
				{ view === 'sheet' && (
					<CharacterSheet
						key={ `${ gameSlug }-${ characterId }` }
						characterId={ characterId }
						gameSlug={ gameSlug }
						capabilities={ capabilities }
						onView={ onView }
					/>
				) }
				{ view === 'story' && (
					<CharacterStory
						key={ `${ gameSlug }-${ characterId }` }
						characterId={ characterId }
						gameSlug={ gameSlug }
					/>
				) }
				{ view === 'history' && (
					<>
						<div className="be-help-heading">
							<h3>{ __( 'History', 'beyond-elysium' ) }</h3>
							<HelpButton helpKey="sheet-history" />
						</div>
						<ChangeHistory
							key={ `${ gameSlug }-${ characterId }` }
							characterId={ characterId }
							gameSlug={ gameSlug }
							canWithdraw={ !! character.can_edit }
							canUndo={ !! character.can_manage }
							characterName={ character.name }
							onChanged={ () => refresh() }
						/>
					</>
				) }
				{ view === 'edit' && (
					<CharacterEditor
						key={ `${ gameSlug }-${ characterId }` }
						characterId={ characterId }
						gameSlug={ gameSlug }
						capabilities={ capabilities }
						startAsNpc={ readNewNpcFromUrl() }
					/>
				) }
			</div>
			{ character.can_edit && (
				<CartReview onSeeHistory={ () => onView( 'history' ) } />
			) }
		</div>
	);
}

export default CharacterSection;
