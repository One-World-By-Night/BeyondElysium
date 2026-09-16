# Admin Characters

The full character roster from wp-admin, for whichever chronicle you pick - player
characters and NPCs on one page, with a toggle between them and a button to start a new one.

## Who can use this

Storytellers (HST and AST) of a chronicle, and any WordPress administrator. Beyond Elysium →
Characters itself needs only the site-wide capability every HST, AST, or administrator
account already carries, so the **Game** dropdown lists every chronicle on the install - but
the roster only loads, and Assign player/Delete only work, for a chronicle where you
actually hold HST or AST (an administrator can manage any chronicle regardless of
membership). Pick a chronicle you don't hold a role in and the list fails to load. A
Narrator, the chronicle's Harpy, and a player never see this menu item at all.

## How to get there

wp-admin sidebar → Beyond Elysium → Characters.

## The screen

- **Game** - a dropdown listing every chronicle on the install. Picking one reloads the
  roster below for it.
- **Show NPCs instead of player characters** - a checkbox. Unchecked shows player
  characters; checked swaps the whole table to NPCs.
- **+ New Character** - opens the same character-creation form a player uses, on the front
  end, pre-set to whichever chronicle you picked here. See
  [Character Editor](character-editor.md).
- Below that, the same roster table as the front-end Characters tab - see
  [Characters](character-list.md) for its columns, sorting, and pagination - except this
  page always shows the Assign player/Change player and Delete actions, and always shows
  every character in the chronicle rather than just your own.
- "No games exist yet - create one under Beyond Elysium → System Config → Games first." appears in place of
  everything above when no chronicle exists on the install at all.

## Common tasks

### Browse a chronicle's characters

1. Open Beyond Elysium → Characters.
2. Pick the chronicle from **Game**.

### Switch between player characters and NPCs

1. Check or uncheck **Show NPCs instead of player characters**.

### Create a character or NPC

1. Pick the chronicle from **Game**.
2. Click **+ New Character**.
3. Fill in the form - check **This is an NPC** if that's what you're building. See
   [Character Editor](character-editor.md).

### Flag an existing character as an NPC, or un-flag one

1. Click its name to open its Sheet, then **Edit this character**.
2. Toggle **This is an NPC** there. See [Character Editor](character-editor.md).

### Assign, change, or delete a character

1. Find it in the table.
2. Use **Assign player** / **Change player**, or **Delete** - see
   [Characters](character-list.md) for both.

## Things to know

- **This is the only place to see or manage NPCs.** The front-end Characters tab never shows
  them, to anyone.
- **The Game dropdown isn't limited to chronicles you actually manage** - it lists every
  chronicle on the install. Loading the roster, and using Assign player or Delete, still
  need a real Storyteller role in whichever one you've picked (or a site admin account) -
  see the [Admin Guide](../admin-guide.md#what-an-hst-can-and-cannot-do).
- **+ New Character and clicking a name both leave wp-admin** - they open the front-end My
  Chronicle page instead of a screen here.
- **Deleting a character here can't be undone** - it takes the character's change history,
  snapshots, sheet style, and connections with it.
- Every Beyond Elysium admin page, including this one, carries a small memorial line at the
  very bottom: "In Memory of Arielle 'XP Day' M."

## Troubleshooting

- **"No games exist yet - create one under Beyond Elysium → System Config → Games first."** Only a
  site administrator can create one.
- **"Failed to load characters. Try refreshing the page."** If this keeps happening for one
  specific chronicle, you likely don't hold HST or AST there - try a chronicle you do, or
  ask a site admin.
- **I don't see + New Character.** Pick a chronicle from **Game** first - it only appears
  once one is selected.
- **I don't see this menu item at all.** It needs a Storyteller role in at least one
  chronicle, or an administrator account.

## Related

- [Character Editor](character-editor.md)
- [Characters](character-list.md)
- [Admin Dashboard](admin-dashboard.md)
- [Games](games.md)
- [Roles](roles.md)
- [Admin Guide](../admin-guide.md#what-an-hst-can-and-cannot-do)
- [Storyteller Guide](../st-guide.md#3-making-characters)
