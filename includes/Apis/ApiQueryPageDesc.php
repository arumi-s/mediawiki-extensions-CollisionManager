<?php

namespace MediaWiki\Extension\CollisionManager\Apis;

use ApiQuery;
use ApiQueryBase;
use MediaWiki\Extension\CollisionManager\CollisionStore;
use MediaWiki\Page\PageIdentity;

class ApiQueryPageDesc extends ApiQueryBase
{
	/** @var CollisionStore */
	private $collisionStore;

	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 * @param CollisionStore $collisionStore
	 */
	public function __construct(
		ApiQuery $query,
		$moduleName,
		CollisionStore $collisionStore
	) {
		parent::__construct($query, $moduleName, 'pi');
		$this->collisionStore = $collisionStore;
	}

	/**
	 * @return PageIdentity[]
	 */
	protected function getTitles()
	{
		$pageSet = $this->getPageSet();
		$titles = $pageSet->getGoodPages();

		return $titles;
	}

	public function execute()
	{
		$titles = $this->getTitles();

		if (count($titles) === 0) {
			return;
		}

		$result = $this->getResult();

		foreach ($titles as $title) {
			$result->addValue(
				[
					'query',
					'pages'
				],
				$title->getArticleID(),
				[
					'pagedesc' => $this->collisionStore->getPageDescription($title)
				]
			);
		}
	}

	public function getCacheMode($params)
	{
		return 'public';
	}
}
