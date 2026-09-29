/**
 * The words a flagged book correction is shown in: where the change sits, and what the book said, says now, and what
 * the chronicle has.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { CatalogCorrection, CorrectionPathStep } from '../types';

/**
 * Keys that only hold the entries or maps beneath them, left out of where a change sits.
 */
const CONTAINERS = new Set( [
	'_meta',
	'items',
	'powers',
	'pools',
	'fields',
	'levels',
	'overflow',
	'elder',
	'sections',
	'stack_definition',
	'steps',
] );

/**
 * Keys that name a rank-keyed map, shown with the rank as one step.
 */
const RANKED: Record< string, () => string > = {
	/* translators: %s: a power rank, such as Elder */
	costs: () => __( '%s cost', 'beyond-elysium' ),
	/* translators: %s: a power rank, such as Elder */
	out_of_type: () => __( '%s out-of-type modifier', 'beyond-elysium' ),
	/* translators: %s: a power rank, such as Elder */
	in_type: () => __( '%s in-type modifier', 'beyond-elysium' ),
	/* translators: %s: a power rank, such as Basic */
	ladder: () => __( '%s rungs on the ladder', 'beyond-elysium' ),
};

/**
 * The words for a key that is a field of an entry or a setting.
 */
const FIELDS: Record< string, () => string > = {
	cost: () => __( 'Cost', 'beyond-elysium' ),
	note: () => __( 'Note', 'beyond-elysium' ),
	approval: () => __( 'Approval', 'beyond-elysium' ),
	reason: () => __( 'Approval reason', 'beyond-elysium' ),
	description: () => __( 'Description', 'beyond-elysium' ),
	hidden: () => __( 'Hidden', 'beyond-elysium' ),
	label: () => __( 'Label', 'beyond-elysium' ),
	title: () => __( 'Title', 'beyond-elysium' ),
	display_order: () => __( 'Order', 'beyond-elysium' ),
	order: () => __( 'Order', 'beyond-elysium' ),
	column: () => __( 'Column', 'beyond-elysium' ),
	width: () => __( 'Width', 'beyond-elysium' ),
	display: () => __( 'Display', 'beyond-elysium' ),
	collapsed: () => __( 'Collapsed', 'beyond-elysium' ),
	required: () => __( 'Required', 'beyond-elysium' ),
	options: () => __( 'Options', 'beyond-elysium' ),
	tier: () => __( 'Tier', 'beyond-elysium' ),
	group: () => __( 'Group', 'beyond-elysium' ),
	subgroup: () => __( 'Subgroup', 'beyond-elysium' ),
	default_start: () => __( 'Starting value', 'beyond-elysium' ),
	max: () => __( 'Maximum', 'beyond-elysium' ),
	cost_per_dot: () => __( 'Cost per dot', 'beyond-elysium' ),
	allow_multiples: () => __( 'Allow multiples', 'beyond-elysium' ),
	approval_by_value: () => __( 'Approval by value', 'beyond-elysium' ),
	approval_by_option: () => __( 'Approval by option', 'beyond-elysium' ),
	pick_restrictions: () => __( 'Pick restrictions', 'beyond-elysium' ),
	traditions: () => __( 'Traditions', 'beyond-elysium' ),
	specializations: () => __( 'Specializations', 'beyond-elysium' ),
	ranks: () => __( 'Ranks', 'beyond-elysium' ),
	creation_rules: () => __( 'Creation rules', 'beyond-elysium' ),
	in_type: () => __( 'In-type rule', 'beyond-elysium' ),
};

/**
 * A key or rank shown as words: underscores as spaces, the first letter capitalised.
 */
function words( key: string ): string {
	const spaced = key.replace( /_/g, ' ' );
	return spaced.charAt( 0 ).toUpperCase() + spaced.slice( 1 );
}

function isEntry( step: CorrectionPathStep ): step is [ string ] {
	return Array.isArray( step );
}

/**
 * Where a flagged change sits, in plain words: the block, creature type or template, then each entry and field on the
 * way, such as "Disciplines › Animalism › Stampede › Cost".
 */
