# Chronicle Access

Who belongs to a chronicle and what role they hold, the accessSchema integration, and two
site-wide settings for backing up or deleting Beyond Elysium's own data.

## Who can use this

Site administrators only. Every route this screen calls needs a real WordPress administrator
account, and the tab itself is hidden from anyone else - an HST or AST sees the
[Chronicle Setup](chronicle-setup.md) checklist's own Storytellers row, but the actual member
list and role assignment live here, out of their reach. A Narrator, a chronicle's Harpy, and
a player never see this tab at all.

## How to get there

wp-admin sidebar → Beyond Elysium → Chronicle Setup → Chronicle Access tab. The tab itself
only appears for a site administrator.

## The screen

- **accessSchema** - a checkbox, "Use accessSchema role paths (falls back to chronicle
  membership below whenever it denies, is unreachable, or is off)", plus a line reporting
  whether a real accessSchema client is detected on this install, with a warning if the
  toggle is on but nothing is installed to back it.
- **Data Management** - site-wide, not per chronicle:
  - A checkbox, "Delete all Beyond Elysium data when the plugin is uninstalled (off by
    default - deactivating or uninstalling otherwise keeps every chronicle intact)."
  - **Export all data** - downloads every chronicle, character, and catalog as one JSON file.
- **Chronicle** - a dropdown of every chronicle on the install. Defaults to the first real
  chronicle rather than the seeded demo, unless a link named one directly.
- **"[Chronicle]'s accessSchema path"** - the stored path shown in a code style, or
  "(not set)", with an **Edit** button that reveals a text box (placeholder
  "Chronicle/KONY") and **Save**/**Cancel**.
- **"[Chronicle]'s notifications"** - a checkbox, "Email a player when their submitted change
  is approved or rejected."
- **Members** - a table of Name, Email, Role, and Actions for this chronicle:
  - **Role** is a dropdown per member offering **HST**, **AST**, **Narrator**, **Harpy (boons)**,
    or **Player** - what each can do is under Things to know below. Changing it saves
    immediately. A member whose WordPress account can't use their role shows "This account
    needs the Editor role on this site to use this role, unless accessSchema grants it."
  - **Remove** drops that member's chronicle-scoped access, after one confirmation.
- **+ Add Member** - opens a form: a **Role** dropdown (the same five values), a search box
  ("Search by name or email…"), a live list of matching WordPress accounts each with an
  **Add as [role]** button, and **Cancel**.

## Common tasks

### Turn accessSchema on or off

1. Check or uncheck the **accessSchema** checkbox at the top of the page.

### Back up all plugin data

1. Under **Data Management**, click **Export all data**.
2. A JSON file downloads with today's date in its name.

### Set a chronicle's accessSchema path

1. Pick the chronicle.
2. Click **Edit** next to its accessSchema path.
3. Type the path (for example, `Chronicle/KONY`).
4. Click **Save**.

### Add a member

1. Pick the chronicle.
2. Click **+ Add Member**.
3. Pick a **Role**.
4. Search by name or email and click **Add as [role]** next to the right person.

### Change a member's role

1. Find them in the **Members** table.
2. Pick the new role from their **Role** dropdown.

### Remove a member

1. Find them in the **Members** table.
2. Click **Remove** and confirm.

## Things to know

- **accessSchema is a fallback chain, not a replacement.** Whenever it denies access, is
  unreachable, or is switched off, permission checks fall back to this chronicle's own
  membership table automatically - a chronicle runs fine with no wider OWBN plugin stack
  installed at all.
- **Five roles, not four: HST, AST, Narrator, Harpy (boons), and Player.** The Harpy runs the
  boon ledger only, with no Storyteller powers over characters or plots.
- **A role only does what the account's WordPress role allows.** Without accessSchema, an HST,
  AST, or Narrator needs the Editor role on this site; the Harpy and Player roles work on any
  account. Chronicle Access flags a member whose account falls short.
- **Removing a member only removes chronicle-scoped access.** Their WordPress account, and
  any characters they own, are untouched.
- **You rarely need to add a player by hand.** A player gets a row here automatically once a
  Storyteller approves their pending request to join, or you assign them an existing
  character - add someone here directly to make them a Storyteller, a Narrator, or your
  Harpy, or to add a player ahead of time.
- **The chronicle picker skips the demo chronicle by default**, preferring the first real one,
  so you don't edit sample data by mistake.
- **A player's own notification opt-out is separate from this screen.** It lives on their own
  WordPress Profile page, and only matters once this chronicle's own notifications toggle is
  on.
- **Data Management is site-wide.** The export and the delete-on-uninstall choice cover every
  chronicle on the install at once, not just the one selected above.

## Troubleshooting

- **"accessSchema is enabled but no client is installed - every chronicle-scoped request is
  falling through to membership below."** The toggle is on but nothing backs it - turn it
  off, or install and activate the accessSchema client.
- **A search in Add Member finds nobody.** Check the spelling - it matches name, email, or
  username.
- **I don't see this tab.** You're not signed in as a site administrator - the
  [Chronicle Setup](chronicle-setup.md) checklist's Storytellers row still tells you, in
  outline, whether one is assigned.
- **I removed a member by mistake.** Add them back with **+ Add Member** and pick their role
  again - nothing else about their account changed.
- **"This account needs the Editor role on this site to use this role..."** Give that person's
  WordPress account the Editor role (Users screen), or grant the role through accessSchema.

## Related

- [Chronicle Setup](chronicle-setup.md)
- [Action & Rumor Settings](apr-settings.md)
- [Games](games.md)
- [Admin Characters](admin-characters.md)
- [Roles](roles.md)
- [Chronicles](chronicles.md)
- [Admin Guide](../admin-guide.md#chronicle-scoped-access)
- [Storyteller Guide](../st-guide.md#accessschema-and-chronicle-roles)
