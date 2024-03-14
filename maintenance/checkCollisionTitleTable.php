<?php

namespace MediaWiki\Extension\CollisionManager\Maintenance;

use Maintenance;
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
 * Check the collision_title table needed for CollisionManager.
 */
class CheckCollisionTitleTable extends Maintenance
{
	public function __construct()
	{
		parent::__construct();
		$this->addDescription('Check `collision_title` table against `page_props`.');
		$this->addOption('start', 'Start indexing at a specific page_id.', false, true);
		$this->addOption('end', 'End indexing at a specific page_id.', false, true);
		$this->setBatchSize(100);

		$this->requireExtension('CollisionManager');
	}

	/**
	 * @inheritDoc
	 */
	public function execute()
	{
		if (!$this->doDBUpdates()) {
			return false;
		}

		return true;
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
				->field('MAX(ct_page)')
				->table('collision_title')
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
			"Starting inspection of collision_title from $start to $end\n"
		);

		$services = MediaWikiServices::getInstance();
		$lbFactory = $services->getDBLoadBalancerFactory();
		$pageStore = $services->getPageStore();
		/** @var CollisionStore */
		$collisionStore = $services->get('CollisionManager.CollisionStore');

		while ($blockStart < $end) {
			$this->output("...inspect ct_page from $blockStart to $blockEnd\n");
			$ctCond = "ct_page BETWEEN $blockStart AND $blockEnd AND ct_page <= $end";
			$cond = "page_id BETWEEN $blockStart AND $blockEnd AND page_id <= $end";
			$res = $db->newSelectQueryBuilder()
				->select($collisionStore->selectFields())
				->from('collision_title')
				->conds($ctCond)
				->caller(__METHOD__)
				->fetchResultSet();
			$ppRes = $db->newSelectQueryBuilder()
				->select($pageStore->getSelectFields())
				->select([
					'ct_rules' => 'pp_rules.pp_value',
					'ct_state' => 'pp_state.pp_value',
				])
				->from('page')
				->leftJoin('page_props', 'pp_rules', ['pp_rules.pp_page = page_id', 'pp_rules.pp_propname' => 'collision-rules'])
				->leftJoin('page_props', 'pp_state', ['pp_state.pp_page = page_id', 'pp_state.pp_propname' => 'collision-state'])
				->conds($cond)
				->orderBy('page_id')
				->caller(__METHOD__)
				->fetchResultSet();
			$deleteBatch = [];
			$updateBatch = [];
			$propRules = [];
			foreach ($ppRes as $row) {
				$title = Title::newFromRow($row);
				$rule = CollisionRule::newFromTitle($title, $row->ct_rules ?? '', $row->ct_state ?? '', true);
				$collisionStore->normalizeRule($title, $rule);
				$propRules[$rule->getPage()] = $rule;
			}
			foreach ($res as $row) {
				$rule = CollisionRule::newFromRow($row);
				$propRule = $propRules[$rule->getPage()] ?? null;
				if ($propRule === null) {
					if ($rule->getTitle() !== null && $rule->getTitle()->exists()) {
						$this->output('missing `page_props` at ' . $rule->getPage() . "\n");
					} else {
						$this->output('unknown page at ' . $rule->getPage() . "\n");
						$deleteBatch[] = $rule->getPage();
					}
				} else {
					if (!$rule->equals($propRule)) {
						$this->output('mismatch at ' . $rule->getPage() . ': ' . implode("\t", [$rule->getPrefixedHead(), $propRule->getPrefixedHead()]) . "\n");
						$updateBatch[] = $propRule->toDbArray();
					}
				}
			}
			if (count($updateBatch)) {
				$db->replace('collision_title', 'ct_page', $updateBatch, __METHOD__);
			}
			if (count($deleteBatch)) {
				$db->delete('collision_title', ['ct_page' => $deleteBatch], __METHOD__);
			}
			$blockStart += $batchSize;
			$blockEnd += $batchSize;
			$lbFactory->waitForReplication(['ifWritesSince' => 5]);
		}

		$this->output("...collision_title table has been checked.\n");
		return true;
	}
}

$maintClass = CheckCollisionTitleTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
