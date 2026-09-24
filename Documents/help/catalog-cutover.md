# Switching a Site to the Declared Catalog

How to move a site from the old shared catalog to the per-creature one, what happens to
every sheet when you do, and how to undo it.

## Who can use this

A site administrator with WP-CLI on the server. There is no button for it in wp-admin. It
changes every character on the install at once, so it runs from the command line, where it
can ask you to confirm.

## New installs

A new install starts on the per-creature catalog, so there is nothing to switch and `plan`
on it reads 0 characters. This page is for a site that was set up before 1.3.4 and is still
on the shared lists: `wp be cutover status` says `declared: no`. A site that started on the
new catalog has no earlier state to return to, so `rollback` on it says so and changes
nothing.

## What it does

Older installs share a few sections across every creature type: Abilities, Merits, Flaws and
Werewolf rites. The newer catalog gives each creature type its own, with the right list, the
right prices and the right groupings for that creature. Switching an install over moves
every character's rows into the new sections.

For each character:

- **The rows move.** Everything held in a shared section goes to that creature type's own
  section, in the same order, with the same dots.
- **A custom entry that plainly is a catalog item becomes that item.** A row a player typed
  by hand - "Lore: Sabbat", "Brawl (Boxing)", "Meditiation" - picks up the real item's price
  and rules. Only a sure match counts: the same name apart from case or punctuation, a
  recorded former name, a trailing footnote mark, a ritual written in another common form,
  or a "Base: Label" entry, where the label becomes the specialization or a note. A guess
  never counts.
- **A catalog name takes the catalog's spelling.** If the old catalog said "Fortune-telling"
  and the new one says "Fortune-Telling", the row takes the new spelling and nothing else
  about it changes.
- **Everything else stays exactly as it was.** Homebrew the catalog has no answer for stays
  custom, shown and editable as before.
- **XP never changes.** Not earned, not unspent. Nothing is repriced and nobody is refunded.

Each character that changes gets one line in its history, something like "Catalog update: 24
rows moved to their new catalog sections, 7 custom entries matched to the catalog", with the
matches listed underneath, and a snapshot of the sheet as it was, taken before anything is
written. Changes waiting in the approval queue are pointed at the new sections. Templates
and creature types are switched over at the end.

## The commands

```
wp be cutover plan
wp be cutover apply --user=<you> --yes
wp be cutover rollback --user=<you> --yes
wp be cutover status
```

### plan

Read-only. It changes nothing anywhere. It prints how many characters would change, how many
rows would move, how many custom entries would match, how many stay custom and why, and any
**retention gaps**.

- `--game=<slug>` limits it to one chronicle.
- `--format=json` prints everything, for a script.
- `--csv=<path>` writes one line per custom entry: what it is now, what it would become,
  why, and the closest catalog names as suggestions. The file has character and player names
  in it. Keep it off any repository and out of email.

A retention gap is a catalog row a character holds that the new section can't find by name.
`apply` refuses to run while there are any, because switching would leave that row pointing
at nothing. The plan lists each one with its character.

### apply

Switches the install. Nothing is written if it refuses. It refuses when:

- the build has no declared catalog to switch to,
- another run holds the lock (a stuck lock clears itself after 30 minutes),
- a creature type points at a section the database doesn't have,
- there is a retention gap, or
- a character belongs to no chronicle, so the plan would have skipped it.

Each character is re-keyed in its own transaction. If any of them fails, the install is not
switched and the ones already done are safe; fix the failures and run `apply` again. It only
flips the site over once every character is done. A second `apply` on a switched install
re-keys nothing; it only repairs a template restored from an old export and any change
queued by an older path.

While it runs, which is a few seconds, people can read but not save. A save gets "The site
is being moved to the new catalog and can't save anything for a moment. Try again in a
minute." That is what keeps a change from landing on a sheet that has already moved. If a
run dies, the hold ends by itself after two minutes.

When it finishes, run `plan` again. On a switched site it should say 0 characters would
change. Anything else is a character still holding a row under an old block, and it needs a
look before anyone edits it.

### rollback

Puts every character back from the snapshot taken before it was changed, and switches
templates and creature types back. If a character's sheet has changed since the switch,
because a Storyteller approved something after it, rollback stops, changes nothing and names
them. `--force` restores them anyway, which erases what was approved after the switch, and
records that it did. A template that changed since the switch, by hand or in a plugin
update, is not put back from the copy: it keeps its layout, its sections are pointed back at
the old blocks, and the command names it.

### status

Whether the site is switched, when, by whom, how many characters and templates were touched,
and whether a run holds the lock.

## What to expect

On real chronicles, about four in ten custom entries match. The rest stay custom, which is
normal, and they keep working. An entry stays custom when the catalog has nothing by that
name, when two catalog items could be it, when matching it would put two rows on one item,
when a Storyteller set a price on it, or when it is an editor's unsaved working copy.

Run `plan` first and read it. On a few hundred characters `apply` takes seconds.

## What players see

Rows in the right sections, custom entries that matched now shown as the catalog item with
any label in brackets, and one "Catalog update" line in the character's [change
history](sheet-history.md). No XP moves.

## Things to know

- **Take a database backup first.** Rollback is exact, but it is not a substitute for one.
- **Switch the site. Don't delete and re-import.** Measured on 454 real character exports:
  importing into a site already on the new catalog matched about 3 in 100 of the custom
  entries that the switch matches about 37 in 100 of, because the importer doesn't read
  "Lore: Sabbat"-style names the way the switch does.
- **A network runs it one site at a time.** On a multisite install, run it once for each
  subsite that has Beyond Elysium active, against that subsite's own URL.
- **Nothing here touches players' XP, sheets outside the shared sections, or anything else
  on the site.**

## Related

- [Change History](sheet-history.md)
- [Point Audit](point-audit.md)
- [Approval Queue](approval-queue.md)
- [Admin Guide](../admin-guide.md)
