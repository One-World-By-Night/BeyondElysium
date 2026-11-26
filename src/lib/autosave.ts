/**
 * Saves a value a moment after it stops changing, and again on demand: the Story view's fields save as you type.
 */

export type AutosaveState = 'idle' | 'dirty' | 'saving' | 'saved' | 'failed';

export interface AutosaveOptions< T > {
	/**
	 * What is already saved; a change back to it saves nothing.
	 */
	initial: T;
	/**
	 * Writes a value, rejecting when it could not be saved.
	 */
	save: ( value: T ) => Promise< void >;
	/**
	 * Told every time the state changes; `message` is why a save failed.
	 */
	onState: ( state: AutosaveState, message?: string ) => void;
	/**
	 * How long after the last change a save starts.
	 */
	delay?: number;
	/**
	 * How long after a failure the save is tried again, up to `maxRetries` times.
	 */
	retryDelay?: number;
	maxRetries?: number;
	equal?: ( a: T, b: T ) => boolean;
}

export interface Autosaver< T > {
	/**
	 * Records the value now held; a save follows after the delay.
	 */
	change: ( value: T ) => void;
	/**
	 * Saves what is waiting now, resolving when that is done.
	 */
	flush: () => Promise< void >;
	/**
	 * Tries a failed save again at once.
	 */
	retry: () => Promise< void >;
	/**
	 * Whether anything held has not been saved: a change waiting, a save running, or a save that failed.
	 */
	hasUnsaved: () => boolean;
	/**
	 * Stops every timer; nothing more is saved.
	 */
	dispose: () => void;
}

function sameJson< T >( a: T, b: T ): boolean {
	return JSON.stringify( a ) === JSON.stringify( b );
}

export function createAutosaver< T >( {
	initial,
	save,
	onState,
	delay = 1500,
	retryDelay = 5000,
	maxRetries = 3,
	equal = sameJson,
}: AutosaveOptions< T > ): Autosaver< T > {
	let saved = initial;
	let pending: T = initial;
	let state: AutosaveState = 'idle';
	let timer: ReturnType< typeof setTimeout > | null = null;
	let running: Promise< void > | null = null;
	let failures = 0;
	let disposed = false;

	const set = ( next: AutosaveState, message?: string ) => {
		state = next;
		onState( next, message );
	};

	const clear = () => {
		if ( timer !== null ) {
			clearTimeout( timer );
			timer = null;
		}
	};

	const schedule = ( wait: number ) => {
		clear();
		timer = setTimeout( () => {
			timer = null;
			void run();
		}, wait );
	};

	function run(): Promise< void > {
		if ( running ) {
			return running;
		}
		clear();
		if ( disposed || equal( pending, saved ) ) {
			if ( state !== 'saved' ) {
				set( 'idle' );
			}
			return Promise.resolve();
		}
		const value = pending;
		set( 'saving' );
		running = save( value )
			.then( () => {
				saved = value;
				failures = 0;
				running = null;
				if ( disposed ) {
					return;
				}
				if ( equal( pending, saved ) ) {
					set( 'saved' );
				} else {
					set( 'dirty' );
					schedule( delay );
				}
			} )
			.catch( ( error: unknown ) => {
				running = null;
				failures += 1;
				if ( disposed ) {
					return;
				}
				set(
					'failed',
					error instanceof Error ? error.message : String( error )
				);
				if ( failures <= maxRetries ) {
					schedule( retryDelay );
				}
			} );
		return running;
	}

	return {
		change( value ) {
			pending = value;
			if ( equal( pending, saved ) && ! running ) {
				clear();
				set( state === 'saved' ? 'saved' : 'idle' );
				return;
			}
			if ( state !== 'saving' ) {
				set( 'dirty' );
			}
			schedule( delay );
		},
		flush() {
			return run();
		},
		retry() {
			failures = 0;
			return run();
		},
		hasUnsaved() {
			return (
				running !== null ||
				state === 'failed' ||
				! equal( pending, saved )
			);
		},
		dispose() {
			disposed = true;
			clear();
		},
	};
}
