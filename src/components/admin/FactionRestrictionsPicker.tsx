/**
 * The finer-grained sibling of EnabledStacksPicker: within an already-enabled
 * creature stack, restrict which values a real catalog-backed identity_field
 * (Vampire Clan/Sect, Werewolf Tribe, and similar) offers - "Vampire yes, but
 * no Sabbat." Engine-pure like its sibling: this reads whichever real
 * select/multiselect identity fields each enabled stack's own resolved
 * catalog actually declares, rather than a hardcoded field-name list, so a
 * future creature type or a renamed field needs no change here.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { CreatureStack, IdentityField } from '../../types';
import './FactionRestrictionsPicker.css';

export interface FactionRestrictionsPickerProps {
	gameSlug: string;
	/** null means "every real creature stack" - `enabled_stacks`' own absent-means-all convention, never "none". */
	enabledStacks: string[] | null;
	restrictions: Record< string, Record< string, string[] > >;
	onSave: ( stackSlug: string, fieldName: string, allowed: string[] ) => void;
	savingKey?: string | null;
}

interface RestrictableField {
	stackSlug: string;
	stackName: string;
	fieldName: string;
	options: string[];
}

/** A field is restrictable when it's a real catalog pick, not free text or an empty/dynamic list. */
function isRestrictable( field: IdentityField ): boolean {
	return (
		( field.field_type === 'select' ||
			field.field_type === 'multiselect' ) &&
		( field.options?.length ?? 0 ) > 0
	);
}

export function FactionRestrictionsPicker( {
	gameSlug,
	enabledStacks,
	restrictions,
	onSave,
	savingKey,
}: FactionRestrictionsPickerProps ) {
	const [ fields, setFields ] = useState< RestrictableField[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ drafts, setDrafts ] = useState< Record< string, Set< string > > >(
		{}
	);
	// The effect below runs again when the stacks listed change, not each time the parent
	// passes a new array.
	const stacksKey =
		enabledStacks === null ? 'all' : enabledStacks.join( ',' );

	useEffect( () => {
		setLoading( true );

		function fieldsForStack(
			slug: string
		): Promise< RestrictableField[] > {
			return api.creatureStacks
				.resolve( slug, gameSlug )
				.then( ( resolved ) => {
					const stack = resolved.stack as CreatureStack;
					const found: RestrictableField[] = [];
					for ( const block of Object.values( resolved.blocks ) ) {
						if ( block.section_type !== 'identity_field' ) {
							continue;
						}
						for ( const field of (
							block.definition as { fields?: IdentityField[] }
						 ).fields ?? [] ) {
							if ( isRestrictable( field ) ) {
								found.push( {
									stackSlug: slug,
									stackName: stack.name,
									fieldName: field.name,
									options: field.options ?? [],
								} );
							}
						}
					}
					return found;
				} )
				.catch( () => [] as RestrictableField[] );
		}

		const stacksPromise: Promise< string[] > =
			enabledStacks === null
				? api.creatureStacks
						.list()
						.then( ( all ) => all.map( ( s ) => s.slug ) )
				: Promise.resolve( enabledStacks );

		stacksPromise
			.then( ( slugs ) => Promise.all( slugs.map( fieldsForStack ) ) )
			.then( ( results ) => {
				setFields( results.flat() );
				setLoading( false );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ stacksKey, gameSlug ] );

	useEffect( () => {
		const next: Record< string, Set< string > > = {};
		for ( const field of fields ) {
			const key = `${ field.stackSlug }:${ field.fieldName }`;
			const restricted =
				restrictions[ field.stackSlug ]?.[ field.fieldName ];
			next[ key ] = new Set(
				restricted && restricted.length > 0 ? restricted : field.options
			);
		}
		setDrafts( next );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ fields ] );

	function toggle( key: string, option: string, allOptions: string[] ) {
		setDrafts( ( prev ) => {
			const next = new Set( prev[ key ] ?? allOptions );
			if ( next.has( option ) ) {
				next.delete( option );
			} else {
				next.add( option );
			}
			return { ...prev, [ key ]: next };
		} );
	}

	if ( loading ) {
		return (
			<p>{ __( 'Loading restrictable fields…', 'beyond-elysium' ) }</p>
		);
	}
	if ( fields.length === 0 ) {
		return (
			<p className="be-faction-restrictions__empty">
				{ __(
					'None of the currently enabled creature types have a restrictable sub-faction field (a Clan, Sect, Tribe, or similar).',
					'beyond-elysium'
				) }
			</p>
		);
	}

	return (
		<div className="be-faction-restrictions">
			{ fields.map( ( field ) => {
				const key = `${ field.stackSlug }:${ field.fieldName }`;
				const checked = drafts[ key ] ?? new Set( field.options );
				const isSaving = savingKey === key;
				return (
					<div className="be-faction-restrictions__field" key={ key }>
						<h4>
							{ sprintf(
								/* translators: 1: creature stack name, 2: identity field name, e.g. "Vampire - Clan" */
								__( '%1$s — %2$s', 'beyond-elysium' ),
								field.stackName,
								field.fieldName
							) }
						</h4>
						<ul className="be-faction-restrictions__options">
							{ field.options.map( ( option ) => (
								<li key={ option }>
									<label>
										<input
											type="checkbox"
											checked={ checked.has( option ) }
											onChange={ () =>
												toggle(
													key,
													option,
													field.options
												)
											}
										/>
										{ option }
									</label>
								</li>
							) ) }
						</ul>
						{ checked.size === 0 && (
							<p className="be-faction-restrictions__warning">
								{ __(
									'At least one option must stay enabled - a real character creation could not otherwise pick a value here.',
									'beyond-elysium'
								) }
							</p>
						) }
						<button
							type="button"
							className="button button-secondary"
							disabled={ checked.size === 0 || isSaving }
							onClick={ () =>
								onSave(
									field.stackSlug,
									field.fieldName,
									Array.from( checked )
								)
							}
						>
							{ isSaving
								? __( 'Saving…', 'beyond-elysium' )
								: __( 'Save', 'beyond-elysium' ) }
						</button>
					</div>
				);
			} ) }
		</div>
	);
}

export default FactionRestrictionsPicker;