export function correctionPlace( correction: CatalogCorrection ): string {
	const parts: string[] = [ correction.target_name ];
	const path = correction.path;
	for ( let i = 0; i < path.length; i++ ) {
		const step = path[ i ];
		if ( isEntry( step ) ) {
			parts.push( correction.labels?.[ i ] ?? String( step[ 0 ] ) );
			continue;
		}
		const key = String( step );
		const next = path[ i + 1 ];
		if ( RANKED[ key ] && next !== undefined && ! isEntry( next ) ) {
			parts.push(
				RANKED[ key ]().replace( '%s', words( String( next ) ) )
			);
			i++;
			continue;
		}
		if ( CONTAINERS.has( key ) ) {
			continue;
		}
		if (
			i > 0 &&
			! isEntry( path[ i - 1 ] ) &&
			String( path[ i - 1 ] ) === 'elder'
		) {
			parts.push( words( key ) );
			continue;
		}
		parts.push( FIELDS[ key ] ? FIELDS[ key ]() : words( key ) );
	}
	return parts.join( ' › ' );
}

/**
 * A value in words, or null when it is too complex to name in a sentence.
 */
export function displayValue( value: unknown, set: boolean ): string | null {
	if ( ! set || value === null || value === undefined ) {
		return __( 'nothing', 'beyond-elysium' );
	}
	if ( typeof value === 'number' ) {
		return String( value );
	}
	if ( typeof value === 'string' ) {
		return value === '' ? __( 'nothing', 'beyond-elysium' ) : value;
	}
	if ( typeof value === 'boolean' ) {
		return value
			? __( 'yes', 'beyond-elysium' )
			: __( 'no', 'beyond-elysium' );
	}
	if (
		Array.isArray( value ) &&
		value.every(
			( item ) => typeof item === 'string' || typeof item === 'number'
		)
	) {
		return value.length === 0
			? __( 'nothing', 'beyond-elysium' )
			: value.join( ', ' );
	}
	return null;
}

/**
 * The name of the entry a correction's path ends on, when it ends on one.
 */
function entryName( correction: CatalogCorrection ): string | null {
	const at = correction.path.length - 1;
	const last = correction.path[ at ];
	if ( last === undefined || ! isEntry( last ) ) {
		return null;
	}
	return correction.labels?.[ at ] ?? String( last[ 0 ] );
}

/**
 * What a flagged change says: what the book said when the chronicle made it, what it says now, and the chronicle's own.
 */
export function correctionMessage( correction: CatalogCorrection ): string {
	const name = entryName( correction );

	if ( correction.removed ) {
		const cost = ( correction.changes ?? [] ).find(
			( change ) =>
				change.yours_set &&
				String( change.path[ change.path.length - 1 ] ) === 'cost'
		);
		const costWords = cost ? displayValue( cost.yours, true ) : null;
		return costWords !== null
			? sprintf(
					/* translators: 1: an entry the book no longer has, such as Stampede, 2: its cost in this chronicle */
					__(
						'The book removed %1$s; yours costs %2$s.',
						'beyond-elysium'
					),
					name ?? '',
					costWords
				)
			: sprintf(
					/* translators: %s: an entry the book no longer has, such as Stampede */
					__(
						'The book removed %s; yours keeps your changes.',
						'beyond-elysium'
					),
					name ?? ''
				);
	}

	if ( ! correction.yours_set ) {
		return name !== null
			? sprintf(
					/* translators: %s: an entry the chronicle removed, such as Iron Will */
					__(
						'The book changed %s, which you removed.',
						'beyond-elysium'
					),
					name
				)
			: __(
					'The book changed this, which you cleared.',
					'beyond-elysium'
				);
	}

	if ( correction.was_set !== true && name !== null ) {
		return sprintf(
			/* translators: %s: an entry the chronicle added and the book now has too, such as Contacts */
			__(
				'The book now has its own %s. Yours stays until you choose.',
				'beyond-elysium'
			),
			name
		);
	}

	const was = displayValue( correction.was, correction.was_set === true );
	const now = displayValue( correction.now, correction.now_set );
	const yours = displayValue( correction.yours, correction.yours_set );
	if ( was === null || now === null || yours === null ) {
		return __( 'The book changed this since you did.', 'beyond-elysium' );
	}

	return sprintf(
		/* translators: 1: the book's value when the chronicle made its change, 2: the book's value now, 3: the chronicle's value */
		__(
			'The book said %1$s, now says %2$s. Yours: %3$s.',
			'beyond-elysium'
		),
		was,
		now,
		yours
	);
}
