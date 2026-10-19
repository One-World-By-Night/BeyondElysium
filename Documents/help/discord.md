# Discord

Connect a chronicle's Discord server, so players can link their account and read their sheet from Discord.

## Who can use this

Connecting and disconnecting is the Head Storyteller's. An Assistant Storyteller sees the connection status but not the controls. Any signed-in member can link their own Discord account once a chronicle is connected; a Storyteller approves the link.

## How to get there

- **Connecting:** [Chronicle Setup](chronicle-setup.md)'s Discord row.
- **Approving a link:** the Discord section on [Players](chronicle-players.md).
- **Linking your own account:** a confirm link the bot gives you after running `/link` in the Discord server, opening a panel on [My Chronicle](my-chronicle.md).

## The screen

- **Chronicle Setup's Discord row:**
  - Not connected: **Generate a connect code** - a one-time, 15-minute code and a ready-to-run `/connect` command, with a link to invite the bot when one is configured.
  - Connected: the server's name, when it was last heard from, and **Disconnect**.
- **Players' Discord section:**
  - **Waiting for a decision** - a confirmed link with **Approve** and **Decline**.
  - **Linked** - an approved link with **Revoke**.
  - Each row names the Discord account, the website account it's confirmed to, and the characters it carries.
- **My Chronicle's link panel** (only when you followed a confirm link): the Discord account's name, your characters in that chronicle to tick, and **Confirm**.

## Common tasks

### Connect a chronicle's Discord server

1. Open [Chronicle Setup](chronicle-setup.md) and open the Discord row.
2. Click **Generate a connect code**.
3. In the Discord server, run the `/connect` command shown, with the code.
4. The row shows **Connected** once the bot answers.

### Link your Discord account

1. In the Discord server, run `/link`.
2. The bot gives you a link. Open it.
3. Sign in if asked.
4. Tick the characters this Discord account should carry, then click **Confirm**.
5. A Storyteller approves it from [Players](chronicle-players.md).

### Approve or decline a link

1. Open [Players](chronicle-players.md).
2. Find the account under **Waiting for a decision**.
3. Click **Approve** or **Decline**.

### Disconnect

1. Open [Chronicle Setup](chronicle-setup.md)'s Discord row.
2. Click **Disconnect**.
3. Players stay linked; they just can't act from Discord until the chronicle connects again.

## Beyond connecting and linking

Once a player is linked, the bot is a second way into the chronicle itself, not just the website:

- **Sheets.** Read your own character, or any character a Storyteller names, with `/sheet`.
- **Temporary state.** Spend and regain pool points, take and heal wounds, with `/spend`, `/regain`, `/wound` and `/heal` - none of them need approval. `/status` shows pools, health, and tonight's losses.
- **Challenges.** Laws of the Night Revised challenges and static tests, settled on what each side claims, with `/challenge`, `/static`, `/test` and `/challenges`.
- **In-character posting.** Post as a character through a trigger, a lock, or `/as`.
- **Game night.** `/checkin`, `/aar`, `/downtime`, `/scene` and `/plot`; a Storyteller sets a linked player's room with `/room`.
- **Dice.** General notation or a World of Darkness pool, with `/roll`.

Every command runs as the real linked account, so it follows the exact same permissions and approval rules as the website. Run `/help` in the Discord server for the full list, or `/help topic:<area>` for one area in detail.

## Things to know

- **One server, one chronicle.** A Discord server connects to exactly one chronicle, and a chronicle to exactly one server.
- **A code works once and lasts 15 minutes.** A new one replaces the old.
- **Disconnecting keeps every link.** A Discord account is the same account whichever server it's reached from; reconnecting doesn't make anyone link again.
- **A Storyteller is approved at once.** Running `/link` as a Storyteller skips the waiting step.
- **Acting from Discord is acting as you.** Whatever the bot does on your behalf follows the exact same rules, approvals, and limits as doing it on the website yourself.

## Troubleshooting

- **The connect code didn't work.** Codes last 15 minutes and work once. Generate a new one.
- **"This chronicle already has a connected Discord server."** Disconnect it first, from Chronicle Setup.
- **My link says it's expired.** Run `/link` again in Discord for a fresh one.
- **I can't see the Connect button.** Only the Head Storyteller can connect or disconnect; an Assistant Storyteller sees the status only.

## Related

- [Chronicle Setup](chronicle-setup.md)
- [Players](chronicle-players.md)
- [My Chronicle](my-chronicle.md)
