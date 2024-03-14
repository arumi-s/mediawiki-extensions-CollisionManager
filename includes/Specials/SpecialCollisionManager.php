<?php

namespace MediaWiki\Extension\CollisionManager\Specials;

use SpecialPage;
use QueryPage;
use MediaWiki\Extension\CollisionManager\CollisionRule;
use MediaWiki\Extension\CollisionManager\CollisionStore;
use MediaWiki\Page\RedirectLookup;
use Title;
use Html;
use Skin;
use stdClass;

class SpecialCollisionManager extends QueryPage
{
	/** @var CollisionStore */
	private $collisionStore;

	/** @var RedirectLookup */
	private $redirectLookup;

	/**
	 * @param CollisionStore $collisionStore
	 * @param RedirectLookup $redirectLookup
	 */
	function __construct(
		CollisionStore $collisionStore,
		RedirectLookup $redirectLookup
	) {
		parent::__construct('CollisionManager', 'move');
		$this->collisionStore = $collisionStore;
		$this->redirectLookup = $redirectLookup;
	}

	function isIncludable()
	{
		return false;
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
			'tables' => ['collision_title', 'page_props'],
			'fields' => $this->collisionStore->selectFields() + ['value' => 'ct_title'],
			'conds' => ['pp_value <> ""'],
			'options' => [
				'HAVING' => 'COUNT(*) > 1',
				'GROUP BY' => ['ct_namespace', 'ct_title'],
			],
			'join_conds' => [
				'page_props' => [
					'LEFT JOIN',
					['pp_page = ct_page', 'pp_propname' => 'collision-rules']
				]
			]
		];
	}

	/**
	 * @param CollisionRule $rule
	 * @param string $newTail
	 * @param bool $unsolved
	 */
	function makeListItem($rule, $newTail, $unsolved)
	{
		$title = $rule->getTitle();
		if ($title === null || !$title->exists()) {
			return '';
		}

		$linkRenderer = $this->getLinkRenderer();
		$language = $this->getLanguage();

		$rule->split($title->getText());
		$oldText = $rule->join();
		$oldTail = $rule->getTail();
		if (!$unsolved) {
			$rule->setTail($newTail);
		}
		$newText = $rule->join();
		$instruction = '';
		$redirectOut = '';

		if ($title->isRedirect()) {
			$redirectTarget = Title::castFromPageIdentity($this->redirectLookup->getRedirectTarget($title));
			if ($redirectTarget !== null) {
				$from = Html::rawElement(
					'em',
					[],
					$linkRenderer->makeKnownLink($title, null)
				);
				$redirectOut = ' ' . $this->getLanguage()->getArrow() . ' ' . $linkRenderer->makeLink($redirectTarget);
			}
		} else {
			$from = $linkRenderer->makeKnownLink($title, null);
		}

		$links = [];
		$links[] = $linkRenderer->makeKnownLink(
			$title,
			$this->msg('edit')->escaped(),
			[],
			['action' => 'edit']
		);

		if ($this->getUser()->isAllowed('delete')) {
			$links[] = $linkRenderer->makeKnownLink(
				$title,
				$this->msg('delete')->escaped(),
				[],
				['action' => 'delete']
			);
		}

		if ($this->getUser()->isAllowed('move')) {
			$links[] = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor('Movepage', $title->getPrefixedDBkey()),
				$this->msg('move')->escaped(),
				[],
				['wpNewTitleMain' => $oldText !== $newText ? $newText : null, 'wpLeaveRedirect' => '', 'wpMovesubpages' => '1']
			);
		}

		if ($unsolved) {
			$instruction .= $this->msg('collision-manager-unsolved')->escaped();
		} else if ($oldText !== $newText && $this->collisionStore->getRulePriority($oldTail) !== $this->collisionStore->getRulePriority($newTail)) {
			$instruction .= $this->msg('collision-manager-move')->rawParams($this->msg('quotation-marks')->rawParams($newText))->escaped();
		}

		return Html::rawElement(
			'li',
			[],
			$from .
			(
				$rule->hasRules() ?
				' - ' . $this->msg('parentheses')->rawParams($language->pipeList($rule->getRulesArray()))->escaped() :
				''
			) .
			(
				$instruction !== '' ?
				' ' . $language->getArrow() . ' ' . Html::rawElement('span', ['class' => 'error'], $instruction) :
				''
			) .
			$this->msg('parentheses')->rawParams($language->pipeList($links))->escaped() .
			$redirectOut
		);
	}

	/**
	 * {@inheritDoc}
	 * @param Skin $skin
	 * @param stdClass $result
	 */
	function formatResult($skin, $result)
	{
		$rule = CollisionRule::newFromRow($result);
		if (!$rule->hasHead()) {
			return '';
		}

		$matchedRules = $this->collisionStore->match($rule->getNamespace(), $rule->getHead(), true);
		if (count($matchedRules) < 2) {
			return '';
		}

		$ids = array_map(
			fn(CollisionRule $matchedRule): int => $matchedRule->getPage(),
			$matchedRules
		);
		$matchedRules = array_filter($matchedRules, function (CollisionRule $matchedRule) use ($ids): bool {
			$title = $matchedRule->getTitle();
			if ($title->isRedirect()) {
				$redirectTarget = Title::castFromPageIdentity($this->redirectLookup->getRedirectTarget($title));
				if ($redirectTarget === null || in_array($redirectTarget->getArticleID(), $ids)) {
					return false;
				}
			}
			return true;
		});

		$tails = $this->collisionStore->getAvailableTails($matchedRules);
		$duplicatedTails = array_keys(array_filter(array_count_values($tails), fn(int $count) => $count > 1));
		$listItems = '';
		foreach ($tails as $id => $tail) {
			$unsolved = in_array($tail, $duplicatedTails);
			$listItems .= $this->makeListItem(
				$matchedRules[$id],
				$tail,
				$unsolved
			);
		}

		return $this->getLanguage()->specialList(
			$rule->getHead(),
			$this->msg('pageswithsamename-repeat')->numParams(count($tails))->escaped()
		) .
			$this->msg('colon-separator')->escaped() .
			Html::rawElement('ul', [], $listItems);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function getGroupName()
	{
		return 'pagetools';
	}
}
