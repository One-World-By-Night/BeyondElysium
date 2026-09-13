# BeyondElysium

Character management for Mind's Eye Theatre LARP, built as a WordPress plugin for [One World by Night](https://www.owbn.net/).

**Status:** `v0.99.11` is released and running in production chronicles. The character engine, editor, Storyteller tools, world data, Grapevine import, configurable approval rules, and blood magic paths are all built; renaming a chronicle's slug is a safe, cascading operation; a chronicle's downtime actions have both a real settings screen and a use-by-use ledger; the query engine now searches items, locations, and rotes, not just characters; a real Grapevine exchange-file import bug (single-dot traits silently zeroed) is fixed; a character can be exported to a real Grapevine `.gex` XML file with a public verification code so another chronicle can confirm it's genuine and still current; and a character can now travel between chronicles outright, verified end to end against two real, separate production installations. See [Installation](#installation) to run it, or the [Roadmap](#roadmap) for what's left before 1.0.

## What It Does

BeyondElysium handles character sheets, player submissions, and Storyteller approval workflows for tabletop LARP chronicles. It ships full character sheets for every World of Darkness creature type — Vampire, Werewolf, Mage, Changeling, Wraith, Demon, Mummy, Kuei-Jin, and more — with zero creature-specific code. A new creature type is a configuration entry, not a rewrite.

Beyond the sheet itself:

- **Character editor** with a full submit/review/approval workflow, XP tracking, and autosave, so a crashed browser or an accidental close doesn't cost a player their draft.
- **Storyteller Toolkit** for plots, player actions, and rumors, written by hand or generated straight from chronicle data.
- **Query engine and roster statistics** — a real answer out of a chronicle's data, faster than scrolling a spreadsheet.
- **World objects** — items, locations, rotes, and a full Harpy/boon ledger.
- **The full MET-mechanics catalog** — disciplines, gifts, merits, flaws, backgrounds, abilities, and more — reconciled against real published data.
- **Grapevine 3.01 import**, for binary and XML exchange files and full game files (`.gv3`), with chronicle create-or-merge, duplicate detection, and a review wizard for anything the importer can't resolve on its own.
- **Per-character sheet customization**, portraits, and print layout.
- **Notifications and a game dashboard.** Players hear when a submission is approved or rejected; Storytellers get an aggregate view of the chronicle, players get their own.
- **Internationalization scaffolding and an accessibility pass**, including ARIA labeling on key interactive components.

## Installation

