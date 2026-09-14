# Storyteller Guide

This guide covers the day-to-day work of running a chronicle in Beyond Elysium: creating
the game, seeding its rules, managing characters, reviewing player submissions, running
plots, and pulling data out of the roster. It assumes the plugin is already installed and
active — see the [README](../README.md) for installation. Looking for the player's side of
all this instead? See the [Player Guide](player-guide.md).

## 1. Creating a Game

A **game** (chronicle) is the top-level container everything else belongs to — characters,
plots, changes, and queries are all scoped to one game and never visible from another.

1. In wp-admin, go to **Beyond Elysium → System Config → Games**.
2. Click **+ New Game**, give it a name, and save. The plugin generates a URL-safe slug from
   the name automatically (or set one explicitly). You are automatically made this
   chronicle's HST the moment it's created — no separate step needed.
3. If this chronicle also has an `owbn_chronicle` post (via `owbn-chronicle-manager`), the
   two stay in sync automatically once both plugins are active — publishing or renaming the
   chronicle post keeps the game's name current. A slug change on the chronicle side does
   not rename the existing game row; it is a known, accepted limitation (see the plugin's
   own `Chronicle_Sync` class comment) since the upstream plugin does not allow a chronicle's
   slug to change through its own UI anyway.

### Chronicle Setup: what's left to configure

Right under Games is **Chronicle Setup** — a checklist for the chronicle you just created,
not a one-time wizard. Every row's status is computed live from what actually exists: pick
your chronicle from the dropdown and it shows exactly what still needs doing (creature
types, a Storyteller besides you, new-character approval, front-end pages, at least one
character) alongside informational rows about what's already using Beyond Elysium's own
defaults (approval rules, catalog and template customisation, downtime/rumor settings).
Nothing here is a one-time setup you complete and forget — if an AST leaves and nobody
replaces them, that row goes back to amber on its own, on your very next visit. A row you
can't act on (because you're an HST, not a site administrator) still shows its real status,
greyed rather than hidden, so you know what to ask for and from whom.

The one control worth calling out: **Creature types**. By default every chronicle offers
all eleven World of Darkness creature types when creating a character. Most real OWBN
chronicles run one or two — narrowing this list here is what actually shrinks the "Choose a
type" dropdown players see, without touching any character your chronicle already has (a
retired Wraith stays fully readable, exportable, and approvable even if you later drop
Wraith from the list — narrowing this only changes what a *new* character can be, never what
an existing one is).

**Sub-Faction Restrictions**, right below the checklist, goes one level finer: within a
creature type you've already enabled, you can narrow a real catalog field to only the values
your chronicle runs — a Vampire Sect or Clan, a Werewolf Tribe, and similarly shaped fields on
any other type. "Vampire yes, but no Sabbat" is exactly this. It's built the same way as
Creature types above: absent or fully-checked means every option stays open, and narrowing it
only ever changes what a *new* character can pick — a character who already held a value
that's since been restricted (a Sabbat vampire from before you added the restriction) keeps
that value and can still be viewed, edited, and approved normally. Every field offered here is
read live from your chronicle's own catalog, so a custom field you've added to a schema block
shows up automatically; nothing needs to be told about it by name.

### accessSchema and chronicle roles

Under **Beyond Elysium → Chronicle Setup → Chronicle Access**, you can:

- Turn accessSchema role-path checking on or off, site-wide.
- Set a chronicle's `asc_role_path` (its accessSchema path prefix, e.g. `Chronicle/KONY`) if
  the wider OWBN plugin stack is present.
- Add and remove chronicle members and set their role: **HST**, **AST**, **Narrator**,
  **Boons** (Harpy), or **Player**.
