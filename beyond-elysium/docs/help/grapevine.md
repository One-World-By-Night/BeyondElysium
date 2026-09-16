# Grapevine Import/Export

How a character - or a whole chronicle - moves between Beyond Elysium and Grapevine 3.01, or
between two Beyond Elysium chronicles: what a file actually carries across, and what it
can't.

## Who can use this

Exporting your own character is open to any player. Exporting anyone else's, and every
import, is Storyteller work (HST and AST); bringing in a full game file needs a site
administrator account. Checking whether a document is genuine, on
[Verify Character](verify.md), is open to anyone holding a code - no account needed.

## How to get there

[Print / Export](sheet-print-export.md) on a character's Sheet exports one character.
[Import](import.md) brings a file, or another chronicle's transfer offer, in. A character's
own [Send Sheet](transfer.md) panel sends it to another chronicle directly.

## The screen

**The file itself.** Beyond Elysium reads and writes the same exchange format (`.gex`)
Grapevine 3.01 uses, plus Grapevine's own full chronicle file (`.gv3`) for bringing in a whole
chronicle at once. A chronicle-to-chronicle transfer uses the same `.gex` document, sent
directly between two Beyond Elysium sites instead of downloaded and re-uploaded by hand.

**What travels with a character:**

- Identity fields, traits, powers, and resource pools - everything Grapevine has a place for.
- Items and locations the character holds - each connects to the receiving chronicle's own
  catalog entry of the same name, added by name if it isn't there yet.
- Total experience earned and unspent.
- Background and Notes, with their formatting intact - Grapevine has no concept of rich text,
  so opening the file elsewhere may show stray formatting marks around bold text or lists.

**What doesn't travel:**

- Boons - kept in the character's own import history at the receiving end, never recreated as
  a live boon there.
- Portrait, sheet appearance customization, plot history, and the background-use ledger.
- Individual experience history entries - only the totals cross over; each chronicle keeps its
  own log from that point on.
- Storyteller-only text and Storyteller-only blocks, unless a Storyteller specifically
  includes them on their own export - a player's own export is always stripped exactly like
  their own view of the sheet. See [Storyteller-Only Content](storyteller-only.md).

**Creature types.** Every creature type Beyond Elysium ships with can be exported,
transferred, and imported. The one exception is a creature type an administrator added to
this install without a matching export format - exporting, transferring, or issuing it a
verification code is refused outright. Bête is a special case: Grapevine has no Bête, so it
travels as the Fera it shares every block with, and comes back as a Bête in Beyond Elysium.

**Duplicates on import.** Bringing in a character (or item, location, or rote) that already
exists - by name, or by the character's own identity when the file carries one - asks you to
**Skip** it, **Overwrite** the one you have, or **Import as a new, separate** record. A
character matched in a *different* chronicle on the same site can only be skipped or imported
as new, never overwritten from yours. Two entries sharing a name in one file share one
decision, so the second can't silently overwrite what the first just wrote.

**Transfers specifically.** A transfer is the same `.gex` exchange, but both sides have to
agree: sending it is your own approval on the home side, and nothing is added to the other
chronicle until one of their Storytellers reviews the offer and accepts it. See
[Send Sheet](transfer.md).

**Sending your own file in directly.** A player can also just send an exported file straight to
a chronicle - joining it, or visiting for a game - without a Storyteller on the sending end at
all. It's reviewed exactly like an uploaded import, and nothing is added until a Storyteller
there accepts it. See [Send a Grapevine File](send-grapevine-file.md).

**Verification.** A verification code can be embedded in an export, or is issued automatically
for a transfer, so anyone holding the file can confirm it's genuine and see whether it still
matches the character. See [Signed Sheets](signed-sheets.md).

## Common tasks

### Export your own character

1. Open your character's Sheet.
2. Pick **Export to Grapevine (.gex)**, click **Go**, then **Download .gex file**. See
   [Print / Export](sheet-print-export.md).

### Bring a character in from a file

1. Open [Import](import.md) and upload the `.gex` file.
2. Resolve every duplicate and flagged trait.
3. Commit.

### Send a character to another chronicle

1. Open the character's Sheet, pick **Send Sheet**, and click **Go**.
2. Fill in the other chronicle's details, or leave them blank to just download the file. See
   [Send Sheet](transfer.md).

### Check whether an exported file is genuine

1. Open [Verify Character](verify.md) and enter its verification code.

## Things to know

- **Overwrite keeps what the file doesn't carry.** Importing over an existing character
  replaces what the file provides and leaves the rest of the sheet - a section your chronicle
  added, or anything Grapevine has no place for - exactly as it was.
- **Nothing is written until you commit or accept.** Every preview stage is read-only, and a
  commit applies in one all-or-nothing pass.
- **A verification code, once issued for an export, never expires on its own** - a transfer's
  code is the exception, and expires after 60 days if nobody accepts it.
- **Format is detected automatically.** Upload whichever `.gex` you have - the Import page
  rejects a `.gv3` file with a message pointing you to the Full Game File tool instead, and a
  `.gex` uploaded there the other way around.
- **A binary `.gex` carries player email addresses** Beyond Elysium can match automatically to
  an account; an XML `.gex` doesn't, so every character needs its player assigned by hand
  afterward.

## Troubleshooting

- **"This character's creature type has no Grapevine equivalent, so it cannot be exported or
  transferred."** An administrator-added creature type with no matching export format -
  nothing can be done from here.
- **"This file is not a recognized Grapevine exchange file."** The file isn't a real `.gex` -
  if it's a full chronicle file, use Full Game File instead.
- **My export doesn't have my Storyteller-only text in it.** Expected on a player's own export
  - a Storyteller can include it on their own export of the same character.
- **A traded character seems to be missing something.** Check What Doesn't Travel above before
  assuming something went wrong - some things are never carried by design.

## Related

- [Import](import.md)
- [Send Sheet](transfer.md)
- [Send a Grapevine File](send-grapevine-file.md)
- [Print / Export](sheet-print-export.md)
- [Verify Character](verify.md)
- [Signed Sheets](signed-sheets.md)
- [Items & Locations](world-objects.md)
- [Storyteller-Only Content](storyteller-only.md)
- [Storyteller Guide: Importing from Grapevine](../st-guide.md#5-importing-from-grapevine)
