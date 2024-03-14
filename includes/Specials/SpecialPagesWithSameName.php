<?php

namespace MediaWiki\Extension\CollisionManager\Specials;

use MediaWiki\MediaWikiServices;
use QueryPage;
use Title;
use Html;

class SpecialPagesWithSameName extends QueryPage
{

	function __construct()
	{
		parent::__construct('PagesWithSameName');
	}

	function isIncludable()
	{
		return true;
	}

	function isExpensive()
	{
		return false;
	}

	function isSyndicated()
	{
		return false;
	}

	function getQueryInfo()
	{
		return [
			'tables' => ['collision_title', 'page'],
			'fields' => [
				'namespace' => 'ct_namespace',
				'title' => 'ct_search',
				'ids' => 'GROUP_CONCAT(page_id SEPARATOR \' \')',
				'value' => 'COUNT(*)',
			],
			'conds' => [
				'ct_rules' => ''
			],
			'options' => [
				'HAVING' => 'COUNT(*) > 1',
				'GROUP BY' => [
					'ct_namespace',
					'ct_search'
				]
			],
			'join_conds' => [
				'page' => [
					'LEFT JOIN',
					['page_id = ct_page']
				]
			]
		];
	}

	/**
	 * @param Title $title
	 * @param ?Title $target
	 */
	function makeLink($title, $target = null)
	{
		$linkRenderer = $this->getLinkRenderer();

		if ($target === null) {
			$subject = $linkRenderer->makeKnownLink($title, null, [], ['redirect' => 'no']);
			$redirectOut = '';
		} else {
			$subject = Html::rawElement('em', [], $linkRenderer->makeKnownLink($title));
			$redirectOut = ' ' . $this->getLanguage()->getArrow() . ' ' . $linkRenderer->makeLink($target);
		}

		$links = [];
		$links[] = $linkRenderer->makeKnownLink(
			$title,
			$this->msg('edit')->escaped(),
			[],
			['action' => 'edit']
		);
		$out = $subject . $this->msg('word-separator')->escaped();

		if ($this->getUser()->isAllowed('delete')) {
			$links[] = $linkRenderer->makeKnownLink(
				$title,
				$this->msg('delete')->escaped(),
				[],
				['action' => 'delete']
			);
		}
		return Html::rawElement(
			'li',
			[],
			$out . $this->msg('parentheses')->rawParams($this->getLanguage()->pipeList($links))->escaped() . $redirectOut
		);
	}

	function formatResult($skin, $result)
	{
		$services = MediaWikiServices::getInstance();
		$redirectLookup = $services->getRedirectLookup();

		$ids = explode(' ', $result->ids);
		$page = '';
		$redi = '';
		foreach ($ids as $id) {
			$title = Title::newFromID($id);
			if (!($title instanceof Title)) {
				continue;
			}
			if ($title->isRedirect()) {
				$target = $redirectLookup->getRedirectTarget($title);
				$redi .= $this->makeLink($title, $target);
			} else {
				$page .= $this->makeLink($title);
			}
		}

		return $this->getLanguage()->specialList(
			count($ids) > 1 ? '<b>' . $result->title . '</b>' : $result->title,
			$this->msg('pageswithsamename-repeat')->numParams($result->value)->escaped()
		) . $this->msg('colon-separator')->escaped() . Html::rawElement('ul', [], $page . $redi);
	}

	protected function getGroupName()
	{
		return 'pagetools';
	}
}
