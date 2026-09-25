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
| Chronicle Setup | Chronicle Setup (`be_manage_chronicle_setup`), Chronicle Access (`be_manage_games`), Action & Rumor Settings and AI Assist (`be_manage_apr`) - staff only: the page needs `be_manage_chronicle_setup`, so a player never sees it | Chronicle-Scoped Access, below; Chronicle Setup itself is a live checklist for a chronicle's own setup, see the [Storyteller Guide](st-guide.md) |
| System Config | Games (`be_manage_games`), Schema Blocks (`be_manage_schemas`), Creature Stacks (`be_manage_games`), Templates (`be_manage_templates`), Approval Rules (`be_manage_approval_rules`), Translations (`be_manage_translations`) | Schema Blocks and Creature Stacks, Templates, Descriptions and Approval Schedules, Approval Rules, and Catalog Term Translation, all below |
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
Storyteller Guide) without ever touching the shared version every other chronicle uses. The
copy keeps what the chronicle changed - values, approval rules, entries it added or removed -
and takes everything else from the shared block, both when the plugin updates and when you save
the shared block here.

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

The same holds for every other setting only an admin makes on a shared system block: approval
levels and reasons, approval-by-value and approval-by-option schedules, a power family's
approval override, the block's own approval rules, and any item, power, level, pool, or field
an admin added. A section added to a system creature stack is kept too. What an update does
refresh is what ships with the plugin - names, costs, notes, translations, a stack's own name
and sections - so changing one of those on a system block lasts only until the next update;
fork the block for your chronicle to change it for good.

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
  Storyteller approval to pick "Antediluvian" while every other option is automatic. A multiselect field checks every value a player picks and the strictest
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
rule below says `st`). A granular rule can ask for either level regardless of which way the
chronicle's own default is set. (There is no coordinator level: a rule whose reason names a
coordinator's approval is a Storyteller rule, and the Storyteller gets that approval.)

