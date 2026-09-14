# Admin Guide

This guide covers the parts of Beyond Elysium that shape the plugin itself, rather than one
chronicle's day-to-day play: schema blocks, creature stacks, adding a new creature type
without writing code, and templates. If you're looking for day-to-day chronicle
management instead, see the [Storyteller Guide](st-guide.md).

## The wp-admin Menu

Everything below lives under one top-level **Beyond Elysium** menu in wp-admin. Clicking the
top-level label itself lands on a real dashboard - an about/what's-where reference, the
Elementor widgets & shortcodes inventory, and a "Create your first chronicle" call-to-action
that's prominent only while no real chronicle exists yet. Sixteen flat submenus were
consolidated into 8 tabbed groups; a tab hides itself individually when the viewer lacks its
own capability, so a page stays reachable even for a viewer who can't see every tab on it:

| Submenu | Tabs (each its own capability) | Covered in this guide |
|---|---|---|
| Characters | — (single page; the NPC/player toggle replaces the old separate NPC Roster page) | — the staff-facing character roster across every chronicle, plus "+ New Character" and NPC flagging, below |
| Plots | — (single page) | — the same plot/action/rumor tooling as the Storyteller Toolkit page, from wp-admin |
| Items & Locations | — (single page) | — the catalog of world objects a character can be connected to |
| Query Tool | Query Tool (`be_run_queries`), Reports (`be_view_reports`) | — the same query builder as the front-end Query Tool, plus the 20-report/cards/batch-output layer |
| Import | — (single page) | Import, below |
| Chronicle Setup | Chronicle Setup (`be_view_characters`), Chronicle Access (`be_manage_games`), Action & Rumor Settings (`be_manage_apr`) | Chronicle-Scoped Access, below; Chronicle Setup itself is a live checklist for a chronicle's own setup, see the [Storyteller Guide](st-guide.md) |
| System Config | Games (`be_manage_games`), Schema Blocks (`be_manage_schemas`), Creature Stacks (`be_manage_schemas`), Templates (`be_manage_templates`), Approval Rules (`be_manage_approval_rules`) | Schema Blocks and Creature Stacks, Templates, Descriptions and Approval Schedules, and Approval Rules, all below |
| Docs | — (single page, `be_view_characters`) | — this guide and its three siblings, rendered in-plugin |

Two related pages live on the front end instead, not in wp-admin at all: the **Game
Dashboard** (roster stats, roster health, upcoming plots) is the Dashboard tab on both the
Storyteller Toolkit page (staff) and My Chronicle (players, their own stats only), and
**Notifications** is a per-chronicle on/off switch on Chronicle Setup → Chronicle Access,
with each player able to opt out individually on their own WordPress Profile page. The
landing dashboard's own "What's where" reference links to both.

### Creating or Flagging an NPC

