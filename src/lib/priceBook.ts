/**
 * The prices fetched for one picker's options.
 */
import type { ChangeRequest, PreviewChangesResponse } from '../types/character';
import { priceBatches, priceHint } from './optionPrice';

export interface PriceBookOptions {
	/**
	 * Prices a batch of changes against the saved sheet; the results come back in the order sent.
	 */
	preview: ( changes: ChangeRequest[] ) => Promise< PreviewChangesResponse >;
	/**
	 * The change picking this option would submit, or null when it cannot be priced.
	 */
	changeFor: ( value: string ) => ChangeRequest | null;
	/**
	 * True for an option whose cost the player chooses.
	 */
	isVariable?: ( value: string ) => boolean;
}

/**
 * Holds the hint for each option that has been priced. An option is asked for once; a price that fails to arrive
 * leaves that option without a hint until the book is reset.
 */
export class PriceBook {
	private readonly hints = new Map< string, string | null >();

	private readonly pending = new Set< string >();

	private readonly listeners = new Set< () => void >();

	private generation = 0;

	constructor( private readonly options: PriceBookOptions ) {}

	/**
	 * The hint for an option, or null when it has none yet.
	 */
	hint = ( value: string ): string | null => this.hints.get( value ) ?? null;

	/**
	 * Asks for the price of every option not already priced or being priced.
	 */
	request = ( values: string[] ): void => {
		const known = new Set( [ ...this.hints.keys(), ...this.pending ] );
		for ( const batch of priceBatches( values, known ) ) {
			const sent: Array< { value: string; change: ChangeRequest } > = [];
			for ( const value of batch ) {
				const change = this.options.changeFor( value );
				if ( change ) {
					sent.push( { value, change } );
				} else {
					this.hints.set( value, null );
				}
			}
			if ( sent.length > 0 ) {
				this.send( sent );
			}
		}
	};

	/**
	 * Forgets every price, and any still on its way.
	 */
	reset(): void {
		this.generation += 1;
		this.hints.clear();
		this.pending.clear();
		this.notify();
	}

	/**
	 * Calls `listener` each time prices arrive or are forgotten; returns the function that stops it.
	 */
	subscribe( listener: () => void ): () => void {
		this.listeners.add( listener );
		return () => {
			this.listeners.delete( listener );
		};
	}

	private send( sent: Array< { value: string; change: ChangeRequest } > ) {
		const generation = this.generation;
		sent.forEach( ( { value } ) => this.pending.add( value ) );
		this.options
			.preview( sent.map( ( item ) => item.change ) )
			.then( ( response ) => {
				if ( generation !== this.generation ) {
					return;
				}
				sent.forEach( ( { value }, index ) => {
					this.pending.delete( value );
					const quote = response.results[ index ];
					this.hints.set(
						value,
						quote
							? priceHint(
									quote,
									this.options.isVariable?.( value ) ?? false
								)
							: null
					);
				} );
				this.notify();
			} )
			.catch( () => {
				if ( generation !== this.generation ) {
					return;
				}
				sent.forEach( ( { value } ) => {
					this.pending.delete( value );
					this.hints.set( value, null );
				} );
			} );
	}

	private notify(): void {
		this.listeners.forEach( ( listener ) => listener() );
	}
}
