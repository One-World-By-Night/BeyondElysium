# REST API Reference

All routes are under the `be/v1` namespace - e.g. `/wp-json/be/v1/games`. Every write route requires a logged-in WordPress user; every route's permission is enforced server-side regardless of what any client UI shows or hides.

`{game_slug}` scopes a route to one chronicle. A request naming a game slug the caller has no relationship to returns `404` (not `403`), so a chronicle's existence is never leaked to someone outside it.

While an upgrade moves a site onto each creature type's own lists (a few seconds), every write to a route here is answered `503 catalog_switch_in_progress` and reads are not affected. A run that died holding its lock stops refusing after two minutes. See [Moving an Older Site to the Per-Creature Lists](admin-guide.md#moving-an-older-site-to-the-per-creature-lists).

**This reference is kept by hand against the controllers, not generated.** After any change to a controller, check it against that controller's `register_routes()` method in `includes/REST/`. A controller with no section here means this document is behind, not that the controller doesn't exist.

## Authorization

Every route below is gated by exactly one WordPress capability (or, where noted, any-of or all-of several), checked chronicle-scoped: `Authorization::check_request()` tries accessSchema first when enabled and reachable, then falls back to the caller's plain capability plus their row in `be_game_members` for that chronicle. See the [Storyteller Guide](st-guide.md#1-creating-a-game) for what each role typically maps to.

## Games

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/games` | `be_view_characters` | List *every* game on the install - correct for a staff picker, never safe to back a front-end chronicle switcher with (see `/my/games` below). A demo chronicle's account passwords are never returned |
| POST | `/games` | `be_manage_games` | Create a game: `name`, and optionally `slug`, `game_type`, `description`, `settings`, `asc_role_path` and `notifications_enabled` |
| GET | `/games/{slug}` | `be_view_characters` | Get one game. A demo chronicle's account passwords are never returned |
| PUT | `/games/{slug}` | `be_manage_games` | Update name, slug, description, settings, `asc_role_path`, `notifications_enabled`. `settings` merges into what is stored, key by key; `settings.enabled_factions` merges one level further, stack by stack and field by field, so a write names only the fields it changes (an empty list lifts that field's restriction). `settings.demo` is the only route that may ever write it - `{on, reset_hours (1/3/6/12/24, default 6), accounts: {storyteller, player}}`, both account ids real users of this site that are not administrators when `on` is true, or `400 invalid_param`; the same check runs when `POST /games` carries `settings.demo`. A demo's `accounts_password` is never written through this route or `POST /games`, and a reset never sets the password of an administrator account. Turning it on or off schedules or unschedules the chronicle's own reset |
| DELETE | `/games/{slug}` | `be_manage_games` | Delete a chronicle. One that still holds content (characters, plots, world objects, its own templates, schema-block forks or creature type layers, named saved queries, verification codes, transfers) is refused with `409 chronicle_has_content` and `data.counts`, unless `?with_content=1` - which deletes all of it, memberships included, in one transaction. Nothing is ever left under the slug. `POST /games` refuses an explicit slug still holding a deleted chronicle's content (`409 slug_has_orphaned_content`), and a rename onto one is refused (`409 orphan_collision`). Flagged as a demo, both delete and a slug change are refused outright with `403 demo_locked` until the flag is turned off |
| GET | `/games/{slug}/content` | `be_manage_games` | Counts what deleting the chronicle would delete with it (`characters`, `plots`, `world_objects`, `templates`, `schema_blocks`, `creature_stacks`, `saved_queries`, `attestations`, `transfers`) - the Games screen names these in its one delete confirmation |
| POST | `/games/{slug}/demo/reset` | `be_manage_games` | Resets a demo chronicle to its declared content now rather than waiting for its schedule. `400 not_a_demo` when the flag isn't on; `500 reset_failed` when its two accounts aren't both real users any more |
| GET | `/{game_slug}/demo` | `be_view_characters` | `{on, reset_hours, next_reset, last_reset: {at, counts} \| null}` - whether this chronicle is a demo, its cadence, when it next resets (`wp_next_scheduled()`), and when it was last reset |
| GET | `/my/games` | `be_view_characters` | Only the chronicles the caller actually holds a `be_game_members` row in, each with the role held there, whether it's flagged as a demo, and whether the chronicle is linked to accessSchema (`{slug, name, role, demo, asc_linked}`; `asc_linked` is true when the site reads accessSchema and the chronicle names an `asc_role_path`), plus, when accessSchema is on, each chronicle whose `asc_role_path` grants the caller a role, with the highest role held there - the real data source for the My Chronicle / Storyteller Toolkit chronicle switcher |
| GET | `/{game_slug}/my/capabilities` | logged in (any) | What the caller can actually do *in this one chronicle* - `be_manage_characters`, `be_manage_plots`, `be_manage_schemas`, `be_manage_connections`, `be_manage_boons`, each resolved through the same chronicle-scoped `Authorization::check_request()` every write route uses, not the site-wide snapshot every page load carries. An unresolvable `game_slug` or a caller with no relationship to this chronicle still returns `200` with every flag `false`, never an error - a switcher renders "no access here" rather than failing |
| GET | `/branding` | `be_manage_games` | The site-wide brand accent default, `{accent_color}` (a hex color, or empty for none) |
| PUT | `/branding` | `be_manage_games` | Set it: `accent_color`, a hex color such as `#1a1a1a`, or an empty string to clear it. `400 invalid_param` for anything else |

## Schema Blocks

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/schema-blocks` | `be_view_characters` | List blocks |
| POST | `/schema-blocks` | `be_manage_games` | Refused `403 book_read_only`: the book is read-only; a chronicle creates its own blocks on its route below |
| GET | `/schema-blocks/{slug}` | `be_view_characters` | Get one block; `?game_slug=` substitutes a chronicle's own fork if it has one |
| PUT | `/schema-blocks/{slug}` | `be_manage_games` | Refused `403 book_read_only`; a request naming `?game_slug=` is `400 use_chronicle_route` |
| DELETE | `/schema-blocks/{slug}` | `be_manage_games` | Refused `403 book_read_only` |
| POST | `/{game_slug}/schema-blocks` | `be_manage_schemas` | Create a block of this chronicle's own, with no book beneath it. Its slug must be one no block uses |
| PUT | `/{game_slug}/schema-blocks/{slug}` | `be_manage_schemas` | Update this chronicle's own block, or its copy of the book's, making the copy on the first edit; what changed is recorded over the book's values, so a later book correction beside it arrives. Any `description` object (`{reference, description, source}`, each HTML) on an item/power/level is sanitized server-side - formatting/lists/tables survive, images and scripts don't - regardless of what the caller submits. `500 save_failed` when the save didn't land - neither the change nor a new copy is kept |
| DELETE | `/{game_slug}/schema-blocks/{slug}` | `be_manage_schemas` | Delete this chronicle's own block or its copy of the book's, going back to the book |

An item, tiered-power level/family, resource pool, or identity field's `definition` entry may also carry an approval schedule beyond its flat `approval`: `approval_by_value` (trait_list items and resource_pool pools - an array of `{from, to, approval, reason?}` ranges resolved against the resulting value, a pool's schedule checked against its permanent rating only), a plain `approval` on a tiered_power level (each level is already its own row), or `approval_by_option` (identity_field - `{optionValue: {approval, reason?}}`, every value in a multiselect checked, strictest wins). See the [Admin Guide](admin-guide.md#approval-by-value-and-approval-by-option) for the editing UI.

## Approval Rules

The editing surface for the schedules described just above - one rule per catalog item/power/level/value-range/option that carries an approval override or a reason, across this chronicle's own trait_list, tiered_power, resource_pool, and identity_field blocks. A rule's `target_type` is one of `item`, `item_range`, `power`, `level`, `pool_range`, or `field_option`; `item_range`/`pool_range` address one `{from, to}` entry in the target's own `approval_by_value` array (both fields required on create/update), `field_option` addresses one option in the target field's `approval_by_option` map (`option` required), and `level` addresses one power level by its `level` number. A rule's response/list shape carries this as `extra` (`[from, to]`, the option string, or `null`) alongside the existing `level` field.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/approval-rules` | `be_manage_approval_rules` | List every rule currently set, this chronicle's own fork where one exists, the global block otherwise |
| POST | `/{game_slug}/approval-rules` | `be_manage_approval_rules` | Set (create or overwrite) one rule on a catalog item, item value-range, power, power level, pool value-range, identity field option, a whole block (`block_default`), or a tiered power block's in-type/out-of-type split (`block_in_type`, requires both `in_type` and `out_of_type`; `400 no_in_type_test` when no creature type in this chronicle declares an in-type test for the block) - forks the block for this chronicle if not already forked. `500 save_failed` when the rule didn't save - nothing is kept, a new copy included |
| GET | `/{game_slug}/approval-rules/options` | `be_manage_approval_rules` | The fixed vocabulary the create/edit form offers: real approval levels, and reason-tier presets |
| GET | `/{game_slug}/approval-rules/default` | `be_manage_approval_rules` | The chronicle's Default Approval Policy, removal/lowering switch, and OWBN Character Bylaws switch: `{ auto_approve, approval_on_removal, owbn_bylaws }` |
| PUT | `/{game_slug}/approval-rules/default` | `be_manage_approval_rules` | Set any one or combination: `{ auto_approve?: true \| false, approval_on_removal?: true \| false, owbn_bylaws?: true \| false }` - at least one is required. Every other chronicle setting is kept |
| PUT | `/{game_slug}/approval-rules/{id}` | `be_manage_approval_rules` | Update one rule. `500 save_failed` when it didn't save |
| DELETE | `/{game_slug}/approval-rules/{id}` | `be_manage_approval_rules` | Clear one rule, back to no override. `500 save_failed` when it didn't save |
| GET | `/{game_slug}/bylaws` | `be_manage_approval_rules` | The OWBN Character Bylaws reference list: every known Character Bylaw rule, each with its own `attachments` (`{clause_id, family, name, levels?, picks?, count_range?}` - `levels`/`picks` narrow a `tiered_power` attachment to the level or Elder-and-above pick it names, `count_range` (`{from, to}`) narrows a trait_list attachment to the new count being bought, the same shape `approval_by_value` uses) and a `link` to the real clause on council.owbn.net. Filters: `search` (substring match on `subject`), `tier` (substring match on `pc` or `npc`), `attached` (`true`/`false`). Paginated like any list route, `per_page` up to 2000 - the reference list is meant to stay readable in full |
| POST | `/{game_slug}/bylaws/refresh` | `be_manage_approval_rules` | Re-pulls every Character Bylaw clause from council.owbn.net live and writes the result as a site option that wins over the shipped file from then on. A clause still live keeps its existing attachment; one council has removed loses its reason; a new clause lists unattached. Returns `{ rule_count, attachment_count, added, removed, changed, generated_at }`. `502` on a network or parse failure - nothing changes |
| POST | `/bylaws/upload` | `be_manage_games` | Site-wide, not per chronicle. Uploads an already-built bylaws file (the shape `tools/bylaws/build.php` writes) as the site override, for a site that can't reach council.owbn.net directly. `multipart/form-data`, field `file`. `400 bylaws_malformed` on a file missing `rules`/`attachments` or a rule missing `clause_id`/`path`/`subject` - nothing changes |

**Default Approval Policy.** A chronicle's own baseline - used only when nothing above (an item, a power, a level, a value range, a field option, or the owning block's own `approval_rules.default`) resolved a level at all - is the plain `settings.auto_approve` boolean on the game itself (`false`/absent: everything needs `st` review by default; `true`: everything is `auto` by default), read and written through `/{game_slug}/approval-rules/default`, so the Storytellers who manage the rules can set it. A granular rule always wins over this default in either direction, in both the `resolve_approval_level()` resolution logic and by construction of the merge itself.

**The removal/lowering switch.** `settings.approval_on_removal` (absent/`false` is off), set through the same route, overrides every rule and the default alike: once on, `Change_Engine::catches_removal_rule()` classifies a proposed change from the character's own held sheet - never from what the client sends - as a removal (`remove_trait`), a lowered count, level or permanent pool value, or a relabel/rename (a `modify_trait` whose name, specialization or power pick differs from what is held), including a crafted `add_trait` that actually names an already-held row at a lower count or level. A caught change always waits, reasoned "Part of a change that removes, lowers or renames something.", and submitted through `/changes/submit` alongside siblings, every one of them waits with it under the same `submission_id`, whatever each one's own rule would otherwise say.

