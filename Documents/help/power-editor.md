# Powers

The editor for a tiered-power section of a character's sheet - leveled catalogs like
Disciplines, Gifts, Arts, and Spheres, where each held power has a numbered level or, past the
numbered ladder, an Elder-and-above pick.

## Who can use this

Anyone editing a character sees this inside the Edit tab - a player editing their own
character, or a Storyteller editing any character in the chronicle. If you can only view the
character, every power here shows as a plain read-only list with no Add, stepper, or Remove
control. See [Character Editor](character-editor.md) for who can open the Edit tab at all.

## How to get there

My Chronicle → Edit tab, inside any section built as a leveled power list (Disciplines, Gifts,
Arts, and similar, depending on the chronicle's own template).

## The screen

- **Add power…** - a searchable dropdown of every power in this section's catalog you don't
  already hold. Typing filters the list; where this section allows a custom entry, typing a
  name that isn't in the list adds it, starting at level 1. **For Blood Magic**, choosing a
  power doesn't add it right away - a **Paradigm** dropdown appears next to it, listing every
  paradigm this section offers (the power's own real teachers first), and **Add** stays
  disabled until you pick one.
- **Add an Elder-and-above power…** - a second dropdown, shown only once you hold some level of
  at least one family with picks past the numbered ladder. Always a real catalog pick - never a
  typed custom entry. Blood Magic gates this the same way as Add power - a paradigm before it's
  actually added.
- **Reorder** - Blood Magic only. Puts your held paths in whatever order you like instead of
  the usual catalog order - see [Your Own Order](player-order.md).
- Each held power, one row:
  - Its name, and either a **List each level** / **Use stepper** toggle plus one of:
    - **Stepper** (the default) - **−** and **+** buttons around the current level.
    - **Checklist** - one box per rung up to the family's own maximum, each labeled with that
      rung's real named power (for example, Celerity's "Alacrity," "Swiftness," and so on).
      Checking a box raises the level to that rung; unchecking one lowers it to just below that
      rung.
  - **Tradition** - a free-text field with suggestions, for the power's own tradition or source.
    A Blood Magic power added before this section asked for one up front shows the placeholder
    "Choose paradigm" until you set one - never forced, since a Storyteller's review is what
    actually checks it.
  - **Remove** / **Undo** - marks the power for removal, or brings it back.
  - An Elder-and-above pick shows its family and power name together (for example, "Celerity:
    Precision") with no stepper or checklist - only Remove/Undo, since it's a single named pick,
    not a rated level.

On a narrow screen, Tradition and Remove/Undo move behind a **Details** button that opens them
in a small panel; the stepper or checklist stays on the row itself.

## Common tasks

### Add a new power

1. Open the section and use **Add power…**.
2. Choose a name from the list (or type one, where allowed). **For Blood Magic**, pick a
   **Paradigm** and click **Add** - it stays disabled until you do.
3. It's added at level 1. Raise it with the stepper, or switch to **List each level** and check
   the rungs you hold.

### Raise or lower a power's level

1. Find the power's row.
2. Use its **−** / **+** stepper, or switch to **List each level** and check or uncheck a rung.

### Add an Elder-and-above pick

1. Hold some level of the family it belongs to.
2. Use **Add an Elder-and-above power…** and choose the pick. **For Blood Magic**, pick a
   **Paradigm** and click **Add**.

### Set a power's tradition

1. Find the power's row (or tap **Details** on a narrow screen).
2. Type into **Tradition** - suggestions appear as you type.

### Remove a power

1. Find the power's row (or tap **Details** on a narrow screen).
2. Click **Remove**.
3. To bring it back before you submit, click **Undo**.

## Things to know

- **Numbered powers add up.** Buying a fresh power at level 3 costs levels 1, 2, and 3 together,
  not level 3 alone - reflected in whatever cost the Edit tab's Pending Changes shows, not here.
- **List each level / Use stepper is one choice for your whole sheet**, remembered on this
  device - switching it on any power switches every power, on every sheet you open here, and it
  stays that way next time.
- **A custom name always needs review.** Typing a name that isn't in the catalog only saves
  where this section allows it, and even then it always goes to a Storyteller for approval, no
  matter how your chronicle has auto-approval configured. Elder-and-above picks are never
  custom - only real catalog entries.
- **An Elder-and-above pick is additive**, not a replacement - you keep your family's numbered
  level, and can hold several distinct Elder-and-above picks in it at once.
- **A paradigm is required to add a new Blood Magic power, but never to keep one.** A power
  added before this section asked for a paradigm up front just shows "Choose paradigm" on its
  row - a nudge, not a lock. A Storyteller reviewing the change is the actual check either way.
- **Any paradigm this section offers works for any power in it.** A path's own catalog-listed
  teachers are only listed first for convenience - picking a different real paradigm from the
  list is allowed and correctly reviewed.
- **Removing marks, it doesn't delete** - nothing leaves the sheet until you submit changes from
  the Edit tab.
- **Every level display matches the sheet and a signed PDF exactly.**

## Troubleshooting

- **I don't see Add power… or a stepper.** You're viewing a character you can't edit - see
  [Character Editor](character-editor.md).
- **I don't see Add an Elder-and-above power….** You don't yet hold any level in a family that
  has picks past the numbered ladder.
- **A name I typed says it isn't in the catalog.** Check the spelling. If it's genuinely new,
  this section may not accept custom entries at all - ask a Storyteller.
- **The stepper won't go higher.** You're at that power's own maximum numbered level - an
  Elder-and-above pick, if the family has any, is a separate control below the list.
- **Switching List each level / Use stepper changed every other power too.** That's expected -
  it's one setting for your whole sheet, not per power.

## Related

- [Character Editor](character-editor.md)
- [Trait Lists](trait-editor.md)
- [Your Own Order](player-order.md)
- [Resource Pools & Identity Fields](pools-identity-editor.md)
- [Character Sheet](character-sheet.md)
- [How Approval Works](approval-flow.md)
- [Player Guide](../player-guide.md#2-editing-your-sheet)
- [Storyteller Guide](../st-guide.md#3-making-characters)
