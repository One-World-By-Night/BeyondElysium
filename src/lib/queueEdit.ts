/**
 * The Approval Queue's editor for a waiting change: how each editable field is labelled and read from a change, which
 * price box a change gets, and what an edit request carries.
 */
import { __, sprintf } from '@wordpress/i18n';
import type {
	CatalogOffer,
	CatalogTick,
	ChangeEditRequest,
	PriceBasis,
	PriceScope,
	QueueChange,
} from '../types/character';
import { parsePrice } from './queuePrice';

/**
 * The field that names the section an added entry is filed in; the editor draws it as a list, not a text box.
 */
export const SECTION_FIELD = 'block_slug';

/**
 * How the editor draws one editable field.
 */
export interface FieldDef {
	key: string;
	label: string;
	kind: 'text' | 'number';
}

const NUMBER_FIELDS = [ 'count', 'level', 'permanent', 'amount' ];

/**
 * The label for an editable field.
 */
export function fieldLabel( key: string ): string {
	switch ( key ) {
		case 'name':
			return __( 'Name', 'beyond-elysium' );
		case 'count':
			return __( 'Dots', 'beyond-elysium' );
		case 'specialization':
			return __( 'Specialization', 'beyond-elysium' );
		case 'note':
			return __( 'Note', 'beyond-elysium' );
		case 'level':
			return __( 'Level', 'beyond-elysium' );
		case 'power_name':
			return __( 'Power', 'beyond-elysium' );
		case 'permanent':
			return __( 'Rating', 'beyond-elysium' );
		case 'value':
			return __( 'New value', 'beyond-elysium' );
		case 'amount':
			return __( 'Amount', 'beyond-elysium' );
		case 'reason':
			return __( 'Reason', 'beyond-elysium' );
		default:
			return key;
	}
}

/**
 * The text boxes the editor shows for a change, in the order the server lists its editable fields. The section list is
 * not one of them.
 */
export function inputFields( change: QueueChange ): FieldDef[] {
	return ( change.editable_fields ?? [] )
		.filter( ( key ) => key !== SECTION_FIELD )
		.map( ( key ) => ( {
			key,
			label: fieldLabel( key ),
			kind: NUMBER_FIELDS.includes( key ) ? 'number' : 'text',
		} ) );
}

/**
 * Whether an added entry on this change can be filed in another section.
 */
export function canMove( change: QueueChange ): boolean {
	return ( change.editable_fields ?? [] ).includes( SECTION_FIELD );
}

/**
 * The section a change is on now.
 */
export function currentSection( change: QueueChange ): string {
	const data = change.change_data as Record< string, unknown >;
	return typeof data.block_slug === 'string' ? data.block_slug : '';
}

function asText( value: unknown ): string {
	if ( value === null || value === undefined ) {
		return '';
	}
	return typeof value === 'object' ? '' : String( value );
}

/**
 * A change's current value for an editable field, as its text box shows it.
 */
export function readField( change: QueueChange, key: string ): string {
	const data = change.change_data as Record< string, unknown >;
	const trait = ( data.trait ?? {} ) as Record< string, unknown >;

	switch ( key ) {
		case 'name':
		case 'count':
		case 'specialization':
		case 'note':
		case 'level':
		case 'power_name':
			return asText( trait[ key ] );
		case 'permanent': {
			const values = ( data.values ?? {} ) as Record< string, unknown >;
			const first = values[ Object.keys( values )[ 0 ] ?? '' ];
			return asText(
				first !== null && typeof first === 'object'
					? ( first as Record< string, unknown > ).permanent
					: first
			);
		}
		case 'value': {
			const fields = ( data.fields ?? {} ) as Record< string, unknown >;
			return asText( fields[ Object.keys( fields )[ 0 ] ?? '' ] );
		}
		case 'amount':
		case 'reason':
			return asText( data[ key ] );
		default:
			return '';
	}
}

/**
 * Which price box a change gets: none, the per-dot or per-pick box for an entry the book has no price for, or the
 * box that overrides a price the book already has.
 */
export function priceBoxKind(
	basis: PriceBasis | null | undefined
): 'none' | 'homebrew' | 'book' {
	if ( ! basis ) {
		return 'none';
	}
	return basis.homebrew ? 'homebrew' : 'book';
}

/**
 * What the price box is for, as the server compares it.
 */
export function priceScope( basis: PriceBasis ): PriceScope {
	return basis.homebrew ? basis.per : 'change';
}

/**
 * The price the box starts with: the one a Storyteller set, or empty.
 */
export function priceStart( basis: PriceBasis | null | undefined ): string {
	return basis?.current === null || basis?.current === undefined
		? ''
		: String( basis.current );
}

/**
 * The hint inside a price box that overrides a price the book already has.
 */
export function usualPriceHint( basis: PriceBasis ): string {
	return sprintf(
		/* translators: %d: the XP the book charges for this change */
		__( 'Usual price: %d XP', 'beyond-elysium' ),
		Math.abs( basis.book_xp ?? 0 )
	);
}

/**
 * Whether the price text is fine to save: blank, or a whole number from 0 to 500.
 */
export function priceTextValid( text: string ): boolean {
	return text.trim() === '' || parsePrice( text ) !== null;
}

/**
 * What a Storyteller has typed into the editor.
 */
export interface EditDraft {
	/**
	 * Text for each editable field, section included.
	 */
	fields: Record< string, string >;
	price: string;
	note: string;
	catalog: CatalogDraft;
}

/**
 * The box "Also add to this chronicle's catalog" as the editor holds it.
 */