## Book Corrections

A change a chronicle made to a catalog block, a creature type or a sheet template is recorded with the book's value beneath it. When the book later changes that value, the change is a correction to review: derived on every read, never stored, and nothing moves anyone's XP.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/catalog-corrections` | `be_manage_schemas` | `{ corrections, count }`. Each correction has `kind` (`block`, `stack` or `template`), `target` (the block's or creature type's slug, or the template's id), `target_name`, `path` (steps into the document: a key, or a one-item list naming an entry), `labels` (each entry step's name, null for other steps), and `was`, `now` and `yours`, each with a `_set` flag. When the book removed the entry the change sits in, `removed` is true and `changes` lists every change inside it |
| POST | `/{game_slug}/catalog-corrections/keep` | `be_manage_schemas` | `{ kind, target, path }`: keep the chronicle's value, recording the book's value now beneath it; on a removed entry, the entry becomes the chronicle's own. `{ all: true }` keeps every correction. Answers with the list as it now stands. `400 invalid_param` for a malformed path, `404 not_found` when the chronicle has no such layer, `500 save_failed` |
| POST | `/{game_slug}/catalog-corrections/take` | `be_manage_schemas` | `{ kind, target, path }`: take the book's value, dropping the chronicle's change there and every change inside it. Forward only: no XP moves. Answers with the list as it now stands |

## Book Variants

A base block can have variants in the book: printings an edition or packet adds to it, or one that replaces it whole. A chronicle chooses its own; its copy of the base block is the book with them applied, then its own changes, and characters keep holding entries under the base block's slug.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/catalog-variants` | `be_manage_schemas` | `{ bases }`: each base block with variants, by name, with `base`, `base_name`, `variants` (`id`, `label`, `mode`: `add` or `replace`) and `chosen`, the ids this chronicle chose |
| POST | `/{game_slug}/catalog-variants/preview` | `be_manage_schemas` | `{ base, variants }`: the entries the chronicle's characters hold in the base block that match it now and would not with those variants chosen, each with `character_id`, `character`, `name` and `power_name`. A held entry is matched by name or alias; a custom entry always is. Changes nothing |
| PUT | `/{game_slug}/catalog-variants` | `be_manage_schemas` | `{ base, variants }`: choose them, the replacing one first, then each adding one in order, and rebuild the chronicle's copy of the base block; a copy left with no variant and no change of its own is removed. `400 unknown_base`, `unknown_variant`, or `one_replacement` when two replace; `500 save_failed`. Answers with `{ bases }` |

## Creature Stacks

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/creature-stacks` | `be_view_characters` | List the book's stacks. With `?game_slug=`, the stacks as that chronicle has them: its layer in place of each one it changed, its own creature types with no book counterpart at all, and only the creature types it allows; a Storyteller-only type (`stack_definition.storyteller_only`, Various) is listed for a caller holding `be_manage_characters` in every chronicle and for no one else |
| POST | `/creature-stacks` | `be_manage_games` | Refused `403 book_read_only`: the book's creature types are read-only |
| GET | `/creature-stacks/{slug}` | `be_view_characters` | Get one stack, resolved (blocks assembled) with `?resolve=true`. Add `?game_slug=` for the stack as that chronicle has it: its layer (a section it hid still listed, with `hidden: true`, and any section of its own), a chronicle's own creature type directly, and its own copies of blocks. Add `&for_creation=true` to also leave out hidden sections and the blocks only they show, and narrow identity-field options to that chronicle's `enabled_factions` restriction - the character-creation picker only; never applied when viewing or editing an existing character. Add `&character_id=` to also assemble the blocks that character holds beyond the stack's own sections, on a type that allows any block (`stack_definition.any_block`, Various); read only for a caller holding `be_manage_characters` or the character's own player |
| PUT | `/creature-stacks/{slug}` | `be_manage_games` | Refused `403 book_read_only` |
| DELETE | `/creature-stacks/{slug}` | `be_manage_games` | Refused `403 book_read_only` |
| POST | `/{game_slug}/creature-stacks` | `be_manage_schemas` | Builds a chronicle's own brand-new creature type from a `slug`, `name`, optional `game_line`, at least one `sections` entry (`block_slug`, optional `label`/`display_order`/`required`), and optional `creation_rules`. Refused `409 duplicate_slug` when the slug is already in use anywhere, book or chronicle; `400 unknown_block` when a section names a block the chronicle can't read |
| PUT | `/{game_slug}/creature-stacks/{slug}` | `be_manage_schemas` | Saves `stack_definition` and/or `creation_rules`, whole, for a chronicle's layer over a book type, or directly for a chronicle's own creature type |
| DELETE | `/{game_slug}/creature-stacks/{slug}` | `be_manage_schemas` | Removes a chronicle's layer over a book type, so it reads as the book's again. For a chronicle's own creature type (no book counterpart), deletes it outright; refused `409 creature_stack_in_use` (with `count`) while any character anywhere still holds it |
| POST | `/{game_slug}/creature-stacks/{slug}/sections` | `be_manage_schemas` | Adds a section for a block the chronicle can read, to a layer or a chronicle's own creature type; `400` when the block is unknown or already on the stack |
| PUT | `/{game_slug}/creature-stacks/{slug}/sections/{block_slug}` | `be_manage_schemas` | Hides or shows one section (`hidden`) |
| DELETE | `/{game_slug}/creature-stacks/{slug}/sections/{block_slug}` | `be_manage_schemas` | Removes a section the chronicle added; refused `400 book_section` for one the book itself declares |

## Characters

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters` | `be_view_characters` | List, with roster filters |
| POST | `/{game_slug}/characters` | `be_edit_own_characters` | Create - bootstrap-exempt: a player with no prior chronicle relationship can still create their first character here. Also creates the character's own plot, `<Character> [id] Plot`, linked to it as its actor (only its player and the chronicle's Storytellers see it); a character whose plot can't be written isn't created (`500 create_failed`). A new character holding anything in a section the chronicle has hidden is refused `400 section_hidden`, and a hidden section's starting entries are left out. A newcomer with no waiting join request is refused `403 join_requests_off` when the chronicle has join requests off |
| GET | `/{game_slug}/my/characters` | `be_view_characters` | Only the caller's own characters, ST-only-text stripped the same as every other non-manager read path |
| GET | `/{game_slug}/characters/{id}` | `be_view_characters` | One character; a non-manager may only view their own. `sheet_warnings` lists, for a caller holding `be_manage_characters` only, each path the sheet holds under one tradition spelled two ways (`block_slug`, `name`, `traditions`, `levels`); it is empty for everyone else |
| PUT | `/{game_slug}/characters/{id}` | `be_edit_own_characters` | Update. A non-manager is refused on a host's own kept-current copy while the visit is still open (`403 kept_current_elsewhere`, naming the real home chronicle) and again once it ends (`403 visit_ended`), until another visit reopens it - a manager is never blocked, since a host Storyteller's own changes are forwarded home on approval instead |
| DELETE | `/{game_slug}/characters/{id}` | `be_manage_characters` | Delete, cascading its changes/snapshots/sheet style |
| GET | `/{game_slug}/characters/statuses` | `be_manage_characters` | The fixed status vocabulary (`active`, `inactive`, `retired`, `dead`, `pending`) - sources the bulk-status picker rather than hardcoding the list a second time client-side |
| POST | `/{game_slug}/characters/bulk-status` | `be_manage_characters` | Set the same status on a batch of characters. Returns a per-character result (`{results: [{id, success, error?}], updated}`), never all-or-nothing - one bad or foreign id in the batch fails only that entry |
| POST | `/{game_slug}/characters/{id}/pool-purchases` | `be_manage_characters` | Grants one trait_list item, `{block_slug, name}`, paid for from the resource pool that block's `_meta.paid_from` names (e.g. a Wraith Thorn from the Shadow's own Shadow XP) instead of the character's own XP. Refuses `400 not_pool_funded` when the block declares none, `400 unknown_item` for a name not in its catalog, `400 insufficient_balance` when the pool can't cover it; records one approved `pool_spend` change on success |
| GET | `/{game_slug}/characters/{id}/hooks` | `be_manage_characters` | Every plot this character is connected to, split into `{open, resolved}` by the plot's own status, each `{plot_id, title, latest_entry_date}`. Excludes the character's own home plot (the automatic actor connection every character gets on creation) |
| GET | `/wp-users` | `be_manage_games` | Not game-scoped - a site administrator's search of WordPress accounts by display name, email or login |
| GET | `/{game_slug}/wp-users` | `be_manage_characters` | A chronicle Storyteller's account search, for assigning a player to a character or adding a player: `search` needs at least three letters of a name, and an email address comes back only when the search is that exact address. On a multisite it searches every account on the network, not only this site's |
| PUT | `/{game_slug}/characters/{id}/order/{block_slug}` | `be_manage_characters` OR `be_edit_own_characters` | Save a new order for the entries a character holds in a block whose `player_order` is on (`400 not_player_order` otherwise). Body: `order`, a list of the held entries' current positions in their new order, and `names`, the entry names in that same order. A player may do it for their own character only (`403 ownership_denied`). Nothing is priced or reviewed. `409 sheet_changed` when the list no longer matches what was loaded; `400 invalid_order` for a malformed pair. Answers `{order}`, the entries as saved |

## Who's Who (NPC and player-character public profiles)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/npcs` | `be_view_characters` | Every NPC whose Who's Who profile the viewer can see - a manager sees all of them. `{id, name, public_description, image_url, titles, factions}`, never the sheet |
| GET | `/{game_slug}/npcs/{id}` | `be_view_characters` | One NPC's profile, same shape. `404` for a non-manager the profile's audience doesn't reach, or for a real id that isn't an NPC |
| GET | `/{game_slug}/profiles` | `be_view_characters` | Every NPC and player-character profile the viewer can see, each one's `/npcs` shape plus `kind` (`npc`/`pc`), `played_by` (the player's display name, only when that character has `profile_show_player` set - otherwise `null`), and `portrait_attachment_id` (an id for `GET /{game_slug}/attachments/{id}`, when the character has uploaded one through the attachments route; `null` otherwise) |
| PUT | `/{game_slug}/characters/{id}/profile` | `be_edit_own_characters` | Updates a character's public-profile fields. A manager may set `public_name`, `public_description`, `public_image_id`, `profile_audience` (any of `everyone`/`storytellers`/`restricted`), and `profile_audience_rules`. A non-manager editing their own character may set `public_name`, `public_description`, `profile_audience` (`everyone` or `storytellers` - `400 audience_not_allowed` for `restricted`) and `profile_show_player`; `public_image_id` from a non-manager is refused `403 image_not_allowed`, and `profile_audience_rules` is ignored. `403 ownership_denied` for a character that isn't the caller's own and isn't manageable |

