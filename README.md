# BeyondElysium

Character management for Mind's Eye Theatre LARP, built as a WordPress plugin for [One World by Night](https://www.owbn.net/).

**Status:** In active development, running on a private test chronicle. Not yet released for public beta.

## What It Does

BeyondElysium handles character sheets, player submissions, and Storyteller approval workflows for tabletop LARP games. It supports every creature type in the World of Darkness (Vampire, Werewolf, Mage, Changeling, Wraith, and others) without any creature-specific code — a new creature type is configuration, not a rewrite.

## How It Works

The plugin uses a schema-driven "engine pattern." Character sheets are assembled from reusable building blocks — trait lists, tiered powers, resource pools, and identity fields. Adding a new creature type means configuring blocks in the admin UI, not writing code.

- **Players** create and edit characters, submit changes for review, and track experience
- **Storytellers** review and approve submissions, run plots and rumors, and manage the roster
- **Schema blocks** define what a character sheet looks like for each game type
- **Creature stacks** assemble blocks into a complete sheet definition
- **Characters** are instances of a stack filled with player data

## Permissions

BeyondElysium runs standalone on plain WordPress capabilities, and integrates with [accessSchema](https://github.com/One-World-By-Night/accessSchema) for chronicle-scoped role paths where the wider OWBN plugin stack is present. Neither is required by the other: a chronicle can run this plugin on a bare WordPress install with no OWBN plugins at all.

Chronicle-scoped permissions are the focus of the release phase currently in flight — see [What's Next](#whats-next).

## Tech Stack

- PHP 8.2 / WordPress 7.x / MySQL 8.4 backend with custom database tables
- React / TypeScript frontend with Elementor widget integration
- REST API (`be/v1` namespace) for all operations
- Optional accessSchema client for chronicle-scoped RBAC

## Build Progress

| Area | Status |
|---|---|
| Foundation, games, schema blocks, creature stacks | Complete |
| Characters, change/approval workflow, XP tracking | Complete |
| Character sheet templates and rendering | Complete |
| Character editor | Complete |
| Storyteller Toolkit — plots, actions, and rumors | Complete |
| Query engine and roster statistics | Complete |
| World objects — items, locations, rotes, boons | Complete |
| MET-mechanics catalog import (disciplines, gifts, merits, and more) | Complete |
| Grapevine exchange-file import — binary and XML, with duplicate detection and a review wizard | Complete |
| Grapevine full game-file (`.gv3`) import and chronicle merge | Complete |
| Per-character sheet customization, portraits, print layout | Complete |
| Permissions audit, performance pass, error-handling audit | Complete |
| Chronicle-scoped authorization | In progress |
| Notifications and game dashboard | Planned |
| Internationalization, accessibility, documentation | Planned |
| Release readiness and public beta | Planned |

## What's Next

The current phase is integration and polish — the last one before a 1.0 release candidate. Its permissions, performance and error-handling passes are done; the remainder is sequenced as:

1. **Character-sheet defect pass** — issues found in live play on the sheet and editor
2. **Chronicle-scoped authorization** — a Storyteller reaches their own chronicle and no other, in both permission modes
3. **Deferred decisions** — every carried-forward question from earlier phases answered and recorded
4. **Notifications and the game dashboard**
5. **Internationalization scaffolding, accessibility, and documentation**
6. **Release readiness** — packaging, upgrade and rollback paths, and the public-beta source cut

Full Grapevine 3.01 import is complete and verified against real chronicle data: binary and XML exchange files, and full game files with create-or-merge into an existing chronicle.

This repository is a placeholder for the released plugin source. It does not yet contain
code — active development happens elsewhere until the project reaches a public-beta
milestone, at which point this repository will hold the released source.

## License

GPL-2.0-or-later