A granular rule **always** wins over this default, in either direction - the default only
ever applies when nothing more specific (an item, a power, a level, a value range, a field
option, or the owning block's own `approval_rules.default`) had an opinion at all. This
matters concretely: switching a chronicle from Pending to Auto-approve never silently
approves something a Storyteller had explicitly flagged as needing review, even a flag with
no reason text attached to it.

## Catalog Term Translation

Under **Beyond Elysium → System Config → Translations**, one screen manages every catalog
term's translation - trait names, power names, identity-field labels and options - for
whichever languages a chronicle actually needs. This is separate from the plugin's own
UI-chrome translation (the labels, buttons, and messages the interface itself is built from,
which install through an ordinary WordPress language pack): a catalog term like "Fortitude"
or "Alertness" never appears as a literal string in any source file, only as a database row,
so `wp i18n make-pot` can never see it and a `.po` file can never carry it.

**Granting access.** `be_manage_translations` is its own capability, independent of
`be_manage_schemas` - a native-speaking volunteer can be trusted to translate terms without
also being trusted to edit the catalog's own mechanics. Grant it to a WordPress role, or add
one to the `administrator`/`editor` role directly, the same way any other Beyond Elysium
capability is granted.

**The screen.** A language picker (**+ Add a language** starts a new one by typing its locale
code, e.g. `es_ES` - no WordPress language pack install is required, since this is catalog
data, not UI chrome) sits beside a **Rescan catalog** button, which re-walks every schema
block - system and every chronicle's own fork - and refreshes the string index against
whatever the real, current catalog actually contains. Below that, a progress bar and a
per-status count (draft / needs review / approved / conflict) for the picked language.
Filters narrow the table beneath: **Catalog** (one schema block or all of them), **Status**
(including **Untranslated only**), and a free-text **Search**. Each row's translation is a
plain text field - type, click away (or press Tab to jump straight to the next untranslated
row), and it saves. A row's **Status** is its own dropdown, editable the same way. Checkboxes
plus **Mark selected approved** apply that status to many rows in one click.

**CSV round trip.** **Export CSV** downloads the current filtered view as `source_text`,
`translation`, `status`. **Import CSV** reads the same three columns back, matched to the
real catalog by name - a reordered or partially-filled file still imports correctly, and any
row that doesn't match a real catalog term is reported, not silently dropped. Every import
previews first: a dry-run summary (added / updated / unchanged / unmatched / conflicts) with
a sample of what changed, and nothing is written until **Commit import**.

**Why a CSV at all, when the table is authoritative:** the file is a convenience for the
initial bulk pass - a volunteer exports 500 untranslated Werewolf Gift names, works through
them in a spreadsheet over a week, imports, checks the dry-run counts, commits. It is an
**export of a live table**, never the source of truth - importing it needs no repository,
no build, no deploy, and no developer. The in-app
inline edit is for the other half of the job: a Storyteller spots a wrong term mid-session,
searches it, fixes one field, and it is right on every sheet, every chronicle fork, and every
printed PDF on the next page load - no ticket, no file, no deploy.

## Moving an Older Site to the Per-Creature Lists

Sites set up before 1.3.4 share one Abilities list, one Merits list, one Flaws list and one
Rites list across every creature type. Each creature type now has its own lists, with its
own prices and groupings, and a new install starts on them. **An older site is moved by the
upgrade itself.** There is nothing to run and nothing to switch on.

For each character holding rows in a shared list, the upgrade:

- **Moves the rows** to that creature type's own section, in the same order, with the same
  dots.
- **Turns a custom entry that plainly is a catalog item into that item.** "Lore: Sabbat",
  "Brawl (Boxing)" and "Meditiation" pick up the real item's price and rules. Only a sure
  match counts: the same name apart from case or punctuation, a recorded former name, a
  trailing footnote mark, a ritual written in another common form, or a "Base: Label" entry,
  where the label becomes the specialization or a note. A guess never counts.
- **Gives a catalog name the catalog's spelling.** If the old list said "Fortune-telling"
  and the new one says "Fortune-Telling", the row takes the new spelling and nothing else
  about it changes.
- **Leaves everything else as it was.** Homebrew the catalog has no answer for stays custom,
  shown and editable as before.
- **Never touches XP.** Not earned, not unspent. Nothing is repriced and nobody is refunded.

Each character that changes gets one **Catalog update** line in its [change
history](help/sheet-history.md), with the matches listed under it, and a snapshot of the
sheet as it was. Changes waiting in the approval queue are pointed at the new sections, and
templates and creature types are switched at the end. On real chronicles about four in ten
custom entries match; the rest stay custom and keep working.

The move takes a few seconds. While it runs people can read but not save: a save gets "The
site is being moved to the new catalog and can't save anything for a moment. Try again in a
minute."

When the upgrade can't move everyone it stops, and a notice across the top of wp-admin says
why. The two usual causes write nothing: a character holds an entry its creature type's own
list doesn't have (add the entry to that list in Schema Blocks, or take it off the
character), or a character belongs to no chronicle (assign it to one, or delete it). The
upgrade tries again by itself once the cause is fixed.

When every character is moved, the shared Abilities, Merits and Flaws blocks and two blocks
no creature type lists any more (Demon Lores and Mortal Numina) are deleted from Schema
Blocks. A block something still uses stays, and the PHP error log names what uses it. Take a
database backup before the upgrade, as you would for any.


## Combos and Bonds Moved by the 1.3.8 Upgrade

Two things earlier imports left in the wrong place are put right by the upgrade itself, one character at a time, each with a line in the character's history and a snapshot of the sheet before it:

- **Combos filed under Disciplines.** A Grapevine import used to keep a combo it could not match ("Combo: Spy Master", "Combi: Draw Fire", and the other spellings) as a custom pick under Disciplines, printed with "(elder)". The upgrade moves each one into Combo Disciplines as a custom combo, keeping the value it held as its XP price. A combo the character already holds there is not doubled, and a divider row such as "Combo Powers-----" stays where it is. Where import lost the price entirely, the combo arrives with no price shown; the site's host can restore those prices from the characters' saved Grapevine files.
- **Bonds kept only in the import record.** A Vampire's Bonds list used to be kept in the character's import history because the sheet had nowhere to show it. The upgrade fills each character's new Bonds section from their newest import record, when that section is still empty.

XP is never touched. A character nothing applies to is left alone, and running the upgrade again changes nothing.

## Adding a Creature Type Without Code

