<?php
/**
 * Fields that are real Beyond Elysium storage with no qkdata.gvd key of their own, each naming the Grapevine class
 * and property it comes from.
 *
 * @see BeyondElysium\Services\Field_Registry
 */

defined( 'ABSPATH' ) || exit;

return [
	// ItemClass.GetValue (Code/ItemClass.cls) reads qkTempers as a real item property; qkdata.gvd has no row for it.
	[ 'key' => 'tempers', 'title' => 'Tempers', 'type' => 'list', 'inventories' => [ 'item' ] ],
];
