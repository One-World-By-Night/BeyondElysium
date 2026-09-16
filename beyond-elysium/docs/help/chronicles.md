# Chronicles

A chronicle is the one container everything else belongs to - every character, plot, and
catalog customization is scoped to one chronicle and never visible from another.

## Who can use this

Everyone belongs to, or can be shown, a chronicle at some level. Creating, renaming, or
deleting one is a site administrator's job alone, on [Games](games.md). Everything else -
membership, day-to-day setup, and ordinary play - is scoped to whichever chronicle you're
looking at, according to your role there.

## How to get there

There's no single screen - a chronicle is the thing you pick at the top of
[My Chronicle](my-chronicle.md), [Storyteller Toolkit](storyteller-toolkit.md), and most
Storyteller-facing wp-admin screens, through a **Chronicle** (or **Game**) dropdown.

## The screen

**Switching chronicles.** My Chronicle and Storyteller Toolkit each carry a **Chronicle**
dropdown at the top, listing every chronicle you belong to, labeled with your role in each.
Picking a different one reloads everything below it - your characters, the tabs you see, all
of it - for that chronicle. wp-admin screens that work across chronicles (Characters, Items &
Locations, Query Tool, Import, Chronicle Setup, and others) carry a similar picker, usually
labeled **Game**, listing every chronicle on the install rather than only ones you belong to.

**What's shared across every chronicle:**

- Your WordPress account, and the roles you hold - a role is set per chronicle, but the
  account itself is the same one everywhere.
- The base catalog: Beyond Elysium's own schema blocks and templates, until a chronicle forks
  its own copy.
- Creature stacks - which creature types exist at all, and what each one's sheet is built
  from. These are never per-chronicle; a chronicle only narrows which of them it offers, on
  [Chronicle Setup](chronicle-setup.md).
- Site-wide settings: whether accessSchema is used, the AI Assist provider used by default,
  and whether uninstalling the plugin deletes its data.

**What's scoped to one chronicle:**

- Every character, plot, item, location, and rote.
- Membership and roles - who's an HST, AST, Narrator, Boons, or Player here specifically.
- A chronicle's own fork of a schema block or template, once it makes one.
- Which creature types are offered, and any sub-faction restriction within one (Vampire yes,
  but no Sabbat, for example).
- Approval rules, Action & Rumor settings, and notification settings.
- Verification codes and transfers, once issued.

**A chronicle's copy of a schema block keeps what it changed and follows the shared block for
the rest**, so catalog fixes still reach it. A copied template is different: it stops following
the shared template, and a later change there needs making again, by hand.

## Common tasks

### Switch to a different chronicle

1. Open [My Chronicle](my-chronicle.md) or [Storyteller Toolkit](storyteller-toolkit.md).
2. Use the **Chronicle** dropdown at the top.

### Find a chronicle's slug

1. wp-admin → System Config → Games. See [Games](games.md).

### See what's customized for your chronicle versus shared

1. Open [Chronicle Setup](chronicle-setup.md) - its **Approval rules**, **Catalog
   customisation**, and **Sheet templates** rows each report whether your chronicle has its
   own or is using Beyond Elysium's defaults.

### Add a new player to your chronicle (Storyteller)

1. Create a character for them, or assign an existing one to them - see
   [Characters](character-list.md#assign-or-change-a-characters-player-storyteller). Either
   one makes the chronicle appear on their own My Chronicle switcher; there's no separate
   membership step for 1.0.0.

### Create a chronicle (site administrator)

1. wp-admin → System Config → Games → **+ New Game**. See [Games](games.md).

## Things to know

- **The Chronicle dropdown on My Chronicle and Storyteller Toolkit only lists chronicles you
  already belong to** - it isn't a directory of every chronicle on the site.
- **wp-admin's Game pickers are different** - they list every chronicle on the install, but
  using most of what's behind them still needs a real role in whichever one you pick.
- **Joining a new chronicle isn't done from a picker.** Starting your first character in a
  chronicle you don't belong to yet is a request to join - it waits pending until a
  Storyteller there approves it. See [Roles](roles.md).
- **Renaming a chronicle's slug moves everything that names it** - characters, forked catalog
  blocks, pages, verification codes, and transfers all move with it. Deleting one is final:
  the one confirmation names everything it holds, and a yes removes all of it for good. See
  [Games](games.md).
- **A chronicle starts with nothing configured beyond the base catalog.**
  [Chronicle Setup](chronicle-setup.md) is where you see exactly what's left.

## Troubleshooting

- **"You don't belong to any chronicle yet."** Ask that chronicle's Storyteller to add you -
  see [Characters](character-list.md#assign-or-change-a-characters-player-storyteller). For
  1.0.0 that's the only way in; there's no self-service join link yet.
- **A chronicle I just joined isn't in my dropdown yet.** Your first character there is likely
  still a pending join request.
- **"No games exist yet - create one under Beyond Elysium → System Config → Games first."** Only a site
  administrator can create one - see [Games](games.md).
- **I don't see a Storyteller tool I expect.** Check the **Chronicle** dropdown - your role
  can differ between chronicles.

## Related

- [My Chronicle](my-chronicle.md)
- [Storyteller Toolkit](storyteller-toolkit.md)
- [Games](games.md)
- [Chronicle Setup](chronicle-setup.md)
- [Chronicle Access](chronicle-access.md)
- [Schema Blocks](schema-blocks.md)
- [Creature Stacks](creature-stacks.md)
- [Roles](roles.md)
- [Storyteller Guide: Creating a Game](../st-guide.md#1-creating-a-game)
