/**
 * Which `?` has the help panel open. One panel shows at a time across every widget on the
 * page: opening another screen's help closes the one before it (1.0.0-help.md H-1).
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
