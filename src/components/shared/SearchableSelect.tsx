/**
 * Searchable combobox input: filters a list of string options as the user types,
 * supports keyboard navigation and optional entry of a value not in the list, and
 * virtualizes its dropdown when the filtered list is long. Used anywhere a plain
 * <select> would be too long to scan.
 *
 * Options arrive either flat (`options`) or sectioned (`groups`, 1.2.9 U4) - a Fera
 * player picking a Gift faced **865 unsorted names in one list** while the catalog
 * knew every one of their species all along. Both shapes render through the same flat
 * row list, so grouping adds a heading row and changes nothing else.
 */
import {
	createPortal,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { KeyboardEvent } from 'react';
import {
	buildOptionRows,
	canUseCustomEntry,
	flattenGroups,
	nextSelectableRow,
	resolveBlurCommit,
	type OptionGroup,
} from '../../lib/searchableSelect';
import './SearchableSelect.css';

interface SearchableSelectCommonProps {
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

/**
 * Exactly one of `options` or `groups`, enforced by the union rather than by a runtime
 * check - a caller cannot pass both and leave it ambiguous which list is authoritative.
 */
export type SearchableSelectProps = SearchableSelectCommonProps &
	(
		| { options: string[]; groups?: never }
		| { groups: OptionGroup[]; options?: never }
	);

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
export function SearchableSelect( {
	options,
	groups,
	value,
	onChange,
	allowCustom,
	placeholder,
	disabled,
	ariaLabel,
	id,
}: SearchableSelectProps ) {
	const [ query, setQuery ] = useState( value );
	const [ open, setOpen ] = useState( false );
	const [ highlighted, setHighlighted ] = useState( 0 );
	const [ scrollTop, setScrollTop ] = useState( 0 );
	const [ rowHeight, setRowHeight ] = useState( FALLBACK_ROW_HEIGHT );
	const [ listRect, setListRect ] = useState< {
		top: number;
		left: number;
		width: number;
		openUpward: boolean;
	} | null >( null );
	const listRef = useRef< HTMLUListElement >( null );
	const inputRef = useRef< HTMLInputElement >( null );
	const measuredOptionRef = useRef< HTMLLIElement | null >( null );
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
			setListRect( {
				top: openUpward ? rect.top : rect.bottom,
				left: rect.left,
				width: rect.width,
				openUpward,
			} );
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

	// One list whether the caller grouped or not: an ungrouped caller is simply a single
	// group with no heading, so there is one code path below rather than two.
	const sections = useMemo(
		() => groups ?? [ { label: '', options: options ?? [] } ],
		[ groups, options ]
	);
	const allOptions = useMemo( () => flattenGroups( sections ), [ sections ] );
	const rows = useMemo(
		() => buildOptionRows( sections, query ),
		[ sections, query ]
	);
	const showCustomRow = useMemo(
		() => canUseCustomEntry( query, allOptions, allowCustom ?? false ),
		[ query, allOptions, allowCustom ]
	);

	// The custom row, when shown, is appended after every section as a navigable row.
	const rowCount = rows.length + ( showCustomRow ? 1 : 0 );

	// Row 0 can be a heading, and re-filtering can turn the highlighted row into one, so
	// the highlight snaps to the first genuinely selectable row whenever the list changes
	// under it. Without this, Enter on a freshly opened or freshly filtered grouped list
	// commits nothing and reads as broken.
	useEffect( () => {
		const current = rows[ highlighted ];
		if (
			highlighted < rowCount &&
			( ! current || current.kind === 'option' )
		) {
			return;
		}
		const next = nextSelectableRow( rows, 0, 1, rowCount );
		setHighlighted( next === -1 ? 0 : next );
	}, [ rows, rowCount, highlighted ] );

	/**
	 * Commits the option (or the custom-entry row) at `index`: updates the
	 * input text, calls `onChange` with the resulting value and whether it
	 * was a custom entry, and closes the dropdown.
	 */
	const selectIndex = ( index: number ) => {
		if ( index === rows.length && showCustomRow ) {
			onChange( query.trim(), true );
			setQuery( query.trim() );
		} else {
			const row = rows[ index ];
			// A heading is a label, not a choice - clicking or Entering one does nothing
			// and leaves the dropdown open rather than committing a section name.
			if ( ! row || row.kind !== 'option' ) {
				return;
			}
			onChange( row.value, false );
			setQuery( row.value );
		}
		setOpen( false );
	};

	/**
	 * Keyboard handler for the input: ArrowDown/ArrowUp open the dropdown
	 * if closed, or move the highlighted row while open (clamped to the
	 * row count); Enter commits the highlighted row via `selectIndex`;
	 * Escape closes the dropdown without committing anything.
	 */
	const onKeyDown = ( e: KeyboardEvent< HTMLInputElement > ) => {
		if ( ! open && ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) ) {
			setOpen( true );
			return;
		}
		if ( ! open ) {
			return;
		}

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			// Steps past a heading rather than onto it; -1 means there is nothing further
			// in that direction, so the highlight stays where it is.
			setHighlighted( ( i ) => {
				const next = nextSelectableRow( rows, i + 1, 1, rowCount );
				return next === -1 ? i : next;
			} );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			setHighlighted( ( i ) => {
				const prev = nextSelectableRow( rows, i - 1, -1, rowCount );
				return prev === -1 ? i : prev;
			} );
		} else if ( e.key === 'Enter' ) {
			e.preventDefault();
			selectIndex( highlighted );
		} else if ( e.key === 'Escape' ) {
			setOpen( false );
		}
	};

	// Virtualization measures one row height and multiplies, so every row - heading
	// included - is pinned to that same height in the style below. A heading is one line
	// of text like an option is; matching them keeps the scroll-track arithmetic exact
	// instead of drifting by a few pixels per section over 865 Fera gifts.
	const virtualized = rows.length > VIRTUALIZE_THRESHOLD;
	const listHeight = rowHeight * VISIBLE_ROWS;
	const firstVisible = virtualized
		? Math.max( 0, Math.floor( scrollTop / rowHeight ) - 2 )
		: 0;
	const lastVisible = virtualized
		? Math.min( rows.length, firstVisible + VISIBLE_ROWS + 4 )
		: rows.length;
	const visibleRows = virtualized
		? rows.slice( firstVisible, lastVisible )
		: rows;

	const dropdown = open && rowCount > 0 && listRect && (
		<ul
			className="be-searchable-select__list be-searchable-select__list--portaled"
			ref={ listRef }
			id={ listboxId }
			role="listbox"
			style={ {
				position: 'fixed',
				top: listRect.openUpward ? undefined : listRect.top,
				bottom: listRect.openUpward
					? window.innerHeight - listRect.top
					: undefined,
				left: listRect.left,
				width: listRect.width,
				...( virtualized
					? { height: listHeight, overflowY: 'auto' }
					: {} ),
			} }
			onScroll={ ( e ) =>
				setScrollTop( ( e.target as HTMLUListElement ).scrollTop )
			}
		>
			{ virtualized && (
				<li style={ { height: firstVisible * rowHeight } } />
			) }
			{ ( () => {
				// The height probe has to land on a real option, not on whatever happens
				// to be first in the visible slice - a grouped list opens on a heading,
				// and attaching the ref there left `rowHeight` pinned to its 28px fallback
				// while real options measured 44px on a phone. A 36% error in the scroll
				// track over 510 Werewolf gifts.
				let probed = false;
				return visibleRows.map( ( row, i ) => {
					const index = virtualized ? firstVisible + i : i;
					if ( row.kind === 'heading' ) {
						return (
							<li
								key={ `heading-${ index }` }
								// `presentation`, not `group` or `option`: this row is a label
								// inside a listbox, never something a screen reader should
								// announce as selectable.
								role="presentation"
								className="be-searchable-select__group"
								style={
									virtualized
										? { height: rowHeight }
										: undefined
								}
							>
								{ row.label }
							</li>
						);
					}
					const isProbe = ! probed;
					probed = true;
					return (
						<li
							key={ `option-${ index }` }
							id={ optionId( index ) }
							ref={ isProbe ? measureFirstOption : undefined }
							role="option"
							aria-selected={ index === highlighted }
							className={
								'be-searchable-select__option' +
								( index === highlighted
									? ' be-searchable-select__option--highlighted'
									: '' )
							}
							onMouseDown={ () => selectIndex( index ) }
						>
							{ row.value }
						</li>
					);
				} );
			} )() }
			{ virtualized && (
				<li
					style={ {
						height: ( rows.length - lastVisible ) * rowHeight,
					} }
				/>
			) }
			{ showCustomRow && (
				<li
					id={ optionId( rows.length ) }
					role="option"
					aria-selected={ rows.length === highlighted }
					className={
						'be-searchable-select__option be-searchable-select__option--custom' +
						( rows.length === highlighted
							? ' be-searchable-select__option--highlighted'
							: '' )
					}
					onMouseDown={ () => selectIndex( rows.length ) }
				>
					{ sprintf(
						/* translators: %1$s: the free-text value typed, offered as a custom option */
						__( 'Use "%1$s" (custom)', 'beyond-elysium' ),
						query.trim()
					) }
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
				aria-activedescendant={
					open && rowCount > 0 ? optionId( highlighted ) : undefined
				}
				aria-label={ ariaLabel ?? placeholder }
				autoComplete="off"
				onFocus={ () => setOpen( true ) }
				onBlur={ () => {
					// Commits a typed value on blur even when no dropdown row was explicitly picked.
					const commit = resolveBlurCommit(
						query,
						allOptions,
						allowCustom ?? false
					);
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
