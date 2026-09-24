/**
 * Admin screen for catalog term translation.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type {
	TranslationBulkRow,
	TranslationFilters,
	TranslationImportResult,
	TranslationRow,
	TranslationStats,
	TranslationStatus,
} from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import HelpButton from '../shared/HelpButton';
import './AdminTranslations.css';
import './Admin.css';

const PER_PAGE = 100;

const STATUS_LABELS: Record< TranslationStatus, string > = {
	draft: __( 'draft', 'beyond-elysium' ),
	needs_review: __( 'needs review', 'beyond-elysium' ),
	approved: __( 'approved', 'beyond-elysium' ),
	conflict: __( 'conflict', 'beyond-elysium' ),
};

/**
 * A short, human name for a locale code this screen has no fixed lookup table for.
 */
function localeName( locale: string ): string {
	try {
		return (
			new Intl.DisplayNames( [ 'en' ], { type: 'language' } ).of(
				locale.replace( '_', '-' )
			) ?? locale
		);
	} catch {
		return locale;
	}
}

function usedInBlocks( row: TranslationRow ): string {
	const blocks = Array.from( new Set( row.used_in.map( ( u ) => u.block ) ) );
	return blocks.join( ', ' );
}

export function AdminTranslations() {
	const [ locales, setLocales ] = useState< string[] >( [] );
	const [ locale, setLocale ] = useState( '' );
	const [ stats, setStats ] = useState< TranslationStats | null >( null );

	const [ filters, setFilters ] = useState< TranslationFilters >( {} );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ page, setPage ] = useState( 1 );

	const [ rows, setRows ] = useState< TranslationRow[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ editValues, setEditValues ] = useState< Record< string, string > >(
		{}
	);
	const [ savingId, setSavingId ] = useState< string | null >( null );
	const [ selected, setSelected ] = useState< Set< string > >( new Set() );

	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ rescanning, setRescanning ] = useState( false );
	const [ bulkSaving, setBulkSaving ] = useState( false );

	const [ importPreview, setImportPreview ] =
		useState< TranslationImportResult | null >( null );
	const [ importFile, setImportFile ] = useState< File | null >( null );
	const [ importing, setImporting ] = useState( false );

	const inputRefs = useRef< Map< string, HTMLInputElement > >( new Map() );

	useEffect( () => {
		api.translations
			.locales()
			.then( ( result ) => {
				const merged = Array.from(
					new Set( [ ...result.installed, ...result.with_rows ] )
				);
				setLocales( merged );
				setLocale( ( current ) => {
					if ( current ) {
						return current;
					}
					return (
						merged.find( ( l ) => l === 'pt_BR' ) ??
						merged.find( ( l ) => l !== 'en_US' ) ??
						merged[ 0 ] ??
						'en_US'
					);
				} );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			);
	}, [] );

	const loadStats = useCallback( () => {
		if ( ! locale ) {
			return;
		}
		api.translations
			.stats( locale )
			.then( setStats )
			.catch( () => undefined );
	}, [ locale ] );

	useEffect( loadStats, [ loadStats ] );

	const loadRows = useCallback( () => {
		if ( ! locale ) {
			return;
		}
		setLoading( true );
		api.translations
			.list( locale, filters, page, PER_PAGE )
			.then( ( result ) => {
				setRows( result.items );
				setTotal( result.total );
				setEditValues( ( prev ) => {
					const next = { ...prev };
					for ( const row of result.items ) {
						if ( ! ( row.id in next ) ) {
							next[ row.id ] = row.translation ?? '';
						}
					}
					return next;
				} );
				setSelected( new Set() );
				setError( null );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setLoading( false ) );
	}, [ locale, filters, page ] );

	useEffect( loadRows, [ loadRows ] );

	// A locale or filter change starts back at page 1.
	useEffect( () => {
		setPage( 1 );
	}, [ locale, filters ] );

	const blockOptions = useMemo(
		() => Object.keys( stats?.by_block ?? {} ).sort(),
		[ stats ]
	);

	function addLanguage() {
		// eslint-disable-next-line no-alert
		const code = window.prompt(
			__(
				'Locale code for the new language (e.g. es_ES):',
				'beyond-elysium'
			)
		);
		const trimmed = code?.trim();
		if ( ! trimmed ) {
			return;
		}
		setLocales( ( prev ) =>
			prev.includes( trimmed ) ? prev : [ ...prev, trimmed ]
		);
		setLocale( trimmed );
	}

	async function rescan() {
		setRescanning( true );
		setError( null );
		try {
			await api.translations.rescan();
			loadStats();
			loadRows();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setRescanning( false );
		}
	}

	/**
	 * Focuses the next visible row's translation field whose value is still empty.
	 */
	function focusNextUntranslated( fromRowId: string ) {
		const ids = rows.map( ( r ) => r.id );
		const fromIndex = ids.indexOf( fromRowId );
		for ( let i = fromIndex + 1; i < ids.length; i++ ) {
			const id = ids[ i ];
			if ( ! ( editValues[ id ] ?? '' ) ) {
				inputRefs.current.get( id )?.focus();
				return true;
			}
		}
		return false;
	}

	function onTranslationKeyDown(
		e: React.KeyboardEvent< HTMLInputElement >,
		rowId: string
	) {
		if ( e.key === 'Tab' && ! e.shiftKey ) {
			if ( focusNextUntranslated( rowId ) ) {
				e.preventDefault();
			}
		}
	}

	async function saveRow(
		row: TranslationRow,
		data: Partial< { translation: string; status: TranslationStatus } >
	) {
		setSavingId( row.id );
		const previous = rows;
		// Optimistic: reflect the edit immediately, roll back on failure.
		setRows( ( prev ) =>
			prev.map( ( r ) =>
				r.id === row.id
					? {
							...r,
							translation: data.translation ?? r.translation,
							status:
								data.status ??
								( data.translation !== undefined &&
								! r.translation_id
									? 'draft'
									: r.status ),
					  }
					: r
			)
		);
		try {
			if ( row.translation_id ) {
				await api.translations.update( row.translation_id, data );
			} else {
				await api.translations.save(
					locale,
					{ stringId: row.id },
					data.translation ?? '',
					data.status ?? 'draft'
				);
			}
			loadStats();
		} catch ( err ) {
			setRows( previous );
			setError(
				errorMessage(
					err,
					__(
						'The translation could not be saved.',
						'beyond-elysium'
					)
				)
			);
		} finally {
			setSavingId( null );
		}
	}

	function onTranslationBlur( row: TranslationRow ) {
		const value = editValues[ row.id ] ?? '';
		if ( value === ( row.translation ?? '' ) ) {
			return;
		}
		saveRow( row, { translation: value } );
	}

	function onStatusChange( row: TranslationRow, status: TranslationStatus ) {
		saveRow( row, { status } );
	}

	function toggleSelected( id: string ) {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );
	}

	function toggleSelectAll() {
		setSelected( ( prev ) =>
			prev.size === rows.length
				? new Set()
				: new Set( rows.map( ( r ) => r.id ) )
		);
	}

	async function markSelectedApproved() {
		const bulkRows: TranslationBulkRow[] = rows
			.filter( ( r ) => selected.has( r.id ) )
			.map( ( r ) => ( {
				source_text: r.source_text,
				translation: editValues[ r.id ] ?? r.translation ?? '',
				status: 'approved' as TranslationStatus,
			} ) )
			.filter( ( r ) => r.translation !== '' );
		if ( bulkRows.length === 0 ) {
			return;
		}
		setBulkSaving( true );
		setError( null );
		try {
			await api.translations.bulk( locale, bulkRows );
			loadRows();
			loadStats();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setBulkSaving( false );
		}
	}

	function onImportFileChosen( e: React.ChangeEvent< HTMLInputElement > ) {
		const file = e.target.files?.[ 0 ];
		e.target.value = '';
		if ( ! file ) {
			return;
		}
		setImportFile( file );
		setImporting( true );
		setError( null );
		api.translations
			.import( locale, file, true )
			.then( setImportPreview )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'The file could not be read.', 'beyond-elysium' )
					)
				)
			)
			.finally( () => setImporting( false ) );
	}

	async function commitImport() {
		if ( ! importFile ) {
			return;
		}
		setImporting( true );
		setError( null );
		try {
			await api.translations.import( locale, importFile, false );
			setImportPreview( null );
			setImportFile( null );
			loadRows();
			loadStats();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'The import could not be committed.', 'beyond-elysium' )
				)
			);
		} finally {
			setImporting( false );
		}
	}

	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const rangeStart = total === 0 ? 0 : ( page - 1 ) * PER_PAGE + 1;
	const rangeEnd = Math.min( total, page * PER_PAGE );

	return (
		<div className="be-admin be-admin-translations">
			<div className="be-help-heading">
				<h1>{ __( 'Translations', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="translations" />
			</div>

			{ error && (
				<p className="be-admin__error" role="alert">
					{ error }
				</p>
			) }

			<div className="be-admin-translations__toolbar">
				<label>
					{ __( 'Language', 'beyond-elysium' ) }
					<select
						value={ locale }
						onChange={ ( e ) => setLocale( e.target.value ) }
					>
						{ locales.map( ( l ) => (
							<option key={ l } value={ l }>
								{ localeName( l ) } ({ l })
							</option>
						) ) }
					</select>
				</label>
				<button type="button" onClick={ addLanguage }>
					{ __( '+ Add a language', 'beyond-elysium' ) }
				</button>
				<button
					type="button"
					disabled={ rescanning }
					onClick={ rescan }
				>
					{ rescanning
						? __( 'Rescanning…', 'beyond-elysium' )
						: __( 'Rescan catalog', 'beyond-elysium' ) }
				</button>
			</div>

			{ stats && (
				<div className="be-admin-translations__progress">
					<p>
						{ sprintf(
							/* translators: 1: translated count, 2: total count, 3: percent */
							__(
								'%1$s of %2$s terms translated (%3$s%%)',
								'beyond-elysium'
							),
							stats.translated.toLocaleString(),
							stats.total.toLocaleString(),
							String(
								stats.total
									? Math.round(
											( stats.translated / stats.total ) *
												100
									  )
									: 0
							)
						) }
					</p>
					<div
						className="be-admin-translations__bar"
						role="progressbar"
						aria-valuenow={ stats.translated }
						aria-valuemin={ 0 }
						aria-valuemax={ stats.total }
					>
						<div
							className="be-admin-translations__bar-fill"
							style={ {
								width: `${
									stats.total
										? ( stats.translated / stats.total ) *
										  100
										: 0
								}%`,
							} }
						/>
					</div>
					<p className="be-admin-translations__status-breakdown">
						{ Object.entries( stats.by_status )
							.map(
								( [ key, count ] ) =>
									`${ count.toLocaleString() } ${
										STATUS_LABELS[
											key as TranslationStatus
										]
									}`
							)
							.join( ' · ' ) }
					</p>
				</div>
			) }

			<div className="be-admin__filters be-admin-translations__filters">
				<label>
					{ __( 'Catalog', 'beyond-elysium' ) }
					<select
						value={ filters.block ?? '' }
						onChange={ ( e ) =>
							setFilters( {
								...filters,
								block: e.target.value || undefined,
							} )
						}
					>
						<option value="">
							{ __( 'All catalogs', 'beyond-elysium' ) }
						</option>
						{ blockOptions.map( ( block ) => (
							<option key={ block } value={ block }>
								{ block }
							</option>
						) ) }
					</select>
				</label>
				<label>
					{ __( 'Status', 'beyond-elysium' ) }
					<select
						value={ filters.status ?? '' }
						onChange={ ( e ) =>
							setFilters( {
								...filters,
								status: ( e.target.value ||
									undefined ) as TranslationFilters[ 'status' ],
							} )
						}
					>
						<option value="">
							{ __( 'Every status', 'beyond-elysium' ) }
						</option>
						<option value="untranslated">
							{ __( 'Untranslated only', 'beyond-elysium' ) }
						</option>
						{ Object.entries( STATUS_LABELS ).map(
							( [ value, label ] ) => (
								<option key={ value } value={ value }>
									{ label }
								</option>
							)
						) }
					</select>
				</label>
				<label>
					{ __( 'Search', 'beyond-elysium' ) }
					<input
						type="text"
						value={ searchInput }
						onChange={ ( e ) => setSearchInput( e.target.value ) }
						onBlur={ () =>
							setFilters( {
								...filters,
								search: searchInput || undefined,
							} )
						}
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								setFilters( {
									...filters,
									search: searchInput || undefined,
								} );
							}
						} }
					/>
				</label>

				<div className="be-admin-translations__csv-actions">
					<a
						className="button"
						href={ api.translations.exportUrl( locale, filters ) }
					>
						{ __( 'Export CSV', 'beyond-elysium' ) }
					</a>
					<label className="button be-admin-translations__import-button">
						{ importing
							? __( 'Reading…', 'beyond-elysium' )
							: __( 'Import CSV', 'beyond-elysium' ) }
						<input
							type="file"
							accept=".csv,text/csv"
							hidden
							disabled={ importing }
							onChange={ onImportFileChosen }
						/>
					</label>
				</div>
			</div>

			{ importPreview && (
				<div className="be-admin__form be-admin-translations__import-preview">
					<h2>
						{ __( 'Import preview (dry run)', 'beyond-elysium' ) }
					</h2>
					<p>
						{ sprintf(
							/* translators: 1: added count, 2: updated count, 3: unchanged count, 4: unmatched count, 5: conflict count */
							__(
								'%1$s added · %2$s updated · %3$s unchanged · %4$s unmatched · %5$s conflicts',
								'beyond-elysium'
							),
							String( importPreview.added ),
							String( importPreview.updated ),
							String( importPreview.unchanged ),
							String( importPreview.unmatched ),
							String( importPreview.conflicts )
						) }
					</p>
					{ importPreview.sample.length > 0 && (
						<table className="be-admin__table">
							<thead>
								<tr>
									<th>{ __( 'Term', 'beyond-elysium' ) }</th>
									<th>
										{ __( 'Outcome', 'beyond-elysium' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ importPreview.sample.map( ( s, i ) => (
									// eslint-disable-next-line react/no-array-index-key
									<tr key={ i }>
										<td>{ s.source_text }</td>
										<td>{ s.outcome }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
					<div className="be-admin__form-actions">
						<button
							type="button"
							disabled={ importing }
							onClick={ commitImport }
						>
							{ __( 'Commit import', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ importing }
							onClick={ () => {
								setImportPreview( null );
								setImportFile( null );
							} }
						>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<div className="be-table-box">
					<table className="be-admin__table be-admin-translations__table be-responsive-table">
						<thead>
							<tr>
								<th>
									<input
										type="checkbox"
										checked={
											rows.length > 0 &&
											selected.size === rows.length
										}
										onChange={ toggleSelectAll }
										aria-label={ __(
											'Select all',
											'beyond-elysium'
										) }
									/>
								</th>
								<th>
									{ __( 'English term', 'beyond-elysium' ) }
								</th>
								<th>
									{ __( 'Appears in', 'beyond-elysium' ) }
								</th>
								<th>{ localeName( locale ) }</th>
								<th>{ __( 'Status', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.id }>
									<td
										data-label={ __(
											'Select',
											'beyond-elysium'
										) }
									>
										<input
											type="checkbox"
											checked={ selected.has( row.id ) }
											onChange={ () =>
												toggleSelected( row.id )
											}
											aria-label={ sprintf(
												/* translators: %s: the catalog term's English name */
												__(
													'Select %s',
													'beyond-elysium'
												),
												row.source_text
											) }
										/>
									</td>
									<td
										data-label={ __(
											'English term',
											'beyond-elysium'
										) }
									>
										{ row.source_text }
									</td>
									<td
										data-label={ __(
											'Appears in',
											'beyond-elysium'
										) }
									>
										{ usedInBlocks( row ) }
									</td>
									<td data-label={ localeName( locale ) }>
										<input
											type="text"
											value={ editValues[ row.id ] ?? '' }
											disabled={ savingId === row.id }
											ref={ ( el ) => {
												if ( el ) {
													inputRefs.current.set(
														row.id,
														el
													);
												} else {
													inputRefs.current.delete(
														row.id
													);
												}
											} }
											onChange={ ( e ) =>
												setEditValues( {
													...editValues,
													[ row.id ]: e.target.value,
												} )
											}
											onBlur={ () =>
												onTranslationBlur( row )
											}
											onKeyDown={ ( e ) =>
												onTranslationKeyDown(
													e,
													row.id
												)
											}
										/>
									</td>
									<td
										data-label={ __(
											'Status',
											'beyond-elysium'
										) }
									>
										{ row.translation_id ? (
											<select
												value={ row.status ?? 'draft' }
												disabled={ savingId === row.id }
												onChange={ ( e ) =>
													onStatusChange(
														row,
														e.target
															.value as TranslationStatus
													)
												}
											>
												{ Object.entries(
													STATUS_LABELS
												).map( ( [ value, label ] ) => (
													<option
														key={ value }
														value={ value }
													>
														{ label }
													</option>
												) ) }
											</select>
										) : (
											'—'
										) }
									</td>
								</tr>
							) ) }
							{ rows.length === 0 && (
								<tr>
									<td colSpan={ 5 }>
										{ __(
											'No catalog terms match these filters.',
											'beyond-elysium'
										) }
									</td>
								</tr>
							) }
						</tbody>
					</table>
				</div>
			) }

			<div className="be-admin-translations__footer">
				<button
					type="button"
					disabled={ selected.size === 0 || bulkSaving }
					onClick={ markSelectedApproved }
				>
					{ bulkSaving
						? __( 'Saving…', 'beyond-elysium' )
						: __( 'Mark selected approved', 'beyond-elysium' ) }
				</button>
				<span className="be-admin-translations__pagination">
					{ sprintf(
						/* translators: 1: range start, 2: range end, 3: total */
						__( '%1$s–%2$s of %3$s', 'beyond-elysium' ),
						rangeStart.toLocaleString(),
						rangeEnd.toLocaleString(),
						total.toLocaleString()
					) }
					<button
						type="button"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ '<' }
					</button>
					<button
						type="button"
						disabled={ page >= totalPages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ '>' }
					</button>
				</span>
			</div>
		</div>
	);
}

export default AdminTranslations;
