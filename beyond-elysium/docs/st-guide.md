# Storyteller Guide

This guide covers the day-to-day work of running a chronicle in Beyond Elysium: creating
the game, seeding its rules, managing characters, reviewing player submissions, running
plots, and pulling data out of the roster. It assumes the plugin is already installed and
active — see the [README](../README.md) for installation. Looking for the player's side of
all this instead? See the [Player Guide](player-guide.md).

## 1. Creating a Game

A **game** (chronicle) is the top-level container everything else belongs to — characters,
plots, changes, and queries are all scoped to one game and never visible from another.

1. In wp-admin, go to **Beyond Elysium → Games**.
2. Click **Add Game**, give it a name, and save. The plugin generates a URL-safe slug from
   the name automatically (or set one explicitly).
3. If this chronicle also has an `owbn_chronicle` post (via `owbn-chronicle-manager`), the
   two stay in sync automatically once both plugins are active — publishing or renaming the
   chronicle post keeps the game's name current. A slug change on the chronicle side does
   not rename the existing game row; it is a known, accepted limitation (see the plugin's
   own `Chronicle_Sync` class comment) since the upstream plugin does not allow a chronicle's
   slug to change through its own UI anyway.

### accessSchema and chronicle roles

Under **Beyond Elysium → Chronicle Access**, you can:

- Turn accessSchema role-path checking on or off, site-wide.
- Set a chronicle's `asc_role_path` (its accessSchema path prefix, e.g. `Chronicle/KONY`) if
  the wider OWBN plugin stack is present.
- Add and remove chronicle members and set their role: **HST**, **AST**, **Narrator**, or
  **Player**.
