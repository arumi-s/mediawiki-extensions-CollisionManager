<?php

namespace MediaWiki\Extension\CollisionManager;

use Wikimedia\Rdbms\ILoadBalancer;
use Config;
use WANObjectCache;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Extension\TextTransform\Normalizers\CachedTextNormalizer;
use MediaWiki\Extension\Disambiguator\Lookup as DisambiguatorLookup;
use Title;
use Database;
use DBConnRef;

class CollisionStore
{
	/** @var int[] */
	public static $disabledNamespaces = [NS_USER, NS_MEDIAWIKI];

	/** @var Config */
	private $config;

	private const KEY = 'ct';

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var WANObjectCache */
	private $cache;

	/** @var RedirectLookup */
	private $redirectLookup;

	/** @var ?CachedTextNormalizer */
	private $textNormalizer;

	/** @var ?DisambiguatorLookup */
	private $disambiguatorLookup;

	/**
	 * @param Config $config
	 * @param ILoadBalancer $loadBalancer
	 * @param WANObjectCache $cache
	 * @param RedirectLookup $redirectLookup
	 * @param ?CachedTextNormalizer $textNormalizer
	 * @param ?DisambiguatorLookup $disambiguatorLookup
	 */
	public function __construct(
		Config $config,
		ILoadBalancer $loadBalancer,
		WANObjectCache $cache,
		RedirectLookup $redirectLookup,
		?CachedTextNormalizer $textNormalizer,
		?DisambiguatorLookup $disambiguatorLookup
	) {
		$this->config = $config;
		$this->loadBalancer = $loadBalancer;
		$this->cache = $cache;
		$this->redirectLookup = $redirectLookup;
		$this->textNormalizer = $textNormalizer;
		$this->disambiguatorLookup = $disambiguatorLookup;
	}

	public function selectFields()
	{
		return [
			'ct_page',
			'ct_namespace',
			'ct_title',
			'ct_rules',
			'ct_state',
		];
	}

	/**
	 * @param Title $title
	 * @param CollisionRule $rule
	 */
	public function set(Title $title, $rule)
	{
		$pageID = $title->getArticleID();
		if ($pageID <= 0) {
			wfDebugLog('collision-manager', "CollisionStore::set() - skipping; not called from a wiki page.\n");
			return;
		}

		$this->store($title, $rule);
	}

	/**
	 * @param Title|null $title
	 * @return CollisionRule|null
	 */
	public function get($title)
	{
		if ($title === null) {
			return null;
		}

		if ($title->inNamespaces(...self::$disabledNamespaces)) {
			return null;
		}

		$id = $title->getArticleID();
		if ($id === 0) {
			return null;
		}

		$key = $this->makeRuleCacheKey($id);
		$method = __METHOD__;

		return $this->cache->getWithSetCallback(
			$key,
			$this->cache::TTL_DAY * 30, // Cache for 30 days
			function ($oldValue, &$ttl, &$setOpts) use ($id, $method) {
				$dbr = $this->loadBalancer->getConnection(DB_REPLICA);
				$setOpts = Database::getCacheSetOptions($dbr);

				$queryBuilder = $dbr->newSelectQueryBuilder();
				$queryBuilder->select($this->selectFields())
					->from('collision_title')
					->where(['ct_page' => $id])
					->limit(1)
					->caller($method);

				$res = $queryBuilder->fetchRow();

				if (!$res) {
					return null;
				}

				return CollisionRule::newFromRow($res);
			}
		);
	}

	/**
	 * @param Title $title
	 */
	public function delete($title)
	{
		$pageID = $title->getArticleID();

		$oldData = $this->get($title);

		$dbw = $this->loadBalancer->getConnection(DB_PRIMARY);
		$dbw->delete('collision_title', ['ct_page' => $pageID]);

		// clear cache based on page id
		$this->clearRuleCache($pageID);

		// clear cache based on old title text (without tail)
		if ($oldData !== null && $oldData->hasHead()) {
			$this->clearMatchCache($oldData->getNamespace(), $oldData->getHead());
		}
	}

