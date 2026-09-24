# REST API Reference

All routes are under the `be/v1` namespace — e.g. `/wp-json/be/v1/games`. Every write route
requires a logged-in WordPress user; every route's permission is enforced server-side
regardless of what any client UI shows or hides.

`{game_slug}` scopes a route to one chronicle. A request naming a game slug the caller has
no relationship to returns `404` (not `403`), so a chronicle's existence is never leaked to
someone outside it.

While a catalog cutover runs (`wp be cutover apply` or `rollback`, a few seconds), every
write to a route here is answered `503 catalog_switch_in_progress` and reads are not
affected. A run that died holding its lock stops refusing after two minutes. See [Switching
a Site to the Declared Catalog](help/catalog-cutover.md).

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
| PUT | `/games/{slug}` | `be_manage_games` | Update name, slug, description, settings, `asc_role_path`, `notifications_enabled`. `settings` merges into what is stored, key by key; `settings.enabled_factions` merges one level further, stack by stack and field by field, so a write names only the fields it changes (an empty list lifts that field's restriction) |
| DELETE | `/games/{slug}` | `be_manage_games` | Delete a chronicle. One that still holds content (characters, plots, world objects, its own templates or schema-block forks, named saved queries, verification codes, transfers) is refused with `409 chronicle_has_content` and `data.counts`, unless `?with_content=1` - which deletes all of it, memberships included, in one transaction. Nothing is ever left under the slug. `POST /games` refuses an explicit slug still holding a deleted chronicle's content (`409 slug_has_orphaned_content`), and a rename onto one is refused (`409 orphan_collision`) |
| GET | `/games/{slug}/content` | `be_manage_games` | Counts what deleting the chronicle would delete with it (`characters`, `plots`, `world_objects`, `templates`, `schema_blocks`, `saved_queries`, `attestations`, `transfers`) - the Games screen names these in its one delete confirmation |
| GET | `/my/games` | `be_view_characters` | Only the chronicles the caller actually holds a `be_game_members` row in, each with the role held there (`{slug, name, role}`) — the real data source for the My Chronicle / Storyteller Toolkit chronicle switcher |
| GET | `/{game_slug}/my/capabilities` | logged in (any) | What the caller can actually do *in this one chronicle* — `be_manage_characters`, `be_manage_plots`, `be_manage_schemas`, `be_manage_connections`, `be_manage_boons`, each resolved through the same chronicle-scoped `Authorization::check_request()` every write route uses, not the site-wide snapshot every page load carries. An unresolvable `game_slug` or a caller with no relationship to this chronicle still returns `200` with every flag `false`, never an error — a switcher renders "no access here" rather than failing |

## Schema Blocks

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/schema-blocks` | `be_view_characters` | List blocks |
| POST | `/schema-blocks` | `be_manage_schemas` | Create a block |
| GET | `/schema-blocks/{slug}` | `be_view_characters` | Get one block; `?game_slug=` substitutes a chronicle's own fork if it has one |
| PUT | `/schema-blocks/{slug}` | `be_manage_schemas` | Update, or auto-fork per-game if `?game_slug=` is present and no fork exists yet. Any `description` object (`{reference, description, source}`, each HTML) on an item/power/level is sanitized server-side — formatting/lists/tables survive, images and scripts don't — regardless of what the caller submits. `500 save_failed` when the save didn't land - for a chronicle, neither the change nor a new copy is kept |
| DELETE | `/schema-blocks/{slug}` | `be_manage_schemas` | Delete |
| POST | `/{game_slug}/schema-blocks` | `be_manage_schemas` | Explicitly fork a block for this chronicle (the same auto-fork the global `PUT` above performs implicitly via `?game_slug=`, as its own dedicated route) |
| PUT | `/{game_slug}/schema-blocks/{slug}` | `be_manage_schemas` | Update this chronicle's own fork, making it on the first edit. `500 save_failed` when the save didn't land - neither the change nor a new copy is kept |
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
| POST | `/{game_slug}/approval-rules` | `be_manage_approval_rules` | Set (create or overwrite) one rule on a catalog item, item value-range, power, power level, pool value-range, or identity field option — forks the block for this chronicle if not already forked. `500 save_failed` when the rule didn't save - nothing is kept, a new copy included |
| GET | `/{game_slug}/approval-rules/options` | `be_manage_approval_rules` | The fixed vocabulary the create/edit form offers: real approval levels, and reason-tier presets |
| GET | `/{game_slug}/approval-rules/default` | `be_manage_approval_rules` | The chronicle's Default Approval Policy: `{ auto_approve }` |
| PUT | `/{game_slug}/approval-rules/default` | `be_manage_approval_rules` | Set it: `{ auto_approve: true \| false }`. Every other chronicle setting is kept |
| PUT | `/{game_slug}/approval-rules/{id}` | `be_manage_approval_rules` | Update one rule. `500 save_failed` when it didn't save |
| DELETE | `/{game_slug}/approval-rules/{id}` | `be_manage_approval_rules` | Clear one rule, back to no override. `500 save_failed` when it didn't save |

**Default Approval Policy.** A chronicle's own baseline — used only when nothing above (an
item, a power, a level, a value range, a field option, or the owning block's own
`approval_rules.default`) resolved a level at all — is the plain `settings.auto_approve`
boolean on the game itself (`false`/absent: everything needs `st` review by default; `true`:
everything is `auto` by default), read and written through `/{game_slug}/approval-rules/default`, so
the Storytellers who manage the rules can set it. A
granular rule always wins over this default in either direction, in both the `resolve_approval_level()`
resolution logic and by construction of the merge itself.

## Creature Stacks

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/creature-stacks` | `be_view_characters` | List stacks |
| POST | `/creature-stacks` | `be_manage_games` | Create a stack |
| GET | `/creature-stacks/{slug}` | `be_view_characters` | Get one stack, resolved (blocks assembled) with `?resolve=true`. Add `?game_slug=` for a chronicle's own forked blocks, and `&for_creation=true` to also narrow identity-field options to that chronicle's `enabled_factions` restriction — the character-creation picker only; never applied when viewing or editing an existing character |
| PUT | `/creature-stacks/{slug}` | `be_manage_games` | Update. `500 save_failed` when the save didn't land |
| DELETE | `/creature-stacks/{slug}` | `be_manage_games` | Delete a custom stack. A system stack is `403`; a stack any character in any chronicle still uses is `409 creature_stack_in_use`, with `count` |

## Characters

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters` | `be_view_characters` | List, with roster filters |
| POST | `/{game_slug}/characters` | `be_edit_own_characters` | Create — bootstrap-exempt: a player with no prior chronicle relationship can still create their first character here. Also creates the character's own plot, `<Character> [id] Plot`, linked to it as its actor (only its player and the chronicle's Storytellers see it); a character whose plot can't be written isn't created (`500 create_failed`) |
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
| POST | `/{game_slug}/characters/{character_id}/changes` | `be_edit_own_characters` | Submit a change — auto-approves and applies immediately if the block's approval rules allow it. A purchase with no catalog price (homebrew) is stored with `change_data.cost_pending: true` and `xp_cost` 0 and waits for a Storyteller to price it; it never auto-approves, and a player's own `chosen_cost` on homebrew is dropped. A Storyteller's `chosen_cost` on a custom trait prices it at submission: per dot, 0 to 500 |
| GET | `/{game_slug}/changes` | `be_manage_characters` | The approval queue — every pending (or filtered) change across the whole chronicle. Filters: `status`, `change_type`, `character_id`, and `approval_level` (`auto` or `st`), which pages and totals that level alone. A change waiting for a price also carries `cost_units`, `{ per: "dot" or "pick", units, negative }`: what one price covers - the new dots for a trait list, one pick for a power - so a client can show the total as it is typed |
| POST | `/{game_slug}/changes/batch-approve` | `be_manage_characters` | Approve several changes in one call. Returns `approved`, `skipped` (missing, already reviewed, edited since, or not allowed) and `needs_cost`: changes waiting for a price are never approved in a batch, and are named here instead |
| GET | `/{game_slug}/my/changes` | `be_view_characters` | Only the caller's own pending changes, across every character they own |
| POST | `/{game_slug}/characters/{character_id}/preview-changes` | `be_edit_own_characters` | Price a set of proposed changes without submitting them. Each result has `xp_cost`, `priced` and `unpriced_reason` (`custom_no_catalog_entry`): `priced: false` means no price exists yet and a Storyteller sets it at approval, so the `0` is not a price and does not move `running_xp_unspent` |
| PUT | `/{game_slug}/changes/{id}` | `be_manage_characters` | Approve or reject; sends the submitting player a notification email unless they or the chronicle opted out. Approving a change with `cost_pending` needs `xp_cost`, a whole number from 0 to 500 - per dot for a trait list, the whole amount for a power: without it the response is 400 `cost_required`, and a value outside that range is 400 `invalid_param`. The price is stamped on the trait, the total is deducted, and `cost_pending` is cleared. `xp_cost` is not read for any other change |

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
| GET | `/templates` | `be_manage_templates` | List global (base) templates, for the template editor - a sheet gets its layout from `resolve` |
| POST | `/templates` | `be_manage_games` | Create a global template |
| GET | `/templates/{id}` | `be_manage_templates` | One global template; a chronicle's own template is `404` here and listed on its own route |
| PUT | `/templates/{id}` | `be_manage_games` | Update a global template |
| DELETE | `/templates/{id}` | `be_manage_games` | Delete a global template |
| GET | `/{game_slug}/templates/resolve` | `be_view_characters` | Resolve the effective layout for a creature stack in this chronicle (game fork if one exists, else the global template) |
| GET | `/{game_slug}/templates` | `be_manage_templates` | List this chronicle's own template forks |
| POST | `/{game_slug}/templates` | `be_manage_templates` | Fork a template for this chronicle |
| PUT | `/{game_slug}/templates/{id}` | `be_manage_templates` | Update a chronicle's own fork |
| DELETE | `/{game_slug}/templates/{id}` | `be_manage_templates` | Delete a chronicle's own fork |

## Plots

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/plots` | `be_view_characters` | List plots. `character_plots=only` keeps each character's own plot and its action rounds (plots tied to a character by `apr_actor`); `exclude` keeps every other plot; anything else is `400` |
| POST | `/{game_slug}/plots` | `be_submit_actions` OR `be_manage_plots` | Create — a player can start a plot thread via an action, an ST can create one directly. A non-manager's plot is always their own player plot (1.1.0 §2.3a): requires `character_id` (must be the caller's own, `403` otherwise), forced `audience: restricted`, connected via a `plot_owner` connection. A manager's plot defaults `audience` to `storytellers` (never player-reachable) and may set `audience`/`audience_rules` explicitly. A Storyteller's `is_rumor: true` saves the plot and its rumor tag together or neither - `500 create_failed` when nothing was kept |
| GET | `/{game_slug}/my/plots` | `be_view_characters` | Only the caller's own plots — theirs by connection, or reachable via `target_query` |
| POST | `/{game_slug}/plots/allocate-actions` | `be_manage_plots` | Allocate a round's action slots. With `commit`, the date's plot and every action entry are saved together or not at all - `500 allocation_failed` when nothing was kept. The date's plot sits under the character's own plot unless `parent_plot_id` names another |
| POST | `/{game_slug}/plots/generate-rumors` | `be_manage_plots` | Generate a date's standard rumors: a preview, or with `commit`, saved, and each matched player emailed once. A commit keeps every rumor or none - `500 generate_failed`, and no one is emailed - and two commits in one chronicle take turns, so the second finds the first's rumors and skips them |
| GET | `/{game_slug}/plots/{id}` | `be_view_characters` | One plot with its entry thread, connections, attachments (never `stored_name`), and `is_owner` (true only for the player who owns this plot, false for everyone else including a manager). Hidden by its own `audience`/`audience_rules` (1.1.0 §2.1) or another character's action allocation is `404` unless you manage plots, and never listed among a plot's children |
| PUT | `/{game_slug}/plots/{id}` | `be_manage_plots` | Update, including `audience` (`everyone`/`storytellers`/`restricted`) and `audience_rules` (`{conditions, logic}`, required non-empty when `audience` is `restricted`) - `400 invalid_param` for an invalid value or an empty `conditions` array |
| DELETE | `/{game_slug}/plots/{id}` | `be_manage_plots` | Delete, cascading its entries, connections, and attachments (rows and files). A character's own plot is refused (`409 character_plot`) - it goes when the character is deleted |
| GET | `/{game_slug}/plots/{id}/member-candidates` | `be_submit_actions` OR `be_manage_plots` | A player plot's own owner or a manager only (else `403 ownership_denied`): active, non-NPC characters not already connected, name and id only (1.1.0 §2.3a) |
| POST | `/{game_slug}/plots/{id}/members` | `be_submit_actions` OR `be_manage_plots` | Add a `plot_member` connection - the owner or a manager only; a non-manager is held to the candidates list even if they post a `character_id` directly |
| DELETE | `/{game_slug}/plots/{id}/members/{connection_id}` | `be_submit_actions` OR `be_manage_plots` | Remove a `plot_member` connection - a manager may remove anyone, the owner only whoever they themselves added (`403 not_your_addition` otherwise). The owner's own connection is never reachable here |
| GET | `/{game_slug}/plots/{id}/visible-characters` | `be_manage_plots` | Every character who can currently see this plot, name and id only - backs the directed-entry picker |

