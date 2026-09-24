# Chronicle Setup

One checklist for setting up a chronicle. Every row is worked out from what the chronicle actually has, so a row goes green when you've done it and goes back if you undo it. Settings that live on this page open right under their row; everything else links to the page that does the job. You can come back and change any of it whenever you like.

## Who can use this

Staff only: a chronicle's **HST** or **AST**, or a site administrator. A player is refused, and the tab doesn't appear for an account that can't set a chronicle up. Your chronicle's **HST** can change **Creature types**, **New-character approval**, **Purchase lists**, **Sub-faction restrictions** and **Branding**; an AST sees those same rows greyed, and can still open the Grapevine link. **Plot features** and (on the demo chronicle only) **Delete demo chronicle** stay a site administrator's alone. Every other row is a plain link to a page gated by its own role. A Narrator, a chronicle's Harpy, and a player have no reason to be here - nothing on this screen belongs to them to fix.

## How to get there

wp-admin sidebar → Beyond Elysium → Chronicle Setup. This is the hub's first tab and opens by default. While a chronicle has no characters yet, a notice on your WordPress Dashboard, the Plugins page and Beyond Elysium's own screens points here; **Dismiss** hides it for you, for that chronicle only.

## The screen

- **Chronicle** - a dropdown listing every chronicle on the install. Picking one reloads the checklist for it, and the choice goes into the page address, so it stays put when you switch to Chronicle Access or Action & Rumor Settings, follow a **Go** link, or press Back.
- A summary line: how many rows are done, then how many need attention - "5 of 14 done. 3 items need attention." or "14 of 14 done. Nothing needs attention." The demo chronicle's own row isn't counted.
- The checklist - one row per thing to set up, each with a status pill, a title and detail line, and one button:
  - The pill is **Needs attention** (amber) for something a chronicle needs, **✓ Done** (green, and the whole row goes light green) once it's set, or **Info** (grey) for an optional row you haven't set.
  - **Go** leaves this page for the one that does the job. **Set up** (or **Change**, once the row is done) opens the row's controls right under it, and **Close** folds them again. A row that needs attention starts open; every other row starts folded, so a finished chronicle is a short list. Saving a row that needed attention turns it green and folds it.
  - **Creature types** - *Needs attention* until you've chosen which of the eleven creature types this chronicle offers when someone creates a character; every type is open by default. The controls are a checkbox per creature type and a **Save** button; at least one must stay checked. Saving all eleven counts as a choice.
  - **Storytellers** - *Needs attention* until at least one HST or AST is assigned. **Go** opens [Chronicle Access](chronicle-access.md).
  - **New-character approval** - *Needs attention* until you've chosen whether a player-created character starts pending or active: **Require approval** or **Active immediately**.
  - **Front-end pages** - *Needs attention* if any of the four provisioned pages (My Chronicle, Storyteller Toolkit, and the print/verify pages) is missing, naming which. **Go** re-runs page provisioning.
  - **Characters** - *Needs attention* until the chronicle has at least one character. **Go** opens the wp-admin Characters page for it.
  - **Approval rules** - *Done* once the chronicle has chosen a default approval policy or has a rule of its own; the detail line says how many rules there are and what happens to a change no rule covers. **Go** opens the [Approval Rules](approval-rules.md) tab.
  - **Catalog customisation** - *Done* once the chronicle has customised at least one schema block. **Go** opens the Schema Blocks tab.
  - **Sheet templates** - *Done* once the chronicle has overridden at least one template. **Go** opens the Templates tab.
  - **Downtime actions & rumors** - *Done* once the chronicle has saved any of its own Action & Rumor settings. **Go** opens [Action & Rumor Settings](apr-settings.md).
  - **Plot features** - off by default, and *Done* while the switch is on. On adds Faction Goals to a plot, the Arc/Subplot/Season/Episode categories when creating one, and an optional date on a timeline entry - extra structure most chronicles never need. One checkbox and no separate Save; a site administrator's change takes effect immediately.
  - **Branding** - *Done* once the chronicle has its own accent color, used for the Storyteller Toolkit's and My Chronicle's own chrome (highlights, primary buttons). Your chronicle's HST picks a color and it saves as soon as chosen, or clicks **Use site default** to remove the override and fall back to whatever the site administrator set in [Branding](branding.md). Any HST can set this, not only a site administrator - it changes nothing but this one chronicle's own look.
  - **Sub-faction restrictions** - *Done* once at least one catalog field has its own list of allowed values. It's one level finer than Creature types: within a creature type you've already enabled, narrow a real catalog field to only the values your chronicle runs - a Vampire Sect or Clan, a Werewolf Tribe, and any similarly shaped field on another type. Each such field gets a checkbox list of its real values and its own **Save** button; at least one value must stay checked. It takes a moment to open, because it lists every field it finds.
  - **Purchase lists** - three switches, all off by default: **Abilities**, **Backgrounds**, and **Merits and Flaws**. *Done* once at least one is on, and the detail line names which. Each creature type buys from its own lists. Turn a switch on and every creature type in this chronicle can buy from every creature type's entries for that area, priced from the list each entry comes from - a Vampire could take a Mage-only Ability, for instance. The lists themselves stay separate, and importing a Grapevine file into the chronicle matches names against the wider list too, so a Mage-only Ability in a Vampire's file comes in as a catalog entry rather than a custom one. The switches work in any combination (Abilities on and the rest off is fine), and each is all or nothing: you can't open a list to some creature types and not others. Each switch saves as soon as you check it.
  - **Players' Grapevine files** - a link players can use to send this chronicle a character's Grapevine file, with this chronicle already picked when they follow it. A **Copy link** button copies it. *Done* once a player has sent a file through it. See [Send a Grapevine File](send-grapevine-file.md).
  - **Demo chronicle** - shown only while the selected chronicle is the seeded demo. Always *Info*. A site administrator gets a **Delete demo chronicle** button here.

