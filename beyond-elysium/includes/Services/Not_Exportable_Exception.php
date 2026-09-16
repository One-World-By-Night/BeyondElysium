<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * A character whose creature type has no Grapevine equivalent, so no exchange document can hold
 * it - a stack an administrator added. Its message is written for the person who asked to export
 * or transfer the character (1.0.0-review F-048).
 */
class Not_Exportable_Exception extends \RuntimeException {
}