## Entries (plot responses/actions)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/plots/{plot_id}/entries` | `be_view_characters` | List a plot's entries (`404` for another character's action allocation, as above), each carrying its own `audience` (`plot`/`storytellers`/`characters`) and, for a directed entry, `audience_character_ids` |
| POST | `/{game_slug}/plots/{plot_id}/entries` | `be_submit_actions` OR `be_manage_plots` | Add an entry. `audience` defaults to `plot`; a non-manager may only choose `plot` or `storytellers` (`403` for `characters`); `characters` requires a non-empty `audience_character_ids`, each id a character who can currently see the plot (`400` otherwise) |
| PUT | `/{game_slug}/entries/{id}` | `be_submit_actions` OR `be_manage_plots` | Update. `audience`/`audience_character_ids` are left untouched entirely when not sent - a plain content edit never resets a chosen audience back to `plot` |
| DELETE | `/{game_slug}/entries/{id}` | `be_manage_plots` | Delete |

## Action & Rumor Settings (APR)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/apr-settings` | `be_manage_apr` | This chronicle's downtime-action and rumor-generation configuration |
| PUT | `/{game_slug}/apr-settings` | `be_manage_apr` | Update it |
| GET | `/{game_slug}/apr-settings/backgrounds` | `be_manage_apr` | The catalog of Backgrounds available to configure as action-granting |