## Changes

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/changes` | `be_view_characters` | One character's change history. A non-manager never sees a host chronicle's `visit_note` or `visit_pairing`, and the page total leaves them out too |
| POST | `/{game_slug}/characters/{character_id}/changes` | `be_edit_own_characters` | Submit a change - auto-approves and applies immediately if the block's approval rules allow it. A non-manager is refused the same way `PUT /{game_slug}/characters/{id}` is, on a host's own kept-current-elsewhere or recently-ended copy (`403 kept_current_elsewhere`/`403 visit_ended`); a manager's own submission still goes through, forwarded to home on approval instead (see the host-forwarding rows above). A purchase with no catalog price (homebrew) is stored with `change_data.cost_pending: true` and `xp_cost` 0 and waits for a Storyteller to price it; it never auto-approves, and a player's own `chosen_cost` on homebrew is dropped. A purchase in a section the chronicle has hidden (an added entry, or a higher count, level or permanent rating) is refused `400 section_hidden`; lowering and removing go on, every read of what is held there too, and a change submitted before the section was hidden can still be approved. A Storyteller's `chosen_cost` on a custom trait prices it at submission, 0 to 500: per dot on a list counted in dots, once on a list of single purchases (`atomic`). A `modify_trait` or `remove_trait` on a tiered power reaches the held row with that name in the tradition `trait.tradition` (or, for a modify, `previous.tradition`) names, spelled as stored; with no tradition named it reaches every row with that name, as before. A tradition on an add or modify is stored in the catalog's spelling, and one the catalog does not list is refused `400 unknown_tradition` unless the row being modified is already stored under exactly that tradition; a modify that would respell a row into a path and tradition another row already holds is refused `400 already_held`. A change naming a section the character's creature type does not list is refused `400 unknown_block`, except on a type that allows any block (Various): there a Storyteller may name any section, and anyone else only one the character already holds. `xp_earn`/`xp_adjust` have their own rules for a non-manager - see [Experience](#experience) below. With `settings.approval_on_removal` on and this one change itself a removal, a lower rating, a relabel or a rename, it waits for a Storyteller on its own, with the reason "Part of a change that removes, lowers or renames something." - never from what the client claims, decided from the character's own held sheet. A `_meta.spent_from` power (Hunter Edges) always prices at 0 XP and is refused `400 not_enough_unspent` without enough unspent dots in its named pool, `400 rank_not_unlocked` without that same path's own next-lower rank already held, or `400 creed_restricted` for a family the character's own creed can't buy at all; a `_meta.raised_by` pool (Hunter's Virtues) always prices at 0 XP and is refused `400 not_enough_temporary` without enough temporary points in the pool it converts from |
| POST | `/{game_slug}/characters/{character_id}/changes/submit` | `be_edit_own_characters` | Submit a whole set of changes together, `{ changes: [...] }` (each shaped like the single-change route's own body), in one transaction under one shared `submission_id`. A non-manager is refused on a host's own kept-current-elsewhere or recently-ended copy, the same as the single-change route (`403 kept_current_elsewhere`/`403 visit_ended`). With `settings.approval_on_removal` on, any change in the set that is a removal, a lower rating, a relabel or a rename makes every change in the set wait together, with the shared reason. A validation or pricing failure on any one change refuses the whole set - `400`/`403`, nothing submitted. Returns `{ submission_id, changes: [...] }` |
| GET | `/{game_slug}/changes` | `be_manage_characters` | The approval queue - every pending (or filtered) change across the whole chronicle. Filters: `status`, `change_type`, `character_id`, and `approval_level` (`auto` or `st`), which pages and totals that level alone. A change waiting for a price also carries `cost_units`, `{ per: "dot" or "pick", units, negative }`: what one price covers - the new dots for a trait list, one pick for a power - so a client can show the total as it is typed. Each row also carries `submission_id` - two or more pending changes sharing one were submitted together |
| POST | `/{game_slug}/changes/batch-approve` | `be_manage_characters` | Approve several changes in one call. Returns `approved`, `skipped` (missing, already reviewed, edited since, or not allowed), `needs_cost` (changes waiting for a price) and `needs_secret_choice` (`log_knowledge` changes, which need per-row input - see [Secrets](#secrets)): none of these are ever approved in a batch, and are named here instead. Two or more of the given ids sharing a `submission_id` approve together, in one transaction, refunds and lowerings first, then the rest - any one of them failing skips the whole group; everything else in the call is still approved independently |
| GET | `/{game_slug}/my/changes` | `be_view_characters` | Only the caller's own pending changes, across every character they own, in this one chronicle. A non-manager never sees a `visit_note` or `visit_pairing` |
| GET | `/my/changes` | `be_view_characters` | The caller's own changes across every chronicle on the site where they have a character: anything pending, plus anything reviewed in the last 30 days, newest submission first. Each row carries `description` (plain language, the same `Change_Description::describe()` the approval queue uses), `display_status` (`pending`, `approved`, `auto_approved`, or `refused`), and `game_slug`/`game_name`. A `visit_note` or `visit_pairing` is never listed. Registered before `/{game_slug}/changes` so a chronicle literally slugged `my` can't shadow it |
| POST | `/{game_slug}/characters/{character_id}/preview-changes` | `be_edit_own_characters` | Price a set of proposed changes without submitting them. Each result has `xp_cost`, `priced` and `unpriced_reason` (`custom_no_catalog_entry`): `priced: false` means no price exists yet and a Storyteller sets it at approval, so the `0` is not a price and does not move `running_xp_unspent`. A change that fails real validation instead carries `invalid: true` and an `error` (`{code, message}`) - `xp_cost`/`priced` carry no real meaning on that row, since the change would be refused on an actual submit, not priced at all |
| PUT | `/{game_slug}/changes/{id}` | `be_manage_characters` | Approve or reject; sends the submitting player a notification email unless they or the chronicle opted out. Approving a change with `cost_pending` needs `xp_cost`, a whole number from 0 to 500 - per dot for a trait list counted in dots, the whole amount for a single purchase (an `atomic` list) or a power: without it the response is 400 `cost_required`, and a value outside that range is 400 `invalid_param`. The price is stamped on the trait, the total is deducted, and `cost_pending` is cleared. `xp_cost` is not read for any other change. Approving a `log_knowledge` change needs `secret_id` or `entity_type`/`entity_id` - see [Secrets](#secrets). Approving one for a visiting copy with an open, agreed keep-current visit forwards it to the character's real home instead of applying it - the row reads `forwarded`, never `approved`. Approving a `visit_note` - a note a host chronicle shared about its own visiting copy - has no sheet effect: it lands Storytellers-only on the character's own plot; refusing one records nothing |

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
| PUT | `/{game_slug}/characters/{character_id}/sheet-style` | `be_customize_sheet` | Update - a separate capability from `be_manage_characters`, grantable per-user |
| DELETE | `/{game_slug}/characters/{character_id}/sheet-style` | `be_customize_sheet` | Reset to defaults |

## Bulk Operations (Query Tool's own result-set actions)

Three bulk actions a Storyteller can run against a query's own selected results, all sharing the same shape: pick rows in the Query Tool, then apply one action to the whole selection at once rather than one character at a time.

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/experience/bulk-award` | `be_manage_characters` | Award the same amount of XP to several characters at once. A visiting copy with an open, agreed keep-current visit is forwarded to its real home instead - nothing changes here, and the award's own change row reads `forwarded` |
| POST | `/{game_slug}/resource-pools/bulk-reset` | `be_manage_characters` | Reset one named resource pool's temporary rating back to its permanent one, across a selection - the end-of-session Willpower/Blood refill, for a whole group at once. A character who doesn't hold the named pool, or belongs to a different chronicle, is silently skipped, not treated as an error |
| POST | `/{game_slug}/characters/bulk-status` | `be_manage_characters` | See Characters, above - listed here too since it's the same Query Tool bulk-action shape |

## Experience

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/experience/apply` | `be_manage_characters` | Apply a different, signed amount to each of several characters in one call: `{ reason, awards: [ { character_id, amount } ] }`, `amount` a whole number from -10,000 to 10,000, never zero. Each row stands on its own - refused `not_in_chronicle` for a character outside this chronicle, `below_zero` for a negative amount larger than the character's own XP Earned - and the response lists every row in order with its result and, once applied, its fresh totals. A positive amount writes an `xp_earn` change, a negative one `xp_adjust`, both approved immediately - unless the character is a visiting copy with an open, agreed keep-current visit, which forwards the award to its real home instead (`applied: true, forwarded: true`, nothing changed here) |

A player's own `xp_earn` submitted through `POST /{game_slug}/characters/{character_id}/changes` (above) is a request, not a direct award: `change_data` needs `request: { where, date?, note? }` (`where` required, up to 200 characters; `date` a real date no later than today; `note` up to 2,000 characters), and the server builds `change_data.reason` itself as `"Requested: {where}"` or `"Requested: {where}, {date}"`. It always waits for a Storyteller. `xp_adjust` through that same route is refused `invalid_change_type` for anyone without `be_manage_characters` on this chronicle - only a Storyteller may submit one, with the shape `{ amount, reason? }` unchanged.

## Query Fields

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/query-fields` | `be_view_characters` | The field catalog the query builder offers |

## Templates

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/templates` | `be_manage_templates` | List global (base) templates, for the template editor - a sheet gets its layout from `resolve` |
| POST | `/templates` | `be_manage_games` | Refused `403 book_read_only`: the book's templates are read-only |
| GET | `/templates/{id}` | `be_manage_templates` | One global template; a chronicle's own template is `404` here and listed on its own route |
| PUT | `/templates/{id}` | `be_manage_games` | Refused `403 book_read_only` |
| DELETE | `/templates/{id}` | `be_manage_games` | Refused `403 book_read_only` |
| GET | `/{game_slug}/templates/resolve` | `be_view_characters` | Resolve the effective layout for a creature stack in this chronicle (game fork if one exists, else the global template) |
| GET | `/{game_slug}/templates` | `be_manage_templates` | List this chronicle's own template forks |
| POST | `/{game_slug}/templates` | `be_manage_templates` | Make a template for this chronicle. One of the same creature type and kind as a book template records everything it differs by as its own, what it leaves out included, and a later book change beside that reaches it |
| PUT | `/{game_slug}/templates/{id}` | `be_manage_templates` | Update a chronicle's own template, recording what changed over the book's template of the same creature type and kind |
| DELETE | `/{game_slug}/templates/{id}` | `be_manage_templates` | Delete a chronicle's own fork |

## Plots

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/plots` | `be_view_characters` | List plots. `character_plots=only` keeps each character's own plot and its action rounds (plots tied to a character by `apr_actor`); `exclude` keeps every other plot; anything else is `400` |
| POST | `/{game_slug}/plots` | `be_submit_actions` OR `be_manage_plots` | Create - a player can start a plot thread via an action, an ST can create one directly. A non-manager's plot is always their own player plot (1.1.0 §2.3a): requires `character_id` (must be the caller's own, `403` otherwise), forced `audience: restricted`, connected via a `plot_owner` connection. A manager's plot defaults `audience` to `storytellers` (never player-reachable) and may set `audience`/`audience_rules` explicitly. A Storyteller's `is_rumor: true` saves the plot and its rumor tag together or neither - `500 create_failed` when nothing was kept |
| GET | `/{game_slug}/my/plots` | `be_view_characters` | Only the caller's own plots - theirs by connection, or reachable via `target_query` |
| POST | `/{game_slug}/plots/allocate-actions` | `be_manage_plots` | Allocate a round's action slots. With `commit`, the date's plot and every action entry are saved together or not at all - `500 allocation_failed` when nothing was kept. The date's plot sits under the character's own plot unless `parent_plot_id` names another |
| POST | `/{game_slug}/plots/generate-rumors` | `be_manage_plots` | Generate a date's standard rumors: a preview, or with `commit`, saved, and each matched player emailed once. A commit keeps every rumor or none - `500 generate_failed`, and no one is emailed - and two commits in one chronicle take turns, so the second finds the first's rumors and skips them |
| GET | `/{game_slug}/plots/{id}` | `be_view_characters` | One plot with its entry thread, connections, attachments (never `stored_name`), and `is_owner` (true only for the player who owns this plot, false for everyone else including a manager). Hidden by its own `audience`/`audience_rules` (1.1.0 §2.1) or another character's action allocation is `404` unless you manage plots, and never listed among a plot's children |
| PUT | `/{game_slug}/plots/{id}` | `be_manage_plots` | Update, including `audience` (`everyone`/`storytellers`/`restricted`) and `audience_rules` (`{conditions, logic}`, required non-empty when `audience` is `restricted`) - `400 invalid_param` for an invalid value or an empty `conditions` array |
| DELETE | `/{game_slug}/plots/{id}` | `be_manage_plots` | Delete, cascading its entries, connections, and attachments (rows and files). A character's own plot is refused (`409 character_plot`) - it goes when the character is deleted |
| GET | `/{game_slug}/plots/{id}/member-candidates` | `be_submit_actions` OR `be_manage_plots` | A player plot's own owner or a manager only (else `403 ownership_denied`): active, non-NPC characters not already connected, name and id only (1.1.0 §2.3a) |
| POST | `/{game_slug}/plots/{id}/members` | `be_submit_actions` OR `be_manage_plots` | Add a `plot_member` connection - the owner or a manager only; a non-manager is held to the candidates list even if they post a `character_id` directly |
| DELETE | `/{game_slug}/plots/{id}/members/{connection_id}` | `be_submit_actions` OR `be_manage_plots` | Remove a `plot_member` connection - a manager may remove anyone, the owner only whoever they themselves added (`403 not_your_addition` otherwise). The owner's own connection is never reachable here |
| GET | `/{game_slug}/plots/{id}/visible-characters` | `be_manage_plots` | Every character who can currently see this plot, name and id only - backs the directed-entry picker |
| PUT | `/{game_slug}/plots/{id}/rumor-levels` | `be_manage_plots` | Set a rumor's level texts as a set: `levels`, an object of level number (1 to 10) to text. A level named with text is written, one sent empty is deleted, one left out is untouched |

