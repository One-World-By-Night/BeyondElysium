# Import

Bring characters, items, locations, and rotes in from a Grapevine exchange file, bring in a
whole chronicle from a full game file, and review anything waiting from outside this chronicle -
a transfer another chronicle's Storyteller has sent, or a Grapevine file a player has sent in
directly.

## Who can use this

Storytellers (HST and AST) for the **Characters & World Objects** tab and **Waiting for
Review**. The **Full Game File** tab needs a site administrator account - it isn't scoped
to one chronicle, since it can create a brand-new one. Reaching **Beyond Elysium → Import** at
all needs the same site-wide capability an HST, AST, or administrator account already
carries. The two tabs only appear side by side for an account that holds both, which in
practice means a site administrator; a Storyteller who isn't also a site admin sees the
Characters & World Objects tool directly, with no tab strip, and never reaches Full Game File.
A Narrator, the chronicle's Harpy, and a player never see this menu item.

## How to get there

wp-admin sidebar → Beyond Elysium → Import.

## The screen

When both tabs are available: **Characters & World Objects** and **Full Game File**.
Otherwise, whichever one tool applies to your account shows directly, without a tab strip.

### Waiting for Review

Shown above the Characters & World Objects tool, and only when a chronicle has something
waiting - a transfer offer, a visiting character, or a player-sent Grapevine file - it stays
hidden entirely otherwise. A table: **Character**, **From**, **Status**, **Actions**.

- A waiting transfer offer (**Waiting for review**): **Review** opens the same preview and
  decisions as an uploaded file, ending in **Accept Transfer** or **Close**; **Refuse** turns
  it away with nothing added.
- A visiting character (**Visiting**): **Send home** ends the visit, **Keep for good** makes
  it yours permanently.
