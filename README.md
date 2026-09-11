# BeyondElysium

Character management for Mind's Eye Theatre LARP, built as a WordPress plugin for [One World by Night](https://www.owbn.net/).

**Status:** Heading toward public beta. The character engine, editor, Storyteller tools, world data, and Grapevine import are built and running in production. What's left is polish, a few configurable approval rules, and the release itself — see [Roadmap](#roadmap).

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
| Character-sheet defect pass, contrast/mobile/print polish | In progress |
| Configurable purchase-approval rules, in-memoriam page, NPC forms | Planned |
| Release readiness and public beta | Planned |

## Roadmap

Three things stand between here and 1.0.

**Finishing polish.** A handful of defects turned up in live playtesting on the character sheet, a WCAG AA contrast pass, a layout pass for phone-width screens, and print styles for the roster and boon ledger.

**Feature rounding.** Granular, configurable purchase-approval rules, so a chronicle can set its own house rules on what needs Storyteller or coordinator-level review, plus an in-memoriam and credits page and NPC-specific character forms.

**Release readiness.** Packaging, upgrade and rollback paths, and the public source release itself — the repository you're reading is that process.

This repository is a placeholder for the released plugin source until that last step. It doesn't contain code yet; active development happens elsewhere until the project reaches its public-beta milestone, at which point this repository becomes the released source.

## License

GPL-2.0-or-later
