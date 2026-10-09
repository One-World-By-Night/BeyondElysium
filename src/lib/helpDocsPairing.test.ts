import { createRequire } from 'module';
import { join } from 'path';

/**
 * The website's help pages: how a page's blocks pair with their Portuguese twins, how its links reach the right
 * heading, and that every real page pairs.
 */
const load = createRequire( __filename );
const TOOLS = join( __dirname, '../../tools/help-docs' );
const { convertBlocks, rewriteLinks, headingAnchors, headingId } = load(
	join( TOOLS, 'convert.js' )
);
const { pairBlocks, planTranslations } = load( join( TOOLS, 'pairing.js' ) );
const { buildDocs } = load( join( TOOLS, 'build.js' ) );

interface Pair {
	kind: string;
	original: string;
	translated: string;
}

const blocks = ( markdown: string ) => convertBlocks( markdown );
const pair = ( english: string, portuguese: string ) =>
	pairBlocks( blocks( english ), blocks( portuguese ) ) as {
		pairs: Pair[];
		problems: string[];
	};

describe( 'pairing a page with its Portuguese twin', () => {
	test( 'a paragraph pairs as one whole block, links and emphasis inside it', () => {
		const result = pair(
			'Open the **Save** tab and [read more](a.md).',
			'Abra a aba **Salvar** e [leia mais](a.md).'
		);
		expect( result.problems ).toEqual( [] );
		expect( result.pairs ).toEqual( [
			{
				kind: 'block',
				original:
					'Open the <strong>Save</strong> tab and <a href="a.md">read more</a>.',
				translated:
					'Abra a aba <strong>Salvar</strong> e <a href="a.md">leia mais</a>.',
			},
		] );
	} );

	test( 'a heading, each list item and each table cell is its own block', () => {
		const result = pair(
			'# Title\n\n- one\n- two\n\n| A | B |\n| - | - |\n| c | d |\n',
			'# Título\n\n- um\n- dois\n\n| A | B |\n| - | - |\n| c | d |\n'
		);
		expect( result.problems ).toEqual( [] );
		expect( result.pairs.map( ( found ) => found.original ) ).toEqual( [
			'Title',
			'one',
			'two',
		] );
	} );

	test( 'a paragraph with no markup pairs as plain text, its line breaks kept', () => {
		const result = pair(
			'First line\nsecond line.\n',
			'Primeira linha\nsegunda linha.\n'
		);
		expect( result.pairs ).toEqual( [
			{
				kind: 'text',
				original: 'First line\nsecond line.',
				translated: 'Primeira linha\nsegunda linha.',
			},
		] );
	} );

	test( 'the quotes in a block are written as entities, as the page holds them', () => {
		const result = pair(
			'Press **Save**, it\'s "done".\n',
			'Clique em **Salvar**, está "pronto".\n'
		);
		expect( result.pairs ).toEqual( [
			{
				kind: 'block',
				original:
					'Press <strong>Save</strong>, it&#39;s &quot;done&quot;.',
				translated:
					'Clique em <strong>Salvar</strong>, está &quot;pronto&quot;.',
			},
		] );
	} );

	test( 'a heading pairs piece by piece, never as a block', () => {
		const result = pair( '## Print `x` now\n', '## Imprimir `x` agora\n' );
		expect( result.pairs ).toEqual( [
			{ kind: 'text', original: 'Print', translated: 'Imprimir' },
			{ kind: 'text', original: 'now', translated: 'agora' },
		] );
	} );

	test( 'text that reads the same in both languages makes no pair', () => {
		const result = pair( 'Run `wp be demo`.', 'Run `wp be demo`.' );
		expect( result ).toEqual( { pairs: [], problems: [] } );
	} );

	test( 'a list item that holds a nested list pairs the text around the list', () => {
		const result = pair(
			'- **Sections** - one row per section.\n  - **Block** - which block.\n',
			'- **Seções** - uma linha por seção.\n  - **Bloco** - qual bloco.\n'
		);
		expect( result.problems ).toEqual( [] );
		expect( result.pairs ).toEqual( [
			{ kind: 'text', original: 'Sections', translated: 'Seções' },
			{
				kind: 'text',
				original: '- one row per section.',
				translated: '- uma linha por seção.',
			},
			{
				kind: 'block',
				original: '<strong>Block</strong> - which block.',
				translated: '<strong>Bloco</strong> - qual bloco.',
			},
		] );
	} );

	test( 'a page with a different number of blocks is refused whole', () => {
		const result = pair( 'One.\n\nTwo.\n', 'Um.\n' );
		expect( result.pairs ).toEqual( [] );
		expect( result.problems ).toEqual( [
			'2 blocks in English, 1 in Portuguese',
		] );
	} );

	test( 'a page whose list has another number of items is refused whole, though its first block pairs', () => {
		const result = pair(
			'First.\n\n- a\n- b\n',
			'Primeiro.\n\n- a\n- b\n- c\n'
		);
		expect( result.pairs ).toEqual( [] );
		expect( result.problems ).toEqual( [
			expect.stringContaining( 'the markup differs' ),
		] );
	} );

	test( 'a block of another kind is refused', () => {
		const result = pair( 'Text.\n', '# Texto\n' );
		expect( result.pairs ).toEqual( [] );
		expect( result.problems.length ).toBeGreaterThan( 0 );
	} );

	test( 'a link outside a whole block that goes to another page is refused', () => {
		const result = pair( '- [Go](a.md)\n  - x\n', '- [Ir](b.md)\n  - x\n' );
		expect( result.pairs ).toEqual( [] );
		expect( result.problems ).toEqual( [
			expect.stringContaining( 'a link goes somewhere else' ),
		] );
	} );
} );

