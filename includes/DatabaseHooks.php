<?php

namespace MediaWiki\Extension\CollisionManager;

use DatabaseUpdater;
use MediaWiki\Extension\CollisionManager\Maintenance\PopulateCollisionTitleTable;

class DatabaseHooks implements
	\MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook
{
	/**
	 * {@inheritDoc}
	 * @param DatabaseUpdater $updater
	 * @return bool|void
	 */
	public function onLoadExtensionSchemaUpdates($updater)
	{
		/**
		 * Generate Schema SQL:
		 * # php maintenance/generateSchemaSql.php --json ./extensions/CollisionManager/schema --type=all
		 */

		$base = __DIR__ . '/../schema';
		$dbType = $updater->getDB()->getType();

		$updater->addExtensionTable('collision_title', "$base/$dbType/tables-generated.sql");

		$updater->addPostDatabaseUpdateMaintenance(PopulateCollisionTitleTable::class);
	}
}