- Turn the per-chronicle "email a player when their change is reviewed" notification on or
  off (see [Notifications](#6-notifications) below).

If accessSchema is off, unreachable, or a member has no accessSchema role for this
chronicle, permissions fall back to this chronicle-membership table automatically — nothing
breaks, and nothing needs configuring differently.

A player is added to this table automatically the moment they create their first character
or are assigned an existing one — you rarely need to add players by hand, only Storytellers
and Narrators.

## 2. Seeding Schema

**Schema blocks** define what goes on a character sheet (trait lists like Merits and
Backgrounds, tiered powers like Disciplines and Gifts, resource pools like Blood and
Willpower, identity fields like Nature and Demeanor). **Creature stacks** assemble blocks
into a complete sheet for one creature type — Vampire, Werewolf, Mage, and so on.

Every install ships with the full MET-mechanics catalog already seeded — you do not need to
build these from scratch. Under **Beyond Elysium → System Config → Schema Blocks** and
**→ Creature Stacks** you can review what exists and, if your chronicle needs a house rule or a
homebrew trait, fork just that block for your own game without touching the shared catalog
other chronicles use. A forked block is scoped to your game only; the base catalog is never
edited in place.

Adding an entirely new creature type (one this catalog doesn't already cover) is an admin
task — see the [Admin Guide](admin-guide.md).

**This is desk work too.** Schema Blocks and Creature Stacks don't reflow for a phone — a
catalog edit wants a keyboard, the same as building a query.

## 3. Making Characters

Storytellers create characters two ways:

- **By hand**, under **Beyond Elysium → Characters → + New Character** — pick a creature
  stack, fill in identity fields, and assign traits directly. Useful for NPCs and for
  entering a character on a player's behalf.
- **By import** (see [Importing from Grapevine](#5-importing-from-grapevine) below) — the
  fastest path for a player who already has a Grapevine character file.

Players can also create their own characters from **My Chronicle**'s **Characters** tab,
subject to whatever approval rules your chronicle's schema blocks define.

### Creating or flagging an NPC

A Storyteller (anyone holding `be_manage_characters`) sees a "This is an NPC" checkbox on
the creation form - never shown to a player. Checking it immediately switches the character
onto the richer NPC sheet template, which adds a Storyteller-only section for voice,
mannerisms, and plot hooks that never appears on an ordinary player character's sheet. The
same checkbox is available in edit mode too, so a character created as a player character
can be flagged as an NPC later, or the reverse, with no separate "convert" action needed.

Every character needs a linked WordPress account (`wp_user_id`) to be playable by someone.
If a character is imported or entered before its player has an account, use the **Assign
Player** control on the character's row in the roster — search by name or email and attach
the account once it exists. Until then the character is visible but not editable by anyone
but a Storyteller.

**Health Levels are pre-filled automatically, not something you or the player has to build.**
Every applicable creature type's sheet includes a Health section — the Laws of the Night
Revised Extended track (`Healthy ×2, Bruised ×3, Wounded ×2, Incapacitated ×1`, plus one
terminal box: `Torpor` for Vampire and Kuei-Jin, `Mortally Wounded` for everything else,
with Mummy adding two further `Dead` boxes past that). It's seeded the moment the character
is created, exactly like Grapevine itself pre-fills a fresh character's health boxes — never
overwriting a hand-built starting sheet that already specifies its own Health values. Wraith
has none — it tracks Corpus instead, the same as Grapevine. It's an ordinary trait list like
Merits, not a special mechanism, so it edits and imports/exports the same way any other held
trait does.

## 4. Running the Approval Queue

Every trait purchase, XP award, or sheet change a player submits becomes a **pending
change** unless your schema's approval rules mark it auto-approved. The queue lives on the
**Storyteller Toolkit** page's **Approval Queue** tab. Your chronicle's own baseline
("Pending by default" vs "Auto-approve by default") and every specific exception to it are
set under **Beyond Elysium → System Config → Approval Rules** - see the
[Admin Guide's Approval Rules section](admin-guide.md#approval-rules).

- Filter by character, change type, or approval level.
- Approve or reject one at a time, with an optional note (a note is required on reject).
- Select several and **Approve Selected** to clear a batch in one action — this applies each
  change individually (same sheet mutation and XP deduction as approving one at a time), it
  is a convenience over the loop, not a different approval mechanism.
- A change over the **coordinator** threshold (a genre/site-wide OWBN role, not a
  per-chronicle one) is flagged as such in the queue, but coordinator-tier enforcement itself
  is not yet built — any Storyteller can currently approve any tier. This is a known,
  deliberately-unbuilt gap, not an oversight.

**This queue works on a phone.** Triaging pending changes between scenes is a real phone
surface, not just a desktop one — each pending change is a card with Approve/Reject at the
top, the rest (level, submitted by, when) behind a **Details** disclosure.

## 5. Importing from Grapevine

Under **Beyond Elysium → Import**, upload a `.gex` (character or game exchange file) or a
full `.gv3` game file exported from Grapevine 3.01.

- A **character** exchange file is checked by name against existing characters in this game.
  If a match is found, you choose to skip it, overwrite the existing character in place
  (preserving its ID and every connection/plot reference to it), or import it as a new,
  separate character.
- A **game** file (`.gv3`) can create a brand-new chronicle from its contents, or merge into
  an existing one — the existing chronicle is always the protected base; the file only
  contributes characters, plots, items, and locations it doesn't already have, and anything
  that collides by name gets the same skip/overwrite/import-as-new choice.
- Any trait the importer can't match automatically is flagged for manual resolution before
  the import can complete — nothing partially imports.

## 6. Notifications

When a Storyteller approves or rejects a player's submitted change, that player's account
gets one email. If a batch approval clears several of one player's changes at once, they get
a single summary email, not one per change — and a change that auto-approves (an XP award
you grant directly, for example) never sends anything, since the player already knows they
just made it.

Turn this off for the whole chronicle under **Chronicle Access**. A player can also opt out
for themselves from their own WordPress profile screen, next to the sheet-customization
checkbox.

## 7. The Game Dashboard

Every chronicle has a fixed **My Chronicle** page and a fixed **Storyteller Toolkit** page —
the same two pages regardless of how many chronicles this site hosts, each with a
**Chronicle** switcher at the top to pick which one you're looking at.

**My Chronicle**'s **Dashboard** tab shows your own characters, your own pending changes,
and your own plots — nothing from anyone else's sheet. Its **Characters**, **Sheet**, and
**Edit** tabs are the character roster, read-only sheet view, and character editor; **My
Plots & Rumors** is your own plot feed.

**Storyteller Toolkit** is Storyteller-only: its tabs (Dashboard, Approval Queue, Plots &
Rumors, Boon Ledger) only appear when you actually hold a Storyteller-level role *in the
chronicle currently selected in the switcher* — an AST who only narrates one chronicle sees
fewer tabs there than in one they HST. Its own **Dashboard** tab shows character counts by
creature type and status, the pending-change count, the active-plot count, a roster-health
count of players with no active character (click it to see who), and a feed of recent
activity across the whole chronicle.

## 8. Plots, Actions, and Rumors

The **Storyteller Toolkit** page's **Plots & Rumors** tab is where plots live: create a
plot, respond to player actions submitted against it, allocate action slots, and generate
rumors that distribute to players via a saved query (see below) rather than by hand. Players
see their own plot connections on **My Chronicle**'s own **My Plots & Rumors** tab.

## 9. Action & Rumor Settings and the Background-Use Ledger

Under **Beyond Elysium → Chronicle Setup → Action & Rumor Settings**, pick a chronicle to configure how many
downtime actions a character receives each game date and which rumors generate
automatically. The **Actions** tab sets personal actions per character, whether unused
actions and growth carry forward week to week, whether Common Actions (from Influences and
configured Backgrounds) are added automatically, an actions-per-level override table for
specific dot ratings, and which Backgrounds grant an action at all — an Influence always
does and is shown for reference only. The **Rumors** tab holds the eight rumor-generation
toggles the Storyteller Toolkit's rumor generator reads; Group and Subgroup rumors are shown
but not yet functional, since no character data exists to generate them from. A **Restore
Grapevine defaults** button is available if you want the original 1998 values instead of
Beyond Elysium's own (higher personal-action, carry-forward-on) defaults — it does not touch
your rumor settings.

Once a character's actions are allocated (in the Action Allocator, under Plots), each
budgeted subaction — Personal, and any Influence or configured Background — gets its own
**background-use ledger**: record what the character actually did with that action, and fill
in the result once it's adjudicated. A background with no live budget can still have a use
recorded against it (it shows under "Other backgrounds," with a note pointing back here) —
recording is never blocked by a chronicle simply not having configured that background yet.
**Clear all for this Character** and **Clear all for this Date** remove recorded uses only;
they never touch a character's action budget itself. Players see and can record uses for
their own characters directly from their character sheet's **Background uses** panel, and
can clear their own use as long as no result has been recorded against it yet.

## 10. Building and Running Queries

Under **Beyond Elysium → Query Tool**, pick which of four inventories to search —
**Characters**, **Items**, **Locations**, or **Rotes** — with a tab strip at the top of the
tool. Each inventory offers its own field list (a location's Gauntlet rating and Security
Level, an item's Type and Concealability, a rote's Level and Sphere prerequisites, and so
on), build a filter against it, and either browse the results or run one of the five
built-in statistics. Switching inventories clears the clauses on screen, since a clause
built against one inventory's fields has no meaning on another's.

A query can be saved and reused; the Saved Queries list shows which inventory each one
searches, and loading one switches back to that inventory automatically. A saved query is
also what a rumor's target audience is defined by (**Characters** queries only) — build the
query once, point the rumor at it, and it recalculates who's in scope every time it runs
rather than freezing a player list at creation time.

Three bulk actions are available only on **Characters** results, for the same reason a rumor
can only target characters: an Item, Location, or Rote result isn't a character, and the
tool never offers an action that would only make sense as one. Select a set of rows to award
XP, reset a resource pool's temporary rating back to its permanent one (the ordinary
end-of-session "everyone's Willpower/Blood refills" chore, across the whole selection at
once instead of one character at a time), or set the same status on every selected
character — retiring a batch, or marking a group inactive at once. A bad or stale character
ID in a selection never touches another chronicle's character; it's reported as failed
rather than silently ignored or acted on.

**This is desk work.** A multi-clause query with a results grid of arbitrary columns doesn't
reflow for a phone, and isn't meant to — build queries and read reports at a keyboard.

## 11. Signed Character Sheets

Every printed sheet is a digitally signed PDF, generated on your own site rather than
captured from the browser — a Storyteller who receives one can be sure the trait values on
it have not been edited after the fact. This replaces the old browser print entirely; there
is only the one Print button now.

**Before anyone can print, your chronicle's host needs a signing certificate.** This is a
one-time setup per site (not per chronicle), done by whoever has SSH/hosting access — if
that isn't you, this section is what to hand them. If it isn't set up yet, the Print button
tells the player so instead of failing silently, and an admin notice on every wp-admin page
names exactly what's missing.

**Generating the certificate:**

```bash
BE_KEYPASS='choose-a-real-passphrase' openssl req -x509 -newkey rsa:4096 -days 3650 \
  -cipher aes-256-cbc -passout env:BE_KEYPASS \
  -subj "/CN=Beyond Elysium Signing" \
  -keyout be-signing.key -out be-signing.crt
```

Place both files **above the webroot** (a sibling of `public_html`, never inside it) with
the key at permissions `0600`. Then add three constants to `wp-config.php`, above the line
that says `/* That's all, stop editing! */`:

```php
define( 'BE_PDF_SIGNING_CERT', '/full/path/above/webroot/be-signing.crt' );
define( 'BE_PDF_SIGNING_KEY',  '/full/path/above/webroot/be-signing.key' );
define( 'BE_PDF_SIGNING_PASSPHRASE', 'the same passphrase you chose above' );
```

**Why a passphrase at all, if the site has to be able to read it anyway?** It protects
against exactly one real, common leak mode — the key file escaping on its own (an accidental
commit, a stray backup, a directory listing) without the passphrase escaping with it. It is
not protection against the site itself being compromised; nothing about local file
permissions is. If you already have an unencrypted key from an older setup, leave the
passphrase constant undefined — Beyond Elysium treats "no passphrase set" as "this key has
none," not as a misconfiguration.

**What a signature means, and what it doesn't.** A self-signed certificate makes PDF readers
report "signature valid, signer not trusted" rather than a plain green checkmark — that's
expected, not a problem to fix. Trusting the certificate once (most PDF readers let you do
this from the signature panel) makes it read as fully valid afterward. The signature proves
the document hasn't changed since it was generated; it does not prove the sheet is still
*current* — a character could have changed since. If your printed sheet is more than a
session or two old, treat it as a record of that moment, not a live view.

## 12. Reports, Cards, and Batch Output

Beyond Elysium's Reports page (under the plugin's admin menu) generates every one of
Grapevine's 19 remaining reports as a signed PDF, sharing the same signing setup as the
character sheet (§11) — if signing isn't configured yet, a report can't be generated either,
for the same reason. Character Roster, Player Roster, Sign-In Sheet, Experience History,
Player Point History, item/location/rote Cards, Plot Report, Master Action/Rumor Report,
Action and Rumor Report, Search Report, Statistics Report, Vampire Status Report, Merits and
Flaws Report, Influence Report, and Character Equipment. Pick a chronicle, pick a report, and
Generate PDF — cards print several to a page, and any `table`-shaped report can be scoped to
a saved query's own results instead of the whole chronicle, which is what "batch output"
means here: one PDF for a chosen set of characters or objects, not a new mechanism to learn.

**Game Calendar always renders empty right now.** Beyond Elysium doesn't yet model a
chronicle's own game-date schedule, so this one report is an honest placeholder rather than
invented data — it will populate once that feature exists.

**House Rules is the 20th report, and the only one with no Grapevine counterpart.** It lists
every catalog item, tiered power level, or tiered power family carrying a description (see the
[Admin Guide](admin-guide.md#descriptions-and-approval-schedules-on-catalog-items)), grouped by
schema block, and generates as the same signed PDF every other report does. Unlike the other
nineteen, it can *also* be dropped directly onto a front-end page — as an Elementor widget
("House Rules" in the Beyond Elysium widget category) or the `[be_house_rules game="chronicle-slug"]`
shortcode — for a live, always-current view players can browse without waiting for a
Storyteller to generate anything, since `be_view_reports` already reaches every real chronicle
role including plain players.

**Item Cards can be scoped to one character's own held items.** A character sheet's
Connections section (visible to anyone holding `be_manage_connections`) lets a Storyteller
link a world-object item to a character — search for it by name, optionally add a note (shown
under the connection once saved), and it's connected. The Item Cards report normally prints
every item in the chronicle's catalog; add `character_id` to the request (the character
sheet's own "Print My Items" button does this automatically) to print only that character's
connected items instead, signed the same way every other report is.

A World Objects catalog entry can be shared across as many characters as hold one — connecting
"the pistol" to fifty enforcers is fifty ordinary connections to the same item, not fifty
copies. When one character's item needs to be genuinely unique (an heirloom, something that
gets damaged or renamed), use **Duplicate** on that item in the World Objects editor instead of
**New** — it opens a fresh create form pre-filled from the original so you're editing a
starting point rather than typing it from scratch, and never changes the original item.

## 13. The Point Audit

Opening a character's sheet as a Storyteller shows a **Point audit** toggle beside View
history and Transfer. It lists every trait, power, resource, and identity field the
character holds, priced against the exact same rules the purchase flow charges — never a
second, independently-guessed number.

**This is not a bill, and it cannot be one.** A large share of what a real sheet holds has no
cost recorded anywhere in the catalog yet — every MET attribute trait (Physical, Social,
Mental), most identity fields, and any resource pool without a set XP rate. The audit lists
every one of those lines too, marked with a plain reason ("catalog item has no cost", "no
pricing rule exists for this yet") rather than silently showing 0 XP or leaving the line off
the report. The coverage line ("Priced N of M lines") and the note beneath the total are
there for exactly this reason — read them before treating the total as an answer. A large gap
between the total and a character's own recorded XP is normal today, not a sign the player
owes you anything.

## Roles Reference

| Role | Access |
|---|---|
| HST | Everything — characters, plots, queries, schema and template customization, importing, chronicle membership. The one exception is deleting or editing the chronicle itself (renaming it, changing its slug); that's a site-administrator act, not a chronicle-level one, by design. |
| AST | Everything HST can do within the chronicle, including importing — the only difference from HST is that an AST cannot delete or edit the chronicle itself. |
| Narrator | Plots — creating and running them, responding to player actions, generating rumors, and the roster queries that plot work depends on. Not full character management. |
| Boons (Harpy) | The boon ledger only — recording and repaying boons. No Storyteller powers over characters or plots. Can still look characters up (needed to know who owes whom) and view reports. |
| Player | Creates and submits their own characters, and edits their own sheet — every edit still goes through the same approval process everyone else's does. Nothing outside their own characters, changes, and plot connections. |

This is the plugin's own chronicle-scoped role model (`be_game_members.role`, one of
`hst`/`ast`/`narrator`/`boons`/`player`), checked in addition to whatever your underlying
WordPress account can already do — both have to allow an action for it to go through. If your
chronicle runs accessSchema, its own role paths are checked first and can grant access this
table doesn't cover; this table describes the fallback every chronicle has regardless.
