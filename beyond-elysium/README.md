# Beyond Elysium

Character management for Mind's Eye Theatre LARP, built as a WordPress plugin for [One World by Night](https://www.owbn.net/).

**Status:** `v0.99.2`, live on two production OWBN chronicles (kony-sabbat.net, Boston By Night). Working through a defined set of remaining items toward a 1.0 release.

## What It Does

Beyond Elysium handles character sheets, player submissions, and Storyteller approval workflows for tabletop LARP games. It supports every creature type in the World of Darkness (Vampire, Werewolf, Mage, Changeling, Wraith, and others) without any creature-specific code — a new creature type is configuration, not a rewrite.

## How It Works

The plugin uses a schema-driven "engine pattern." Character sheets are assembled from reusable building blocks — trait lists, tiered powers, resource pools, and identity fields. Adding a new creature type means configuring blocks in the admin UI, not writing code.

- **Players** create and edit characters, submit changes for review, and track experience
- **Storytellers** review and approve submissions, run plots and rumors, and manage the roster
- **Schema blocks** define what a character sheet looks like for each game type
- **Creature stacks** assemble blocks into a complete sheet definition
- **Characters** are instances of a stack filled with player data
- **Chronicles** are isolated from one another — a Storyteller reaches their own chronicle's characters, plots and changes, and no other's

## Permissions

Beyond Elysium runs standalone on plain, game-scoped WordPress capabilities, and integrates with [accessSchema](https://github.com/One-World-By-Night/accessSchema) for chronicle-scoped role paths where the wider OWBN plugin stack is present. Neither is required by the other: a chronicle can run this plugin on a bare WordPress install with no OWBN plugins at all, and every permission check falls back cleanly if accessSchema is absent, unreachable, or declines to answer.

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
| Chronicle-scoped authorization | Complete |
| Notifications and game dashboard | Complete |
| Internationalization, accessibility, documentation | Complete |
| Release-readiness checklist — packaging, uninstall handling, security review | Complete |
| Blood magic paths, downtime and rumor systems | Complete |

## What's Next

Toward 1.0: a defined set of remaining items, each already scoped with its own design —
letting characters travel between chronicles, signed/printable sheets, a background-use
ledger and downtime settings, guided setup for a new chronicle, and a few others.

The current phase is integration and polish — the last one before a 1.0 release candidate. Chronicle-scoped authorization, deferred decisions, notifications, and the game dashboard are all done and live. What remains:

1. **Internationalization scaffolding** — every user-facing string translatable, no partial translation shipped
2. **Accessibility** — keyboard navigation, ARIA labeling, contrast, phone-width layouts, print styles for rosters and the boon ledger
3. **Documentation** — a REST API reference, a Storyteller guide, an admin guide, and Elementor widget descriptions
4. **Release readiness** — clean artifact packaging, upgrade-path and rollback verification, a security review

Full Grapevine 3.01 import is complete and verified against real chronicle data: binary and XML exchange files, and full game files with create-or-merge into an existing chronicle.

## Development

```bash
composer install              # PSR-4 autoloader + dev tooling
npm install && npm run build  # React/TypeScript frontend
./bin/verify                  # lint, static analysis, tests, build
./bin/dist                    # deployable artifact
```

Requires PHP 8.2+, WordPress 7.x and MySQL 8.4, matching OWBN production.

## License

GPL-2.0-or-later
