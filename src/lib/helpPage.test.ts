import {
	headingAnchors,
	headingSlug,
	headingText,
	helpTarget,
	pageTitle,
	withoutTitle,
} from './helpPage';

describe( 'helpTarget', () => {
	it( 'opens another help page by its file name', () => {
		expect( helpTarget( 'chronicle-access.md' ) ).toEqual( {
			kind: 'help',
			key: 'chronicle-access',
			anchor: '',
		} );
		expect( helpTarget( 'roles.md#who-can-use-this' ) ).toEqual( {
			kind: 'help',
			key: 'roles',
			anchor: 'who-can-use-this',
		} );
	} );

	it( 'opens a help page linked down from a guide', () => {
		expect( helpTarget( 'help/send-grapevine-file.md' ) ).toEqual( {
			kind: 'help',
			key: 'send-grapevine-file',
			anchor: '',
		} );
		expect( helpTarget( 'help/roles.md#who-can-use-this' ) ).toEqual( {
			kind: 'help',
			key: 'roles',
			anchor: 'who-can-use-this',
		} );
	} );

	it( 'opens a guide section one folder up', () => {
		expect( helpTarget( '../st-guide.md#3-making-characters' ) ).toEqual( {
			kind: 'guide',
			slug: 'st-guide',
			anchor: '3-making-characters',
		} );
		expect( helpTarget( '../player-guide.md' ) ).toEqual( {
			kind: 'guide',
			slug: 'player-guide',
			anchor: '',
		} );
	} );

	it( 'leaves the web to the browser and follows a section on the same page', () => {
		expect( helpTarget( 'https://beyondelysium.com/' ) ).toEqual( {
			kind: 'external',
			href: 'https://beyondelysium.com/',
		} );
		expect( helpTarget( '#troubleshooting' ) ).toEqual( {
			kind: 'section',
			anchor: 'troubleshooting',
		} );
	} );

	it( 'follows nothing it cannot open', () => {
		expect( helpTarget( '../CLAUDE.md' ) ).toBeNull();
		expect( helpTarget( '../../includes/Core/Plugin.php' ) ).toBeNull();
		expect( helpTarget( 'sub/dir.md' ) ).toBeNull();
	} );
} );

describe( 'headingSlug', () => {
	it( 'makes the anchors the guides are linked by', () => {
		expect(
			headingSlug(
				'9. Action & Rumor Settings and the Background-Use Ledger'
			)
		).toBe( '9-action--rumor-settings-and-the-background-use-ledger' );
		expect( headingSlug( 'What an HST Can and Cannot Do' ) ).toBe(
			'what-an-hst-can-and-cannot-do'
		);
		expect( headingSlug( '3. Making Characters' ) ).toBe(
			'3-making-characters'
		);
	} );

	it( 'keeps letters beyond English', () => {
		expect( headingSlug( 'Configuração inicial' ) ).toBe(
			'configuração-inicial'
		);
	} );
} );

describe( 'headingText', () => {
	it( 'drops the Markdown and keeps the words', () => {
		expect( headingText( 'The `be-player` page' ) ).toBe(
			'The be-player page'
		);
		expect( headingText( 'See [Roles](roles.md) *first*' ) ).toBe(
			'See Roles first'
		);
	} );
} );

describe( 'headingAnchors', () => {
	it( 'numbers a repeated heading and skips code blocks', () => {
		const markdown = [
			'# Title',
			'## Common tasks',
			'```',
			'# not a heading',
			'```',
			'### Common tasks',
		].join( '\n' );
		expect( headingAnchors( markdown ) ).toEqual( [
			'title',
			'common-tasks',
			'common-tasks-1',
		] );
	} );
} );

describe( 'pageTitle and withoutTitle', () => {
	it( 'moves the title into the panel header', () => {
		const markdown = '# Roles\n\nThe five roles.\n\n## Who can use this\n';
		expect( pageTitle( markdown ) ).toBe( 'Roles' );
		expect( withoutTitle( markdown ) ).toBe(
			'The five roles.\n\n## Who can use this\n'
		);
	} );
} );
