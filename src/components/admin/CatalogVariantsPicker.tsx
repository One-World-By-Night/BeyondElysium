/**
 * The book variants a chronicle chooses, under Chronicle Setup's Book variants row: for each base block that has them,
 * the variants that add to it and the one that may replace it, with the held entries a change would unmatch shown
 * before it is saved.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import api from '../../api/client';
import type { CatalogVariantBase, VariantUnmatched } from '../../types';
import {
	chooseReplacing,
	orderedChoice,
	sameChoice,
	toggleAdding,
} from '../../lib/variantChoice';
import { errorMessage } from '../../lib/errorMessage';

/**
 * Each base block's choice as the page holds it, from what the chronicle chose.
 */
function draftsFrom( bases: CatalogVariantBase[] ): Record< string, string[] > {
	const drafts: Record< string, string[] > = {};
	for ( const base of bases ) {
		drafts[ base.base ] = orderedChoice( base.variants, base.chosen );
	}
	return drafts;
}

export function CatalogVariantsPicker( {
	gameSlug,
	onChanged,
}: {
	gameSlug: string;
	onChanged: () => void;
} ) {
	const [ bases, setBases ] = useState< CatalogVariantBase[] | null >( null );
	const [ drafts, setDrafts ] = useState< Record< string, string[] > >( {} );
	const [ pending, setPending ] = useState< {
		base: string;
		ids: string[];
		unmatched: VariantUnmatched[];
	} | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const client = api.catalogVariants( gameSlug );

	useEffect( () => {
		client
			.list()
			.then( ( result ) => {
				setBases( result.bases );
				setDrafts( draftsFrom( result.bases ) );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug ] );

	/**
	 * Saves a base block's choice.
	 */
	async function commit( base: string, ids: string[] ) {
		setBusy( true );
		setError( null );
		try {
			const result = await client.choose( base, ids );
			setBases( result.bases );
			setDrafts( draftsFrom( result.bases ) );
			setPending( null );
			onChanged();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setBusy( false );
		}
	}

	/**
	 * Checks what a base block's choice would leave unmatched, then saves it or asks first.
	 */
	async function save( base: string ) {
		const ids = drafts[ base ] ?? [];
		setBusy( true );
		setError( null );
		try {
			const { unmatched } = await client.preview( base, ids );
			if ( unmatched.length > 0 ) {
				setPending( { base, ids, unmatched } );
				setBusy( false );
				return;
			}
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
			setBusy( false );
			return;
		}
		await commit( base, ids );
	}

	if ( bases === null ) {
		return error ? (
			<p role="alert">{ error }</p>
		) : (
			<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
		);
	}

	return (
		<div className="be-catalog-variants">
			{ error && (
				<p className="be-admin__error" role="alert">
					{ error }
				</p>
			) }
			{ bases.length === 0 && (
				<p>
					{ __(
						'No block in the book has variants.',
						'beyond-elysium'
					) }
				</p>
			) }
			{ bases.map( ( base ) => {
				const draft = drafts[ base.base ] ?? [];
				const replacing = base.variants.filter(
					( variant ) => variant.mode === 'replace'
				);
				const adding = base.variants.filter(
					( variant ) => variant.mode === 'add'
				);
				const chosenReplacing =
					replacing.find( ( variant ) =>
						draft.includes( variant.id )
					)?.id ?? '';
				return (
					<fieldset
						key={ base.base }
						className="be-catalog-variants__base"
						disabled={ busy }
					>
						<legend>{ base.base_name }</legend>
						{ replacing.length > 0 && (
							<div className="be-catalog-variants__replace">
								<label>
									<input
										type="radio"
										name={ `be-variant-replace-${ base.base }` }
										checked={ chosenReplacing === '' }
										onChange={ () =>
											setDrafts( {
												...drafts,
												[ base.base ]: chooseReplacing(
													base.variants,
													draft,
													''
												),
											} )
										}
									/>
									{ __( "The book's own", 'beyond-elysium' ) }
								</label>
								{ replacing.map( ( variant ) => (
									<label key={ variant.id }>
										<input
											type="radio"
											name={ `be-variant-replace-${ base.base }` }
											checked={
												chosenReplacing === variant.id
											}
											onChange={ () =>
												setDrafts( {
													...drafts,
													[ base.base ]:
														chooseReplacing(
															base.variants,
															draft,
															variant.id
														),
												} )
											}
										/>
										{ sprintf(
											/* translators: %s: a book variant that replaces a block, such as OWBN Arcanoi packet (2016) */
											__(
												'Replaced by %s',
												'beyond-elysium'
											),
											variant.label
										) }
									</label>
								) ) }
							</div>
						) }
						{ adding.map( ( variant ) => (
							<label key={ variant.id }>
								<input
									type="checkbox"
									checked={ draft.includes( variant.id ) }
									onChange={ () =>
										setDrafts( {
											...drafts,
											[ base.base ]: toggleAdding(
												base.variants,
												draft,
												variant.id
											),
										} )
									}
								/>
								{ sprintf(
									/* translators: %s: a book variant that adds to a block, such as Dark Ages printings */
									__( 'Add %s', 'beyond-elysium' ),
									variant.label
								) }
							</label>
						) ) }
						<p>
							<button
								type="button"
								className="button"
								disabled={ sameChoice( draft, base.chosen ) }
								onClick={ () => save( base.base ) }
							>
								{ __( 'Save', 'beyond-elysium' ) }
							</button>
						</p>
						{ pending?.base === base.base && (
							<div
								className="be-catalog-variants__warning"
								role="alert"
							>
								<p>
									{ sprintf(
										/* translators: %d: number of entries characters hold that would no longer match the catalog */
										_n(
											'%d held entry would no longer match the catalog. It stays on the sheet as it is:',
											'%d held entries would no longer match the catalog. They stay on the sheet as they are:',
											pending.unmatched.length,
											'beyond-elysium'
										),
										pending.unmatched.length
									) }
								</p>
								<ul>
									{ pending.unmatched.map( ( entry, i ) => (
										<li key={ i }>
											{ entry.power_name
												? sprintf(
														/* translators: 1: a character, 2: a power, 3: the pick within it */
														__(
															'%1$s: %2$s, %3$s',
															'beyond-elysium'
														),
														entry.character,
														entry.name,
														entry.power_name
													)
												: sprintf(
														/* translators: 1: a character, 2: an entry the character holds */
														__(
															'%1$s: %2$s',
															'beyond-elysium'
														),
														entry.character,
														entry.name
													) }
										</li>
									) ) }
								</ul>
								<p>
									<button
										type="button"
										className="button"
										onClick={ () =>
											commit( pending.base, pending.ids )
										}
									>
										{ __(
											'Save anyway',
											'beyond-elysium'
										) }
									</button>{ ' ' }
									<button
										type="button"
										className="button"
										onClick={ () => setPending( null ) }
									>
										{ __( 'Cancel', 'beyond-elysium' ) }
									</button>
								</p>
							</div>
						) }
					</fieldset>
				);
			} ) }
		</div>
	);
}

export default CatalogVariantsPicker;
