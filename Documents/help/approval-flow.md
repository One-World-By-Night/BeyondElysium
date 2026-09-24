# How Approval Works

How a change you submit moves from an edit on your own screen to something real on the
sheet: pending, then approved or rejected, with the rules that decide which changes need a
Storyteller at all.

## Who can use this

Every player's submitted changes go through this. Reviewing one - approving or rejecting it
- is a Storyteller's job (HST and AST); nobody else reviews a change. What actually needs
review, and what doesn't, is set by a Storyteller (on Approval Rules or directly on a schema
block) or by a site administrator (the chronicle's own overall default).

## How to get there

This isn't a screen of its own. You submit a change from the [Character Editor](character-editor.md);
a Storyteller reviews it on the [Approval Queue](approval-queue.md); what needs review in the
first place is set on [Approval Rules](approval-rules.md).

## The screen

**Two outcomes only.** A submitted change is either **auto-approved** - applied to the sheet
the instant you submit it, with nothing to wait for - or it's **pending**, sitting in the
Approval Queue until a Storyteller approves or rejects it. There's no separate coordinator
step: a rule's reason may say to get a coordinator's sign-off first, but the click that
actually approves a change is always a Storyteller's.

**What decides which one you get.** A rule can live at several levels of detail - a specific
range of values on one item or pool, one option on an identity field, one level of a leveled
power, a whole item or whole power, or a whole catalog block's own default. Whatever's most
specific to what you're actually submitting wins, falling back a level at a time until
something has an opinion; if nothing anywhere does, your chronicle's own overall default
(Pending by default, or Auto-approve by default) decides. A specific rule always wins over
that default, in either direction - switching a chronicle to auto-approve never silently
waves through something a Storyteller flagged for review, even one with no reason text
attached.

**A custom entry always needs a Storyteller.** Typing a name that isn't in the catalog -
where a section even allows it - always goes to a Storyteller, no matter how your
chronicle's rules are set. There's no catalog price to check it against, so nothing can
auto-approve it. It has no price of its own either: the Storyteller who approves it sets
one, and until then your preview says "Price set by a Storyteller on approval" instead of
showing a cost. It isn't free - it just hasn't been priced.

**Numbered powers price every rung.** A fresh purchase at level 3 is priced for levels 1, 2,
and 3 together, not level 3 alone - true whether the change ends up auto-approved or pending.

**XP is charged on approval, not on submission.** Nothing comes out of your unspent
experience until the change actually applies to the sheet - immediately, for something that
auto-approves, or whenever a Storyteller clicks Approve. Rejecting a change costs nothing
and leaves the sheet untouched. A Storyteller's own XP awards usually apply immediately,
since they already carry a Storyteller's authority. For homebrew, the amount is whatever the
Storyteller sets when they approve it.

**The queue is tied to what a Storyteller was actually shown.** If you resubmit a change
that's still pending, or someone else reviews it first, a Storyteller's Approve or Reject
click on the old version is refused and their queue reloads with what's really there - a
Storyteller can never approve something different from what they saw.

## Common tasks

### Check whether your change is still waiting

1. Open [My Chronicle](my-chronicle.md) → **Dashboard** → **My Pending Changes**.
2. Still listed there means still waiting. Not listed, but not on the sheet either? Check that
   character's [Change History](sheet-history.md) for whether it was approved or rejected.

### See why a specific change needs review (Storyteller)

1. Open the [Approval Queue](approval-queue.md).
2. Read the **Approval Reason** column for that row (or, on a phone, open **Details**).

### Set what needs review in your chronicle (Storyteller)

1. Open [Approval Rules](approval-rules.md), or edit the item, power, pool, or field directly
   on [Schema Blocks](schema-blocks.md).
2. Pick the block, then the specific item, power, level, value range, pool, or field option.
3. Choose an approval level and, optionally, a reason.
4. Save.

### Change what happens when no specific rule applies

1. Open [Approval Rules](approval-rules.md).
2. Under **Default Approval Policy**, choose **Pending by default** or **Auto-approve by
   default**.

## Things to know

- **Two levels only: `auto` and `st`.** There's no separate coordinator tier - a reason
  naming a coordinator's approval is still a Storyteller's own click.
- **The most specific rule always wins, in either direction.** A narrow rule set to auto still
  applies even under a chronicle set to Pending by default, and a narrow rule set to
  Storyteller review is never quietly skipped just because the chronicle defaults to
  auto-approve.
- **A resource pool's rule checks its permanent rating only.** Spending or regaining points
  during play never triggers review - only a permanent, XP-funded increase can.
- **An identity field's rule checks every value you pick.** For a field that allows more than
  one choice, the strictest requirement among your picks applies.
- **Approving several at once (Approve Selected) is the same click, repeated.** It applies
  the identical approval to every change you've checked - not a separate bulk mechanism with
  its own rules. The one exception is homebrew that still needs a price: it can't be
  approved in a batch, because nobody has said what it costs.
- **Not everything goes through this queue at all.** Some things a Storyteller does directly
  - an XP award, for instance - are recorded as already decided, since a Storyteller's own
  action already carries that authority.

## Troubleshooting

- **My change didn't show up on my sheet.** It's most likely pending Storyteller review -
  check [My Chronicle](my-chronicle.md) → Dashboard → My Pending Changes, or the character's
  [Change History](sheet-history.md).
- **Submit Changes won't click.** As a player, submitting would leave you with negative XP -
  reduce what you're buying, or ask a Storyteller for more, first.
- **A Storyteller says nothing happened when they clicked Approve.** Someone else already
  reviewed it, or you changed it after the queue loaded - the queue reloads with whatever's
  actually there now.
- **A rule doesn't seem to be applying.** Check it addresses the value actually being reached
  (never a change from before), and that nothing more specific overrides it.

## Related

- [Approval Queue](approval-queue.md)
- [Approval Rules](approval-rules.md)
- [Character Editor](character-editor.md)
- [Change History](sheet-history.md)
- [Dashboard](player-dashboard.md)
- [Schema Blocks](schema-blocks.md)
- [Roles](roles.md)
- [Storyteller Guide: Running the Approval Queue](../st-guide.md#4-running-the-approval-queue)
- [Admin Guide: Approval Rules](../admin-guide.md#approval-rules)
