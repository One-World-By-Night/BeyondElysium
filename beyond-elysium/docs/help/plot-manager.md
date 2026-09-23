# Plots & Rumors

Where a chronicle's plots live: create one, read and respond to what players post, allocate game-night actions, and generate rumors.

## Who can use this

Storytellers (HST and AST) and Narrators. A player never sees this screen - their own connected plots and rumors show on [My Plots & Rumors](my-plots.md) instead. The chronicle's Harpy doesn't see this tab either, unless they also hold a Storyteller or Narrator role.

## How to get there

Storyteller Toolkit → Plots & Rumors tab.

## The screen

This tab has two views: an overview grid of every plot, and a single plot's own detail view.

### Plot overview (the default view)

- **Allocate actions** / **Generate rumors** - open the matching tool in a panel over this screen. See [Allocate Actions](allocate-actions.md) and [Rumors](rumors.md).
- **+ New plot** - opens a form: a title (required), a rich-text description (with an AI Assist button), and an optional cover image. If your chronicle has turned on [Plot Features](chronicle-setup.md), a **Category** dropdown also appears - **Ordinary plot** (the default) or **arc**, **subplot**, **season**, **episode**; choosing subplot or episode adds a required parent-plot dropdown, since those two only make sense nested under something. **Create plot** saves it; **Cancel** closes the form without saving.
- Filters: **Status** (all, or `active`, `resolved`, `archived`), an initiator filter (defaults to showing both; narrow to `player` or `st`), a character filter (**All plots**, **Character plots only** - each character's own plot and its action rounds - or **Without character plots**), and a text search across title and description. Changing any of them starts again at page 1.
- A grid of plot cards - cover image (or a blank placeholder), title, and badges for status, category (if Plot Features is on and one was set), and whether it's player- or Storyteller-initiated. Click a card to open it.
- **Previous** / **Next**, 24 plots per page, with the current page and the total count.

### A single plot (detail view)

- **All plots** - a link back to the grid.
- Cover image, with **Add a cover image…** / **Change cover…** for you to set or replace it.
- Title, then a status badge, whether it's player- or Storyteller-initiated, and a game date if the plot has one.
- **Overview** - the plot's prose. **Edit overview** opens a rich-text box (with AI Assist); **Save** or **Cancel**.
- **ST notes** - a rich-text box (with AI Assist) always available to you and to other Storytellers and Narrators, never to a player even one who can otherwise read the plot. Write, then **Save ST notes** - empty until someone writes something, same as Cliffhanger.
- **Who can see this** - **Everyone in the chronicle**, **Storytellers and Narrators only** (the default for a new plot), or **Only characters matching rules I set**, the last opening a query builder against your characters with a live count of who currently matches. Pick, then **Save audience**. A player's own plot shows the same control, but only a Storyteller can actually change it - the owner never widens their own.
- **Files** - any images or PDFs attached to this plot, up to 20, 10 MB each, with a download link per file. You, or a player plot's own owner, get an upload box and a **Remove** button per file; everyone else who can see the plot only sees the list.
- **Faction goals** - shown only when [Plot Features](chronicle-setup.md) is on, and only to managers. A repeatable list of faction, what they want, and optional key NPCs; **+ Add faction goal** adds a blank row, **Remove** drops one, **Save goals** persists the list. Never shown to a player.
- **Under this plot** - any plots nested under this one (an allocated action, a rumor, or another plot manually nested), each a clickable card opening the same way.
- **Timeline** - every entry so far, oldest first: its type (`action`, `response`, `note`, or `resolution`), a badge if it's **Private** or **Directed** (nothing shown for an ordinary public entry), its date, and its content. An entry created by Allocate actions or a background use renders as a short summary of what was spent and the result, rather than raw text. Below it, a form with a rich-text box lets you post a new entry - a manager can pick `response`, `note`, `resolution`, or `action`; a player, on their own feed, can only ever post `action`. With Plot Features on, this form also offers an optional timeline date, separate from when the entry was actually posted. Every entry also has its own **Who can see this entry** choice: you get **Public**, **Storytellers and Narrators only**, or **Directed to specific characters** (pick from whoever can currently see the plot); a player composing their own entry only ever sees the first two, and the entry's own author always sees it regardless of choice.
- **Cliffhanger** - a short rich-text box ("What's left unresolved…", with AI Assist) and its own **Save cliffhanger** button.
- **Secrets** - a title and write-up you keep separate from the plot's own text, with its own **Who can see this** and a **Reveal** to any character (optionally held for a release batch). A player sees only secrets they've been revealed, under **What You Know** - see [Secrets](secrets.md).
- Action bar: **+ Rumor**, **+ Action**, **Connect character**, and **Mark resolved** (reads **Resolved** once it's been clicked).

## Common tasks

### Create a plot

1. Open Storyteller Toolkit → Plots & Rumors.
2. Click **+ New plot**.
3. Type a title and, if you want, a description.
4. Optionally add a cover image.
5. Click **Create plot**.

### Respond to a player's action

1. Open the plot the action was posted to.
2. Read it under **Timeline**.
3. Pick `response` from the entry-type dropdown, write your reply, and click **Post**.

### Add a note only Storytellers and Narrators can see

1. Open the plot.
2. Pick `note` from the entry-type dropdown.
3. Write it and click **Post**.

### Mark a plot resolved

1. Open the plot.
2. Click **Mark resolved**.

### Connect a character to a plot

1. Open the plot.
2. Click **Connect character**. See [Connections](connections.md).

### Find a character's plot, or leave them all out

1. Pick **Character plots only** in the character filter to see each character's own plot and its action rounds, or **Without character plots** to see the chronicle's other plots.
2. Type the character's name in the search box to go straight to theirs.

### Send a reply only one player sees

1. Open the plot.
2. Write your reply, pick **Storytellers and Narrators only** from the entry's own audience dropdown, and click **Post**. Only you, other managers, and the entry's own author can read it.

### Direct a message to specific characters only

1. Open the plot.
2. Write your reply and pick **Directed to specific characters**.
3. Check off who should see it from the list (only characters who can currently see this plot appear) and click **Post**.

### Change who can see a plot

1. Open the plot.
2. Under **Who can see this**, pick a new option - for **Only characters matching rules I set**, build at least one complete clause first.
3. Click **Save audience**.

### Attach a file to a plot

1. Open the plot.
2. Under **Files**, click **Choose File** and pick an image or PDF (10 MB max, up to 20 files per plot).
3. It appears in the list right away, with a download link. Click **Remove** to take it back off.

### Nest an action or a rumor under a plot

1. Open the plot you want it under.
2. Click **+ Action** or **+ Rumor** in the action bar. The plot you had open is already picked as the parent. See [Allocate Actions](allocate-actions.md) or [Rumors](rumors.md).

## Things to know

- **Plot Features is off by default.** An HST or AST turns it on for the whole chronicle in [Chronicle Setup](chronicle-setup.md). Off, this screen looks exactly as it always has - no category picker, no Faction Goals section, no timeline date field. Most chronicles never need it; it exists for the few that want the extra structure.
- **Search and the three filters only narrow the grid you're looking at** - status, initiator, character plots, and title/description text. They don't reach into a plot's entries or its notes.
- **Every character has its own plot**, named `<Character> [id] Plot` and made with the character, PC or NPC. Each game date's actions for that character sit under it, and its title follows the character's name until you retitle it. Only you, other managers, and the character's player see it, and it can't be deleted on its own - it goes when the character does.
- **An action-allocation plot's own entries are locked.** The plain entry form refuses anything shaped like an Allocate actions or Background Uses entry - those are only ever created or changed through their own tools. See [Allocate Actions](allocate-actions.md) and [Background Uses](background-uses.md).
- **A player's own action stays editable only until you respond.** Once a `response` entry exists after it, the action is part of the record and locks.
- **Only you and other managers can see or open someone else's action-allocation plot.** A player only ever sees their own on [My Plots & Rumors](my-plots.md).
- **A plot created from this screen always counts as Storyteller-initiated**, even one built from a Narrator's own idea.
- **Closing the Allocate actions or Rumors panel reloads what you were looking at**, so anything you just committed appears right away.
- **A plot or entry can be held for a later release** instead of going out the moment you write it - see [Releases](release-batches.md). A held plot or entry carries a "Draft" or "In a release batch" badge here until its batch releases; you and other managers always see it regardless, exactly as normal.
- On a narrow screen this grid never scrolls sideways - it's already a stacking card layout.

## Troubleshooting

- **"Failed to load plots."** Refresh and try again.
- **"No plots match these filters."** Clear a filter or the search box.
- **"Failed to create this plot."** A title is required - check the field and try again.
- **"Failed to save."** Retry saving the overview or cliffhanger. If it keeps failing, reload the plot.
- **"Failed to add this entry."** Try posting again. If it keeps failing, the plot may no longer exist. Directing a post requires at least one character checked.
- **"Failed to upload this file."** Only images (JPEG, PNG, GIF, WebP) and PDFs are allowed, 10 MB max - check the file and try again.
- **"This plot already has the most files it may carry."** Remove one first - 20 for a plot, one for an item.
- **I don't see this tab at all.** You don't hold a Storyteller or Narrator role in the chronicle currently selected - switch chronicles, or ask an HST/AST to check your role.

## Related

- [Chronicle Setup](chronicle-setup.md) - turn Plot Features on or off
- [My Plots & Rumors](my-plots.md)
- [Allocate Actions](allocate-actions.md)
- [Rumors](rumors.md)
- [Connections](connections.md)
- [Background Uses](background-uses.md)
- [Releases](release-batches.md)
- [Downtime](downtime-queue.md)
- [Storyteller Toolkit](storyteller-toolkit.md)
- [Roles](roles.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Admin Guide - Who Can See a Plot, Item, or Location](../admin-guide.md#who-can-see-a-plot-item-or-location)
- [Storyteller Guide](../st-guide.md#8-plots-actions-and-rumors)
