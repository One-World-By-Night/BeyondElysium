# Profile Settings

Two Beyond Elysium checkboxes on your ordinary WordPress profile screen: whether you're
emailed about your own approved or rejected character changes, and - site admin only - whether
a specific user may customize how their character sheets look.

## Who can use this

Everyone with an account on the site sees **Change Notifications** on their own profile. Only
a site admin sees **Sheet Customization** at all, on their own profile or anyone else's.

## How to get there

wp-admin sidebar → **Profile** (a site admin sees this nested under **Users → Your Profile**
instead). A site admin can also open another user's version of this screen from
**Users → All Users**, by editing that user.

## The screen

A "Beyond Elysium" section appears on the profile screen, below WordPress's own fields:

- **Sheet Customization** - a checkbox, shown only to a site admin: "Allow this user to
  customize their own character sheets (font, colors, background image, section graphics)."
  Checking it for a user, then saving the profile, lets that person use
  [Customize Appearance](sheet-customize.md) on any sheet they can already edit.
- **Change Notifications** - a checkbox, shown to anyone editing a profile they're allowed to
  edit: "Do not email me when a storyteller approves or rejects one of my submitted character
  changes." Checked means you've opted out; unchecked (the default) means you get that email.

Both save with the profile screen's own **Update Profile** button - there's no separate save
just for these two.

## Common tasks

### Turn off change-approval emails

1. Open your own profile.
2. Under "Beyond Elysium," check "Do not email me when a storyteller approves or rejects one
   of my submitted character changes."
3. Click **Update Profile**.

### Grant a player sheet customization

1. As a site admin, open that user's profile from **Users → All Users**.
2. Under "Beyond Elysium," check "Allow this user to customize their own character sheets…"
3. Click **Update Profile**.

## Things to know

- **The grant is per user, not per character or per chronicle.** Once checked, that person can
  style every character they can already edit, in every chronicle they belong to.
- **Storytellers (HST and AST) already have sheet customization** without this checkbox -
  this is how a plain player gets it. See [Customize Appearance](sheet-customize.md).
- **This is the only piece of change-notification control a player has directly.** Whether a
  chronicle sends these emails at all is a separate, chronicle-level setting a site admin
  controls - see [Chronicle Access](chronicle-access.md). Opting out here means you never get
  the email regardless of that setting; leaving it unchecked only gets you the email from a
  chronicle that has notifications turned on.
- **This is a WordPress profile screen, not a Beyond Elysium page.** Everything else on it -
  your name, email, password - is ordinary WordPress, unrelated to this plugin.

## Troubleshooting

- **I don't see a "Beyond Elysium" section at all.** You're viewing a profile you're not
  allowed to edit - most users only see this on their own profile.
- **I don't see Sheet Customization.** Only a site admin sees this checkbox, on any profile.
- **I granted sheet customization but the user still doesn't see Customize Appearance on a
  sheet.** Confirm the change actually saved (reopen their profile and check the box is still
  ticked), and confirm they're looking at a character they can already edit - the grant
  doesn't override ownership.
- **I'm still getting change-approval emails after opting out.** Confirm you clicked
  **Update Profile** after checking the box - the checkbox alone doesn't take effect until the
  profile is saved.

## Related

- [Customize Appearance](sheet-customize.md)
- [Character Sheet](character-sheet.md)
- [Chronicle Access](chronicle-access.md)
- [Roles](roles.md)
