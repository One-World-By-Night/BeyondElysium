# Connections

Links two things together - a character to a plot, an item or location to whoever holds it,
or an outside contact with no sheet of their own. The same tool handles all of it, wherever
you see a **Connections** section or a **Connect character** button.

## Who can use this

Storytellers (HST and AST) add, edit, and remove connections wherever this tool appears - a
plot's own connections, an item or location's connections to characters, and a character's
own Connections section on their sheet. A Narrator, who otherwise works in Plots & Rumors
just like a Storyteller, can open this same panel from a plot and see its current
connections, but adding or removing one there is refused - narrower than the rest of their
Plots & Rumors access. The chronicle's Harpy and a plain player never see this panel at all.

## How to get there

- Storyteller Toolkit → Plots & Rumors → open a plot → **Connect character** in its action
  bar.
- Beyond Elysium → Items & Locations (wp-admin) → open an item or location → its
  **Connections** section.
- My Chronicle → Sheet tab → a character's own **Connections** section - it only appears
  there for a Storyteller.

## The screen

- A dropdown for what kind of thing to connect to: **Character in this chronicle**,
  **External (name only)**, **Plot**, **World object**, **Tag**.
- Depending on that choice: a plain dropdown of names (character or plot, up to 100 loaded
  for this chronicle), a **Search world objects…** box, a plain text box for an external
  name, or nothing extra for a bare Tag.
- **Label** - a short free-text tag for the relationship (for example "sister" or "involved
  in"). Disabled for an External connection, since the name you typed already serves as the
  label.
- A second text box for **Notes** (optional).
- **Add connection** - disabled until you've picked or typed a valid target.
- Below the form, every existing connection for this entity: the other end's name, a badge
  naming its type (unless it's an External entry), the connection's label if it has one, its
  notes if any, and a **Remove** button.
- "No connections yet." when the list is empty; "Loading…" while it's fetching.

## Common tasks

### Connect a character to a plot

1. Open the plot (Storyteller Toolkit → Plots & Rumors).
2. Click **Connect character** in its action bar.
3. Leave the dropdown on **Character in this chronicle** and pick the character.
4. Optionally add a **Label** and **Notes**.
5. Click **Add connection**.

### Connect an item or location to the character who holds it

1. Open the item or location (Beyond Elysium → Items & Locations).
2. In its **Connections** section, leave the dropdown on **Character in this chronicle**.
3. Pick the character, and add a **Label** (for example "carried by") or **Notes** if you
   want.
4. Click **Add connection**.

### Record an outside contact with no character sheet

1. Open the panel from wherever it applies.
2. Set the dropdown to **External (name only)**.
3. Type their name.
4. Click **Add connection**. The name you typed becomes both the connection's label and its
   display name in the list.

### Remove a connection

1. Find it in the list.
2. Click **Remove**.

## Things to know

- **This one tool covers five kinds of link**, not only "connect a character" - the same
  dropdown also links to another plot, a world object, a freeform tag, or an outside name
  with no record in the system.
- **A Narrator can look but not touch, on a plot.** Opening Connect character from a plot
  shows the form and the current list, but Add connection and Remove both fail for a
  Narrator - only a Storyteller can actually change a plot's connections.
- **Removing is immediate** - there's no confirmation step and no undo. You'd have to add it
  again from scratch.
- **A character's own Connections list can include a boon they're part of**, shown as a
  world object named for the boon, labeled `owed_by` or `owed_to`. Removing it here breaks
  that boon's record on the Boon Ledger instead of marking it repaid - use the ledger's own
  **Mark repaid** control for a boon instead.
- **The same item or location can be connected to many characters at once** - it's shared,
  not copied. If one character's copy needs to become unique later (an heirloom, something
  that gets damaged or renamed), duplicate the item on the Items & Locations screen and
  connect that copy instead.
- The character and plot pickers load up to 100 of each for this chronicle as a plain
  dropdown - in a very large chronicle, unlike the world-object picker, there's no
  search-as-you-type for those two.

## Troubleshooting

- **"Failed to load connections."** Refresh and try again.
- **"Failed to create this connection."** Most often this is a Narrator trying to add one on
  a plot, which is Storyteller-only - check who's signed in. It can also mean the target no
  longer exists.
- **"Failed to remove this connection."** Try again, or ask a Storyteller to remove it.
- **Add connection won't click.** Pick or type a target first - External needs a typed name,
  everything else needs a selection.
- **A boon disappeared from the Boon Ledger.** Someone removed one of its two connections
  from a character's Connections section. It can't be repaired from here - record the boon
  again.
- **I don't see this section at all.** It's Storyteller-only - a Narrator, the chronicle's
  Harpy, and a player never see it.

## Related

- [Plots & Rumors](plot-manager.md)
- [Character Sheet](character-sheet.md)
- [Boon Ledger](boon-ledger.md)
- [Roles](roles.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Storyteller Guide](../st-guide.md#12-reports-cards-and-batch-output)