The Characters page's "+ New Character" button opens the same character-creation form a
player uses (front end, My Chronicle's Edit tab) for the currently-selected chronicle. A
viewer holding `be_manage_characters` additionally sees a "This is an NPC" checkbox there -
never shown to a player - which resolves the richer NPC sheet template (voice, mannerisms,
plot hooks) immediately. The same checkbox appears in edit mode for an already-existing
character, so flagging or un-flagging NPC status later needs no separate action.

## Schema Blocks and Creature Stacks

Beyond Elysium never has creature-specific code. Every character sheet is assembled at
render time from two kinds of catalog entries:

- **Schema blocks** — one reusable building block: a trait list (Merits, Backgrounds), a
  tiered power (Disciplines, Gifts, Spheres), a resource pool (Blood, Willpower, Gnosis), or
  an identity field (Nature, Demeanor, Tribe).
- **Creature stacks** — an ordered assembly of blocks that makes up one creature type's
  complete sheet.

Under **Beyond Elysium → System Config → Schema Blocks**, each block shows its section type, whether it's a
system block (part of the shipped catalog) or a chronicle's own fork, and its full
definition. A system block can be forked per-chronicle from the Storyteller side (see the
Storyteller Guide) without ever touching the shared version every other chronicle uses.

Under **Beyond Elysium → System Config → Creature Stacks**, each stack lists which blocks it uses and in
what section/column they render.

## Descriptions and Approval Schedules on Catalog Items

Beyond its basic name/cost/approval, any catalog entry — a trait list item, a tiered power
level, a tiered power family, a resource pool, or an identity field — can carry a
**Description** and a finer-grained **approval schedule**, both edited from the same
**Beyond Elysium → System Config → Schema Blocks** screen the item already lives on.

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
setting, which in turn falls back to the block's overall default, which in turn falls back to
the chronicle's own **default approval policy** - see [Approval Rules](#approval-rules)
below - and see the
[Storyteller Guide's approval section](st-guide.md#4-running-the-approval-queue) for how a
resolved approval level reaches the queue.

## Approval Rules

Under **Beyond Elysium → System Config → Approval Rules**, one page lists every approval
override currently set anywhere in a chronicle's catalog - the exact same underlying data the
Schema Blocks screen's own Description/Approval editors write, just gathered into one flat,
addressable list instead of scattered across whichever block each rule happens to live on.
Anything set here shows up there too, and vice versa; edit whichever is more convenient for
the moment - in context while already editing a block's other fields, or here for a quick
scan of everything a chronicle currently requires review for.

Picking a block offers the matching target picker for its section type:

- **Trait list** (Merits, Backgrounds, …) - an item, then a choice between "the whole item"
  (its flat approval) or "a specific value range" (an `Approval by value` entry, addressed by
  its exact `From`/`To` bounds).
- **Tiered power** (Disciplines, Gifts, Spheres, …) - a power, then a choice between "the
  whole power" (its `approval_override`) or "one level only" (that level's own `reason` -
  see the note above about why a level's flat approval is set on its own catalog row instead,
  not duplicated here).
- **Resource pool** (Blood, Willpower, Gnosis, …) - a pool, always addressed by an exact
  `From`/`To` range on its **permanent** value, same rule as the Schema Blocks editor's own
  version of this control.
- **Identity field** (Clan, Sect, …) - a field (only ones that actually offer real options),
  then one of its options.

### Default Approval Policy

The same page also carries the chronicle's own baseline: **Pending by default** (today's
long-standing behavior - everything needs Storyteller review unless a rule below says
`auto`) or **Auto-approve by default** (the reverse - everything is waved through unless a
rule below says `st` or `coordinator`). This is a two-way switch, not a third tier alongside
`auto`/`st`/`coordinator` - a granular rule can still ask for any of the three regardless of
which way the chronicle's own default is set.

