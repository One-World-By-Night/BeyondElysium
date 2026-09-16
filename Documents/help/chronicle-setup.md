# Chronicle Setup

A live checklist for one chronicle: what still needs doing, plus a handful of settings you can
fix right from the list - which creature types are open to new characters, whether a new
character needs Storyteller approval, and which values of a catalog field like Clan or Tribe
your chronicle actually allows.

## Who can use this

Storytellers (HST and AST) use this screen to see what's left to configure and to link
onward to the pages that fix it. The page itself only checks that you can view characters at
all - the same wide check the [Admin Dashboard](admin-dashboard.md) uses - so anyone signed
in to the site can technically open it and pick any chronicle to see its status. Of the
checklist's controls, your chronicle's **HST** can change **Creature types**,
**New-character approval**, and **Sub-Faction Restrictions** below them - an AST sees those
same rows, greyed, same as anyone else. **Plot Features** and (on the demo chronicle only)
**Delete demo chronicle** stay a site administrator's alone. Every other row is a plain link
to a page gated by its own role. A Narrator, a chronicle's Harpy, and a player have no reason
to be here - nothing on this screen belongs to them to fix.

## How to get there

wp-admin sidebar → Beyond Elysium → Chronicle Setup. This is the hub's first tab and opens by
default.

## The screen

- **Chronicle** - a dropdown listing every chronicle on the install. Picking one reloads the
  checklist and Sub-Faction Restrictions below for it.
- A summary line: "N item(s) need attention." or "Nothing needs attention."
- The checklist - a table with a status pill, a title and detail line, and either an inline
  control or a **Go** button per row:
  - **Creature types** - *Needs attention* until you've narrowed which of the eleven creature
    types this chronicle offers when someone creates a character; every type is open by
    default. Your chronicle's HST gets a checkbox per creature type and a **Save** button
    right here; at least one type must stay checked.
  - **Storytellers** - *Needs attention* until at least one HST or AST is assigned. **Go**
    opens [Chronicle Access](chronicle-access.md).
  - **New-character approval** - *Needs attention* until you've chosen whether a
    player-created character starts pending or active. Your chronicle's HST gets two radio
    buttons here: **Require approval** or **Active immediately**.
  - **Front-end pages** - *Needs attention* if any of the four provisioned pages (My
    Chronicle, Storyteller Toolkit, and the print/verify pages) is missing, naming which.
    **Go** re-runs page provisioning.
  - **Characters** - *Needs attention* until the chronicle has at least one character. **Go**
    opens the wp-admin Characters page for it.
  - **Approval rules**, **Catalog customisation**, and **Sheet templates** - always shown as
    *Info*, reporting whether the chronicle has added any rules, forked any schema blocks, or
    overridden any templates, or is still using Beyond Elysium's own defaults for each. **Go**
    opens the matching System Config tab.
  - **Downtime actions & rumors** - always *Info*, reporting whether the chronicle has its own
    Action & Rumor settings or is using the defaults. **Go** opens
    [Action & Rumor Settings](apr-settings.md).
  - **Demo chronicle** - shown only while the selected chronicle is the seeded demo. Always
    *Info*. A site administrator gets a **Delete demo chronicle** button here.
- **Plot Features** - beneath the checklist, off by default. On adds Faction Goals to a
  plot, the Arc/Subplot/Season/Episode categories when creating one, and an optional date on
  a timeline entry - extra structure most chronicles never need. One checkbox and no
  separate Save; a site administrator's change takes effect immediately. Anyone who can't
  change it reads "A site administrator sets this." instead.
- **Sub-Faction Restrictions** - beneath that, one level finer than the Creature
  types row above: within a creature type you've already enabled, narrow a real catalog field
  to only the values your chronicle runs - a Vampire Sect or Clan, a Werewolf Tribe, and any
  similarly shaped field on another type. For each such field found on an enabled type, a
  checkbox list of its real values and its own **Save** button; at least one value must stay
  checked. If none of the enabled creature types has a field like this, the section says so
  instead. Anyone who isn't the chronicle's HST reads "Your chronicle's HST sets these." in
  place of the lists.
