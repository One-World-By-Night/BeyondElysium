# Player Guide

This guide covers what a player does day to day: creating a character, editing it,
tracking experience, and following plots. If you're running a chronicle instead, see the
[Storyteller Guide](st-guide.md).

Everything a player does lives on one page, **My Chronicle**, with a **Chronicle** picker at
the top if you play in more than one — pick the right one first, then use the tabs below it
to move between your dashboard, roster, sheet, editor, and plots.

## 1. Your Characters

The **Characters** tab lists your own characters in the chronicle. Use **+ New Character**
there to create one — pick a creature type, fill in your identity fields, and spend your
starting build the same way you'll spend experience later.

**Joining a new chronicle.** If you don't play in a chronicle yet, starting a character there is
your request to join. The character waits, and you aren't a player there yet, until one of the
chronicle's Storytellers approves it; they get an email when you ask. You can have one request
waiting per chronicle.

**Already have a character from Grapevine?** Send its exported file straight to the chronicle
instead of building it by hand again — pick whether you're joining or visiting for a game, and
a Storyteller there reviews and accepts it the same way. See
[Send a Grapevine File](help/send-grapevine-file.md).

If a Storyteller entered your character for you (from a Grapevine import or by hand), it may
not be linked to your WordPress account yet. Ask them to use **Assign Player** on your
character's row — until then, it doesn't appear in your list at all.

Your new character's **Health** section comes pre-filled — this isn't something you buy
with your starting build. It's the standard Laws of the Night Revised Extended wound track
for your creature type, already there the moment the character is created.

## 2. Editing Your Sheet

Open your character from **Characters** (or go straight to the **Edit** tab). Every section
of the sheet — traits, powers, resource
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
pool increase reduces what's left to spend, following your chronicle's own point costs.
Powers are bought level by level: raising a Discipline (or an Art, Arcanos, Hekau path, or
Numina) from 1 to 3 costs level 2 plus level 3, and a brand-new power at level 3 costs all
three levels. A
Storyteller can award you experience directly (session attendance, plot participation) —
awards like this usually apply immediately, without needing your own submission.

## 3. Notifications

When a Storyteller approves or rejects a change you submitted, you get one email — even if
several of your changes were reviewed together, you get a single summary, not one email per
change. If you'd rather not receive these, open your WordPress profile page and look for
"Change Notifications" next to the sheet-customization option — uncheck it there. This is
your own personal setting; a Storyteller doesn't need to be involved.

## 4. Your Dashboard

**My Chronicle**'s **Dashboard** tab (the first one, and where you land by default) shows a
player-facing summary: your own characters, your own pending changes (the ones still
awaiting review), and your own plots — never anyone else's data.

## 5. Plots and Rumors

The **My Plots & Rumors** tab shows every plot thread you're connected to, plus any rumor
that's reached you. Open one to read the full thread and, if it calls for a response,
submit your action directly from there — the same review process as any other change
applies to plot actions your Storyteller needs to approve.

When you post, you choose who reads it: **Public** (everyone who can see the plot) or
**Private - Storytellers and me only**. A Storyteller can also send a reply directed at your
character specifically — you'll see it, but a player whose character wasn't named won't. A
plot may also carry attached files (images or PDFs); if it's your own character's plot,
you can attach one of your own from the same screen.

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
background image, and section graphics with **Customize appearance** on your character's
sheet — this is purely visual and never changes what your character can do.

## 8. Printing Your Sheet

**Print / Export** on your character's page generates a PDF rather than printing the
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

If your site hasn't set up a signing certificate, or hasn't switched secure printing on,
Print still works — every page of the PDF is simply stamped UNSIGNED. Nothing proves that copy
wasn't edited, so another chronicle may not accept it as proof. Both are one-time steps for
whoever runs your site: nothing you did, and nothing you can fix from your own account.

If a Storyteller has connected an item to your character — a weapon, a talisman, anything
your character actually carries — **Print My Items** appears in the same actions list as Print / Export.
It generates the same kind of PDF, but only for the items connected to that character, so
you can bring a real prop card to the table for what your character is holding.

## 9. Proposing an Item

Your character made something, found something, or carries something that isn't in the
chronicle's catalog yet. **My Chronicle → Propose an Item** is how you ask a Storyteller to
make it real.

Pick your character first — a proposal belongs to a character, not to you. Then choose what
kind of thing it is (item, location, or rote for Mage characters), name it, and describe it.
The fields underneath change to match the kind, because a location needs different details
from a weapon.

It goes into the same Approval Queue your Storytellers already work through for trait
changes, so nobody has to remember to check a second list. If it's approved, two things
happen together: the item joins your chronicle's catalog, and it is connected to your
character — you asked for your character to have it, so approving gives you both. If it's
rejected, nothing is written anywhere, and your Storyteller can say why.

It costs no experience. An item isn't an XP purchase; if your chronicle wants a particular
item to cost something, that's a separate change against your sheet.

You can't edit an item after it's approved — it belongs to the chronicle's catalog then, and
only a Storyteller edits that. Ask a Storyteller, or propose a replacement.

## 10. Using Your Sheet on a Phone

Reading your own character sheet at a live game is a first-class phone experience — your
identity and traits come before any of the print/edit/customize controls, which collapse
below the content instead of sitting above it. Every control on the editor meets a real
touch-target size, and a picker with a long catalog (a big Gifts or Rituals list, say) no
longer misaligns as you scroll it.

Editing on a phone works, but it isn't the polished experience reading is — a rare, tolerant
session rather than a frequent one, so a few controls (a power's tradition field, removing a
held power) move behind a **Details** button instead of sitting inline in an already-full
row. Lists and tables never make you scroll sideways: on a phone, or anywhere a table doesn't
have room for its columns, each row becomes a card. Building a multi-clause query or editing a
chronicle's schema blocks is still desk work.