	/**
	 * @param string $text
	 * @param string $separator
	 * @return string
	 */
	public function normalize($text, $separator = '')
	{
		if ($this->textNormalizer === null) {
			return preg_replace('/\\s+/', $separator, mb_strtolower($text, 'UTF-8'));
		}

		return $this->textNormalizer->normalize($text, $separator);
	}

	/**
	 * @param Title $title
	 * @param CollisionRule $rule
	 * @return CollisionRule
	 */
	public function normalizeRule(Title $title, $rule)
	{
		$dummyRule = CollisionRule::newFromTitle($title, $rule->getRules(), $rule->getState());
		$rule->setHead($this->normalize($dummyRule->getHead()));
		$rule->setSearch($this->normalize($title->getText(), ' '));
		return $rule;
	}

	/**
	 * @param int $namespace
	 * @param string $head
	 * @param bool $allowRedirect
	 * @return CollisionRule[]
	 */
	public function match($namespace, $head, $allowRedirect = false)
	{
		if (in_array($namespace, self::$disabledNamespaces)) {
			return [];
		}

		$text = $this->normalize($head);

		if ($text === '') {
			return [];
		}

		$key = $this->makeMatchCacheKey($namespace, $text);
		$method = __METHOD__;

		/** @var CollisionRule[] */
		$rules = $this->cache->getWithSetCallback(
			$key,
			$this->cache::TTL_DAY * 30,
			function ($oldValue, &$ttl, &$setOpts) use ($namespace, $text, $method) {
				$dbr = $this->loadBalancer->getConnection(DB_REPLICA);
				$setOpts = Database::getCacheSetOptions($dbr);

				$queryBuilder = $dbr->newSelectQueryBuilder();
				$queryBuilder->select($this->selectFields())
					->select(['page_is_redirect'])
					->from('collision_title')
					->join('page', null, ['page_id = ct_page'])
					->where([
						'ct_namespace' => $namespace,
						'ct_title' => $text,
					])
					->caller($method);

				$res = $queryBuilder->fetchResultSet();

				$rules = [];
				foreach ($res as $row) {
					$rule = CollisionRule::newFromRow($row);
					$rules[$rule->getPage()] = $rule;
				}
				return $rules;
			}
		);

		if (!$allowRedirect) {
			foreach ($rules as $key => $rule) {
				if ($rule->getIsRedirect()) {
					unset($rules[$rule->getPage()]);
				}
			}
		}

		return $rules;
	}

	/**
	 * @param Title $text
	 * @param string[]|string $rawRules
	 * @return string
	 */
	public function find($title, $rawRules = '')
	{
		if ($title->inNamespaces(...self::$disabledNamespaces)) {
			return $title->getPrefixedText();
		}

		$rule = CollisionRule::newFromTitle($title, $rawRules, null, true);

		$matchedRules = $this->match($rule->getNamespace(), $rule->getHead(), true);

		if (empty($matchedRules)) {
			$title = Title::newFromText($rule->getPrefixedHead() . $rule->getExt());
			if ($title !== null && $title->exists()) {
				$rule->setTail($rule->hasRules() ? $rule->getRulesArray()[0] : '');
				return $rule->join();
			} else {
				$rule->setTail('');
				return $rule->join();
			}
		}

		$matchedTitles = [];

		foreach ($matchedRules as $matchedRule) {
			if ($matchedRule->matches($rule)) {
				$title = $matchedRule->getTitle();
				if ($title !== null) {
					$matchedTitles[] = $title->getPrefixedText();
				}
			}
		}

		if (count($matchedTitles) === 1) {
			return $matchedTitles[0];
		}

		$matchedRules[0] = $rule;

		$res = $this->getAvailableTails($matchedRules);

		if (empty($res[0])) {
			return $matchedTitles[0];
		}

		$rule->setTail($res[0]);
		return $rule->join();
	}

