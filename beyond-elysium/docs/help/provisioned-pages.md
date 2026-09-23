# Front-End Pages

The four pages Beyond Elysium creates automatically the first time it runs: My Chronicle, Storyteller Toolkit, Character Sheet (Print), and Verify Character.

## Who can use this

Reading any of the four pages is unrestricted, the same as any other WordPress page - each page's own front-end tool checks who can do what once it loads, not the page itself. Recreating a missing page needs a site admin: the Chronicle Setup checklist's **Front-end pages** row is visible to any Storyteller who opens [Chronicle Setup](chronicle-setup.md), but its fix only takes effect for the site's Administrator role.

## How to get there

wp-admin → **Pages** lists all four alongside your site's ordinary content, each with its own fixed slug:

| Page | Slug |
| --- | --- |
| My Chronicle | `be-player` |
| Storyteller Toolkit | `be-storyteller` |
| Character Sheet (Print) | `character-sheet-print` |
| Verify Character | `be-verify` |

To check whether all four still exist, or recreate one that's missing: wp-admin → Beyond Elysium → Chronicle Setup → Chronicle Setup tab → the **Front-end pages** row.

## The screen

These aren't Beyond Elysium screens of their own - each is an ordinary WordPress page holding one line of content: a single mount point for one of the plugin's front-end tools.

- **My Chronicle** (`be-player`) and **Storyteller Toolkit** (`be-storyteller`) each carry a chronicle-switching, tabbed tool. See [My Chronicle](my-chronicle.md) and [Storyteller Toolkit](storyteller-toolkit.md).
- **Character Sheet (Print)** (`character-sheet-print`) renders a single character's sheet with none of your theme's header, footer, or admin bar around it. Visited with a character and chronicle named in its own address and `print=1` added, it opens the browser's print dialog automatically once the sheet has loaded - a plain browser print, separate from the signed PDF the Sheet screen's own **Print / Export** action opens directly. See [Print / Export](sheet-print-export.md).
- **Verify Character** (`be-verify`) is the public page a verification code's own link points at. See [Verify Character](verify.md).

Every one of the four is created once - the first time this plugin runs, or the first time it upgrades past a version that didn't have one yet - and is never overwritten or re-created once a page exists at that slug.

## Common tasks

### Find where a page is linked, or add one to your menu

1. wp-admin → **Pages**, and look for **My Chronicle**, **Storyteller Toolkit**, **Character Sheet (Print)**, or **Verify Character**.
2. Add whichever ones your visitors need to your site's navigation menu - the plugin creates the pages but doesn't add any of them to a menu itself.

### Recreate a page that was deleted

1. wp-admin → Beyond Elysium → Chronicle Setup → Chronicle Setup tab.
2. Find the **Front-end pages** row - it reads "Needs attention" and names which page is missing.
3. Click **Go**.

### Rename a page without breaking it

1. Change the page's **Title** freely, in wp-admin → Pages.
2. Leave its **Slug** exactly as it was created - the plugin's own links and generated codes point at the fixed slugs in the table above, not the title or menu position.

## Things to know

- **A page is only created if nothing already exists at that slug.** If your site already has its own page at, say, `be-verify`, from before this plugin was installed, Beyond Elysium leaves it alone rather than overwriting it - which also means Verify Character won't work until that slug is free or the conflicting page is moved.
- **None of the four bake in a specific chronicle.** My Chronicle and Storyteller Toolkit read whichever chronicle a visitor picks from their own dropdown; Character Sheet (Print) and Verify Character read the character, chronicle, or code straight from the page's own web address. You'll never need a separate copy of any of these four per chronicle.
- **The slug shown above is the page's identity to the plugin**, visible as its post name in wp-admin → Pages. With the common "Post name" permalink setting and no parent page, that's also the page's front-end address (for example, `/be-player/`) - a different permalink structure, or moving the page under a parent in your page hierarchy, changes the address without changing the slug the plugin looks for.
- **Character Sheet (Print) has no actions or navigation on it at all, by design** - that's the point, so nothing but the sheet itself shows up when it prints.
- **Deleting one of these pages doesn't delete anything it displays.** It only takes down that entry point - characters, chronicles, and verification codes are untouched, and the Chronicle Setup checklist flags the page as missing until it's recreated.
- **Renaming a chronicle doesn't touch these pages at all** - none of the four have a chronicle's slug built into their own address, only into the links visitors follow to reach a specific chronicle once inside them.

## Troubleshooting

- **A page I need isn't in wp-admin → Pages at all.** It may not have been created yet, if this install predates the page it's looking for - open Chronicle Setup and use the **Front-end pages** row's **Go** button to create it.
- **Verify Character (or Character Sheet Print) shows nothing at that address.** Something else already occupies that exact slug - look for another page at `be-verify` or `character-sheet-print` and move it if you need Beyond Elysium's own page there instead.
- **I moved or renamed a page and now a link from inside the plugin is broken.** Renaming the page's title is safe; changing its slug isn't - see Things to know above.
- **The Front-end pages row on Chronicle Setup still says something's missing after I clicked Go.** Reload the checklist - it re-checks live on every load, so a stale view can still show the old status for a moment.

## Related

- [My Chronicle](my-chronicle.md)
- [Storyteller Toolkit](storyteller-toolkit.md)
- [Verify Character](verify.md)
- [Print / Export](sheet-print-export.md)
- [Chronicle Setup](chronicle-setup.md)
- [Elementor Widgets](elementor-widgets.md)
- [Games](games.md)
