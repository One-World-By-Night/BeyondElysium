/**
 * StatisticsView renders the Statistics tab of the query tool: choose a field
 * and a statistic type, run it, and display the result as a bar chart with
 * expandable buckets. Used by QueryTool alongside the Search and Saved Queries
 * tabs.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import type { QueryField, StatisticsResult, StatisticType } from '../../types/query';
import './StatisticsView.css';

export interface StatisticsViewProps {
	fields: QueryField[];
	onRun: ( key: string, statType: StatisticType, okZero: boolean, trait?: string ) => void;
	result: StatisticsResult | null;
	loading: boolean;
}

const STAT_TYPES: { value: StatisticType; label: string }[] = [
	{ value: 'distribution', label: __( 'Distribution', 'beyond-elysium' ) },
	{ value: 'distinct_distribution', label: __( 'Distinct Trait Distribution', 'beyond-elysium' ) },
	{ value: 'specific_distribution', label: __( 'Specific Trait Distribution', 'beyond-elysium' ) },
	{ value: 'maxima', label: __( 'Maxima', 'beyond-elysium' ) },
	{ value: 'sums', label: __( 'Sums', 'beyond-elysium' ) },
];

/**
 * Renders the statistics tab: pick a field and a statistic type (distribution,
 * maxima, sums, etc.), run it, and show the result as a bar chart scaled to the
 * largest bucket. Each bucket expands to list the character names behind it.
 */
export function StatisticsView( { fields, onRun, result, loading }: StatisticsViewProps ) {
	const [ key, setKey ] = useState( '' );
	const [ statType, setStatType ] = useState<StatisticType>( 'distribution' );
	const [ okZero, setOkZero ] = useState( true );
	const [ trait, setTrait ] = useState( '' );
	const [ expanded, setExpanded ] = useState<string | null>( null );

	function run( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! key ) {
			return;
		}
		onRun( key, statType, okZero, statType === 'specific_distribution' ? trait : undefined );
	}

	return (
		<div className="be-statistics-view">
			<form className="be-statistics-view__form" onSubmit={ run }>
				<select value={ key } onChange={ ( e ) => setKey( e.target.value ) } aria-label={ __( 'Character field to generate statistics for', 'beyond-elysium' ) }>
					<option value="">{ __( 'Select a field…', 'beyond-elysium' ) }</option>
					{ fields.map( ( f ) => (
						<option key={ f.key } value={ f.key }>
							{ f.title }
						</option>
					) ) }
				</select>

				<select value={ statType } onChange={ ( e ) => setStatType( e.target.value as StatisticType ) } aria-label={ __( 'Statistic type to run', 'beyond-elysium' ) }>
					{ STAT_TYPES.map( ( t ) => (
						<option key={ t.value } value={ t.value }>
							{ t.label }
						</option>
					) ) }
				</select>

				{ statType === 'specific_distribution' && (
					<input type="text" placeholder={ __( 'Trait name…', 'beyond-elysium' ) } value={ trait } onChange={ ( e ) => setTrait( e.target.value ) } />
				) }

				{ ( statType === 'distribution' || statType === 'specific_distribution' ) && (
					<label>
						<input type="checkbox" checked={ okZero } onChange={ ( e ) => setOkZero( e.target.checked ) } />
						{ __( 'Include zero/none', 'beyond-elysium' ) }
					</label>
				) }

				<button type="submit" disabled={ loading || ! key }>
					{ __( 'Run', 'beyond-elysium' ) }
				</button>
			</form>

			{ result && (
				<div className="be-statistics-view__result">
					<p>
						{ sprintf(
							/* translators: 1: total count, 2: size of the largest bucket */
							__( 'Total: %1$d — Largest bucket: %2$d', 'beyond-elysium' ),
							result.total,
							result.maximum
						) }
					</p>
					<ul className="be-statistics-view__bars">
						{ Object.entries( result.buckets )
							.sort( ( [ , a ], [ , b ] ) => b - a )
							.map( ( [ bucket, value ] ) => (
								<li key={ bucket }>
									<button
										type="button"
										className="be-statistics-view__bucket-toggle"
										onClick={ () => setExpanded( expanded === bucket ? null : bucket ) }
									>
										<span className="be-statistics-view__bucket-label">{ bucket }</span>
										<span className="be-statistics-view__bar-track">
											<span
												className="be-statistics-view__bar-fill"
												style={ { width: `${ result.maximum ? ( value / result.maximum ) * 100 : 0 }%` } }
											/>
										</span>
										<span className="be-statistics-view__bucket-value">{ value }</span>
									</button>
									{ expanded === bucket && (
										<ul className="be-statistics-view__bucket-names">
											{ ( result.match_sets[ bucket ] ?? [] ).map( ( name, i ) => (
												<li key={ i }>{ name }</li>
											) ) }
										</ul>
									) }
								</li>
							) ) }
					</ul>
				</div>
			) }
		</div>
	);
}

export default StatisticsView;
