# Send Sheet

Sending one of your chronicle's characters to visit or move to another chronicle, and the controls for a character who's currently away or currently visiting.

## Who can use this

Storytellers only (HST and AST). A player never sees this panel, even on their own character
- transferring is a Storyteller-to-Storyteller arrangement on both ends. A player can still get their own character to another chronicle without a Storyteller's help on the sending end - see [Send a Grapevine File](send-grapevine-file.md) - but accepting either kind of arrival needs the same Storyteller standing, on the Import page - see below.

## How to get there

Character Sheet → pick **Send Sheet** in the actions list and click **Go**. Accepting a character someone else has sent you is a separate step, on the Import page - see [Import](import.md) → Waiting for Review.

## The screen

What you see here depends on whether this character is currently free, sending, or visiting.

### No open transfer

A form:

- **Host site URL** and **Host chronicle slug** - the other chronicle's web address and chronicle identifier. Leave both blank to just get the exchange file, if you'd rather send it yourself (by email, for instance).
- **Initiate Transfer** - builds the exchange document and, if you filled in a host, sends it there directly.

### Sending (this character is your own, currently away)

- A status line naming the transfer's state and the host chronicle, once one has confirmed.
- While **pending** (sent, not yet picked up by the host): **Mark received abroad** (once you've confirmed with the other chronicle that it arrived) and **Cancel transfer**.
- Once **abroad** (confirmed received): **Release permanently** - gives the character up for good.
- **Download transfer document** - the exchange file this transfer sent, any time it's available.

### Visiting (this character arrived here from another chronicle)

- A status line naming the home chronicle and when the visit began.
- While **visiting**: **Send home** and **Keep for good**.

A travelling or visiting notice also appears above the character's header any time a transfer touches them, whether or not this panel is open.

## Common tasks

### Send a character to another chronicle

1. Open the character's Sheet, pick **Send Sheet**, and click **Go**.
2. Fill in **Host site URL** and **Host chronicle slug** if you know them, or leave both blank to just download the file.
3. Click **Initiate Transfer**.
4. If you left the host fields blank, click **Download transfer document** and send the file to the other chronicle yourself.

### Confirm a character arrived, once the host has it

1. Open **Send Sheet** on the sending character's sheet.
2. Click **Mark received abroad**.

### Cancel a transfer you haven't confirmed yet

1. Open **Send Sheet** while the transfer is still **pending**.
2. Click **Cancel transfer**.

### Accept a character another chronicle has sent you

1. Go to **Import**. A waiting offer appears under **Waiting for Review**.
2. Click **Review**. Make every decision the preview asks for, the same as reviewing an uploaded file - a character already here under the same identity shows what's changed.
3. Click **Accept Transfer**.

### End a visit

1. Open **Send Sheet** on the visiting character's sheet (or find it under Waiting for Review).
2. Click **Send home** to end the visit and let the character return, or **Keep for good** to make it yours permanently.

### Send a visiting character back home

1. After clicking **Send home**, open that character's own **Send Sheet** panel again - it now offers a fresh **Initiate Transfer** form, since the visit here has ended.
2. Fill in the original home chronicle's site and slug (or leave both blank to download and send the file yourself) and click **Initiate Transfer**.
3. The home chronicle reviews it like any other incoming transfer, and accepting it closes out their own record of the character having been away.

## Things to know

- **Both ends approve.** Sending is your own approval on the home side. Nothing is added to the other chronicle until one of their Storytellers reviews the offer and accepts it - there's no automatic acceptance, no matter who sends it.
- **A returning character shows what changed.** When a character comes back to a chronicle that already holds a copy of them, the review lists what's different - details, experience totals, and every trait added, removed, or changed - so you know what accepting will do before you do it.
- **A transfer carries the sheet, items, and locations - not everything.** Equipment and Locations connect to the receiving chronicle's own catalog by name; boons stay in the character's import history rather than being recreated. Portraits, sheet style, plot history, and the background-use ledger don't travel.
- **An unclaimed offer expires.** A transfer nobody accepts or confirms - either side - expires after 60 days, and its verification code stops working with it.
- **Some creature types can't travel.** A creature type with no Grapevine equivalent - one an administrator added to this install without a matching export format - can't be exported, transferred, or given a verification code at all.
- **Sending a character emails the other side.** The receiving chronicle's HSTs and ASTs get an email the moment an offer arrives, so it doesn't sit unseen.
- **Deleting a character closes its transfers.** An offer still waiting can't be accepted afterward, and an open visit ends.

## Troubleshooting

- **"This character already has an open outbound transfer."** It's already travelling - finish or cancel that transfer before starting another.
- **"This character's creature type has no Grapevine equivalent, so it cannot be exported or transferred."** This creature type has no matching export format. Nothing can be done from here.
- **The host never confirmed anything.** The document downloads regardless, so you can still send it yourself - the host site may be unreachable, or the transfer may be waiting for their Storytellers under their own Waiting for Review.
- **Accept Transfer won't click.** The review still has decisions outstanding - the count needed is shown; make each one first.
- **I don't see this panel at all.** It's Storyteller-only, and only shown on a sheet you can manage.

## Related

- [Character Sheet](character-sheet.md)
- [Import](import.md)
- [Send a Grapevine File](send-grapevine-file.md)
- [Verify Character](verify.md)
- [Grapevine Import/Export](grapevine.md)
- [Storyteller Guide](../st-guide.md#5-importing-from-grapevine)
