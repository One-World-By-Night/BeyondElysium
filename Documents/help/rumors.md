# Rumors

Adds a rumor to the chronicle - written by hand, or generated automatically from real chronicle data for a game date.

## Who can use this

Storytellers (HST and AST) and Narrators - the same access that opens the rest of [Plots & Rumors](plot-manager.md). A player never opens this tool; a rumor that targets one of their characters shows up on their own [My Plots & Rumors](my-plots.md) feed, and, unless they've turned off notifications, they're emailed once a committed rumor first reaches one of their characters.

## How to get there

Storyteller Toolkit → Plots & Rumors → **Generate rumors** button (on the plot grid), or **+ Rumor** from inside an open plot or action, which pre-fills that plot as the parent.

## The screen

Two sections.

### Write one by hand

- A **rumor title**.
- A rich-text description box ("What's being whispered…", with AI Assist).
- A parent dropdown - any existing plot or action, not just a top-level one - or "No parent".
- **Add rumor**.

### Generate from chronicle data

- A **game date** field and **Preview**.
- Once previewed, a list of the rumors the generator would create for that date - each with its title, its category, and how many characters it currently reaches - or a message that nothing new would be generated.
- **Commit N** - writes every listed rumor. Once committed, a confirmation replaces the button.

## Common tasks

### Write a rumor by hand

1. Open Storyteller Toolkit → Plots & Rumors → **Generate rumors**.
2. Type a **rumor title** and, if you want, what's being whispered.
3. Optionally pick a parent plot or action.
4. Click **Add rumor**.

### Generate the standard rumor set for a game date

1. Open **Generate rumors**.
2. Pick the **game date**.
3. Click **Preview**.
4. Check the list - each rumor's recipient count tells you who it currently reaches.
5. Click **Commit N**.

### Nest a rumor under a plot or action

1. Open the plot or action first.
2. Click **+ Rumor** in its action bar - Rumors opens with that plot already picked as the parent.

## Things to know

- **A generated rumor starts with no content** - it's a title and a target only, until you open it under [Plots & Rumors](plot-manager.md) and write it in, the same as any other plot.
- **The generator never repeats a title already used for that exact game date** - previewing or committing again for the same date only ever adds what's genuinely new.
- **What generates depends on your chronicle's Action & Rumor Settings.** Public Knowledge and carrying the previous date's rumors forward are on by default; personal, race, group, subgroup, and influence rumors are configured there too. See [Action & Rumor Settings](apr-settings.md).
- **A group or subgroup rumor reaches everyone who shares that same value** - a character's group is its Clan, Tribe, Kith, Tradition, or similar, and its subgroup its Sect, Auspice, Seeming, or Guild, depending on creature type.
- **Previewing never sends mail or writes anything** - only Commit does, and only a committed generation emails matched players.
- **A rumor's reach is computed live from its target**, not fixed at the moment it was created - if a rumor targets "Brujah" and a character's Clan changes later, that rumor's reach changes with it.
- **A rumor you write by hand carries no game date**, unlike one the generator creates.

## Troubleshooting

- **"Failed to preview rumors for this date." / "Failed to commit rumors for this date."** Try again.
- **"Failed to create this rumor."** A title is required.
- **"Nothing new for this date - every title the generator would produce already exists."** Write one by hand instead if you need another.
- **A rumor isn't reaching a player you expected.** A personal, race, group, subgroup, or influence rumor is only ever generated when a currently active character justifies it - but once generated, it reaches every character matching its target, active or not.
- **I don't see this button.** You need a Storyteller or Narrator role in the chronicle currently selected.

## Related

- [Plots & Rumors](plot-manager.md)
- [Action & Rumor Settings](apr-settings.md)
- [My Plots & Rumors](my-plots.md)
- [Roles](roles.md)
- [Storyteller Guide](../st-guide.md#9-action--rumor-settings-and-the-background-use-ledger)
