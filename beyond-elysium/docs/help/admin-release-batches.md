# Admin Releases

A wp-admin entry point to the same Releases tool the Storyteller Toolkit uses, for staff who'd rather work from wp-admin than the front end.

## Who can use this

Anyone holding Plots & Rumors access - the same audience as [Releases](release-batches.md), the tool this tab embeds. Reaching **Beyond Elysium → Plots** in wp-admin at all needs the same site-wide capability an HST, AST, Narrator, or administrator account already carries, so the **Game** dropdown lists every chronicle on the install - but scheduling or releasing a batch only works for a chronicle where you actually hold Plots & Rumors access. A player never sees this menu item.

## How to get there

wp-admin sidebar → Beyond Elysium → Plots → **Releases** tab.

## The screen

- **Game** - a dropdown listing every chronicle on the install. Picking one loads that chronicle's release batches below.
- Below that, the full [Releases](release-batches.md) tool - the same batch lists, new-batch form, and batch detail view as Storyteller Toolkit → Releases.
- "No games exist yet - create one under Beyond Elysium → System Config → Games first." appears in place of everything above when no chronicle exists on the install at all.

## Common tasks

### Open a chronicle's release batches from wp-admin

1. Open Beyond Elysium → Plots.
2. Click the **Releases** tab.
3. Pick the chronicle from **Game**.
4. Use the tool exactly as described in [Releases](release-batches.md).

## Things to know

- **This is the same tool, not a second one.** Scheduling, releasing, and moving items between batches all work identically here - only the chronicle picker and the tab around it are different from the Storyteller Toolkit.
- **The Game dropdown isn't limited to chronicles you actually manage** - it lists every chronicle on the install. Picking one you hold no role in fails to load.
- Every Beyond Elysium admin page, including this one, carries a small memorial line at the very bottom: "In Memory of Arielle 'XP Day' M."

## Troubleshooting

- **"No games exist yet - create one under Beyond Elysium → System Config → Games first."** Only a site administrator can create one.
- **"Failed to load release batches."** You likely don't hold Plots & Rumors access in the chronicle you picked - try a different one, or ask an HST/AST.
- **I don't see the Releases tab at all.** It needs Plots & Rumors access in at least one chronicle, or an administrator account.

## Related

- [Releases](release-batches.md)
- [Plots & Rumors](plot-manager.md)
- [Admin Dashboard](admin-dashboard.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#8-plots-actions-and-rumors)
