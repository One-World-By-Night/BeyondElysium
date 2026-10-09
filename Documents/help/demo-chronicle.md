# Demo Chronicle

Marks any chronicle so it resets itself back to a declared starting point on a schedule, rather than staying a one-time sample. This is a different thing from the `be-demo` chronicle the plugin seeds once on a fresh install (see [Games](games.md)'s "Delete demo chronicle" row) - that one is a fixed, one-time set of sample characters; this feature can turn *any* chronicle on *any* install into a chronicle that keeps rebuilding itself.

## Who can use this

A WordPress administrator account only, the same bar as [Games](games.md) itself. Nobody else can turn the flag on or off, pick its two accounts, or change its cadence - a chronicle's own HST and AST do not see this section at all.

## How to get there

wp-admin sidebar → Beyond Elysium → System Config → Games tab → **Edit** on the chronicle you want → the **Demo chronicle** section below the save button.

## The screen

- **Make this chronicle a demo** - a checkbox, off by default. Checking it warns that everything in the chronicle is replaced at the next reset, and every reset after that.
- **Reset cadence** - how often it resets: every 1, 3, 6, 12, or 24 hours. Defaults to 6.
- **Storyteller account** and **Player account** - two existing WordPress users, searched by name or email, who are granted the HST and player role on every reset. These two accounts are the only ones a reset leaves as chronicle members; anyone else who joined since the last reset is removed.
- **Save demo settings** - writes the flag, cadence, and accounts.
- **Reset now**, once the flag is on - runs the reset immediately instead of waiting for its schedule, after one confirmation.
- **Last reset** - when the most recent reset ran, once one has.

A viewer anywhere in the chronicle - My Chronicle, the Storyteller Toolkit, a character's sheet, or its editor - sees a banner naming when it next resets, and the chronicle switcher labels it "- Demo".

## Common tasks

### Turn a chronicle into a demo

1. **Edit** the chronicle on the Games screen.
2. Check **Make this chronicle a demo**.
3. Pick a cadence.
4. Search for and pick the **Storyteller account** and **Player account**.
5. Click **Save demo settings**.
6. It resets for the first time at the next scheduled run, or immediately via **Reset now**.

### Reset a demo chronicle right now

1. **Edit** the chronicle.
2. Click **Reset now**.
3. Confirm. Everything in it is replaced from its declared content.

### Turn the flag off

1. **Edit** the chronicle.
2. Uncheck **Make this chronicle a demo**.
3. Click **Save demo settings**. Its scheduled reset stops, and delete and rename work again.

## Things to know

- **Nothing in a demo chronicle ever emails anyone.** Every mail path the plugin has - change reviews, release digests, plot post notices, submission notices, invitations - is silenced for a demo chronicle specifically, not just for the two demo accounts.
- **A demo chronicle cannot be deleted or renamed** while the flag is on - turn it off first. It also refuses to send a character to another site, to accept an inbound transfer offer, and AI drafting is switched off on it. Every AI button still shows - AI Assist, Draft roleplaying notes, Draft from a premise, Draft recap - and clicking one explains that drafting is off on the public demo and sends nothing.
- **Uploads stay allowed.** A reset deletes every attachment - rows and files - along with everything else, so there's nothing to clean up by hand.
- **A missed reset runs on the next visit**, the same way every WordPress scheduled task does - there is no server-level cron requirement beyond what the rest of the plugin already needs.
- **The companion chronicle.** Turning the flag on also creates a second chronicle, at `{slug}-companion`, with only the storyteller account as a member - never the player account - so the player account has a second chronicle it can ask to join. It resets alongside the primary one and carries the same lock.

## Troubleshooting

- **"This chronicle is not flagged as a demo."** on Reset now - the flag was turned off since the page loaded. Reload.
- **"The reset could not run - check that both demo accounts are still real users."** One of the two accounts was deleted. Pick a new one and save.
- **"A demo chronicle cannot be deleted while it is flagged as a demo."** / **"...cannot be renamed..."** Turn the flag off first.
- **I don't see this section.** You need a WordPress administrator account.

## Related

- [Games](games.md)
- [Chronicle Setup](chronicle-setup.md)
- [Admin Guide](../admin-guide.md#demo-chronicles)