## Entries (plot responses/actions)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/plots/{plot_id}/entries` | `be_view_characters` | List a plot's entries (`404` for another character's action allocation, as above), each carrying its own `audience` (`plot`/`storytellers`/`characters`), `shared_at` and, for a directed entry, `audience_character_ids` |
| POST | `/{game_slug}/plots/{plot_id}/entries` | `be_submit_actions` OR `be_manage_plots` | Add an entry. `audience` defaults to `plot`; a non-manager may only choose `plot` or `storytellers` (`403` for `characters`); `characters` requires a non-empty `audience_character_ids`, each id a character who can currently see the plot (`400` otherwise). `share_with_home` (bool) shares the entry's own content as a note with the plot's character's real home chronicle, on an online visit (any state but terminal, or ended within 30 days), kept current or not - silently does nothing on an ineligible visit or a hand-carried copy with no recorded home, stamping `shared_at` only when it actually sent |
| PUT | `/{game_slug}/entries/{id}` | `be_submit_actions` OR `be_manage_plots` | Update. `audience`/`audience_character_ids` are left untouched entirely when not sent - a plain content edit never resets a chosen audience back to `plot`. `share_with_home` shares again as its own new note, exactly as on create |
| DELETE | `/{game_slug}/entries/{id}` | `be_manage_plots` | Delete |

## Secrets

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/secrets` | `be_view_characters` | Every secret on one entity (`entity_type`/`entity_id` required: `plot`/`item`/`location`/`npc`). Audience-filtered and `[ST]`-stripped for a non-manager |
| POST | `/{game_slug}/secrets` | `be_manage_plots` | Create, attached to a real entity in this game. `title` required; `content`, `audience`, `audience_rules` as a plot's own |
| PUT | `/{game_slug}/secrets/{id}` | `be_manage_plots` | Update `title`/`content`/`audience`/`audience_rules` |
| DELETE | `/{game_slug}/secrets/{id}` | `be_manage_plots` | Delete, cascading every reveal on it |
| GET | `/{game_slug}/secrets/{id}/reveals` | `be_manage_plots` | Every reveal of one secret |
| POST | `/{game_slug}/secrets/{id}/reveals` | `be_manage_plots` | Reveal it to a character: `character_id` required, `how` one of `game`/`downtime`/`rumor`/`told`/`other` (default `game`), optional `note`, `held`, `release_batch_id`. `409 already_revealed` for a character already revealed this secret |
| DELETE | `/{game_slug}/secrets/{id}/reveals/{reveal_id}` | `be_manage_plots` | Remove one reveal |
| GET | `/{game_slug}/secrets/all` | `be_manage_plots` | Every secret in the chronicle, optionally narrowed by `search` against the title - for the Approval Queue's tie-to-an-existing-secret picker, never audience-filtered |
| GET | `/{game_slug}/my/secrets` | `be_view_characters` | `{known, waiting}` - `known` is every secret revealed to one of the caller's own characters (`id`, `reveal_id`, `character_id`, `character_name`, `title`, `content`, `entity_type`, `entity_name`, `how`, `told_by`, `approved`, `can_pass`, `learned_at`); `waiting` is the caller's own pending or refused `log_knowledge`/`pass_secret` changes |
| GET | `/{game_slug}/my/secrets/people` | `be_view_characters` | The characters the caller may name as the person who told them something: their Who's Who plus the characters at the far end of a character-to-character connection from one of their own active characters, their own characters among them when they are in Who's Who or connected, sorted by name - `[ { id, name, kind } ]` with `kind` `pc` or `npc`. A manager gets every active character |
| POST | `/{game_slug}/my/secrets/log` | `be_view_characters` | Logs what one of the caller's own characters learned - `character_id` (the caller's own), `title`, `details`, `how`, and optionally `teller_character_id` or `teller_name`. Files a pending `log_knowledge` change, always `st`-level. `403 secret_passing_off` when the chronicle has this off; `400 invalid_param` when `teller_character_id` is not on that list or is the learning `character_id` itself |
| POST | `/{game_slug}/secrets/{id}/pass` | `be_view_characters` | Tells another character a secret the caller's own character knows - `from_character_id` (the caller's own), `to_character_id`, optional `note`. Refuses, in order: `403 secret_passing_off`; `403` the teller isn't the caller's own character; `404` the teller hasn't learned the secret (an unreleased held reveal included - never confirms the secret exists); `403 knowledge_not_approved`; `400 secret_already_public` (`everyone` audience); `404` the recipient isn't in the caller's Who's Who; `400` telling yourself; `409 already_known`. Files a pending `pass_secret` change, always `st`-level; on `immediate`, also writes an unapproved reveal at once, which the recipient can read but not pass on until a Storyteller approves the change |

Approving a `log_knowledge` change (`PUT /{game_slug}/changes/{id}`) also takes either `secret_id` (an existing secret) or `entity_type`/`entity_id`/`title`/`content` (a new one); `400` when neither is given. Approving either `log_knowledge` or `pass_secret` needs `be_manage_plots` as well as the route's own `be_manage_characters` (`403 secret_capability_denied` otherwise, reject still allowed); a batch approval skips a `log_knowledge` change into its own `needs_secret_choice` bucket, since it cannot be decided without per-row input.

## Action & Rumor Settings (APR)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/apr-settings` | `be_manage_apr` | This chronicle's downtime-action and rumor-generation configuration |
| PUT | `/{game_slug}/apr-settings` | `be_manage_apr` | Update it |
| GET | `/{game_slug}/apr-settings/backgrounds` | `be_manage_apr` | The catalog of Backgrounds available to configure as action-granting |

## AI Assist

