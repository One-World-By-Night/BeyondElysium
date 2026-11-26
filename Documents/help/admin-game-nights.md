# Admin Game Nights

A wp-admin entry point to the same Game Nights tool the Storyteller Toolkit uses, for staff who'd rather work from wp-admin than the front end.

## Who can use this

Storytellers (HST and AST) and Narrators - the same audience as [Game Nights](game-nights.md), the tool this page embeds. Reaching **Beyond Elysium → Game Nights** in wp-admin at all needs the same site-wide capability an HST, AST, Narrator, or administrator account already carries, so the **Game** dropdown lists every chronicle on the install - but scheduling a session or signing characters in only works for a chronicle where you actually hold a Storyteller or Narrator role. A player never sees this menu item.

## How to get there

wp-admin sidebar → Beyond Elysium → Game Nights.

## The screen

- **Game** - a dropdown listing every chronicle on the install. Picking one loads that chronicle's sessions below.
- Below that, the full [Game Nights](game-nights.md) tool - the same session list, session form, sign-in roster, and attendance XP award as Storyteller Toolkit → Game Nights.
- "No games exist yet - create one under Beyond Elysium → System Config → Games first." appears in place of everything above when no chronicle exists on the install at all.

## Common tasks

### Open a chronicle's game nights from wp-admin

1. Open Beyond Elysium → Game Nights.
2. Pick the chronicle from **Game**.
3. Use the tool exactly as described in [Game Nights](game-nights.md).

## Things to know

- **This is the same tool, not a second one.** Scheduling a session, signing characters in, and awarding attendance XP all work identically here - only the chronicle picker and the page around it are different from the Storyteller Toolkit.
- **The Game dropdown isn't limited to chronicles you actually manage** - it lists every chronicle on the install. Picking one you hold no role in fails to load.
- Every Beyond Elysium admin page, including this one, carries a small memorial line at the very bottom: "In Memory of Arielle 'XP Day' M."

## Troubleshooting

- **"No games exist yet - create one under Beyond Elysium → System Config → Games first."** Only a site administrator can create one.
- **"Failed to load game sessions."** You likely don't hold a Storyteller or Narrator role in the chronicle you picked - try a different one, or ask an HST/AST.
- **I don't see this menu item at all.** It needs a Storyteller or Narrator role in at least one chronicle, or an administrator account.

## Related

- [Game Nights](game-nights.md)
- [Plots & Rumors](plot-manager.md)
- [Admin Dashboard](admin-dashboard.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#8-plots-actions-and-rumors)
