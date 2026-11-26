/**
 * The sheet's action bar and the panel each action opens below it.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import SheetStyleEditor from './SheetStyleEditor';
import PointAudit from './PointAudit';
import CreationTallyPanel from './CreationTallyPanel';
import { BackgroundLedger } from '../apr/BackgroundLedger';
import { TransferPanel } from './TransferPanel';
import HelpButton from '../shared/HelpButton';
import { canIn } from '../../lib/chronicleCapabilities';
import { sheetActions, type SheetAction } from '../../lib/sheetActions';
import type { SheetPrintOptions } from '../../lib/useSheetPrintOptions';
import type { MyCapabilities } from '../../types';
import type { Character, SheetStyle } from '../../types/character';

export interface SheetActionsProps {
	character: Character;
	characterId: number;
	gameSlug: string;
	capabilities?: MyCapabilities;
	blockSlugs: string[];
	onStyleChange: ( style: SheetStyle ) => void;
	print: SheetPrintOptions;
	pdfAvailability: { ok: boolean; code: string } | null;
}

export function SheetActions( {
	character,
	characterId,
	gameSlug,
	capabilities,
	blockSlugs,
	onStyleChange,
	print,
	pdfAvailability,
}: SheetActionsProps ) {
	const {
		printBackground,
		setPrintBackground,
		printNotes,
		setPrintNotes,
		printXpHistory,
		setPrintXpHistory,
		printFullPowerNames,
		setPrintFullPowerNames,
		printShowCost,
		setPrintShowCost,
		printPaper,
		setPrintPaper,
	} = print;
	// Which action's panel is open below the buttons.
	const [ openPanel, setOpenPanel ] = useState< SheetAction | '' >( '' );
	const panelRef = useRef< HTMLDivElement >( null );
	const [ exportNotice, setExportNotice ] = useState< string | null >( null );
	const [ exporting, setExporting ] = useState( false );
	// Off by default - mints a fresh, real attestation row on every export.
	const [ includeVerification, setIncludeVerification ] = useState( false );
	const [ ledgerDate, setLedgerDate ] = useState( () =>
		new Date().toISOString().slice( 0, 10 )
	);

	// A keyboard user lands on the panel they asked for.
	useEffect( () => {
		if ( openPanel ) {
			panelRef.current?.focus();
		}
	}, [ openPanel ] );

	/**
	 * Exports this character to a Grapevine `.gex` file and offers it as a browser download.
	 */
	const handleExport = async () => {
		setExporting( true );
		setExportNotice( null );
		try {
			const result = await api
				.characters( gameSlug )
				.export( characterId, {
					hide_st: ! character.can_manage,
					verify: includeVerification,
				} );
			const blob = new Blob( [ result.xml ], {
				type: 'application/xml',
			} );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = `${ character.name.replace(
				/[^a-z0-9]+/gi,
				'_'
			) }.gex`;
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			URL.revokeObjectURL( url );

			const notes = [ ...result.warnings, ...result.transliterations ];
			setExportNotice(
				notes.length > 0
					? sprintf(
							/* translators: %d: number of warnings or transliteration notes from the export */
							__(
								'Exported with %d note(s) - see below.',
								'beyond-elysium'
							),
							notes.length
						) +
							' ' +
							notes.join( ' | ' )
					: __( 'Exported.', 'beyond-elysium' )
			);
		} catch {
			setExportNotice(
				__( 'Export failed. Please try again.', 'beyond-elysium' )
			);
		} finally {
			setExporting( false );
		}
	};

	const actionLabels: Record< SheetAction, string > = {
		print: __( 'Print / Export', 'beyond-elysium' ),
		items: __( 'Print My Items', 'beyond-elysium' ),
		appearance: __( 'Customize appearance', 'beyond-elysium' ),
		ledger: __( 'Background uses', 'beyond-elysium' ),
		send: __( 'Send Sheet', 'beyond-elysium' ),
		audit: __( 'Point audit', 'beyond-elysium' ),
		tally: __( 'Build tally', 'beyond-elysium' ),
		gex: __( 'Export to Grapevine (.gex)', 'beyond-elysium' ),
	};

	/**
	 * Runs an action: Print My Items opens its PDF; every other action opens, or closes, its panel below the buttons.
	 */
	function runAction( action: SheetAction ) {
		if ( action === 'items' ) {
			window.open(
				api.reports( gameSlug ).pdfUrl( 'item-cards', { characterId } ),
				'_blank'
			);
			return;
		}
		setOpenPanel( openPanel === action ? '' : action );
	}

	return (
		<>
			<div
				className="be-character-sheet__chrome be-character-sheet__toolbar"
				role="toolbar"
				aria-label={ __( 'Sheet actions', 'beyond-elysium' ) }
			>
				{ sheetActions( character ).map( ( action ) => (
					<button
						key={ action }
						type="button"
						className="be-character-sheet__action-button"
						aria-pressed={ openPanel === action }
						onClick={ () => runAction( action ) }
					>
						{ actionLabels[ action ] }
					</button>
				) ) }
				<HelpButton helpKey="character-sheet" />
			</div>
			{ exportNotice && (
				<div
					className="be-character-sheet__chrome be-character-sheet__export-notice"
					role="status"
				>
					{ exportNotice }
				</div>
			) }

			{ openPanel && (
				<div
					className="be-character-sheet__chrome be-character-sheet__action-panel"
					ref={ panelRef }
					tabIndex={ -1 }
					aria-label={ actionLabels[ openPanel ] }
				>
					<div className="be-character-sheet__action-panel-header">
						<h4 className="be-character-sheet__section-title">
							{ actionLabels[ openPanel ] }
						</h4>
						{ ( openPanel === 'print' || openPanel === 'gex' ) && (
							<HelpButton helpKey="sheet-print-export" />
						) }
						{ openPanel === 'audit' && (
							<HelpButton helpKey="point-audit" />
						) }
						{ openPanel === 'ledger' && (
							<HelpButton helpKey="background-uses" />
						) }
						{ openPanel === 'send' && (
							<HelpButton helpKey="transfer" />
						) }
						<button
							type="button"
							className="be-character-sheet__action-close"
							onClick={ () => setOpenPanel( '' ) }
						>
							{ __( 'Close', 'beyond-elysium' ) }
						</button>
					</div>

					{ /* What prints is chosen here. XP History and Full power names also change this page. */ }
					{ openPanel === 'print' && (
						<>
							<div className="be-character-sheet__print-options">
								<span>
									{ __(
										'Include when printing:',
										'beyond-elysium'
									) }
								</span>
								<label>
									<input
										type="checkbox"
										checked={ printBackground }
										onChange={ ( e ) =>
											setPrintBackground(
												e.target.checked
											)
										}
									/>
									{ __( 'Background', 'beyond-elysium' ) }
								</label>
								<label>
									<input
										type="checkbox"
										checked={ printNotes }
										onChange={ ( e ) =>
											setPrintNotes( e.target.checked )
										}
									/>
									{ __( 'Notes', 'beyond-elysium' ) }
								</label>
								<label>
									<input
										type="checkbox"
										checked={ printXpHistory }
										onChange={ ( e ) =>
											setPrintXpHistory(
												e.target.checked
											)
										}
									/>
									{ __( 'XP History', 'beyond-elysium' ) }
								</label>
								<label>
									<input
										type="checkbox"
										checked={ printFullPowerNames }
										onChange={ ( e ) =>
											setPrintFullPowerNames(
												e.target.checked
											)
										}
									/>
									{ __(
										'Full power names',
										'beyond-elysium'
									) }
								</label>
								<label>
									<input
										type="checkbox"
										checked={ printShowCost }
										onChange={ ( e ) =>
											setPrintShowCost( e.target.checked )
										}
									/>
									{ __( 'Show XP costs', 'beyond-elysium' ) }
								</label>
								<label>
									{ __( 'Paper', 'beyond-elysium' ) }
									<select
										className="be-character-sheet__print-paper"
										value={ printPaper }
										onChange={ ( e ) =>
											setPrintPaper(
												e.target.value === 'a4'
													? 'a4'
													: 'letter'
											)
										}
									>
										<option value="letter">
											{ __( 'Letter', 'beyond-elysium' ) }
										</option>
										<option value="a4">
											{ __( 'A4', 'beyond-elysium' ) }
										</option>
									</select>
								</label>
							</div>
							{ pdfAvailability !== null &&
								! pdfAvailability.ok && (
									<p
										className="be-character-sheet__print-unsigned"
										role="status"
									>
										{ pdfAvailability.code ===
										'secure_printing_off'
											? __(
													'Secure printing is switched off for this site, so prints are marked UNSIGNED.',
													'beyond-elysium'
												)
											: __(
													'This site has no signing certificate yet, so prints are marked UNSIGNED.',
													'beyond-elysium'
												) }
									</p>
								) }
							<button
								type="button"
								className="be-character-sheet__print"
								onClick={ () =>
									window.open(
										api
											.sheets( gameSlug )
											.pdfUrl( [ characterId ], {
												background: printBackground,
												notes: printNotes,
												xpHistory: printXpHistory,
												fullPowerNames:
													printFullPowerNames,
												showCost: printShowCost,
												pageSize: printPaper,
											} ),
										'_blank'
									)
								}
							>
								{ __( 'Open PDF', 'beyond-elysium' ) }
							</button>
						</>
					) }

					{ openPanel === 'gex' && (
						<div className="be-character-sheet__gex-options">
							<label className="be-character-sheet__verify-toggle">
								<input
									type="checkbox"
									checked={ includeVerification }
									onChange={ ( e ) =>
										setIncludeVerification(
											e.target.checked
										)
									}
								/>
								{ __(
									'Include verification code',
									'beyond-elysium'
								) }
							</label>
							<button
								type="button"
								className="be-character-sheet__gex-export"
								disabled={ exporting }
								onClick={ handleExport }
							>
								{ exporting
									? __( 'Exporting…', 'beyond-elysium' )
									: __(
											'Download .gex file',
											'beyond-elysium'
										) }
							</button>
						</div>
					) }

					{ openPanel === 'appearance' &&
						character.can_customize_sheet && (
							<SheetStyleEditor
								characterId={ characterId }
								gameSlug={ gameSlug }
								blockSlugs={ blockSlugs }
								onChange={ onStyleChange }
							/>
						) }

					{ openPanel === 'audit' && character.can_manage && (
						<PointAudit
							characterId={ characterId }
							gameSlug={ gameSlug }
						/>
					) }

					{ openPanel === 'tally' && character.can_manage && (
						<CreationTallyPanel
							characterId={ characterId }
							gameSlug={ gameSlug }
						/>
					) }

					{ openPanel === 'ledger' && (
						<>
							<label className="be-character-sheet__ledger-date">
								{ __( 'Game date', 'beyond-elysium' ) }
								<input
									type="date"
									value={ ledgerDate }
									onChange={ ( e ) =>
										setLedgerDate( e.target.value )
									}
								/>
							</label>
							<BackgroundLedger
								gameSlug={ gameSlug }
								characterId={ characterId }
								gameDate={ ledgerDate }
								canManage={ canIn(
									'be_manage_characters',
									capabilities
								) }
							/>
						</>
					) }

					{ openPanel === 'send' && character.can_manage && (
						<TransferPanel
							gameSlug={ gameSlug }
							characterId={ characterId }
							characterUuid={ character.uuid }
							visits={ character.visits ?? [] }
							visitingFrom={ character.visiting_from ?? null }
						/>
					) }
				</div>
			) }
		</>
	);
}

export default SheetActions;
