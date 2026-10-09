/**
 * SheetStyleEditor is the appearance-customization panel for one character's sheet: font, accent/background/text
 * colors, a background image, and a per-section graphic picker.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { readableTextFor, readsWell } from '../../lib/colorContrast';
import { pickMediaImage } from '../../lib/pickMediaImage';
import type { SheetStyle } from '../../types/character';
import HelpButton from '../shared/HelpButton';
import './SheetStyleEditor.css';

export interface SheetStyleEditorProps {
	characterId: number;
	gameSlug: string;
	/**
	 * Block slugs actually on this sheet, for the per-section graphic pickers.
	 */
	blockSlugs: string[];
	onChange: ( style: SheetStyle ) => void;
}

/**
 * The curated font choices offered for a sheet.
 */
const FONT_CHOICES: { value: string; label: string }[] = [
	{ value: '', label: __( 'Default', 'beyond-elysium' ) },
	{ value: 'Georgia, serif', label: __( 'Georgia', 'beyond-elysium' ) },
	{
		value: "'Times New Roman', serif",
		label: __( 'Times New Roman', 'beyond-elysium' ),
	},
	{ value: "'Garamond', serif", label: __( 'Garamond', 'beyond-elysium' ) },
	{
		value: "'Trajan Pro', 'Cinzel', serif",
		label: __( 'Trajan Pro', 'beyond-elysium' ),
	},
	{
		value: "'Cormorant Garamond', serif",
		label: __( 'Cormorant Garamond', 'beyond-elysium' ),
	},
	{ value: 'Arial, sans-serif', label: __( 'Arial', 'beyond-elysium' ) },
	{
		value: "'Helvetica Neue', sans-serif",
		label: __( 'Helvetica Neue', 'beyond-elysium' ),
	},
	{
		value: "'Segoe UI', sans-serif",
		label: __( 'Segoe UI', 'beyond-elysium' ),
	},
];

/**
 * A color input that follows the picker while it is open and saves once, when the choice is committed.
 */
function ColorField( {
	id,
	label,
	value,
	fallback,
	onPreview,
	onCommit,
}: {
	id: string;
	label: string;
	value: string | null | undefined;
	fallback: string;
	onPreview: ( color: string ) => void;
	onCommit: ( color: string ) => void;
} ) {
	const input = useRef< HTMLInputElement >( null );
	const commit = useRef( onCommit );
	commit.current = onCommit;
	const [ draft, setDraft ] = useState( value ?? fallback );

	useEffect( () => {
		setDraft( value ?? fallback );
	}, [ value, fallback ] );

	useEffect( () => {
		const element = input.current;
		if ( ! element ) {
			return undefined;
		}
		const onChange = () => commit.current( element.value );
		element.addEventListener( 'change', onChange );
		return () => element.removeEventListener( 'change', onChange );
	}, [] );

	return (
		<div className="be-sheet-style-editor__row">
			<label htmlFor={ id }>{ label }</label>
			<input
				id={ id }
				ref={ input }
				type="color"
				value={ draft }
				onChange={ ( e ) => {
					setDraft( e.target.value );
					onPreview( e.target.value );
				} }
			/>
		</div>
	);
}

/**
 * Renders the appearance-customization controls for one character's sheet.
 */