## AI Assist

Server-side AI writing-assist integration (Admin Guide's "AI Writing Assist" section). Two
route pairs, both handled the same way: a site-wide pair for fields that belong to no
chronicle, and a chronicle-scoped pair for everything else. The generate route's own required
capability is resolved server-side from the request's `field_context`, never trusted from the
client.

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/ai-assist` | Resolved per `field_context` | Generate a suggestion for a site-wide field (Schema Block catalog descriptions, Credits text) |
| GET | `/ai-assist/settings` | `be_manage_games` | The site-wide provider, key-configured flags, and custom base URL/model overrides — never the key itself |
| PUT | `/ai-assist/settings` | `be_manage_games` | Update the site-wide provider, key (empty string clears it), base URL, or model |
| POST | `/ai-assist/test` | `be_manage_games` | Test a provider/key/base URL/model combination directly from the request body — never a saved key |
| POST | `/{game_slug}/ai-assist` | Resolved per `field_context` | Generate a suggestion for a chronicle-scoped field (character, plot, rumor, world-object) |
| GET | `/{game_slug}/ai-assist/settings` | `be_manage_apr` | This chronicle's own opt-in, provider, key-configured flags, and base URL/model overrides |
| PUT | `/{game_slug}/ai-assist/settings` | `be_manage_apr` | Update this chronicle's own AI Assist settings. `500 save_failed` when the save didn't land |
| POST | `/{game_slug}/ai-assist/test` | `be_manage_apr` | Test a provider/key/base URL/model combination for this chronicle, before saving |

## Background Uses

Recording what a player actually did with an allocated downtime action, and an ST adjudicating it.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/spendable` | `be_view_characters` | How many actions this character has left to spend on the current game date |
| GET | `/{game_slug}/characters/{character_id}/background-uses` | `be_view_characters` | List this character's recorded uses |
| POST | `/{game_slug}/characters/{character_id}/background-uses` | `be_submit_actions` OR `be_manage_characters` | Record what the player did with one action. `500 record_failed` when it couldn't be saved - nothing is kept |
| POST | `/{game_slug}/characters/{character_id}/background-uses/clear` | `be_manage_characters` | Clear every use recorded against this character for one game date |
| POST | `/{game_slug}/background-uses/clear-date` | `be_manage_characters` | Clear every character's recorded uses for one game date at once |
| PUT | `/{game_slug}/background-uses/{id}` | `be_submit_actions` OR `be_manage_characters` OR `be_manage_plots` | Update one recorded use — an ST filling in the adjudicated result, or the player editing their own before it's reviewed |
| DELETE | `/{game_slug}/background-uses/{id}` | `be_submit_actions` OR `be_manage_characters` | Delete one recorded use |

## Connections

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/connections` | `be_manage_connections` OR `be_manage_plots` | List connections between characters/objects. Staff only: the list shows who holds what and whose action allocation is whose |
| POST | `/{game_slug}/connections` | `be_manage_connections` | Create |
| PUT | `/{game_slug}/connections/{id}` | `be_manage_connections` | Update |
| DELETE | `/{game_slug}/connections/{id}` | `be_manage_connections` | Delete |

## Query

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/query` | `be_run_queries` AND `be_manage_characters` | Run an ad-hoc query against the roster |
| POST | `/{game_slug}/statistics` | `be_run_queries` AND `be_manage_characters` | Run one of the five built-in roster statistics |
| GET | `/{game_slug}/queries` | `be_run_queries` AND `be_manage_characters` | List saved queries |
| POST | `/{game_slug}/queries` | `be_run_queries` AND `be_manage_characters` | Save a query |
| PUT | `/{game_slug}/queries/{id}` | `be_run_queries` AND `be_manage_characters` | Update a saved query |
| DELETE | `/{game_slug}/queries/{id}` | `be_run_queries` AND `be_manage_characters` | Delete a saved query |

## World Objects (items, locations, rotes)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/world-objects` | `be_view_characters` | List, filterable by type. With `object_type`, any of that type's properties filters too: a number exactly or with `_min`/`_max`, text exactly in any case, and a list - an item's `abilities`, a rote's `spheres` - by one entry's name. Items/locations are narrowed by their own `audience`/`audience_rules` for a non-manager (1.1.0 §2.5, a connected character always included); rotes and boons pass through untouched |
| POST | `/{game_slug}/world-objects` | `be_manage_world_objects` | Create. Name, rarity, and cost are plain text (at most 255, 20, and 100 characters; longer is `400`), description and limitations allow the same HTML a post does. `audience`/`audience_rules` accepted the same shape as a plot's; meaningful for item/location only. A boon is refused (`409 use_boon_ledger`) - boons are made on `/boons` |
| GET | `/{game_slug}/world-objects/{id}` | `be_view_characters` | Get one, with its attachments embedded (never `stored_name`). Hidden by its own audience for a non-manager unless a connected character reaches it |
| PUT | `/{game_slug}/world-objects/{id}` | `be_manage_world_objects` | Update, cleaned and limited the same way as a create. `audience`/`audience_rules` updatable the same way. `409 use_boon_ledger` for a boon |
| DELETE | `/{game_slug}/world-objects/{id}` | `be_manage_world_objects` | Delete, cascading its attachments (rows and files). `409 use_boon_ledger` for a boon, which is never deleted |

## Attachments

Files on a plot, item, or location (1.1.0 §2.6) - images and PDFs, 10 MB each, up to 20 for a
plot/location or exactly 1 for an item. Never served from the WordPress media library; see
Admin Guide "File Uploads."

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/attachments` | `be_submit_actions` OR `be_manage_plots` OR `be_manage_world_objects` | Upload. Requires `entity_type` (`plot`/`item`/`location`) and `entity_id`, plus one file in the request's file params. The real gate is per-entity: a Storyteller always, or a plot's own owner (`403` otherwise); items/locations are `be_manage_world_objects` only. `400 invalid_file_type`/`file_too_large` against the file's real content, never its claimed type; `409 limit_reached` at the cap |
| GET | `/{game_slug}/attachments/{id}` | `be_view_characters` | Streams the raw file bytes (not JSON) once the caller's audience reaches the owning entity - `404` otherwise, with nothing about the file disclosed |
| DELETE | `/{game_slug}/attachments/{id}` | `be_submit_actions` OR `be_manage_plots` OR `be_manage_world_objects` | Delete - the database row and the file together, or neither. Same per-entity gate as upload |

## Boons

`be_manage_boons` is granted site-wide to every WordPress role, including `subscriber` — the
real per-chronicle gate is still the caller's own `be_game_members` role there (`boons`,
`hst`, or `ast`); a logged-in visitor with no membership in this chronicle still gets `403`
from the write routes below.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/boons` | `be_view_characters` | The ledger — whole-game, or one character's owed/owed-to-them split |
| POST | `/{game_slug}/boons` | `be_manage_boons` | Record a new boon |
| PUT | `/{game_slug}/boons/{id}/repay` | `be_manage_boons` | Mark a boon repaid — a symmetric transactional update. `500 save_failed` when it didn't save |

## Import (exchange files — `.gex`, character or game-scoped)

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/import/parse` | `be_import` | Upload and parse; returns a preview, stores the job for one hour |
| GET | `/{game_slug}/import/{job_id}` | `be_import` | Re-fetch a stored job's current state |
| POST | `/{game_slug}/import/{job_id}/commit` | `be_import` | Commit — refuses while anything is unresolved/flagged; transactional; safe to re-POST |

## Export

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/characters/{id}/export` | `be_manage_characters` OR `be_edit_own_characters` | Export one character to a Grapevine `.gex` XML document. `422 not_exportable` for a creature type with no Grapevine equivalent (one an administrator added). `hide_st` asks for a player's copy - no Storyteller-only block, no `[ST]...[/ST]`-marked text in notes, biography, or boon terms - and only matters to a Storyteller: a player's own export is always that copy, whatever the request says. `as_transfer: true` is refused (`400 use_transfer_route`); a transfer document comes only from `/transfers/outbound`. `verify` mints a fresh attestation embedded in the document, so each call issues a new code — not free to call repeatedly for the same download, and its stored `document_hash` covers this exact document (redacted or not) rather than the sheet's own always-unredacted `sheet_hash`, so a later re-check compares against what was actually handed over |

## Game Import (full `.gv3` game files — not game-scoped, since a new chronicle may not exist yet)

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/import/game/parse` | `be_import` AND `be_manage_games` | Upload and parse a full game file |
| GET | `/import/game/{job_id}` | `be_import` AND `be_manage_games` | Re-fetch a stored job |
| POST | `/import/game/{job_id}/commit` | `be_import` AND `be_manage_games` | Create a new chronicle, or merge into an existing one, from the file's contents |

## Transfers (chronicle-to-chronicle character transfer)

Sending a character to a different chronicle — on this install, or a different Beyond
Elysium site entirely — with a real handshake and attestation rather than a plain export/
re-import. A Storyteller approves each side: the home chronicle's sends it, and the receiving
chronicle's reviews the offer and accepts or refuses it. Nothing is written to the receiving
chronicle before that.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/transfers` | `be_manage_characters` | List the transfers this chronicle is party to on this site — outbound rows it is home to, inbound rows it hosts — without stored payloads |
| POST | `/{game_slug}/transfers/outbound` | `be_manage_characters` | Send a character. Always returns the transfer document; given `host_site` and `host_slug`, also offers it to that chronicle, and the row stays `pending` until acknowledged. `422 not_exportable`, recording nothing, for a creature type with no Grapevine equivalent. `409 already_travelling` while the character has an open outbound transfer, including one another request recorded a moment before; `500 create_failed` when the transfer didn't save |
| POST | `/{game_slug}/transfers/{id}/acknowledge` | `be_manage_characters` | Home side: mark a `pending` transfer received abroad |
| POST | `/{game_slug}/transfers/{id}/release` | `be_manage_characters` | Home side: give an `abroad` character up for good |
| POST | `/{game_slug}/transfers/{id}/decline` | `be_manage_characters` | Home side: cancel a `pending` transfer. Revokes its verification code, so an offer still waiting at the host can no longer be accepted |
| POST | `/{game_slug}/transfers/inbound` | none (public by design) | Called by the *sending* site. Verified against the sender's own `/verify/{code}`, which must answer for a code issued for a transfer (a verified export's code is refused, `400 verify_failed`); records an `offered` row holding the document and emails this chronicle's HSTs and ASTs. Returns `202 {pending_review: true}`. 10 requests a minute per IP; `409 already_offered` while the character is already waiting or visiting, including an offer recorded a moment before; `500 create_failed` when the offer didn't save; `429 too_many_offers` past 50 waiting offers |
| GET | `/{game_slug}/transfers/{id}/review` | `be_import` | Host side: an `offered` transfer's import preview — the same shape `GET /{game_slug}/import/{job_id}` returns. A duplicate matched in this chronicle also carries `changes`: one `{section, entry, here, arriving}` row per difference from the sheet already here (`here` null for something only arriving, `arriving` null for something only here); a match in another chronicle carries none |
| POST | `/{game_slug}/transfers/{id}/accept` | `be_import` | Host side: accept an offer with import `resolutions`. Asks the home site again first (`400 verify_failed` if it cancelled), needs a decision for every duplicate (Skip is refused - refuse the offer instead), imports in one transaction, and moves the row to `visiting`. A character returning to the chronicle it left also closes that outbound row as `returned` |
| POST | `/{game_slug}/transfers/{id}/refuse` | `be_import` | Host side: turn an offer down; nothing is written |
| POST | `/{game_slug}/transfers/{id}/send-home` | `be_manage_characters` | Host side: end a visit (`visiting` → `sent_home`) |
| POST | `/{game_slug}/transfers/{id}/retain` | `be_manage_characters` | Host side: keep a visiting character for good (`visiting` → `retained`) |

