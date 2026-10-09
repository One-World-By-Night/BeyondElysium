# Secrets

A Storyteller-authored write-up you keep separate from a plot's, item's, location's, character's, or NPC's own text - who owns the Chantry, what's really in the crate, why the Prince fears the Sheriff. A secret has its own real "who can see this" setting, and you choose exactly which characters have been told, one at a time.

## Who can use this

Storytellers (HST and AST) create and manage secrets and their reveals. A player never creates one - they only ever see **What You Know**, the secrets already revealed to their own characters.

## How to get there

The Storyteller Toolkit's **Secrets** tab, which lists every secret in the chronicle, or the **Secrets** section at the bottom of a plot's detail view, or an item's, location's, or NPC's own editor.

## The screen

- A list of this entity's own secrets, each collapsed to its title. Click one to open it. On the Toolkit's **Secrets** tab the list holds every secret in the chronicle: search it by title, and each row names what the secret is attached to. **+ New Secret** there starts one attached to a plot, item, location, character or NPC, or to nothing at all (the default).
- Inside an opened secret: its write-up, its own **Who can see this** (the same Everyone/Storytellers/rules choice a plot or item has), and **Revealed to** - everyone it's been told, each with a **Remove** button.
- **Reveal to…** - pick a character, how they learned it (In game, Downtime, Rumor, Told by someone, or Other), and optionally **Hold for a release batch** - see [Releases](release-batches.md). **Reveal** adds it.
- A reveal a player's own pass created shows **Told by** and the telling character, rather than a Storyteller's name, in **Revealed to**.
- **Add Secret** at the bottom - a title and write-up starts a new one, storytellers-only by default until you widen it.
- **Delete**, on each secret - removes it and every reveal on it.

## Things to know

- **A secret defaults to Storytellers-only, and stays that way even once you reveal it to someone.** Revealing a secret to a character records who's been told - it does not by itself change who can see the secret. Set **Who can see this** to **Only characters matching rules I set** (or **Everyone**, once it's common knowledge) for a reveal to actually reach that character - the same "audience decides who sees it, connections and rules decide who among them" rule every other audience-gated thing in this plugin follows.
- **A held reveal works exactly like a held rumor or downtime answer** - it doesn't reach the character until its release batch goes out. See [Releases](release-batches.md).
- **Deleting a secret takes every reveal on it with it.** There's no way to delete just the secret and keep a record of who knew it.
- **`[ST]...[/ST]` markers are still stripped** from a secret's write-up for anyone who isn't a Storyteller, the same as everywhere else - even a character who's genuinely been revealed the secret.
- **Players can log and pass secrets of their own, if your chronicle allows it** - a per-chronicle switch in Chronicle Setup ("Players and secrets": Off, Needs a Storyteller, or Immediate). A player's **log** ("I learned something") is not a secret yet - it arrives in the Approval Queue, where you either tie it to an existing secret (search by title) or build a new one from it; approving either way writes an approved reveal for the logging character. A player's **pass** (telling another character something their own character already knows) also goes through the Approval Queue unless your chronicle is set to Immediate, in which case the recipient reads it right away and the queue entry is just your after-the-fact check - refusing it removes what they were shown. On Immediate, the recipient's player is emailed (per their own [Plot posts and new things your characters can see](profile-settings.md) choice) that their character now knows something, and the chronicle's HSTs, ASTs and narrators are emailed too, so the still-needed review doesn't go unnoticed.

See [Approval Queue](approval-queue.md) for what a waiting log or pass looks like there.

## Troubleshooting

- **"Failed to load secrets."** Refresh and try again.
- **"Failed to create this secret."** Make sure a title is filled in.
- **A reveal didn't show up for the player.** Check the secret's own **Who can see this** - it needs to be **Only characters matching rules I set** (a reveal counts as one of the matching characters) or **Everyone**, not the Storytellers-only default.
- **I tried to reveal the same character twice.** Refused - a character can only be revealed a given secret once; remove the existing reveal first if you need to change how or when they learned it.

## Related

- [What I Know](what-i-know.md)
- [Plots & Rumors](plot-manager.md)
- [Items & Locations](world-objects.md)
- [Releases](release-batches.md)
- [Approval Queue](approval-queue.md)
- [Chronicle Setup](chronicle-setup.md)