This is the point of the schema-driven design: a new creature type is configuration, every
time, not a code change.

1. **Build or reuse schema blocks.** If the new type needs traits nothing else uses yet
   (its own power list, its own resource pool), create those blocks first under **Schema
   Blocks → Add Block**, picking the right section type for each.
2. **Create the creature stack.** Under **System Config → Creature Stacks → Add Stack**, give it a slug and
   name, then assemble it from existing and new blocks — set each block's column and
   display order.
3. **Creation rules** can be stored on the stack definition alongside its block list (a
   starting dot allocation, a required identity field, and so on), but nothing reads them yet —
   character creation doesn't enforce them in this release.
4. The new type is immediately available everywhere a creature stack is selectable —
   character creation, the roster filter, query building — with no further wiring.

A custom type can be deleted only once no character in any chronicle is that type - a character
can't change its type, and one whose type is gone would have no sheet to show, print, or audit.

One thing a new type can't do: leave the site. Export to Grapevine and chronicle-to-chronicle
transfers travel as Grapevine exchange files, and Grapevine has no race for a type you made up,
so exporting or transferring one of its characters is refused with a message saying so. The
shipped types all travel; Bête goes as the Fera it shares every block with and comes back as a
Bête in Beyond Elysium.

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

If accessSchema is off, not installed, or unreachable for a given request, every permission check falls back to this membership table automatically — a chronicle can run entirely on plain WordPress capabilities with no OWBN plugin stack present at all.

On a chronicle linked to accessSchema (accessSchema on for the site, and an `asc_role_path` on the chronicle), the HST and AST add and remove **players** themselves from the Storyteller Toolkit's **Players** tab; staff roles stay here. Adding a player there also grants `{asc_role_path}/player` through the owbn-core accessSchema client, and removing one revokes it. A chronicle that isn't linked has no Players tab and its players routes answer 404, so its members are managed here alone. The accessSchema server only accepts a grant sent with its read-write API key, so the key owbn-core holds on this site must be that one; with the read-only key, the player is still added here and the Storyteller is told OWbN refused the grant.


## What an HST Can and Cannot Do

An HST is a WordPress `editor`, not an `administrator`, and three pages stay
administrator-only regardless of chronicle role: **Games** (create a chronicle, rename or
delete one), **Chronicle Access** (assign HST/AST/Narrator/Boons/Player), and any settings
scoped to `be_manage_games`. This is deliberate — `game-roles.php` excludes `be_manage_games`
from every chronicle role by name, so no HST can appoint their own AST even for their own
chronicle.

An HST *can* now (as of `v0.99.16`) reach **System Config**'s **Schema Blocks** and
**Templates** for their own chronicle's own customization — forking a block or a template
for a chronicle they hold `hst` membership in. This needs both of two things to be
true: the site-wide capability (`be_manage_schemas`/`be_manage_templates`, granted to
`editor` since `v0.99.16`) and a real membership row in that specific chronicle. Holding the
capability alone, with no membership row, still gets a `403` — it is not a bare
site-wide grant, the same two-layer check every chronicle-scoped route in this plugin uses.
As of `v1.0.0` (below), both **Schema Blocks** and **Templates** are an HST's alone; an AST
holds neither for their own chronicle.

**Narrower as of `v1.0.0`** (owner ruling, 2026-09-15): an AST no longer holds
`be_manage_approval_rules`, `be_manage_schemas`, `be_manage_templates`, or the new
`be_delete_characters` for their own chronicle — Approval Rules, catalog and template
customization (forking a Schema Block or a Template), and permanently deleting a character
are an HST's alone. An AST keeps everything else the two roles used to share equally: import,
transfers, editing characters, and the bulk XP/status/reset operations. Conversely, an HST
gained real write access to three Chronicle Setup settings that used to be
`be_manage_games`-only (a site administrator, no exceptions): **Creature types**,
**Sub-Faction Restrictions**, and **New-character approval** — the new
`be_manage_chronicle_setup` capability, chronicle-scoped the same two-layer way as everything
else. Separately, a Narrator (`be_manage_plots`, not `be_manage_characters`) can now see and
allocate actions for any character in their chronicle, not only one they happen to own as a
player — the character roster the Action Allocator reads from is no longer restricted to
their own characters, though editing, deleting, or creating a character still needs
`be_manage_characters`/`be_delete_characters`, which a Narrator never holds.

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