describe( 'planning every page together', () => {
	const page = ( slug: string, english: string, portuguese: string ) => ( {
		slug,
		english: blocks( english ),
		portuguese: blocks( portuguese ),
	} );

	test( 'one page that does not pair is refused and the others still pair', () => {
		const plan = planTranslations( [
			page( 'good', 'Hello.\n', 'Olá.\n' ),
			page( 'bad', 'One.\n\nTwo.\n', 'Um.\n' ),
		] );
		const bySlug = Object.fromEntries(
			plan.pages.map( ( found: { slug: string } ) => [
				found.slug,
				found,
			] )
		);
		expect( bySlug.good.pairs ).toHaveLength( 1 );
		expect( bySlug.bad.pairs ).toEqual( [] );
		expect( bySlug.bad.problems ).not.toEqual( [] );
		expect( plan.conflicts ).toEqual( [] );
	} );

	test( 'two English blocks with the same words and two Portuguese texts are a conflict', () => {
		const plan = planTranslations( [
			page( 'a', 'Find it.\n', 'Ache-o.\n' ),
			page( 'b', 'Find it.\n', 'Ache-a.\n' ),
		] );
		expect( plan.conflicts ).toEqual( [
			{
				original: 'Find it.',
				versions: [
					{ original: 'Find it.', translated: 'Ache-o.' },
					{ original: 'Find it.', translated: 'Ache-a.' },
				],
			},
		] );
	} );

	test( 'plain text and marked-up text with the same words but two Portuguese texts are a conflict', () => {
		const plan = planTranslations( [
			page( 'a', 'Save.\n', 'Salve.\n' ),
			page( 'b', '**Save**.\n', '**Salvar**.\n' ),
		] );
		expect( plan.conflicts ).not.toEqual( [] );
	} );

	test( 'a string and a whole block that are the same string have to agree', () => {
		const plan = planTranslations( [
			page( 'a', '- Games\n', '- Jogos\n' ),
			page( 'b', '- Games\n', '- Partidas\n' ),
		] );
		expect( plan.conflicts ).toEqual( [
			{
				original: 'Games',
				versions: [
					{ original: 'Games', translated: 'Jogos' },
					{ original: 'Games', translated: 'Partidas' },
				],
			},
		] );
	} );

	test( 'a heading and a link cell with the same words and the same Portuguese words pair piece by piece', () => {
		const plan = planTranslations( [
			page( 'a', '# Games\n', '# Jogos\n' ),
			page(
				'b',
				'- [Games](g.md)\n  - x\n- Games\n',
				'- [Jogos](g.md)\n  - x\n- Jogos\n'
			),
			page( 'c', '[Games](g.md)\n', '[Jogos](g.md)\n' ),
		] );
		expect( plan.conflicts ).toEqual( [] );
		const found = plan.pages.flatMap(
			( entry: { pairs: Pair[] } ) => entry.pairs
		);
		expect(
			found.filter(
				( entry: Pair ) =>
					entry.kind === 'text' && entry.original === 'Games'
			)
		).not.toEqual( [] );
	} );
} );

