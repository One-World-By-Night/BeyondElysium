# Players

Review join requests, invite a player by email with their characters, see who hasn't signed in yet, and manage your players and which characters are theirs, all on one screen. The HST and the AST both do this here. Staff roles (HST, AST, Narrator, Harpy) are set on [Chronicle Access](chronicle-access.md) by a site administrator. The tab shows on every chronicle; a chronicle linked to OWbN roles also grants and revokes that role as players are added and removed, and its join link signs an applicant in through OWbN.

## Who can use this

The chronicle's HST and AST, on every chronicle. A Narrator, the Harpy and players don't see this tab.

## How to get there

Open the [Storyteller Toolkit](storyteller-toolkit.md), pick the chronicle, then choose **Players**.

## The screen

- **Join requests** - the chronicle's join link, with a **Copy link** button, and every account that has asked to join: their message, a character they started or a Grapevine file they sent if either carries the request, and **Approve**/**Refuse** on a waiting one. A note typed before **Refuse** goes to the applicant. See [Joining a Chronicle](joining.md) for the applicant's own side of this.
- **Invite a player** - a box for their email address, a list of the chronicle's player characters to tick, and a box, ticked by default, to email them an invitation. Typing three letters of a name instead of an address looks up existing accounts; **Use this email** fills in theirs.
- **The character list** - free characters first, then those waiting for someone's email, then those already linked to a player (you can't tick those). Type in its filter box to narrow it by name.
- **Waiting to sign in** - every invite nobody has signed in for yet: the email, the characters waiting for it, who sent it and when, with a **Cancel** button.
- **Players** - everyone who is a player in this chronicle, each with their characters. Each character has **Unlink**; each player has **Add characters** and **Remove**.
- A line under **Players** names the OWbN role that goes with being a player here, such as `chronicle/kony/player`.
- After anything you do, a message says what happened: who became a player, which characters were linked, which were left alone and why.

## Common tasks

### Review a join request

1. Read their message, and open a character or file they carry if one's attached.
2. Click **Approve** to make them a player - a character they started goes active, a file they sent still needs its own review from Import.
3. Or type a note and click **Refuse** - a character they started for the request is deleted, and the note is emailed to them.

### Invite a player with their characters

1. Type their email address.
2. Tick their characters. Use the filter box to find them by name.
3. Leave **Email them an invitation** ticked if they should get an email, then click **Invite**.
4. Read the message. If they already have an OWbN account with that email, they are a player now and their characters are theirs. If not, the invite waits under **Waiting to sign in**, and the first time they sign in through OWbN with that email address they join the chronicle and find their characters waiting.

### Give a player more characters

1. Click **Add characters** beside them.
2. Tick the characters, then click **Link ticked characters**.

### Take a character away from a player

1. Click **Unlink** on the character and confirm. The character stays in the chronicle, linked to nobody, ready to give to someone else.

### Cancel an invite

1. Click **Cancel** beside it under **Waiting to sign in** and confirm. Its characters stop waiting for that email.

### Remove a player

1. Click **Remove** beside them and confirm.
2. They are no longer a player here. Their characters stay exactly as they are.

## Things to know

- **Join requests can be turned off** on [Chronicle Setup](chronicle-setup.md). Off, the chronicle leaves the join list and its join link refuses a new request; one already waiting is still reviewed here.
- **Approving a character or accepting a file is the one way in.** Both make the account a player and close the request at the same time - there's no separate "approve the request" step beyond that for either.
- **Approving a request that carries a Grapevine file refuses, with a note pointing at Import** - the file needs its own resolutions/duplicates review there, which a bare approve can't supply. Refuse still works, deleting nothing of the file itself.
- **The email has to match.** An invite is accepted by the account whose email address is the one you typed, whatever the capitals. If they sign in with a different address, the invite keeps waiting; cancel it and invite the address they use, or link their characters by hand once they're a player.
- **The invitation email goes out once,** when you invite, and only if the box is ticked. It names your chronicle and you, and links to the chronicle with OWbN sign-in.
- **A character already linked to someone else is never moved.** It is listed in the message and left alone; unlink it from them first.
- **Invites work across the network.** The same person can be invited by several chronicles; they join each one the first time they sign in.
- **Staff are never changed here.** Inviting an HST or AST links their characters and leaves their role alone, and **Remove** only takes out players.
- **OWbN roles follow.** Becoming a player here also grants the chronicle's player role in OWbN, and removing them takes it away. If OWbN refuses, the message says why; the player is still added here, and an OWbN admin can grant the role.
- **A pending email set on a character** from the Characters list is an invite too. It shows under **Waiting to sign in** and links the same way.
- **Linking is recorded.** Each character's history shows who it was linked to or unlinked from.
- **Removing is not deleting.** Their account, their characters and their place in any other chronicle are untouched.

## Troubleshooting

- **There is no Players tab.** You are not the chronicle's HST or AST.
- **"This request carries a Grapevine file - review and accept it from Import..."** Approve refused on purpose - go review the file from Import, which closes the request too once you accept it.
- **An invite is still waiting after they signed in.** They used a different email address. Check the address on their OWbN account.
- **"... is linked to ..., so it was left alone."** Unlink that character from the other player first, then add it again.
- **"OWbN did not grant ..."** The player is added here. Pass the message to an OWbN admin, who can grant the role.

## Related

- [Joining a Chronicle](joining.md)
- [Storyteller Toolkit](storyteller-toolkit.md)
- [Characters](character-list.md)
- [Chronicle Setup](chronicle-setup.md)
- [Chronicle Access](chronicle-access.md)
- [Roles](roles.md)
