# Reports

Twenty ready-made reports - rosters, history, cards, plot and action logs, statistics, and
more - each generated as a signed PDF on demand.

## Who can use this

Reaching **Beyond Elysium → Query Tool → Reports** in wp-admin needs the same site-wide
capability that opens the Query Tool: an HST, AST, or administrator account. Once the page is
open, it lists only the reports your role in the chosen chronicle can actually run - a
Storyteller sees all 20. A Narrator sees the plot, action, and rumor reports plus everything
open to any member. House Rules, the Game Calendar, and the item, location, and rote cards are
open to every chronicle member by rule, players included - but a player normally never opens
this wp-admin screen itself, since it needs a Storyteller- or admin-level account. In practice
a player reaches House Rules through a front-end page a Storyteller has set up (see
[House Rules](house-rules.md)), Item Cards through their own Character Sheet's "Print My
Items" button (see [Character Sheet](character-sheet.md)), and the Game Calendar, Location
Cards, and Rote Cards through My Chronicle's own **Reports** tab (see
[My Chronicle](my-chronicle.md)) - this wp-admin screen is where a Storyteller generates any
of the 20 as a signed PDF, not the only place a player can read the three that are already
open to them.

## How to get there

wp-admin sidebar → Beyond Elysium → Query Tool → **Reports** tab. The Query Tool itself lives
on the same page - see [Query Tool](query-tool.md).

## The screen

- **Game** - a dropdown listing every chronicle on the install.
- A table listing every one you're allowed to run: its title, its shape (`table`, `card`,
  `statistics`, `narrative`, or `calendar`), and a **Generate PDF** link.
- The **Statistics Report** row additionally shows a dropdown - **Merits**, **Flaws**,
  **Influences** - picked before you click **Generate PDF**, since that's the one report where
  the field to summarize isn't fixed.
- **Generate PDF** downloads the report as a PDF for the currently selected chronicle.

### The 20 reports

| Report | Who can run it |
| --- | --- |
| Character Roster | Storytellers |
| Sign-In Sheet | Storytellers |
| Experience History | Storytellers |
| Search Report | Storytellers |
| Statistics Report | Storytellers |
| Merits and Flaws Report | Storytellers |
| Influence Report | Storytellers |
| Vampire Status Report | Storytellers |
| Player Roster | Storytellers |
| Player Point History | Storytellers |
| Character Equipment | Storytellers |
| Item Cards | Every member |
| Location Cards | Every member |
| Rote Cards | Every member |
| Plot Report | Storytellers and Narrators |
| Master Action Report | Storytellers and Narrators |
| Master Rumor Report | Storytellers and Narrators |
| Action and Rumor Report | Storytellers and Narrators |
| Game Calendar | Every member |
| House Rules | Every member |

## Common tasks

### Generate a report

1. Open Beyond Elysium → Query Tool → Reports and pick the chronicle.
2. Find the one you want in the table.
3. Click **Generate PDF**.

### Run the Statistics Report on a specific field

1. Find **Statistics Report** in the table.
2. Pick **Merits**, **Flaws**, or **Influences** from the dropdown next to it.
3. Click **Generate PDF**.

## Things to know

- **Without a signing certificate, every one still prints** - stamped UNSIGNED on every page,
  with the file name ending `-unsigned.pdf`. See [Signed Sheets](signed-sheets.md).
- **Cards print several to a page.** Item, Location, and Rote Cards use the same card layout
  a player would print for their own items, generated for the whole catalog at once.
- **Item Cards can be scoped to one character.** That's the character sheet's own "Print My
  Items" button, not a control on this screen - see [Character Sheet](character-sheet.md).
- **Game Calendar always renders empty right now, on this screen or on My Chronicle.** Beyond
  Elysium doesn't yet model a chronicle's own game-date schedule, so this one is an honest
  placeholder everywhere it appears, not broken or guessed at.
- **House Rules has no Grapevine counterpart**, and is the only one that can also live on a
  front-end page instead of being generated on demand - see [House Rules](house-rules.md).
- **The action ones read the plot record as it actually happened.** A budget line's Total,
  Growth, and what's left Unused after every use appear on their own lines; when several
  players had posted to the same plot and a Storyteller answered one, it can't always tell
  whose post the reply was for, and says so ("Reply came after several actions") rather than
  guessing.
- **Storyteller-only text is stripped the same way it is everywhere else** - a
  `[ST]...[/ST]` passage in an item or location never reaches one a non-Storyteller can run.

## Troubleshooting

- **"You do not have permission to run this report."** Your role in this chronicle doesn't
  cover that one - check the table above for who can run it.
- **"Report not found."** The page's list is stale - reload it and try again.
- **"No games exist yet - create one under Beyond Elysium → System Config → Games first."** Only a
  site administrator can create one.
- **I don't see this menu item at all.** It needs the same account type that opens the Query
  Tool - a Storyteller role in at least one chronicle, or an administrator account.

## Related

- [Query Tool](query-tool.md)
- [My Chronicle](my-chronicle.md)
- [House Rules](house-rules.md)
- [Items & Locations](world-objects.md)
- [Character Sheet](character-sheet.md)
- [Signed Sheets](signed-sheets.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#12-reports-cards-and-batch-output)
