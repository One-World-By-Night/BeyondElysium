<?php
/**
 * The few parts of WP-CLI that beyond-elysium/includes/CLI calls, declared so static analysis can read
 * them - WP-CLI is not a dependency of the plugin and no stubs package for it is installed. Read by
 * PHPStan only (`scanFiles` in phpstan.neon), never loaded at runtime.
 */

namespace {
	class WP_CLI {
		/**
		 * @param string                    $name
		 * @param callable|object|string    $callable
		 * @param array<string,mixed>       $args
		 */
		public static function add_command( $name, $callable, $args = [] ) {}

		/**
		 * @param string $message
		 * @param bool   $exit
		 * @return never
		 */
		public static function error( $message, $exit = true ) {}

		/** @param string $message */
		public static function log( $message ) {}

		/** @param string $message */
		public static function success( $message ) {}

		/** @param string $message */
		public static function warning( $message ) {}

		/**
		 * @param string               $question
		 * @param array<string,mixed>  $assoc_args
		 */
		public static function confirm( $question, $assoc_args = [] ) {}

		/**
		 * @param mixed                $value
		 * @param array<string,mixed>  $assoc_args
		 */
		public static function print_value( $value, $assoc_args = [] ) {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                          $format
	 * @param array<int,array<string,mixed>>  $items
	 * @param string[]                        $fields
	 */
	function format_items( $format, $items, $fields ) {}
}
