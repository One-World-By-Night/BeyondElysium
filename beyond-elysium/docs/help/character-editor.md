# Character Editor

Where you build a new character and change an existing one. Every trait, power, pool, and identity field on a sheet passes through this screen before it reaches the sheet itself.

## Who can use this

Players can create their own characters and edit their own. Storytellers (HST and AST) can create a character for anyone and edit any character in the chronicle - including fields a player's own copy of this screen never shows: status, the assigned narrator, and the Storyteller's private notes. A Narrator or the chronicle's Harpy who isn't also a member with player access can't create or edit a character here at all.

You can only open this screen for a character you own, unless you're a Storyteller. Anyone else gets an error, not a locked-down view.

## How to get there

My Chronicle → **Character** tab → **Edit** view.

- With a character already picked - from the Characters tab, or the **Edit** view of the Character section - this opens straight into editing it.
- With no character picked yet, a Storyteller gets this blank one-page form to start a new one. Anyone else gets the [Character Builder](character-builder.md) instead.

A Storyteller creating a character from wp-admin's Characters page lands on this same screen.

## The screen

### New Character (no character picked yet)

This one-page form is a Storyteller's: it is where you make NPCs, quick NPCs, sheets that already exist and characters with extra sections. For a player character it offers **Use the builder**, which opens the [Character Builder](character-builder.md) on the same chronicle.

- **Name** - required.
- **Creature Type** - a dropdown of this chronicle's allowed creature types. Skipped if you arrived here already set to build a specific type.
- **This is an NPC** - a checkbox, shown only to a Storyteller. Checking it switches the form to the richer NPC layout, which adds a Storyteller-only section for voice, mannerisms, and plot hooks, and reveals a second choice:
  - **Full sheet** - the complete NPC layout, same as above.
  - **Quick stats only** - a short layout with just enough (Physical/Social/Mental,
    Willpower, Health, key Abilities/Powers/Equipment) to run this NPC in a scene without
    building out a whole character. See **Make Full NPC** below for upgrading one later.
- The stack's own sections below, exactly like the sheet sections described below - fill in whatever you want the character to start with.
- **Create Character** - writes the character. Nothing you build here is priced or reviewed the way a later change is: whatever you put on the starting sheet is what the character gets. The build must fit inside what a new character starts with: a build that costs more XP than the chronicle's **Starting experience** is refused, with a message that names both numbers. Tick **Existing character** to enter a sheet that already exists, which charges nothing.

If you're already a member of this chronicle, the character exists right away (or starts **pending**, if the chronicle requires new-character approval or the character holds a pick your Approval Rules hand to a Storyteller, until a Storyteller sets it active; a Storyteller's own request for a status stands). If you aren't a member of this chronicle yet, this is instead a request to join: you see a "Request Sent" notice, the character waits with nobody able to open its sheet, and you become a player here only once a Storyteller approves it.

### Header (editing an existing character)

- The character's portrait, if it has one. Add or change it on the **Story** view.
- The character's name.
- **This is an NPC** - a checkbox, shown only to a Storyteller looking at a character they can manage. Flipping it re-loads the sheet under the other layout immediately.
- **Assigned to** - which Storyteller owns this NPC, shown once it's flagged as one. See [My Queue](my-queue.md).
- **Make Full NPC** - shown only on a Quick NPC. Switches it to the full layout for good; there's no way back to Quick from here.
- A note reading "You can view this sheet but not edit it" in place of every edit control, if you can see this character but can't change it.

### Background, Notes, and the Who's Who profile