## Common tasks

### Check what's left to set up

1. Open Chronicle Setup and pick the chronicle from the dropdown.
2. Read the summary line, then down the list - anything marked *Needs attention* is unfinished, and it's already open so you can act on it.

### Limit which creature types are offered

1. Find the **Creature types** row. It's open while it needs attention; otherwise click **Change**.
2. Uncheck any type this chronicle doesn't run.
3. Click **Save**. The row turns green and folds.

### Require Storyteller approval for new characters

1. Find the **New-character approval** row.
2. Choose **Require approval**.

### Turn on the expanded plot features

1. Find **Plot features** and click **Set up**.
2. Check the box. It saves as soon as you check it.

### Restrict a sub-faction (for example, no Sabbat)

1. Find **Sub-faction restrictions** and click **Set up**.
2. Find the field you want to narrow (for example, Vampire's Sect field).
3. Uncheck the values you don't want offered.
4. Click that field's own **Save** button.

### Let players buy from every creature type's list

1. Find **Purchase lists** and click **Set up**.
2. Check the area you want to open: Abilities, Backgrounds, or Merits and Flaws. It saves as soon as you check it.
3. Players in this chronicle now see that area's full list, from every creature type, when they add a trait.

### Share the link for players to send their Grapevine files

1. Find **Players' Grapevine files** and click **Set up**.
2. Click **Copy link** and share it however you'd share any other link.

### Go back and change something you've finished

1. Click **Change** on a setting that lives on this page, or **Go** on a row that links to another page.
2. Make the change. The row stays green for as long as what it reads from is still there.

### Delete the demo chronicle

1. Pick the demo chronicle from the dropdown.
2. Find the **Demo chronicle** row and click **Delete demo chronicle**.
3. Confirm "Delete the demo chronicle and all 22 sample characters? This cannot be undone."

## Things to know

- **Nothing here is a one-time setup you complete and forget.** Every row is computed live from what actually exists - if your last AST leaves, the Storytellers row goes back to *Needs attention* on its own, the next time anyone opens this page.
- **Green means the chronicle has it, not that you ticked something.** A row reads what's really stored: a choice made, a customised block, an overridden template, a saved setting. Delete the template override and *Sheet templates* goes back to grey; nothing keeps a tick after the thing it stands for is gone.
- **Optional rows are grey until you set them, never amber.** Only Creature types, Storytellers, New-character approval, Front-end pages and Characters can ask for attention. Everything else works on Beyond Elysium's own defaults until you decide otherwise.
- **The chronicle you're working on follows you.** Its slug is in the page address, so Chronicle Access, Action & Rumor Settings and Approval Rules open on the same chronicle, and a **Go** link opens its page on the chronicle you were looking at.
- **Narrowing creature types or sub-factions never touches an existing character.** It only changes what a *new* character can be or pick - a character built before the restriction keeps its value and stays fully readable, editable, and approvable.
- **Absent or fully-checked always means "every option open,"** for both pickers - never "none." At least one creature type, and at least one value per restricted field, must stay checked.
- **Saving one sub-faction restriction never erases another.** A Vampire's Sect restriction and its Clan restriction save independently of each other.
- **Turning a purchase list off takes nothing away.** A character who already holds an entry from another creature type's list keeps it and can still change or remove it, but it can no longer be bought again, and the Point Audit can no longer price it from the catalog.
- **A purchase list only widens what can be bought.** Nothing is written to any catalog list, so switching one off leaves no trace, and the Schema Blocks screens still show each creature type's own list.
- **A Go link still takes you to the page it names, even if you can't do anything once you're there.** If you're not a site administrator, following Go to Chronicle Access won't show you anything new.
- **An AST sees the settings rows exactly as a player would - greyed, read-only.** Creature types, New-character approval, Purchase lists, Sub-faction restrictions and Branding are an HST's; Plot features is a site administrator's.

## Troubleshooting

- **"No chronicles exist yet - create one under Beyond Elysium → System Config → Games first."** Only a site administrator can create one.
- **A save shows an error.** The message says why - usually that the change needs your chronicle's HST, or (for Plot features and deleting the demo chronicle) a site administrator.
- **The checklist shows a row I can't act on.** Only your chronicle's HST can change Creature types, New-character approval, Purchase lists, Sub-faction restrictions or Branding; only a site administrator can change Plot features or delete the demo chronicle. Everyone else sees the same row, greyed, as information.
- **A row I finished isn't green.** A row goes green only when something is really stored for it. Approval rules needs a default policy chosen or a rule created on the Approval Rules tab; Downtime actions & rumors needs a saved setting on Action & Rumor Settings; Catalog customisation needs a customised block; Sheet templates needs an overridden template. Also check the Chronicle dropdown: the page opens on the chronicle named in its address, which may not be the one you worked on.
- **Sub-faction restrictions says none of my enabled creature types have a restrictable field.** Normal if the types you've enabled don't have a Clan/Sect/Tribe-shaped field in their catalog - there's nothing to narrow.
- **A Save button won't click.** You've unchecked every option in that list - at least one must stay checked.
- **A notice on my Dashboard says a chronicle isn't set up yet.** It appears for a chronicle with no characters and points here; **Dismiss** removes it for you. It goes away by itself once the chronicle has a character.

## Related

- [Chronicle Access](chronicle-access.md)
- [Send a Grapevine File](send-grapevine-file.md)
- [Action & Rumor Settings](apr-settings.md)
- [Branding](branding.md)
- [AI Assist Settings (Chronicle)](writing-assist-chronicle.md)
- [Games](games.md)
- [Admin Characters](admin-characters.md)
- [Roles](roles.md)
- [Chronicles](chronicles.md)
- [Storyteller Guide](../st-guide.md#chronicle-setup-whats-left-to-configure)
- [Admin Guide](../admin-guide.md#what-an-hst-can-and-cannot-do)
