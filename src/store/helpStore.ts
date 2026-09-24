/**
 * Which `?` has the help panel open.
 */
import { create } from 'zustand';

interface HelpState {
	owner: symbol | null;
	open: ( owner: symbol ) => void;
	close: ( owner: symbol ) => void;
}

export const useHelpStore = create< HelpState >( ( set, get ) => ( {
	owner: null,
	open: ( owner ) => set( { owner } ),
	close: ( owner ) => {
		if ( get().owner === owner ) {
			set( { owner: null } );
		}
	},
} ) );
