import {
	inviteMessages,
	linkMessages,
	skippedMessages,
} from './playerInviteMessages';

describe( 'inviteMessages', () => {
	it( 'says an existing account became a player and names what was linked', () => {
		expect(
			inviteMessages( 'pat@example.test', {
				status: 'linked',
				display_name: 'Pat Player',
				player: { status: 'added' },
				linked: [
					{ id: 1, name: 'Ada Vane' },
					{ id: 2, name: 'Bram Cole' },
				],
				skipped: [],
			} )
		).toEqual( [
			'Pat Player is a player here now.',
			'Linked: Ada Vane, Bram Cole.',
		] );
	} );

	it( 'says an invitation went out and names the characters waiting', () => {
		expect(
			inviteMessages( 'new@example.test', {
				status: 'invited',
				email_sent: true,
				held: [ { id: 3, name: 'Cass Reed' } ],
				linked: [],
				skipped: [],
			} )
		).toEqual( [
			'Invitation emailed to new@example.test. They join the first time they sign in with that address.',
			'Waiting for them: Cass Reed.',
		] );
	} );

	it( 'says an invite was only saved when no email went out', () => {
		expect(
			inviteMessages( 'quiet@example.test', {
				status: 'invited',
				email_sent: false,
				held: [],
				linked: [],
				skipped: [],
			} )[ 0 ]
		).toBe(
			'Invite saved for quiet@example.test. They join the first time they sign in with that address.'
		);
	} );
} );

describe( 'skippedMessages', () => {
	it( 'names a character linked to someone else and who holds it', () => {
		expect(
			skippedMessages( [
				{
					id: 1,
					name: 'Ada Vane',
					linked_to: 'First Owner',
					reason: 'linked_elsewhere',
				},
			] )
		).toEqual( [
			'Ada Vane is linked to First Owner, so it was left alone. Unlink it from them first.',
		] );
	} );

	it( 'says nothing about a character that was not found', () => {
		expect( skippedMessages( [ { id: 9, reason: 'not_found' } ] ) ).toEqual(
			[]
		);
	} );
} );

describe( 'linkMessages', () => {
	it( 'names the player and the characters linked', () => {
		expect(
			linkMessages( 'Pat Player', {
				linked: [ { id: 1, name: 'Ada Vane' } ],
				skipped: [],
			} )
		).toEqual( [ 'Linked to Pat Player: Ada Vane.' ] );
	} );
} );