## AI Writing Assist

A small **AI Assist** button sits next to every long-form free-text field in the plugin —
character Biography/Notes, the NPC Roleplaying Notes block, plot descriptions/cliffhangers/
timeline entries, rumor descriptions, World Object Description/Limitations/text properties,
Schema Block catalog Reference/Description/Source, an Approval Rule's Reason, a chronicle's
own Description, and the Credits text. Clicking it opens a small popover: an empty field asks
what to write about, a field with existing text offers to polish it. Nothing is ever saved
automatically — a suggestion only reaches the field after an explicit **Accept**, and the
field's own normal Save button is still what actually persists it.

**ST-only, by design.** The button is gated on the same management-tier capability that
already governs that field's own area (`be_manage_characters`, `be_manage_plots`,
`be_manage_world_objects`, `be_manage_schemas`, `be_manage_approval_rules`, or
`be_manage_games`) — never the plain edit-tier capability a field's own save route accepts.
A player editing their own character's Biography, for example, never sees this button at all,
even though they can otherwise save that field themselves.

**Two providers, deliberately chosen**: OpenAI first, Claude second — Gemini was considered
and dropped. Each request sends only that one field's own current text (or a short one-line
prompt for an empty field); nothing else about the character, chronicle, or other players
ever leaves the site.

### Configuring it

**This requires a real API key from OpenAI or Anthropic — not a ChatGPT Plus or Claude Pro
login.** There is no way to connect this feature to either provider using a regular
consumer subscription login instead; neither provider offers that as an option, for this
plugin or for anyone else. The two are different products with different billing:

| | Consumer subscription (ChatGPT Plus / Claude Pro) | API key (what this feature actually needs) |
|---|---|---|
| Where you get it | chatgpt.com / claude.ai | platform.openai.com / console.anthropic.com |
| Billing | Flat monthly fee | Pay only for what's actually used - no monthly minimum |
| Works with this feature? | **No - cannot be used here at all** | **Yes - this is the only supported option** |

Getting a key: create a developer account at the API console (not the consumer site) for
whichever provider you want, add a payment method there, and generate a key. The models this
feature uses by default (`gpt-4o-mini` / `claude-haiku-4-5`) are each provider's cheap tier,
and every request sends only one short field's own text - realistic usage for occasional
biography/plot polishing runs to cents, not a real budget line.

Under **Beyond Elysium → System Config → AI Assist** (`be_manage_games`, administrator-only),
a single **Provider** dropdown offers three options — only one is ever configured at a time,
and only that one's fields are shown:

- **OpenAI (ChatGPT)** — the real OpenAI API, needing just an API key.
- **Claude** — the real Anthropic API, needing just an API key.
- **Self-Hosted (OpenAI-compatible)** — see below.

Whichever is selected becomes the site-wide default, used directly for every catalog-level
field (Schema Block descriptions, Credits text — neither belongs to any one chronicle), and
as the fallback for any chronicle that opts in without supplying its own.

Under **Beyond Elysium → Chronicle Setup → AI Assist** (`be_manage_apr` — the same access
tier as Action & Rumor Settings, reachable by an HST with no site-administrator access),
enable the feature for one chronicle and pick from the same three-option dropdown to
optionally give it its own configuration, overriding the site-wide one for that chronicle's
own character/plot/rumor/world-object fields.

**A key is never shown again once saved.** Every settings screen displays only whether a key
is configured (a plain "configured" indicator, never the value) — re-enter a key to change
it, or use **Clear** to remove it. Every key is encrypted at rest.

### Using your own server instead (self-hosted / OpenAI-compatible)

Choosing **Self-Hosted (OpenAI-compatible)** from the Provider dropdown reveals three fields:
an **API base URL**, a **Model** name, and an API key. Point the base URL at any self-hosted
server that speaks the same request/response shape as OpenAI's own Chat Completions API —
Ollama, LM Studio, vLLM, LocalAI, or similar — and name whichever model that server is
running. This isn't a fourth wire protocol: under the hood it's the same OpenAI request shape
at a different URL, since every common self-hosted option already speaks it; there's no
comparably common self-hosted equivalent for Claude's own API, so it isn't offered as a
separate self-hosted flavor. The API key field is still required even for a server with no
real authentication of its own — many accept any placeholder value (check your server's own
docs for what it expects, if anything).

