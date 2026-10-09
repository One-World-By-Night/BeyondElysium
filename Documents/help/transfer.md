# Send Sheet

Sending one of your chronicle's characters to visit or move to another chronicle, and the controls for every open visit. A character on a visit is never away from your own chronicle - it stays active here the whole time, and can be active at any number of other chronicles at once, one visit per host.

## Who can use this

Storytellers only (HST and AST). A player never sees this panel, even on their own character - transferring is a Storyteller-to-Storyteller arrangement on both ends. A player can still get their own character to another chronicle without a Storyteller's help on the sending end - see [Send a Grapevine File](send-grapevine-file.md) - but accepting either kind of arrival needs the same Storyteller standing, on the Import page - see below.

## How to get there

Character Sheet → pick **Send Sheet** in the actions list and click **Go**. Accepting a character someone else has sent you is a separate step, on the Import page - see [Import](import.md) → Waiting for Review.

## The screen

What you see here depends on whether this character has any open visits, and from which side.

### Sending (this character is your own)

- Every one of this character's open outbound visits lists its own status line and its own controls - a character can have any number open at once, one per host.
  - **offered** (sent, not yet picked up by the host): **Cancel transfer**.
  - **visiting** (the host has accepted): a status line naming the host chronicle and whether it's kept current, **Keep current: on**/**Keep current: off**, and **End visit**.
  - A kept-current visit's status line also shows when it last delivered, or "can't reach it" once a day has passed with nothing confirmed.
- **Download transfer document** - the exchange file the most recent send produced, any time one is available.
- An **Initiate Transfer** form always stays open below the list, so you can send this same character to another host at any time, even while other visits are already open.

### Visiting (this character arrived here from another chronicle)

