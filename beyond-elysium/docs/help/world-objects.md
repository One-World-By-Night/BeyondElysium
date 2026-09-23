# Items & Locations

The chronicle's catalog of items, locations, and rotes that exist on their own, independent of any one character - create them, edit them, make a variant with Duplicate, copy an item for a specific character, and connect them to whoever holds them.

## Who can use this

Storytellers (HST and AST). Reaching **Beyond Elysium → Items & Locations** in wp-admin needs the same site-wide capability an HST, AST, or administrator account already carries. A Narrator, the chronicle's Harpy, and a player never see this menu item - a Harpy manages boons on the separate [Boon Ledger](boon-ledger.md) instead, and boons never appear in this catalog at all.

## How to get there

wp-admin sidebar → Beyond Elysium → Items & Locations.

## The screen

- **Game** - a dropdown listing every chronicle on the install. Picking one loads that chronicle's catalog.
- **New item**, **New location**, or **New rote** - opens a blank create form; the label follows whichever tab is active.
- Tabs: **Items**, **Locations**, **Rotes**. Boons have their own ledger and never appear here.
- **Search name…** - filters the active tab's list by name.
- **Catalog / Personal copies / All**, items only - which items the list shows. **Catalog** (the default) hides every copy made for a character; **Personal copies** shows only those; **All** shows both. See **Copy for a character** below.
- A paginated table, with columns that change per tab:
  - Items: Name, Type, Damage, Level.
  - Locations: Name, Type, Owner, Security.
  - Rotes: Name, Level, Spheres.
- Click a row to open it in the detail pane alongside the list.
- **Previous** / **Next**, with the current page and total count.

### The detail pane

- A **breadcrumb**, locations only, shown when the location is inside another one - "Downtown › Elysium."
- Name and description.
- **Based on {source}**, items only, shown only on a copy - the item it was copied from. See **Copy for a character** below.
- **Used up.** / **Expired.**, items only, shown only once one applies - see **Uses and Expires** below.
- Every property that object type defines, formatted for its kind:
  - **Items** - Type, Subtype, Level, Bonus, Damage Type, Damage Amount, Concealability,
    Powers, Appearance, Uses, Uses Left, Expires, plus trait-list entries for Tempers,
    Negatives, Abilities, and Availability.
  - **Locations** - Type, Owner, Where, Appearance, Access, Security, Security Traits,
    Security Retests, Gauntlet, Umbra, Affinity, Totem, plus a Links trait list (the
    original free-text one Grapevine ported over - see **Links** below for the newer,
    real one). **Owner** and **Where** show a real linked character's or the parent
    location's name instead of the typed text, wherever one exists - see Things to know.
  - **Rotes** - Level, Duration, Description, Grades, plus a Spheres trait list.
