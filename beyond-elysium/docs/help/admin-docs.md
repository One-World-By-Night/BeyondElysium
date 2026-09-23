# Docs

A read-only view of Beyond Elysium's own bundled guides - Storyteller, admin, player, and the REST API reference - as four tabs inside wp-admin.

## Who can use this

Anyone who can reach wp-admin. Like the [Admin Dashboard](admin-dashboard.md), this page only checks that you can view characters at all - a capability every core WordPress role holds by default, including a plain Subscriber - so it isn't gated by any Storyteller or admin role. In practice, mostly Storytellers and site admins open it; a plain player rarely has a reason to be in wp-admin at all.

## How to get there

wp-admin sidebar → Beyond Elysium → Docs.

## The screen

- Four tabs: **Storyteller Guide**, **Admin Guide**, **Player Guide**, **REST API Reference**.
- Clicking a tab loads that guide's content and renders it below, the first time you open it - switching back to a tab you've already opened doesn't reload it.
- A link inside one guide that points at another of the four guides switches tabs here instead of leaving the page; any other link behaves like an ordinary link.

## Common tasks

### Read a guide

1. Open Beyond Elysium → Docs.
2. Click **Storyteller Guide**, **Admin Guide**, **Player Guide**, or **REST API Reference**.

### Follow a cross-reference between guides

1. Click a link inside the guide you're reading.
2. If it points at one of the other three guides, this page switches to that tab in place.

## Things to know

- **This mirrors the plugin's own shipped files - it isn't a live editor.** Nothing here can be changed from the screen; the guide's own source file has to change, and the plugin has to ship a new version, for anything shown here to be different.
- **This is one of the few admin pages a plain player can technically open.** It shows the same four guides to everyone, regardless of role - nothing here is chronicle-scoped or hidden.
- Every Beyond Elysium admin page, including this one, carries a small memorial line at the very bottom: "In Memory of Arielle 'XP Day' M."

## Troubleshooting

- **A tab shows an error instead of the guide.** The plugin's shipped file for that guide couldn't be read - reload the page; if it keeps happening, tell your site admin.
- **A link inside a guide didn't switch tabs.** Only a link to one of these same four guides does that - a link to a specific section elsewhere, or anything outside these four docs, opens or navigates normally instead.

## Related

- [Admin Dashboard](admin-dashboard.md)
- [Storyteller Guide](../st-guide.md)
- [Admin Guide](../admin-guide.md)
- [Player Guide](../player-guide.md)
- [REST API Reference](../rest-api.md)