- Turn the per-chronicle "email a player when their change is reviewed" notification on or
  off (see [Notifications](#6-notifications) below).

If accessSchema is off, unreachable, or a member has no accessSchema role for this
chronicle, permissions fall back to this chronicle-membership table automatically — nothing
breaks, and nothing needs configuring differently.

A player is added to this table automatically the moment they create their first character
or are assigned an existing one — you rarely need to add players by hand, only Storytellers
and Narrators.

## 2. Seeding Schema

**Schema blocks** define what goes on a character sheet (trait lists like Merits and
Backgrounds, tiered powers like Disciplines and Gifts, resource pools like Blood and
Willpower, identity fields like Nature and Demeanor). **Creature stacks** assemble blocks
into a complete sheet for one creature type — Vampire, Werewolf, Mage, and so on.

Every install ships with the full MET-mechanics catalog already seeded — you do not need to
build these from scratch. Under **Beyond Elysium → Schema Blocks** and **Beyond Elysium →
Creature Stacks** you can review what exists and, if your chronicle needs a house rule or a
homebrew trait, fork just that block for your own game without touching the shared catalog
other chronicles use. A forked block is scoped to your game only; the base catalog is never
edited in place.

Adding an entirely new creature type (one this catalog doesn't already cover) is an admin
task — see the [Admin Guide](admin-guide.md).

## 3. Making Characters

Storytellers create characters two ways:

- **By hand**, under **Beyond Elysium → Characters → Add Character** — pick a creature
  stack, fill in identity fields, and assign traits directly. Useful for NPCs and for
  entering a character on a player's behalf.
- **By import** (see [Importing from Grapevine](#5-importing-from-grapevine) below) — the
  fastest path for a player who already has a Grapevine character file.

Players can also create their own characters from the front-end **Characters** page, subject
to whatever approval rules your chronicle's schema blocks define.

Every character needs a linked WordPress account (`wp_user_id`) to be playable by someone.
If a character is imported or entered before its player has an account, use the **Assign
Player** control on the character's row in the roster — search by name or email and attach
the account once it exists. Until then the character is visible but not editable by anyone
but a Storyteller.

## 4. Running the Approval Queue

Every trait purchase, XP award, or sheet change a player submits becomes a **pending
change** unless your schema's approval rules mark it auto-approved. The queue lives on the
**Storyteller Toolkit** front-end page, or in the approval queue widget wherever your
chronicle has placed it.

- Filter by character, change type, or approval level.
- Approve or reject one at a time, with an optional note (a note is required on reject).
- Select several and **Approve Selected** to clear a batch in one action — this applies each
  change individually (same sheet mutation and XP deduction as approving one at a time), it
  is a convenience over the loop, not a different approval mechanism.
- A change over the **coordinator** threshold (a genre/site-wide OWBN role, not a
  per-chronicle one) is flagged as such in the queue, but coordinator-tier enforcement itself
  is not yet built — any Storyteller can currently approve any tier. This is a known,
  deliberately-unbuilt gap, not an oversight.

## 5. Importing from Grapevine

Under **Beyond Elysium → Import**, upload a `.gex` (character or game exchange file) or a
full `.gv3` game file exported from Grapevine 3.01.

- A **character** exchange file is checked by name against existing characters in this game.
  If a match is found, you choose to skip it, overwrite the existing character in place
  (preserving its ID and every connection/plot reference to it), or import it as a new,
  separate character.
- A **game** file (`.gv3`) can create a brand-new chronicle from its contents, or merge into
  an existing one — the existing chronicle is always the protected base; the file only
  contributes characters, plots, items, and locations it doesn't already have, and anything
  that collides by name gets the same skip/overwrite/import-as-new choice.
- Any trait the importer can't match automatically is flagged for manual resolution before
  the import can complete — nothing partially imports.

## 6. Notifications

When a Storyteller approves or rejects a player's submitted change, that player's account
gets one email. If a batch approval clears several of one player's changes at once, they get
a single summary email, not one per change — and a change that auto-approves (an XP award
you grant directly, for example) never sends anything, since the player already knows they
just made it.

Turn this off for the whole chronicle under **Chronicle Access**. A player can also opt out
for themselves from their own WordPress profile screen, next to the sheet-customization
checkbox.

## 7. The Game Dashboard

The **Dashboard** page (auto-created for every chronicle) shows different things depending
on who's looking:

- **Storytellers** see character counts by creature type and status, the pending-change
  count, the active-plot count, and a feed of recent activity across the whole chronicle.
- **Players** see their own characters, their own pending changes, and their own plots —
  nothing from anyone else's sheet.

## 8. Plots, Actions, and Rumors

The **Storyteller Toolkit** page is where plots live: create a plot, respond to player
actions submitted against it, allocate action slots, and generate rumors that distribute to
players via a saved query (see below) rather than by hand. Players see their own plot
connections on the **My Plots & Rumors** page.

## 9. Action & Rumor Settings and the Background-Use Ledger

Under **Beyond Elysium → Action & Rumor Settings**, pick a chronicle to configure how many
downtime actions a character receives each game date and which rumors generate
automatically. The **Actions** tab sets personal actions per character, whether unused
actions and growth carry forward week to week, whether Common Actions (from Influences and
configured Backgrounds) are added automatically, an actions-per-level override table for
specific dot ratings, and which Backgrounds grant an action at all — an Influence always
does and is shown for reference only. The **Rumors** tab holds the eight rumor-generation
toggles the Storyteller Toolkit's rumor generator reads; Group and Subgroup rumors are shown
but not yet functional, since no character data exists to generate them from. A **Restore
Grapevine defaults** button is available if you want the original 1998 values instead of
Beyond Elysium's own (higher personal-action, carry-forward-on) defaults — it does not touch
your rumor settings.

Once a character's actions are allocated (in the Action Allocator, under Plots), each
budgeted subaction — Personal, and any Influence or configured Background — gets its own
**background-use ledger**: record what the character actually did with that action, and fill
in the result once it's adjudicated. A background with no live budget can still have a use
recorded against it (it shows under "Other backgrounds," with a note pointing back here) —
recording is never blocked by a chronicle simply not having configured that background yet.
**Clear all for this Character** and **Clear all for this Date** remove recorded uses only;
they never touch a character's action budget itself. Players see and can record uses for
their own characters directly from their character sheet's **Background uses** panel, and
can clear their own use as long as no result has been recorded against it yet.

## 10. Building and Running Queries

Under **Beyond Elysium → Query Tool**, pick which of four inventories to search —
**Characters**, **Items**, **Locations**, or **Rotes** — with a tab strip at the top of the
tool. Each inventory offers its own field list (a location's Gauntlet rating and Security
Level, an item's Type and Concealability, a rote's Level and Sphere prerequisites, and so
on), build a filter against it, and either browse the results or run one of the five
built-in statistics. Switching inventories clears the clauses on screen, since a clause
built against one inventory's fields has no meaning on another's.

A query can be saved and reused; the Saved Queries list shows which inventory each one
searches, and loading one switches back to that inventory automatically. A saved query is
also what a rumor's target audience is defined by (**Characters** queries only) — build the
query once, point the rumor at it, and it recalculates who's in scope every time it runs
rather than freezing a player list at creation time.

Bulk XP award is available only on **Characters** results, for the same reason a rumor can
only target characters: an Item, Location, or Rote result isn't a character, and the tool
never offers an action that would only make sense as one.

## Roles Reference

| Role | Access |
|---|---|
| HST | Everything — characters, plots, queries, schema and template customization, importing, chronicle membership. The one exception is deleting or editing the chronicle itself (renaming it, changing its slug); that's a site-administrator act, not a chronicle-level one, by design. |
| AST | Everything HST can do within the chronicle, including importing — the only difference from HST is that an AST cannot delete or edit the chronicle itself. |
| Narrator | Plots — creating and running them, responding to player actions, generating rumors, and the roster queries that plot work depends on. Not full character management. |
| Player | Creates and submits their own characters, and edits their own sheet — every edit still goes through the same approval process everyone else's does. Nothing outside their own characters, changes, and plot connections. |

This is the plugin's own chronicle-scoped role model (`be_game_members.role`, one of
`hst`/`ast`/`narrator`/`player`), checked in addition to whatever your underlying WordPress
account can already do — both have to allow an action for it to go through. If your
chronicle runs accessSchema, its own role paths are checked first and can grant access this
table doesn't cover; this table describes the fallback every chronicle has regardless.
