# Admin Guide

This guide covers the parts of Beyond Elysium that shape the plugin itself, rather than one
chronicle's day-to-day play: schema blocks, creature stacks, adding a new creature type
without writing code, and templates. If you're looking for day-to-day chronicle
management instead, see the [Storyteller Guide](st-guide.md).

## Schema Blocks and Creature Stacks

Beyond Elysium never has creature-specific code. Every character sheet is assembled at
render time from two kinds of catalog entries:

- **Schema blocks** — one reusable building block: a trait list (Merits, Backgrounds), a
  tiered power (Disciplines, Gifts, Spheres), a resource pool (Blood, Willpower, Gnosis), or
  an identity field (Nature, Demeanor, Tribe).
- **Creature stacks** — an ordered assembly of blocks that makes up one creature type's
  complete sheet.

Under **Beyond Elysium → Schema Blocks**, each block shows its section type, whether it's a
system block (part of the shipped catalog) or a chronicle's own fork, and its full
definition. A system block can be forked per-chronicle from the Storyteller side (see the
Storyteller Guide) without ever touching the shared version every other chronicle uses.

Under **Beyond Elysium → Creature Stacks**, each stack lists which blocks it uses and in
what section/column they render.

## Descriptions and Approval Schedules on Catalog Items

Beyond its basic name/cost/approval, any catalog entry — a trait list item, a tiered power
level, a tiered power family, a resource pool, or an identity field — can carry a
**Description** and a finer-grained **approval schedule**, both edited from the same
**Beyond Elysium → Schema Blocks** screen the item already lives on.

### Description

A **Description** button next to an item, power level, or power family opens a small editor
with three separate rich-text sections:

- **Reference** — a page or document citation.
- **Description** — a general note or house rule.
- **Source** — where this ruling came from (a separate idea from the item's own printed
  sourcebook citation, which is a plain field elsewhere on the same row).

Each section keeps formatting, lists, and tables; images and anything else are stripped when
saved. This is a **site-wide** field, not per-chronicle — a chronicle can still fork the
block to write its own note, but an edit made here (with no chronicle selected) is visible to
every chronicle immediately. It also survives every future plugin update: a system block's
catalog data (cost, sphere requirements, and so on) refreshes from the shipped source on
every version bump, but a description an admin has written is carried forward untouched.

### Approval by Value and Approval by Option

An item's flat approval setting ("this whole item needs Storyteller approval") can be
sharpened to depend on what a player is actually raising it to:

- **Trait list items and resource pools** — an **Approval by value** button opens a small
  table of ranges (`From` / `To` / `Approval` / `Reason`), for example Occult 1-3
  auto-approved, 4-5 needing Storyteller review. This resolves against the value a player is
  submitting, never a comparison against what they held before — reaching level 4 needs
  review however the character got there. A resource pool's schedule checks its **permanent**
  rating only; spending or regaining points in play never triggers it.
- **Tiered power levels** (Disciplines, Gifts, Spheres, …) — each level is already its own
  row, so it gets a plain **Approval** dropdown directly, with no range to configure.
- **Identity fields** (Nature, Clan, Generation, …) — an **Approval by option** button lists
  every option the field offers with its own approval dropdown, for example requiring
  Coordinator approval to pick "Antediluvian" while every other option stays at the block's
  default. A multiselect field checks every value a player picks and the strictest
  requirement applies.

A value or option with no schedule entry falls back to the item's own flat `approval`
setting, which in turn falls back to the block's overall default — see the
[Storyteller Guide's approval section](st-guide.md#4-running-the-approval-queue) for how a
resolved approval level reaches the queue.

## Adding a Creature Type Without Code

This is the point of the schema-driven design: a new creature type is configuration, every
time, not a code change.

1. **Build or reuse schema blocks.** If the new type needs traits nothing else uses yet
   (its own power list, its own resource pool), create those blocks first under **Schema
   Blocks → Add Block**, picking the right section type for each.
2. **Create the creature stack.** Under **Creature Stacks → Add Stack**, give it a slug and
   name, then assemble it from existing and new blocks — set each block's column and
   display order.
3. **Set creation rules**, if the type needs any (a starting dot allocation, a required
   identity field, and so on) — these live on the stack definition alongside its block list.
4. The new type is immediately available everywhere a creature stack is selectable —
   character creation, the roster filter, query building — with no further wiring.

Every one of the eleven Storyteller-facing widgets (character sheet, editor, roster,
approval queue, and the rest) reads the same schema-block/creature-stack definitions at
render time. There is nowhere else in the plugin a creature type needs to be registered.

## Templates

A **template** controls how a creature stack's blocks are laid out on the rendered sheet —
which blocks go in which column, in what order, and under what section heading. Every
creature stack gets a sensible default template automatically; templates only need editing
when a chronicle wants a different visual arrangement than the default.

Under **Beyond Elysium → Templates**, a template names a creature stack, a set of section
groupings, and per-block column/width/title overrides. A template can also reference another
block's field for cross-block display (`title_refs`) or resolve a display name through a
lookup block (`name_lookup`) — both used for cases like showing a power's governing Sphere
or Discipline name inline rather than just its raw slug.

Templates ship with the same system-vs-fork distinction as schema blocks: the default is
shared, and a chronicle that wants its own layout forks it without affecting anyone else's.

## Chronicle-Scoped Access

Under **Beyond Elysium → Chronicle Access**, an admin controls:

- The site-wide accessSchema toggle (on/off), and whether a real accessSchema client is
  actually detected on this install.
- Each chronicle's `asc_role_path` — its accessSchema path prefix.
- Chronicle membership and role for every user (HST, AST, Narrator, Player).
- The per-chronicle notification toggle.

If accessSchema is off, not installed, or unreachable for a given request, every permission
check falls back to this membership table automatically — a chronicle can run entirely on
plain WordPress capabilities with no OWBN plugin stack present at all.

## What an HST Can and Cannot Do

An HST is a WordPress `editor`, not an `administrator`, and three pages stay
administrator-only regardless of chronicle role: **Games** (create a chronicle, rename or
delete one), **Chronicle Access** (assign HST/AST/Narrator/Player), and any settings scoped
to `be_manage_games`. This is deliberate — `game-roles.php` excludes `be_manage_games` from
every chronicle role by name, so no HST can appoint their own AST even for their own
chronicle.

An HST *can* now (as of `v0.99.16`) reach **Schema Blocks**, **Creature Stacks**, and
**Templates** for their own chronicle's own customization — forking a block or a template
for a chronicle they hold `hst`/`ast` membership in. This needs both of two things to be
true: the site-wide capability (`be_manage_schemas`/`be_manage_templates`, granted to
`editor` since `v0.99.16`) and a real membership row in that specific chronicle. Holding the
capability alone, with no membership row, still gets a `403` — it is not a bare
site-wide grant, the same two-layer check every chronicle-scoped route in this plugin uses.

## Import

Under **Beyond Elysium → Import**, an admin (not just a Storyteller) can import a full
Grapevine game file (`.gv3`) to create a brand-new chronicle, or a character/game exchange
file (`.gex`) the same way a Storyteller would from the chronicle side. See the
[Storyteller Guide's import section](st-guide.md#5-importing-from-grapevine) for the
duplicate-detection and merge behavior, which is identical from either surface.

## REST API

Every read and write in the plugin goes through its REST API (`be/v1` namespace), which
every one of the widgets above is a thin client of — nothing in the admin or Storyteller UI
does anything the API itself doesn't also expose. See the
[REST API reference](rest-api.md) for the full endpoint list, parameters, and permission
requirements.
