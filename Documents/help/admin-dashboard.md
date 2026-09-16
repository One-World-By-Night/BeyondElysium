# Admin Dashboard

The landing page for Beyond Elysium's wp-admin menu: what each of the other pages does,
where the front-end pages and Elementor widgets live, and a nudge to create your first
chronicle if none exists yet.

## Who can use this

Anyone who can reach wp-admin. This page only checks that you can view characters at all - a
capability every core WordPress role holds by default, including a plain Subscriber - so
it isn't gated the way the pages it describes are. In practice, only Storytellers and a site
admin have a reason to be here: a plain player sees the "Beyond Elysium" menu item and this
page, but not most of the rows it links to, since each of those needs its own narrower role.

## How to get there

wp-admin sidebar → **Beyond Elysium**. The top-level click itself lands here - it's also
listed a second time underneath as **Dashboard**, the first row.

## The screen

- A short description of the plugin.
- **Create your first chronicle** - shown only while every chronicle on the install is still
  the seeded demo one. Links to **Go to System Config → Games**.
- **What's where** - a table naming each of the other menu pages (Characters, Plots, Items &
  Locations, Query Tool, Import, Chronicle Setup, System Config, Docs) and what it's for.
- **Player- and Storyteller-facing pages** - a second table for the two things that live on
  the front end instead of wp-admin: **Game Dashboard** (roster stats and roster health, on
  both the Storyteller Toolkit and My Chronicle) and **Notifications** (the per-chronicle
  toggle on Chronicle Setup → Chronicle Access, plus each player's own opt-out on their
  WordPress Profile page).
- **Widgets & shortcodes** - every Elementor widget this plugin adds (Character Sheet,
  Character List, Character Editor, Approval Queue, Plot Manager, My Plots, Query Tool,
  World Objects, Boon Ledger, Import Tool, Game Dashboard, House Rules), plus the one
  shortcode this plugin has: `[be_house_rules game="chronicle-slug"]`.

## Common tasks

### Find out where something lives

1. Open **Beyond Elysium** (or **Beyond Elysium → Dashboard**).
2. Read down **What's where** for a wp-admin page, or **Player- and Storyteller-facing
   pages** for something on the front end.

### Start your first chronicle

1. Open **Beyond Elysium**. If **Create your first chronicle** is showing, click **Go to
   System Config → Games**.
2. Creating a chronicle itself needs a site admin account - see [Games](games.md).

### Add a widget to a page

1. Check **Widgets & shortcodes** for the one you want.
2. Add it to a page in Elementor, or type the `[be_house_rules game="..."]` shortcode
   directly where you want a live House Rules page.

## Things to know

- **This page doesn't check chronicle membership at all** - it only describes where things
  live and links onward to pages that are individually gated. Being able to see it proves
  nothing about what you can actually do on the pages it names.
- **The call-to-action disappears the moment a second, real chronicle exists.** It isn't a
  permanent fixture, and it never reappears once your install has grown past the demo.
- **Which of the other sidebar rows you can actually click depends on your role.** A plain
  player sees this page and Docs; a Narrator adds Plots; an HST or AST sees everything except
  Games, Chronicle Access, and anything scoped to a site admin.
- Every Beyond Elysium admin page, including this one, carries a small memorial line at the
  very bottom: "In Memory of Arielle 'XP Day' M."

## Troubleshooting

- **I only see "Dashboard" and "Docs" in the sidebar.** That's expected for a plain player -
  every other row needs a Storyteller role in at least one chronicle, or a site admin
  account.
- **"Create your first chronicle" won't go away.** It only hides once a chronicle other than
  the seeded demo one exists - check **System Config → Games**.
- **A widget I added to a page doesn't show anything.** Most widgets still need a chronicle
  picked in their own settings, or need the viewer to hold the matching role in it - check
  that widget's own help doc.

## Related

- [Admin Characters](admin-characters.md)
- [Storyteller Toolkit](storyteller-toolkit.md)
- [My Chronicle](my-chronicle.md)
- [House Rules](house-rules.md)
- [Roles](roles.md)
- [Admin Guide](../admin-guide.md#the-wp-admin-menu)
