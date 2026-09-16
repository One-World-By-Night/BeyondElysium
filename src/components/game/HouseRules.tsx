/**
 * Front-end display of the "House Rules" report - every catalog item, tiered
 * power level, or tiered power family carrying a `description` note
 * (Decision 094), grouped by the schema block it lives on. A live view of
 * the same data `Reports_Controller::get_pdf()` renders as a signed PDF;
 * this component fetches the plain JSON form instead
 * (`api.reports(gameSlug).document('house-rules')`) and renders it directly
 * on the page - meant to be dropped in via the Elementor widget or the
 * `[be_house_rules]` shortcode, not just viewed from the admin Reports page.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import HelpButton from '../shared/HelpButton';
import './HouseRules.css';

interface HouseRuleSections {
	reference?: string;
	description?: string;
	source?: string;
}

interface HouseRuleEntry {
	name: string;
	sections: HouseRuleSections;
}

interface HouseRuleGroup {
	block_name: string;
	entries: HouseRuleEntry[];
}

interface HouseRulesDocument {
	title: string;
	groups: HouseRuleGroup[];
}

export interface HouseRulesProps {
	gameSlug: string;
}

const SECTION_LABELS: Record< keyof HouseRuleSections, string > = {
	reference: __( 'Reference', 'beyond-elysium' ),
	description: __( 'Description', 'beyond-elysium' ),
	source: __( 'Source', 'beyond-elysium' ),
};

/**
 * Renders every set `description` section as raw HTML via
 * `dangerouslySetInnerHTML` - safe here because `Rich_Text_Sanitizer` has
 * already narrowed it server-side, at write time, the same trust boundary
 * `CharacterSheet.tsx` already relies on for biography/notes.
 */
function SectionBlock( { label, html }: { label: string; html: string } ) {
	return (
		<div className="be-house-rules__section">
			<span className="be-house-rules__section-label">{ label }</span>
			{ /* eslint-disable-next-line react/no-danger */ }
			<div
				className="be-house-rules__section-body"
				dangerouslySetInnerHTML={ { __html: html } }
			/>
		</div>
	);
}

export function HouseRules( { gameSlug }: HouseRulesProps ) {
	const [ data, setData ] = useState< HouseRulesDocument | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setLoading( true );
		api.reports( gameSlug )
			.document( 'house-rules' )
			.then( ( result ) => {
				setData( result as unknown as HouseRulesDocument );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load house rules.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}, [ gameSlug ] );

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( error || ! data ) {
		return (
			<div className="be-house-rules__error" role="alert">
				{ error ??
					__( 'Could not load house rules.', 'beyond-elysium' ) }
			</div>
		);
	}

	if ( data.groups.length === 0 ) {
		return (
			<p className="be-house-rules__empty">
				{ __(
					'No house rules are set on this catalog yet.',
					'beyond-elysium'
				) }
			</p>
		);
	}

	return (
		<div className="be-house-rules">
			<div className="be-help-heading">
				<h2>{ __( 'House Rules', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="house-rules" />
			</div>
			{ data.groups.map( ( group ) => (
				<div className="be-house-rules__group" key={ group.block_name }>
					<h3 className="be-house-rules__group-title">
						{ group.block_name }
					</h3>
					{ group.entries.map( ( entry ) => (
						<div
							className="be-house-rules__entry"
							key={ entry.name }
						>
							<h4 className="be-house-rules__entry-name">
								{ entry.name }
							</h4>
							{ (
								Object.keys( SECTION_LABELS ) as Array<
									keyof HouseRuleSections
								>
							 ).map( ( key ) =>
								entry.sections[ key ] ? (
									<SectionBlock
										key={ key }
										label={ SECTION_LABELS[ key ] }
										html={ entry.sections[ key ] as string }
									/>
								) : null
							) }
						</div>
					) ) }
				</div>
			) ) }
		</div>
	);
}

export default HouseRules;