Download `beyond-elysium-0.99.11.zip` from [Releases](https://github.com/One-World-By-Night/BeyondElysium/releases) and install it through **Plugins → Add New → Upload Plugin**.

That zip is the built, ready-to-run plugin. The `beyond-elysium/` folder in this repository is its *source* — `build/` and `vendor/` are generated rather than committed, so copying that folder straight into `wp-content/plugins/` will not work. To build it yourself:

```bash
composer install
npm install && npm run build
./bin/dist          # writes dist/beyond-elysium-<version>.zip
./bin/verify        # lint, static analysis, and the test suite
```

Requires PHP 8.2, WordPress 6.0 or newer, and [Elementor](https://wordpress.org/plugins/elementor/) — the page builder this plugin registers its widgets into. On WordPress 6.5+ the dependency is declared in the plugin header, so WordPress will offer to install Elementor for you and will not activate this plugin without it. Tested against PHP 8.2.33, MySQL 8.4.6, and WordPress 7.1.

## How It Works

The plugin uses a schema-driven "engine pattern." Character sheets are assembled from reusable building blocks — trait lists, tiered powers, resource pools, and identity fields. Adding a new creature type means configuring blocks in the admin UI, not writing code.

- **Players** create and edit characters, submit changes for review, and track experience.
- **Storytellers** review and approve submissions, run plots and rumors, and manage the roster.
- **Schema blocks** define what a character sheet looks like for each game type.
- **Creature stacks** assemble blocks into a complete sheet definition.
- **Characters** are instances of a stack, filled with player data.

## Permissions

BeyondElysium runs standalone on plain WordPress capabilities, and integrates with [accessSchema](https://github.com/One-World-By-Night/accessSchema) for chronicle-scoped role paths where the wider OWBN plugin stack is present. Neither depends on the other: a chronicle can run this plugin on a bare WordPress install with no other OWBN plugins at all. If accessSchema is off, unreachable, or doesn't cover a given member, permissions fall back to the plugin's own chronicle-membership table automatically — nothing breaks, and nothing needs configuring differently.

Every chronicle gets four roles: **HST** and **AST** (full chronicle management, characters through imports), **Narrator** (plots, player actions, and the roster queries that support them), and **Player** (their own characters, their own changes, nothing else). Full breakdown in the [Storyteller Guide](Documents/st-guide.md#roles-reference).

## Tech Stack

- PHP 8.2 / WordPress 7.x / MySQL 8.4 backend with custom database tables
- React / TypeScript frontend with Elementor widget integration
- REST API (`be/v1` namespace) for all operations
- Optional accessSchema client for chronicle-scoped RBAC

## Documentation

BeyondElysium ships in-app documentation, viewable inside the plugin's own admin screen. The same files, unedited, are collected in [`Documents/`](Documents/) here:

- [Storyteller Guide](Documents/st-guide.md) — running a chronicle: games, schema, characters, the approval queue, imports, notifications, the dashboard, plots.
- [Admin Guide](Documents/admin-guide.md) — schema blocks, creature stacks, adding a new creature type without code, templates.
- [Player Guide](Documents/player-guide.md) — creating and editing a character, submitting changes, experience, plots.
- [REST API Reference](Documents/rest-api.md) — every route, its required capability, and what it does.

## Build Progress

| Area | Status |
|---|---|
| Foundation, games, schema blocks, creature stacks | Complete |
| Characters, change/approval workflow, XP tracking | Complete |
| Character sheet templates and rendering | Complete |
| Character editor, with autosave-draft protection | Complete |
| Storyteller Toolkit — plots, actions, and rumors | Complete |
| Query engine and roster statistics | Complete |
| World objects — items, locations, rotes, boons | Complete |
| MET-mechanics catalog, reconciled against published data | Complete |
| Grapevine exchange-file import — binary and XML, with duplicate detection and a review wizard | Complete |
| Grapevine full game-file (`.gv3`) import and chronicle merge | Complete |
| Per-character sheet customization, portraits, print layout | Complete |
| Chronicle-scoped authorization — HST/AST/Narrator/Player, standalone or accessSchema-integrated | Complete |
| Notifications and game dashboard | Complete |
| Internationalization scaffolding and accessibility pass | Complete |
| In-app documentation — Storyteller, Admin, and Player guides, REST API reference | Complete |
| Character-sheet defect pass, WCAG AA contrast, phone-width layout, print styles | Complete |
| Configurable purchase-approval rules, credits and in-memoriam, NPC forms | Complete |
| Release readiness — packaging, upgrade and uninstall paths, public release | Complete |
| Blood magic paths, Elder-tier discipline pricing, downtime action allocation, rumor delivery | Complete |
| Chronicle rename — slug changes cascade transactionally to characters, schema-block forks, and page/widget references instead of orphaning them | Complete |
| Background-use ledger and Action & Rumor settings — what a downtime action grants, and a use-by-use record of what a character spent it on | Complete |
| Query engine extended to items, locations, and rotes — not just characters | Complete |
| Grapevine exchange-file import fix (single-dot traits no longer zero on import) and the field-order groundwork for character export | Complete |
| Export a character to a real Grapevine `.gex` XML file, from the character sheet | Complete |
| A public verification endpoint for an exported character, with a human-facing check page | Complete |
| Chronicle-to-chronicle character transfer, online and offline, with the travelling/visiting badge | Complete |

## Roadmap

`v0.99.0` was the first public release. The path to 1.0 is now a closed scope, each item already given a full architecture pass before any of it is built: letting a character travel between chronicles (export, verification, and transfer itself are done; a signed, verifiable PDF and a binary export option are what's left), an itemized XP audit for a sheet, the rest of a phone-first pass on the character sheet (the read-only sheet's own phone-width layout bug is already fixed), a guided first-run setup for a new chronicle, and expanding the Rote catalog from a published compendium.

**Bylaw-driven approval data.** Chronicles configure their own approval rules through the admin UI today. Importing OWBN's published Character Regulation Bylaws directly — 1,032 clauses — is researched and specified but deliberately not built: most clauses restrict character *concepts* rather than named traits, so it needs a review workflow rather than a straight import.

**Held for after 1.0, by choice.** Character sharing between players, coordinator-tier approval enforcement, and autosave-draft protection for plot and rumor forms are each specified and intentionally deferred.

## License

GPL-2.0-or-later
