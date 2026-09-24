/**
 * The itemised point audit: every held line on a character's sheet, priced or explicitly marked unpriced with a
 * reason.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type {
	PointAudit as PointAuditReport,
	PointAuditLine,
} from '../../types/character';
import CollapsiblePanel from '../shared/CollapsiblePanel';
import './PointAudit.css';

export interface PointAuditProps {
	characterId: number;
	gameSlug: string;
}

const UNPRICED_REASON_LABEL: Record< string, string > = {
	catalog_item_has_no_cost: __(
		'catalog item has no cost',
		'beyond-elysium'
	),
	name_not_in_catalog: __( 'name not in catalog', 'beyond-elysium' ),
	family_not_in_catalog: __( 'family not in catalog', 'beyond-elysium' ),
	level_has_no_cost: __( 'level has no cost', 'beyond-elysium' ),
	level_above_ceiling_no_pick_rank: __(
		'held above the ladder, no priced rank above it yet',
		'beyond-elysium'
	),
	custom_no_catalog_entry: __( 'custom, no catalog entry', 'beyond-elysium' ),
	identity_field_no_catalog_cost: __(
		'identity field, no catalog cost',
		'beyond-elysium'
	),
	resource_pool_no_pricing_rule: __(
		'resource pool has no pricing rule yet',
		'beyond-elysium'
	),
	held_block_not_in_catalog: __(
		'held block not in catalog',
		'beyond-elysium'
	),
};

function groupBySection(
	lines: PointAuditLine[]
): Array< [ string, PointAuditLine[] ] > {
	const order: string[] = [];
	const groups: Record< string, PointAuditLine[] > = {};
	for ( const line of lines ) {
		if ( ! groups[ line.section_label ] ) {
			groups[ line.section_label ] = [];
			order.push( line.section_label );
		}
		groups[ line.section_label ].push( line );
	}
	return order.map( ( label ) => [ label, groups[ label ] ] );
}

/**
 * Renders one line: a priced line shows its XP and basis.
 */
function AuditLine( { line }: { line: PointAuditLine } ) {
	const label = line.label;

	if ( line.xp === null ) {
		return (
			<li className="be-point-audit__line be-point-audit__line--unpriced">
				<span className="be-point-audit__line-label">{ label }</span>
				<span className="be-point-audit__line-reason">
					{ UNPRICED_REASON_LABEL[ line.unpriced_reason ?? '' ] ??
						line.unpriced_reason }
				</span>
				{ line.undeclared_by_stack && (
					<span className="be-point-audit__line-flag">
						{ __( 'undeclared by stack', 'beyond-elysium' ) }
					</span>
				) }
			</li>
		);
	}

	const sign = line.direction === 'earned' ? '−' : '';
	return (
		<li className="be-point-audit__line be-point-audit__line--priced">
			<span className="be-point-audit__line-label">{ label }</span>
			<span className="be-point-audit__line-xp">
				{ sign }
				{ Math.abs( line.xp ) } { __( 'XP', 'beyond-elysium' ) }
			</span>
			{ line.modifier ? (
				<span className="be-point-audit__line-modifier">
					{ sprintf(
						/* translators: %d: the out-of-type surcharge added to this line's cost */
						__( '(+%d out-of-type)', 'beyond-elysium' ),
						line.modifier
					) }
				</span>
			) : null }
		</li>
	);
}

/**
 * Renders the collapsible point-audit panel for one character.
 */
export function PointAudit( { characterId, gameSlug }: PointAuditProps ) {
	const [ report, setReport ] = useState< PointAuditReport | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setLoading( true );
		setError( null );
		api.characters( gameSlug )
			.pointAudit( characterId )
			.then( ( result ) => {
				setReport( result );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load the point audit.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}, [ characterId, gameSlug ] );

	if ( loading ) {
		return (
			<p className="be-point-audit__status">
				{ __( 'Loading…', 'beyond-elysium' ) }
			</p>
		);
	}
	if ( error || ! report ) {
		return (
			<p className="be-point-audit__status">
				{ error ?? __( 'No report available.', 'beyond-elysium' ) }
			</p>
		);
	}

	return (
		<div className="be-point-audit">
			<div className="be-point-audit__summary">
				<div className="be-point-audit__totals">
					<span>
						{ __( 'Spent:', 'beyond-elysium' ) }{ ' ' }
						<strong>{ report.spent_total }</strong>
					</span>
					<span>
						{ __( 'Earned:', 'beyond-elysium' ) }{ ' ' }
						<strong>{ report.earned_total }</strong>
					</span>
					<span>
						{ __( 'Net:', 'beyond-elysium' ) }{ ' ' }
						<strong>{ report.net_total }</strong>
					</span>
					<span>
						{ __( 'XP of record:', 'beyond-elysium' ) }{ ' ' }
						<strong>{ report.xp_spent_of_record }</strong>
					</span>
					<span>
						{ __( 'Variance:', 'beyond-elysium' ) }{ ' ' }
						<strong>{ report.variance }</strong>
					</span>
				</div>
				<div className="be-point-audit__coverage">
					{ sprintf(
						/* translators: 1: number of lines with a real XP price, 2: total number of held lines */
						__( 'Priced %1$d of %2$d lines.', 'beyond-elysium' ),
						report.coverage.priced_lines,
						report.coverage.priced_lines +
							report.coverage.unpriced_lines
					) }
				</div>
				<p className="be-point-audit__caveat">{ report.caveat }</p>
			</div>

			{ groupBySection( report.lines ).map(
				( [ sectionLabel, lines ] ) => (
					<CollapsiblePanel
						id={ `point-audit:${ sectionLabel }` }
						className="be-point-audit__section"
						key={ sectionLabel }
						heading={
							<h5 className="be-point-audit__section-title">
								{ sprintf(
									/* translators: 1: sheet section name, 2: number of audited lines in it */
									__( '%1$s (%2$d)', 'beyond-elysium' ),
									sectionLabel,
									lines.length
								) }
							</h5>
						}
					>
						<ul className="be-point-audit__lines">
							{ lines.map( ( line, i ) => (
								<AuditLine
									line={ line }
									key={ `${ line.block_slug }-${ i }` }
								/>
							) ) }
						</ul>
					</CollapsiblePanel>
				)
			) }
		</div>
	);
}

export default PointAudit;
