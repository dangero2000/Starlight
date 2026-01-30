<?php
/**
 * Schema hooks for the Starlight extension.
 *
 * @file
 * @ingroup Extensions
 * @license AGPL-3.0-or-later
 */

namespace MediaWiki\Extension\Starlight\Hooks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\Installer\DatabaseUpdater;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$base = dirname( __DIR__, 2 );
		$dbType = $updater->getDB()->getType();

		$updater->addExtensionTable(
			'starlight_review',
			"$base/schema/$dbType/tables-generated.sql"
		);
	}
}