This is the practical way to eliminate per-request API cost entirely: a self-hosted model has
no metered billing, at the cost of running (and paying for) the server yourself. A chronicle
configuring its own Self-Hosted entry is independent of the site-wide default; a chronicle
that falls back to the site-wide key also inherits the site-wide server, so the two never
mismatch.

**Test Connection** — inside whichever fieldset is currently showing — sends a minimal
request using whatever you've currently typed (key, and base URL/model for Self-Hosted,
whether saved yet or not) and reports success or a specific failure, so a typo'd URL or an
expired key is caught before you rely on it in the field. It never tests an already-saved key
silently; a key is never sent back to this page once saved, so testing it means re-entering
it first.

## Multisite

Beyond Elysium runs on a WordPress multisite network. Each site keeps its own characters,
catalog, chronicles, settings and roles, entirely separate from every other site on the
network - that is not a mode you switch on, it is simply how the plugin stores things.

Install it network-wide and leave it **not** network-activated, and each site turns it on
for itself. Network-activating instead would build a full schema and catalog on every site,
whether that site wants it or not.

To let site administrators activate it themselves, enable **Network Admin → Settings → Menu
Settings → Plugins**. That lets them activate and deactivate plugins on their own site only;
installing, updating and deleting plugins stay super-admin-only on a network regardless, and
network-activated plugins never appear on their screen at all.

Deleting a site takes its Beyond Elysium tables with it, and deleting the plugin cleans up
every site that turned on "delete data on uninstall" for itself - a site that never asked for
deletion keeps everything, even if another site did.

**One caveat.** WordPress only loads a plugin on sites where it is active, so the table
cleanup runs only when Beyond Elysium is loaded in that request. If you delete a site from
Network Admin while the plugin is not active on the site you are working from, its tables are
left behind. They are inert, and nothing else is affected, but you may want to drop them -
they are named with that site's own table prefix followed by `be_`:

```sql
SHOW TABLES LIKE 'yni_12_be\_%';
```

## Secure Printing

**Beyond Elysium → System Config → Secure Printing.**

Printing never refuses. With secure printing off, with no certificate installed, or on a host
that cannot sign at all, sheets and reports still print through the same typesetter and come
out looking the same — every page stamped UNSIGNED. An unsigned print can never be mistaken
for a signed one, and a chronicle that will never have a certificate is not locked out of
printing.

A print is signed only when **both** are true:

1. A usable certificate is configured, through three `wp-config.php` constants.
2. An administrator has ticked **Sign printed sheets and reports** on that screen.

The switch is separate from the certificate deliberately. A certificate arriving on the
server isn't the same as a decision to sign with it — you might be testing one, or have
inherited one from whoever ran the site before you. It is site-wide rather than per
chronicle, because the certificate is site-wide; a per-chronicle switch would imply
per-chronicle certificates, which multiplies the one genuinely delicate thing here.

### Installing a certificate

The plugin never holds your private key. It is never uploaded through the browser, never
written to the database, and never stored in the uploads folder. Put the two files outside the
web root over SFTP and point three constants at them:

```php
define( 'BE_PDF_SIGNING_CERT', '/home/you/private/be-signing.crt' );
define( 'BE_PDF_SIGNING_KEY', '/home/you/private/be-signing.key' );
define( 'BE_PDF_SIGNING_PASSPHRASE', 'your passphrase' );
```

Leave the third out if the key has no passphrase — that's a real configuration, not a
mistake. With shell access, this is the command both production chronicles used:

```sh
openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 \
  -keyout be-signing.key -out be-signing.crt -cipher aes-256-cbc
```

### Hosts with no shell

Plenty of shared hosting gives you no command line, so `openssl req` is unavailable — and
that, not knowing where to put a file, is what locks a chronicle out of signed printing for
good. The screen will mint a self-signed pair **in memory** and hand it to you once, with the
constants to paste. Nothing is written to the server or saved in the database. Copy both files
before leaving the page; asking again mints a different certificate.

