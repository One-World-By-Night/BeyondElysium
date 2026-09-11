# REST API Reference

All routes are under the `be/v1` namespace — e.g. `/wp-json/be/v1/games`. Every write route
requires a logged-in WordPress user; every route's permission is enforced server-side
regardless of what any client UI shows or hides.

`{game_slug}` scopes a route to one chronicle. A request naming a game slug the caller has
no relationship to returns `404` (not `403`), so a chronicle's existence is never leaked to
someone outside it.

**Manually maintained against the controllers, not auto-generated** — the "generated so it
cannot drift" tooling this ideally deserves (Step 9b, workflow-0.9.md) was not built this
pass; this reference was produced by reading every `register_routes()` method directly
and will need re-checking by hand if a route changes. Worth building the real generator as
a follow-up.

## Authorization

Every route below is gated by exactly one WordPress capability (or, where noted, any-of or
all-of several), checked chronicle-scoped: `Authorization::check_request()` tries
accessSchema first when enabled and reachable, then falls back to the caller's plain
capability plus their row in `be_game_members` for that chronicle. See the
[Storyteller Guide](st-guide.md#1-creating-a-game) for what each role typically maps to.

## Games

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/games` | `be_view_characters` | List games |
| POST | `/games` | `be_manage_games` | Create a game |
| GET | `/games/{slug}` | `be_view_characters` | Get one game |
| PUT | `/games/{slug}` | `be_manage_games` | Update name, slug, description, settings, `asc_role_path`, `notifications_enabled` |
| DELETE | `/games/{slug}` | `be_manage_games` | Delete the game row only — does not cascade its content |

## Schema Blocks

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/schema-blocks` | `be_view_characters` | List blocks |
| POST | `/schema-blocks` | `be_manage_schemas` | Create a block |
| GET | `/schema-blocks/{slug}` | `be_view_characters` | Get one block; `?game_slug=` substitutes a chronicle's own fork if it has one |
| PUT | `/schema-blocks/{slug}` | `be_manage_schemas` | Update, or auto-fork per-game if `game_slug` is present and no fork exists yet |
| DELETE | `/schema-blocks/{slug}` | `be_manage_schemas` | Delete |

## Creature Stacks

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/creature-stacks` | `be_view_characters` | List stacks |
| POST | `/creature-stacks` | `be_manage_schemas` | Create a stack |
| GET | `/creature-stacks/{slug}` | `be_view_characters` | Get one stack, resolved (blocks assembled) |
| PUT | `/creature-stacks/{slug}` | `be_manage_schemas` | Update |
| DELETE | `/creature-stacks/{slug}` | `be_manage_schemas` | Delete |

## Characters

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters` | `be_view_characters` | List, with roster filters |
| POST | `/{game_slug}/characters` | `be_edit_own_characters` | Create — bootstrap-exempt: a player with no prior chronicle relationship can still create their first character here |
| GET | `/{game_slug}/my/characters` | `be_view_characters` | Only the caller's own characters, ST-only-text stripped the same as every other non-manager read path |
| GET | `/{game_slug}/characters/{id}` | `be_view_characters` | One character; a non-manager may only view their own |
| PUT | `/{game_slug}/characters/{id}` | `be_edit_own_characters` | Update |
| DELETE | `/{game_slug}/characters/{id}` | `be_manage_characters` | Delete, cascading its changes/snapshots/sheet style |
| GET | `/wp-users` | `be_manage_characters` | Not game-scoped — searches WordPress accounts for the "assign a player" picker |

## Changes

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/changes` | `be_view_characters` | One character's change history |
| POST | `/{game_slug}/characters/{character_id}/changes` | `be_edit_own_characters` | Submit a change — auto-approves and applies immediately if the block's approval rules allow it |
| GET | `/{game_slug}/changes` | `be_manage_characters` | The approval queue — every pending (or filtered) change across the whole chronicle |
| POST | `/{game_slug}/changes/batch-approve` | `be_manage_characters` | Approve several changes in one call |
| GET | `/{game_slug}/my/changes` | `be_view_characters` | Only the caller's own pending changes, across every character they own |
| POST | `/{game_slug}/characters/{character_id}/preview-changes` | `be_edit_own_characters` | Price a set of proposed changes without submitting them |
| PUT | `/{game_slug}/changes/{id}` | `be_manage_characters` | Approve or reject; sends the submitting player a notification email unless they or the chronicle opted out |

## Snapshots

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/snapshots` | `be_manage_characters` | List a character's saved snapshots |
| POST | `/{game_slug}/characters/{character_id}/snapshots` | `be_manage_characters` | Create one manually (also created automatically every 25 approved changes) |
| GET | `/{game_slug}/characters/{character_id}/snapshots/{id}` | `be_manage_characters` | One snapshot's full sheet state |

## Sheet Style

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/sheet-style` | `be_view_characters` | Get a character's sheet customization |
| PUT | `/{game_slug}/characters/{character_id}/sheet-style` | `be_customize_sheet` | Update — a separate capability from `be_manage_characters`, grantable per-user |
| DELETE | `/{game_slug}/characters/{character_id}/sheet-style` | `be_customize_sheet` | Reset to defaults |

## Experience

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/experience/bulk-award` | `be_manage_characters` | Award XP to several characters at once |

## Query Fields

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/query-fields` | `be_view_characters` | The field catalog the query builder offers |

## Templates

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/templates` | `be_view_characters` | List global (base) templates |
| POST | `/templates` | `be_manage_templates` | Create a global template |
| GET | `/templates/{id}` | `be_view_characters` | One template |
| PUT | `/templates/{id}` | `be_manage_templates` | Update |
| DELETE | `/templates/{id}` | `be_manage_templates` | Delete |
| GET | `/{game_slug}/templates/resolve` | `be_view_characters` | Resolve the effective layout for a creature stack in this chronicle (game fork if one exists, else the global template) |
| GET | `/{game_slug}/templates` | `be_view_characters` | List this chronicle's own template forks |
| POST | `/{game_slug}/templates` | `be_manage_templates` | Fork a template for this chronicle |
| PUT | `/{game_slug}/templates/{id}` | `be_manage_templates` | Update a chronicle's own fork |
| DELETE | `/{game_slug}/templates/{id}` | `be_manage_templates` | Delete a chronicle's own fork |

## Plots

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/plots` | `be_view_characters` | List plots |
| POST | `/{game_slug}/plots` | `be_submit_actions` OR `be_manage_plots` | Create — a player can start a plot thread via an action, an ST can create one directly |
| GET | `/{game_slug}/my/plots` | `be_view_characters` | Only the caller's own plots — theirs by connection, or reachable via `target_query` |
| POST | `/{game_slug}/plots/allocate-actions` | `be_manage_plots` | Allocate a round's action slots |
| POST | `/{game_slug}/plots/generate-rumors` | `be_manage_plots` | Generate and distribute rumors via a saved query |
| GET | `/{game_slug}/plots/{id}` | `be_view_characters` | One plot with its entry thread |
| PUT | `/{game_slug}/plots/{id}` | `be_manage_plots` | Update |
| DELETE | `/{game_slug}/plots/{id}` | `be_manage_plots` | Delete, cascading its entries and connections |

## Entries (plot responses/actions)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/plots/{plot_id}/entries` | `be_view_characters` | List a plot's entries |
| POST | `/{game_slug}/plots/{plot_id}/entries` | `be_submit_actions` OR `be_manage_plots` | Add an entry |
| PUT | `/{game_slug}/entries/{id}` | `be_submit_actions` OR `be_manage_plots` | Update |
| DELETE | `/{game_slug}/entries/{id}` | `be_manage_plots` | Delete |

## Connections

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/connections` | `be_view_characters` | List connections between characters/objects |
| POST | `/{game_slug}/connections` | `be_manage_connections` | Create |
| PUT | `/{game_slug}/connections/{id}` | `be_manage_connections` | Update |
| DELETE | `/{game_slug}/connections/{id}` | `be_manage_connections` | Delete |

## Query

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/query` | `be_run_queries` | Run an ad-hoc query against the roster |
| POST | `/{game_slug}/statistics` | `be_run_queries` | Run one of the five built-in roster statistics |
| GET | `/{game_slug}/queries` | `be_run_queries` | List saved queries |
| POST | `/{game_slug}/queries` | `be_run_queries` | Save a query |
| PUT | `/{game_slug}/queries/{id}` | `be_run_queries` | Update a saved query |
| DELETE | `/{game_slug}/queries/{id}` | `be_run_queries` | Delete a saved query |

## World Objects (items, locations, rotes)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/world-objects` | `be_view_characters` | List, filterable by type |
| POST | `/{game_slug}/world-objects` | `be_manage_world_objects` | Create |
| GET | `/{game_slug}/world-objects/{id}` | `be_view_characters` | Get one |
| PUT | `/{game_slug}/world-objects/{id}` | `be_manage_world_objects` | Update |
| DELETE | `/{game_slug}/world-objects/{id}` | `be_manage_world_objects` | Delete |

## Boons

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/boons` | `be_view_characters` | The ledger — whole-game, or one character's owed/owed-to-them split |
| POST | `/{game_slug}/boons` | `be_manage_world_objects` | Record a new boon |
| PUT | `/{game_slug}/boons/{id}/repay` | `be_manage_world_objects` | Mark a boon repaid — a symmetric transactional update |

## Import (exchange files — `.gex`, character or game-scoped)

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/import/parse` | `be_import` | Upload and parse; returns a preview, stores the job for one hour |
| GET | `/{game_slug}/import/{job_id}` | `be_import` | Re-fetch a stored job's current state |
| POST | `/{game_slug}/import/{job_id}/commit` | `be_import` | Commit — refuses while anything is unresolved/flagged; transactional; safe to re-POST |

## Game Import (full `.gv3` game files — not game-scoped, since a new chronicle may not exist yet)

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/import/game/parse` | `be_import` AND `be_manage_games` | Upload and parse a full game file |
| GET | `/import/game/{job_id}` | `be_import` AND `be_manage_games` | Re-fetch a stored job |
| POST | `/import/game/{job_id}/commit` | `be_import` AND `be_manage_games` | Create a new chronicle, or merge into an existing one, from the file's contents |

## Chronicle Members

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/members` | `be_manage_games` | List this chronicle's members and roles |
| POST | `/{game_slug}/members` | `be_manage_games` | Add a member, or change an existing member's role |
| DELETE | `/{game_slug}/members/{wp_user_id}` | `be_manage_games` | Remove a member's chronicle-scoped access |

## Authorization Settings

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/authorization-settings` | `be_manage_games` | Whether accessSchema checking is on, and whether a real accessSchema client is detected |
| PUT | `/authorization-settings` | `be_manage_games` | Turn accessSchema checking on or off, site-wide |

## Game Stats

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/stats` | `be_manage_characters` | The ST dashboard's aggregate numbers — character counts, pending changes, active plots, recent activity. Cached one minute; a review action invalidates the cache for its own chronicle immediately |