	/**
	 * @param int[] $namespaces
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return string[]
	 */
	public function search($namespaces, $search, $limit, $offset)
	{
		return $this->doSearch($namespaces, $search, $limit, $offset);
	}

	/**
	 * @param int[] $namespaces
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return Title[]
	 */
	private function doSearch($namespaces, $search, $limit, $offset = 0)
	{
		if (empty($namespaces)) {
			$namespaces = [NS_MAIN]; // if searching on many always default to main
		}

		$keys = explode(' ', $this->normalize($search, ' '));
		if (count($keys) === 0) {
			return [];
		}

		$likeList = [];
		$limit = $limit + 1;
		$dbr = $this->loadBalancer->getConnection(DB_REPLICA);

		foreach ($keys as $key) {
			$likeList[] = $key;
			$likeList[] = $dbr->anyString();
		}

		/** @var Title[] */
		$results = [];
		$offset = max($offset, 0);
		// if number of results is enough, return it
		if (!$this->internalPrefixSearchCond($dbr, $results, $namespaces, array_merge($keys, [$dbr->anyString()]), $limit + $offset)) {
			if (!$this->internalPrefixSearchCond($dbr, $results, $namespaces, $likeList, $limit + $offset)) {
				// if number of results is not enough, search again with looser conds
				if (!$this->internalPrefixSearchCond($dbr, $results, $namespaces, array_merge([$dbr->anyString(), ' '], $likeList), $limit + $offset)) {
					// if number of results is not enough, search again with even looser conds
					$this->internalPrefixSearchCond($dbr, $results, $namespaces, array_merge([$dbr->anyString()], $likeList), $limit + $offset);
				}
			}
		}

		return $offset > 0 ? array_slice(array_values($results), $offset, $limit - 1) : array_values($results);
	}

	/**
	 * Does various search conds
	 * 
	 * @param DBConnRef &$dbr
	 * @param Title[] &$results
	 * @param int[] $namespaces
	 * @param array $likeList
	 * @param int $limit
	 * @return bool True if the list is full, false is otherwise
	 */
	private function internalPrefixSearchCond(&$dbr, &$results, $namespaces, $likeList, $limit)
	{
		$internalLimit = $limit - count($results);
		if ($internalLimit <= 0) {
			return true;
		}

		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select(['page_namespace', 'page_title'])
			->from('page')
			->join('collision_title', null, ['ct_page = page_id'])
			->where([
				'ct_namespace' => $namespaces,
				'ct_search ' . $dbr->buildLike($likeList),
			])
			->orderBy(['ct_namespace', 'CHAR_LENGTH(ct_title)', 'ct_title'], 'ASC')
			->limit($internalLimit)
			->caller(__METHOD__);

		$res = $queryBuilder->fetchResultSet();

		// Reformat useful data for future printing by JSON engine
		if (!$res->numRows()) {
			return false;
		}

		foreach ($res as $row) {
			$title = Title::makeTitle($row->page_namespace, $row->page_title);
			if ($title === null) {
				continue;
			}

			$target = $this->redirectLookup->getRedirectTarget($title);
			if ($target instanceof Title && $title instanceof Title) {
				$results[$target->getFullText()] = $title;
			} else {
				$results[$title->getFullText()] = $title;
			}
		}
		$res->free();

		return count($results) >= $limit;
	}

	/**
	 * @param Title $title
	 * @return string
	 */
	public function getPageDescription($title)
	{
		$rule = $this->get($title);

		if ($rule === null || !$rule->hasState()) {
			return implode(
				wfMessage('pipe-separator')->text(),
				array_map(
					fn($t) => preg_replace(['/^.+?:/u', '/_/'], ['', ' '], $t),
					array_keys(array_slice($title->getParentCategories(), 0, 5))
				)
			);
		}

		return $rule->getState();
	}

