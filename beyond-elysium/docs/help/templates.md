# Templates

How a creature stack's sections are laid out on the rendered sheet - which block goes in which
column, in what order, and under what heading.

## Who can use this

A site administrator manages the shared templates every chronicle uses by default - the
version of this screen reached directly, with no chronicle chosen.

Storytellers (HST and AST) reach the same screen scoped to their own chronicle, normally by
following [Chronicle Setup](chronicle-setup.md)'s Sheet templates link, and can give their
chronicle its own layout - either a brand-new one, or a copy of a shared template they then
adjust. Reaching this screen with no chronicle chosen still lets a Storyteller see the shared
templates, but not create, edit, or delete one there.

A Narrator, a chronicle's Harpy, and a player never see this screen; what it produces is what
they see automatically every time they open a sheet.

## How to get there

- Site administrator, editing the shared templates: wp-admin sidebar → Beyond Elysium →
  System Config → Templates tab.
- Storyteller, editing your own chronicle's layout: wp-admin sidebar → Beyond Elysium →
  Chronicle Setup, pick your chronicle, find **Sheet templates**, and click **Go**.

## The screen

With a chronicle chosen, **This chronicle's templates** appears first: **Name**, **Type**,
**Stack**, **Actions** (**Edit**, **Delete**), and **+ New Template for this chronicle**.

**Shared templates** always follows: **Name**, **Type**, **Stack**, **System?** (Yes/No),
**Actions**:

- **Customize for this chronicle** (only with a chronicle chosen) - opens a create form for
  your chronicle, pre-filled from that shared template.
- **Edit** / **Delete** (site administrator only, with no chronicle chosen; Delete never
  offered for a **System = Yes** template).

**+ New Template** (site administrator, no chronicle chosen) opens a blank form.

### The create/edit form

- **Name**.
- **Template Type** - free text (`sheet_full`, `sheet_compact`, `sheet_mobile`, and similar).
- **Stack Slug** - optional; blank applies to every creature type, naming one narrows the
  template to that type alone.
- **Columns** - how many columns the sheet's sections flow into (1 to 4).
- **Sections** table - one row per section of the sheet. **+ Add section** appends a new row;
  **Remove** deletes one.
  - **Block** - which schema block this section renders. Each block can appear only once in a
    template.
  - **Title** - the section's own heading, with optional **+ Title reference** rows beneath it
    - pick another Block and its Field name to append that field's resolved value onto this
    title, for example naming which Discipline a power belongs to.
  - **Width** - third, half, or full: how much of a row this section's box takes up.
  - **Column** - which numbered column (1 up to Columns above) this section sits in.
  - **Order** - its position within that column; lower numbers sit higher up.
  - **Display override** - leave as the block's own default, or force one of the sheet's
    built-in display styles (for example, dots instead of a plain count) for this section
    alone.
  - **Collapsed** - a checkbox saved with the section. Nothing on the rendered sheet currently
    changes based on it.
- **Save** / **Cancel**.

## Common tasks

### Give one chronicle its own sheet layout

1. Open [Chronicle Setup](chronicle-setup.md) for that chronicle, find **Sheet templates**,
   and click **Go**.
2. In **Shared templates** below, find the one you want to change and click **Customize for
   this chronicle**.
3. Adjust its sections, columns, or titles.
4. Click **Save**. Only this chronicle uses the new layout - the shared template is untouched.

### Add a section to a template

1. Edit the template.
2. Click **+ Add section**.
3. Pick its **Block**, type a **Title**, and set **Column**, **Order**, and **Width**.
4. Click **Save**.

### Show a power's governing trait in its own heading

1. Edit the section that needs it.
2. Click **+ Title reference**.
3. Pick the **Block** that holds the value, and type its **Field name**.
4. Click **Save**.

### Create a brand-new global template

1. Open System Config → Templates with no chronicle chosen.
2. Click **+ New Template**.
3. Fill in **Name** and **Template Type**, and optionally a **Stack Slug**.
4. Build the layout below.
5. Click **Save**.

## Things to know

- **Two catalogs, same as Schema Blocks.** A chronicle's own template - made here or via
  Customize for this chronicle - always wins over the shared one, for the same Template Type
  and Stack Slug; every other chronicle keeps using the shared version.
- **A blank Stack Slug applies to every creature type**; naming one narrows the template to
  just that type.
- **Falling back is layered.** Delete a chronicle's own template and its sheets go back to the
  shared one underneath. Delete the shared template too (site administrator, non-system only)
  and sheets fall back further still, to a layout Beyond Elysium generates automatically from
  the creature stack's own sections.
- **A system template can never be deleted**, in either catalog - only a chronicle's own copy
  of one can be.
- **Width only ever accepts third, half, or full** - there's nothing in between, and anything
  else is refused when you save.
- **Each block can appear in a template only once.** Add the same Block to a second section
  and saving is refused.
- **A template only controls layout.** It never decides what's hidden from a player - a
  section flagged Storyteller only on [Schema Blocks](schema-blocks.md) stays hidden no matter
  which template is showing, or which column it's placed in.
- **Seeing the tab isn't the same as being able to use it here.** Any Storyteller-capable
  account can open this screen, but changing a specific chronicle's own templates still needs
  a real Storyteller role in that one chronicle.

## Troubleshooting

- **I don't see Edit or Delete on a shared template.** Only a site administrator can change the
  shared catalog directly - use **Customize for this chronicle** instead, with your chronicle
  chosen.
- **Every chronicle I try shows a permission error.** You can see this tab without being a
  Storyteller anywhere - each chronicle still checks whether you actually hold a Storyteller
  role in it.
- **I don't see this tab at all.** System Config needs a WordPress administrator account, or a
  Storyteller role in some chronicle.
- **Saving says the layout is invalid.** Check every section has a Block chosen, that no two
  sections share the same Block, and that each Column number is within the template's own
  Columns count.
- **My chronicle's sheets look different after I deleted a template.** That's expected -
  deleting your chronicle's own template returns its sheets to the shared layout underneath,
  which may be arranged differently.

## Related

- [Schema Blocks](schema-blocks.md)
- [Creature Stacks](creature-stacks.md)
- [Character Sheet](character-sheet.md)
- [Chronicle Setup](chronicle-setup.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Admin Guide: Templates](../admin-guide.md#templates)