This needs PHP's `openssl` extension, which is not an extra requirement the feature invents:
a PDF is signed through that same extension, so a host without it cannot sign a sheet no
matter where the certificate came from. Where it's missing, the screen says so rather than
offering a button that cannot work.

See the [Secure Printing](help/secure-printing.md) help page for the full walkthrough.

## Who Can See a Plot, Item, or Location

Every plot, item, and location has a **Who can see this** setting: **Everyone in the
chronicle**, **Storytellers and Narrators only**, or **Only characters matching rules I
set**. A new plot starts Storytellers-only; a new item or location starts open to everyone.
Widening a player's own plot is Storyteller-only — the player who owns it can never widen it
themselves, though they get everything else a global plot has (public and private replies,
directed posts, uploads).

Picking **Only characters matching rules I set** opens the same clause-and-value query
builder the Query Tool uses, against your chronicle's characters — "Clan is Tremere," "Sect
is Sabbat," or several clauses combined with AND/OR. A live count shows how many characters
currently match while you build it, plus a reminder that a character directly connected to
the plot, item, or location (an owner, a holder, an invited co-narrator) always sees it too,
whether or not it matches the rule. A rule with no complete clause yet is treated as "no rule
set" rather than blocking the save — add at least one complete clause before it actually
narrows anything.

A plot entry (a reply on the Timeline) has its own, separate three-way choice: **Public**
(everyone who can see the plot), **Storytellers and Narrators only** (private to you, other
managers, and the entry's own author), or, Storyteller-only, **Directed to specific
characters** — pick from a list of who can currently see the plot at all. A player composing
their own entry only ever sees the first two choices.

## File Uploads

Plots, items, and locations can each carry uploaded files — images and PDFs, 10 MB each. A
plot or location may carry up to 20; an item carries exactly one. An upload follows its
entity's own audience automatically: whoever can open the plot, item, or location can open
what's attached to it, and no one else. The upload control is a **Files** section on the
plot's own detail view (Storyteller Toolkit → Plots & Rumors) and on an item or location's
detail pane (Items & Locations); a Storyteller, or a player plot's own owner, sees an upload
button and a Remove button per file, everyone else who can see the entity sees the list and
a download link only.

These files never go through the WordPress media library, because a media library file is a
public URL anyone can open regardless of anything this plugin decides. Instead each one is
written to its own randomly-named folder under `wp-content/uploads/beyond-elysium-private/`,
served only through a signed-in request that re-checks the owning entity's audience every
time — never a direct link.

**Read this if your host runs nginx.** The private folder ships with a `.htaccess` file that
tells Apache to refuse every direct request to it. Apache honors that file automatically.
**nginx does not read `.htaccess` at all**, so on an nginx host that rule does nothing by
itself — what still stands between a stranger and a file is that its folder name is 32 random
hex characters, never shown anywhere, in a path nobody has reason to guess. That is real
protection, but it is unguessable, not locked the way it is on Apache. If your host runs
nginx and you want the same server-level guarantee Apache gets for free, add a rule to your
site's own nginx config denying direct requests under `uploads/beyond-elysium-private/`; ask
your host if you're not sure which web server you're on.

## Players Proposing Items

A player can propose an item, location or rote for their own character from **My Chronicle →
Propose an Item**. It arrives as an ordinary change in the Approval Queue rather than a
separate list.

Approving one writes the chronicle's catalog, so it needs **both** `be_manage_characters`
(to work the queue at all) and `be_manage_world_objects` (to write the catalog). An HST and
an AST hold both. A reviewer holding character rights but not catalog rights sees the row and
can reject it, but not approve it — otherwise character-approval rights would quietly become
catalog-write rights.

Approval creates the catalog row and the character's connection to it in one transaction:
the player asked for their character to have the thing, so a catalog entry without the
connection would only be half of what was approved.

## REST API

Every read and write in the plugin goes through its REST API (`be/v1` namespace), which
every one of the widgets above is a thin client of — nothing in the admin or Storyteller UI
does anything the API itself doesn't also expose. See the
[REST API reference](rest-api.md) for the full endpoint list, parameters, and permission
requirements.
