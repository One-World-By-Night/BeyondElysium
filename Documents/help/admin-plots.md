# Admin Plots

A wp-admin entry point to the same Plots & Rumors tool the Storyteller Toolkit uses, for
staff who'd rather work from wp-admin than the front end.

## Who can use this

Storytellers (HST and AST) and Narrators - the same audience as
[Plots & Rumors](plot-manager.md), the tool this page embeds. Reaching **Beyond Elysium →
Plots** in wp-admin at all needs the same site-wide capability an HST, AST, or administrator
account already carries, so the **Game** dropdown lists every chronicle on the install - but
the tool only loads, and posting or editing only works, for a chronicle where you actually
hold a Storyteller or Narrator role. A player and the chronicle's Harpy never see this menu
item.

## How to get there

wp-admin sidebar → Beyond Elysium → Plots.

## The screen

- **Game** - a dropdown listing every chronicle on the install. Picking one loads that
  chronicle's plots below.
- Below that, the full [Plots & Rumors](plot-manager.md) tool - the same overview grid, plot
  detail view, Allocate actions panel, and Rumors panel as Storyteller Toolkit → Plots &
  Rumors.
- "No games exist yet - create one under Beyond Elysium → System Config → Games first." appears in place of
  everything above when no chronicle exists on the install at all.

## Common tasks

### Open a chronicle's plots from wp-admin

1. Open Beyond Elysium → Plots.
2. Pick the chronicle from **Game**.
3. Use the tool exactly as described in [Plots & Rumors](plot-manager.md).

## Things to know

- **This is the same tool, not a second one.** Creating a plot, responding to actions, notes,
  cliffhangers, and connections all work identically here - only the chronicle picker and the
  page around it are different from the Storyteller Toolkit.
- **The Game dropdown isn't limited to chronicles you actually manage** - it lists every
  chronicle on the install. Picking one you hold no role in fails to load.
- Every Beyond Elysium admin page, including this one, carries a small memorial line at the
  very bottom: "In Memory of Arielle 'XP Day' M."

## Troubleshooting

- **"No games exist yet - create one under Beyond Elysium → System Config → Games first."** Only a
  site administrator can create one.
- **"Failed to load plots."** You likely don't hold a Storyteller or Narrator role in the
  chronicle you picked - try a different one, or ask an HST/AST.
- **I don't see this menu item at all.** It needs a Storyteller or Narrator role in at least
  one chronicle, or an administrator account.

## Related

- [Plots & Rumors](plot-manager.md)
- [Allocate Actions](allocate-actions.md)
- [Rumors](rumors.md)
- [Connections](connections.md)
- [Admin Dashboard](admin-dashboard.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#8-plots-actions-and-rumors)
