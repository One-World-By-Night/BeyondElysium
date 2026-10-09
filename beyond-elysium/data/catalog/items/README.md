# Item catalog

One file per book: `{book-slug}.json`, read by `Services\Item_Catalog` at request time and checked against `world-object-schemas.php`'s `item` shape by `Catalog_Validator`/`bin/validate-catalog`. Nothing here is written to a table - a chronicle's own "Add from the book" copy is a real `be_world_objects` row with `properties.book_ref` set to `{book}:{key}`, and later fixes to a file here never flow into a copy already made.

## Entry shape

```
{
  "key": "...",
  "name": "...",
  "object_type": "item",
  "description": "...",            // optional, free text
  "properties": { ... },           // any subset of the item property schema
  "source": { "book": "...", "code": "...", "page": N }
}
```

## Mapping rules (mundane gear)

- Bonus Traits -> `properties.bonus` (int).
- Negative Traits -> `properties.negatives`, one `trait_list` entry per trait.
- Concealability -> `properties.concealability` (string, verbatim).
- Damage -> `properties.damage_amount` (int; "One/Two health level(s)" reads as 1/2). No book surveyed so far prints a separate damage *type*, so `damage_type` stays unset until one does.
- Availability -> `properties.availability`, **one** `trait_list` entry whose `name` is the book's own availability line verbatim (a conditional line like "Police 4, Street 3 or Underworld 2 otherwise" is one condition, not several options, and is kept as one entry rather than split).
- Special Ability -> `properties.powers` (text, comma-joined for more than one).
- `item_type` is the book's own category for the entry (Melee, Throwing, Ranged, Firearm, Firearm Add-on, Armor, Shield); `item_subtype` narrows it further where the book does (Antique/Modern armor).
- A firearm's own printed **Rate** has no field of its own (not Bonus, Damage, or Special Ability) and goes in `description`, never folded into `powers`.
- **Armor has no dedicated property for the Health Levels it absorbs** - `world-object-schemas.php`'s `item` shape is built around a weapon's own stats, not an absorbing item's rating. Armor entries store Health Levels in `properties.level` (otherwise unused by mundane gear - it's only meaningful for Changing Breeds' fetishes, a different file) and restate it in `description`. A deliberate repurposing for this book, not a schema change.
- Anything a book prints that the fields above can't hold goes in `description`, never dropped.
- A stat block that names two different weapons under one entry ("Bonus Traits: Club: 2, Ax: 3") splits into two ordinary entries, one per weapon - a catalog entry's own numeric properties are each a single value.
- "None." as a book's own concealability text is this catalog's `NA` (no way to conceal it) - the same value several Dark Epics entries already use, kept consistent rather than spelled a second way.

## Mapping rules (fetishes and talens)

- `item_type` Fetish or Talen.
- Fetish Trait Cost -> `properties.level`.
- Gnosis -> `properties.tempers`, one entry `{ "name": "Gnosis", "count": N }`.
- Spirit Affinity -> `properties.item_subtype`.
- Melee Bonus Traits -> `properties.bonus`.
- The prose description -> `properties.powers`.
- A talen -> `properties.uses_max: 1`, unless the book states a different count.
- A weapon-shaped fetish that prints no Melee Bonus Traits line grants no combat bonus - `bonus` stays unset, never defaulted from its Fetish Trait Cost or Gnosis.
- A talen section that prints Gnosis and Spirit Affinity but no Fetish Trait Cost at all leaves `level` unset - some books price fetishes but not talens.
- A book that labels its cost field "Level:" instead of "Fetish Trait Cost:" still maps to `properties.level` - the field's own name, not its label, is what this rule tracks.
- A named Fetish subtype unique to one breed (Ananasi's Fylfots, the Nagah's Bindhi) is still `item_type: "Fetish"` - there is no separate mechanical category, and any shared framing the book gives the subtype as a whole is folded into each entry's own `powers` text.
- A talen whose own stated use count is a range ("between five and ten pinches"), not one fixed number, leaves `uses_max` unset rather than picking a number out of the range.

## Reprints

An item printed in an earlier book is listed once, from that book. A later book's entry with the same name and the same stats is left out entirely. One with different stats under the same name is its own entry, naming its own book, so a Storyteller picks the printing their chronicle actually runs.

## Left out

Formula-built items with no printed list (Mage Wonders, Wraith Relics, Changeling Treasures). Tabletop-only books. Locations and rotes - this folder is items only.

## Books

| File | Book | Code | What it covers |
| --- | --- | --- | --- |
| `dark-epics.json` | Mind's Eye Theatre: Dark Epics | WW05027 | pp.83-90, "Nuts and Bolts" - the base mundane gear list every other MET corebook's own gear measures against |
| `laws-of-the-night-revised.json` | Mind's Eye Theatre: Laws of the Night Revised Edition | WW05013 | "Weapon Examples"/Armor - 9 entries that differ from Dark Epics' own printing of the same gear (a divergent stat, not just a divergent page); every same-name/same-stats duplicate is left out per the reprints rule above |
| `laws-of-the-hunt.json` | Mind's Eye Theatre: Laws of the Hunt | WW05014 | "Weapon Examples"/Armor, pp.259-263 - 2 entries that differ from the accumulated catalog (Broadsword, Shuriken/Dart); 13 of its 15 printed entries are real duplicates |
| `laws-of-the-east.json` | Mind's Eye Theatre: Laws of the East | WW05016 | "Weapon Examples"/Armor, pp.186-191 - a Kuei-Jin/Cathayan sourcebook, mostly new content; 10 entries |
| `laws-of-the-reckoning.json` | Mind's Eye Theatre: Laws of the Reckoning | WW05037 | "Weapon Examples"/Armor, pp.249-256 - the Hunter (Imbued) corebook; its own `Arsenal` resource gates most availability, a real mechanical difference that keeps most firearms as their own entries even on matching stats; 16 entries |
| `laws-of-the-resurrection.json` | Mind's Eye Theatre: Laws of the Resurrection | WW05035 | "Weapon Examples"/Armor, pp.193-198 - independently written throughout; only 5 of about 20 printed entries carry a real divergence |
| `changing-breeds-1.json` | Mind's Eye Theatre: Changing Breeds | WW05019 | Fetishes/Talens - Nuwisha (pp.48-49), Corax (pp.100-101), Bastet (pp.214-216); the other breeds are in its sequel volumes |
| `changing-breeds-2.json` | Mind's Eye Theatre: Changing Breeds II | WW05024 | Fetishes/Talens - Gurahl (pp.101-103), Mokole across five cultural streams: Mokole, Gumagan, Makara, Mokole-mbembe, Zhong Lung (pp.190-197) |
| `changing-breeds-3.json` | Mind's Eye Theatre: Changing Breeds III | WW05034 | Ananasi's own "Fylfot" fetishes (pp.87-91, no Talens), Ratkin's own fetishes (pp.195-197, no Talens) |
| `changing-breeds-4.json` | Changing Breeds 4 | UN0070 | Nagah's own Fetishes/Talens/Bindhi (pp.98-102), Rokea's own fetishes (pp.205-206, no Talens); the last of the four Changing Breeds volumes - Kitsune is never covered in this line, only mentioned in passing by every other breed's own sourcebook |
