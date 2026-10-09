import {
	validateJoinMessage,
	JOIN_MESSAGE_MAX_LENGTH,
} from './joinChronicleForm';

describe( 'validateJoinMessage', () => {
	it( 'passes a short, real message', () => {
		expect(
			validateJoinMessage( 'Hi, I would love to play here.' )
		).toBeNull();
	} );

	it( 'refuses an empty message', () => {
		expect( validateJoinMessage( '' ) ).not.toBeNull();
	} );

	it( 'refuses a message that is only whitespace', () => {
		expect( validateJoinMessage( '   \n  ' ) ).not.toBeNull();
	} );

	it( 'passes a message at exactly the limit', () => {
		expect(
			validateJoinMessage( 'a'.repeat( JOIN_MESSAGE_MAX_LENGTH ) )
		).toBeNull();
	} );

	it( 'refuses a message over the limit', () => {
		expect(
			validateJoinMessage( 'a'.repeat( JOIN_MESSAGE_MAX_LENGTH + 1 ) )
		).not.toBeNull();
	} );
} );