A character matched by its identity (uuid) is only this chronicle's to overwrite when it lives
here. One that lives in another chronicle on this site is reported as `matched_by:
"uuid_elsewhere"` and can be skipped or imported as a new character with its own identity -
never overwritten. This applies to every import, not only transfers.

## Submissions (a player sending their own Grapevine file straight to a chronicle)

The player-initiated counterpart to Transfers: no Storyteller on the sending end at all -
anyone signed in can send an exported `.gex` to any chronicle, joining it or visiting for a
game. It reuses the same review/accept/refuse shape Transfers already established, keyed on
`game_id` rather than a slug so a chronicle rename can't orphan a waiting file.

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/submissions/preview` | `be_edit_own_characters` | Bootstrap-exempt, same rule as character creation. Reads the upload and reports which of its characters can be sent here, with a reason for one that can't; stores nothing - `413 file_too_large` past 5 MB, `400 unsupported_format` for a `.gv3`, `400 invalid_format` for anything else unrecognized, `422 no_character` for a file with none |
| POST | `/{game_slug}/submissions` | `be_edit_own_characters` | Bootstrap-exempt. Re-reads the upload and records the request; `character_index` picks one out of several, `arrival` (`joining` or `visiting`, required) and an optional `home_chronicle`. `400 choose_character` for several characters with no index; `400 not_allowed` when the chosen character fails the same allowed-here check `preview` reports; `409 join_already_requested` for a non-member who already has a pending hand-built join character here (and the reverse, from `POST /{game_slug}/characters`, checks this table too); `409 already_waiting` for a second file to the same chronicle; `429 too_many_waiting` past 50 chronicle-wide. Emails every HST and AST |
| POST | `/{game_slug}/submissions/{id}/withdraw` | `be_edit_own_characters` | The sender only (`404 submission_not_found` for anyone else, or a wrong `game_slug`) - `waiting` → `withdrawn` |
| GET | `/my/submissions` | `be_view_characters` | The caller's own last 20, any chronicle. Registered before `/{game_slug}/submissions` so a chronicle literally slugged `my` can't shadow it |
| GET | `/{game_slug}/submissions` | `be_import` | This chronicle's `waiting` rows, each with `sender_name` |
| GET | `/{game_slug}/submissions/{id}/review` | `be_import` | The same preview shape `GET /{game_slug}/import/{job_id}` returns, plus `overwrite_allowed`/`existing_owner` on each duplicate - a player-sent file can never overwrite a character it doesn't already own, even one it matches by name. A no-longer-allowed character (a restriction added since it was sent) surfaces as a warning rather than silently blocking review |
| GET | `/{game_slug}/submissions/{id}/verification` | `be_import` | The embedded verification code checked against its issuing site, if the file carries one - the same seven-outcome check `GET /verify/{code}` runs, reachable here without a Storyteller visiting that page by hand |
| POST | `/{game_slug}/submissions/{id}/accept` | `be_import` | Imports through the same pipeline every import uses, with the sender forced onto the result: `wp_user_id` the sender, `player_name` cleared, `status` active, never an NPC, no narrator. A join makes the sender a member; a visit behaves exactly like accepting an inbound transfer. Emails the sender |
| POST | `/{game_slug}/submissions/{id}/refuse` | `be_import` | Turns it down with an optional note; nothing is written. Emails the sender |

## Chronicle Members

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/members` | `be_manage_games` | List this chronicle's members and roles |
| POST | `/{game_slug}/members` | `be_manage_games` | Add a member, or change an existing member's role |
| DELETE | `/{game_slug}/members/{wp_user_id}` | `be_manage_games` | Remove a member's chronicle-scoped access |

