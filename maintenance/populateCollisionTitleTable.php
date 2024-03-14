<?php

namespace MediaWiki\Extension\CollisionManager\Maintenance;

use LoggedUpdateMaintenance;
use MediaWiki\Extension\CollisionManager\CollisionRule;
use MediaWiki\Extension\CollisionManager\CollisionStore;
use MediaWiki\MediaWikiServices;
use Title;

$IP = getenv('MW_INSTALL_PATH');
if ($IP === false) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Populate the collision_title table needed for CollisionManager.
 */
class PopulateCollisionTitleTable extends LoggedUpdateMaintenance
{
	public function __construct()
	{
		parent::__construct();
		$this->addDescription('Populate `collision_title` table.');
		$this->addOption('start', 'Start indexing at a specific page_id.', false, true);
		$this->addOption('end', 'End indexing at a specific page_id.', false, true);
		$this->setBatchSize(100);

		$this->requireExtension('CollisionManager');
	}

	/**
	 * @inheritDoc
	 */
	protected function getUpdateKey()
	{
		return __CLASS__;
	}

	/**
	 * @inheritDoc
	 */
	protected function doDBUpdates()
	{
		$db = $this->getDB(DB_PRIMARY);

		$batchSize = $this->getBatchSize();
		$start = $this->getOption('start', 0);
		$end = $this->getOption('end', 0);

		if ($start === 0) {
			$start = 1;
		}
		if ($end === 0) {
			$end = (int) $db->newSelectQueryBuilder()
				->field('MAX(page_id)')
				->table('page')
				->caller(__METHOD__)
				->fetchField();
		}

		$blockStart = $start;
		$blockEnd = $start + $batchSize - 1;

		if ($start >= $end) {
			$this->output("Invalid start and end\n");
			return true;
		}

		$this->output(
			"Starting population of collision_title from $start to $end\n"
		);

		$services = MediaWikiServices::getInstance();
		$lbFactory = $services->getDBLoadBalancerFactory();
		$pageStore = $services->getPageStore();
		/** @var CollisionStore */
		$collisionStore = $services->get('CollisionManager.CollisionStore');

		while ($blockStart < $end) {
			$this->output("...populate page_id from $blockStart to $blockEnd\n");
			$cond = "page_id BETWEEN $blockStart AND $blockEnd AND page_id <= $end";
			$res = $db->newSelectQueryBuilder()
				->select($pageStore->getSelectFields())
				->select([
					'ct_page' => 'page_id',
					'ct_namespace' => 'page_namespace',
					'ct_title' => 'COALESCE(pp_title.pp_value, "")',
					'ct_rules' => 'pp_rules.pp_value',
					'ct_state' => 'pp_state.pp_value',
				])
				->from('page')
				->leftJoin('page_props', 'pp_title', ['pp_title.pp_page = page_id', 'pp_title.pp_propname' => 'collision-title'])
				->leftJoin('page_props', 'pp_rules', ['pp_rules.pp_page = page_id', 'pp_rules.pp_propname' => 'collision-rules'])
				->leftJoin('page_props', 'pp_state', ['pp_state.pp_page = page_id', 'pp_state.pp_propname' => 'collision-state'])
				->conds($cond)
				->orderBy('page_id')
				->caller(__METHOD__)
				->fetchResultSet();
			$batch = [];
			foreach ($res as $row) {
				$title = Title::newFromRow($row);
				$rule = CollisionRule::newFromRow($row);
				$collisionStore->normalizeRule($title, $rule);

				$entry = $rule->toDbArray();
				$batch[] = $entry;
			}
			if (count($batch)) {
				$db->insert('collision_title', $batch, __METHOD__);
			}
			$blockStart += $batchSize;
			$blockEnd += $batchSize;
			$lbFactory->waitForReplication(['ifWritesSince' => 5]);
		}

		$this->output("...collision_title table has been populated.\n");
		return true;
	}
}

$maintClass = PopulateCollisionTitleTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