	/**
	 * @param string $rule
	 * @return int
	 */
	public function getRulePriority($rule): int
	{
		if ($rule === '') {
			return -1;
		}

		$rulePriorities = $this->config->get('CollisionManagerRulePriorities');

		if (isset($rulePriorities[$rule])) {
			return $rulePriorities[$rule];
		}

		foreach ($rulePriorities as $key => $value) {
			if (strpos($key, '/', 0) === 0 && preg_match($key, $rule)) {
				return $value;
			}
		}

		return $rulePriorities[''] ?? 99999;
	}

	/**
	 * @param Title $title
	 * @return bool
	 */
	public function isValidTarget($title): bool
	{
		return $title !== null && !$title->isRedirect() && ($this->disambiguatorLookup === null || !$this->disambiguatorLookup->isDisambiguationPage($title));
	}

	/**
	 * @param int $id
	 * @return string
	 */
	private function makeRuleCacheKey($id): string
	{
		return $this->cache->makeKey(self::KEY, 'id', $id);
	}

	/**
	 * @param int $namespace
	 * @param string $text
	 * @return string
	 */
	private function makeMatchCacheKey($namespace, $text): string
	{
		return $this->cache->makeKey(self::KEY, 'rules', (string) $namespace, md5($text));
	}

	/**
	 * @param int $id
	 * @return bool
	 */
	private function clearRuleCache($id): bool
	{
		return $this->cache->delete($this->makeRuleCacheKey($id));
	}

	/**
	 * @param int $namespace
	 * @param string $text
	 * @return bool
	 */
	private function clearMatchCache($namespace, $text): bool
	{
		return $this->cache->delete($this->makeMatchCacheKey($namespace, $text));
	}

	/**
	 * @param Title $title
	 * @param CollisionRule $rule
	 */
	private function store($title, $rule)
	{
		$id = $title->getArticleID();

		$oldRule = $this->get($title);

		$row = $rule->toDbArray();
		$row['ct_page'] = $id;

		$dbw = $this->loadBalancer->getConnection(DB_PRIMARY);
		$dbw->replace(
			'collision_title',
			'ct_page',
			[$row],
			__METHOD__
		);

		// clear cache based on page id
		$this->clearRuleCache($id);

		// clear cache based on old head
		if ($oldRule !== null && $oldRule->hasHead()) {
			$this->clearMatchCache($rule->getNamespace(), $oldRule->getHead());
		}

		// clear cache based on current head
		if ($rule->hasHead()) {
			$this->clearMatchCache($rule->getNamespace(), $rule->getHead());
		}
	}

	/**
	 * @param CollisionRule[] $matchedRules
	 * @return string[]
	 */
	public function getAvailableTails($matchedRules = [])
	{
		if (empty($matchedRules)) {
			return [];
		}

		$tails = [];
		$layers = [];
		foreach ($matchedRules as $id => $matchedRule) {
			$tails[$id] = '';
			foreach ($matchedRule->getRulesArray() as $layerIndex => $rule) {
				if (!isset($layers[$layerIndex])) {
					$layers[$layerIndex] = [];
				}
				$layers[$layerIndex][$id] = $rule;
			}
		}

		foreach ($layers as $layerIndex => $layer) {
			$ids = $this->getDuplicate($tails, $layer);
			if (empty($ids)) {
				break;
			}

			foreach ($ids as $k) {
				if (isset($layer[$k])) {
					$tails[$k] = $layer[$k];
				}
			}
		}

		return $tails;
	}

	/**
	 * @param string[] $array
	 * @param string[] $layer
	 * @return string[]
	 */
	private function getDuplicate($array, $layer)
	{
		$result = [];
		$count = array_count_values($array);
		$ranker = new Ranker();

		foreach ($array as $k => $v) {
			if ($count[$v] > 1) {
				if (!isset($layer[$k])) {
					$ranker->min($k, -1);
				} else {
					$result[$k] = $k;
					$ranker->min($k, $this->getRulePriority($layer[$k]));
				}
			}
		}

		if (!$ranker->equal && $ranker->key !== null && $ranker->value !== -1) {
			unset($result[$ranker->key]);
		}

		return array_keys($result);
	}
}
