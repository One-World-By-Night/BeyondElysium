# Storyteller Toolkit

The Storyteller-facing hub for a chronicle: pick which chronicle, then move between its Dashboard, My Queue, Approval Queue, Characters, Players, Plots & Rumors, Secrets, Boon Ledger, Items & Locations, Catalog, Game Nights, Releases, Downtime, Factions, Relationships, and Email Log tabs.

## Who can use this

The HST sees every tab, including Players on every chronicle. An AST sees the same tabs except Catalog, which the HST can give them on the Players tab. A Narrator sees My Queue, Plots & Rumors, Secrets, Game Nights, Releases, and Downtime - the tools tied to running plots and sessions, not sheet approval or the catalog. The chronicle's Harpy sees only Boon Ledger. A plain player sees none of these tabs - their own view of a chronicle is [My Chronicle](my-chronicle.md), not this page.

## How to get there

Look for a "Storyteller Toolkit" link in your site's navigation - this plugin creates the page automatically. If you can't find it, ask a Storyteller or your site admin where it's linked. On Beyond Elysium's own layout My Chronicle links to it at the top for anyone with a Storyteller role in the chronicle.

## The screen

- **Chronicle** - a dropdown listing every chronicle you belong to, each one labeled with your role there. Choosing a different one reloads the tabs below for your role in that chronicle specifically. If you don't belong to any chronicle, this says so instead of showing a dropdown.
- **Tabs**, each shown only when you hold the matching role in the chronicle currently selected:
  - **Dashboard** - chronicle-wide character, change, and plot stats. See
    [Game Dashboard](game-dashboard.md).
  - **My Queue** - this Storyteller's own pending work, across plots and changes. See
    [My Queue](my-queue.md).
  - **Approval Queue** - every pending sheet change, in one list. See
    [Approval Queue](approval-queue.md).
  - **Characters** - every character in the chronicle, with Apply XP, assigning a player, and **+ New Character** and **+ New NPC** to create one. See [Character Editor](character-editor.md).
  - **Players** - review join requests, invite or add an existing account as a player, or take one out (HST and AST, every chronicle; accessSchema roles follow on a linked one). See [Players](chronicle-players.md).
  - **Plots & Rumors** - create and run plots, allocate actions, generate rumors. See
    [Plots & Rumors](plot-manager.md).
  - **Secrets** - every secret in the chronicle in one searchable list, each showing what it is attached to and who has been told. See [Secrets](secrets.md).
  - **Boon Ledger** - the chronicle's Harpy view. See [Boon Ledger](boon-ledger.md).
  - **Items & Locations** - the chronicle's item and location catalog, and the connections between them and characters. See [Items & Locations](world-objects.md).
  - **Catalog** - add to and change what the chronicle's characters can buy: its own Disciplines, Gifts, Rituals, Merits and the rest, and the price and description of the book's entries (the HST, or an AST or Narrator the HST has given it to). See [Catalog](catalog.md).
  - **Game Nights** - sessions and attendance. See [Game Nights](game-nights.md).
  - **Releases** - scheduled and manual release batches for rumors and downtime results. See [Releases](release-batches.md).
  - **Downtime** - the downtime action queue. See [Downtime](downtime-queue.md).
  - **Factions** - factions and their court positions. See [Factions](factions.md).
  - **Relationships** - a chart of character-to-character relationships. See [Relationships](relationships.md).
  - **Email Log** - every email the chronicle sent, held for a digest or chose not to send, and why (HST and AST). See [Email Log](email-log.md).
- If none of these apply to your role in the chosen chronicle, the page says "You don't hold a Storyteller role in this chronicle." instead of showing any tabs.

The page keeps your chosen chronicle and tab in its web address, so refreshing or bookmarking it returns you to the same view.

## Beyond Elysium's own layout

Unless a site administrator has turned it off on [Branding](branding.md), this page shows Beyond Elysium's own layout in place of the theme's:

