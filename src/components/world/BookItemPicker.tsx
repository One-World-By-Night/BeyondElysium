/**
 * Search/book/type picker over the declared, read-only item catalog - "Add from the book" on Items & Locations and
 * "Start from a book entry" on Propose an Item both open this and hand the chosen entry back to their own caller.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { CatalogBookRef, CatalogItemEntry } from '../../types/world';
import './BookItemPicker.css';

export interface BookItemPickerProps {
	onPick: ( entry: CatalogItemEntry ) => void;
	onCancel: () => void;
}

/**
 * Every distinct `properties.item_type` among the given entries, sorted, for the type filter's own options.
 */
export function availableItemTypes( items: CatalogItemEntry[] ): string[] {
	return Array.from(
		new Set(
			items
				.map( ( item ) => String( item.properties.item_type ?? '' ) )
				.filter( Boolean )
		)
	).sort();
}

export function BookItemPicker( { onPick, onCancel }: BookItemPickerProps ) {
	const [ search, setSearch ] = useState( '' );
	const [ book, setBook ] = useState( '' );
	const [ itemType, setItemType ] = useState( '' );
	const [ items, setItems ] = useState< CatalogItemEntry[] >( [] );
	const [ books, setBooks ] = useState< CatalogBookRef[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( null );
		api.itemsCatalog
			.list( { search, book, itemType } )
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}
				setItems( result.items );
				setBooks( result.books );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__(
							'Failed to load the book catalog. Try again.',
							'beyond-elysium'
						)
					);
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ search, book, itemType ] );

	const itemTypes = availableItemTypes( items );

	return (
		<div className="be-book-item-picker">
			<div className="be-book-item-picker__filters">
				<input
					type="search"
					placeholder={ __( 'Search by name…', 'beyond-elysium' ) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
				/>
				<select
					value={ book }
					onChange={ ( e ) => setBook( e.target.value ) }
				>
					<option value="">
						{ __( 'All books', 'beyond-elysium' ) }
					</option>
					{ books.map( ( b ) => (
						<option key={ b.slug } value={ b.slug }>
							{ b.name }
						</option>
					) ) }
				</select>
				<select
					value={ itemType }
					onChange={ ( e ) => setItemType( e.target.value ) }
				>
					<option value="">
						{ __( 'All types', 'beyond-elysium' ) }
					</option>
					{ itemTypes.map( ( t ) => (
						<option key={ t } value={ t }>
							{ t }
						</option>
					) ) }
				</select>
			</div>

			{ error && (
				<p className="be-book-item-picker__error" role="alert">
					{ error }
				</p>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : error ? null : items.length === 0 ? (
				<p>{ __( 'No matching items.', 'beyond-elysium' ) }</p>
			) : (
				<ul className="be-book-item-picker__list">
					{ items.map( ( item ) => (
						<li key={ item.book_ref }>
							<div className="be-book-item-picker__row">
								<span className="be-book-item-picker__name">
									{ item.name }
								</span>
								<span className="be-book-item-picker__book">
									{ item.book }
								</span>
								<button
									type="button"
									onClick={ () => onPick( item ) }
								>
									{ __( 'Use this', 'beyond-elysium' ) }
								</button>
							</div>
						</li>
					) ) }
				</ul>
			) }

			<button type="button" onClick={ onCancel }>
				{ __( 'Cancel', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default BookItemPicker;