- A status line naming the home chronicle, when the visit began, and whether it's kept current - or "can't reach it" once a day has passed with nothing confirmed.
- **Ask to keep current**, if neither side has - home's own Storytellers agree to it from here or from their own copy, whichever side asks.
- Once home has asked: **Agree to keep current**. Once agreed: **Turn off keep current**, which tells home too.
- A kept-current visit also shows **Share a note with home** (a free-text message, no sheet effect - see [Common tasks](#send-a-free-text-note-home) below) and an **Update log**, listing when a sheet update last landed and what it changed.
- While **visiting**: **Send home** and **Keep for good**.

An "Also active at ..." notice appears above the character's header any time it has an open visit elsewhere, whether or not this panel is open; a locally-hosted visiting character gets its own "Visiting from ..." notice instead. The character roster carries the same signal as a badge on the character's name - "Also active elsewhere," or "Can't reach a visit" once one has gone quiet.

## Common tasks

### Send a character to another chronicle

1. Open the character's Sheet, pick **Send Sheet**, and click **Go**.
2. Fill in **Host site URL** and **Host chronicle slug** if you know them, or leave both blank to just download the file.
3. Click **Initiate Transfer**.
4. If you left the host fields blank, click **Download transfer document** and send the file to the other chronicle yourself.

### Cancel a visit you haven't sent yet

1. Open **Send Sheet** while the visit is still **offered**.
2. Click **Cancel transfer**.

### Accept a character another chronicle has sent you

1. Go to **Import**. A waiting offer appears under **Waiting for Review**.
2. Click **Review**. Make every decision the preview asks for, the same as reviewing an uploaded file - a character already here under the same identity shows what's changed.
3. Click **Accept Transfer**.

### Keep a visiting character current

1. On either side's own **Send Sheet** panel, while the visit is **visiting**, click **Keep current: on** (home) or **Ask to keep current** (host).
2. The other side sees the request and clicks **Agree to keep current**.
3. From then on, any change made to the character at home reaches the host on its own within a few minutes, and the host's own panel shows when it last delivered. Either side can turn it off again, which tells the other.

### Send a free-text note home

1. On the host's own **Send Sheet** panel, with a kept-current visit agreed on both sides, type into **Share a note with home** and click **Send note**.
2. The note arrives at home as a pending change - Storytellers-only, no sheet effect - for a home Storyteller to approve or refuse under Approval Queue.

### End a visit

1. Open **Send Sheet** on the visiting character's sheet (or find it under Waiting for Review).
2. Click **Send home** to end the visit and let the character return, or **Keep for good** to make it yours permanently.

### Send a visiting character back home

1. After clicking **Send home**, open that character's own **Send Sheet** panel again - the **Initiate Transfer** form is always there, whether or not another visit is still open.
2. Fill in the original home chronicle's site and slug (or leave both blank to download and send the file yourself) and click **Initiate Transfer**.
3. The home chronicle reviews it like any other incoming transfer, and accepting it closes out their own record of the character having been away.

## Things to know

- **Both ends approve.** Sending is your own approval on the home side. Nothing is added to the other chronicle until one of their Storytellers reviews the offer and accepts it - there's no automatic acceptance, no matter who sends it.
- **A visit never takes the character away.** It stays active in your own chronicle the whole time a visit is open elsewhere - it can be active at any number of chronicles at once, one visit per host.
- **Keeping current needs both sides.** Either side can ask; the visit only starts actually syncing once the other side agrees. Turning it off, from either side, tells the other and stops it immediately.
- **A kept-current host's own edits don't land directly.** While a visit is kept current and agreed, the host's copy is read-only to a player there - a Storyteller's own change or XP award still goes through, but is forwarded to home as a pending change rather than applied locally. A host's free-text note works the same way.
- **A returning character shows what changed.** When a character comes back to a chronicle that already holds a copy of them, the review lists what's different - details, experience totals, and every trait added, removed, or changed - so you know what accepting will do before you do it.
- **A transfer carries the sheet, items, and locations - not everything.** Equipment and Locations connect to the receiving chronicle's own catalog by name; boons stay in the character's import history rather than being recreated. Portraits, sheet style, plot history, and the background-use ledger don't travel.
- **An unclaimed offer expires.** A transfer nobody accepts or confirms - either side - expires after 60 days, and its verification code stops working with it.
- **Some creature types can't travel.** A creature type with no Grapevine equivalent - one an administrator added to this install without a matching export format - can't be exported, transferred, or given a verification code at all.
- **Sending a character emails the other side.** The receiving chronicle's HSTs and ASTs get an email the moment an offer arrives, so it doesn't sit unseen.
- **Deleting a character closes its transfers.** Every offer still waiting can't be accepted afterward, and every open visit ends.
- **A host that renames itself doesn't break an open visit.** Home's own record updates to the new name automatically the next time anything passes between the two sides.

## Troubleshooting

- **"This character already has an open outbound transfer."** It already has an open visit to that same host - finish or cancel it before sending there again. A visit to a *different* host is unaffected; any number can be open at once.
- **"This character's creature type has no Grapevine equivalent, so it cannot be exported or transferred."** This creature type has no matching export format. Nothing can be done from here.
- **The host never confirmed anything.** The document downloads regardless, so you can still send it yourself - the host site may be unreachable, or the transfer may be waiting for their Storytellers under their own Waiting for Review.
- **Accept Transfer won't click.** The review still has decisions outstanding - the count needed is shown; make each one first.
- **"Can't reach it" on a kept-current visit.** A full day has passed with no confirmed delivery or receipt - the other site may be down, or genuinely unreachable. It clears on its own the next time an update gets through; nothing needs doing from here.
- **A note didn't go anywhere.** Sharing a note needs a kept-current visit agreed on both sides - it quietly does nothing otherwise, rather than erroring.
- **I don't see this panel at all.** It's Storyteller-only, and only shown on a sheet you can manage.

## Related

- [Character Sheet](character-sheet.md)
- [Import](import.md)
- [Approval Queue](approval-queue.md)
- [Send a Grapevine File](send-grapevine-file.md)
- [Verify Character](verify.md)
- [Grapevine Import/Export](grapevine.md)
- [Storyteller Guide](../st-guide.md#5-importing-from-grapevine)
