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
