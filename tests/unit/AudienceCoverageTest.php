<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every player-reachable reader of a plot, plot entry, world object, attachment, or card report must consult
 * `Services\Audience` before returning a row.
 */
class AudienceCoverageTest extends TestCase {

	/**
	 * Every currently-existing player-reachable reader, mapped to the entity type it returns.
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
	 * The source of one method, found by name regardless of visibility or staticness.
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