- Rarity, Cost, and Limitations, shown only when set.
- **Inside This Location**, locations only, shown when other locations are nested inside this one - each one named, e.g. every room inside a building.
- **Who's Here**, locations only - who's `based_at` this location right now. You see every named link, with which one (owner, domain, haven, or based here); a player only ever sees the NPCs based here whose own public profile reaches them (see [Who's Who](whos-who.md)) - never a link's label, never another player's haven.
- **Who can see this** - items and locations only, never rotes: **Everyone in the chronicle** (the default), **Storytellers and Narrators only**, or **Only characters matching rules I set**, which opens a query builder against your characters with a live count of who currently matches. A character directly connected to the item or location (its holder, for instance) always sees it regardless of this setting.
- **Files** - items and locations only: any images or PDFs attached, up to one for an item or 20 for a location, 10 MB each, with a download link per file and, for you, an upload box and a **Remove** button per file.
- **Connections** - every character connected to this object, and the tools to add or remove one. See [Connections](connections.md).
- **History**, items only - every event recorded against this item, oldest first: given, taken, traded, stolen, lost, used, copied, proposed, and adjusted, each with when it happened and any note attached.
- **Edit**, **Duplicate**, (items only) **Copy for a character**, and (items only) **Transfer** buttons below the detail view.

### Copy for a character

Items only - a real, independent copy of a catalog item made for one specific character, distinct from **Duplicate** (a blank create form pre-filled from the original, connected to nobody) and from an ordinary **Connection** (the same catalog row shared by everyone connected to it).

1. Open the item, then click **Copy for a character**.
2. Choose the character, and optionally give the copy its own name (it keeps the source's name otherwise).
3. Click **Copy**. The new copy opens in the editor, already connected to that character and visible only to them.

Copying carries over every field and property, and any file attached to the source - as its own separate file, so changing one item's file never touches the other's. The copy is always **Only characters matching rules I set**-visible to just its holder, no matter what the source's own **Who can see this** says; widen it from there like any other item if it should reach anyone else. The source item is never changed.

### Uses and Expires

Items only, in the create/edit form: **Uses** (how many times it can be used at all, leave blank for unlimited), and once the item exists, **Uses Left** and **Expires** (a date). Once **Uses** is set, the item shows **Used up** the moment its **Uses Left** reaches zero; **Expires**, once passed, shows **Expired** on its own regardless of uses. Both are checked whenever the item is used - see [Reports](reports.md)'s Item Cards for how uses and expiry print on a card, and each edit to either is recorded on the item's own **History**.

### Transfer

Items only - moves an item's connection from whoever holds it now to a new character in one step, or clears it with nobody holding it at all.

1. Open the item, then click **Transfer**.
2. Pick how it changed hands: **Given**, **Traded**, **Stolen**, or **Lost**.
3. For anything but **Lost**, choose the recipient.
4. Optionally add a note, then click **Transfer**.

Every existing connection between the item and a character is removed first - a physical item transfer is exclusive, not one more shared connection alongside others - and the move is recorded on the item's own **History**.

### Verification codes

Items only. Every time an item's card is printed - **Item Cards** in Reports, or a player's **Print My Items** - it carries a "Verify: {link}" line a player can open to confirm the card is genuine and see whether it still matches the item today (who holds it, uses left, and expiry). See [Verify Character](verify.md).

Printing the same item's card again reuses the same code as long as nothing about it (its name, uses left, or expiry) has changed since; a real change, or a new holder, prints a new code instead. An old code is never silently dropped - it stays live and simply reports the mismatch, unless you revoke it yourself.

- **Revoke Cards**, on the item's detail view - immediately revokes every code ever printed for this item. Nothing about the item itself changes; only its own printed codes stop verifying.

### The create/edit form

- **Name** (required), a rich-text **Description** (with an AI Assist button), **Rarity**, **Cost**, a rich-text **Limitations** (with an AI Assist button). For an item or location, **Who can see this** too - see the detail pane's own description above; it isn't offered for a rote.
- **Inside**, locations only - a dropdown of every other location in this chronicle. Leave it "Nowhere (top-level)" for a location that isn't inside anything else.
- One field per property the object type defines - a single-line box, a number box, a date box, a rich-text box (with its own AI Assist button, for a long-text property such as an item's Appearance or a rote's Description) or, for a trait-list property, a repeatable Name/Count/Note row with its own **Add** and **Remove**.
- **Links**, locations only, shown once the location already exists (not while first creating it) - who's the **Owner**, whose **Domain** it is, whose **Haven** it is, and who's **Based here**. Each link names a real character; **Add Link** appends one, **Remove** takes one off. Not the same as the older **Links** trait list among the properties above, which is free text Grapevine already wrote and is left exactly as it was.
- **Secrets**, items and locations only, shown once it already exists - a write-up kept separate from the item's or location's own text, with its own audience and reveals. See [Secrets](secrets.md).
- **Save Changes** (editing) or **Create** (new), and **Cancel**.

"No games exist yet - create one under Beyond Elysium → System Config → Games first." appears in place of everything above when no chronicle exists on the install at all.

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
3. The form opens pre-filled from the original, named "Copy of [original name]" - change whatever should differ.
4. Click **Create**. The original entry is never touched.

### Copy an item for a character

1. Open the item.
2. Click **Copy for a character**.
3. Choose the character, and optionally rename the copy.
4. Click **Copy**. It opens in the editor, connected to that character and visible only to them - the original item is never touched.

### Transfer an item to a new character

1. Open the item.
2. Click **Transfer**.
3. Pick how it changed hands and, unless it's **Lost**, the recipient.
4. Optionally add a note, then click **Transfer**.

### Give an item a limited number of uses or an expiry date

1. Open the item, then click **Edit**.
2. Set **Uses** to how many times it can be used (leave it blank for unlimited), or set **Expires** to a date.
3. Click **Save Changes**. Once **Uses** is set, **Uses Left** starts equal to it and counts down as the item is used.

### Restrict who can see an item or location

1. Open it, then click **Edit** (or set it while creating).
2. Under **Who can see this**, pick **Storytellers and Narrators only** or build a rule under **Only characters matching rules I set**.
3. Save. A character already connected to it (its holder, for instance) keeps seeing it either way.

### Attach a file to an item or location

1. Open it.
2. Under **Files**, click **Choose File** and pick an image or PDF (10 MB max; one file for an item, 20 for a location).
3. It appears in the list right away. Click **Remove** to take it back off.

### Connect an item or location to the character who holds it

1. Open the item or location.
2. Under **Connections**, follow [Connections](connections.md).

### Nest a location inside another, or set who owns it

1. Open the location, then click **Edit** (or set it while creating).
2. Under **Inside**, pick the location it sits inside, if any.
3. Once the location exists, use **Links** to name its Owner, Domain, Haven, or who's Based here - each one a real character.
4. Save.

## Things to know

- **A location's Owner and Where prefer a real link over typed text, but the typed text is never lost.** Grapevine's own Owner/Where fields keep round-tripping through import and export unchanged; a real Owner link or an Inside parent simply takes display precedence over them wherever one exists, here and on the Location Cards report alike.
- **A location can't be deleted while something is inside it.** Move or delete whatever's nested inside first.
- **A character connected through any location link always sees it**, the same rule that already applies to an item's holder - an Owner, Domain holder, Haven holder, or someone Based there sees the location regardless of its own audience setting.
- **Duplicate never touches the original.** It pre-fills a fresh create form from the entry you duplicated - nothing is saved until you click **Create**, and the source entry is unchanged either way.
- **Boons aren't managed here.** Items, Locations, and Rotes share this catalog; a boon is recorded, repaid, and kept only on the [Boon Ledger](boon-ledger.md).
- **Storyteller-only text is invisible to everyone else.** Any `[ST]...[/ST]` text in a description or limitations field never reaches a player, in the catalog list or anywhere else it appears - you always see it in full here, since only a Storyteller opens this page.
- **The same entry can be connected to many characters at once** - connecting one item to fifty characters is fifty ordinary connections to the same row, not fifty copies. When one character's copy needs to become unique (an heirloom, something that gets damaged or renamed), use **Copy for a character** instead.
- **Copy for a character is always restricted to its holder, and always keeps its own file.** The copy's **Who can see this** starts as **Only characters matching rules I set** no matter what the source's own setting is, and any file attached to the source is duplicated rather than shared - editing or removing the copy's file never touches the source's.
- **No "Mark a use" button exists yet.** Spending a use is meant to be something a player does for their own held item, but no player-facing surface for it has been built - today the only way to reduce **Uses Left** is to retype it by hand on **Edit**, which records as an ordinary edit (**Adjusted** on the item's **History**), not a real recorded use.
- On a narrow screen this catalog list stacks into cards instead of scrolling sideways.

## Troubleshooting

- **"Failed to load world objects."** Refresh and try again.
- **"No items/locations/rotes match these filters."** Clear the search box.
- **"Failed to save this world object."** Check that **Name** is filled in, then try again.
- **"Failed to load this item. Try again."** Shown in place of the edit or duplicate form when the entry couldn't be fetched - reload the page.
- **"Failed to upload this file."** Only images (JPEG, PNG, GIF, WebP) and PDFs are allowed, 10 MB max.
- **"This item already has the most files it may carry."** An item takes exactly one file - remove it first to attach a different one.
- **"Move or delete this location's own children first."** Shown when deleting a location that has another location nested inside it - move the nested one out (set its **Inside** to something else, or to nowhere) or delete it first.
- **"parent_id must be a real location in this game."** The **Inside** choice didn't resolve to a real location - refresh and try again.
- **"Failed to copy this item."** Try again. Only an item may be copied this way - a location or rote never shows the button.
- **"Failed to transfer this item."** Try again - make sure a recipient is chosen unless **Lost** is picked.
- **I don't see this menu item at all.** It needs a Storyteller role in at least one chronicle, or an administrator account.

## Related

- [Connections](connections.md)
- [Who's Who](whos-who.md)
- [Secrets](secrets.md)
- [Boon Ledger](boon-ledger.md)
- [Reports](reports.md)
- [Query Tool](query-tool.md)
- [Import](import.md)
- [AI Writing Assist](writing-assist.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Admin Guide - Who Can See a Plot, Item, or Location](../admin-guide.md#who-can-see-a-plot-item-or-location)
- [Admin Guide](../admin-guide.md#the-wp-admin-menu)