## Setup Status

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/setup-status` | `be_manage_characters` | The Chronicle Setup checklist's rows, computed live against real data every time — never stored, so nothing here goes stale between visits. Staff only (an HST or AST): a player is refused. |

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
| GET | `/docs/{slug}` | `be_view_characters` | Serves one of this plugin's own bundled guides (`st-guide`, `admin-guide`, `player-guide`, `rest-api` — this very document) as Markdown, `{ slug, content }`, for the in-plugin Docs screen and the help panel |
| GET | `/docs/help/{key}` | `be_view_characters` | One screen's help page, `docs/help/{key}.md`, as Markdown: `{ key, content }` - what a screen's `?` opens in the help panel. `404` for a key that names no file |

## Game Stats

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/stats` | `be_manage_characters` | The ST dashboard's aggregate numbers — character counts, pending changes, active plots (not counting each character's own plot), recent activity, and `players_without_active_character` (a count only; see the detail route below). Cached one minute; a review action invalidates the cache for its own chronicle immediately |
| GET | `/{game_slug}/stats/players-without-active-character` | `be_manage_characters` | The actual player list behind that count — every player-role member with zero `active` characters (no characters at all, or only a retired/dead/pending one — the same condition), resolved to a display name. Not itself cached; fetched only when the dashboard's roster-health card is opened |

