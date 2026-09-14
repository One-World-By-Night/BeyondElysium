# REST API Reference

All routes are under the `be/v1` namespace — e.g. `/wp-json/be/v1/games`. Every write route
requires a logged-in WordPress user; every route's permission is enforced server-side
regardless of what any client UI shows or hides.

`{game_slug}` scopes a route to one chronicle. A request naming a game slug the caller has
no relationship to returns `404` (not `403`), so a chronicle's existence is never leaked to
someone outside it.

**Manually maintained against the controllers, not auto-generated** — the "generated so it
cannot drift" tooling this ideally deserves (Step 9b, workflow-0.9.md) was not built this
pass. A full re-audit against every `register_routes()` method in `includes/REST/` (2026-09-13)
found this reference had drifted well past a single missed route — eleven whole controllers
undocumented and two capabilities stated backwards - proof this really does need re-checking
by hand after every release that touches a controller, not just when a route "feels" new.
Worth building the real generator as a follow-up; until then, treat a controller you don't
see a section for here as a sign this doc is behind, not a sign the controller doesn't exist.

## Authorization

Every route below is gated by exactly one WordPress capability (or, where noted, any-of or
all-of several), checked chronicle-scoped: `Authorization::check_request()` tries
accessSchema first when enabled and reachable, then falls back to the caller's plain
capability plus their row in `be_game_members` for that chronicle. See the
[Storyteller Guide](st-guide.md#1-creating-a-game) for what each role typically maps to.

## Games

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/games` | `be_view_characters` | List *every* game on the install — correct for a staff picker, never safe to back a front-end chronicle switcher with (see `/my/games` below) |
| POST | `/games` | `be_manage_games` | Create a game |
| GET | `/games/{slug}` | `be_view_characters` | Get one game |
| PUT | `/games/{slug}` | `be_manage_games` | Update name, slug, description, settings, `asc_role_path`, `notifications_enabled` |
| DELETE | `/games/{slug}` | `be_manage_games` | Delete the game row only — does not cascade its content |
| GET | `/my/games` | `be_view_characters` | Only the chronicles the caller actually holds a `be_game_members` row in, each with the role held there (`{slug, name, role}`) — the real data source for the My Chronicle / Storyteller Toolkit chronicle switcher |
| GET | `/{game_slug}/my/capabilities` | logged in (any) | What the caller can actually do *in this one chronicle* — `be_manage_characters`, `be_manage_plots`, `be_manage_schemas`, `be_manage_connections`, `be_manage_boons`, each resolved through the same chronicle-scoped `Authorization::check_request()` every write route uses, not the site-wide snapshot every page load carries. An unresolvable `game_slug` or a caller with no relationship to this chronicle still returns `200` with every flag `false`, never an error — a switcher renders "no access here" rather than failing |

## Schema Blocks

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/schema-blocks` | `be_view_characters` | List blocks |
| POST | `/schema-blocks` | `be_manage_schemas` | Create a block |
| GET | `/schema-blocks/{slug}` | `be_view_characters` | Get one block; `?game_slug=` substitutes a chronicle's own fork if it has one |
| PUT | `/schema-blocks/{slug}` | `be_manage_schemas` | Update, or auto-fork per-game if `?game_slug=` is present and no fork exists yet. Any `description` object (`{reference, description, source}`, each HTML) on an item/power/level is sanitized server-side — formatting/lists/tables survive, images and scripts don't — regardless of what the caller submits. |
| DELETE | `/schema-blocks/{slug}` | `be_manage_schemas` | Delete |
| POST | `/{game_slug}/schema-blocks` | `be_manage_schemas` | Explicitly fork a block for this chronicle (the same auto-fork the global `PUT` above performs implicitly via `?game_slug=`, as its own dedicated route) |
| PUT | `/{game_slug}/schema-blocks/{slug}` | `be_manage_schemas` | Update this chronicle's own fork |
| DELETE | `/{game_slug}/schema-blocks/{slug}` | `be_manage_schemas` | Delete this chronicle's own fork, reverting to the global block |

An item, tiered-power level/family, resource pool, or identity field's `definition` entry may
also carry an approval schedule beyond its flat `approval`: `approval_by_value` (trait_list
items and resource_pool pools — an array of `{from, to, approval, reason?}` ranges resolved
against the resulting value, a pool's schedule checked against its permanent rating only), a
plain `approval` on a tiered_power level (each level is already its own row), or
`approval_by_option` (identity_field — `{optionValue: {approval, reason?}}`, every value in a
multiselect checked, strictest wins). See the
[Admin Guide](admin-guide.md#approval-by-value-and-approval-by-option) for the editing UI.

## Approval Rules

The editing surface for the schedules described just above — one rule per catalog
item/power/level/value-range/option that carries an approval override or a reason, across
this chronicle's own trait_list, tiered_power, resource_pool, and identity_field blocks. A
rule's `target_type` is one of `item`, `item_range`, `power`, `level`, `pool_range`, or
`field_option`; `item_range`/`pool_range` address one `{from, to}` entry in the target's own
`approval_by_value` array (both fields required on create/update), `field_option` addresses
one option in the target field's `approval_by_option` map (`option` required), and `level`
addresses one power level by its `level` number. A rule's response/list shape carries this as
`extra` (`[from, to]`, the option string, or `null`) alongside the existing `level` field.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/approval-rules` | `be_manage_approval_rules` | List every rule currently set, this chronicle's own fork where one exists, the global block otherwise |
| POST | `/{game_slug}/approval-rules` | `be_manage_approval_rules` | Set (create or overwrite) one rule on a catalog item, item value-range, power, power level, pool value-range, or identity field option — forks the block for this chronicle if not already forked |
| GET | `/{game_slug}/approval-rules/options` | `be_manage_approval_rules` | The fixed vocabulary the create/edit form offers: real approval levels, and reason-tier presets |
| PUT | `/{game_slug}/approval-rules/{id}` | `be_manage_approval_rules` | Update one rule |
| DELETE | `/{game_slug}/approval-rules/{id}` | `be_manage_approval_rules` | Clear one rule, back to no override |

**Default Approval Policy.** A chronicle's own baseline — used only when nothing above (an
item, a power, a level, a value range, a field option, or the owning block's own
`approval_rules.default`) resolved a level at all — is the plain `settings.auto_approve`
boolean on the game itself (`false`/absent: everything needs `st` review by default; `true`:
everything is `auto` by default), read and written through the existing
[`PUT /games/{slug}`](#games) route's own `settings` merge, not a route of its own. A
granular rule always wins over this default in either direction, in both the `resolve_approval_level()`
resolution logic and by construction of the merge itself.

## Creature Stacks

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/creature-stacks` | `be_view_characters` | List stacks |
| POST | `/creature-stacks` | `be_manage_schemas` | Create a stack |
| GET | `/creature-stacks/{slug}` | `be_view_characters` | Get one stack, resolved (blocks assembled) with `?resolve=true`. Add `?game_slug=` for a chronicle's own forked blocks, and `&for_creation=true` to also narrow identity-field options to that chronicle's `enabled_factions` restriction — the character-creation picker only; never applied when viewing or editing an existing character |
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
| GET | `/{game_slug}/characters/statuses` | `be_manage_characters` | The fixed status vocabulary (`active`, `inactive`, `retired`, `dead`, `pending`) — sources the bulk-status picker rather than hardcoding the list a second time client-side |
| POST | `/{game_slug}/characters/bulk-status` | `be_manage_characters` | Set the same status on a batch of characters. Returns a per-character result (`{results: [{id, success, error?}], updated}`), never all-or-nothing — one bad or foreign id in the batch fails only that entry |
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

## Bulk Operations (Query Tool's own result-set actions)

Three bulk actions a Storyteller can run against a query's own selected results, all sharing
the same shape: pick rows in the Query Tool, then apply one action to the whole selection at
once rather than one character at a time.

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/experience/bulk-award` | `be_manage_characters` | Award the same amount of XP to several characters at once |
| POST | `/{game_slug}/resource-pools/bulk-reset` | `be_manage_characters` | Reset one named resource pool's temporary rating back to its permanent one, across a selection — the end-of-session Willpower/Blood refill, for a whole group at once. A character who doesn't hold the named pool, or belongs to a different chronicle, is silently skipped, not treated as an error |
| POST | `/{game_slug}/characters/bulk-status` | `be_manage_characters` | See Characters, above — listed here too since it's the same Query Tool bulk-action shape |

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

## Action & Rumor Settings (APR)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/apr-settings` | `be_manage_apr` | This chronicle's downtime-action and rumor-generation configuration |
| PUT | `/{game_slug}/apr-settings` | `be_manage_apr` | Update it |
| GET | `/{game_slug}/apr-settings/backgrounds` | `be_manage_apr` | The catalog of Backgrounds available to configure as action-granting |

## Background Uses

Recording what a player actually did with an allocated downtime action, and an ST adjudicating it.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/spendable` | `be_view_characters` | How many actions this character has left to spend on the current game date |
| GET | `/{game_slug}/characters/{character_id}/background-uses` | `be_view_characters` | List this character's recorded uses |
| POST | `/{game_slug}/characters/{character_id}/background-uses` | `be_submit_actions` OR `be_manage_characters` | Record what the player did with one action |
| POST | `/{game_slug}/characters/{character_id}/background-uses/clear` | `be_manage_characters` | Clear every use recorded against this character for one game date |
| POST | `/{game_slug}/background-uses/clear-date` | `be_manage_characters` | Clear every character's recorded uses for one game date at once |
| PUT | `/{game_slug}/background-uses/{id}` | `be_submit_actions` OR `be_manage_characters` OR `be_manage_plots` | Update one recorded use — an ST filling in the adjudicated result, or the player editing their own before it's reviewed |
| DELETE | `/{game_slug}/background-uses/{id}` | `be_submit_actions` OR `be_manage_characters` | Delete one recorded use |

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

`be_manage_boons` is granted site-wide to every WordPress role, including `subscriber` — the
real per-chronicle gate is still the caller's own `be_game_members` role there (`boons`,
`hst`, or `ast`); a logged-in visitor with no membership in this chronicle still gets `403`
from the write routes below.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/boons` | `be_view_characters` | The ledger — whole-game, or one character's owed/owed-to-them split |
| POST | `/{game_slug}/boons` | `be_manage_boons` | Record a new boon |
| PUT | `/{game_slug}/boons/{id}/repay` | `be_manage_boons` | Mark a boon repaid — a symmetric transactional update |

## Import (exchange files — `.gex`, character or game-scoped)

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/import/parse` | `be_import` | Upload and parse; returns a preview, stores the job for one hour |
| GET | `/{game_slug}/import/{job_id}` | `be_import` | Re-fetch a stored job's current state |
| POST | `/{game_slug}/import/{job_id}/commit` | `be_import` | Commit — refuses while anything is unresolved/flagged; transactional; safe to re-POST |

## Export

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/characters/{id}/export` | `be_manage_characters` OR `be_edit_own_characters` | Export one character to a Grapevine `.gex` XML document. `hide_st` strips `[ST]...[/ST]`-marked text the same way a non-manager's own sheet view already does; `verify` mints a fresh attestation embedded in the document, so each call issues a new code — not free to call repeatedly for the same download |

## Game Import (full `.gv3` game files — not game-scoped, since a new chronicle may not exist yet)

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/import/game/parse` | `be_import` AND `be_manage_games` | Upload and parse a full game file |
| GET | `/import/game/{job_id}` | `be_import` AND `be_manage_games` | Re-fetch a stored job |
| POST | `/import/game/{job_id}/commit` | `be_import` AND `be_manage_games` | Create a new chronicle, or merge into an existing one, from the file's contents |

## Transfers (chronicle-to-chronicle character transfer)

Sending a character to a different chronicle — on this install, or a different Beyond
Elysium site entirely — with a real handshake and attestation rather than a plain export/
re-import.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/transfers` | `be_manage_characters` | List this chronicle's outbound and inbound transfers |
| POST | `/{game_slug}/transfers/outbound` | `be_manage_characters` | Start sending a character elsewhere |
| POST | `/{game_slug}/transfers/{id}/acknowledge` | `be_manage_characters` | Acknowledge an inbound transfer request |
| POST | `/{game_slug}/transfers/{id}/release` | `be_manage_characters` | Release the character — the sending side's final confirmation |
| POST | `/{game_slug}/transfers/{id}/decline` | `be_manage_characters` | Decline an inbound transfer |
| POST | `/{game_slug}/transfers/inbound` | none (public by design) | The receiving side's callback from the *sending* site — trust comes from verifying the request against the sender's own attestation, not from a WordPress capability on this install |

## Chronicle Members

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/members` | `be_manage_games` | List this chronicle's members and roles |
| POST | `/{game_slug}/members` | `be_manage_games` | Add a member, or change an existing member's role |
| DELETE | `/{game_slug}/members/{wp_user_id}` | `be_manage_games` | Remove a member's chronicle-scoped access |

## Setup Status

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/setup-status` | `be_view_characters` | The Chronicle Setup checklist's rows, computed live against real data every time — never stored, so nothing here goes stale between visits |

## Authorization Settings

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/authorization-settings` | `be_manage_games` | Whether accessSchema checking is on, and whether a real accessSchema client is detected |
| PUT | `/authorization-settings` | `be_manage_games` | Turn accessSchema checking on or off, site-wide |

## Data Management

Site-wide, not game-scoped — lives on the Chronicle Access admin screen.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/data-management` | `be_manage_games` | Whether uninstalling the plugin also deletes its data |
| PUT | `/data-management` | `be_manage_games` | Turn that opt-in on or off |
| GET | `/data-management/export` | `be_manage_games` | A full JSON export of every plugin table, for a backup or a migration |

## Credits

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/credits` | `be_view_characters` | Site-wide credits text and in-memoriam list |
| PUT | `/credits` | `be_manage_games` | Update it |

## Docs

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/docs/{slug}` | `be_view_characters` | Serves one of this plugin's own bundled guides (`st-guide`, `admin-guide`, `player-guide`, `rest-api` — this very document) as rendered HTML, for the in-plugin Docs screen |

## Game Stats

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/stats` | `be_manage_characters` | The ST dashboard's aggregate numbers — character counts, pending changes, active plots, recent activity, and `players_without_active_character` (a count only; see the detail route below). Cached one minute; a review action invalidates the cache for its own chronicle immediately |
| GET | `/{game_slug}/stats/players-without-active-character` | `be_manage_characters` | The actual player list behind that count — every player-role member with zero `active` characters (no characters at all, or only a retired/dead/pending one — the same condition), resolved to a display name. Not itself cached; fetched only when the dashboard's roster-health card is opened |

## Sheets (signed character-sheet PDF)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/sheets/pdf` | `be_view_characters` | Returns signed PDF bytes for one or more characters (`character_ids`, comma-separated, max 50). A manager may request any character in the chronicle; a non-manager only their own — one denied or missing id fails the whole request. `503 signing_unavailable` when the chronicle hasn't configured a signing certificate. Optional `full_power_names`, `background`, `notes`, `xp_history` |
| GET | `/{game_slug}/sheets/availability` | `be_view_characters` | Preflight: is signing configured for this chronicle right now |

## Verify

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/verify/{code}` | none (public by design) | Confirms one exported/printed document's attestation code is real and unaltered — not game-scoped, and deliberately reachable by anyone with the link, not just chronicle members. Backs the `be-verify` page's human-facing check |

## Reports (the 20 GV301-plus reports, cards, and batch output)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/reports` | `be_view_reports` | Lists the report registry: key, title, shape, entity |
| GET | `/{game_slug}/reports/{report_key}` | `be_view_reports` | Returns the resolved report as plain JSON — no signing, no PDF. Built for a live front-end view (House Rules' own widget/shortcode use it); works for any report in the registry, not just House Rules |
| GET | `/{game_slug}/reports/{report_key}/pdf` | `be_view_reports` | Returns signed PDF bytes for one report. `conditions`/`logic` scope a `table`/`card` report the same way the query builder does (an empty `conditions` means everyone in scope); `stat_field`/`stat_type` parameterize the generic Statistics Report; `character_id` (both this route and the JSON form above) narrows a `card`-shaped report (e.g. `item-cards`) to only the world objects connected to that one character — a manager may pass any character in the chronicle, a non-manager only their own (`404 character_not_found` for a mismatched game, `403 ownership_denied` for someone else's character). `404 report_not_found` for an unknown key; `503 signing_unavailable` when signing isn't configured — every report shares the signed sheet's own signing pipeline |

## Point Audit

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{id}/point-audit` | `be_manage_characters` | The itemised point audit for one character — every held line, priced or explicitly marked unpriced with a machine-readable reason. Never `be_view_characters`/`be_edit_own_characters`: a grand total computed across a Storyteller-only block would leak its stored values arithmetically, so a non-manager gets `403`, never a reduced total. `complete` is always `false` — this is not a bill, see [st-guide.md](../docs/st-guide.md) |
