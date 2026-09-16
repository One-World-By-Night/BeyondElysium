# Roles

The five roles a chronicle can assign - HST, AST, Narrator, Boons, and Player - and what
each one can actually do there, separate from whatever your underlying WordPress account
allows.

## Who can use this

Everyone runs into roles, even without thinking about it - your role in a chronicle is what
decides which tabs and buttons you see there. Assigning or changing someone's role is a site
administrator's job, on Chronicle Access. Which chronicle a role applies to always matters:
the same person can hold a different role in every chronicle they're part of.

## How to get there

Roles aren't a screen of their own. A site administrator sets them on
[Chronicle Access](chronicle-access.md); everywhere else, your role in whichever chronicle is
currently selected quietly decides what you see - which Storyteller Toolkit tabs appear, what
a character's Sheet offers you, whether Query Tool or Approval Queue shows up at all.

## The screen

| Role | What it can do |
| --- | --- |
| HST | Everything in the chronicle - characters (including permanently deleting one), plots, approval rules, their own chronicle's catalog and template customization, and importing. Also the chronicle's own creature types, sub-faction restrictions, and new-character approval on Chronicle Setup. Renaming or deleting the chronicle, assigning roles on Chronicle Access, and Plot Features stay with a real site administrator account regardless of role - chronicle membership itself is out of an HST's reach too. Without accessSchema, the account needs WordPress's Editor role. |
| AST | Everything an HST can do except approval rules, catalog and template customization, permanently deleting a character, and the three Chronicle Setup settings above - an HST's alone. Keeps importing, transfers, editing characters, and bulk XP/status/reset operations. |
| Narrator | Plots and rumors - creating and running them, responding to player actions, generating rumors, and the plot, action, and rumor reports. Can allocate actions for any character in the chronicle, not just one of their own. Not character editing, deleting, or creating, and not the Query Tool, which reads whole sheets and is Storyteller-only. Without accessSchema, the account needs WordPress's Editor role. |
| Boons | What a chronicle calls its Harpy. Runs the boon ledger only - recording and repaying boons. Can still look a character up (to know who owes whom) and view reports, but holds no Storyteller power over characters or plots. |
| Player | Creates and edits their own characters, and submits their own actions - every edit goes through the same approval rules as anyone else's. Nothing outside their own characters, changes, and plot connections. |

A role only ever applies inside the one chronicle it was granted in. Holding HST in one
chronicle says nothing about what you can do in another - you might be a plain player there,
or not a member at all yet.

## Common tasks

### See what role you hold in a chronicle

1. Open [My Chronicle](my-chronicle.md) or [Storyteller Toolkit](storyteller-toolkit.md).
2. Open the **Chronicle** dropdown at the top - each chronicle you belong to is labeled with
   your role there.

### Check who holds a role in a chronicle

1. A site administrator opens [Chronicle Access](chronicle-access.md).
2. Read the **Role** column in the **Members** table.

### Change someone's role

1. A site administrator opens [Chronicle Access](chronicle-access.md).
2. Finds them under **Members** and picks a new value from their **Role** dropdown.

## Things to know

- **Roles are per chronicle, not per person.** The same WordPress account can be HST in one
  chronicle and a plain player in another - switching the **Chronicle** dropdown on My
  Chronicle or Storyteller Toolkit changes which role's tabs and tools you see.
- **Your WordPress account matters too.** A chronicle role is checked alongside what your
  underlying WordPress account can already do - both have to allow an action for it to go
  through. This is also why only a real site administrator account, never a chronicle role,
  can reach [Games](games.md), [Chronicle Access](chronicle-access.md), or Plot Features on
  [Chronicle Setup](chronicle-setup.md) - while creature types, sub-faction restrictions, and
  new-character approval on that same screen are your chronicle's HST's to set.
- **A player becomes a member automatically, not by hand.** Starting your first character in
  a chronicle you don't belong to yet is a request to join - it waits pending, every HST and
  AST is emailed, and a Storyteller setting the character active is what actually makes its
  player a member here. See [Chronicles](chronicles.md).
- **Sending an existing character's Grapevine file works the same way.** Anyone signed in can
  send one to any chronicle - joining it, or just visiting for a game - and a Storyteller
  accepting it is what makes the sender a member (for joining) or lets the character in (for
  visiting). See [Send a Grapevine File](send-grapevine-file.md).
- **A wider OWBN role system can grant access too.** If a chronicle runs accessSchema, its own
  role paths are checked first and can grant something this table doesn't cover - this table
  describes the fallback every chronicle has regardless. See
  [Chronicle Access](chronicle-access.md).

## Troubleshooting

- **I don't see a tab I expect.** You hold a narrower role in the chronicle currently selected
  than you thought - check the **Chronicle** dropdown, or ask an HST/AST to check your role.
- **"You don't hold a Storyteller role in this chronicle."** Exactly what it says - switch
  chronicles, or ask an HST/AST to check your role there.
- **I'm an HST and can't rename or delete my own chronicle.** That's reserved for a real site
  administrator account by design - see [Games](games.md).
- **A player says they can't do anything with a character they should own.** They may not be
  a member of this chronicle yet, or their first character here is still a pending join
  request waiting on a Storyteller.

## Related

- [Chronicle Access](chronicle-access.md)
- [Chronicle Setup](chronicle-setup.md)
- [Chronicles](chronicles.md)
- [Send a Grapevine File](send-grapevine-file.md)
- [My Chronicle](my-chronicle.md)
- [Storyteller Toolkit](storyteller-toolkit.md)
- [Approval Queue](approval-queue.md)
- [Boon Ledger](boon-ledger.md)
- [Query Tool](query-tool.md)
- [Games](games.md)
- [Storyteller Guide: Roles Reference](../st-guide.md#roles-reference)
- [Admin Guide: What an HST Can and Cannot Do](../admin-guide.md#what-an-hst-can-and-cannot-do)