- **Players' Grapevine Files** - a read-only link players can use to send this chronicle a
  character's Grapevine file, with this chronicle already picked when they follow it. A
  **Copy link** button copies it. See [Send a Grapevine File](send-grapevine-file.md).

## Common tasks

### Check what's left to set up

1. Open Chronicle Setup and pick the chronicle from the dropdown.
2. Read down the checklist - anything marked *Needs attention* is unfinished.

### Limit which creature types are offered

1. Find the **Creature types** row.
2. Uncheck any type this chronicle doesn't run.
3. Click **Save**.

### Require Storyteller approval for new characters

1. Find the **New-character approval** row.
2. Choose **Require approval**.

### Turn on the expanded plot features

1. Find **Plot Features**.
2. Check the box. It saves as soon as you check it.

### Restrict a sub-faction (for example, no Sabbat)

1. Scroll to **Sub-Faction Restrictions**.
2. Find the field you want to narrow (for example, Vampire's Sect field).
3. Uncheck the values you don't want offered.
4. Click that field's own **Save** button.

### Share the link for players to send their Grapevine files

1. Find **Players' Grapevine Files**.
2. Click **Copy link** and share it however you'd share any other link.

### Delete the demo chronicle

1. Pick the demo chronicle from the dropdown.
2. Find the **Demo chronicle** row and click **Delete demo chronicle**.
3. Confirm "Delete the demo chronicle and all 22 sample characters? This cannot be undone."

## Things to know

- **Nothing here is a one-time setup you complete and forget.** Every row is computed live
  from what actually exists - if your last AST leaves, the Storytellers row goes back to
  *Needs attention* on its own, the next time anyone opens this page.
- **Narrowing creature types or sub-factions never touches an existing character.** It only
  changes what a *new* character can be or pick - a character built before the restriction
  keeps its value and stays fully readable, editable, and approvable.
- **Absent or fully-checked always means "every option open,"** for both pickers - never
  "none." At least one creature type, and at least one value per restricted field, must stay
  checked.
- **Saving one sub-faction restriction never erases another.** A Vampire's Sect restriction
  and its Clan restriction save independently of each other.
- **A Go link still takes you to the page it names, even if you can't do anything once you're
  there.** If you're not a site administrator, following Go to Chronicle Access won't show
  you anything new.
- **An AST sees Creature types, New-character approval, and Sub-Faction Restrictions exactly
  as a player would - greyed, read-only.** Those three became an HST's alone, not a site
  administrator's alone, but an AST was never included either way.

## Troubleshooting

- **"No chronicles exist yet - create one under Beyond Elysium → System Config → Games
  first."** Only a site administrator can create one.
- **A save shows an error.** The message says why - usually that the change needs your
  chronicle's HST, or (for Plot Features and deleting the demo chronicle) a site
  administrator.
- **The checklist shows a row I can't act on.** Only your chronicle's HST can change Creature
  types, New-character approval, or Sub-Faction Restrictions; only a site administrator can
  change Plot Features or delete the demo chronicle. Everyone else sees the same row, greyed,
  as information.
- **Sub-Faction Restrictions says none of my enabled creature types have a restrictable
  field.** Normal if the types you've enabled don't have a Clan/Sect/Tribe-shaped field in
  their catalog - there's nothing to narrow.
- **A Save button won't click.** You've unchecked every option in that list - at least one
  must stay checked.

## Related

- [Chronicle Access](chronicle-access.md)
- [Send a Grapevine File](send-grapevine-file.md)
- [Action & Rumor Settings](apr-settings.md)
- [AI Assist Settings (Chronicle)](writing-assist-chronicle.md)
- [Games](games.md)
- [Admin Characters](admin-characters.md)
- [Roles](roles.md)
- [Chronicles](chronicles.md)
- [Storyteller Guide](../st-guide.md#chronicle-setup-whats-left-to-configure)
- [Admin Guide](../admin-guide.md#what-an-hst-can-and-cannot-do)
