# Query Tool

Build a filtered search across a chronicle's characters, items, locations, or rotes, see the matches, run statistics over them, and save a query to reuse later.

## Who can use this

Storytellers (HST and AST) only. The Query Tool reads whole character sheets - Storyteller-only blocks included - so a Narrator does not hold the capability this tool needs, even though Narrators work elsewhere in the Storyteller Toolkit. Reaching **Beyond Elysium → Query Tool** in wp-admin at all needs the same site-wide capability an HST, AST, or administrator account already carries; a Narrator's account may still be able to open the page, but every request it makes - loading fields, running a query, loading saved queries - fails. A player never sees this menu item.

## How to get there

wp-admin sidebar → Beyond Elysium → Query Tool → **Query Tool** tab. Reports live on the same page - see [Reports](reports.md).

## The screen

- **Game** - a dropdown listing every chronicle on the install.
- Three tabs: **Search**, **Statistics**, **Saved Queries**.
- An inventory strip below the tabs: **Characters**, **Items**, **Locations**, **Rotes**. Switching inventory clears whatever clauses, results, and statistics were on screen - a clause built against one inventory's fields has no meaning against another's.

### Search tab

- **Search fields to narrow the pickers below…** - filters the field dropdown in every clause by name.
- **Match ALL clauses (AND)** / **Match ANY clause (OR)** - the logic joining every clause.
- One row per clause: a field dropdown, an operator dropdown (narrowed to what that field's type supports), a value box (a name, a number, or a date, depending on the field and operator), a **NOT** checkbox, and a plain-language description of what the clause means. **✕** removes a clause; **+ Add clause** adds another.
- **Run Query** - runs the search. Disabled until at least one clause exists.
- **Save as…** - a name box, and **Save** to save the current inventory, logic, and clauses as a named query.
- Results below: a sortable table (columns depend on the inventory - Characters shows Name, Type, Status; Items shows Name, Item Type, Level; Locations shows Name, Location Type, Level; Rotes shows Name, Level, Duration), a **Match Reason** column explaining why each row matched, **Export CSV**, and **Previous** / **Next** pagination.
- On a **Characters** result only, once you've checked one or more rows: a bulk-award form (XP amount, a required reason, **Award XP to selected**), a pool-reset form (pick a resource pool block and pool, **Reset temporary to permanent**), and a status form (pick a status, **Set status for selected**).

### Statistics tab

- A field dropdown, a statistic-type dropdown (**Distribution**, **Distinct Trait Distribution**, **Specific Trait Distribution**, **Maxima**, **Sums**), a trait-name box (Specific Trait Distribution only), an **Include zero/none** checkbox, and **Run**.
- The result: a total and the largest bucket's size, then a bar per bucket sized to the largest one. Clicking a bar expands it to list the character names behind it.

### Saved Queries tab

- Every query saved for this chronicle, each showing its name and which inventory it searches. A query saved automatically as your own most recent search shows **(auto)** - there's only ever one of those per person, per chronicle.
- **Load** - switches to the Search tab with that query's inventory, logic, and clauses restored.
- **Rename** - a plain prompt for a new name. Not offered on an automatic recent-search entry.
- **Delete** - removes it.

## Common tasks

### Build and run a query

1. Open Beyond Elysium → Query Tool and pick the chronicle.
2. Pick an inventory from the strip below the tabs.
3. Click **+ Add clause**, pick a field, an operator, and a value. Add more clauses if you need them, and pick **Match ALL clauses (AND)** or **Match ANY clause (OR)**.
4. Click **Run Query**.

### Save a query for later

1. Build and run a query as above.
2. Type a name in **Save as…**.
3. Click **Save**.

### Reuse a saved query

1. Open the **Saved Queries** tab.
2. Click **Load** on the one you want.

### Run a statistic

1. Open the **Statistics** tab.
2. Pick a field and a statistic type.
3. Click **Run**.
4. Click a bar to see the names behind that bucket.

### Award XP, reset a pool, or set status for a group of characters

1. Run a **Characters** query that returns the group you want.
2. Check each character's row.
3. Fill in the bulk-award, pool-reset, or status form that appears below the results, and click its button.

## Things to know

- **This tool is a Storyteller's.** Everything it returns - including a Storyteller-only block's stored values - is filtered out for anyone who isn't a Storyteller of this chronicle before a single clause is evaluated, and an NPC never appears in results for anyone else either. In practice this only matters if a request somehow reaches the engine outside the normal permission check, since the page itself is Storyteller-only.
- **Switching inventory starts over.** Conditions, results, and any statistics result are cleared the moment you pick a different inventory - nothing carries across by accident.
- **Bulk actions work on Characters results only.** Items, Locations, and Rotes rows aren't characters, so awarding XP, resetting a pool, or setting a status never appears for them.
- **A bad character ID in a selection is skipped, never guessed at.** If a row's character no longer exists, or belongs to a different chronicle, it's reported as failed rather than silently touched or silently dropped.
- **Export CSV covers only the page you're looking at**, not the whole result set - page through and export each page if you need everything.
- **A named saved query is shared** - every Storyteller in the chronicle can load it, but only its creator or a Storyteller can rename or delete it. Your own "most recent search" entry is yours alone; nobody else sees it in their own Saved Queries list.
- This is desk work best done at a keyboard, but results still read on a phone - when there's no room for the columns, each result becomes a card instead of scrolling sideways.

## Troubleshooting

- **"Failed to load the field list. Try refreshing the page."** Refresh the page.
- **"Failed to load saved queries."** Refresh and try again.
- **Run Query won't click.** Add at least one clause first.
- **A query shows an error instead of results.** The message says what the server refused - a field the chosen inventory doesn't have, say, or a clause missing its value. "Failed to run this query." means the request itself failed; try again.
- **I see the page but every action fails.** You likely don't hold a Storyteller role (HST or AST) in the chronicle you picked - a Narrator's account can sometimes still open this page, but the Query Tool itself needs Storyteller standing. Check the chronicle you've selected, or ask an HST/AST.
- **I don't see this menu item at all.** It needs a Storyteller role in at least one chronicle, or an administrator account.

## Related

- [Reports](reports.md)
- [Items & Locations](world-objects.md)
- [Roles](roles.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Storyteller Guide](../st-guide.md#10-building-and-running-queries)
