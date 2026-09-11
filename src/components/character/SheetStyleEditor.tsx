/**
 * SheetStyleEditor is the appearance-customization panel for one character's
 * sheet: font, accent/background/text colors, a background image, and a
 * per-section graphic picker. Saves each change immediately and reports the
 * resulting style back to the caller through onChange.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { pickMediaImage } from '../../lib/pickMediaImage';
import type { SheetStyle } from '../../types/character';
import './SheetStyleEditor.css';

export interface SheetStyleEditorProps {
	characterId: number;
	gameSlug: string;
	/** Block slugs actually on this sheet, for the per-section graphic pickers. */
	blockSlugs: string[];
	onChange: ( style: SheetStyle ) => void;
}

/** The curated font choices offered for a sheet; these values are written directly into CSS. */
const FONT_CHOICES: { value: string; label: string }[] = [
	{ value: '', label: __( 'Default', 'beyond-elysium' ) },
	{ value: 'Georgia, serif', label: __( 'Georgia', 'beyond-elysium' ) },
	{ value: "'Times New Roman', serif", label: __( 'Times New Roman', 'beyond-elysium' ) },
	{ value: "'Garamond', serif", label: __( 'Garamond', 'beyond-elysium' ) },
	{ value: "'Trajan Pro', 'Cinzel', serif", label: __( 'Trajan Pro', 'beyond-elysium' ) },
	{ value: "'Cormorant Garamond', serif", label: __( 'Cormorant Garamond', 'beyond-elysium' ) },
	{ value: 'Arial, sans-serif', label: __( 'Arial', 'beyond-elysium' ) },
	{ value: "'Helvetica Neue', sans-serif", label: __( 'Helvetica Neue', 'beyond-elysium' ) },
	{ value: "'Segoe UI', sans-serif", label: __( 'Segoe UI', 'beyond-elysium' ) },
];

/**
 * Renders the appearance-customization controls for one character's sheet: font,
 * accent/background/text color pickers, a background image picker, and a
 * per-section graphic picker for each block slug given. Each change saves
 * immediately through the sheet-style API.
 */
export function SheetStyleEditor( { characterId, gameSlug, blockSlugs, onChange }: SheetStyleEditorProps ) {
	const [ style, setStyle ] = useState<SheetStyle>( {} );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		api.sheetStyle( gameSlug )
			.get( characterId )
			.then( ( loaded ) => {
				setStyle( loaded );
				onChange( loaded );
			} )
			.catch( () => setError( __( 'Failed to load the current sheet style.', 'beyond-elysium' ) ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ characterId, gameSlug ] );

	async function save( next: SheetStyle ) {
		setSaving( true );
		setError( null );
		try {
			const saved = await api.sheetStyle( gameSlug ).save( characterId, {
				font_family: next.font_family ?? '',
				accent_color: next.accent_color ?? null,
				background_color: next.background_color ?? null,
				text_color: next.text_color ?? null,
				background_image_id: next.background_image_id ?? null,
				section_graphics: next.section_graphics ?? {},
			} );
			setStyle( saved );
			onChange( saved );
		} catch ( err: unknown ) {
			setError( err instanceof Object && 'message' in err ? String( err.message ) : __( 'Failed to save.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	async function reset() {
		setSaving( true );
		setError( null );
		try {
			await api.sheetStyle( gameSlug ).reset( characterId );
			const empty: SheetStyle = {};
			setStyle( empty );
			onChange( empty );
		} catch {
			setError( __( 'Failed to reset.', 'beyond-elysium' ) );
		} finally {
			setSaving( false );
		}
	}

	async function pickBackground() {
		const attachment = await pickMediaImage( __( 'Choose a sheet background image', 'beyond-elysium' ) );
		if ( attachment ) {
			save( { ...style, background_image_id: attachment.id, background_image_url: attachment.url } );
		}
	}

	async function pickSectionGraphic( blockSlug: string ) {
		const attachment = await pickMediaImage( sprintf( __( 'Choose a graphic for "%s"', 'beyond-elysium' ), blockSlug ) );
		if ( attachment ) {
			save( {
				...style,
				section_graphics: { ...( style.section_graphics ?? {} ), [ blockSlug ]: attachment.id },
			} );
		}
	}

	return (
		<div className="be-sheet-style-editor">
			<h4>{ __( 'Sheet Appearance', 'beyond-elysium' ) }</h4>
			{ error && (
				<p className="be-sheet-style-editor__error" role="alert">
					{ error }
				</p>
			) }

			<div className="be-sheet-style-editor__row">
				<label htmlFor="be-sheet-style-font">{ __( 'Font', 'beyond-elysium' ) }</label>
				<select
					id="be-sheet-style-font"
					value={ style.font_family ?? '' }
					disabled={ saving }
					onChange={ ( e ) => save( { ...style, font_family: e.target.value } ) }
				>
					{ FONT_CHOICES.map( ( f ) => (
						<option key={ f.value } value={ f.value }>
							{ f.label }
						</option>
					) ) }
				</select>
			</div>

			<div className="be-sheet-style-editor__row">
				<label htmlFor="be-sheet-style-accent">{ __( 'Accent color', 'beyond-elysium' ) }</label>
				<input
					id="be-sheet-style-accent"
					type="color"
					value={ style.accent_color ?? '#000000' }
					disabled={ saving }
					onChange={ ( e ) => save( { ...style, accent_color: e.target.value } ) }
				/>
			</div>

			<div className="be-sheet-style-editor__row">
				<label htmlFor="be-sheet-style-bg-color">{ __( 'Background color', 'beyond-elysium' ) }</label>
				<input
					id="be-sheet-style-bg-color"
					type="color"
					value={ style.background_color ?? '#ffffff' }
					disabled={ saving }
					onChange={ ( e ) => save( { ...style, background_color: e.target.value } ) }
				/>
			</div>

			<div className="be-sheet-style-editor__row">
				<label htmlFor="be-sheet-style-text">{ __( 'Text color', 'beyond-elysium' ) }</label>
				<input
					id="be-sheet-style-text"
					type="color"
					value={ style.text_color ?? '#000000' }
					disabled={ saving }
					onChange={ ( e ) => save( { ...style, text_color: e.target.value } ) }
				/>
			</div>

			<div className="be-sheet-style-editor__row">
				<span>{ __( 'Background image', 'beyond-elysium' ) }</span>
				<button type="button" disabled={ saving } onClick={ pickBackground }>
					{ style.background_image_url ? __( 'Change…', 'beyond-elysium' ) : __( 'Choose…', 'beyond-elysium' ) }
				</button>
				{ style.background_image_url && (
					<img
						className="be-sheet-style-editor__preview"
						src={ style.background_image_url }
						alt={ __( 'Sheet background preview', 'beyond-elysium' ) }
					/>
				) }
			</div>

			{ blockSlugs.length > 0 && (
				<div className="be-sheet-style-editor__section-graphics">
					<span>{ __( 'Section graphics', 'beyond-elysium' ) }</span>
					<ul>
						{ blockSlugs.map( ( slug ) => (
							<li key={ slug }>
								{ slug }
								<button type="button" disabled={ saving } onClick={ () => pickSectionGraphic( slug ) }>
									{ style.section_graphic_urls?.[ slug ] ? __( 'Change…', 'beyond-elysium' ) : __( 'Choose…', 'beyond-elysium' ) }
								</button>
							</li>
						) ) }
					</ul>
				</div>
			) }

			<button type="button" disabled={ saving } onClick={ reset }>
				{ __( 'Reset to default', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default SheetStyleEditor;
