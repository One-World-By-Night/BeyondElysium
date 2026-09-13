/**
 * Searchable combobox input: filters a flat list of string options as the
 * user types, supports keyboard navigation and optional entry of a value
 * not in the list, and virtualizes its dropdown when the filtered list is
 * long. Used anywhere a plain <select> would be too long to scan.
 */
import { createPortal, useEffect, useId, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { KeyboardEvent } from 'react';
import { canUseCustomEntry, filterOptions, resolveBlurCommit } from '../../lib/searchableSelect';
import './SearchableSelect.css';

export interface SearchableSelectProps {
	options: string[];
	value: string;
	/** Called with the committed value and whether it came from the custom-entry row rather than the option list. */
	onChange: ( value: string, isCustom: boolean ) => void;
	allowCustom?: boolean;
	placeholder?: string;
	disabled?: boolean;
	/** Accessible name for the input, since this component has no visible <label> of its own; falls back to `placeholder`. */
	ariaLabel?: string;
	/** Lets an external <label htmlFor> target this component's internal <input>. */
	id?: string;
}

/** Above this many filtered options, only a scroll window of rows is rendered. */
const VIRTUALIZE_THRESHOLD = 200;
/** Fallback only, used until the first real option is measured (mobile-sheet-design.md §5.4) - never trusted as fact. */
const FALLBACK_ROW_HEIGHT = 28;
const VISIBLE_ROWS = 10;

/**
 * Renders a text input with a filtered dropdown listbox, wired up with
 * ARIA combobox semantics: `aria-expanded` reflects whether the dropdown
 * is open, `aria-activedescendant` points at the highlighted option, and
 * `aria-controls` links the input to the listbox. Arrow keys move the
 * highlight, Enter commits the highlighted row, and Escape closes the
 * dropdown without changing the value. Typing filters the option list
 * live; when `allowCustom` is set and the typed text matches no option,
 * an extra "use as custom entry" row is appended so it can be committed
 * directly. Renders only a windowed slice of rows once the filtered list
 * passes `VIRTUALIZE_THRESHOLD`, to keep long lists scrolling smoothly.
 */
export function SearchableSelect( { options, value, onChange, allowCustom, placeholder, disabled, ariaLabel, id }: SearchableSelectProps ) {
	const [ query, setQuery ] = useState( value );
	const [ open, setOpen ] = useState( false );
	const [ highlighted, setHighlighted ] = useState( 0 );
	const [ scrollTop, setScrollTop ] = useState( 0 );
	const [ rowHeight, setRowHeight ] = useState( FALLBACK_ROW_HEIGHT );
	const [ listRect, setListRect ] = useState<{ top: number; left: number; width: number; openUpward: boolean } | null>( null );
	const listRef = useRef<HTMLUListElement>( null );
	const inputRef = useRef<HTMLInputElement>( null );
	const measuredOptionRef = useRef<HTMLLIElement | null>( null );
	const listboxId = useId();
	const optionId = ( index: number ) => `${ listboxId }-option-${ index }`;

	// Portaled to document.body (§5.4), so its own position has to be computed from the
	// input's real rect rather than inherited from CSS flow - recomputed on open and kept
	// live while open, since the input can move under scroll or a resize without closing
	// the dropdown first.
	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const reposition = () => {
			const rect = inputRef.current?.getBoundingClientRect();
			if ( ! rect ) {
				return;
			}
			const spaceBelow = window.innerHeight - rect.bottom;
			const openUpward = spaceBelow < 200 && rect.top > spaceBelow;
			setListRect( { top: openUpward ? rect.top : rect.bottom, left: rect.left, width: rect.width, openUpward } );
		};
		reposition();
		window.addEventListener( 'scroll', reposition, true );
		window.addEventListener( 'resize', reposition );
		return () => {
			window.removeEventListener( 'scroll', reposition, true );
			window.removeEventListener( 'resize', reposition );
		};
	}, [ open ] );

	// Measures the real rendered row height once an option exists, rather than trusting a
	// hardcoded constant CSS has never actually matched (§3.7/§5.4) - a real 865-item
	// catalog measured 37px against a hardcoded 28px, a 24% scroll-track error that grows
	// to 36% once the touch-target floor (§5.2) enlarges the option rows further.
	const measureFirstOption = ( el: HTMLLIElement | null ) => {
		measuredOptionRef.current = el;
		if ( el ) {
			const height = el.getBoundingClientRect().height;
			if ( height > 0 && height !== rowHeight ) {
				setRowHeight( height );
			}
		}
	};

	const filtered = useMemo( () => filterOptions( options, query ), [ options, query ] );
	const showCustomRow = useMemo(
		() => canUseCustomEntry( query, options, allowCustom ?? false ),
		[ query, options, allowCustom ]
	);

	// The custom row, when shown, is appended after the filtered options as a navigable row.
	const rowCount = filtered.length + ( showCustomRow ? 1 : 0 );

	/**
	 * Commits the option (or the custom-entry row) at `index`: updates the
	 * input text, calls `onChange` with the resulting value and whether it
	 * was a custom entry, and closes the dropdown.
	 */
	const selectIndex = ( index: number ) => {
		if ( index === filtered.length && showCustomRow ) {
			onChange( query.trim(), true );
			setQuery( query.trim() );
		} else if ( filtered[ index ] !== undefined ) {
			onChange( filtered[ index ], false );
			setQuery( filtered[ index ] );
		}
		setOpen( false );
	};

	/**
	 * Keyboard handler for the input: ArrowDown/ArrowUp open the dropdown
	 * if closed, or move the highlighted row while open (clamped to the
	 * row count); Enter commits the highlighted row via `selectIndex`;
	 * Escape closes the dropdown without committing anything.
	 */
	const onKeyDown = ( e: KeyboardEvent<HTMLInputElement> ) => {
		if ( ! open && ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) ) {
			setOpen( true );
			return;
		}
		if ( ! open ) {
			return;
		}

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			setHighlighted( ( i ) => Math.min( i + 1, rowCount - 1 ) );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			setHighlighted( ( i ) => Math.max( i - 1, 0 ) );
		} else if ( e.key === 'Enter' ) {
			e.preventDefault();
			selectIndex( highlighted );
		} else if ( e.key === 'Escape' ) {
			setOpen( false );
		}
	};

	const virtualized = filtered.length > VIRTUALIZE_THRESHOLD;
	const listHeight = rowHeight * VISIBLE_ROWS;
	const firstVisible = virtualized ? Math.max( 0, Math.floor( scrollTop / rowHeight ) - 2 ) : 0;
	const lastVisible = virtualized
		? Math.min( filtered.length, firstVisible + VISIBLE_ROWS + 4 )
		: filtered.length;
	const visibleOptions = virtualized ? filtered.slice( firstVisible, lastVisible ) : filtered;

	const dropdown = open && rowCount > 0 && listRect && (
		<ul
			className="be-searchable-select__list be-searchable-select__list--portaled"
			ref={ listRef }
			id={ listboxId }
			role="listbox"
			style={ {
				position: 'fixed',
				top: listRect.openUpward ? undefined : listRect.top,
				bottom: listRect.openUpward ? window.innerHeight - listRect.top : undefined,
				left: listRect.left,
				width: listRect.width,
				...( virtualized ? { height: listHeight, overflowY: 'auto' } : {} ),
			} }
			onScroll={ ( e ) => setScrollTop( ( e.target as HTMLUListElement ).scrollTop ) }
		>
			{ virtualized && <li style={ { height: firstVisible * rowHeight } } /> }
			{ visibleOptions.map( ( option, i ) => {
				const index = virtualized ? firstVisible + i : i;
				return (
					<li
						key={ option }
						id={ optionId( index ) }
						ref={ i === 0 ? measureFirstOption : undefined }
						role="option"
						aria-selected={ index === highlighted }
						className={
							'be-searchable-select__option' +
							( index === highlighted ? ' be-searchable-select__option--highlighted' : '' )
						}
						onMouseDown={ () => selectIndex( index ) }
					>
						{ option }
					</li>
				);
			} ) }
			{ virtualized && <li style={ { height: ( filtered.length - lastVisible ) * rowHeight } } /> }
			{ showCustomRow && (
				<li
					id={ optionId( filtered.length ) }
					role="option"
					aria-selected={ filtered.length === highlighted }
					className={
						'be-searchable-select__option be-searchable-select__option--custom' +
						( filtered.length === highlighted ? ' be-searchable-select__option--highlighted' : '' )
					}
					onMouseDown={ () => selectIndex( filtered.length ) }
				>
					{ sprintf( __( 'Use "%1$s" (custom)', 'beyond-elysium' ), query.trim() ) }
				</li>
			) }
		</ul>
	);

	return (
		<div className="be-searchable-select">
			<input
				type="text"
				id={ id }
				ref={ inputRef }
				className="be-searchable-select__input"
				value={ query }
				placeholder={ placeholder }
				disabled={ disabled }
				role="combobox"
				aria-expanded={ open && rowCount > 0 }
				aria-controls={ listboxId }
				aria-autocomplete="list"
				aria-activedescendant={ open && rowCount > 0 ? optionId( highlighted ) : undefined }
				aria-label={ ariaLabel ?? placeholder }
				autoComplete="off"
				onFocus={ () => setOpen( true ) }
				onBlur={ () => {
					// Commits a typed value on blur even when no dropdown row was explicitly picked.
					const commit = resolveBlurCommit( query, options, allowCustom ?? false );
					if ( commit ) {
						onChange( commit.value, commit.isCustom );
					}
					window.setTimeout( () => setOpen( false ), 150 );
				} }
				onChange={ ( e ) => {
					setQuery( e.target.value );
					setHighlighted( 0 );
					setOpen( true );
				} }
				onKeyDown={ onKeyDown }
			/>
			{ dropdown && createPortal( dropdown, document.body ) }
		</div>
	);
}

export default SearchableSelect;
