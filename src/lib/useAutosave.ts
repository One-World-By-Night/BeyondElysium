/**
 * Autosaving for one group of fields: the saver, its state, and the flush a field's blur or the view's unmounting asks
 * for.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	createAutosaver,
	type Autosaver,
	type AutosaveState,
} from './autosave';

export interface AutosaveHandle< T > {
	state: AutosaveState;
	message?: string;
	change: ( value: T ) => void;
	flush: () => void;
	retry: () => void;
	hasUnsaved: () => boolean;
}

/**
 * The autosaver for one group of fields. `initial` is what is already saved, read once when the group mounts; `save`
 * is the latest function passed in.
 */
export function useAutosave< T >(
	initial: T,
	save: ( value: T ) => Promise< void >
): AutosaveHandle< T > {
	const latest = useRef( save );
	latest.current = save;
	const [ view, setView ] = useState< {
		state: AutosaveState;
		message?: string;
	} >( { state: 'idle' } );
	const saver = useRef< Autosaver< T > | null >( null );
	if ( saver.current === null ) {
		saver.current = createAutosaver< T >( {
			initial,
			save: ( value ) => latest.current( value ),
			onState: ( state, message ) => setView( { state, message } ),
		} );
	}
	const current = saver.current;

	useEffect( () => {
		return () => {
			void current.flush().finally( () => current.dispose() );
		};
	}, [ current ] );

	return {
		state: view.state,
		message: view.message,
		change: current.change,
		flush: () => void current.flush(),
		retry: () => void current.retry(),
		hasUnsaved: current.hasUnsaved,
	};
}
