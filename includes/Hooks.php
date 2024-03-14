<?php

namespace MediaWiki\Extension\CollisionManager;

use Config;
use MediaWiki\Extension\Disambiguator\Lookup as DisambiguatorLookup;
use ExtensionRegistry;
use Title;
use ImagePage;
use OutputPage;
use ParserOutput;
use IContextSource;
use LinksUpdate;
use ManualLogEntry;
use SpecialPage;
use Html;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;

class Hooks implements
	\MediaWiki\Hook\LinksUpdateHook,
	\MediaWiki\Page\Hook\PageDeleteCompleteHook,
	\MediaWiki\Api\Hook\ApiOpenSearchSuggestHook,
	\MediaWiki\Hook\OutputPageParserOutputHook,
	\MediaWiki\Page\Hook\ImageOpenShowImageInlineBeforeHook,
	\MediaWiki\Hook\InfoActionHook,
	\MediaWiki\Search\Hook\PrefixSearchBackendHook,
	\MediaWiki\Search\Hook\SearchGetNearMatchHook,
	\MediaWiki\Extension\CustomRedirect\Hooks\GetCustomRedirectHook
{
	/** @var Config */
	private $config;

	/** @var CollisionStore */
	private $collisionStore;

	/** @var DisambiguatorLookup */
	private $disambiguatorLookup;

	/** @var DisambigBuilder */
	private $disambigBuilder;

	/** @var ExtensionRegistry */
	private $extensionRegistry;

	public function __construct(
		Config $config,
		CollisionStore $collisionStore,
		DisambiguatorLookup $disambiguatorLookup,
		DisambigBuilder $disambigBuilder
	) {
		$this->config = $config;
		$this->collisionStore = $collisionStore;
		$this->disambiguatorLookup = $disambiguatorLookup;
		$this->disambigBuilder = $disambigBuilder;
		$this->extensionRegistry = ExtensionRegistry::getInstance();
	}

	/**
	 * {@inheritDoc}
	 * @param LinksUpdate $linksUpdate
	 * @return bool|void
	 */
	public function onLinksUpdate($linksUpdate)
	{
		$title = $linksUpdate->getTitle();

		$output = $linksUpdate->getParserOutput();

		$rules = $output->getPageProperty('collision-rules');
		$state = $output->getPageProperty('collision-state');

		$rule = CollisionRule::newFromTitle($title, $rules, $state, true);
		$this->collisionStore->normalizeRule($title, $rule);
		$this->collisionStore->set($title, $rule);
	}

	/**
	 * {@inheritDoc}
	 * @param ProperPageIdentity $page
	 * @param Authority $deleter
	 * @param string $reason
	 * @param int $pageID
	 * @param RevisionRecord $deletedRev
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 * @return true|void
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		$this->collisionStore->delete(Title::castFromPageIdentity($page));
	}

	/**
	 * {@inheritDoc}
	 * @param array[] &$results
	 * @return bool|void
	 */
	public function onApiOpenSearchSuggest(&$results)
	{
		foreach ($results as &$row) {
			$row['extract'] = $this->collisionStore->getPageDescription($row['title']);
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 * @param OutputPage $outputPage
	 * @param ParserOutput $parserOutput
	 * @return void
	 */
	public function onOutputPageParserOutput($outputPage, $parserOutput): void
	{
		$title = $outputPage->getTitle();

		if (
			$title->inNamespaces(NS_FILE, ...CollisionStore::$disabledNamespaces) ||
			($outputPage->getRevisionId() === null && !$outputPage->isArticle())
		) {
			return;
		}

		$this->disambigBuilder->addDisambig($outputPage, $parserOutput, $title);
	}

	/**
	 * {@inheritDoc}
	 * @param ImagePage $imagePage
	 * @param OutputPage $output
	 * @return bool|void
	 */
	public function onImageOpenShowImageInlineBefore($imagePage, $output)
	{
		$title = $output->getTitle();

		if (!$title->inNamespace(NS_FILE) || $output->getContext()->getActionName() === 'edit') {
			return true;
		}

		$parserOutput = $imagePage->getParserOutput();

		if ($parserOutput === false) {
			return true;
		}

		$this->disambigBuilder->addDisambig($output, $parserOutput, $title);
	}

	/**
	 * {@inheritDoc}
	 * @param IContextSource $context
	 * @param array &$pageInfo
	 * @return bool|void
	 */
	public function onInfoAction($context, &$pageInfo)
	{
		$title = $context->getTitle();

		$rule = $this->collisionStore->get($title);

		if ($rule === null) {
			return;
		}

		$pageInfo['header-basic'][] = [
			$context->msg('collision-manager-info-title-label'),
			Html::element('code', [], $rule->getHead())
		];

		if ($rule->hasRules()) {
			$list = Html::openElement('ol');
			foreach ($rule->getRulesArray() as $r) {
				$list .= Html::element('li', [], $r);
			}
			$list .= Html::closeElement('ol');

			$pageInfo['header-basic'][] = [
				$context->msg('collision-manager-info-rules-label'),
				$list
			];
		}

		if ($rule->hasState()) {
			$pageInfo['header-basic'][] = [
				$context->msg('collision-manager-info-state-label'),
				$rule->getState()
			];
		}
	}

	/**
	 * {@inheritDoc}
	 * @param int[] $ns
	 * @param string $search
	 * @param int $limit
	 * @param string[] &$results
	 * @param int $offset
	 * @return bool|void
	 */
	public function onPrefixSearchBackend($ns, $search, $limit, &$results, $offset)
	{
		$results = $this->collisionStore->search($ns, $search, $limit, $offset);

		return false;
	}

	/**
	 * {@inheritDoc}
	 * @param string $term 
	 * @param Title &$title
	 * @return bool|void
	 */
	public function onSearchGetNearMatch($term, &$title)
	{
		list($term) = CollisionRule::sepTail($term);
		$rules = $this->collisionStore->match(0, $term, true);

		if (count($rules) > 1) {
			$title = SpecialPage::getTitleFor('Disambig', $term);
			return false;
		}

		if (count($rules) === 1) {
			$title = current($rules)->getTitle();
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 * @param Title &$title
	 * @param bool &$changed
	 * @return bool|void
	 */
	public function onGetCustomRedirect(Title &$title, bool &$changed)
	{
		$text = $this->collisionStore->find($title);

		if ($text === $title->getPrefixedText()) {
			return;
		}

		$target = Title::newFromText($text);
		if ($target === null) {
			return;
		}

		$title = $target;
		$changed = true;
		return false;
	}
}
