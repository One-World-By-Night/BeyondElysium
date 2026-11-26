# Branding

The site-wide default accent color, used for the Storyteller Toolkit's and My Chronicle's own chrome (highlights, primary buttons) on any chronicle that hasn't set its own. Not a full theme - Beyond Elysium's other colors (errors, warnings, success states) stay fixed regardless of this setting, so the plugin's own UI still reads correctly no matter what this is set to. The same tab turns Beyond Elysium's own layout on My Chronicle and the Storyteller Toolkit on or off for the whole site.

## Who can use this

Site admin only - the WordPress Administrator role (`be_manage_games`). A chronicle's own HST sets an override for just that chronicle on its own [Chronicle Setup](chronicle-setup.md) screen instead - this screen only sets the fallback every chronicle without one of those uses.

## How to get there

wp-admin sidebar → Beyond Elysium → System Config → Branding tab.

## The screen

- **Accent color** - a color picker. Changing it saves immediately.
- **Reset to plugin default** - clears the site-wide default, so every chronicle without its own override falls back to Beyond Elysium's own built-in accent instead.
- **Use Beyond Elysium's own layout on My Chronicle and the Storyteller Toolkit** - on unless you turn it off, and saves immediately. On, both pages show Beyond Elysium's own header with the site's name, the page's sections and its own footer in place of the theme's; off, they sit inside the theme as before. A page rebuilt with Elementor or given another template keeps its own layout either way. See [My Chronicle](my-chronicle.md) and [Storyteller Toolkit](storyteller-toolkit.md).

## Related

- [Chronicle Setup](chronicle-setup.md)
- [AI Assist Settings (Site)](writing-assist-site.md)
- [Games](games.md)
