# Resource Pools & Identity Fields

Two of the building blocks inside the character editor: the dot-and-stepper trackers for pools like Willpower and Blood, and the dropdowns, checkboxes, and text fields for named traits like Clan and Nature.

## Who can use this

Anyone editing a character sees this inside the Edit tab - a player editing their own character, or a Storyteller editing any character in the chronicle. If you can only view the character, every pool and field here shows as a plain read-only value with no stepper or input. See [Character Editor](character-editor.md) for who can open the Edit tab at all.

A few pool and field values are Storyteller-only no matter who's editing - see Things to know.

## How to get there

My Chronicle → Edit tab, inside any section built as a resource pool (Willpower, Blood Pool, Rage, and similar) or an identity field (Clan, Nature, Generation, and similar), depending on the chronicle's own template.

## The screen

### Resource pools

- Each pool gets its own row: a label, then a **P** (Permanent) track and a **T** (Temporary) track, each a row of same-sized dots with a number and **−** / **+** buttons.
- Permanent dots are square; temporary dots are round. Both are drawn the same size as every other dot on the sheet - only the shape and color tell them apart.
- Clicking **−** or **+** moves that track by one, down to 0 or up to the pool's maximum.
- A temporary value above permanent shows past the permanent mark in red, rather than being capped - a temporary boost above your normal rating is visible at a glance.
- A pool's label can change with something else on your sheet. A vampire's Conscience and Self-Control pools, for example, relabel to Conviction and Instinct once you're following a Path other than Humanity - the same pool and the same dots, just a different name.

### Identity fields

Each field renders as one of these, depending on how the chronicle's template built it:

- **Dropdown** - a single choice from a fixed list. If the field allows a custom entry, you can type your own value instead of picking one.
- **Checkbox group** - for a field that allows more than one value at once. A counter under the group shows how many you've picked against the maximum; once you're at the maximum, the remaining boxes disable until you uncheck one.
- **Number box** - a plain number, with a minimum and maximum where the field sets them.
- **Text box** or **text area** - a single line or a longer block of free text. A text area also carries an **AI Assist** button, shown only to a Storyteller, even on a player's own character.

## Common tasks

### Raise a resource pool

1. Open the character in the **Edit** tab.
2. Find the pool's row.
3. Click **+** next to **P** to raise its permanent rating, or next to **T** to spend or regain points in play.
4. Check **Pending Changes** for its cost, then click **Submit Changes**.

### Change an identity field

1. Open the character in the **Edit** tab.
2. Find the field.
3. Pick a new value, or type one where the field allows it.
4. Click **Submit Changes**.

### Pick more than one option on a multiselect field

1. Find the field - the counter under it shows how many you've picked and the maximum.
2. Check the options you want, up to that maximum.

## Things to know

- **Permanent changes are priced; temporary ones aren't.** Raising a pool's Permanent rating queues an XP cost in Pending Changes, the same as any other purchase. Moving its Temporary track - spending or regaining points in play - costs nothing and needs no review.
- **Some pools aren't purchased at all.** A pool your chronicle awards rather than sells (Renown is the classic example) has no XP cost, and only a Storyteller can set its Permanent rating. You can still move its Temporary track freely, but submitting a change that raises the Permanent side yourself is refused.
- **Identity fields are free to set.** Changing one costs no XP. Your chronicle can still require Storyteller review for a specific field, or for one option on it - picking "Antediluvian," for example, while every other Clan choice is automatic - and that shows up in Pending Changes as needing Storyteller approval, the same as any other reviewed change.
- **A few fields can't be cleared by a player.** A field your chronicle prices other traits by - Clan is the usual case - can be changed to a different value but not blanked out. Ask a Storyteller if it genuinely needs to be empty.
- **Every dot is the same size** - a pool's dots here, a trait's rating on the rest of the sheet, and a signed PDF all draw the same 14-pixel dot.

## Troubleshooting

- **"…'s permanent rating is set by a Storyteller."** You tried to raise a pool that has no XP cost (an awarded pool, such as Renown) past what you already hold. Ask a Storyteller to set it instead.
- **"… cannot be cleared - ask a Storyteller."** You tried to blank out a field your chronicle uses to price other traits. Change it to a different value, or ask a Storyteller to clear it for you.
- **A checkbox won't check.** You've already picked the maximum this field allows - the counter under the group shows how many. Uncheck one first.
- **A pool's name doesn't match what I expected.** Some pools relabel based on another choice on your sheet (a vampire's Virtues under a non-Humanity Path, for example). The dots and the stored value aren't affected - only the name shown changes.

## Related

- [Character Editor](character-editor.md)
- [Trait Lists](trait-editor.md)
- [Powers](power-editor.md)
- [Approval Rules](approval-rules.md)
- [How Approval Works](approval-flow.md)
- [Player Guide](../player-guide.md#2-editing-your-sheet)
- [Storyteller Guide](../st-guide.md#3-making-characters)