export function SheetStyleEditor( {
	characterId,
	gameSlug,
	blockSlugs,
	onChange,
}: SheetStyleEditorProps ) {
	const [ style, setStyle ] = useState< SheetStyle >( {} );
	const [ loaded, setLoaded ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		api.sheetStyle( gameSlug )
			.get( characterId )
			.then( ( current ) => {
				setStyle( current );
				onChange( current );
			} )
			.catch( () =>
				setError(
					__(
						'Failed to load the current sheet style.',
						'beyond-elysium'
					)
				)
			)
			.finally( () => setLoaded( true ) );
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
			setError(
				err instanceof Object && 'message' in err
					? String( err.message )
					: __( 'Failed to save.', 'beyond-elysium' )
			);
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
		const attachment = await pickMediaImage(
			__( 'Choose a sheet background image', 'beyond-elysium' )
		);
		if ( attachment ) {
			save( {
				...style,
				background_image_id: attachment.id,
				background_image_url: attachment.url,
			} );
		}
	}

	async function pickSectionGraphic( blockSlug: string ) {
		const attachment = await pickMediaImage(
			sprintf(
				/* translators: %s: the schema block's slug this section graphic is for */
				__( 'Choose a graphic for "%s"', 'beyond-elysium' ),
				blockSlug
			)
		);
		if ( attachment ) {
			save( {
				...style,
				section_graphics: {
					...( style.section_graphics ?? {} ),
					[ blockSlug ]: attachment.id,
				},
			} );
		}
	}

	const effectiveText =
		style.text_color ||
		( style.background_color
			? readableTextFor( style.background_color )
			: null );
	const hardToRead =
		!! style.background_color &&
		!! effectiveText &&
		( ! readsWell( effectiveText, style.background_color ) ||
			( !! style.accent_color &&
				! readsWell( style.accent_color, style.background_color ) ) );

	return (
		<div className="be-sheet-style-editor">
			<div className="be-help-heading">
				<h4>{ __( 'Sheet Appearance', 'beyond-elysium' ) }</h4>
				<HelpButton helpKey="sheet-customize" />
			</div>
			{ error && (
				<p className="be-sheet-style-editor__error" role="alert">
					{ error }
				</p>
			) }

			{ ! loaded && <p>{ __( 'Loading…', 'beyond-elysium' ) }</p> }

			{ loaded && (
				<>
					<div className="be-sheet-style-editor__row">
						<label htmlFor="be-sheet-style-font">
							{ __( 'Font', 'beyond-elysium' ) }
						</label>
						<select
							id="be-sheet-style-font"
							value={ style.font_family ?? '' }
							disabled={ saving }
							onChange={ ( e ) =>
								save( {
									...style,
									font_family: e.target.value,
								} )
							}
						>
							{ FONT_CHOICES.map( ( f ) => (
								<option key={ f.value } value={ f.value }>
									{ f.label }
								</option>
							) ) }
						</select>
					</div>

					<ColorField
						id="be-sheet-style-accent"
						label={ __( 'Accent color', 'beyond-elysium' ) }
						value={ style.accent_color }
						fallback="#000000"
						onPreview={ ( color ) =>
							onChange( { ...style, accent_color: color } )
						}
						onCommit={ ( color ) =>
							save( { ...style, accent_color: color } )
						}
					/>

					<ColorField
						id="be-sheet-style-bg-color"
						label={ __( 'Background color', 'beyond-elysium' ) }
						value={ style.background_color }
						fallback="#ffffff"
						onPreview={ ( color ) =>
							onChange( { ...style, background_color: color } )
						}
						onCommit={ ( color ) =>
							save( { ...style, background_color: color } )
						}
					/>

					<ColorField
						id="be-sheet-style-text"
						label={ __( 'Text color', 'beyond-elysium' ) }
						value={ style.text_color }
						fallback="#000000"
						onPreview={ ( color ) =>
							onChange( { ...style, text_color: color } )
						}
						onCommit={ ( color ) =>
							save( { ...style, text_color: color } )
						}
					/>

					{ hardToRead && (
						<p
							className="be-sheet-style-editor__hint"
							role="status"
						>
							{ __(
								'These colors are hard to read together - try a lighter text color on a dark background, or the reverse.',
								'beyond-elysium'
							) }
						</p>
					) }

					<div className="be-sheet-style-editor__row">
						<span>
							{ __( 'Background image', 'beyond-elysium' ) }
						</span>
						<button
							type="button"
							disabled={ saving }
							onClick={ pickBackground }
						>
							{ style.background_image_url
								? __( 'Change…', 'beyond-elysium' )
								: __( 'Choose…', 'beyond-elysium' ) }
						</button>
						{ style.background_image_url && (
							<img
								className="be-sheet-style-editor__preview"
								src={ style.background_image_url }
								alt={ __(
									'Sheet background preview',
									'beyond-elysium'
								) }
							/>
						) }
					</div>

					{ blockSlugs.length > 0 && (
						<div className="be-sheet-style-editor__section-graphics">
							<span>
								{ __( 'Section graphics', 'beyond-elysium' ) }
							</span>
							<ul>
								{ blockSlugs.map( ( slug ) => (
									<li key={ slug }>
										{ slug }
										<button
											type="button"
											disabled={ saving }
											onClick={ () =>
												pickSectionGraphic( slug )
											}
										>
											{ style.section_graphic_urls?.[
												slug
											]
												? __(
														'Change…',
														'beyond-elysium'
													)
												: __(
														'Choose…',
														'beyond-elysium'
													) }
										</button>
									</li>
								) ) }
							</ul>
						</div>
					) }

					<button type="button" disabled={ saving } onClick={ reset }>
						{ __( 'Reset to default', 'beyond-elysium' ) }
					</button>
				</>
			) }
		</div>
	);
}

export default SheetStyleEditor;
