=== Beyond Elysium ===
Tags: larp, character sheet, mind's eye theatre, world of darkness, chronicle
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Character management for Mind's Eye Theatre LARP chronicles, built for One World by Night.

== Description ==

Beyond Elysium keeps a chronicle's characters: the sheets, the changes players submit, and the Storyteller approval in between.

Eleven creature types ship with it: Vampire, Werewolf, Mage, Changeling, Wraith, Demon, Mummy, Kuei-Jin, Mortal, Fera, and Bête. Sheets are built from shared blocks (trait lists, tiered powers, resource pools, identity fields), so there is no per-creature code, and a chronicle can adjust a block for its own game without touching anyone else's.

* Players build their characters, submit changes, and track their experience.
* Storytellers review submissions, run plots, actions, and rumors, and manage the roster.
* Each chronicle's characters, plots, and changes are its own. A Storyteller reaches only the chronicles they run.
* Grapevine 3.01 exchange files (.gex) import, and characters export back to .gex with a verification code the receiving chronicle can check.
* Printed sheets and reports are digitally signed PDFs.

== Installation ==

1. Install and activate Elementor, which Beyond Elysium requires.
2. Upload the plugin zip under Plugins > Add New > Upload Plugin, then activate it.
3. Open Beyond Elysium in the admin menu and work through Chronicle Setup.

Signed PDFs need a certificate and key defined in `wp-config.php`. Until they are, an admin notice gives the exact constants and the command that generates both.

== Frequently Asked Questions ==

= Does it need One World by Night's other plugins? =

No. It runs on a bare WordPress install with Elementor. Where accessSchema is installed and enabled, chronicle roles can come from it too.

= Can it import a full Grapevine game file? =

Not yet. Export an exchange file (.gex) from Grapevine and import that.

= Where are the release notes? =

https://github.com/One-World-By-Night/BeyondElysium/releases

== License ==

GPL-2.0-or-later. Signed-PDF output bundles tecnickcom/tcpdf (LGPL-3.0-or-later), which is compatible with GPLv3 but not GPLv2, so the combined work is distributed under GPLv3 terms through this plugin's "or later" clause.
