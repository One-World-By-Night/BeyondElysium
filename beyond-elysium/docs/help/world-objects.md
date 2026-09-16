# Items & Locations

The chronicle's catalog of items, locations, and rotes that exist on their own, independent
of any one character - create them, edit them, make a variant with Duplicate, and connect
them to whoever holds them.

## Who can use this

Storytellers (HST and AST). Reaching **Beyond Elysium → Items & Locations** in wp-admin needs
the same site-wide capability an HST, AST, or administrator account already carries. A
Narrator, the chronicle's Harpy, and a player never see this menu item - a Harpy manages
boons on the separate [Boon Ledger](boon-ledger.md) instead, and boons never appear in this
catalog at all.

## How to get there

wp-admin sidebar → Beyond Elysium → Items & Locations.

## The screen

- **Game** - a dropdown listing every chronicle on the install. Picking one loads that
  chronicle's catalog.
- **New item**, **New location**, or **New rote** - opens a blank create form; the label
  follows whichever tab is active.
- Tabs: **Items**, **Locations**, **Rotes**. Boons have their own ledger and never appear
  here.
- **Search name…** - filters the active tab's list by name.
- A paginated table, with columns that change per tab:
  - Items: Name, Type, Damage, Level.
  - Locations: Name, Type, Owner, Security.
  - Rotes: Name, Level, Spheres.
- Click a row to open it in the detail pane alongside the list.
- **Previous** / **Next**, with the current page and total count.

### The detail pane

- Name and description.
- Every property that object type defines, formatted for its kind:
  - **Items** - Type, Subtype, Level, Bonus, Damage Type, Damage Amount, Concealability,
    Powers, Appearance, plus trait-list entries for Tempers, Negatives, Abilities, and
    Availability.
  - **Locations** - Type, Owner, Where, Appearance, Access, Security, Security Traits,
    Security Retests, Gauntlet, Umbra, Affinity, Totem, plus a Links trait list.
  - **Rotes** - Level, Duration, Description, Grades, plus a Spheres trait list.
- Rarity, Cost, and Limitations, shown only when set.
- **Connections** - every character connected to this object, and the tools to add or remove
  one. See [Connections](connections.md).
- **Edit** and **Duplicate** buttons below the detail view.

### The create/edit form

- **Name** (required), a rich-text **Description** (with an AI Assist button), **Rarity**,
  **Cost**, a rich-text **Limitations** (with an AI Assist button).
- One field per property the object type defines - a single-line box, a number box, a date
  box, a rich-text box (with its own AI Assist button, for a long-text property such as an
  item's Appearance or a rote's Description) or, for a trait-list property, a repeatable
  Name/Count/Note row with its own **Add** and **Remove**.
- **Save Changes** (editing) or **Create** (new), and **Cancel**.

"No games exist yet - create one under Beyond Elysium → System Config → Games first." appears in place of
everything above when no chronicle exists on the install at all.

## Common tasks

### Create an item, location, or rote

1. Open Beyond Elysium → Items & Locations and pick the chronicle.
2. Pick the **Items**, **Locations**, or **Rotes** tab.
3. Click **New item**, **New location**, or **New rote** (matching the tab you picked).
4. Fill in **Name** and whichever properties apply.
5. Click **Create**.

### Edit an existing entry

1. Find it in the list - use **Search name…** if the list is long.
2. Click its row to open the detail pane.
3. Click **Edit**.
4. Change what you need and click **Save Changes**.

### Make a variant of an existing entry

1. Open the entry you want to copy.
2. Click **Duplicate**.
3. The form opens pre-filled from the original, named "Copy of [original name]" - change
   whatever should differ.
4. Click **Create**. The original entry is never touched.

### Connect an item or location to the character who holds it

1. Open the item or location.
2. Under **Connections**, follow [Connections](connections.md).

## Things to know

- **Duplicate never touches the original.** It pre-fills a fresh create form from the entry
  you duplicated - nothing is saved until you click **Create**, and the source entry is
  unchanged either way.
- **Boons aren't managed here.** Items, Locations, and Rotes share this catalog; a boon is
  recorded, repaid, and kept only on the [Boon Ledger](boon-ledger.md).
- **Storyteller-only text is invisible to everyone else.** Any `[ST]...[/ST]` text in a
  description or limitations field never reaches a player, in the catalog list or anywhere
  else it appears - you always see it in full here, since only a Storyteller opens this page.
- **The same entry can be connected to many characters at once** - connecting one item to
  fifty characters is fifty ordinary connections to the same row, not fifty copies. When one
  character's copy needs to become unique (an heirloom, something that gets damaged or
  renamed), Duplicate the item first and connect that copy instead.
- On a narrow screen this catalog list stacks into cards instead of scrolling sideways.

## Troubleshooting

- **"Failed to load world objects."** Refresh and try again.
- **"No items/locations/rotes match these filters."** Clear the search box.
- **"Failed to save this world object."** Check that **Name** is filled in, then try again.
- **"Failed to load this item. Try again."** Shown in place of the edit or duplicate form when
  the entry couldn't be fetched - reload the page.
- **I don't see this menu item at all.** It needs a Storyteller role in at least one
  chronicle, or an administrator account.

## Related

- [Connections](connections.md)
- [Boon Ledger](boon-ledger.md)
- [Reports](reports.md)
- [Query Tool](query-tool.md)
- [Import](import.md)
- [AI Writing Assist](writing-assist.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Admin Guide](../admin-guide.md#the-wp-admin-menu)