Server-side AI writing-assist integration (Admin Guide's "AI Writing Assist" section). Two route pairs, both handled the same way: a site-wide pair for fields that belong to no chronicle, and a chronicle-scoped pair for everything else. The generate route's own required capability is resolved server-side from the request's `field_context`, never trusted from the client.

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/ai-assist` | Resolved per `field_context` | Generate a suggestion for a site-wide field (Schema Block catalog descriptions, Credits text) |
| GET | `/ai-assist/settings` | `be_manage_games` | The site-wide provider, key-configured flags, and custom base URL/model overrides - never the key itself |
| PUT | `/ai-assist/settings` | `be_manage_games` | Update the site-wide provider, key (empty string clears it), base URL, or model |
| POST | `/ai-assist/test` | `be_manage_games` | Test a provider/key/base URL/model combination directly from the request body - never a saved key |
| POST | `/{game_slug}/ai-assist` | Resolved per `field_context` | Generate a suggestion for a chronicle-scoped field (character, plot, rumor, world-object). `403 demo_locked` on a chronicle flagged as a demo |
| GET | `/{game_slug}/ai-assist/settings` | `be_manage_apr` | This chronicle's own opt-in, provider, key-configured flags, and base URL/model overrides |
| PUT | `/{game_slug}/ai-assist/settings` | `be_manage_apr` | Update this chronicle's own AI Assist settings. `500 save_failed` when the save didn't land |
| POST | `/{game_slug}/ai-assist/test` | `be_manage_apr` | Test a provider/key/base URL/model combination for this chronicle, before saving |
| POST | `/{game_slug}/ai-assist/npc-draft` | `be_manage_characters` | Draft an NPC's Storyteller-only roleplaying-notes fields from that NPC's own name, creature type, identity, public profile, biography, and notes. Returns `{data: {...}}` only - nothing is written. `403 demo_locked` on a demo chronicle |
| POST | `/{game_slug}/ai-assist/plot-draft` | `be_manage_plots` | Draft a plot from a one-line premise, optionally grounded in picked characters/NPCs (their public profile and identity fields only) and factions. Unlike every other AI Assist route, this one writes immediately rather than returning a preview: one transaction creates the plot (`audience: storytellers`), 3-5 Storyteller-only `note` entries, and 2-3 held, tagged rumors, rolling back entirely on an invalid or incomplete reply. Returns `{plot_id}`. `403 demo_locked` on a demo chronicle |
| POST | `/{game_slug}/ai-assist/recap-draft` | `be_manage_sessions` | Draft a session's recap fields from that session's own attendance and after-game reports. Returns `{data: {...}}` only - nothing is written. `403 demo_locked` on a demo chronicle |

## Background Uses

Recording what a player actually did with an allocated downtime action, and an ST adjudicating it.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/spendable` | `be_view_characters` | How many actions this character has left to spend on the current game date |
| GET | `/{game_slug}/characters/{character_id}/background-uses` | `be_view_characters` | List this character's recorded uses |
| POST | `/{game_slug}/characters/{character_id}/background-uses` | `be_submit_actions` OR `be_manage_characters` | Record what the player did with one action. `500 record_failed` when it couldn't be saved - nothing is kept |
| POST | `/{game_slug}/characters/{character_id}/background-uses/clear` | `be_manage_characters` | Clear every use recorded against this character for one game date |
| POST | `/{game_slug}/background-uses/clear-date` | `be_manage_characters` | Clear every character's recorded uses for one game date at once |
| PUT | `/{game_slug}/background-uses/{id}` | `be_submit_actions` OR `be_manage_characters` OR `be_manage_plots` | Update one recorded use - an ST filling in the adjudicated result, or the player editing their own before it's reviewed |
| DELETE | `/{game_slug}/background-uses/{id}` | `be_submit_actions` OR `be_manage_characters` | Delete one recorded use |

## Connections

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/connections` | `be_manage_connections` OR `be_manage_plots` | List connections between characters/objects. Staff only: the list shows who holds what and whose action allocation is whose. Every id on a row (`id`, `game_id`, `source_id`, `target_id`, `created_by`) is an integer, and `target_id` is `null` when the connection has no target |
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
| POST | `/{game_slug}/world-objects/{id}/copy-for-character` | `be_manage_world_objects` | Copy an item for one character: `character_id`, and an optional `name`. The copy keeps every field, property and audience rule, is always restricted, connects to that character as its holder, and starts its own history. Items only (`409 items_only`) |
| POST | `/{game_slug}/world-objects/{id}/use` | `be_manage_world_objects` OR `be_edit_own_characters` | Spend one use of an item: `character_id`, and an optional `note`. The holder's own player or a Storyteller (`403 not_holder`); `400 no_uses` for an item with no uses set, `409 used_up` or `409 expired` |
| POST | `/{game_slug}/world-objects/{id}/transfer` | `be_manage_world_objects` | Hand an item to another character: `how` (`given`, `traded`, `stolen` or `lost`), `to_character_id` (left out for `lost`) and an optional `note`. Recorded in the item's history. A boon is not an item (`409 use_boon_ledger`) |
| GET | `/{game_slug}/world-objects/{id}/events` | `be_manage_world_objects` | An item's own history, oldest first. Each event is `given`, `taken`, `traded`, `stolen`, `lost`, `used`, `copied`, `proposed` or `adjusted` |
| POST | `/{game_slug}/world-objects/{id}/revoke-cards` | `be_manage_world_objects` | Revoke every verification code ever printed for one item. A card already printed then verifies as revoked; a reprint issues a new code |
| GET | `/{game_slug}/locations/{id}/links` | `be_view_characters` | A location's named links, `[{id, label, source_type, source_id, name}]`, where `label` is `owner`, `domain`, `haven` or `based_at`. A manager sees every link. Anyone else gets only the "who's here" roster: the NPCs based there whose own public profile reaches them |
| POST | `/{game_slug}/locations/{id}/links` | `be_manage_world_objects` | Add a link: `label` (one of the four) and `source_id`, a character in this chronicle. `400 invalid_param` |
| DELETE | `/{game_slug}/locations/{id}/links/{link_id}` | `be_manage_world_objects` | Remove one link |

## Item Catalog (declared, read-only book data)

A chronicle-less, book-sourced item list - one JSON file per rulebook, shipped with the plugin, never stored in a database table. Backs **Add from the book** on Items & Locations and **Start from a book entry** on Propose an Item; both read this route, then create or propose an ordinary item through the routes above.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/items/catalog` | `be_view_characters` | Not game-scoped - any signed-in account reads it. `{ items, books }`: `items` filtered by `search` (name substring, case-insensitive), `book` (a book's own slug, exact), and `item_type` (case-insensitive exact match against `properties.item_type`); each carries `key`, `name`, `object_type`, `description`, `properties`, `source { book, code, page }`, `book`, `book_slug`, and `book_ref` (`{book_slug}:{key}`, what `properties.book_ref` is stamped with once copied into a chronicle). `books` lists every book with at least one item, each `{slug, name}` |

## Attachments

Files on a plot, item, location, or character - images and PDFs, 10 MB each, up to 20 for a plot/location, exactly 1 for an item or a character. A character's own attachment is image-only (no PDF). Never served from the WordPress media library; see Admin Guide "File Uploads."

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/attachments` | `be_submit_actions` OR `be_manage_plots` OR `be_manage_world_objects` | Upload. Requires `entity_type` (`plot`/`item`/`location`/`character`) and `entity_id`, plus one file in the request's file params. The real gate is per-entity: a Storyteller always, or a plot's own owner (`403` otherwise); items/locations are `be_manage_world_objects` only; a character is its own owner or a manager - reachable by a plain player through the same `be_submit_actions` every player role already holds. `400 invalid_file_type`/`file_too_large` against the file's real content, never its claimed type (images only for a character); `409 limit_reached` at the cap |
| GET | `/{game_slug}/attachments/{id}` | `be_view_characters` | Streams the raw file bytes (not JSON) once the caller's audience reaches the owning entity - `404` otherwise, with nothing about the file disclosed. For a character, its own owner always reaches it, whatever the character's Who's Who audience |
| DELETE | `/{game_slug}/attachments/{id}` | `be_submit_actions` OR `be_manage_plots` OR `be_manage_world_objects` | Delete - the database row and the file together, or neither. Same per-entity gate as upload |

## Boons

`be_manage_boons` is granted site-wide to every WordPress role, including `subscriber` - the real per-chronicle gate is still the caller's own `be_game_members` role there (`boons`, `hst`, or `ast`); a logged-in visitor with no membership in this chronicle still gets `403` from the write routes below.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/boons` | `be_view_characters` | The ledger - whole-game, or one character's owed/owed-to-them split |
| POST | `/{game_slug}/boons` | `be_manage_boons` | Record a new boon |
| PUT | `/{game_slug}/boons/{id}/repay` | `be_manage_boons` | Mark a boon repaid - a symmetric transactional update. `500 save_failed` when it didn't save |

## Import (exchange files - `.gex`, character or game-scoped)

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/import/parse` | `be_import` | Upload and parse; returns a preview, stores the job for one hour |
| GET | `/{game_slug}/import/{job_id}` | `be_import` | Re-fetch a stored job's current state |
| POST | `/{game_slug}/import/{job_id}/commit` | `be_import` | Commit - refuses while anything is unresolved/flagged; transactional; safe to re-POST |

A character whose own creature type has no shipped stack on this site, or isn't enabled in this chronicle, is refused rather than written: the preview's `refused` array carries one `{character, reason}` row per such character, and commit's `characters` array reports that row with `action: "refused"` and `id: 0` instead of creating it. Everyone else in the same file still imports.

## Export

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/characters/{id}/export` | `be_manage_characters` OR `be_edit_own_characters` | Export one character to a Grapevine `.gex` XML document. `422 not_exportable` for a creature type with no Grapevine equivalent (one an administrator added). `hide_st` asks for a player's copy - no Storyteller-only block, no `[ST]...[/ST]`-marked text in notes, biography, or boon terms - and only matters to a Storyteller: a player's own export is always that copy, whatever the request says. `as_transfer: true` is refused (`400 use_transfer_route`); a transfer document comes only from `/transfers/outbound`. `verify` mints a fresh attestation embedded in the document, so each call issues a new code - not free to call repeatedly for the same download, and its stored `document_hash` covers this exact document (redacted or not) rather than the sheet's own always-unredacted `sheet_hash`, so a later re-check compares against what was actually handed over |

## Game Import (full `.gv3` game files - not game-scoped, since a new chronicle may not exist yet)

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/import/game/parse` | `be_import` AND `be_manage_games` | Upload and parse a full game file |
| GET | `/import/game/{job_id}` | `be_import` AND `be_manage_games` | Re-fetch a stored job |
| POST | `/import/game/{job_id}/commit` | `be_import` AND `be_manage_games` | Create a new chronicle, or merge into an existing one, from the file's contents |

A full game file's plots, rumors and actions have a real import destination. The preview carries their totals in `counts` and, in a `narrative` object, `{plots, rumors, actions}: {already_present}` (a dry-run count of what re-importing would skip, by the same title-and-date rule the commit itself uses) and `unmatched_names` (cast or action character names matching nobody, in the file or the target chronicle). `commit`'s own `resolutions.import_kinds` (`{plots?, rumors?, actions?}`, each a boolean, ticked - imported - when absent) chooses which of the three to write; the result reports `plots`/`rumors`/`actions` (created) and `skipped_plots`/`skipped_rumors`/`skipped_actions` (already present, or every one of that kind when its own checkbox was off) alongside the existing `items`/`locations`/`rotes`/`characters`, plus `unmatched_cast`/`unmatched_actors` naming who matched nobody. A rumor's own Grapevine query converts to a real audience rule when it is exactly one condition this install recognizes; a query needing more than that (several conditions at once, a negated one, or one needing both a trait name and a count) keeps the rumor Storyteller-only with a note on the plot explaining why, rather than guessing. Queries themselves, XP awards, templates, calendar entries, and action/rumor allocation settings are still left out - their own counts stay in `skipped`.

## Transfers (chronicle-to-chronicle visits)

Sending a character to visit a different chronicle - on this install, or a different Beyond Elysium site entirely - with a real handshake and attestation rather than a plain export/re-import. A Storyteller approves each side: the home chronicle sends it, and the host chronicle reviews the offer and accepts or refuses it. A visit never takes the character away from home - it stays active there the whole time, and home's own character list shows every chronicle it's also active at (`GET /{game_slug}/characters`' `visits` field, each carrying `keep_current`, `delivered_at` and `unreachable_since`). Any number of visits can be open for a character at once, one per host. A character's own `visiting_from` (the single inbound row, if any) carries `unreachable_since` too - set on whichever side has gone a full day without a confirmed delivery or receipt of a kept-current update, cleared by the next one that gets through; a plain (not kept-current) visit never carries it, since nothing is ever scheduled to arrive on one.

**States.** Home: `offered` (sent, waiting) → `visiting` (the host accepted) → `ended`, or `declined` / `expired`. Host: `offered` → `visiting` → `ended`, or `refused` / `expired`. `released` (home) and `retained` (host) mean the same thing from each side: the sheet of record moves to the host for good. There is no Acknowledge route - once a host accepts, it calls home directly and home's own row moves by itself; either side ending a visit calls the other the same way, each call carrying a fresh, short-lived verification code the receiving site calls back to confirm. A side that can't be reached, or refuses the code, is not retried yet - the local action already stands regardless.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/transfers` | `be_manage_characters` | List the transfers this chronicle is party to on this site - outbound rows it is home to, inbound rows it hosts - without stored payloads |
| POST | `/{game_slug}/transfers/outbound` | `be_manage_characters` | Send a character. Always returns the transfer document; given `host_site` and `host_slug`, also offers it to that chronicle, and the row stays `offered` until the host answers. An optional `keep_current` asks to keep the character's sheet current at the host for as long as the visit lasts - carried with the offer, the host must still agree. `422 not_exportable`, recording nothing, for a creature type with no Grapevine equivalent. `409 already_travelling` while the character already has an open visit to that same host, including one another request recorded a moment before (a visit to a *different* host is unaffected - any number can be open at once); `500 create_failed` when the transfer didn't save. `403 demo_locked` on a chronicle flagged as a demo |
| POST | `/{game_slug}/transfers/{id}/release` | `be_manage_characters` | Home side: give a `visiting` character up for good. Tells the host, whose own row moves to `retained` |
| POST | `/{game_slug}/transfers/{id}/decline` | `be_manage_characters` | Home side: cancel an `offered` transfer. Revokes its verification code, so an offer still waiting at the host can no longer be accepted |
| POST | `/{game_slug}/transfers/inbound` | none (public by design) | Called by the *sending* site. Verified against the sender's own `/verify/{code}`, which must answer for a code issued for a transfer (a verified export's code is refused, `400 verify_failed`); records an `offered` row holding the document and emails this chronicle's HSTs and ASTs. Returns `202 {pending_review: true}`. 10 requests a minute per IP; `409 already_offered` while the character is already waiting or visiting here, including an offer recorded a moment before; `500 create_failed` when the offer didn't save; `429 too_many_offers` past 50 waiting offers. `403 demo_locked` on a chronicle flagged as a demo. A `home_site` with a link-local, unspecified or shared address is refused `400 unsafe_site` before any request is made to it |
| GET | `/{game_slug}/transfers/{id}/review` | `be_import` | Host side: an `offered` transfer's import preview - the same shape `GET /{game_slug}/import/{job_id}` returns. A duplicate matched in this chronicle also carries `changes`: one `{section, entry, here, arriving}` row per difference from the sheet already here (`here` null for something only arriving, `arriving` null for something only here); a match in another chronicle carries none |
| POST | `/{game_slug}/transfers/{id}/accept` | `be_import` | Host side: accept an offer with import `resolutions`. Asks the home site again first (`400 verify_failed` if it cancelled), needs a decision for every duplicate (Skip is refused - refuse the offer instead), imports in one transaction, moves the row to `visiting`, then calls home directly so its own row moves too. An optional `keep_current_accepted`, true only when home itself asked, sets `keep_current`/`keep_current_accepted` on both rows at once |
| POST | `/{game_slug}/transfers/{id}/refuse` | `be_import` | Host side: turn an offer down; nothing is written |
| POST | `/{game_slug}/transfers/{id}/send-home` | `be_manage_characters` | Host side: end a visit (`visiting` → `ended`). Tells home, whose own row ends too |
| POST | `/{game_slug}/transfers/{id}/retain` | `be_manage_characters` | Host side: keep a visiting character for good (`visiting` → `retained`). Tells home, whose own row moves to `released` |
| POST | `/{game_slug}/transfers/{uuid}/from-host` | none (public by design) | Home side: the host telling this chronicle its own accept or end of a visit, or (`type change`/`note`) a local change the host could not apply - a visiting copy with an open, agreed keep-current visit forwards here instead of applying anything. A `note` files as a new `visit_note` change, no sheet effect; approved, it lands Storytellers-only on the character's own plot. (`type moved`) the host renamed itself - identified by the slug this chronicle still has on file, carrying the new slug and chronicle name to replace it with; this chronicle's own outbound row is updated, nothing else. (`type pairing`) a host's request to pair a player-submitted character with this chronicle: names no existing visit at all, since this chronicle has never heard of the character - the file's own home-issued code is resolved locally (no network call) to find which character this is really about, and a pending `visit_pairing` change is filed, never auto-approved; approving it creates a new outbound visit here (`visiting`, both keep-current flags on) and tells the host back; refusing it, or 60 days with no answer, leaves the host's own copy unpaired. Verified against the host's own `/verify/{code}`, bound to this exact call (a code issued for a different visit, state, or call type is refused); refuses a call whose verified issuer doesn't match the claimed host site; refuses past 50 items still waiting from that one host (`429 too_many_waiting`); refuses a `change` whose `change_type` the changes route itself would not accept (`400 invalid_param`); refuses a second `pairing` for the same character from the same host while one is waiting (`409 already_waiting`); refuses a host site with a link-local, unspecified or shared address (`400 unsafe_site`). Markup in a forwarded change or note is cleaned with `wp_kses_post()`, and the approval queue names the host's site address beside its name on a `pairing`. 10 requests a minute per IP |
| POST | `/{game_slug}/transfers/{uuid}/from-home` | none (public by design) | Host side: home telling this chronicle a visit ended, a keep-current change, or (`type update`) a kept-current character's own sheet - name, `sheet_data`, XP earned and unspent, and the next sequence - verified against the home site's own `/verify/{code}` (kind `transfer`, the same kind the original visit's own code carries) and refused at or below the sequence already applied (`409 stale_sequence`). A snapshot is taken first; every `trait_list`/`tiered_power` entry is re-checked against this chronicle's own catalog independent of what it was at home, landing `custom` where this chronicle has no match; one row is added to the visit's own `update_log` (newest 50: when, sequence, the sections that changed, the entries that landed custom). Plot links, attendance, connections and this chronicle's own copies of blocks are never touched. (`type pairing_accepted`) home confirming it approved a pairing request - sets `keep_current_accepted` on this chronicle's own already-existing row and records home's own real uuid for it, since a paired character never shares the same uuid on both sides. 10 requests a minute per IP |
| POST | `/{game_slug}/transfers/{id}/keep-current` | `be_manage_characters` | Either side: turn keep-current on or off (`on`) for an open visit, naming either side's own row by its own id. Turning on sets this row's own `keep_current`; the other side learns of it once the visit is `visiting`. Turning off clears `keep_current` and `keep_current_accepted` here and, the same way, at the other side |
| POST | `/{game_slug}/transfers/{id}/keep-current/accept` | `be_manage_characters` | Host side: agree to keep a `visiting` character current, after review (`accept`'s own `keep_current_accepted` is the same agreement at review time). Tells home, which sets `keep_current`/`keep_current_accepted` to match |
| POST | `/{game_slug}/transfers/{id}/note` | `be_manage_characters` | Host side: share a free-text note about a visiting character with its real home, on an open, agreed keep-current visit. No sheet effect either way |

A character matched by its identity (uuid) is only this chronicle's to overwrite when it lives here. One that lives in another chronicle on this site is reported as `matched_by: "uuid_elsewhere"` and can be skipped or imported as a new character with its own identity - never overwritten. This applies to every import, not only transfers.

## Submissions (a player sending their own Grapevine file straight to a chronicle)

The player-initiated counterpart to Transfers: no Storyteller on the sending end at all - anyone signed in can send an exported `.gex` to any chronicle, joining it or visiting for a game. It reuses the same review/accept/refuse shape Transfers already established, keyed on `game_id` rather than a slug so a chronicle rename can't orphan a waiting file.

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/submissions/preview` | `be_edit_own_characters` | Bootstrap-exempt, same rule as character creation. Reads the upload and reports which of its characters can be sent here, with a reason for one that can't; stores nothing - `413 file_too_large` past 5 MB, `400 unsupported_format` for a `.gv3`, `400 invalid_format` for anything else unrecognized, `422 no_character` for a file with none |
| POST | `/{game_slug}/submissions` | `be_edit_own_characters` | Bootstrap-exempt. Re-reads the upload and records the request; `character_index` picks one out of several, `arrival` (`joining` or `visiting`, required), an optional `home_chronicle`, and `keep_current` (bool) - asks a visiting submission's host to try pairing it with its real home once accepted; stored on the row, has no effect on a `joining` one. `400 choose_character` for several characters with no index; `400 not_allowed` when the chosen character fails the same allowed-here check `preview` reports; `409 join_already_requested` for a non-member who already has a pending hand-built join character here (and the reverse, from `POST /{game_slug}/characters`, checks this table too); `409 already_waiting` for a second file to the same chronicle; `403 join_requests_off` for a `joining` file from a newcomer with no waiting join request when the chronicle has join requests off; `429 too_many_waiting` past 50 chronicle-wide. Emails every HST and AST |
| POST | `/{game_slug}/submissions/{id}/withdraw` | `be_edit_own_characters` | The sender only (`404 submission_not_found` for anyone else, or a wrong `game_slug`) - `waiting` → `withdrawn` |
| GET | `/my/submissions` | `be_view_characters` | The caller's own last 20, any chronicle. Registered before `/{game_slug}/submissions` so a chronicle literally slugged `my` can't shadow it |
| GET | `/{game_slug}/submissions` | `be_import` | This chronicle's `waiting` rows, each with `sender_name` |
| GET | `/{game_slug}/submissions/{id}/review` | `be_import` | The same preview shape `GET /{game_slug}/import/{job_id}` returns, plus `overwrite_allowed`/`existing_owner` on each duplicate - a player-sent file can never overwrite a character it doesn't already own, even one it matches by name. A no-longer-allowed character (a restriction added since it was sent) surfaces as a warning rather than silently blocking review |
| GET | `/{game_slug}/submissions/{id}/verification` | `be_import` | The embedded verification code checked against its issuing site, if the file carries one - the same seven-outcome check `GET /verify/{code}` runs, reachable here without a Storyteller visiting that page by hand |
| POST | `/{game_slug}/submissions/{id}/accept` | `be_import` | Imports through the same pipeline every import uses, with the sender forced onto the result: `wp_user_id` the sender, `player_name` cleared, `status` active, never an NPC, no narrator. A join makes the sender a member; a visit behaves exactly like accepting an inbound transfer, with its own `keep_current` flag carried onto that new row. When the submission asked to be kept current and its file carried a real verification code, accepting also sends the file's real home a pairing request (`type pairing` on `from-host`) - fire-and-forget; home may never answer, or may refuse, in which case the host's own copy simply stays `keep_current` without `keep_current_accepted`, same as any other unaccepted request |
| POST | `/{game_slug}/submissions/{id}/refuse` | `be_import` | Turns it down with an optional note; nothing is written. Emails the sender |

## Chronicle Members

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/members` | `be_manage_games` | List this chronicle's members and roles |
| POST | `/{game_slug}/members` | `be_manage_games` | Add a member, or change an existing member's role |
| DELETE | `/{game_slug}/members/{wp_user_id}` | `be_manage_games` | Remove a member's chronicle-scoped access |

## Chronicle Players (for the chronicle's HST and AST)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/players` | `be_manage_characters` | `{players: [{wp_user_id, display_name, since, characters: [{id, name}]}], asc_role_path, join_link}` - the chronicle's player-role members, by display name, each with the characters linked to them, the accessSchema player path (`null` on an unlinked chronicle, which every players route answers on), and the chronicle's own public join link (`auth=sso` only when linked) |
| POST | `/{game_slug}/players` | `be_manage_characters` | `wp_user_id` of an existing account. Joins them to the site as a subscriber when they are not on it (multisite), writes a `player` membership row, and grants `{asc_role_path}/player` through owbn-core, then refreshes that one account's cached roles. Returns `{status, site_added, asc: {attempted, granted, role_path, message}}` with `status` `added` (201), `already_player` or `staff` (200, a staff member left unchanged); `404 no_account` for an account that doesn't exist. A grant accessSchema refuses is reported in `asc` and the membership stands |
| DELETE | `/{game_slug}/players/{wp_user_id}` | `be_manage_characters` | Removes the `player` membership row and revokes the player role; the account's characters are untouched. Returns `{status: removed or not_member, asc: {attempted, revoked, role_path, message}}`; `409 staff_member` for a staff member |
| GET | `/{game_slug}/players/invites` | `be_manage_characters` | The chronicle's open invites: `[{id, email, invited_by, invited_at, characters: [{id, name}]}]`, each with the characters waiting for its email |
| POST | `/{game_slug}/players/invites` | `be_manage_characters` | `email` (the exact address; `400 invalid_email` otherwise), `character_ids` (the chronicle's player characters), `send_email` (default true). An account with that email anywhere on the network, whatever the capitals, is made a player now, as `POST /players` does, and the characters are linked to it: `200 {status: linked, wp_user_id, display_name, player, linked, skipped}`. Otherwise the invite is stored, the characters hold the email in `pending_player_email`, the email joins the network index, and with `send_email` one invitation goes out: `201 {status: invited, invite_id, held, skipped, email_sent}`. A character linked to someone else is never moved: it is in `skipped` with `reason: linked_elsewhere` and `linked_to`. When an account with that email signs in (`wp_login`), is created (`user_register`), or first makes a signed-in request to a site holding an invite, each such site makes it a player, links the waiting characters with a history line each (`player_link`), and marks the invite accepted |
| DELETE | `/{game_slug}/players/invites/{id}` | `be_manage_characters` | Cancels an open invite and clears its characters' pending email; `404 invite_not_found` for one that is not open in this chronicle |
| POST | `/{game_slug}/players/{wp_user_id}/characters` | `be_manage_characters` | `character_ids`: links each to that member of the chronicle, with a history line; returns `{linked: [{id, name}], skipped: [{id, name?, linked_to?, reason}]}`. `404 not_member` for an account that is not a member |
| DELETE | `/{game_slug}/players/{wp_user_id}/characters/{character_id}` | `be_manage_characters` | Unlinks the character from that account, with a history line; `404 not_linked` when it is not linked to them in this chronicle |
| GET | `/{game_slug}/players/join-requests` | `be_manage_characters` | Every join request on this chronicle, waiting first: `[{id, wp_user_id, display_name, message, status, created_at, character: {id, name} or null, submission_id, note}]` |
| POST | `/{game_slug}/players/join-requests/{id}/approve` | `be_manage_characters` | Grants membership (`Chronicle_Players::add()`) and, when the request carries a character, activates it; closes the request and emails the applicant. `404 not_found`; `409 already_answered` for one not `waiting`; `400 review_the_file_instead` when the request carries a Grapevine file, granting nothing - accept it from Import instead, which closes the request too |
| POST | `/{game_slug}/players/join-requests/{id}/refuse` | `be_manage_characters` | `note` (optional). Deletes a pending character the request carries, or refuses a submission it carries; closes the request and emails the applicant with the note. Same `404`/`409` as approve |

## Mail Log (a chronicle's email record, for its HST and AST)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/mail-log` | `be_manage_characters` | One page of the chronicle's log, newest first, with `X-WP-Total` and `X-WP-TotalPages`. Each row: `{id, created_at, wp_user_id, recipient_name, recipient_email, kind, kind_label, subject, result, result_label, reason, reason_label, error, entity_type, entity_id, entity_label}`. `result` is `sent`, `failed`, `skipped` (not sent, with a `reason`) or `queued` (held for the recipient's daily digest). Never carries what a message said. Filters: `search` (name, address or subject), `kind`, `result`, `since` (`day`, `week`, `month` or `all`), `entity_type` with `entity_id`, `wp_user_id`; `page` and `per_page` (default 20, at most 100). A chronicle's rows only; another chronicle's slug is `403` for anyone who isn't its Storyteller |
| GET | `/{game_slug}/mail-log/options` | `be_manage_characters` | `{kinds: [{key, label}], results: [{key, label}], periods: [{key, label}], retention_days}` - what the filters offer, and how many days a row is kept (90) |

## Game Sessions (game nights, attendance, after-game reports, spotlight)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/sessions` | `be_view_characters` | The chronicle's game nights, soonest first; `from` and `to` (dates) narrow the range. Each: `{id, game_date, start_time, place, notes, downtime_opens_at, downtime_deadline_at, downtime_extensions, default_batch_id, reports_due_at, ...}`. For anyone who isn't a manager, `[ST]` text is stripped from `notes` and the Storyteller-only `recap` is left out |
| POST | `/{game_slug}/sessions` | `be_manage_sessions` | Create one. `game_date` is required and unique in the chronicle (`409 duplicate_date`). Optional: `start_time`, `place`, `notes`, `reports_due_at`, `downtime_opens_at`, `downtime_deadline_at`, `downtime_extensions`, `default_batch_id`, and `recap` (`{key_events, player_decisions, npcs_involved: [{name, status}], cliffhanger, prep}`) |
| PUT | `/{game_slug}/sessions/{id}` | `be_manage_sessions` | Update any of those fields; `409 duplicate_date` when another night already has the date |
| DELETE | `/{game_slug}/sessions/{id}` | `be_manage_sessions` | Delete one. `409 session_in_use` while it has attendance, a casting or an after-game report |
| PUT | `/{game_slug}/session-settings` | `be_manage_characters` | Set the chronicle's session settings, merged into what is saved: `attendance_xp`, `report_xp` and `spotlight_days` (whole numbers), and `release_schedule`, `{rules: [...]}`. A rule is `{type: weekly, weekday, time}` or `{type: monthly, day_of_month (1 to 28), time}`; a rule that doesn't validate is dropped. Answers `{sessions, release_schedule}` |
| GET | `/{game_slug}/sessions/{id}/attendance` | `be_manage_sessions` | Everyone signed in: `{id, session_id, character_id, visitor_name, visitor_chronicle, recorded_by, created_at}` |
| POST | `/{game_slug}/sessions/{id}/attendance` | `be_manage_sessions` | Sign one in: a `character_id`, or a `visitor_name` with an optional `visitor_chronicle`. `404 character_not_found`, `400 invalid_candidate`, `409 already_signed_in` |
| DELETE | `/{game_slug}/sessions/{id}/attendance/{attendance_id}` | `be_manage_sessions` | Remove one sign-in |
| POST | `/{game_slug}/sessions/{id}/award-attendance-xp` | `be_manage_characters` | Award XP to every character signed in. `amount` defaults to the chronicle's `attendance_xp` (1). `409 already_awarded` once done, unless `force` is sent. Answers `{awarded_count, amount}` |
| POST | `/{game_slug}/sessions/{id}/downtime-extensions` | `be_manage_apr` | Give one character a later downtime deadline for this night: `character_id` and `until`. It replaces the night's own deadline for that character alone |
| DELETE | `/{game_slug}/sessions/{id}/downtime-extensions/{character_id}` | `be_manage_apr` | Take it back; the character returns to the night's own deadline |
| GET | `/{game_slug}/sessions/{id}/reports` | `be_edit_own_characters` | The night's after-game reports: every one for a manager (`be_manage_plots` or `be_manage_characters`), only the caller's own for anyone else. `[ST]` text is stripped from `did`, `wants` and `to_staff` for anyone who isn't a manager |
| POST | `/{game_slug}/sessions/{id}/reports` | `be_edit_own_characters` | File a report for the caller's own character: `character_id`, with `did`, `wants` and `to_staff`. `403 ownership_denied`; `400 session_in_future` for a night after today; `409 reports_closed` once `reports_due_at` has passed; `409 already_exists` when the character already has one for this night |
| PUT | `/{game_slug}/sessions/{id}/reports` | `be_edit_own_characters` | Change it, under the same rules |
| POST | `/{game_slug}/after-game-reports/{report_id}/read` | `be_manage_plots` OR `be_manage_characters` | Mark a report read |
| POST | `/{game_slug}/sessions/{id}/award-report-xp` | `be_manage_characters` | Award XP to every character with a report for the night. `amount` defaults to the chronicle's `report_xp` (1); `409 already_awarded` once done, unless `force` is sent. Answers `{awarded_count, amount}` |
| GET | `/{game_slug}/spotlight` | `be_manage_plots` OR `be_manage_characters` | The spotlight check: every active, non-NPC character's attention profile, flagged ones first and then the least recently attended to. Each: `{character_id, name, last_attended, active_plots, last_staff_post_at, last_report_at, flagged}`. A character is flagged when it has had no staff post, or none within the chronicle's `spotlight_days` |

## Factions and Positions

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/factions` | `be_view_characters` | Every faction the viewer can see, by its audience. `is_member` says whether one of the caller's own characters belongs. `goals` is present only for a manager or a member, and `audience_rules` only for a manager |
| POST | `/{game_slug}/factions` | `be_manage_factions` | Create one: `name`, `faction_type` (free text; the editor suggests sect, clan, coterie, pack, chantry, court, cabal, sept, motley, house, other), `parent_id`, `description`, `goals`, `status` (`active` or `disbanded`), `audience`, `audience_rules` |
| GET | `/{game_slug}/factions/{id}` | `be_view_characters` | One faction the viewer can see |
| PUT | `/{game_slug}/factions/{id}` | `be_manage_factions` | Update it |
| DELETE | `/{game_slug}/factions/{id}` | `be_manage_factions` | Delete it and unlink its positions |
| GET | `/{game_slug}/factions/{id}/members` | `be_view_characters` | The roster, for a member of the faction or a manager only (`403 roster_denied`). Each: `{id, character_id, character_name, rank, is_leader, is_public, created_at}`; `is_public` is whether the membership shows on the character's public profile |
| GET | `/{game_slug}/factions/{id}/members/candidates` | `be_view_characters` | A name-only picker of the characters that can be added, `[{id, name}]` |
| POST | `/{game_slug}/factions/{id}/members` | `be_view_characters` | Add a character: `character_id`. A Storyteller, or a leader of the faction (`403 ownership_denied`), who may add active player characters only (`400 invalid_candidate`). `409 already_member` |
| DELETE | `/{game_slug}/factions/{id}/members/{character_id}` | `be_view_characters` | Remove a member. A Storyteller, or a leader of the faction, who can't remove themself or another leader (`403 cannot_remove_leader`) |
| PATCH | `/{game_slug}/factions/{id}/members/{character_id}` | `be_view_characters` | Change a member: `rank`; `is_leader` and `is_public` are a Storyteller's alone. A faction keeps at least one leader (`400 update_failed`). `404 member_not_found` |
| GET | `/{game_slug}/positions` | `be_view_characters` | Every office the viewer can see, `faction_id` narrowing to one faction. Each: `{id, faction_id, title, since, audience, held, character_id, character_name, ...}`; `holder_public`, `audience_rules` and `notes` are present only for a manager |
| POST | `/{game_slug}/positions` | `be_manage_factions` | Create one: `title`, `faction_id`, `character_id` (the holder), `holder_public`, `audience`, `audience_rules`, `notes` |
| PUT | `/{game_slug}/positions/{id}` | `be_manage_factions` | Update it; a change of holder is written to its history |
| DELETE | `/{game_slug}/positions/{id}` | `be_manage_factions` | Delete it and its holder history |
| GET | `/{game_slug}/positions/{id}/history` | `be_manage_factions` | Every holder in order, `[{id, character_id, character_name, started, ended}]` |
| GET | `/{game_slug}/position-presets` | `be_manage_factions` | The title suggestions the editor offers, grouped, `{group: [title, ...]}` |

## Release Batches

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/release-batches` | `be_manage_plots` | The chronicle's batches, newest first; `status` (`draft`, `scheduled` or `released`) narrows. Each: `{id, name, release_at, status, released_at, notified_at, rumor_count, entry_count, ...}` |
| POST | `/{game_slug}/release-batches` | `be_manage_plots` | Create one: `name`, and an optional `release_at`. With a time it is `scheduled`, without one a `draft` |
| PUT | `/{game_slug}/release-batches/{id}` | `be_manage_plots` | Change `name`, `release_at` (empty clears it) or `status` (`draft` or `scheduled`). `409 batch_released` once it is out |
| DELETE | `/{game_slug}/release-batches/{id}` | `be_manage_plots` | Delete a draft or scheduled batch; its items go back to draft. `409 batch_released` |
| GET | `/{game_slug}/release-batches/{id}/items` | `be_manage_plots` | What the batch holds: `{rumors, entries, reveals}` |
| POST | `/{game_slug}/release-batches/{id}/items` | `be_manage_plots` | Add one: `type` (`plot`, `entry` or `reveal`) and `id`. The item is held, with this batch's id on it |
| DELETE | `/{game_slug}/release-batches/{id}/items/{type}/{item_id}` | `be_manage_plots` | Take one out; it goes back to draft |
| POST | `/{game_slug}/release-batches/{id}/release-now` | `be_manage_plots` | Release the batch now. `409 already_out` |
| POST | `/{game_slug}/release-batches/release-now` | `be_manage_plots` | Make a batch, fill it and release it in one call: `items`, a list of `{type, id}` (`plot` or `entry`), and an optional `name` |

## NPC Castings

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/castings` | `be_view_characters` | The night's castings: `session_id` is required (`400 invalid_request`, `404 session_not_found`). A Storyteller sees every casting for it, anyone else only their own |
| POST | `/{game_slug}/castings` | `be_manage_characters` | Cast a chronicle member as an NPC for a night: `session_id`, `character_id`, `wp_user_id`, and an optional `brief`. `400 not_an_npc`, `400 not_a_member`, `409 already_cast` when the NPC already has a casting that night |
| GET | `/{game_slug}/castings/my-upcoming` | `be_view_characters` | The caller's own castings dated today or later, with the NPC's name and the night's date |
| GET | `/{game_slug}/castings/members` | `be_manage_characters` | The members who can be cast, `[{id, name, role}]` |
| PUT | `/{game_slug}/castings/{id}` | `be_manage_characters` | Change the cast member (`wp_user_id`) or the `brief` |
| DELETE | `/{game_slug}/castings/{id}` | `be_manage_characters` | Remove a casting |
| GET | `/{game_slug}/castings/{id}/brief` | `be_view_characters` | The read-only brief: the NPC's resolved sections plus the casting's own brief text. Only the cast member or a Storyteller can read it (`403 ownership_denied`), and the cast member only while the casting's access window is open |
| GET | `/{game_slug}/castings/{id}/brief.pdf` | `be_view_characters` | The same brief as a PDF, signed like every other sheet |

## Downtime Queue and My Queue

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/downtime/queue` | `be_manage_plots` | One row for each character's action plot on a game date, unanswered first. `game_date` is required (`400 invalid_param`). Each: `{plot_id, character_id, character_name, player_id, player_name, action_count, last_action_at, answered, answer_release_state, window_state, assigned_to, connections: [{type, id, name}]}`. `answer_release_state` is `not_answered`, `immediate`, `draft`, `scheduled` or `released`; `window_state` is `none`, `not_open`, `open` or `closed`; `connections` are the other characters, NPCs, items and locations tied to the plot |
| GET | `/{game_slug}/my/queue` | `be_manage_plots` OR `be_manage_characters` | The caller's own work in this chronicle: `{downtime, plots, castings, unassigned}`. `downtime` is the unanswered action plots assigned to them (any game date), `plots` the ordinary plots assigned to them whose newest player post is newer than the newest staff post, `castings` their castings for nights today or later, and `unassigned` the counts, `{downtime, plots}`, of the same two kinds that nobody owns yet |
| GET | `/{game_slug}/staff` | `be_manage_plots` OR `be_manage_characters` | Every `hst`, `ast` and `narrator` member of the chronicle, `[{id, name, role}]`: the picker behind every assignee field |

## Joining a Chronicle (for an applicant, signed in but not yet a member)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/joinable` | signed in | Every chronicle on this site taking join requests (`settings.join_requests`, absent reads as on) that the caller isn't already a member of: `[{slug, name}]` |
| POST | `/{game_slug}/join` | signed in | `message` (required, ≤1,000 characters). `403 join_requests_off` when the chronicle has them off; `409 already_a_member`; `409 join_already_requested` when one is already waiting; `429 too_many_requests` once the account has opened three requests on this chronicle in the last day. On a network, adds the account to this site as a subscriber when it holds no role here yet, before the request opens. Emails every HST and AST once. Returns `201` with the new row |
| GET | `/{game_slug}/join` | signed in | The caller's own waiting request on this chronicle, or `null`: `{id, game_id, wp_user_id, message, character_id, submission_id, status, note, reviewed_by, reviewed_at, created_at}`. `message` is stripped of `[ST]...[/ST]` the same as everywhere else a non-manager reads it |
| DELETE | `/{game_slug}/join` | signed in | Withdraws the caller's own waiting request; deletes a pending character started for it. `404 not_found` when none is waiting |

`Characters_Controller::create_item()`'s `joining` branch and `Submissions_Controller::create_item()` both tie a new character or file to an already-waiting request (`Join_Request::tie_character()`/`tie_submission()`) when one exists, with no second notification since one already went out when the request opened; absent a waiting request, both fall back to their original, unmodified pending/notify behavior. `Templates_Controller`'s template-resolve route and `Creation_Tally_Controller`'s tally-draft route both admit any signed-in account on a real chronicle (`allow_bootstrap`), matching the create route's own precedent, so an applicant can build a character before any request exists.

## Setup Status

| Method | Path | Capability | Notes |
|---|---|---|---|
| PUT | `/{game_slug}/chronicle-setup` | `be_manage_chronicle_setup` | The settings an HST may change for their own chronicle without the full `be_manage_games`: `enabled_stacks`, `enabled_factions` (one field at a time), `require_new_character_approval`, `accent_color`, `purchase_scope`, `starting_xp`, `join_requests`, and `secret_passing`. `purchase_scope` switches the purchase lists that are open to every creature type: `abilities`, `backgrounds` and `merits_flaws`, each true or false. `starting_xp` is a non-negative whole number, or an empty string to clear it back to none. `secret_passing` is one of `off`, `approval`, or `immediate`. A write carries only the areas it changes and the others stay as they were; an unknown area, or a value that is not plainly on or off (or, for `starting_xp`, not a non-negative whole number; for `secret_passing`, not one of its three values), is a `400`. Merges into the chronicle's stored settings. |
| GET | `/{game_slug}/setup-status` | `be_manage_characters` | The Chronicle Setup checklist's rows, computed live against real data every time - never stored, so nothing here goes stale between visits. Each row has `id`, `status` (`attention`, `ok` or `info`), `title`, `detail`, `fix` and `actionable`; there are eighteen, plus one more on the demo chronicle. An optional row reads `info` until the chronicle has set something of its own, then `ok`; the Catalog customisation row reads `attention` while the chronicle has book corrections to review (see Book Corrections), and the Book variants row lists the variants it chose (see Book Variants). `summary` gives `attention`, `ok`, `info` and `total`, the rows there are to do (the demo row isn't counted). Staff only (an HST or AST): a player is refused. |

## Authorization Settings

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/authorization-settings` | `be_manage_games` | Whether accessSchema checking is on, and whether a real accessSchema client is detected |
| PUT | `/authorization-settings` | `be_manage_games` | Turn accessSchema checking on or off, site-wide |

## Data Management

Site-wide, not game-scoped - lives on the Chronicle Access admin screen.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/data-management` | `be_manage_games` | Whether uninstalling the plugin also deletes its data |
| PUT | `/data-management` | `be_manage_games` | Turn that opt-in on or off |
| GET | `/data-management/export` | `be_manage_games` | A full JSON export of every plugin table, for a backup or a migration |

## Credits

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/credits` | `be_view_characters` | Site-wide credits text and in-memoriam list |
| PUT | `/credits` | `be_manage_games` | Update the credits text. `in_memoriam` in the body is ignored; the list has no write path |

## Docs

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/docs/{slug}` | `be_view_characters` | Serves one of this plugin's own bundled guides (`st-guide`, `admin-guide`, `player-guide`, `rest-api` - this very document) as Markdown, `{ slug, content, language, fallback }`, for the in-plugin Docs screen and the help panel. A viewer whose language is Portuguese (Brazil) gets the translation in `docs/pt_BR/` when there is one: `language` is `pt_BR` and `fallback` is `false`; with none, the English original comes back with `language` `en` and `fallback` `true`. Any other viewer gets English, `fallback` `false` |
| GET | `/docs/help/{key}` | `be_view_characters` | One screen's help page, `docs/help/{key}.md`, as Markdown: `{ key, content, language, fallback }` - what a screen's `?` opens in the help panel, in the viewer's language the same way as `/docs/{slug}`. `404` for a key that names no English page |

## Game Stats

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/stats` | `be_manage_characters` | The ST dashboard's aggregate numbers - character counts, pending changes, active plots (not counting each character's own plot), recent activity, and `players_without_active_character` (a count only; see the detail route below). Cached one minute; a review action invalidates the cache for its own chronicle immediately |
| GET | `/{game_slug}/stats/players-without-active-character` | `be_manage_characters` | The actual player list behind that count - every player-role member with zero `active` characters (no characters at all, or only a retired/dead/pending one - the same condition), resolved to a display name. Not itself cached; fetched only when the dashboard's roster-health card is opened |

## Sheets (character-sheet PDF, signed when the site has a certificate)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/sheets/pdf` | `be_view_characters` | Returns PDF bytes for one or more characters (`character_ids`, comma-separated, max 50). A manager may request any character in the chronicle; a non-manager only their own - one denied or missing id fails the whole request, as does one whose creature type no longer exists (`404 creature_stack_not_found`, naming the character). Signed when the site has a signing certificate; without one the PDF is stamped UNSIGNED on every page and its filename ends `-unsigned.pdf`. Optional `full_power_names`, `background`, `notes`, `xp_history`, `show_cost` (default on), and `page_size` (`letter` or `a4`; left out, the site's language decides: Letter for a US, Canadian, Mexican or Philippine locale, A4 otherwise; anything else is a 400) |
| GET | `/{game_slug}/sheets/availability` | `be_view_characters` | Preflight: is signing configured on this site right now (`ok: false` means prints come out unsigned) |

## Verify

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/verify/{code}` | none (public by design) | Confirms one exported/printed document's attestation code is real and unaltered - not game-scoped, and deliberately reachable by anyone with the link, not just chronicle members. Backs the `be-verify` page's human-facing check |

## Reports (the 20 GV301-plus reports, cards, and batch output)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/reports` | `be_view_reports` | Lists the reports the caller may run in this chronicle: key, title, shape, entity. Each report also needs its own capability - character and player reports `be_manage_characters`, plot/action/rumor reports `be_manage_plots`; the item/location/rote cards, Game Calendar, and House Rules need nothing more. Running a report without it is `403 report_forbidden` |
| GET | `/{game_slug}/reports/{report_key}` | `be_view_reports` | Returns the resolved report as plain JSON - no signing, no PDF. Built for a live front-end view (House Rules' own widget/shortcode use it); works for any report in the registry, not just House Rules |
| GET | `/{game_slug}/reports/{report_key}/pdf` | `be_view_reports` | Returns PDF bytes for one report, signed or stamped UNSIGNED exactly as a sheet is. `conditions`/`logic` scope a `table`/`card` report the same way the query builder does (an empty `conditions` means everyone in scope); `stat_field`/`stat_type` parameterize the generic Statistics Report; `character_id` (both this route and the JSON form above) narrows a `card`-shaped report (e.g. `item-cards`) to only the world objects connected to that one character - a manager may pass any character in the chronicle, a non-manager only their own (`404 character_not_found` for a mismatched game, `403 ownership_denied` for someone else's character). `object_id` (both this route and the JSON form above) narrows a `card`-shaped report (`item-cards`, `location-cards`) to the one world object with that id; it never widens what the caller may see, so a non-manager still only gets an object their characters' audience reaches. `404 report_not_found` for an unknown key |
| GET | `/{game_slug}/reports/availability` | `be_view_reports` | Which card reports the caller can run for a character (`character_id`), `{report_key: bool}`. A Storyteller can run all of them; anyone else can run a card report that needs a holder block (Rote Cards needs a rote-holding block) only for a character whose creature type has it |

## Point Audit

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/{game_slug}/characters/{id}/point-audit` | `be_manage_characters` | The itemised point audit for one character - every held line, priced or explicitly marked unpriced with a machine-readable reason. Never `be_view_characters`/`be_edit_own_characters`: a grand total computed across a Storyteller-only block would leak its stored values arithmetically, so a non-manager gets `403`, never a reduced total. A character whose creature type no longer exists is `404 creature_stack_not_found`. A tiered-power line's `modifier` is how much a rank modifier changed its cost, signed, and `modifier_side` says which: `in_type` or `out_of_type`; both are `null` on a line no modifier changed. `complete` is always `false` - this is not a bill, see [st-guide.md](st-guide.md) |

## Creation Tally

The build tally for a character in progress against its creature type's declared `creation_rules`: what each step covers, each pool's own balance, every limit flag, and what is left for XP. A guide, never a gate - nothing here blocks a save.

| Method | Path | Capability | Notes |
|---|---|---|---|
| POST | `/{game_slug}/creation-tally` | `be_edit_own_characters` or `be_manage_characters` | Tallies a draft build that isn't saved yet. Body: `stack_slug` (required) and `sheet_data` (the draft sheet in progress; defaults empty). `400 invalid_param` for a missing `stack_slug`, one that doesn't resolve to a real creature stack, or a `sheet_data` that isn't an object |
| GET | `/{game_slug}/characters/{id}/creation-tally` | `be_manage_characters` | The same tally read from a pending character's own saved sheet, for a Storyteller reviewing a new build. `404 character_not_found` for a character in another chronicle; `404 creature_stack_not_found` for one whose creature type no longer exists |

Both routes return the same shape, from the one `Services\Creation_Tally` engine, in the book's step order: `steps` - one entry per declared step, each carrying its own `kind`, `label` and `applies`, plus what that kind reports (`prioritized`/`budget` list each covered section's `used` against `allowed` and `over`; `budget` also lists its `quotas`, each `met` against `min` and `ok`; `earned`/`free` give the pool's `points`/`spent`; `limit` gives its own `flags`; `grant`/`start` give what they resolved). `pools` - every named pool's `own`, `earned`, `spent` and `left`. `limits` - every `limit` step's flags, merged (`target`, `reason` - `max_points`, `max_rating`, `min_rating` or `ceiling` - and the value that tripped it). `grants_missing` - a grant step's entries the sheet doesn't hold yet. `xp` - `starting`, `needed` (what no budget, pool or grant covered, priced at `Cost_Engine`'s own purchase prices) and `left`, which goes negative on an overspent build with nothing stopping it.

## Translations (catalog term translation)

Site-wide, not chronicle-scoped - one install, one language. Every route needs `be_manage_translations`, granted independently of `be_manage_schemas`. `locale` on every route below is a plain locale code (e.g. `pt_BR`), not validated against WordPress's own installed-language list - a chronicle in a language with no WordPress core translation installed can still be worked on here.

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/translations` | `be_manage_translations` | One page of catalog terms for `?locale=`, left-joined with their translation. Filters: `status` (`untranslated` or a real `Translation::STATUSES` value), `block`, `search` (substring on the English term), `has_translation` (`1`/`0`). Paginated, `per_page` capped at 500 (§6's own D38/D52 warning against a missing-default truncation, against 8,298 rows) |
| GET | `/translations/progress` | `be_manage_translations` | Per-locale totals, a per-status breakdown, and a per-block breakdown (total terms and how many are translated) - the progress bar and status line on the Translations screen |
| GET | `/translations/locales` | `be_manage_translations` | Locales with at least one real translation row already (`with_rows`), plus every locale WordPress itself has installed (`installed`, `en_US` always first) - the language picker's own suggestions |
| POST | `/translations` | `be_manage_translations` | Creates or replaces one term's translation for a locale. Accepts either `string_id` or `source_text` - naming a term `rescan()` hasn't indexed yet creates its string row rather than 404ing. Body: `locale`, `translation`, `status` (defaults `draft`), and one of `string_id`/`source_text` |
| PATCH | `/translations/{id}` | `be_manage_translations` | Updates an existing translation row's own `translation` and/or `status`. `404 not_found` for an unknown id |
| DELETE | `/translations/{id}` | `be_manage_translations` | Clears a translation row entirely (not the catalog term itself, which stays indexed for a future translation) |
| POST | `/translations/bulk` | `be_manage_translations` | Sets many rows at once by `source_text`, matching `{ locale, rows: [{source_text, translation, status}] }` - backs "mark selected approved." One bad row is skipped and counted, never aborts the batch. Returns `{updated, skipped}` |
| GET | `/translations/export` | `be_manage_translations` | Downloads a CSV honouring the same filters `GET /translations` accepts, `text/csv` with a UTF-8 BOM: `source_text`, `translation`, `status` columns |
| POST | `/translations/import-csv` | `be_manage_translations` | Multipart CSV upload matching the export shape. `dry_run=1` reports `{added, updated, unchanged, unmatched, conflicts, sample}` without writing anything - a conflict is the file disagreeing with itself (two rows for the same term with two different translations), not the file disagreeing with what's already saved, which is an ordinary update |
| POST | `/translations/rescan` | `be_manage_translations` | Re-walks the real, current catalog and refreshes the string index against it - every schema block, system and every chronicle fork. Returns `{added, updated, orphaned}` |
