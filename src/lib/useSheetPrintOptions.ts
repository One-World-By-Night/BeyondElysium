/**
 * What the sheet includes when it prints or exports, read once from the page's address.
 */
import { useState } from '@wordpress/element';

export type SheetPaper = 'letter' | 'a4';

export interface SheetPrintOptions {
	printBackground: boolean;
	setPrintBackground: ( on: boolean ) => void;
	printNotes: boolean;
	setPrintNotes: ( on: boolean ) => void;
	printXpHistory: boolean;
	setPrintXpHistory: ( on: boolean ) => void;
	printFullPowerNames: boolean;
	setPrintFullPowerNames: ( on: boolean ) => void;
	/**
	 * A count_is_cost block's held entries (Combo Disciplines) always name their XP price.
	 */
	printShowCost: boolean;
	setPrintShowCost: ( on: boolean ) => void;
	printPaper: SheetPaper;
	setPrintPaper: ( paper: SheetPaper ) => void;
}

/**
 * The print choices, each starting from the address's own parameter and off by default.
 */
export function useSheetPrintOptions(
	urlParams: URLSearchParams
): SheetPrintOptions {
	const [ printBackground, setPrintBackground ] = useState(
		() => urlParams.get( 'print_background' ) === '1'
	);
	const [ printNotes, setPrintNotes ] = useState(
		() => urlParams.get( 'print_notes' ) === '1'
	);
	const [ printXpHistory, setPrintXpHistory ] = useState(
		() => urlParams.get( 'print_xp_history' ) === '1'
	);
	const [ printFullPowerNames, setPrintFullPowerNames ] = useState(
		() => urlParams.get( 'print_full_power_names' ) === '1'
	);
	const [ printShowCost, setPrintShowCost ] = useState(
		() => urlParams.get( 'print_hide_cost' ) !== '1'
	);
	const [ printPaper, setPrintPaper ] = useState< SheetPaper >(
		() => window.beyondElysium?.paperSize ?? 'letter'
	);
	return {
		printBackground,
		setPrintBackground,
		printNotes,
		setPrintNotes,
		printXpHistory,
		setPrintXpHistory,
		printFullPowerNames,
		setPrintFullPowerNames,
		printShowCost,
		setPrintShowCost,
		printPaper,
		setPrintPaper,
	};
}
