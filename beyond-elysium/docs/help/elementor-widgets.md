# Elementor Widgets

Beyond Elysium adds twelve Elementor widgets, grouped in their own "Beyond Elysium" category, that place its front-end tools on any page you build - plus one shortcode for a page that isn't built with Elementor.

## Who can use this

Whoever builds pages on this site - normally a site admin or another WordPress Editor. Placing a widget uses Elementor's own page-editing permissions; Beyond Elysium doesn't add or change who may do that. Once a widget is on a page, what a *visitor* can do with it is a separate matter, decided when the page loads - see Things to know below.

## How to get there

Open any page in the Elementor editor. Every Beyond Elysium widget lives in its own **Beyond Elysium** category in the widget panel, alongside Elementor's own built-in ones.

## The screen

Every widget shares the same shape: drag it onto the page, then fill in its **Content** section in the panel on the left. Every one of them has a **Game Slug** field - the chronicle it shows - and most have nothing else.

| Widget | What it shows | Extra settings | Full doc |
| --- | --- | --- | --- |
| Character Sheet | A read-only, printable character sheet. | Character ID (0 reads `?character_id=` from the page's own address instead); Template Type (Full Sheet / Compact Sheet / Mobile Sheet) | [Character Sheet](character-sheet.md) |
| Character List | The character roster for this chronicle, with search and filtering. | Creature Type; Default Status Filter; Sheet Page URL (links each name to your Character Sheet page; left blank, names render as plain text); Per Page | [Characters](character-list.md) |
| Character Editor | The player/Storyteller-facing form for creating and editing a character sheet. | Character ID (0 reads `?character_id=`; still 0 after that opens create mode); Creature Stack Slug (pins create mode to one creature type; blank lets the player pick) | [Character Editor](character-editor.md) |
| Approval Queue | Pending player changes for Storytellers to approve or reject, across every character in the chronicle. | none | [Approval Queue](approval-queue.md) |
| Plot Manager | The Storyteller Toolkit for creating and running plots, actions, and rumors. | Default Status Filter (All / Active / Resolved / Archived) | [Plots & Rumors](plot-manager.md) |
| My Plots | A feed of the plots and rumors a player is connected to. | none | [My Plots & Rumors](my-plots.md) |
| Query Tool | Builds and runs saved queries against this chronicle, plus roster statistics. | none | [Query Tool](query-tool.md) |
| World Objects | Manages items, locations, rotes, and boons for the chronicle. | Default Type (Items / Locations / Rotes); Show Create/Edit Controls (hides the add/edit buttons from view) | [Items & Locations](world-objects.md) |
| Boon Ledger | A transactional ledger of boons owed and paid between characters. | Character ID (0 shows the whole chronicle's ledger; a specific ID scopes to that character) | [Boon Ledger](boon-ledger.md) |
| Import Tool | Imports a Grapevine character or game exchange file (.gex) into the chronicle. | none | [Import](import.md) |
| Game Dashboard | The landing page for a chronicle - Storytellers see aggregate stats and recent activity, players see their own characters, pending changes, and plots. | Sheet Page URL; Approval Queue Page URL; Roster Page URL; Plots Page URL (Storyteller quick links - blank omits the link) | [Game Dashboard](game-dashboard.md) |
| House Rules | Every house-rule note set on this chronicle's catalog. | none | [House Rules](house-rules.md) |

### `[be_house_rules]` shortcode

For a page that isn't built with Elementor. `[be_house_rules game="chronicle-slug"]` renders the same live House Rules view the widget does. It takes one attribute, `game` - the chronicle's slug - and has no settings panel, since it's plain text typed into the page or post content.

## Common tasks

### Add a chronicle's roster to a page

1. Open the page in the Elementor editor.
2. Drag **Character List**, from the **Beyond Elysium** category, onto the page.
3. Set **Game Slug** to that chronicle's slug.
4. Optionally set **Creature Type**, **Default Status Filter**, **Sheet Page URL**, and **Per Page**.

### Link a roster to a sheet page

1. Build a separate page with a **Character Sheet** widget on it, Character ID left at 0.
2. On the **Character List** widget, set **Sheet Page URL** to that page's address.
3. Character names in the list become links; clicking one opens that page with the right character already selected.

### Show a chronicle's House Rules without Elementor

1. Edit the page or post in the ordinary WordPress editor.
2. Add a Shortcode block (or type directly, in the Classic Editor) containing `[be_house_rules game="chronicle-slug"]`.

### Find a chronicle's slug

1. wp-admin → System Config → Games. See [Games](games.md).

## Things to know

- **Every widget needs a real Game Slug.** Get it wrong or leave it blank and the widget has nothing to show - find a chronicle's slug on [Games](games.md).
- **A widget is pinned to one chronicle at page-build time.** This differs from the plugin's own My Chronicle and Storyteller Toolkit pages, which let a visitor switch between every chronicle they belong to. To offer more than one chronicle through Elementor, build a separate page (or a separate section) per chronicle, each with its own widget instance and its own Game Slug.
- **Placing a widget doesn't grant anyone access.** Every widget's own routes enforce their own permission checks when the page loads, exactly as they do inside the plugin's own pages - putting Approval Queue on a page doesn't let a player use it, it just gives everyone a place to try. See each widget's own "Who can use this" for who sees what.
- **Character ID fields fall back to a URL parameter.** Character Sheet, Character Editor, and Boon Ledger all treat Character ID `0` as "read `?character_id=` from the page's own address instead" - useful when Sheet Page URL links to the same page for every character.
- **Character Editor's Character ID stays 0 with no URL parameter either, and that's create mode**, not an error - the widget then shows a blank form to start a new character.
- **World Objects' Show Create/Edit Controls is cosmetic only.** Turning it off hides the add/edit buttons from view - it doesn't and can't stop the underlying routes from checking who's allowed to write, so hiding it from a page a player might see is a courtesy, not a security measure.
- **These widgets don't replace the plugin's own four pages.** Beyond Elysium already creates My Chronicle, Storyteller Toolkit, Character Sheet (Print), and Verify Character automatically - use these widgets to add the same tools to a page of your own design, or to show one tool on a page by itself. See [Front-End Pages](provisioned-pages.md).

## Troubleshooting

- **A widget shows nothing, or an error.** Check Game Slug is a real chronicle's slug, spelled exactly as it appears on [Games](games.md).
- **A widget shows nothing useful for a real visitor.** That's the tool's own role check working as designed - see that widget's own help doc (linked in the table above) for who can use it and what a player sees instead.
- **Character names in Character List aren't links.** Sheet Page URL is blank - set it to the page carrying your Character Sheet widget.
- **Clicking a character's name doesn't show the right character.** Confirm the Character Sheet widget on the target page has Character ID set to 0, so it reads `?character_id=` from the address Character List builds - a nonzero Character ID there always shows that one character regardless of which name was clicked.
- **The `[be_house_rules]` shortcode shows nothing.** Check the `game="..."` attribute is a real chronicle's slug.

## Related

- [Character Sheet](character-sheet.md)
- [Characters](character-list.md)
- [Character Editor](character-editor.md)
- [Approval Queue](approval-queue.md)
- [Plots & Rumors](plot-manager.md)
- [My Plots & Rumors](my-plots.md)
- [Query Tool](query-tool.md)
- [Items & Locations](world-objects.md)
- [Boon Ledger](boon-ledger.md)
- [Import](import.md)
- [Game Dashboard](game-dashboard.md)
- [House Rules](house-rules.md)
- [Games](games.md)
- [Front-End Pages](provisioned-pages.md)
- [Admin Dashboard](admin-dashboard.md)