## Sheets (character-sheet PDF, signed when the site has a certificate)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/sheets/pdf` | `be_view_characters` | Returns PDF bytes for one or more characters (`character_ids`, comma-separated, max 50). A manager may request any character in the chronicle; a non-manager only their own — one denied or missing id fails the whole request, as does one whose creature type no longer exists (`404 creature_stack_not_found`, naming the character). Signed when the site has a signing certificate; without one the PDF is stamped UNSIGNED on every page and its filename ends `-unsigned.pdf`. Optional `full_power_names`, `background`, `notes`, `xp_history` |
| GET | `/{game_slug}/sheets/availability` | `be_view_characters` | Preflight: is signing configured on this site right now (`ok: false` means prints come out unsigned) |

## Verify

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/verify/{code}` | none (public by design) | Confirms one exported/printed document's attestation code is real and unaltered — not game-scoped, and deliberately reachable by anyone with the link, not just chronicle members. Backs the `be-verify` page's human-facing check |

## Reports (the 20 GV301-plus reports, cards, and batch output)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/reports` | `be_view_reports` | Lists the reports the caller may run in this chronicle: key, title, shape, entity. Each report also needs its own capability - character and player reports `be_manage_characters`, plot/action/rumor reports `be_manage_plots`; the item/location/rote cards, Game Calendar, and House Rules need nothing more. Running a report without it is `403 report_forbidden` |
| GET | `/{game_slug}/reports/{report_key}` | `be_view_reports` | Returns the resolved report as plain JSON — no signing, no PDF. Built for a live front-end view (House Rules' own widget/shortcode use it); works for any report in the registry, not just House Rules |
| GET | `/{game_slug}/reports/{report_key}/pdf` | `be_view_reports` | Returns PDF bytes for one report, signed or stamped UNSIGNED exactly as a sheet is. `conditions`/`logic` scope a `table`/`card` report the same way the query builder does (an empty `conditions` means everyone in scope); `stat_field`/`stat_type` parameterize the generic Statistics Report; `character_id` (both this route and the JSON form above) narrows a `card`-shaped report (e.g. `item-cards`) to only the world objects connected to that one character — a manager may pass any character in the chronicle, a non-manager only their own (`404 character_not_found` for a mismatched game, `403 ownership_denied` for someone else's character). `404 report_not_found` for an unknown key |

## Point Audit

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{id}/point-audit` | `be_manage_characters` | The itemised point audit for one character — every held line, priced or explicitly marked unpriced with a machine-readable reason. Never `be_view_characters`/`be_edit_own_characters`: a grand total computed across a Storyteller-only block would leak its stored values arithmetically, so a non-manager gets `403`, never a reduced total. A character whose creature type no longer exists is `404 creature_stack_not_found`. `complete` is always `false` — this is not a bill, see [st-guide.md](st-guide.md) |

## Translations (catalog term translation)

Site-wide, not chronicle-scoped — one install, one language (Decision 106). Every route needs
`be_manage_translations`, granted independently of `be_manage_schemas`. `locale` on every
route below is a plain locale code (e.g. `pt_BR`), not validated against WordPress's own
installed-language list — a chronicle in a language with no WordPress core translation
installed can still be worked on here.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/translations` | `be_manage_translations` | One page of catalog terms for `?locale=`, left-joined with their translation. Filters: `status` (`untranslated` or a real `Translation::STATUSES` value), `block`, `search` (substring on the English term), `has_translation` (`1`/`0`). Paginated, `per_page` capped at 500 (§6's own D38/D52 warning against a missing-default truncation, against 8,298 rows) |
| GET | `/translations/progress` | `be_manage_translations` | Per-locale totals, a per-status breakdown, and a per-block breakdown (total terms and how many are translated) — the progress bar and status line on the Translations screen |
| GET | `/translations/locales` | `be_manage_translations` | Locales with at least one real translation row already (`with_rows`), plus every locale WordPress itself has installed (`installed`, `en_US` always first) — the language picker's own suggestions |
| POST | `/translations` | `be_manage_translations` | Creates or replaces one term's translation for a locale. Accepts either `string_id` or `source_text` — naming a term `rescan()` hasn't indexed yet creates its string row rather than 404ing. Body: `locale`, `translation`, `status` (defaults `draft`), and one of `string_id`/`source_text` |
| PATCH | `/translations/{id}` | `be_manage_translations` | Updates an existing translation row's own `translation` and/or `status`. `404 not_found` for an unknown id |
| DELETE | `/translations/{id}` | `be_manage_translations` | Clears a translation row entirely (not the catalog term itself, which stays indexed for a future translation) |
| POST | `/translations/bulk` | `be_manage_translations` | Sets many rows at once by `source_text`, matching `{ locale, rows: [{source_text, translation, status}] }` — backs "mark selected approved." One bad row is skipped and counted, never aborts the batch. Returns `{updated, skipped}` |
| GET | `/translations/export` | `be_manage_translations` | Downloads a CSV honouring the same filters `GET /translations` accepts, `text/csv` with a UTF-8 BOM: `source_text`, `translation`, `status` columns |
| POST | `/translations/import-csv` | `be_manage_translations` | Multipart CSV upload matching the export shape. `dry_run=1` reports `{added, updated, unchanged, unmatched, conflicts, sample}` without writing anything — a conflict is the file disagreeing with itself (two rows for the same term with two different translations), not the file disagreeing with what's already saved, which is an ordinary update |
| POST | `/translations/rescan` | `be_manage_translations` | Re-walks the real, current catalog and refreshes the string index against it — every schema block, system and every chronicle fork. Returns `{added, updated, orphaned}` |
