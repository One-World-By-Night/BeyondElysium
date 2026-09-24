<?php

namespace BeyondElysium\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Runs Beyond Elysium maintenance from a shell.
 *
 * Only here to give WP-CLI a parent for the commands beneath it (`wp be cutover ...`): a command
 * registered under a name WP-CLI has no parent for is deferred and never appears.
 */
class Be_Command {
}