- **The header** names the site first, with its logo, as a link to the site's home page, then **Storyteller Toolkit**, the chronicle picker, a **My Chronicle** link, **Help**, and your name with **Sign out**.
- **The notice bell** sits in the header before **Help**, with a count of what you have not read. It opens a list: for a player, what a Storyteller did with their changes ("Alice: 2 approved, 1 refused", with each line and any note), a change a Storyteller edited, a change taken back with Undo, and XP awarded; for a Storyteller, "Alice sent 3 changes; 2 wait for you", linking to the [Approval Queue](approval-queue.md). Each notice links to where it belongs, **Mark all read** clears the count, and notices are kept for 90 days. A Storyteller also finds **Changes waiting for you** there, with the choices Each submission, Once a day and Never, the default being whatever the chronicle's staff email decides.
- **On a computer** every tab your role allows shows in one row under the header, wrapping onto a second row when they don't fit.
- **On a phone** a bar at the bottom of the screen holds **Home** (the Dashboard), **Approvals** (the Approval Queue), **Characters** and **Plots** (Plots & Rumors). A place your role doesn't have goes to your next tab instead, so a Narrator's bar holds Plots, My Queue, Secrets and Game Nights. **More** lists every other tab, the My Chronicle link, Help and signing out.
- **The footer** carries Powered by Beyond Elysium, the Dark Pack logo and Paradox's notice.

To keep the page on your phone's home screen, use your browser's **Add to Home Screen**. It opens in the browser, named after the site.

## Common tasks

### Create a character or an NPC

1. Open the **Characters** tab.
2. Click **+ New Character**, or **+ New NPC** to start with the NPC box ticked.
3. Fill in the [Character Editor](character-editor.md) and click **Create Character**. **Assign player** on the Characters tab gives the new character to a player afterward.

### Switch to a different chronicle

1. Open the **Chronicle** dropdown at the top of the page.
2. Pick the one you want. The tabs below reload for your role there.

### Jump straight to a tool

1. Click the tab you need - whichever ones are showing for your role in the selected chronicle.

## Things to know

- **Tabs are chronicle-specific.** Switching to a chronicle where you hold a narrower role hides tabs you had a moment ago; the page falls back to the first tab still available to you there.
- **This is the Storyteller-facing page.** Your own characters and your own plot feed live on [My Chronicle](my-chronicle.md) instead, even if you're also an HST or AST - a Storyteller opening My Chronicle's own Dashboard tab sees the same chronicle-wide view described in [Game Dashboard](game-dashboard.md), but every other My Chronicle tab is that Storyteller's own player-facing view.
- **A Narrator reaches five tabs here** - My Queue, Plots & Rumors, Game Nights, Releases, and Downtime - the same Plots & Rumors tools a Storyteller has (Allocate actions and Generate rumors included, not only posting responses and notes), but not Dashboard, Approval Queue, Players, Boon Ledger, Items & Locations, or Factions, and not Catalog unless the HST has given it to them.
- **The chronicle's Harpy sees only Boon Ledger here** - not Characters, Plots & Rumors, or the Approval Queue, even though a Harpy can look up characters to see who owes whom.

## Troubleshooting

- **"Sign in to see your chronicles."** You aren't signed in. Click **Sign in**, and you come back to this page.
- **"You don't hold a Storyteller role in this chronicle."** You're a plain player in the chronicle currently selected, or you're in the wrong chronicle - check the **Chronicle** dropdown, or ask an HST/AST to check your role.
- **"You don't belong to any chronicle yet."** You have no membership anywhere yet - ask a Storyteller for a join link.
- **A tab I expect isn't showing.** You hold a narrower role in the chronicle currently selected than you thought - switch chronicles, or ask an HST/AST to check your role there.
- **Nothing loads at all.** Make sure you're logged in, then refresh the page.

## Related

- [Game Dashboard](game-dashboard.md)
- [My Queue](my-queue.md)
- [Approval Queue](approval-queue.md)
- [Players](chronicle-players.md)
- [Plots & Rumors](plot-manager.md)
- [Boon Ledger](boon-ledger.md)
- [Items & Locations](world-objects.md)
- [Catalog](catalog.md)
- [Game Nights](game-nights.md)
- [Releases](release-batches.md)
- [Downtime](downtime-queue.md)
- [Factions](factions.md)
- [Relationships](relationships.md)
- [My Chronicle](my-chronicle.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#7-the-game-dashboard)
