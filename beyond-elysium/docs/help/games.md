# Games

Create, rename, and delete chronicles - the top-level container every character, plot, and
catalog customization belongs to.

## Who can use this

A WordPress administrator account only. Every Storyteller, Narrator, Harpy, and player can
see the list of chronicles elsewhere - the chronicle picker on their own screens - but only a
site administrator can create, rename, or delete one here.

## How to get there

wp-admin sidebar → Beyond Elysium → System Config → Games tab.

## The screen

- A table of every chronicle on the install: **Name**, **Slug**, **Type**, **Created**, and
  **Actions** (**Edit**, **Delete**). "No games yet. Create the first one below." when none
  exist.
- **+ New Game** - opens a form: **Name** (required), **Slug** (optional - derived from the
  name if left blank), **Game Type** (defaults to `met`), **Description**, and
  **Save**/**Cancel**. No AI Assist button here - a chronicle you're still creating has
  nothing yet for it to attach to.
- **Edit** on a row opens the same form, pre-filled. Its **Description** field gains an
  **AI Assist** button. Changing **Slug** here shows a warning about what a rename moves.
- **Delete** on a row asks one confirmation. If the chronicle holds nothing, it just asks
  "Delete "X"?"; if it holds real content, the confirmation names what - for example, "Delete
  "X" and everything in it - 22 characters, 1 plot? This cannot be undone." - and a yes
  deletes all of it.
- After a successful rename, a notice names the new slug and how many characters, schema
  block forks, page references, and Elementor widgets moved with it.

## Common tasks

### Create a chronicle

1. Click **+ New Game**.
2. Type a **Name**. Leave **Slug** blank to have one generated.
3. Click **Save**.
4. Open [Chronicle Setup](chronicle-setup.md) next to see what's left to configure.

### Rename a chronicle's slug

1. Click **Edit** on its row.
2. Change **Slug**, and read the warning that appears.
3. Click **Save**.

### Edit a chronicle's name, type, or description

1. Click **Edit** on its row.
2. Change the field.
3. Click **Save**.

### Delete a chronicle

1. Click **Delete** on its row.
2. Read the confirmation - it names everything the chronicle holds.
3. Confirm.

## Things to know

- **Creating a chronicle here makes you its HST immediately** - no separate step, and nobody
  else is added automatically. Use [Chronicle Access](chronicle-access.md) to add other
  Storytellers, a Narrator, or your chronicle's Harpy.
- **A new chronicle starts with nothing else configured.** [Chronicle Setup](chronicle-setup.md)
  is where you see what's left.
- **Renaming the slug moves everything that names it** - every character, any customized
  schema block, the pages and widgets that reference this chronicle, active verification
  codes, and this site's side of any transfer. A slug already in use, or left behind by a
  chronicle you deleted, is refused before anything moves.
- **Deleting a chronicle is final and total.** The one confirmation names exactly what's
  inside, and a yes removes every bit of it - characters, plots, items and locations, its own
  templates and customized catalog blocks, saved queries, verification codes, and transfers.
  Nothing is ever left behind under the slug for a later chronicle with the same name to
  inherit.
- **Game Type is free text.** `met` is the only ruleset Beyond Elysium runs today - leave it
  as-is unless you have a specific reason to change it.

## Troubleshooting

- **"A game with this slug already exists."** Pick a different slug.
- **"A deleted chronicle's characters or records are still stored under this slug. Choose a
  different slug."** That slug isn't free to reuse - pick a different one.
- **"Cannot rename: a deleted chronicle's characters or records are still stored under this
  slug, and this chronicle would take them over."** Same problem, met while renaming - pick a
  different slug.
- **"Cannot rename: a chronicle already using this slug left customized schema blocks
  behind…"** Rare, and only possible from before this was fixed - pick a different slug.
- **"This chronicle still holds characters or other content. Delete it with its content, or
  keep it."** Click **Delete** again - the confirmation will name what's inside and remove
  all of it.
- **I don't see this tab.** You need a WordPress administrator account.

## Related

- [Chronicle Setup](chronicle-setup.md)
- [Chronicle Access](chronicle-access.md)
- [Action & Rumor Settings](apr-settings.md)
- [Admin Characters](admin-characters.md)
- [Admin Dashboard](admin-dashboard.md)
- [Roles](roles.md)
- [Chronicles](chronicles.md)
- [Storyteller Guide](../st-guide.md#1-creating-a-game)
- [Admin Guide](../admin-guide.md#what-an-hst-can-and-cannot-do)