describe( 'links reach the heading the website numbers', () => {
	const page = '# Guide\n\n## First\n\n## Second\n\n## First\n';

	test( 'anchors are GitHub-style, repeats numbered', () => {
		expect( headingAnchors( page ) ).toEqual( [
			'guide',
			'first',
			'second',
			'first-1',
		] );
		expect( headingAnchors( '# Ficha\n\n## Personalização\n' ) ).toEqual( [
			'ficha',
			'personalização',
		] );
	} );

	test( 'the website numbers every heading of a page from zero', () => {
		expect( headingId( 0 ) ).toBe( '0-toc-title' );
		expect( headingId( 3 ) ).toBe( '3-toc-title' );
	} );

	test( 'a link to a heading becomes a link to its number, here and on another page', () => {
		const anchors = {
			guide: headingAnchors( page ),
			other: headingAnchors( '# Other\n\n## Part\n' ),
		};
		const result = rewriteLinks(
			'[a](#second) [b](other.md#part) [c](other.md)',
			new Set( [ 'guide', 'other' ] ),
			false,
			anchors,
			'guide'
		);
		expect( result.unresolved ).toEqual( [] );
		expect( result.rewritten ).toBe(
			'[a](#2-toc-title) [b](https://beyondelysium.com/docs/other/#1-toc-title) [c](https://beyondelysium.com/docs/other/)'
		);
	} );

	test( 'a link to a heading that is not there is reported and left alone', () => {
		const result = rewriteLinks(
			'[a](#nope) [b](other.md#nope)',
			new Set( [ 'guide', 'other' ] ),
			false,
			{ guide: [ 'guide' ], other: [ 'other' ] },
			'guide'
		);
		expect( result.unresolved ).toEqual( [
			'](other.md#nope)',
			'](#nope)',
		] );
		expect( result.rewritten ).toBe( '[a](#nope) [b](other.md#nope)' );
	} );
} );

describe( 'the real pages', () => {
	const built = buildDocs( join( __dirname, '../../beyond-elysium/docs' ) );

	test( 'every help page and guide pairs with its Portuguese translation', () => {
		expect(
			built.docs
				.filter( ( doc: { portuguese: unknown } ) => ! doc.portuguese )
				.map(
					( doc: { slug: string; unpaired: string[] } ) =>
						`${ doc.slug }: ${ doc.unpaired.join( '; ' ) }`
				)
		).toEqual( [] );
		expect( built.docs.length ).toBeGreaterThan( 80 );
	} );

	test( 'no English string has two Portuguese translations', () => {
		expect( built.conflicts ).toEqual( [] );
	} );

	test( 'every link in every page, in both languages, reaches a page and heading that exist', () => {
		expect( built.unresolved ).toEqual( [] );
	} );

	test( 'every screenshot follows a block of its page', () => {
		expect( built.shotProblems ).toEqual( [] );
		const shots = built.docs.flatMap(
			( doc: { shots: unknown[] } ) => doc.shots
		);
		expect( shots.length ).toBeGreaterThanOrEqual( 80 );
	} );
} );
