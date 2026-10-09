# Storyteller Guide

This guide covers the day-to-day work of running a chronicle in Beyond Elysium: creating the game, seeding its rules, managing characters, reviewing player submissions, running plots, and pulling data out of the roster. It assumes the plugin is already installed and active - see the [README](../README.md) for installation. Looking for the player's side of all this instead? See the [Player Guide](player-guide.md).

## 1. Creating a Game

A **game** (chronicle) is the top-level container everything else belongs to - characters, plots, changes, and queries are all scoped to one game and never visible from another.

1. In wp-admin, go to **Beyond Elysium → System Config → Games**.
2. Click **+ New Game**, give it a name, and save. The plugin generates a URL-safe slug from the name automatically (or set one explicitly). You are automatically made this chronicle's HST the moment it's created - no separate step needed.
3. If this chronicle also has an `owbn_chronicle` post (via `owbn-chronicle-manager`), the two stay in sync automatically once both plugins are active - publishing or renaming the chronicle post keeps the game's name current. A slug change on the chronicle side does not rename the existing game row; it is a known, accepted limitation (see the plugin's own `Chronicle_Sync` class comment) since the upstream plugin does not allow a chronicle's slug to change through its own UI anyway.

### Chronicle Setup: what's left to configure

Right under Games is **Chronicle Setup** - a checklist for the chronicle you just created, not a one-time wizard. Every row's status is computed live from what actually exists: pick your chronicle from the dropdown and each row is amber (*Needs attention*), green (*Done*) or grey (*Info*, optional and not set yet), and the summary line says how far through you are ("5 of 16 done"). Five rows ask for attention as a matter of course: creature types, a Storyteller besides you, new-character approval, front-end pages, and at least one character. A sixth, catalog customisation, only turns amber when a book update collides with something your chronicle changed itself - see below. Everything else is optional and turns from grey to green as soon as your chronicle has set something of its own: approval rules (a rule, or a default policy you chose), starting experience, catalog and template customisation, a chosen book variant, downtime and rumor settings, plot features, branding, sub-faction restrictions, purchase lists, and the Grapevine link once a player has used it. A row that lives on another page has a **Go** button; a setting on this page has **Set up** (**Change** once it's done) and opens its controls right under the row. Rows that need attention start open and everything else starts folded. Nothing here is a one-time setup you complete and forget - if an AST leaves and nobody replaces them, that row goes back to amber on its own, and if you delete a template override the Sheet templates row goes back to grey. A row you can't act on (because you're an HST, not a site administrator) still shows its real status, greyed rather than hidden, so you know what to ask for and from whom. While a chronicle has no characters yet, a dismissible notice on your WordPress Dashboard points you here.