A granular rule **always** wins over this default, in either direction - the default only
ever applies when nothing more specific (an item, a power, a level, a value range, a field
option, or the owning block's own `approval_rules.default`) had an opinion at all. This
matters concretely: switching a chronicle from Pending to Auto-approve never silently
approves something a Storyteller had explicitly flagged as needing review, even a flag with
no reason text attached to it.

## Adding a Creature Type Without Code

This is the point of the schema-driven design: a new creature type is configuration, every
time, not a code change.

1. **Build or reuse schema blocks.** If the new type needs traits nothing else uses yet
   (its own power list, its own resource pool), create those blocks first under **Schema
   Blocks → Add Block**, picking the right section type for each.
2. **Create the creature stack.** Under **System Config → Creature Stacks → Add Stack**, give it a slug and
   name, then assemble it from existing and new blocks — set each block's column and
   display order.
3. **Set creation rules**, if the type needs any (a starting dot allocation, a required
   identity field, and so on) — these live on the stack definition alongside its block list.
4. The new type is immediately available everywhere a creature stack is selectable —
   character creation, the roster filter, query building — with no further wiring.

Every one of the plugin's front-end widgets (character sheet, editor, roster, approval
queue, dashboard, and the rest — fifteen in total, `src/index.tsx`'s widget registry) reads
the same schema-block/creature-stack definitions at render time. There is nowhere else in
the plugin a creature type needs to be registered.

## Templates

A **template** controls how a creature stack's blocks are laid out on the rendered sheet —
which blocks go in which column, in what order, and under what section heading. Every
creature stack gets a sensible default template automatically; templates only need editing
when a chronicle wants a different visual arrangement than the default.

Under **Beyond Elysium → System Config → Templates**, a template names a creature stack, a set of section
groupings, and per-block column/width/title overrides. A template can also reference another
block's field for cross-block display (`title_refs`) or resolve a display name through a
lookup block (`name_lookup`) — both used for cases like showing a power's governing Sphere
or Discipline name inline rather than just its raw slug.

Templates ship with the same system-vs-fork distinction as schema blocks: the default is
shared, and a chronicle that wants its own layout forks it without affecting anyone else's.

## Chronicle-Scoped Access

Under **Beyond Elysium → Chronicle Setup → Chronicle Access**, an admin controls:

- The site-wide accessSchema toggle (on/off), and whether a real accessSchema client is
  actually detected on this install.
- Each chronicle's `asc_role_path` — its accessSchema path prefix.
- Chronicle membership and role for every user — **five** roles, not four: **HST**, **AST**,
  **Narrator**, **Boons** (a Harpy — runs the boon ledger only, no Storyteller powers over
  characters or plots), and **Player**.
- The per-chronicle notification toggle.
- **Data Management** (site-wide, not per-chronicle): whether uninstalling the plugin also
  deletes its data, and a one-click full JSON export of every plugin table for a backup or a
  migration.

If accessSchema is off, not installed, or unreachable for a given request, every permission
check falls back to this membership table automatically — a chronicle can run entirely on
plain WordPress capabilities with no OWBN plugin stack present at all.

## What an HST Can and Cannot Do

An HST is a WordPress `editor`, not an `administrator`, and three pages stay
administrator-only regardless of chronicle role: **Games** (create a chronicle, rename or
delete one), **Chronicle Access** (assign HST/AST/Narrator/Boons/Player), and any settings
scoped to `be_manage_games`. This is deliberate — `game-roles.php` excludes `be_manage_games`
from every chronicle role by name, so no HST can appoint their own AST even for their own
chronicle.

An HST *can* now (as of `v0.99.16`) reach **System Config**'s **Schema Blocks**, **Creature Stacks**, and
**Templates** for their own chronicle's own customization — forking a block or a template
for a chronicle they hold `hst`/`ast` membership in. This needs both of two things to be
true: the site-wide capability (`be_manage_schemas`/`be_manage_templates`, granted to
`editor` since `v0.99.16`) and a real membership row in that specific chronicle. Holding the
capability alone, with no membership row, still gets a `403` — it is not a bare
site-wide grant, the same two-layer check every chronicle-scoped route in this plugin uses.

## Front-End Pages

Four WordPress pages, created automatically the first time the plugin runs (or updates),
carry every front-end widget: **My Chronicle** (`be-player`), **Storyteller Toolkit**
(`be-storyteller`), **Character Sheet (Print)** (`character-sheet-print`), and **Verify
Character** (`be-verify`). Fixed at four regardless of how many chronicles this site hosts —
My Chronicle and Storyteller Toolkit each carry a chronicle switcher rather than being tied
to one chronicle at creation time; see the [Storyteller Guide](st-guide.md#7-the-game-dashboard)
for what lives on each.

If one of these pages is ever deleted by mistake, it is **not** recreated automatically on
its own — a page, once created at a given slug, is never overwritten or replaced. Recover it
from **Beyond Elysium → Chronicle Setup**: the **Front-end pages** checklist row turns red
when any of the four is missing, with a **Fix** link that re-runs the same provisioning
step (`?provision_pages=1`, `be_manage_games`-gated) the plugin already ran once
automatically.

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
