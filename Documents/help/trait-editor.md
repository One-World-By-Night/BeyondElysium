# Trait Lists

The editor for a trait-list section of a character's sheet - catalogs like Abilities,
Backgrounds, Merits, and Flaws, where each held entry is a name and a count or level.

## Who can use this

Anyone editing a character sees this inside the Edit tab - a player editing their own
character, or a Storyteller editing any character in the chronicle. If you can only view the
character, every trait here shows as a plain read-only list with no Add or edit control. See
[Character Editor](character-editor.md) for who can open the Edit tab at all.

## How to get there

My Chronicle → Edit tab, inside any section built as a trait list (Abilities, Backgrounds,
Merits, Flaws, and similar, depending on the chronicle's own template).

## The screen

- Held entries, one row each: the name, a dot or count display for anything held above zero,
  and, when set, a specialization in parentheses, a note, and the cost chosen for it. Some
  sections group their entries
  under heading and subheading rows instead of one flat list, depending on how that section is
  built.
- **✎** on each row - opens the same modal used for adding one, for editing.
- **+ Add** - opens a blank modal to add a new entry.

### The Add/Edit modal

- **Name** - a searchable dropdown of this section's catalog. Typing filters the list; where
  this section allows a custom entry, typing a name that isn't in the list adds it as one.
  Shown only when adding - a held entry's name can't be changed, only removed and re-added.
- **Count / Level** - a number, minimum 1.
- **Cost** - shown only for an entry the catalog prices at a choice of costs, such as a Merit
  listed "1 or 3" or "3-5". It starts at the lowest, which is what you pay if you leave it.
- **Specialization** - shown only for a section that supports one (for example, an Ability like
  Academics can carry a specialization such as "Byzantine History").
- **Note** - a free-text field, always available.
- **Remove** / **Undo removal** - marks the entry for removal, or brings it back, without
  leaving the modal.
- **Save** - disabled until a name is chosen; writes the entry back into this screen's own copy
  of the section - it doesn't reach the sheet until you submit changes.

## Common tasks

### Add a new entry

1. Open the section and click **+ Add**.
2. Choose a name from the list (or type one, where allowed).
3. Set the **Count / Level**, and a specialization or note if you want one. For an entry
   with a choice of costs, pick its **Cost**.
4. Click **Save**.

### Change an entry's count or level

1. Click **✎** on the entry.
2. Update **Count / Level**.
3. Click **Save**.

### Remove an entry

1. Click **✎** on the entry.
2. Click **Remove**.
3. To bring it back before you submit, open it again and click **Undo removal**.

## Things to know

- **Adding the same name again adds to it, not a duplicate row** - for most sections, choosing
  a name you already hold (with the same specialization, if it has one) raises its existing
  count instead of creating a second entry. A few sections - Merits and Flaws, for example -
  always keep each pick as its own row instead.
- **A custom name always needs review.** Typing a name that isn't in the catalog only saves
  where this section allows custom entries at all, and even then it always goes to a
  Storyteller for approval, no matter how your chronicle has auto-approval configured.
- **Removing marks, it doesn't delete** - nothing actually leaves the sheet until you submit
  changes from the Edit tab, so you can undo a removal right up until then.
- **Every dot is the same size** - the count display here matches the sheet and a signed PDF
  exactly.
- **Nothing here is priced or saved on its own.** Every add, change, or removal queues in the
  Edit tab's Pending Changes until you submit it.

## Troubleshooting

- **Save won't click.** Choose a name first - it's required.
- **A name I typed says it isn't in the catalog.** Check the spelling. If it's genuinely new,
  this section may not accept custom entries at all - ask a Storyteller.
- **I don't see + Add or the ✎ button.** You're viewing a character you can't edit - see
  [Character Editor](character-editor.md).
- **My removal disappeared.** You likely reopened the entry and clicked Undo removal, or
  discarded your pending changes before submitting.

## Related

- [Character Editor](character-editor.md)
- [Powers](power-editor.md)
- [Resource Pools & Identity Fields](pools-identity-editor.md)
- [Character Sheet](character-sheet.md)
- [How Approval Works](approval-flow.md)
- [Player Guide](../player-guide.md#2-editing-your-sheet)
- [Storyteller Guide](../st-guide.md#3-making-characters)