**Book corrections.** Forking a block or a creature type (see [Seeding Schema](#2-seeding-schema) below) freezes your own values against future book updates - deliberately, so an update never silently overwrites a house rule. When the book later changes something you'd also changed yourself, that's a **book correction**: the **Catalog customisation** row turns amber and names it in plain words ("Disciplines › Elder cost: The book said 12, now says 15. Yours: 10."), with **Keep mine** (your value stands, the book's change is set aside) or **Use the book's** (takes the new value, your own change is dropped) on each one, and **Keep all mine** to resolve every correction shown at once. Neither choice moves any character's XP - this only decides which value the catalog copy itself carries going forward.

The one control worth calling out: **Creature types**. By default every chronicle offers all twelve World of Darkness creature types when creating a character. **Various** is not on this list: it is the Storyteller-only creature type, offered to a Storyteller in every chronicle and to no player, and it lists every creature type's powers on its sheet, can hold any other section from any creature type, and takes an entry no catalog lists (see [Creature Stacks](help/creature-stacks.md)). Most real OWBN chronicles run one or two - narrowing this list here is what actually shrinks the "Choose a type" dropdown players see, without touching any character your chronicle already has (a retired Wraith stays fully readable, exportable, and approvable even if you later drop Wraith from the list - narrowing this only changes what a *new* character can be, never what an existing one is).

**Sub-faction restrictions**, a row further down the same list, goes one level finer: within a creature type you've already enabled, you can narrow a real catalog field to only the values your chronicle runs - a Vampire Sect or Clan, a Werewolf Tribe, and similarly shaped fields on any other type. "Vampire yes, but no Sabbat" is exactly this. It's built the same way as Creature types above: absent or fully-checked means every option stays open, and narrowing it only ever changes what a *new* character can pick - a character who already held a value that's since been restricted (a Sabbat vampire from before you added the restriction) keeps that value and can still be viewed, edited, and approved normally. Every field offered here is read live from your chronicle's own catalog, so a custom field you've added to a schema block shows up automatically; nothing needs to be told about it by name.

**Purchase lists**, the row after that, decides what your players can buy. Each creature type has its own Abilities, Backgrounds, Merits and Flaws, and by default a character buys only from its own. Three switches (Abilities, Backgrounds, and Merits and Flaws) open a list to every creature type in your chronicle: a Vampire can then take a Mage-only Ability, priced from the Mage list. They work in any combination, and each is all or nothing. You can't open a list to some creature types and not others. The lists themselves stay separate. A Grapevine file imported into the chronicle matches against the wider list too, so that Mage-only Ability arrives as a catalog entry rather than a custom one waiting for a price. A character who already holds something keeps it if you switch the list off again.

### accessSchema and chronicle roles

Under **Beyond Elysium → Chronicle Setup → Chronicle Access**, you can:

- Turn accessSchema role-path checking on or off, site-wide.
- Set a chronicle's `asc_role_path` (its accessSchema path prefix, e.g. `Chronicle/KONY`) if the wider OWBN plugin stack is present.
- Add and remove chronicle members and set their role: **HST**, **AST**, **Narrator**, **Boons** (Harpy), or **Player**.
- Turn the per-chronicle "email a player when their change is reviewed" notification on or off (see [Notifications](#6-notifications) below).

If accessSchema is off, unreachable, or a member has no accessSchema role for this chronicle, permissions fall back to this chronicle-membership table automatically - nothing breaks, and nothing needs configuring differently.

A player is added to this table automatically the moment they create their first character, are assigned an existing one, or a join request of theirs is approved. An HST or AST manages players from the **Players** tab of the [Storyteller Toolkit](help/chronicle-players.md), on every chronicle: review and answer join requests, type a player's email, tick their characters and click **Invite**, or add an existing account directly. An existing OWbN account with an invited email becomes a player at once with those characters; anyone else gets an invitation email, and the first time they sign in through OWbN with that address they join the chronicle with their characters waiting. The tab also lists the invites nobody has signed in for yet (with **Cancel**), and each player with their characters (**Add characters**, **Unlink**, **Remove**). On a chronicle linked to OWbN roles, adding also grants the chronicle's player role in OWbN and removing takes it away; the message says what OWbN did, and a player OWbN refuses is still added here. Staff roles stay on Chronicle Access. A player who holds a role through accessSchema alone also sees the chronicle in their own chronicle list.

## 2. Seeding Schema

**Schema blocks** define what goes on a character sheet (trait lists like Merits and Backgrounds, tiered powers like Disciplines and Gifts, resource pools like Blood and Willpower, identity fields like Nature and Demeanor). **Creature stacks** assemble blocks into a complete sheet for one creature type - Vampire, Werewolf, Mage, and so on.

A **tiered power** keeps two things apart on purpose: its **ladder** - the numbered rungs a rating counts, five for every track in the game - and its **picks**, the named powers above the ladder (Elder, Master, Ascended, Methuselah, and Wraith's Innate below it). A player's rating is the ladder; a pick is a separate named thing they hold as well. They are stored separately and edited separately, and a rating can never wander into pick territory. A block also declares its own rank vocabulary and per-rank costs, so Kuei-Jin's 4/7/10 and a Discipline's 3/6/9 sit side by side without either being "the default".

Every install ships with the full MET-mechanics catalog already seeded - you do not need to build these from scratch. Under **Beyond Elysium → System Config → Schema Blocks** and **→ Creature Stacks** you can review what exists and, if your chronicle needs a house rule or a homebrew trait, fork just that block for your own game without touching the shared catalog other chronicles use. A forked block is scoped to your game only; the base catalog is never edited in place. Your copy keeps what you changed and still picks up fixes to everything else in the shared block.

Adding an entirely new creature type (one this catalog doesn't already cover) is something you can build yourself, scoped to your own chronicle, from Chronicle Setup's **Creature types** row - see [Creature Stacks](help/creature-stacks.md) and the [Admin Guide](admin-guide.md#adding-a-creature-type-without-code).

**This is desk work too.** Schema Blocks and Creature Stacks don't reflow for a phone - a catalog edit wants a keyboard, the same as building a query.

## 3. Making Characters

Storytellers create characters two ways:

- **By hand**, from the **Storyteller Toolkit → Characters** tab (**+ New Character**, or **+ New NPC** to start with the NPC box ticked) or under **Beyond Elysium → Characters → + New Character** - pick a creature stack, fill in identity fields, and assign traits directly. Useful for NPCs and for entering a character on a player's behalf.
- **By import** (see [Importing from Grapevine](#5-importing-from-grapevine) below) - the fastest path for a player who already has a Grapevine character file.

Players can also create their own characters from **My Chronicle**'s **Characters** tab, subject to whatever approval rules your chronicle's schema blocks define.

### Creation rules and the build tally

A creature type's own **creation rules** (set on its [Creature Stack](help/creature-stacks.md#the-creation-rules-editor), not something you touch per character) decide what a new build costs beyond an ordinary purchase: prioritized attribute splits, free budgets for basic Disciplines or Gifts, pools earned from Flaws or negative traits, starting values, and entries a character just begins with. Whoever is building the sheet - player or Storyteller - sees a live **Build Tally** panel beside the form: what each step still covers, each pool's balance, and what's left to pay at the ordinary XP cost. Nothing here blocks a save - a build that runs over simply starts with negative unspent XP, so you can see exactly why on the roster or the approval queue, rather than a form refusing to submit.

If your chronicle has set a **Starting experience** amount (Chronicle Setup's own row for it), a brand-new character is granted that much XP automatically and the build's own cost, past whatever the rules engine's budgets and pools already covered, is charged against it in the same step - both as one approved change on the character's history, nothing pending. Entering a character that already exists in your chronicle's records (an import, or hand-transcribing an existing player's sheet) is different from a fresh build: check **Existing character** on the creation form to skip both the starting-XP grant and the build charge, since neither applies to a sheet you're just recording as it already stands. This checkbox is Storyteller-only - a player creating their own character never sees it.

A creature type with no creation rules declared at all prices its whole starting sheet as ordinary purchase XP, exactly as it always has - there's nothing to configure to keep that behavior.

### Creating or flagging an NPC

A Storyteller (anyone holding `be_manage_characters`) sees a "This is an NPC" checkbox on the creation form - never shown to a player. Checking it immediately switches the character onto the richer NPC sheet template, which adds a Storyteller-only section for voice, mannerisms, and plot hooks that never appears on an ordinary player character's sheet. The same checkbox is available in edit mode too, so a character created as a player character can be flagged as an NPC later, or the reverse, with no separate "convert" action needed.

**Storyteller-only text.** Wrap anything players must not see in `[ST]...[/ST]` - in a character's biography or notes, or in an item's, location's, rote's, or boon's text. Only the chronicle's Storytellers see it (for boons, its Harpy too); for everyone else it is removed before it leaves the server, and neither a catalog search nor a report's filters can find it.

**Stuck on a blank Biography or NPC notes field?** An "AI Assist" button sits next to it (and every other long-form text field in the plugin) once your chronicle has opted in - see the [Admin Guide's AI Writing Assist section](admin-guide.md#ai-writing-assist). Never shown to a player, and never saves anything on its own - you review and Accept before it ever touches the field. An NPC's whole Storyteller-only notes section can be drafted in one request too, via its own **Draft roleplaying notes** button beside that section's heading, which fills every field there that's still empty. The same section also carries a folded **Hooks** panel listing every plot this NPC is connected to, open and resolved - see [Character Editor](help/character-editor.md).

**Requests to join.** Your chronicle has its own join link (**Players** tab, **Copy link**) you can hand out directly - anyone signed in who follows it, or who finds your chronicle on their own **Chronicles you can ask to join** list, can ask with a short message, or by starting a character, or by sending a Grapevine file, any one of the three on its own. A message-only request, and one carrying a character or a file, both show under the Players tab's own **Join requests** section with **Approve**/**Refuse**; approving a character or accepting a file (from Import) works the same way and closes the request at the same time - there's no separate "approve the request" click on top of either. Refusing a message-only or character-carrying request can carry a note, emailed to the applicant; refusing one with a character deletes it. Nothing makes anyone a member on its own until you approve. Turn requests off per chronicle from [Chronicle Setup](help/chronicle-setup.md) if you'd rather add people yourself. See [Joining a Chronicle](help/joining.md) for the applicant's own side of this.

Every character needs a linked WordPress account (`wp_user_id`) to be playable by someone. If a character is imported or entered before its player has an account, use the **Assign Player** control on the character's row in the roster - search by name or email and attach the account once it exists. Until then the character is visible but not editable by anyone but a Storyteller.

**Health Levels are pre-filled automatically, not something you or the player has to build.** Every applicable creature type's sheet includes a Health section - the Laws of the Night Revised Extended track (`Healthy ×2, Bruised ×3, Wounded ×2, Incapacitated ×1`, plus one terminal box: `Torpor` for Vampire and Kuei-Jin, `Mortally Wounded` for everything else, with Mummy adding two further `Dead` boxes past that). It's seeded the moment the character is created, exactly like Grapevine itself pre-fills a fresh character's health boxes - never overwriting a hand-built starting sheet that already specifies its own Health values. Wraith has none - it tracks Corpus instead, the same as Grapevine. It's an ordinary trait list like Merits, not a special mechanism, so it edits and imports/exports the same way any other held trait does.

## 4. Running the Approval Queue

Every trait purchase, XP award, or sheet change a player submits becomes a **pending change** unless your schema's approval rules mark it auto-approved. The queue lives on the **Storyteller Toolkit** page's **Approval Queue** tab. Your chronicle's own baseline ("Pending by default" vs "Auto-approve by default") and every specific exception to it are set under **Beyond Elysium → System Config → Approval Rules** - see the [Admin Guide's Approval Rules section](admin-guide.md#approval-rules).

- Filter by character, change type, or approval level.
- Approve or reject one at a time, with an optional note (a note is required on reject).
- Select several and **Approve Selected** to clear a batch in one action - this applies each change individually (same sheet mutation and XP deduction as approving one at a time), it is a convenience over the loop, not a different approval mechanism.
- **Homebrew waits for a price.** A trait or power that isn't in the catalog has no price, so the queue doesn't make one up. It shows **Needs a price** and a box for what it costs, per dot for a trait counted in dots, and one price for a single purchase, such as a Merit, or for a power. You can't approve it until you've typed a number, and 0 counts. It can't go into a batch either. The player sees "Price set by a Storyteller on approval" until you do. Bonds and Guanxi are never priced, so they never ask. See [Approval Queue](help/approval-queue.md#a-change-that-needs-a-price).
- There are two approval levels: automatic, and Storyteller review. When a rule's reason says a coordinator's approval is needed (an OWBN bylaw, for example), getting it is the Storyteller's job before approving - Beyond Elysium has no separate coordinator step.
- **With OWBN Character Bylaws on** (Approval Rules), a purchase a bylaw already covers cites it right in the Approval Reason column, ending in a clickable clause number that opens the real clause on council.owbn.net - no need to look it up by hand.
- **With the removal/lowering switch on** (Approval Rules), a set a player submitted together that got caught - a removal, a lower rating, a relabel, or a rename - shows as one **"Submitted together"** banner over its rows, net XP and all, with **Approve all** / **Refuse all** in place of per-row buttons. Approve all applies the set in one transaction, refunds and lowerings first; Refuse all takes one shared reason and writes nothing to the sheet.

**This queue works on a phone.** Triaging pending changes between scenes is a real phone surface, not just a desktop one - each pending change is a card with Approve/Reject at the top, the rest (level, submitted by, when) behind a **Details** disclosure.

## 5. Importing from Grapevine

Under **Beyond Elysium → Import**, upload a `.gex` (character or game exchange file) or a full `.gv3` game file exported from Grapevine 3.01.

- A **character** exchange file is checked against the characters already here - by name, and by the character's own identity when the file carries one (every transfer document does). For each match you choose to skip it, overwrite the existing character in place (preserving its ID and every connection/plot reference to it), or import it as a new, separate character. A character that already lives in *another* chronicle on this site can only be skipped or imported as a new character - never overwritten from yours. Overwrite replaces what the file carries and keeps the rest of the sheet: a section your chronicle added, or anything a Grapevine file has no place for, stays as it was.
- The items and locations a character holds come with it: each connects to your catalog entry of the same name, and one your catalog doesn't have yet is added by name (the preview lists those first). Its boons are kept in the character's import history, not recreated.
- A **game** file (`.gv3`) can create a brand-new chronicle from its contents, or merge into an existing one - the existing chronicle is always the protected base; the file only contributes characters, items, and locations it doesn't already have, and anything that collides by name gets the same skip/overwrite/import-as-new choice. Its plots, rumors and actions come too - each its own checkbox on the preview, ticked by default - a plot or rumor already here by title and date, or an action already recorded for that character and date, is skipped rather than duplicated. A plot arrives Storyteller-only with its cast connected; a rumor arrives delivered if Grapevine had already marked it done, held for you to release otherwise; an action joins that character's own history for the date, the same record the Downtime Queue reads.
- Any trait the importer can't match automatically is flagged for manual resolution before the import can complete - nothing partially imports.
- A Disciplines entry labelled as a combo ("Combo:", "Combi:", "Combination:" and their variants) always lands under Combo Disciplines: the catalog's entry when it holds one, otherwise a custom combo carrying the file's value as its XP price. It never waits on you as an unmatched Discipline.
- A Vampire's Bonds (Vinculums and blood bonds) import into the Bonds section with their ratings.

**Transfers from other chronicles.** When another chronicle sends you a character, it appears under **Waiting for Review** at the top of the Import page, and every HST and AST gets an email. Nothing is added to your chronicle yet. **Review** shows it exactly like an uploaded file - duplicates, flagged traits and all - and **Accept Transfer** imports it once every decision is made. A character coming back to your chronicle also shows what differs from the sheet you already hold - details, experience totals, and every trait added, removed, or changed - so you know what Overwrite will change; **Refuse** turns it down. If the sending chronicle cancels first, accepting fails and you can refuse it. An accepted character shows as visiting; **Send home** ends the visit and **Keep for good** makes it yours. Sending a character works from its sheet's **Send Sheet** panel: it waits at the other chronicle until one of their Storytellers accepts - there's nothing further for you to mark once they do, and the character stays active in your own chronicle the whole time a visit is open elsewhere, even at several at once. An offer nobody reviews expires after 60 days, and so does a visit you sent that no host accepted - its verification code stops working with it. Deleting a character closes its visits the same way: an offer still waiting can't be accepted, and an open visit ends.

**Keeping a visit current.** Either side can ask, from the character's own **Send Sheet** panel, once a visit is open - the other side has to agree before it starts. Agreed, any change made at home reaches the host on its own within a few minutes, with a running update log on the host's own panel naming when each one landed and what it touched; either side can turn it off again, which tells the other immediately. While it's on, the host's own copy is read-only to a player there - a Storyteller's own change or XP award still goes through, but is forwarded to home as a pending change in your Approval Queue (labeled "From [host]," since `submitted_by` is nobody local) rather than applied at the host. A host Storyteller can also share a free-text note about the visit, which arrives the same way, Storyteller-only, no sheet effect. See [Send Sheet](help/transfer.md) and [Approval Queue](help/approval-queue.md#a-change-forwarded-from-a-host-chronicle).

**Player-sent Grapevine files.** A player doesn't need a Storyteller on the sending end at all - anyone signed in can send their own exported `.gex` straight to your chronicle, joining it or just visiting for a game, and it lands in the same **Waiting for Review** list alongside transfers, with the same email to every HST and AST. Review checks any embedded verification code against its issuing chronicle automatically, so you see whether the file still matches what was exported before you decide; it also shows whether the sender asked to keep the character current. Accepting a join makes the sender a player here the same moment; accepting a visit works exactly like an inbound transfer, Send home and all - and, if the sender asked for it and the file carried a code, also sends a pairing request to the real home chronicle the code names, fire-and-forget, so an unanswered or refused request just leaves the copy here unpaired. One restriction that doesn't apply to your own transfers: a player-sent file can never overwrite a character it doesn't already own here. See [Send a Grapevine File](help/send-grapevine-file.md).

## 6. Notifications

When a Storyteller approves or rejects a player's submitted change, that player's account gets one email. If a batch approval clears several of one player's changes at once, they get a single summary email, not one per change - and a change that auto-approves (an XP award you grant directly, for example) never sends anything, since the player already knows they just made it.

A character transfer offered to your chronicle emails each of its HSTs and ASTs, so the offer doesn't sit unseen on the Import page.

Connecting a player's character to a plot, item, or location - or widening who a plot, item, or location's audience reaches - emails that character's own player when it genuinely makes something newly visible to them: never when they could already see it, never when that player made the connection themselves, and never for an NPC or a character with no player. A held plot (a rumor still waiting on its own release batch) stays silent until it's released, which notifies through its own batch email instead, not this one. Creating or editing a plot, item, or location through its own model - the demo reset, an import, an upgrade repair - never emails anyone; only the real screens you use day to day do. A player chooses Immediately, Daily digest, or Off for all of this on their own profile - see [Profile Settings](help/profile-settings.md).

On an Immediate-mode chronicle (Chronicle Setup's "Players and secrets"), a player telling another character a secret also emails your chronicle's HSTs, ASTs and narrators directly - the recipient already knows it, but it still needs your review before it can be passed on again. See [Secrets & Who's Who](help/secrets.md).

Turn this off for the whole chronicle under **Chronicle Access**. A player can also opt out for themselves from their own WordPress profile screen, next to the sheet-customization checkbox.

**Who got what.** The Storyteller Toolkit's **Email Log** tab records every email your chronicle sends, and every one it decides not to send, with the reason: who it was for, what it was about, its subject line, and whether the mail system took it. It never keeps the body of an email. On a plot, **Who was emailed about this plot** opens it already narrowed to that plot. Rows are kept 90 days. See [Email Log](help/email-log.md).

## 7. The Game Dashboard

Every chronicle has a fixed **My Chronicle** page and a fixed **Storyteller Toolkit** page - the same two pages regardless of how many chronicles this site hosts, each with a **Chronicle** switcher at the top to pick which one you're looking at.

**My Chronicle**'s **Dashboard** tab shows your own characters, your own pending changes, and your own plots - nothing from anyone else's sheet. Its **Characters**, **Sheet**, and **Edit** tabs are the character roster, read-only sheet view, and character editor; **My Plots & Rumors** is your own plot feed.

**Storyteller Toolkit** is Storyteller-only: its tabs (Dashboard, My Queue, Approval Queue, Characters, Players, Plots & Rumors, Secrets, Boon Ledger, Items & Locations, Game Nights, Releases, Downtime, Factions, Relationships, Email Log) only appear when you actually hold a Storyteller-level role *in the chronicle currently selected in the switcher* - an AST who only narrates one chronicle sees fewer tabs there than in one they HST. Its own **Dashboard** tab shows character counts by creature type and status, the pending-change count, the active-plot count, a **Characters Needing Attention** count (how many active characters are currently flagged on the Spotlight check - see §16), a roster-health count of players with no active character (click it to see who), and a feed of recent activity across the whole chronicle. Its **Characters** tab is the chronicle's whole roster: search it by name, filter by creature type or status, tick **Show NPCs instead of player characters** to switch lists, apply XP, assign or change a player, and click a name to open that character's sheet, where **Edit this character** opens the editor.

## 8. Plots, Actions, and Rumors

The **Storyteller Toolkit** page's **Plots & Rumors** tab is where plots live: create a plot, respond to player actions submitted against it, allocate action slots, and generate rumors that distribute to players via a saved query (see below) rather than by hand. Players see their own plot connections on **My Chronicle**'s own **My Plots & Rumors** tab.

Every character, PC or NPC, has its own plot, `<Character> [id] Plot`, made with the character. Only its player and the chronicle's Storytellers see it, each game date's actions for the character sit under it unless you nest them elsewhere, and it goes when the character does - it can't be deleted on its own.

A plot's **Who can see this** setting (Everyone / Storytellers and Narrators only / a rule you set against character traits) controls who reaches it at all; a new plot starts Storytellers-only. A player can post an action publicly or privately to Storytellers, and you can additionally direct a reply to specific characters only - see [Admin Guide → Who Can See a Plot, Item, or Location](admin-guide.md#who-can-see-a-plot-item-or-location) for the full picture, including images and PDFs attached under a plot's own **Files** section.

**Draft from a premise**, beside Allocate actions and Generate rumors, turns a one-line premise into a whole plot in one step - its Storyteller-only beats and a couple of held rumors, optionally grounded in characters, NPCs, and factions you check off first. There's no preview: it creates and opens the plot the moment you click it. See [Draft a Plot from a Premise](help/draft-plot.md).

## 9. Action & Rumor Settings and the Background-Use Ledger

Under **Beyond Elysium → Chronicle Setup → Action & Rumor Settings**, pick a chronicle to configure how many downtime actions a character receives each game date and which rumors generate automatically. The **Actions** tab sets personal actions per character, whether unused actions and growth carry forward week to week, whether Common Actions (from Influences and configured Backgrounds) are added automatically, an actions-per-level override table for specific dot ratings, and which Backgrounds grant an action at all - an Influence always does and is shown for reference only. The **Rumors** tab holds the eight rumor-generation toggles the Storyteller Toolkit's rumor generator reads. Group and Subgroup rumors make one rumor for each group among your active characters, reaching everyone in it: a character's group is its Clan, Tribe, Kith, Tradition, and so on, and its subgroup its Sect, Auspice, Seeming, or Guild, depending on the creature type. A **Restore Grapevine defaults** button is available if you want the original 1998 values instead of Beyond Elysium's own (higher personal-action, carry-forward-on) defaults - it does not touch your rumor settings.

Once a character's actions are allocated (in the Action Allocator, under Plots), each budgeted subaction - Personal, and any Influence or configured Background - gets its own **background-use ledger**: record what the character actually did with that action, and fill in the result once it's adjudicated. A background with no live budget can still have a use recorded against it (it shows under "Other backgrounds," with a note pointing back here) - recording is never blocked by a chronicle simply not having configured that background yet. **Clear all for this Character** and **Clear all for this Date** remove recorded uses only; they never touch a character's action budget itself. Players see and can record uses for their own characters directly from their character sheet's **Background uses** panel, and can clear their own use as long as no result has been recorded against it yet.

## 10. Building and Running Queries

Under **Beyond Elysium → Query Tool**, pick which of four inventories to search - **Characters**, **Items**, **Locations**, or **Rotes** - with a tab strip at the top of the tool. Each inventory offers its own field list (a location's Gauntlet rating and Security Level, an item's Type and Concealability, a rote's Level and Sphere prerequisites, and so on), build a filter against it, and either browse the results or run one of the five built-in statistics. Switching inventories clears the clauses on screen, since a clause built against one inventory's fields has no meaning on another's.

A query can be saved and reused; the Saved Queries list shows which inventory each one searches, and loading one switches back to that inventory automatically. A saved query is also what a rumor's target audience is defined by (**Characters** queries only) - build the query once, point the rumor at it, and it recalculates who's in scope every time it runs rather than freezing a player list at creation time.

Three bulk actions are available only on **Characters** results, for the same reason a rumor can only target characters: an Item, Location, or Rote result isn't a character, and the tool never offers an action that would only make sense as one. Select a set of rows to award XP, reset a resource pool's temporary rating back to its permanent one (the ordinary end-of-session "everyone's Willpower/Blood refills" chore, across the whole selection at once instead of one character at a time), or set the same status on every selected character - retiring a batch, or marking a group inactive at once. A bad or stale character ID in a selection never touches another chronicle's character; it's reported as failed rather than silently ignored or acted on.

**This is desk work.** Building a multi-clause query is best done at a keyboard. Its results still read on a phone: when there isn't room for the columns, each result becomes a card, the same as the roster and the approval queue.

## 11. Signed Character Sheets

Every printed sheet is a PDF generated on your own site rather than captured from the browser. It follows the Grapevine sheet: a header of short facts, the attributes side by side, then everything else in three columns, carrying on into the next column and page with "(cont.)"; the traits points are spent on and every pool carry one empty circle per point to fill in as it is spent (see [Print / Export](help/sheet-print-export.md#what-the-pdf-looks-like)). It prints on Letter when your site's language is set to a US, Canadian, Mexican or Philippine locale and on A4 otherwise; the print panel's **Paper** choice changes it for one print. Once your site has a signing certificate, it's digitally signed - a Storyteller who receives one can be sure the trait values on it have not been edited after the fact. This replaces the old browser print entirely; there is only the one Print button now.

**Signing needs a certificate on your site's host.** This is a one-time setup per site (not per chronicle), done by whoever has SSH/hosting access - if that isn't you, this section is what to hand them. Until it's set up, sheets and reports still print, but every page is stamped UNSIGNED, the file name ends in `-unsigned.pdf`, the sheet's Print / Export panel tells the player prints are unsigned, and an admin notice on every wp-admin page names exactly what's missing. An unsigned copy proves nothing about whether it was edited, so don't take one as proof of a visiting character's sheet.

**Generating the certificate:**

```bash
BE_KEYPASS='choose-a-real-passphrase' openssl req -x509 -newkey rsa:4096 -days 3650 \
  -cipher aes-256-cbc -passout env:BE_KEYPASS \
  -subj "/CN=Beyond Elysium Signing" \
  -keyout be-signing.key -out be-signing.crt
```

Place both files **above the webroot** (a sibling of `public_html`, never inside it) with the key at permissions `0600`. Then add three constants to `wp-config.php`, above the line that says `/* That's all, stop editing! */`:

```php
define( 'BE_PDF_SIGNING_CERT', '/full/path/above/webroot/be-signing.crt' );
define( 'BE_PDF_SIGNING_KEY',  '/full/path/above/webroot/be-signing.key' );
define( 'BE_PDF_SIGNING_PASSPHRASE', 'the same passphrase you chose above' );
```

**Why a passphrase at all, if the site has to be able to read it anyway?** It protects against exactly one real, common leak mode - the key file escaping on its own (an accidental commit, a stray backup, a directory listing) without the passphrase escaping with it. It is not protection against the site itself being compromised; nothing about local file permissions is. If you already have an unencrypted key from an older setup, leave the passphrase constant undefined - Beyond Elysium treats "no passphrase set" as "this key has none," not as a misconfiguration.

**What a signature means, and what it doesn't.** A self-signed certificate makes PDF readers report "signature valid, signer not trusted" rather than a plain green checkmark - that's expected, not a problem to fix. Trusting the certificate once (most PDF readers let you do this from the signature panel) makes it read as fully valid afterward. The signature proves the document hasn't changed since it was generated; it does not prove the sheet is still *current* - a character could have changed since. If your printed sheet is more than a session or two old, treat it as a record of that moment, not a live view.

## 12. Reports, Cards, and Batch Output

Beyond Elysium's Reports page (under the plugin's admin menu) generates every one of Grapevine's 19 remaining reports as a PDF, sharing the same signing setup as the character sheet (§11) - without a certificate, a report prints stamped UNSIGNED, the same as a sheet. Character Roster, Player Roster, Sign-In Sheet, Experience History, Player Point History, item/location/rote Cards, Plot Report, Master Action/Rumor Report, Action and Rumor Report, Search Report, Statistics Report, Vampire Status Report, Merits and Flaws Report, Influence Report, and Character Equipment. Pick a chronicle, pick a report, and Generate PDF - cards print several to a page, and any `table`-shaped report can be scoped to a saved query's own results instead of the whole chronicle, which is what "batch output" means here: one PDF for a chosen set of characters or objects, not a new mechanism to learn.

**Who can run which report.** Character and player reports (rosters, sign-in sheet, experience and point history, search, statistics, status, merits and flaws, influence, equipment) are for HSTs and ASTs. Plot, action, and rumor reports are also open to narrators. Everyone in the chronicle, players included, can run House Rules, the Game Calendar, and the item, location, and rote cards. The Reports page only lists what you can run.

**Reading the action reports.** The Master Action Report and the Action and Rumor Report list one line per action. An action allocation shows a line for each budget line (Personal, and each Background that grants actions) with its Total, Growth, and what's left Unused after every use, and each Background use recorded against it gets its own line with your result. A player's own post on a plot names their character on that plot. Plot threads don't link a reply to a post, so a reply shows as a post's result only when that player was the only one waiting on an answer. When several players had posted, their lines read "Reply came after several actions" and name the plot, so check the thread.

**Game Calendar lists the chronicle's game nights.** Each night on the Game Nights calendar appears, soonest first, with its date, start time, place and notes, in the PDF and on My Chronicle's Reports tab; a chronicle with none gets a short note saying so. A Storyteller's `[ST]` text in the notes is removed for players.

**House Rules is the 20th report, and the only one with no Grapevine counterpart.** It lists every catalog item, tiered power level, or tiered power family carrying a description (see the [Admin Guide](admin-guide.md#descriptions-and-approval-schedules-on-catalog-items)), grouped by schema block, and generates as the same PDF every other report does. Unlike the other nineteen, it can *also* be dropped directly onto a front-end page - as an Elementor widget ("House Rules" in the Beyond Elysium widget category) or the `[be_house_rules game="chronicle-slug"]` shortcode - for a live, always-current view players can browse without waiting for a Storyteller to generate anything: every member of the chronicle, plain players included, may run it.

**Item Cards can be scoped to one character's own held items.** A character sheet's Connections section (visible to anyone holding `be_manage_connections`) lets a Storyteller link a world-object item to a character - search for it by name, optionally add a note (shown under the connection once saved), and it's connected. The Item Cards report normally prints every item in the chronicle's catalog; add `character_id` to the request (the character sheet's own **Print My Items** action does this automatically) to print only that character's connected items instead, signed the same way every other report is (or stamped UNSIGNED, the same way).

A World Objects catalog entry can be shared across as many characters as hold one - connecting "the pistol" to fifty enforcers is fifty ordinary connections to the same item, not fifty copies. When one character's item needs to be genuinely unique (an heirloom, something that gets damaged or renamed), use **Duplicate** on that item in the World Objects editor instead of **New** - it opens a fresh create form pre-filled from the original so you're editing a starting point rather than typing it from scratch, and never changes the original item.

## 13. The Point Audit

Opening a character's sheet as a Storyteller adds **Point audit** to its actions list, alongside View history and Send Sheet. It lists every trait, power, resource, and identity field the character holds, priced against the exact same rules the purchase flow charges - never a second, independently-guessed number.

**This is not a bill, and it cannot be one.** A large share of what a real sheet holds has no cost recorded anywhere in the catalog yet - every MET attribute trait (Physical, Social, Mental), most identity fields, and any resource pool without a set XP rate. The audit lists every one of those lines too, marked with a plain reason ("catalog item has no cost", "no pricing rule exists for this yet") rather than silently showing 0 XP or leaving the line off the report. The coverage line ("Priced N of M lines") and the note beneath the total are there for exactly this reason - read them before treating the total as an answer. A large gap between the total and a character's own recorded XP is normal today, not a sign the player owes you anything.

## 14. Factions and Court Positions

A **faction** is any in-fiction group your chronicle tracks - a sect, coterie, pack, chantry, court, cabal, or anything else - with its own roster, its own goals, and its own **Who can see this** setting separate from either. Storytellers (HST and AST) create, edit, and delete factions from **Storyteller Toolkit → Factions**; a player proposes one for their own character from **My Chronicle → Propose a Group** instead, and every proposal goes to the Approval Queue (§4) like any other change - a faction is a chronicle-membership structure, not sheet data, so even an auto-approve chronicle still routes it to a Storyteller.

A faction's goals are member-and-Storyteller-only even when the faction itself is visible to everyone - a plain viewer reads its name, type, and description, never its goals or its roster. Each member row carries a **Rank** (an internal title, separate from a chronicle-wide position below) that a leader can set for their own faction directly; only a Storyteller can promote or demote a leader, and a faction can never be left with zero leaders while it still has members.

A **position** is a chronicle-wide office - Prince, Sheriff, Grand Elder, and the like - optionally scoped to a faction (a court's own Seneschal) or standing on its own, managed from **Storyteller Toolkit → Factions → Positions**. Changing a position's holder writes its full history automatically - the outgoing holder's row closes, a new one opens for the replacement - and marking a position not "publicly known" hides *who* holds it from anyone but a Storyteller without hiding that it's held at all.

See [Factions](help/factions.md) and [Positions](help/positions.md) for the full screen.

**Storyteller Toolkit → Relationships** (HST/AST only) charts character-to-character connections as a circle, grouped and colored by faction. It defaults to one focus character and whoever they're directly connected to; a "Show whole chronicle" view is only offered while 60 or fewer characters have any relationship at all, past which it stays narrowed to protect readability. See [Relationships](help/relationships.md).

## 15. Releases: Batches and the Recurring Schedule

Rumors and downtime answers don't have to go out the moment you write them. **Storyteller Toolkit → Releases** (anyone with Plots & Rumors access) groups them into **batches**: leave a batch's release date blank to hold it as a **Draft**, or set one to move it to **Scheduled**. A batch releases everything it holds at once and sends each reached player one summary email naming their own characters - never the content itself.

**Release schedule**, the collapsible panel above the batch list, is the chronicle-level alternative to picking a date by hand: add a weekly rule (a weekday and time) or a monthly rule (a day of the month and time), in any combination. The schedule controls *when*, never *what* - on the day and time a rule names, every batch you've left in **Draft** for this chronicle releases as-is, the same as clicking **Release now** on each yourself, just automatic. If nothing is sitting in Draft when a rule fires, nothing happens - it never fabricates a batch to release.

See [Releases](help/release-batches.md) for the full screen, including moving items between batches and what "Released is final" means.

## 16. Game Nights, Attendance, and the Spotlight Check

**Storyteller Toolkit → Game Nights** (HST, AST, and Narrator - a Narrator often runs the door) is where a chronicle's sessions live: create one with a date, time, and place; sign in who showed up (your own roster, plus visitors from other chronicles by name); and award Attendance XP once the sign-in is settled - a one-time action per session, not a recurring one.

Players file their own **After-Game Report** from **My Chronicle → After-Game Report**: what their character did, what they want next, and a staff-only field - one per character per session, editable until that session's reports-due time passes. Back on the session's own detail view, a Storyteller marks each report read and awards **Report XP** the same way Attendance XP works, keyed off who filed rather than who signed in.

**Spotlight** (HST/AST only, from the Game Nights tab with no session selected) is a chronicle-wide list of every active, non-NPC character's own attention: flagged when no Storyteller or Narrator has posted anything but a private note on any of their plots within the chronicle's own Spotlight-days setting (42 by default, changeable under **Session settings** on the same tab) - or never has at all. Flagged characters list first. This is the exact number behind the Game Dashboard's **Characters Needing Attention** card (§7) - the two can never disagree, since both are computed the same way.

A session's own detail view also carries a Storyteller-only **Recap** - key events, player decisions, who was involved and their status, a cliffhanger, and prep for next time, never shown to a player. **Draft recap** fills in whatever's still empty from that session's own attendance and after-game reports; review before saving, like every other draft tool.

See [Game Nights](help/game-nights.md) and [After-Game Report](help/after-game-report.md).

## 17. The Downtime Queue

**Storyteller Toolkit → Downtime** (same access as Releases) shows one game date's action plots at a glance: pick the date, filter to **Unanswered** or **All**, and see each character's submitted-action count, whether they've been answered, the answer's release state, and their own downtime window (Open, Not open yet, Closed, or No window - a session with neither an open time nor a deadline set enforces nothing, same as before this screen existed). Clicking a row opens that plot's thread directly to write or edit the answer, which is held per the Releases rules above (§15) unless you explicitly send it immediately.

Each row also carries its own assignee - who owns following up on that character's downtime. Assigning yourself is what surfaces it under **My Queue**.

See [Downtime](help/downtime-queue.md).

## 18. NPC Casting

From a session's own detail view (Game Nights → a session, §16), a Storyteller can cast any chronicle member - not staff only - to play an NPC for that one game, with an optional note just for that session. The cast member reads a **casting brief**: the NPC's name and public name if it has one, the session's date/time/place, your note, and the NPC's sheet minus every Storyteller-only section except its roleplaying notes - never its XP, status, connections, secrets, or real assigned player. The brief opens the moment they're cast and stays open through the day after the session, long enough to prep ahead.

A Storyteller who's also been cast finds it under **My Queue**'s Castings section; a plain player finds it on their own Dashboard's **My Castings** card.

See [NPC Casting Brief](help/npc-casting.md).

## 19. Secrets and Who's Who

A **secret** is a Storyteller-authored write-up kept separate from a plot's, item's, location's, or NPC's own text - who really owns the Chantry, what's actually in the crate - with its own **Who can see this** setting. Add one from the **Secrets** section at the bottom of that entity's own editor, then **Reveal to…** specific characters one at a time, noting how they learned it (In game, Downtime, Rumor, Told by someone, Other) and optionally holding the reveal for a release batch (§15). A secret defaults to Storytellers-only and *stays* that way even once revealed to someone - revealing only records who's been told; widening **Who can see this** is what actually lets that character read it. Players see everything revealed to their own characters under **My Chronicle → What I Know**.

If Chronicle Setup's **Players and secrets** row is on, a player can also log what their character picked up ("Log something I learned") and tell another character something they already know ("Tell someone"), both from the same What I Know screen. Neither reaches anyone until it's gone through the Approval Queue - a log waits for you to tie it to an existing secret or build a new one from it; a pass either waits outright (**Needs a Storyteller**) or reaches its recipient at once with you reviewing it afterward (**Immediate**). See [Approval Queue](help/approval-queue.md#a-logged-knowledge-claim).

**Who's Who** (My Chronicle → Who's Who, any chronicle member) is the opt-in public directory of NPCs and player characters alike - a display name, description, and portrait, and for a player character optionally who plays it, never the sheet itself or who's assigned to it. An NPC only appears once a Storyteller sets up its Who's Who Profile from the NPC's own Character Editor (§3); a player character only appears once its own player shows it from the same screen. Nothing here happens automatically just by creating a character.

See [Secrets](help/secrets.md), [What I Know](help/what-i-know.md), and [Who's Who](help/whos-who.md).

## 20. Fixing a Catalog Term You Spot in Play

A player says a Ritual name, a Merit, or a Discipline reads wrong on their Portuguese sheet - this is a one-row fix, not a ticket. Under **Beyond Elysium → System Config → Translations** (a WordPress administrator can grant you `be_manage_translations` if you don't have it), search the term, correct the text in its own field, and it's right on every sheet, every chronicle's own fork, and every printed PDF the next time anyone loads it - no file, no deploy, no developer. See [Catalog Term Translation](help/translations.md) and the Admin Guide's own section of the same name for the full screen, including the offline CSV round trip a volunteer would use for a bulk translation pass rather than one term at a time.

## Roles Reference

| Role | Access |
|---|---|
| HST | Everything in the chronicle - characters (including permanently deleting one), plots, queries, approval rules, schema and template customization, importing. Also the chronicle's own Creature types, Sub-Faction Restrictions, and New-character approval on Chronicle Setup. Creating, renaming, or deleting the chronicle itself, assigning its roles on Chronicle Access, and Plot Features are a site administrator's, by design. |
| AST | Everything an HST can do, including adding and removing players on the Storyteller Toolkit's **Players** tab, except Approval Rules, catalog and template customization (forking a schema block or a template), permanently deleting a character, and the three Chronicle Setup settings above - an HST's alone. Keeps import, transfers, editing characters, and the bulk XP/status/reset operations. |
| Narrator | Plots - creating and running them, responding to player actions, generating rumors, and the plot, action, and rumor reports. Can allocate actions for any character in the chronicle, seeing the full roster to do it, but cannot edit, delete, or create a character, and cannot use the Query Tool, which reads whole sheets and is for HSTs and ASTs. |
| Boons (Harpy) | The boon ledger only - recording and repaying boons. No Storyteller powers over characters or plots. Can still look characters up (needed to know who owes whom) and view reports. |
| Player | Creates and submits their own characters, and edits their own sheet - every edit still goes through the same approval process everyone else's does. Nothing outside their own characters, changes, and plot connections. |

This is the plugin's own chronicle-scoped role model (`be_game_members.role`, one of `hst`/`ast`/`narrator`/`boons`/`player`), checked in addition to whatever your underlying WordPress account can already do - both have to allow an action for it to go through. If your chronicle runs accessSchema, its own role paths are checked first and can grant access this table doesn't cover; this table describes the fallback every chronicle has regardless.

## Proposed Items

Players can propose an item, location or rote for their own character. It arrives in the **Approval Queue** alongside trait changes rather than in a separate list, so there is nothing extra to remember to check.

Approving one does two things in a single step: it adds the entry to your chronicle's catalog and connects it to the proposing character. The player asked for their character to have the thing, so approving gives them both. Rejecting writes nothing at all - leave a note saying why, especially if the chronicle already has something close.

Approving needs catalog rights as well as character rights. An HST and an AST have both. If you can see a proposal but the approve action refuses, you hold character-approval rights without item and location rights - you can still reject it, and any HST or AST can approve.

An item costs no experience. If your chronicle wants one to cost something, handle that as a separate change against the character's sheet.

Players cannot edit an item once it is approved; it belongs to the catalog then. Edit it yourself from [Items & Locations](help/world-objects.md).

A player proposing ordinary gear from the Mind's Eye Theatre rulebooks can start from a real book entry instead of typing it in by hand (**Start from a book entry** on their Propose an Item tab), and you have the same shortcut on your own side (**New item from the book** on Items & Locations) - either way, the entry's stats fill in automatically and the result is an ordinary editable item in your catalog, with nothing special about it once it's there.

## 21. Where Experience Comes From

Four places feed a character's experience, each for a different moment:

- **Game Nights** (§16) awards the same configured amount to everyone signed in, or everyone who filed a report - a one-time action per session.
- **Query Tool** ([Bulk actions](help/query-tool.md#award-xp-reset-a-pool-or-set-status-for-a-group-of-characters)) awards one amount to a whole group of characters you've just queried.
- **Apply XP**, on the [Characters list](help/character-list.md), is for a different amount per character: type one in each row and click Apply, or Apply All at once. A negative amount takes XP back, refused if it would take XP Earned below zero.
- **Request XP**, on a player's own [Character Editor](help/character-editor.md#request-xp), is theirs to use for XP earned somewhere this chronicle can't see for itself. It always waits for your review, shows up in the Approval Queue with what they told you, and you approve or reject it exactly like any other change.

All four write an ordinary entry to the character's history; none of them touches the Approval Queue except a player's own request.