- A player-sent Grapevine file (**Waiting for review**, "From" reading who sent it and
  whether they're joining or visiting) - **Review** opens the same preview a file upload
  gets, plus who sent it, their choice of joining or visiting (yours to change before
  accepting), and a note on whether the file's own verification code still matches what its
  home chronicle exported; **Refuse** opens a short form for an optional note back to the
  sender before turning it away.

See [Send Sheet](transfer.md) for the sending side of a transfer, and
[Send a Grapevine File](send-grapevine-file.md) for a player's own file.

### Characters & World Objects (`.gex`)

A five-stage wizard: **1. Upload**, **2. Preview**, **3. Match Players**, **4. Resolve
Traits**, **5. Commit**.

- **Upload** - a file picker accepting a `.gex` file, binary or XML, and **Parse File**.
- **Preview** and **Resolve Traits** both show the same review content:
  - **Counts** - how many of each record type the file carries (Players, Characters,
    Queries, Items, Rotes, Locations, Actions, Plots, Rumors), skipping anything at zero.
  - **Warnings**, when the parser has any.
  - **Duplicate Characters** - each one already in this chronicle by UUID or by name, with a
    **Decision** dropdown (Skip, Overwrite, or Import as a new, separate character), and, for
    one matched in this chronicle, a table of what differs from the sheet already here.
  - **Duplicate Items/Locations/Rotes** - the same Skip/Overwrite/Import-as-new choice for a
    catalog entry already here by name.
  - **Flagged Traits** - a raw value the parser couldn't match exactly, with a **Suggestion**
    dropdown of close catalog matches, a **Keep as written** checkbox, and (if you can manage
    the catalog) **Also add to catalog**.
  - **Unresolved** - a raw value with no catalog match at all, offering only **Keep as
    written, unmatched** and, if you can manage the catalog, **Also add to catalog**.
  - **Players Needing a Match** - a player name from the file with no confirmed WordPress
    account yet, and any suggested matches.
- **Match Players** - the same players-needing-a-match list, or a note that this file's
  format carries no player identity to match at all (XML), or that everyone already matched.
- **Commit** - once everything is resolved: "Ready to commit job [id]. This creates every
  item, location, rote and character in one transaction - nothing is written unless all of it
  succeeds." **Commit** applies it; the result lists how many of each record type were
  processed, each character/item/location/rote by name (and its action, when not a plain
  create), and a note when the file also carried record types this tool doesn't import.

### Full Game File (`.gv3`)

A five-stage wizard: **1. Upload**, **2. Preview**, **3. Choose Target**, **4. Resolve**,
**5. Commit**.

- **Upload** - a file picker accepting a `.gv3` file (binary) and **Parse File**.
- **Preview** - the chronicle's title, the same review content as the `.gex` wizard's Preview
  stage, and **Not Imported by This Tool**: counts of queries, actions, plots, rumors, XP
  awards, templates, and calendar entries the file carries (and action/rumor allocation
  settings, when present) that this tool leaves out.
- **Choose Target** - **Create a new chronicle** (with a **Name** box) or **Merge into an
  existing chronicle** (a dropdown of this install's chronicles). Merging never overwrites the
  existing chronicle wholesale - only records that collide by name need a decision, next.
- **Resolve** - the preview, re-checked against the target you picked, so any duplicates
  shown are real for that specific chronicle.
- **Commit** - the same blocked-count pattern as the `.gex` wizard; once ready, a line naming
  the job and saying it's creating a new chronicle with the name you gave it, or merging into
  the chronicle you picked. **Commit** applies it; the result names the chronicle created or
  merged into and counts of characters, items, locations, and rotes processed.

## Common tasks

### Import a character or world-object exchange file

1. Open Beyond Elysium → Import and pick the chronicle.
2. Under **Upload**, choose a `.gex` file and click **Parse File**.
3. Resolve every duplicate character, duplicate item/location/rote, flagged trait, and
   unresolved trait shown.
4. Click **Next: Match Players**, then **Next: Resolve Traits**, then **Next: Commit**.
5. Click **Commit**.

### Resolve a flagged trait

1. Find it under **Flagged Traits**.
2. Pick the closest catalog name from **Suggestion**, or check **Keep as written** to keep it
   exactly as the file has it.
3. If you can manage the catalog and want future imports to match this automatically, also
   check **Also add to catalog**.

### Resolve a duplicate character

1. Find it under **Duplicate Characters**.
2. Read what it's matched by, and, if shown, what differs from the sheet already here.
3. Pick **Skip**, **Overwrite**, or **Import as a new, separate character**.

### Import a full chronicle from a game file

1. Open Beyond Elysium → Import → **Full Game File** tab (site admin only).
2. Under **Upload**, choose a `.gv3` file and click **Parse File**.
3. Click **Next: Choose Target**, then pick **Create a new chronicle** (and name it) or
   **Merge into an existing chronicle** (and pick one).
4. Click **Next: Resolve** and address every duplicate and flagged trait.
5. Click **Next: Commit**, then **Commit**.

### Review and accept a transfer from another chronicle

1. Open Beyond Elysium → Import. A waiting offer appears under **Waiting for Review**.
2. Click **Review**.
3. Make every decision the preview asks for.
4. Click **Accept Transfer**.

### End a visit from another chronicle's character

1. Find it under **Waiting for Review**.
2. Click **Send home** to end the visit, or **Keep for good** to make it yours permanently.

### Review and accept a Grapevine file a player sent in

1. Open Beyond Elysium → Import. A waiting file appears under **Waiting for Review**.
2. Click **Review**. The sender's name and email, their joining/visiting choice, and a
   verification note (if the file carries a code) show above the usual preview.
3. Make every decision the preview asks for. A duplicate character already belongs to someone
   else can be imported as new or refused, never overwritten.
4. Change **Joining**/**Visiting** if the sender's own choice isn't what you want, then click
   **Accept Sheet**.

### Refuse a player-sent file

1. Find it under **Waiting for Review**.
2. Click **Refuse**.
3. Add a note for the sender if you'd like (optional), then click **Refuse sheet**.

## Things to know

- **Nothing is written until you commit or accept.** Every stage before that is preview only,
  and a commit applies in one all-or-nothing transaction - a failure partway through leaves
  nothing behind.
- **Format is detected automatically.** Upload whichever `.gex` you have, binary or XML - you
  don't pick a format. A `.gv3` uploaded to Characters & World Objects, or a `.gex` uploaded to
  Full Game File, is rejected with a message telling you which tool to use instead.
- **A duplicate name is resolved once.** Two entries sharing a name in one file share one
  decision, so choosing Overwrite can't let a second entry silently overwrite what the first
  one just wrote.
- **Some record types have no home yet.** A file's queries, actions, plots, rumors, XP
  awards, templates, and calendar entries (and, for a full game file, action/rumor allocation
  settings) are counted and left out rather than guessed at.
- **Decisions reset with a fresh file or target.** Clicking Start Over, uploading a new file,
  or picking a different merge target clears every trait and duplicate decision you'd made -
  nothing carries over from a different job or a different target.
- **XML files carry no player identity.** A binary export includes player email addresses
  Beyond Elysium can match automatically; an XML export doesn't, so every character needs its
  player assigned by hand afterward, from the roster.
- **A creature type with no Grapevine equivalent** can still arrive inside an imported file,
  but can't be exported, transferred, or given a verification code afterward.
- **A transfer needs your review too.** Accepting one under Waiting for Review is the same
  preview-and-decide flow as a file, and nothing is added to this chronicle until you click
  Accept Transfer.
- **A player-sent file works the same way, with one extra rule.** A duplicate character that
  already belongs to someone else can never be overwritten from a player-sent file - only
  skipped or imported as a new, separate character.

## Troubleshooting

- **"A file upload is required."** Pick a file before clicking **Parse File**.
- **"This file is not a recognized Grapevine exchange file."** The file isn't a real `.gex`.
  If it's a full chronicle file, use the **Full Game File** tab instead.
- **"Full game file import (.gv3) is not yet supported - export a .gex exchange file
  instead."** You uploaded a `.gv3` file to Characters & World Objects. Switch to **Full Game
  File** (site admin only) and upload it there.
- **"This route accepts a full Grapevine game file (.gv3, binary) - a .gex exchange file goes
  through the regular Import page instead."** You uploaded a `.gex` file to Full Game File.
  Use Characters & World Objects instead.
- **Commit won't click.** Outstanding decisions remain - the count needed is shown above the
  button. Go back and resolve each one.
- **I don't see the Full Game File tab.** It needs a site administrator account, not just a
  Storyteller's.
- **I don't see this menu item at all.** It needs a Storyteller role in at least one
  chronicle, or an administrator account.

## Related

- [Send Sheet](transfer.md)
- [Send a Grapevine File](send-grapevine-file.md)
- [Items & Locations](world-objects.md)
- [Roles](roles.md)
- [Grapevine Import/Export](grapevine.md)
- [Admin Guide](../admin-guide.md#import)
- [Storyteller Guide](../st-guide.md#5-importing-from-grapevine)
