<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every player-reachable reader of a plot, plot entry, world object, attachment, or card
 * report must consult `Services\Audience` before returning a row - a plot, item, location,
 * or file's own `audience`/`audience_rules` (or a plot entry's own `audience`) is otherwise
 * silently ignored by whichever reader forgot to check it, exactly the shape of gap this
 * item's own U5/U6 work found and fixed twice already (`World_Objects_Controller`'s create/
 * update wiring, `Plots_Controller::get_item()`'s embedded entries list).
 *
 * Modelled on `FreeTextCoverageTest`: a `KNOWN` map of every player-reachable method to the
 * entity type it returns, asserting each method's own body calls `Audience::` somewhere.
 * `resolve_plot_for_membership()`/`resolve_visible_attachment()` are private helpers, not REST
 * callbacks themselves, but every public reader that can disclose a plot or an attachment's
 * existence routes through one of them - listing the helper once covers every caller.
 *
 * Honest limit, as with its sibling guards: this proves a *known* reader consults the
 * service, not that no unknown reader exists. §1.1.0 §8 also names the secrets routes, the
 * casting brief, and `Entries_Controller`'s rumor levels as readers a later 1.1.0 item adds -
 * none of which exist yet; each is added to `KNOWN` the same release it's built, not guessed
 * at here. `Npc_Profiles_Controller` and `Factions_Controller` (factions and positions) are
 * both real now (1.1.0 §3.7, F1/F2) and already listed below.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §8
 */
class AudienceCoverageTest extends TestCase {

	/**
	 * Every currently-existing player-reachable reader, mapped to the entity type it returns.
	 * Adding a new reader of a plot/entry/world-object/attachment/card is a deliberate act:
	 * add it here, or explain in a code comment why `Audience` does not apply (matching
	 * `Report_Document::build_card()`'s own rote/boon carve-out).
	 */
	private const KNOWN = [
		'REST/Plots_Controller.php::get_items'                    => 'plot',
		'REST/Plots_Controller.php::get_item'                     => 'plot',
		'REST/Plots_Controller.php::get_my_plots'                 => 'plot',
		'REST/Plots_Controller.php::get_visible_characters'       => 'plot',
		'REST/Plots_Controller.php::resolve_plot_for_membership'  => 'plot',
		'REST/Entries_Controller.php::get_items'                  => 'plot_entry',
		'REST/World_Objects_Controller.php::get_items'            => 'world_object',
		'REST/World_Objects_Controller.php::get_item'             => 'world_object',
		'REST/Attachments_Controller.php::resolve_visible_attachment' => 'attachment',
		'Services/Report_Document.php::build_card'                => 'item|location',
		'REST/Npc_Profiles_Controller.php::get_items'             => 'npc',
		'REST/Npc_Profiles_Controller.php::get_item'              => 'npc',
		'REST/Factions_Controller.php::get_items'                 => 'faction',
		'REST/Factions_Controller.php::resolve_visible_faction'   => 'faction',
		'REST/Factions_Controller.php::get_positions'             => 'position',
	];

	public function test_each_known_reader_actually_consults_audience(): void {
		$missing = [];

		foreach ( self::KNOWN as $qualified => $entity_type ) {
			[ $file, $method ] = explode( '::', $qualified );
			$body               = $this->method_body( $file, $method );

			if ( null === $body ) {
				$missing[] = "{$qualified} -> method not found in {$file}";
				continue;
			}
			if ( ! str_contains( $body, 'Audience::' ) ) {
				$missing[] = "{$qualified} (returns {$entity_type}) -> never calls Audience::";
			}
		}

		$this->assertSame(
			[],
			$missing,
			"A reader is listed here but its body no longer calls Audience::\n" . implode( "\n", $missing )
		);
	}

	/**
	 * The source of one method, found by name regardless of visibility or staticness -
	 * `public function`, `private function`, and `private static function` all appear across
	 * the files this guard covers.
	 *
	 * @param string $relative_file Path under includes/, e.g. "REST/Plots_Controller.php".
	 * @param string $method
	 * @return string|null
	 */
	private function method_body( string $relative_file, string $method ): ?string {
		$source = (string) file_get_contents( BE_PLUGIN_ROOT . '/beyond-elysium/includes/' . $relative_file );

		$pattern = '/\n\t(?:public|private)\s+(?:static\s+)?function\s+' . preg_quote( $method, '/' ) . '\s*\(.*?\n\t\}/s';
		return preg_match( $pattern, $source, $m ) ? $m[0] : null;
	}
}
