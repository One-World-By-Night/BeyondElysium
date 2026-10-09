# Help pages on beyondelysium.com

The plugin's help pages and four guides also live on beyondelysium.com as BetterDocs posts, in English and Portuguese (Brazil), each page with a screenshot in both languages. These files build what goes there.

## The steps

1. `node bin/capture-help-screenshots.js` takes every screenshot in `beyond-elysium/docs/help/screenshots.json` on the local development site, once in English and once with the site in Portuguese (Brazil), into `dist/help-screenshots/{en,pt_BR}/{page}-{n}.webp`. The demo chronicle `be-demo` must be set up locally; the script signs in as the `admin` and `pw_player` accounts and puts the demo's NPC casting on its next upcoming session. Environment: `BE_SITE`, `BE_WP_PATH`, `BE_WP_CLI`, `BE_PASSWORD_ADMIN`, `BE_PASSWORD_PLAYER`. Options: `--lang en|pt_BR`, `--only page,page`, `--out folder`.
2. `node bin/sync-help-docs.js` turns each page and guide into block HTML and pairs it with its Portuguese twin, writing `dist/help-docs.json`. It exits with an error when a link has no target, a screenshot follows no heading, or one English string has two Portuguese translations. A page that does not pair stays English and is listed.
3. `bin/push-help-docs.php`, run with `wp eval-file` on the website, writes the pages, uploads the screenshots and writes the Portuguese strings into TranslatePress. It reads `/tmp/help-docs.json` and `/tmp/help-screenshots/`. Run again, it changes nothing. `BE_HELP_DRY_RUN=1` writes nothing, `BE_HELP_ONLY=slug,slug` pushes those pages only, and `BE_HELP_PREFIX=trial-` puts the pages under a slug prefix and out of the Help category, to rehearse; `BE_HELP_REMOVE=1` with a prefix puts the rehearsal in the trash and deletes its screenshots.
4. `node bin/check-help-pages.js` reads the live pages in both languages and checks them against `dist/help-docs.json`. It only reads. Options: `--site`, `--only`, `--prefix`, `--file`, `--header`.

`bin/setup-translatepress.php` sets TranslatePress up once on the website: Portuguese (Brazil) under `/pt/`, the language switcher hidden, the Developer add-on's SEO Pack on (it translates image alt text) and the licence key from the file named in `BE_TP_LICENSE_FILE`.

## How a page is paired

The English and Portuguese Markdown go through the same converter and are compared block by block (`pairing.js`). Each paragraph, list item and table cell is one string; when it holds a link, emphasis or code it is one block string, matched to the page by its words. A heading is paired piece by piece, because the website adds its own link inside every heading. A page whose blocks do not line up, or whose links point elsewhere, is refused whole.

TranslatePress keeps one translation for each English string, so the same English words must carry the same Portuguese everywhere. `sync-help-docs.js` lists every string that does not.

Every link to a heading becomes a link to the id BetterDocs gives that heading, `{n}-toc-title`, where `n` counts the headings of the page from zero (`convert.js`).