Background, Notes, and the [Who's Who](whos-who.md) profile moved to the **Story** view of the Character section, where they save as you type and a Storyteller's RP notes sit. See [My Chronicle](my-chronicle.md).

### Secrets (NPCs only)

Shown only to a Storyteller editing an NPC, once it already exists - a write-up kept separate from the NPC's own sheet, with its own audience and reveals to specific characters. See [Secrets](secrets.md).

### Hooks (NPCs only)

Shown only to a Storyteller editing an NPC that already exists - a folded panel listing every plot this NPC is connected to, split into **Open** and **Resolved**, each with its latest entry's date. Click a plot's name to open it on the Storyteller Toolkit. An NPC connected to nothing yet shows "None." under both headings.

### Request XP

Shown only to a player, on their own character, on the **Story** view - not to a Storyteller. Folded by default. For experience earned somewhere this chronicle can't see for itself, most often a game or event run outside Beyond Elysium entirely:

- **Amount** - a whole number from 1 to 10,000.
- **Where you earned it** - required, up to 200 characters.
- **Date played** - optional; can't be after today.
- **Details** - optional, up to 2,000 characters.
- **Send request** - sends it right away, separately from anything in your cart, which stays there. The panel then reads "Sent. Your Storytellers will review it." and clears.

A request always waits for a Storyteller, whatever this chronicle's approval settings are, and shows in the Approval Queue with what you typed. See [Player Guide](../player-guide.md#2-editing-your-sheet) and [Approval Queue](approval-queue.md).

### Sheet sections

The rest of the screen is the chronicle's own template, one box per section, in the same layout the read-only sheet uses:

- **Trait lists** (Abilities, Backgrounds, Merits, and the like) - each held entry shows as a compact row with an edit button. **+ Add** opens the same form for a new one: choose a name from the catalog or type your own where the section allows it, set a count or level, optionally a specialization (or, for a repeatable entry, a "Who or what?" answer) and a note. **Adding a name you already hold raises that entry instead of starting a second one** - a specialization labels one holding, so a different focus does not buy a second Brawl; only entries the catalog marks as repeatable (two Retainers, two fields of study) get a row each, one per answer. Removing a held entry marks it for removal rather than deleting it outright, so you can still undo it before you submit. See [Trait Lists](trait-editor.md).
- **Powers** (Disciplines, Gifts, Arts, and other leveled catalogs) - add a power, then raise or lower it with a +/− stepper, or switch to a checklist that names every rung up to your current level. **Rated powers and Elder-and-above picks are two separate lists**: the rating is a number on the ladder, a pick is a named power above it, and the two never share a count. Picks sit below the rated powers, grouped by rank. A blood-sorcery power also asks for a tradition. See [Powers](power-editor.md).
- **Resource pools** (Willpower, Blood Pool, Rage, and the like) - a permanent and a temporary row per pool, each with its own +/− stepper and dot display.
- **Identity fields** (Clan, Nature, Generation, and similar named fields) - a dropdown, checkbox group, number box, text box, or text area, depending on the field.

See [Resource Pools & Identity Fields](pools-identity-editor.md) for both of the last two in depth.

### Add a section (Various characters)

A Various character's editor lists every creature type's powers from the start, so nothing has to be added before you can pick Obtenebration, a Gift or a Sphere: Disciplines and Blood Magic (Vampire), Gifts (Werewolf and Fera), Arts and Realms (Changeling), Spheres (Mage), Arcanoi (Wraith), Lores (Demon), Edges (Hunter), Disciplines and Shintai (Kuei-Jin), Hekau (Mummy), and Psychic Phenomena, Hedge Magic, Martial Arts, Theurgy, Fomori Powers and Bioenhancements. Abilities, Backgrounds, Merits, Flaws and Tempers are each one list holding every creature type's entries with duplicates removed, and **Other Powers** is for powers you make up. A section with nothing in it is left off the finished sheet.

A Storyteller editing or creating a character whose creature type is **Various** also sees an **Add a section** button under the sections, for everything the editor does not already list (rituals, rotes, combination Disciplines, and the rest). It opens a list of the sections not on the sheet yet, which you can type into to narrow it, grouped under a bold heading for the creature type that owns it (Vampire, Werewolf, Mage, and the rest), then the sections several creature types share, then any that belong to none. Pick one and click **Add**: an empty box for it appears on the sheet, and the first entry you add to it is what keeps it there - a section that holds nothing is not saved. Sections a Various character already holds show on its sheet, in print and in the signed export without being added again. Only a Storyteller sees this button, and only on Various; every other creature type keeps the sections its template lists.

An NPC's Storyteller-only roleplaying-notes section (voice, mannerisms, motivations, and the like) also carries a **Draft roleplaying notes** button beside its heading, disabled once every one of its fields already has text (a field you have never written counts as empty, so a brand-new NPC can be drafted straight away). On a demo chronicle it always stays clickable so it can explain that drafting is switched off. See [AI Writing Assist](writing-assist.md).

### Reordering a List

A trait list or powers section a chronicle has set to let players choose their own order (see [Schema Blocks](schema-blocks.md)) shows flat, in whatever order you last left it, with no grouping - and a **Reorder** button above the list. Click it to drag rows into place, or use the **▲**/**▼** buttons beside each one; **Save order** sends the new order right away, with no approval and no XP cost, since nothing about what you hold is changing. **Cancel** leaves the order exactly as it was. A chronicle that turns this off for a section goes back to the normal grouped display, and the Reorder button disappears.

### The cart

Everything you change in the sections above goes into your cart instead of touching the sheet. The cart is the set of changes you have made and not yet sent, kept on this device for each character, so a closed tab does not lose it.

- **The meter.** A bar pinned at the bottom of Edit always shows what you can spend: "10 XP to spend". With changes in the cart it reads "Cart: 3 · 6 XP · 4 left", and "2 XP over" in red when the cart costs more than you have free. XP held for changes still waiting for a Storyteller is not free to spend, so it is already taken off. A Storyteller sees the count and price with no limit. The same "Cart: 3 · 6 XP" appears as a chip in the character's header in every view, and either one opens Review.
- **Review** opens a dialog (the whole screen on a phone). Its lines come in two groups, **Applies now** and **Waits for a Storyteller**, each with its price and, for a line that waits, the reason. **Remove** takes one line out and puts that part of the sheet back as it was. Under the lines are the **Total**, what is **Spent now**, what is **Left after**, and "6 XP held until a Storyteller decides" for the lines that wait. A **Note to your Storyteller** (up to 1,000 characters) shows when something waits and goes with every change you send; Storytellers read it in the Approval Queue as "Note from the player".
- **Submit** sends the whole cart in one request. Review then reads like an order confirmation: "Sent. 2 applied now. 3 are waiting for a Storyteller, with 6 XP held." with **See it in History**. Submit is off while a line cannot be submitted (Review names it) or the cart costs more than you have free to spend. If the server refuses the set, the cart stays as it was.
- **Empty cart** puts every line back as the sheet has it, after asking first, and keeps you where you are.
- **Changed since you added it.** If a Storyteller changes a part of the sheet that your cart also changed, that part comes back from the sheet, not from the cart, and the cart says "The sheet changed since you added this: Occult. Add it again if you still want it."
- Changes you send leave the cart. Those that wait become an order in **History**, and their XP is held for them.

You can also add to the cart without opening Edit: the panel that opens when you tap a trait (see [Character Sheet](character-sheet.md)) has **Add to cart**, for example "Raise to x4 · 1 XP", and Edit's own trait rows, power rows and Add dialog have an **About** button that opens the same panel.

If you leave with anything in the cart, your browser warns you before you navigate away, and the cart is saved on this device as you go.

## Common tasks

### Create a new character

1. Open My Chronicle and pick your chronicle, if you play in more than one.
2. On the **Characters** tab, click **+ New Character**. A Storyteller can also do this from the Characters tab of the [Storyteller Toolkit](storyteller-toolkit.md), where **+ New NPC** opens the form with the NPC box already ticked.
3. Type a **Name** and pick a **Creature Type**. A Storyteller also sees **Various**, the creature type that can hold any section from any creature type.
4. Fill in whichever sections you want the character to start with.
5. Click **Create Character**.

### Edit an existing character

1. Open the character and click **Edit** in the row of views.
2. Use each section's own control to add, change, or remove a trait, power, pool value, or identity field.
3. Click **Review** in the meter at the bottom (or the cart chip in the header), and check each line and the totals.
4. If something waits for a Storyteller, you can write a **Note to your Storyteller**. Then click **Submit**.

### Update Background or Notes

1. Open the character and click **Story**.
2. Type into **Background** or **Notes**.
3. There is nothing to click: it saves a moment after you stop typing, and the line under the fields reads **Saved**. This is independent of your cart.

### Come back to your cart

1. Open the character. The cart chip in the header, and the meter in Edit, show what you left in it.
2. A part of the sheet that a Storyteller changed in the meantime is not brought back; the cart names it so you can add it again.
3. A whole-sheet draft saved by an earlier version appears once in Edit with **Restore** or **Discard**; restoring it turns it into your cart.

### Empty the cart

1. Click **Review**, then **Empty cart**.
2. Confirm "Empty the cart?" - every line goes back as the sheet has it, and you stay where you are.

## Things to know

- **Joining vs. creating.** Starting your first character in a chronicle you don't already belong to is a request to join, not an ordinary create: it waits pending, the chronicle's Storytellers are emailed, and a Storyteller setting it active is what makes you a player there. Only one such request can wait per chronicle at a time.
- **Every character gets its own plot.** Creating one - PC or NPC - also creates `<Character> [id] Plot`, which only the character's player and the chronicle's Storytellers see. See [Plot Manager](plot-manager.md).
- **A starting sheet isn't priced.** Unlike every later change, what you build on a brand-new character's sheet is written as-is - no XP cost, no Storyteller review of the individual entries. If your chronicle requires new-character approval, the checkpoint is the character's own status, not what's on the sheet.
- **Health fills in on its own.** A new character's Health section is pre-filled the moment it's created - it isn't something you build yourself.
- **Some fields are Storyteller-only, even on your own character.** Your status, your assigned narrator, and your Storyteller's private notes about you never appear here for a player, and can't be changed here even by you.
- **Numbered powers add up.** Buying a fresh power at level 3 costs levels 1, 2, and 3 together - not level 3 alone.
- **Some powers spend Traits instead of XP.** A few catalogs (Hunter Edges, for one) price a power in Traits spent from a named pool elsewhere on the sheet, never XP - buying one marks that many dots on the pool "spent" (shown beside its dots) without lowering the pool's own rating, and removing the power frees them again. Buying one needs enough unspent dots in that pool, and some catalogs also require already holding that power's own path at the rank below it.
- **A pool that reads "Raise with [pool] (N)" doesn't take a dot click.** A few pools (Hunter's Virtues, for one) only rise by converting N temporary points from another named pool - click the button, not the dots, and it refuses if that pool doesn't have enough temporary points right now. While you are filling in a brand-new character, those pools do take dot clicks, up to the free dots the creation rules give (three Virtue dots for a Hunter); the Build Tally warns you when you are past them, and the sheet is refused until you are back within them. Once the character exists, only the button raises them.
- **A rating above the book's maximum waits for a Storyteller.** A few pools can reach further than the book allows (a Mummy's Balance goes to 10, the book stops at 5). Asking for a rating above the book's maximum sends the change to a Storyteller with the reason, and a new character can't start above it.
- **A custom name always needs review.** Typing a name that isn't in the catalog only saves at all where that section allows it, and even then it always goes to a Storyteller for approval, no matter how your chronicle has auto-approval configured.
- **Dots are all one size** - on this screen, on the sheet, and on a signed PDF. A pool's points and a trait's rating use the same dot.
- **The cart is per device.** Your cart is saved to this browser as you edit, so a closed tab or a crash doesn't lose your work - but it lives only on the device you used, until you send it or empty it.
- **A kept-current visiting copy can't be edited here.** While this character has an open, agreed kept-current visit elsewhere, editing or submitting a change is refused until the visit ends - "Kept current from [chronicle]. Make changes there." Edit it at its real home instead. See [Send Sheet](transfer.md).

## Troubleshooting

- **My changes disappeared after I submitted.** Sent lines leave the cart. Those that wait for a Storyteller show as an order in the **History** view, where you can withdraw them, and on your Dashboard.
- **I can't see the cart.** The meter sits at the bottom of Edit, and the cart chip in the header shows once the cart has something in it.
- **Submit won't click.** The cart costs more than you have free to spend (the meter reads "XP over"), or a line cannot be submitted as written - Review names it. Remove it, reduce what you're buying, or ask a Storyteller for more XP.
- **A name I typed says it isn't in the catalog.** Check the spelling first. If it's genuinely new, this section may not accept custom entries at all - ask a Storyteller.
- **"Your character is already waiting for this chronicle's Storytellers to approve it."** You already have a join request pending here - wait for a Storyteller to review it before starting another.
- **I can't open a character I know exists.** Either it isn't linked to your account yet, or it belongs to someone else and you're not a Storyteller here - ask a Storyteller to assign it to you.
- **Nothing I submitted saved.** The whole set is submitted together and fails together, and the cart is left as it was - read the error in Review, fix what's wrong, and click **Submit** again.
- **"Kept current from [chronicle]. Make changes there."** This character is a kept-current visiting copy - edit it at the chronicle named instead; it reaches here on its own.

## Related

- [Character Sheet](character-sheet.md)
- [Trait Lists](trait-editor.md)
- [Powers](power-editor.md)
- [Resource Pools & Identity Fields](pools-identity-editor.md)
- [Approval Queue](approval-queue.md)
- [Send Sheet](transfer.md)
- [AI Writing Assist](writing-assist.md)
- [Who's Who](whos-who.md)
- [My Queue](my-queue.md)
- [Roles](roles.md)
- [How Approval Works](approval-flow.md)
- [Player Guide](../player-guide.md#2-editing-your-sheet)
- [Storyteller Guide](../st-guide.md#3-making-characters)
