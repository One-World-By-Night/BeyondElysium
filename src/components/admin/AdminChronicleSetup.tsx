/**
 * The Chronicle Setup checklist (GS-5, guided-chronicle-setup-design.md §6.4):
 * a chronicle picker plus ten status rows, each computed live server-side.
 * No completion state of any kind is stored here or anywhere else (§5.5) -
 * every render re-fetches, and a row that goes from green to amber (an AST
 * removed, say) is reflected immediately, not on some later "recheck."
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import api from '../../api/client';
import EnabledStacksPicker from './EnabledStacksPicker';
import type { Game, SetupStatus, SetupStatusItem } from '../../types';
import './AdminChronicleSetup.css';

const STATUS_LABEL: Record<SetupStatusItem[ 'status' ], string> = {
	attention: __( 'Needs attention', 'beyond-elysium' ),
	ok: __( 'Ready', 'beyond-elysium' ),
	info: __( 'Info', 'beyond-elysium' ),
};

/**
 * Renders one checklist row: its status pill, title and detail, and either
 * an inline control (rows 1/3/10) or a plain link to the page that fixes it.
 * A row the viewer cannot act on renders greyed with its status still
 * visible - useful even when it cannot be fixed from here.
 */
function StatusRow( {
	item,
	children,
}: {
	item: SetupStatusItem;
	children?: ReactNode;
} ) {
	return (
		<tr className={ `be-chronicle-setup__row be-chronicle-setup__row--${ item.status }` }>
			<td>
				<span className="be-chronicle-setup__pill">{ STATUS_LABEL[ item.status ] }</span>
			</td>
			<td>
				<strong>{ item.title }</strong>
				<p>{ item.detail }</p>
			</td>
			<td className={ item.actionable ? '' : 'be-chronicle-setup__not-actionable' }>
				{ children ??
					( item.fix.kind === 'link' && item.fix.href ? (
						<a className="button" href={ item.fix.href }>
							{ __( 'Go', 'beyond-elysium' ) }
						</a>
					) : null ) }
			</td>
		</tr>
	);
}

export function AdminChronicleSetup() {
	const [ games, setGames ] = useState<Game[]>( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ status, setStatus ] = useState<SetupStatus | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ savingRow, setSavingRow ] = useState<string | null>( null );

	useEffect( () => {
		api.games
			.list()
			.then( ( result ) => {
				setGames( result );
				if ( result.length > 0 ) {
					const fromUrl = new URLSearchParams( window.location.search ).get( 'game' );
					setGameSlug( result.find( ( g ) => g.slug === fromUrl )?.slug ?? result[ 0 ].slug );
				}
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	function reload() {
		if ( ! gameSlug ) {
			return;
		}
		api.setupStatus( gameSlug )
			.get()
			.then( setStatus )
			.catch( () => setStatus( null ) );
	}

	useEffect( reload, [ gameSlug ] );

	const currentGame = games.find( ( g ) => g.slug === gameSlug ) ?? null;
	const item = ( id: string ) => status?.items.find( ( i ) => i.id === id ) ?? null;

	function saveStacks( slugs: string[] ) {
		setSavingRow( 'enabled_stacks' );
		api.games
			.update( gameSlug, { settings: { enabled_stacks: slugs } } )
			.then( () => {
				setSavingRow( null );
				reload();
			} )
			.catch( () => setSavingRow( null ) );
	}

	function saveApproval( required: boolean ) {
		setSavingRow( 'require_new_character_approval' );
		api.games
			.update( gameSlug, { settings: { require_new_character_approval: required } } )
			.then( () => {
				setSavingRow( null );
				reload();
			} )
			.catch( () => setSavingRow( null ) );
	}

	function deleteDemo() {
		if ( ! window.confirm( __( 'Delete the demo chronicle and all 22 sample characters? This cannot be undone.', 'beyond-elysium' ) ) ) {
			return;
		}
		api.games.delete( gameSlug, true ).then( () => {
			window.location.reload();
		} );
	}

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( games.length === 0 ) {
		return <p>{ __( 'No chronicles exist yet - create one under Beyond Elysium → Games first.', 'beyond-elysium' ) }</p>;
	}

	const enabledStacksItem = item( 'enabled_stacks' );
	const approvalItem = item( 'require_new_character_approval' );
	const demoItem = item( 'demo_chronicle' );

	return (
		<div className="be-admin be-chronicle-setup">
			<h1>{ __( 'Chronicle Setup', 'beyond-elysium' ) }</h1>

			<div className="be-admin__filters">
				<label>
					{ __( 'Chronicle', 'beyond-elysium' ) }{ ' ' }
					<select value={ gameSlug } onChange={ ( e ) => setGameSlug( e.target.value ) }>
						{ games.map( ( g ) => (
							<option key={ g.slug } value={ g.slug }>
								{ g.name }
							</option>
						) ) }
					</select>
				</label>
			</div>

			{ status && (
				<p className="be-chronicle-setup__summary">
					{ status.summary.attention > 0
						? sprintf(
							/* translators: %d: number of checklist items needing attention */
							_n( '%d item needs attention.', '%d items need attention.', status.summary.attention, 'beyond-elysium' ),
							status.summary.attention
						)
						: __( 'Nothing needs attention.', 'beyond-elysium' ) }
				</p>
			) }

			{ status && (
				<table className="be-admin__table be-chronicle-setup__table">
					<tbody>
						{ status.items.map( ( row ) => {
							if ( row.id === 'enabled_stacks' && enabledStacksItem ) {
								return (
									<StatusRow item={ row } key={ row.id }>
										{ row.actionable ? (
											<EnabledStacksPicker
												enabled={ ( currentGame?.settings?.enabled_stacks as string[] | undefined ) ?? null }
												onSave={ saveStacks }
												saving={ savingRow === 'enabled_stacks' }
											/>
										) : null }
									</StatusRow>
								);
							}
							if ( row.id === 'require_new_character_approval' && approvalItem ) {
								const current = currentGame?.settings?.require_new_character_approval as boolean | undefined;
								return (
									<StatusRow item={ row } key={ row.id }>
										{ row.actionable ? (
											<div className="be-chronicle-setup__approval-toggle">
												<label>
													<input
														type="radio"
														name="require_new_character_approval"
														checked={ current === true }
														onChange={ () => saveApproval( true ) }
														disabled={ savingRow === 'require_new_character_approval' }
													/>
													{ __( 'Require approval', 'beyond-elysium' ) }
												</label>
												<label>
													<input
														type="radio"
														name="require_new_character_approval"
														checked={ current === false }
														onChange={ () => saveApproval( false ) }
														disabled={ savingRow === 'require_new_character_approval' }
													/>
													{ __( 'Active immediately', 'beyond-elysium' ) }
												</label>
											</div>
										) : null }
									</StatusRow>
								);
							}
							if ( row.id === 'demo_chronicle' && demoItem ) {
								return (
									<StatusRow item={ row } key={ row.id }>
										{ row.actionable ? (
											<button type="button" className="button" onClick={ deleteDemo }>
												{ __( 'Delete demo chronicle', 'beyond-elysium' ) }
											</button>
										) : null }
									</StatusRow>
								);
							}
							return <StatusRow item={ row } key={ row.id } />;
						} ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

export default AdminChronicleSetup;
