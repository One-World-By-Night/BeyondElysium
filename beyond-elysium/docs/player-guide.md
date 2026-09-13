# Player Guide

This guide covers what a player does day to day: creating a character, editing it,
tracking experience, and following plots. If you're running a chronicle instead, see the
[Storyteller Guide](st-guide.md).

## 1. Your Characters

The **Characters** page shows the chronicle's full roster. Look for your own character's
name, or use **Add Character** to create one if you don't have one yet — pick a creature
type, fill in your identity fields, and spend your starting build the same way you'll spend
experience later.

If a Storyteller entered your character for you (from a Grapevine import or by hand), it may
not be linked to your WordPress account yet. Ask them to use **Assign Player** on your
character's row — until then, you can see the character but can't edit it.

## 2. Editing Your Sheet

Open your character and use **Edit**. Every section of the sheet — traits, powers, resource
pools, identity fields — is editable from the same page. Changes aren't written to your
sheet the instant you make them locally; they're queued until you click **Submit Changes**.

### What happens when you submit

Most changes need a Storyteller's review before they take effect — this is normal, not a
bug. When you submit:

- If a change is **approved automatically** (some XP awards, or anything your chronicle has
  configured to auto-approve), it appears on your sheet immediately.
- If a change needs **Storyteller review**, you'll see a notice: "N changes were submitted
  and are awaiting Storyteller approval." Your edit is safely recorded — it just hasn't
  reached your sheet yet. It will not show up if you reload the page before a Storyteller
  approves it; that's expected, not a lost edit.
- Once a Storyteller approves it, it's really on your sheet, and (unless your chronicle or
  you have turned this off) you'll get an email saying so — see below.

Don't resubmit the same change while it's still pending — it queues a second, separate
request instead of replacing the first. If you're not sure whether something already went
through, check with a Storyteller before submitting it again.

### Experience

Your unspent and total experience show on the editor. Spending it on a trait, power, or
pool increase reduces what's left to spend, following your chronicle's own point costs. A
Storyteller can award you experience directly (session attendance, plot participation) —
awards like this usually apply immediately, without needing your own submission.

## 3. Notifications

When a Storyteller approves or rejects a change you submitted, you get one email — even if
several of your changes were reviewed together, you get a single summary, not one email per
change. If you'd rather not receive these, open your WordPress profile page and look for
"Change Notifications" next to the sheet-customization option — uncheck it there. This is
your own personal setting; a Storyteller doesn't need to be involved.

## 4. Your Dashboard

The **Dashboard** page shows a player-facing summary: your own characters, your own pending
changes (the ones still awaiting review), and your own plots — never anyone else's data.

## 5. Plots and Rumors

The **My Plots & Rumors** page shows every plot thread you're connected to, plus any rumor
that's reached you. Open one to read the full thread and, if it calls for a response,
submit your action directly from there — the same review process as any other change
applies to plot actions your Storyteller needs to approve.

## 6. Recording Background Uses

Once your Storyteller has allocated actions for a game date, your character sheet's
**Background uses** panel (pick the game date you want, at the top of the panel) lets you
record what you actually did with each one — Personal actions, any Influence, and any
Background your chronicle has configured to grant an action. Type what happened and hit
**Record a use**; your Storyteller fills in the result once it's adjudicated. You can record
a use against a Background that has no action budget too — it's shown separately, with a
note that your Storyteller hasn't configured it to grant an action yet, but the use is still
recorded either way. You can clear your own use as long as no result has been recorded
against it yet; once your Storyteller adjudicates it, it's locked.

## 7. Sheet Customization

If your chronicle has granted you sheet customization, you can set your own font, colors,
background image, and section graphics from the character editor — this is purely visual
and never changes what your character can do.

## 8. Printing Your Sheet

**Print / Export** on your character's page generates a signed PDF rather than printing the
page directly — it's the one way to get a copy of your sheet, whether you're keeping a
record, handing a printout to a visiting chronicle's Storyteller, or bringing a copy to a
game with spotty wifi. Three checkboxes control what's included: your background, your
notes, and your full XP history; a fourth switches tiered powers (Disciplines, Gifts, and
the like) from a single number to every named rung you've earned.

Opening the PDF, you may see your reader report something like "signature valid, signer not
trusted" rather than a plain green checkmark. That's expected, not a problem — it means the
PDF genuinely hasn't been altered since your chronicle generated it, verified against a
certificate that reader just hasn't been told to trust yet, the same way a new website's
certificate looks different the very first time. It does **not** mean anything is wrong with
your sheet.

If Print is disabled or tells you the chronicle hasn't set up sheet signing yet, that's a
one-time setup step your Storyteller's host needs to complete — nothing you did, and nothing
you can fix from your own account.
