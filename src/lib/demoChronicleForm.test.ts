import { describe, expect, it } from 'vitest';
import { validateDemoChronicleForm } from './demoChronicleForm';

describe( 'validateDemoChronicleForm', () => {
	it( 'passes when the flag is off, whatever the accounts are', () => {
		expect(
			validateDemoChronicleForm( {
				on: false,
				storytellerId: undefined,
				playerId: undefined,
			} )
		).toBeNull();
	} );

	it( 'passes when the flag is on and both accounts are chosen', () => {
		expect(
			validateDemoChronicleForm( {
				on: true,
				storytellerId: 5,
				playerId: 9,
			} )
		).toBeNull();
	} );

	it( 'refuses turning the flag on with no storyteller chosen', () => {
		expect(
			validateDemoChronicleForm( {
				on: true,
				storytellerId: undefined,
				playerId: 9,
			} )
		).not.toBeNull();
	} );

	it( 'refuses turning the flag on with no player chosen', () => {
		expect(
			validateDemoChronicleForm( {
				on: true,
				storytellerId: 5,
				playerId: undefined,
			} )
		).not.toBeNull();
	} );
} );