export interface CatalogDraft {
	on: boolean;
	/**
	 * The group an item is filed under; empty for none.
	 */
	group: string;
	/**
	 * The rank a pick sits at; empty until one is chosen.
	 */
	rank: string;
}

/**
 * The editor's starting point for a change: its own values, the price already set, and the stored note.
 */
export function draftFor( change: QueueChange ): EditDraft {
	const fields: Record< string, string > = {};
	for ( const def of inputFields( change ) ) {
		fields[ def.key ] = readField( change, def.key );
	}
	if ( canMove( change ) ) {
		fields[ SECTION_FIELD ] = currentSection( change );
	}
	const offer = change.catalog_offer ?? null;
	const stored = change.catalog ?? null;
	return {
		fields,
		price: priceStart( change.price_basis ),
		note: change.review_notes ?? '',
		catalog: {
			on: stored !== null,
			group:
				stored !== null
					? ( stored.group ?? '' )
					: offer?.kind === 'item'
						? ( offer.suggested_group ?? '' )
						: '',
			rank: stored?.rank ?? '',
		},
	};
}

/**
 * What the box asks for: the group of an item, the rank of a pick, and nothing for a family.
 */
export function catalogTickFor(
	offer: CatalogOffer,
	draft: CatalogDraft
): CatalogTick {
	if ( offer.kind === 'item' ) {
		return draft.group !== '' ? { group: draft.group } : {};
	}
	return offer.kind === 'pick' ? { rank: draft.rank } : {};
}

/**
 * Whether the box can be ticked: it needs a price in the price box, and the entry must not be moving, since both a
 * price and a tick wait for a move to be saved.
 */
export function catalogBoxEnabled(
	change: QueueChange,
	draft: EditDraft
): boolean {
	return (
		!! change.catalog_offer &&
		draft.price.trim() !== '' &&
		parsePrice( draft.price ) !== null &&
		! isMove( change, draft )
	);
}

/**
 * The line under a ticked box saying what will be added.
 */
export function catalogLine( offer: CatalogOffer, draft: EditDraft ): string {
	const name = draft.fields.name || '…';
	if ( offer.kind === 'family' ) {
		return sprintf(
			/* translators: %s: the name of a new family of powers, such as a Discipline */
			__(
				"Adds %s to this chronicle's list with all its levels, at this section's usual costs.",
				'beyond-elysium'
			),
			name
		);
	}
	if ( offer.kind === 'pick' ) {
		return sprintf(
			/* translators: 1: the name of a power, such as a Gift, 2: the name of the family it belongs to */
			__(
				"Adds %1$s to %2$s at the section's usual cost for its rank.",
				'beyond-elysium'
			),
			draft.fields.power_name || '…',
			name
		);
	}
	return __(
		'Other characters in this chronicle can then pick it at this price.',
		'beyond-elysium'
	);
}

/**
 * Whether two ticks say the same thing.
 */
function sameTick( a: CatalogTick | null, b: CatalogTick | null ): boolean {
	if ( a === null || b === null ) {
		return a === b;
	}
	return (
		( a.group ?? '' ) === ( b.group ?? '' ) &&
		( a.rank ?? '' ) === ( b.rank ?? '' )
	);
}

/**
 * Whether the draft moves the entry to another section.
 */
export function isMove( change: QueueChange, draft: EditDraft ): boolean {
	return (
		canMove( change ) &&
		draft.fields[ SECTION_FIELD ] !== undefined &&
		draft.fields[ SECTION_FIELD ] !== currentSection( change )
	);
}

/**
 * A field's text as the request sends it: a whole number for a number box that holds one, otherwise the text itself so
 * the server can say what is wrong with it.
 */
function sendable( key: string, text: string ): string | number {
	return NUMBER_FIELDS.includes( key ) && /^-?\d+$/.test( text.trim() )
		? Number( text.trim() )
		: text;
}

/**
 * The request an edit sends: only the fields that changed, the price in the box (none when the entry is moving, since a
 * move is saved on its own), and the note when it was touched.
 */
export function buildEditRequest(
	change: QueueChange,
	draft: EditDraft
): ChangeEditRequest {
	const request: ChangeEditRequest = { review_token: change.review_token };

	const fields: Record< string, string | number > = {};
	for ( const def of inputFields( change ) ) {
		const text = draft.fields[ def.key ] ?? '';
		if ( text !== readField( change, def.key ) ) {
			fields[ def.key ] = sendable( def.key, text );
		}
	}
	const moving = isMove( change, draft );
	if ( moving ) {
		fields[ SECTION_FIELD ] = draft.fields[ SECTION_FIELD ];
	}
	if ( Object.keys( fields ).length > 0 ) {
		request.fields = fields;
	}

	const basis = change.price_basis;
	if ( basis && ! moving ) {
		const price = parsePrice( draft.price );
		request.price = price;
		if ( price !== null ) {
			request.price_per = priceScope( basis );
		}
	}

	const offer = change.catalog_offer ?? null;
	if ( offer && ! moving ) {
		const wanted =
			draft.catalog.on && catalogBoxEnabled( change, draft )
				? catalogTickFor( offer, draft.catalog )
				: null;
		if ( ! sameTick( wanted, change.catalog ?? null ) ) {
			request.catalog = wanted;
		}
	}

	if ( draft.note !== ( change.review_notes ?? '' ) ) {
		request.review_notes = draft.note;
	}
	return request;
}

/**
 * A rank as a list shows it: its stored word with a capital letter.
 */
export function rankLabel( rank: string ): string {
	return rank === '' ? rank : rank[ 0 ].toUpperCase() + rank.slice( 1 );
}
