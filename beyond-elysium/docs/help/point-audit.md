# Point Audit

Every held trait, power, resource pool, and identity field on a character's sheet, priced line by line against the same rules a purchase uses - and, for anything that can't be priced, a plain reason why.

## Who can use this

Storytellers only (HST and AST). This is gated stricter than an ordinary sheet view, even for the character's own player: a player never sees this, even on their own character. The audit reads the character's complete data, Storyteller-only blocks included, and a total that reveals their value has to stay behind the same wall as the blocks themselves.

## How to get there

Character Sheet → pick **Point audit** in the actions list and click **Go** (Storyteller-only; the list doesn't offer it to anyone else).

## The screen

- **Close** - puts the audit away.
- A summary row: **Spent**, **Earned**, **Net** (Spent minus Earned), **XP of record** (the character's own recorded Earned minus Unspent), and **Variance** (Net minus XP of record).
- **Priced N of M lines.** - how much of the sheet this audit could actually price.
- A caveat sentence naming how many lines couldn't be priced and, when there is one, the most common reason.
- The rest of the report, grouped under the sheet's own section headings. Each held line is either:
  - **Priced** - its name (with a "×N" count above one), its XP cost, and, for a power bought
    outside your character's own type, a "(+N out-of-type)" note. A line that gives XP back (a
    Flaw, for example) shows its amount with a minus sign.
  - **Unpriced** - its name and a plain reason: "catalog item has no cost," "name not in
    catalog," "family not in catalog," "level has no cost," "custom, no catalog entry,"
    "identity field, no catalog cost," "resource pool has no pricing rule yet," or "held block
    not in catalog." A line may also carry "undeclared by stack" - the section holding it isn't
    part of this character's own creature-type template at all.

## Common tasks

### Check a character's XP accounting

1. Open the character's Sheet.
2. Pick **Point audit** and click **Go**.
3. Read the summary row, then the caveat sentence, before treating the total as anything final.

### Find out why a trait didn't count toward the total

1. Open **Point audit**.
2. Find the trait under its section - an unpriced line shows its reason right beside it.

## Things to know

- **This is never a bill.** A real sheet always has lines the catalog has no cost for yet - every identity field (Clan, Nature, and the like) is one of them, by design: it's listed, but it never prices. Read the coverage line and the caveat before treating the total as an answer.
- **Every price here comes from the same rules a purchase uses.** Nothing here is a second, separately-guessed number - if a held trait prices as 6 XP here, buying it fresh costs the same.
- **A resource pool gets a line whether it's held or not.** An unpriced pool (Blood, for most stacks) still gets a line - it just never prices.
- **A gap between Net and XP of record is normal**, not a sign of a problem - it grows with every line the catalog can't price yet.
- **Loads fresh every time you open it.** Nothing here is cached, so it always reflects the sheet as it stands right now.

## Troubleshooting

- **"Failed to load the point audit."** Refresh and try again.
- **"This character's creature type no longer exists, so its points can't be audited."** The character's creature type was removed before Beyond Elysium stopped allowing that - ask a site administrator.
- **I don't see this button.** Point audit is Storyteller-only - you need to be an HST or AST in this chronicle, even to see it on your own character.
- **A trait I expect to see priced shows a reason instead.** That's not a mistake on the sheet - it means the catalog itself has no cost recorded yet for that exact trait, family, or level.

## Related

- [Character Sheet](character-sheet.md)
- [Character Editor](character-editor.md)
- [Trait Lists](trait-editor.md)
- [Powers](power-editor.md)
- [Resource Pools & Identity Fields](pools-identity-editor.md)
- [Storyteller Guide](../st-guide.md#13-the-point-audit)
