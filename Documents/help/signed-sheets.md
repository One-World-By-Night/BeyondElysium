# Signed Sheets

Every printed character sheet or report is a real PDF your chronicle's own site generates - never a browser printout - and, once the site has a signing certificate, digitally signed: proof the trait values on it haven't been edited since it was generated.

## Who can use this

Anyone who can print a sheet or report gets a signed one automatically whenever signing is available - there's no separate setting to turn on for yourself. Setting up signing in the first place is a one-time job for whoever manages the site's hosting, not something a Storyteller or player can do from inside Beyond Elysium.

## How to get there

This isn't a screen of its own - every **Print / Export**, every report's **Generate PDF**, and every character export builds on the same signing setup. See [Print / Export](sheet-print-export.md) and [Reports](reports.md).

## The screen

**What a signature proves, and what it doesn't.** A digital signature proves the document hasn't changed since it was generated - the trait values, XP, everything on the page is exactly what the site produced. It does not prove the sheet is still current: the character could have changed since. Treat a signed sheet as a record of that moment, not a live view.

**"Signature valid, signer not trusted."** A self-signed certificate - the kind Beyond Elysium uses - makes PDF readers show this instead of a plain green checkmark. That's expected, not a problem: it means the file genuinely hasn't been altered, checked against a certificate your reader just hasn't been told to trust yet, the same way a new website's own certificate looks different the very first time you visit it. Trusting it once, from the reader's own signature panel, makes it read as fully valid afterward.

**Without a certificate set up.** Sheets and reports still print - every page comes back stamped UNSIGNED in red, and the file name ends `-unsigned.pdf`. An unsigned copy proves nothing about whether it was edited, so don't treat one as proof of a visiting character's sheet.

**Verification codes.** A separate way to prove a document is genuine, built into a Grapevine export and a chronicle transfer - not a signed PDF. Checking **Include verification code** before exporting embeds a short code and a link in the file; anyone holding it can check that code on [Verify Character](verify.md) to confirm the issuing chronicle really issued it, and see whether the character still matches it today (name, status, XP earned, XP unspent, and the sheet as a whole). The code isn't shown on screen - it's written into the file itself. An export's code never expires on its own; a transfer's code expires after 60 days if nobody accepts it, and stops working the moment its transfer does.

A signed PDF and a verification code check two different things: the PDF's signature proves the file itself is untouched, while a code proves the chronicle it claims really issued it, and tells you whether the character has since changed. A PDF doesn't carry a code, and a code doesn't come with a PDF.

## Common tasks

### Check whether a signed PDF is untouched

1. Open it in a PDF reader that shows signature status - most do automatically.
2. Trust the certificate once, if your reader asks - it reads as valid every time after.

### Check whether an exported file is genuine

1. Find its verification code inside the file.
2. Open [Verify Character](verify.md), enter the code, and click **Check**.

### Include a verification code in an export

1. Open the character's Sheet, pick **Export to Grapevine (.gex)**, and click **Go**.
2. Check **Include verification code**.
3. Click **Download .gex file**.

### Set up signing for a site (whoever manages hosting)

1. Generate a self-signed signing certificate and key.
2. Store both above the webroot (never inside it), and add `BE_PDF_SIGNING_CERT`, `BE_PDF_SIGNING_KEY`, and `BE_PDF_SIGNING_PASSPHRASE` to `wp-config.php`.
3. Print a sheet or report afterward - it comes back signed instead of stamped UNSIGNED.

See the [Storyteller Guide's signed-sheet section](../st-guide.md#11-signed-character-sheets) for the exact commands.

## Things to know

- **Signing is per site, not per chronicle.** Every chronicle on the same install shares the same certificate, once one is set up.
- **This replaces browser printing entirely.** There's one Print button, and it always produces a real PDF - signed, or stamped UNSIGNED.
- **A revoked verification code still gives a real result, not a plain error** - it tells you the issuing chronicle withdrew it, distinct from a code that never existed.
- **A deleted character's verification code still resolves** - it just reports every check as no longer matching, since the character it was issued for is gone.
- **Nothing private ever shows on Verify Character** - no full sheet, biography, notes, or the character's player, only the handful of facts on its checklist.
- **A report can be signed too.** Every one of the 20 reports follows the same signed/UNSIGNED rule as a character sheet.

## Troubleshooting

- **My PDF says UNSIGNED.** The site has no signing certificate configured yet - a hosting setup step, not a problem with the character. See [Print / Export](sheet-print-export.md).
- **"This code doesn't match any verification record."** Check it was typed correctly - an unknown, expired, and malformed code all show this same message.
- **"Too many checks from this connection."** Wait a minute and try again - Verify Character limits how often the same connection can check.
- **My reader says "signer not trusted."** Expected with a self-signed certificate - see What a Signature Proves, and What It Doesn't, above.

## Related

- [Print / Export](sheet-print-export.md)
- [Verify Character](verify.md)
- [Character Sheet](character-sheet.md)
- [Reports](reports.md)
- [Send Sheet](transfer.md)
- [Grapevine Import/Export](grapevine.md)
- [Storyteller Guide: Signed Character Sheets](../st-guide.md#11-signed-character-sheets)
- [Player Guide: Printing Your Sheet](../player-guide.md#8-printing-your-sheet)
