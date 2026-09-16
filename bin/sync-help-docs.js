#!/usr/bin/env node
/**
 * Converts every beyond-elysium/docs/help/*.md file into the Gutenberg block HTML
 * beyondelysium.com's BetterDocs "docs" post type expects, resolving every internal
 * cross-reference (another help page, or one of the four already-synced top-level guides -
 * admin-guide.md, player-guide.md, st-guide.md, rest-api.md) to a real, live URL along the
 * way. Writes one JSON file, [{slug, title, content}, ...], for bin/push-help-docs.php to
 * apply on the remote site over SSH - this script never touches the live site itself.
 *
 * Usage: node bin/sync-help-docs.js [output-path]
 *   (default output: dist/help-docs.json)
 *
 * Then, on the target site (see dev/ssh-to-beyondelysium.sh):
 *   scp the output file to /tmp/help-docs.json and bin/push-help-docs.php to /tmp/, then
 *   `wp eval-file /tmp/push-help-docs.php` there. Idempotent by slug - safe to re-run
 *   whenever a help page changes; existing posts are updated in place, never duplicated.
 */
const fs = require('fs');
const path = require('path');
const { marked } = require('marked');

const HELP_DIR = path.join(__dirname, '..', 'beyond-elysium', 'docs', 'help');
const SITE = 'https://beyondelysium.com';
const OUT = process.argv[2] || path.join(__dirname, '..', 'dist', 'help-docs.json');

// The four top-level guides live one directory up from docs/help/ and were synced before
// this script existed - their own source filename doesn't always match their live slug.
const GUIDE_SLUGS = {
	'admin-guide': 'admin-guide',
	'player-guide': 'player-guide',
	'st-guide': 'storyteller-guide',
	'rest-api': 'rest-api-reference',
};

/**
 * Resolves a source file's own markdown links to live site URLs.
 *
 * The two document sets sit one directory apart, so the same link shape means opposite
 * things depending on which set the file belongs to:
 *   - in a help page:  `foo.md` is a sibling help page, `../foo.md` is a guide
 *   - in a guide:      `foo.md` is a sibling guide,     `help/foo.md` is a help page
 */
function rewriteLinks(markdown, allSlugs, isGuide = false) {
	const unresolved = [];
	const rewritten = markdown.replace(
		/\]\((\.\.\/|help\/)?([a-z0-9-]+)\.md(#[^)]*)?\)/g,
		(full, prefix, base, anchor) => {
			anchor = anchor || '';
			const wantsGuide = isGuide ? !prefix : prefix === '../';
			let slug;
			if (wantsGuide) {
				slug = GUIDE_SLUGS[base];
			} else {
				slug = allSlugs.has(base) ? base : undefined;
			}
			if (!slug) {
				unresolved.push(full);
				return full;
			}
			return `](${SITE}/docs/${slug}/${anchor})`;
		}
	);
	return { rewritten, unresolved };
}

function extractTitle(markdown) {
	const m = markdown.match(/^#\s+(.+)$/m);
	return m ? m[1].trim() : null;
}

/**
 * Renders one top-level marked token to its own HTML, wrapped as the matching Gutenberg
 * block comment - static blocks (WordPress stores a static block's fully-rendered HTML
 * directly in post_content), not editor-reconstructible ones. A nested list's own inner
 * <ul>/<li> markup is left as plain HTML inside the single outer wp:list wrapper rather
 * than block-ified item by item - core's block renderer displays it correctly either way,
 * and hand-replicating Gutenberg's own inner-block markers for arbitrary nesting depth
 * would add real fragility for a purely cosmetic (editor-only) difference.
 */
function renderBlock(token) {
	if (token.type === 'heading') {
		const inline = marked.parseInline(token.text);
		const level = token.depth;
		return `<!-- wp:heading {"level":${level}} -->\n<h${level} class="wp-block-heading">${inline}</h${level}>\n<!-- /wp:heading -->`;
	}
	if (token.type === 'paragraph') {
		const html = marked.parser([token]).trim();
		return `<!-- wp:paragraph -->\n${html}\n<!-- /wp:paragraph -->`;
	}
	if (token.type === 'list') {
		const html = marked.parser([token]).trim();
		const tag = token.ordered ? 'ol' : 'ul';
		const withClass = html.replace(new RegExp(`^<${tag}>`), `<${tag} class="wp-block-list">`);
		return `<!-- wp:list${token.ordered ? ' {"ordered":true}' : ''} -->\n${withClass}\n<!-- /wp:list -->`;
	}
	if (token.type === 'table') {
		const html = marked.parser([token]).trim();
		return `<!-- wp:table -->\n<figure class="wp-block-table">${html}</figure>\n<!-- /wp:table -->`;
	}
	if (token.type === 'space') {
		return null;
	}
	// Defensive fallback for anything a source survey of the 55 files never found (a code
	// fence, blockquote, image, or raw HTML) - never silently drop real content.
	const html = marked.parser([token]).trim();
	return `<!-- wp:html -->\n${html}\n<!-- /wp:html -->`;
}

function convert(markdown) {
	// The leading H1 is kept - the existing 4 guides' own converted content repeats their
	// H1 as the post body's first heading too (post_title carries it a second time in the
	// page chrome), so this matches established precedent rather than being redundant.
	const tokens = marked.lexer(markdown);
	return tokens.map(renderBlock).filter(Boolean).join('\n\n');
}

function main() {
	const files = fs.readdirSync(HELP_DIR).filter((f) => f.endsWith('.md'));
	const allSlugs = new Set(files.map((f) => path.basename(f, '.md')));

	const docs = [];
	const allUnresolved = [];

	for (const file of files) {
		const slug = path.basename(file, '.md');
		const raw = fs.readFileSync(path.join(HELP_DIR, file), 'utf8');
		const title = extractTitle(raw);
		const { rewritten, unresolved } = rewriteLinks(raw, allSlugs);
		if (unresolved.length) {
			allUnresolved.push({ file, unresolved });
		}
		docs.push({ slug, title, content: convert(rewritten) });
	}

	// The four top-level guides, from one directory up, under their own live slugs.
	for (const [ base, slug ] of Object.entries(GUIDE_SLUGS)) {
		const file = `${base}.md`;
		const raw = fs.readFileSync(path.join(HELP_DIR, '..', file), 'utf8');
		const { rewritten, unresolved } = rewriteLinks(raw, allSlugs, true);
		if (unresolved.length) {
			allUnresolved.push({ file, unresolved });
		}
		docs.push({ slug, title: extractTitle(raw), content: convert(rewritten) });
	}

	fs.mkdirSync(path.dirname(OUT), { recursive: true });
	fs.writeFileSync(OUT, JSON.stringify(docs, null, 2));

	console.log(`Converted ${docs.length} files -> ${OUT}`);
	if (allUnresolved.length) {
		console.log('\nUNRESOLVED LINKS (left untouched - check these by hand):');
		for (const { file, unresolved } of allUnresolved) {
			console.log(`  ${file}:`, unresolved);
		}
		process.exitCode = 1;
	} else {
		console.log('All internal links resolved.');
	}
}

main();
