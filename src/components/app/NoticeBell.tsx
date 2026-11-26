/**
 * The bell in BE's header: how many notices a person has not read, and a panel with the newest, each pointing at where
 * it belongs. The count is read with the page, when the tab shows again and when the section changes - never on a timer.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import {
	noticeHref,
	noticeText,
	type Notice,
	type NoticeSettings,
} from '../../lib/notices';
import { formatStoredTime } from '../../lib/storedTime';
import './NoticeBell.css';

export interface NoticeBellProps {
	gameSlug: string;
	/**
	 * Changes when the page's section changes, which is when the count is read again.
	 */
	section?: string;
}

export function NoticeBell( { gameSlug, section }: NoticeBellProps ) {
	const [ unread, setUnread ] = useState( 0 );
	const [ items, setItems ] = useState< Notice[] >( [] );
	const [ settings, setSettings ] = useState< NoticeSettings | null >( null );
	const [ open, setOpen ] = useState( false );
	const panel = useRef< HTMLElement >( null );

	const load = () => {
		if ( ! gameSlug ) {
			return;
		}
		api.notices( gameSlug )
			.list()
			.then( ( list ) => {
				setUnread( list.unread );
				setItems( list.items );
				setSettings( list.settings ?? null );
			} )
			.catch( () => undefined );
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug, section ] );

	useEffect( () => {
		const onVisible = () => {
			if ( document.visibilityState === 'visible' ) {
				load();
			}
		};
		document.addEventListener( 'visibilitychange', onVisible );
		return () =>
			document.removeEventListener( 'visibilitychange', onVisible );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ gameSlug ] );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'keydown', onKey );
		panel.current?.focus();
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ open ] );

	if (
		! gameSlug ||
		Number( window.beyondElysium?.currentUserId ?? 0 ) <= 0
	) {
		return null;
	}

	const markRead = ( ids?: number[] ) =>
		api
			.notices( gameSlug )
			.markRead( ids )
			.then( ( result ) => {
				setUnread( result.unread );
				setItems( ( held ) =>
					held.map( ( notice ) =>
						ids === undefined || ids.includes( notice.id )
							? {
									...notice,
									read_at: notice.read_at ?? 'now',
								}
							: notice
					)
				);
			} )
			.catch( () => undefined );

	const label =
		unread > 0
			? sprintf(
					/* translators: %d: how many notices have not been read */
					_n(
						'Notices, %d unread',
						'Notices, %d unread',
						unread,
						'beyond-elysium'
					),
					unread
				)
			: __( 'Notices', 'beyond-elysium' );

	return (
		<>
			<button
				type="button"
				className="be-notice-bell"
				aria-label={ label }
				aria-expanded={ open }
				onClick={ () => {
					setOpen( true );
					load();
				} }
			>
				<svg
					viewBox="0 0 24 24"
					width="20"
					height="20"
					aria-hidden="true"
					focusable="false"
				>
					<path
						d="M12 22a2.4 2.4 0 0 0 2.4-2.4h-4.8A2.4 2.4 0 0 0 12 22Zm7-6.6V11a7 7 0 0 0-5.5-6.8V3.5a1.5 1.5 0 0 0-3 0v.7A7 7 0 0 0 5 11v4.4L3 17.4V19h18v-1.6Z"
						fill="currentColor"
					/>
				</svg>
				{ unread > 0 && (
					<span className="be-notice-bell__count">{ unread }</span>
				) }
			</button>
			{ open && (
				<div
					className="be-notice-panel__backdrop"
					role="presentation"
					data-no-translation=""
					onClick={ () => setOpen( false ) }
				>
					{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-noninteractive-element-interactions */ }
					<aside
						ref={ panel }
						className="be-notice-panel"
						role="dialog"
						aria-modal="true"
						aria-label={ __( 'Notices', 'beyond-elysium' ) }
						tabIndex={ -1 }
						onClick={ ( e ) => e.stopPropagation() }
					>
						<header className="be-notice-panel__header">
							<h3>{ __( 'Notices', 'beyond-elysium' ) }</h3>
							<span className="be-notice-panel__actions">
								<button
									type="button"
									disabled={ unread === 0 }
									onClick={ () => markRead() }
								>
									{ __( 'Mark all read', 'beyond-elysium' ) }
								</button>
								<button
									type="button"
									onClick={ () => setOpen( false ) }
								>
									{ __( 'Close', 'beyond-elysium' ) }
								</button>
							</span>
						</header>
						{ settings && (
							<label className="be-notice-panel__setting">
								<span>
									{ __(
										'Changes waiting for you',
										'beyond-elysium'
									) }
								</span>
								<select
									value={ settings.waiting_notify }
									onChange={ ( e ) =>
										api
											.notices( gameSlug )
											.saveSettings( e.target.value )
											.then( setSettings )
											.catch( () => undefined )
									}
								>
									<option value="">
										{ settings.staff_address
											? __(
													'Default: nothing, the staff address has it',
													'beyond-elysium'
												)
											: __(
													'Default: each submission',
													'beyond-elysium'
												) }
									</option>
									<option value="each">
										{ __(
											'Each submission',
											'beyond-elysium'
										) }
									</option>
									<option value="daily">
										{ __( 'Once a day', 'beyond-elysium' ) }
									</option>
									<option value="never">
										{ __( 'Never', 'beyond-elysium' ) }
									</option>
								</select>
							</label>
						) }
						{ items.length === 0 ? (
							<p>
								{ __(
									'Nothing new. What happens to your characters and your work shows up here.',
									'beyond-elysium'
								) }
							</p>
						) : (
							<ul className="be-notice-panel__list">
								{ items.map( ( notice ) => {
									const words = noticeText( notice );
									const href = noticeHref( notice, gameSlug );
									return (
										<li
											key={ notice.id }
											className={
												notice.read_at
													? 'be-notice-panel__item'
													: 'be-notice-panel__item be-notice-panel__item--unread'
											}
										>
											<a
												href={ href }
												onClick={ ( e ) => {
													if ( notice.read_at ) {
														return;
													}
													e.preventDefault();
													markRead( [
														notice.id,
													] ).then( () =>
														window.location.assign(
															href
														)
													);
												} }
											>
												<span className="be-notice-panel__title">
													{ words.title }
												</span>
												{ words.lines.map(
													( line, index ) => (
														<span
															key={ index }
															className="be-notice-panel__line"
														>
															{ line }
														</span>
													)
												) }
												<span className="be-notice-panel__when">
													{ formatStoredTime(
														notice.created_at,
														{ time: true }
													) }
												</span>
											</a>
										</li>
									);
								} ) }
							</ul>
						) }
					</aside>
				</div>
			) }
		</>
	);
}

export default NoticeBell;
