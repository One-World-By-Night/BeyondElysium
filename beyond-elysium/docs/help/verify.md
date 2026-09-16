# Verify Character

A public page that checks a verification code and reports whether the document that carried
it - an exported character file, or a chronicle transfer - is genuine, and whether it still
matches the character today.

## Who can use this

Anyone. No account and no chronicle membership are needed - this page is open to the public,
the same as the code printed or embedded in the document it checks.

## How to get there

Open the Verify Character page directly, or follow a link that already carries a code (one
embedded in an exported file will point here). Ask a Storyteller or your site admin for the
page's address if you don't have a link.

## The screen

- **Verification code** - a text box (placeholder "XXXX-XXXX") and **Check**. If the page's
  own web address already carries a code, it checks automatically on open.
- The result, once checked:
  - A banner saying the attestation is genuine, or that it's been revoked by its issuing
    chronicle and should no longer be treated as valid.
  - **Character**, **Creature type**, **Status**, **Experience** (earned / unspent), **Issued
    by** (a link to the issuing chronicle), **Issued on**, and **Document type** (Grapevine
    export or Chronicle transfer) - exactly what was on the document the day it was issued,
    never a live read of the character.
  - **Still matches the character today?** - a checklist (Name, Status, XP earned, XP
    unspent, Full sheet), each marked yes or no, plus when it was checked. Not shown for a
    revoked attestation.

## Common tasks

### Check a code

1. Open the Verify Character page.
2. Type the code into **Verification code** (a hyphen in the middle is optional).
3. Click **Check**.

### Check a code that's already in a link

1. Open the link. The page checks it automatically and shows the result.

## Things to know

- **The code isn't shown on screen when a character is exported.** It's written into the
  file itself - a Storyteller or anyone reading the exported document can find it there,
  along with a direct link to this page.
- **A genuine code can still say things don't match.** "Genuine" means the document really
  was issued by the chronicle it claims - it says nothing about whether the character has
  changed since. Read the checklist for that.
- **A deleted character reports as fully changed**, not as an error - every checklist item
  shows no once the character it was issued for no longer exists.
- **A code never expires on its own once issued for an export.** A chronicle transfer's code
  is the exception - it expires if nobody accepts it within 60 days.
- **Nothing private is shown.** This page never reveals the full sheet, biography, notes, the
  character's player, or any Storyteller-only content - only the handful of facts listed
  above and whether they still match.
- **Checks are rate-limited.** Too many checks in a short time from the same connection are
  turned away briefly - this protects the service, not any one code.
- **A Storyteller reviewing a player-sent Grapevine file sees this same check automatically.**
  When such a file carries a code, its own review screen checks it against the issuing
  chronicle without anyone visiting this page by hand - see
  [Send a Grapevine File](send-grapevine-file.md).

## Troubleshooting

- **"This code doesn't match any verification record."** Check it was typed correctly. An
  unknown, expired, or malformed code all show this same message - there's no way to tell
  which from here.
- **"Too many checks from this connection."** Wait a minute and try again.
- **"This attestation has been revoked by its issuing chronicle."** The chronicle that issued
  it has withdrawn it - treat the document as no longer valid, even though the code itself
  still resolves.
- **Every checklist item says no.** Either the character has genuinely changed since this
  document was issued, or the character no longer exists.

## Related

- [Character Sheet](character-sheet.md)
- [Print / Export](sheet-print-export.md)
- [Send Sheet](transfer.md)
- [Send a Grapevine File](send-grapevine-file.md)
- [Signed Sheets](signed-sheets.md)
- [Player Guide](../player-guide.md#8-printing-your-sheet)
