<?php

namespace MediaWiki\Extension\CollisionManager;

use MediaWiki\MediaWikiServices;
use stdClass;
use Title;
use TitleFormatter;
use Exception;

class CollisionRule
{
	public const RULE_SEPARATOR = '|';

	public const TAIL_SEPARATOR = '，';

	public const TAIL_PREFIX = '（';

	public const TAIL_SUFFIX = '）';

	/** @var int */
	private $mNamespace;

	/** @var string */
	private $mHead;

	/** @var string */
	private $mTail;

	/** @var string */
	private $mExt;

	/** @var string */
	private $mRules;

	/** @var string */
	private $mState;

	/** @var string */
	private $mSearch;

	/** @var int */
	private $mPage;

	/** @var bool */
	private $mIsRedirect;

	/** @var Title */
	private $mTitle;

	/** @var Title */
	private $mPageTitle;

	/** @var string */
	private $mPrefix;

	/** @var string[] */
	private $mRulesArray;

	/**
	 * Make a CollisionRule object from a DB row
	 *
	 * @param stdClass $row Object database row (needs at least ct_namespace and ct_title)
	 * @return CollisionRule
	 */
	public static function newFromRow($row)
	{
		$rule = self::makeRule($row->ct_namespace, $row->ct_title);
		$rule->loadFromRow($row);
		return $rule;
	}

	/**
	 * @param Title $title
	 * @param string[]|string $rules
	 * @param string $state
	 * @param bool $inferRules
	 * @return CollisionRule
	 */
	public static function newFromTitle(Title $title, $rules = null, $state = null, $inferRules = false)
	{
		$rule = self::makeRule($title->getNamespace(), '', $rules, $state, $title->getArticleID());
		$rule->split($title->getText());

		if ($inferRules && !$rule->hasRules() && $rule->hasTail()) {
			$rule->setRules($rule->getTail());
		}

		$rule->setIsRedirect($title->isRedirect());

		return $rule;
	}

	/**
	 * Load CollisionRule object fields from a DB row.
	 * If false is given, the rule will be treated as non-existing.
	 *
	 * @param stdClass|bool $row Database row
	 */
	public function loadFromRow($row)
	{
		if ($row) { // rule found
			if (isset($row->ct_rules)) {
				$this->setRules((string) $row->ct_rules);
			}
			if (isset($row->ct_state)) {
				$this->setState((string) $row->ct_state);
			}
			if (isset($row->ct_page)) {
				$this->setPage((int) $row->ct_page);
			}
			if (isset($row->page_is_redirect)) {
				$this->setIsRedirect((bool) $row->page_is_redirect);
			}
		} else { // rule not found
			$this->setRules('');
			$this->setState('');
			$this->setPage(0);
			$this->setIsRedirect(false);
		}
	}

	/**
	 * @param int $namespace
	 * @param string $head
	 * @param string[]|string $rules
	 * @param string $state
	 * @param int $page
	 * @param string $tail
	 * @param string $ext
	 * @return CollisionRule The new object
	 */
	public static function makeRule($namespace, string $head, $rules = '', $state = '', $page = 0, $tail = '', $ext = '')
	{
		$rule = new CollisionRule();
		$rule->setNamespace($namespace);
		$rule->setHead($head);
		$rule->setRules($rules);
		$rule->setState($state);
		$rule->setSearch('');
		$rule->setPage($page);
		$rule->setIsRedirect(false);
		$rule->setTail($tail);
		$rule->setExt($ext);
		return $rule;
	}

	/**
	 * @param string[]|string $rules
	 * @return string[]
	 */
	public static function trimRulesArray($rules = [])
	{
		if (is_array($rules)) {
			$rules = implode(self::RULE_SEPARATOR, $rules);
		}
		$rules = explode(self::RULE_SEPARATOR, $rules);

		$output = [];
		foreach ($rules as $rule) {
			$rule = trim($rule);
			if ($rule !== '') {
				$output[] = $rule;
			}
		}
		return $output;
	}

	/**
	 * @param string|string[] $tail
	 * @return string
	 */
	public static function wrapTail($tail = '')
	{
		if (is_array($tail)) {
			$tail = implode(self::TAIL_SEPARATOR, $tail);
		}

		$tail = trim($tail);
		return $tail === '' ? '' : self::TAIL_PREFIX . $tail . self::TAIL_SUFFIX;
	}

	/**
	 * @param string $title
	 * @param string|string[] $tail
	 * @return string
	 */
	public static function joinTail($title = '', $tail = '')
	{
		return $title . self::wrapTail($tail);
	}

	/**
	 * @param string $text
	 * @param ?string[] $rules
	 * @return array{string,string}
	 */
	public static function sepTail($text, $rules = null)
	{
		$rules = empty($rules) ? '(.*)' : '(' . implode('|', self::preg_quote_array(self::trimRulesArray($rules), '#')) . ')';
		if (preg_match('#^(.*)' . self::wrapTail($rules) . '$#u', $text, $matches)) {
			return [trim($matches[1]), $matches[2]];
		}
		return [$text, ''];
	}

	/**
	 * @return int
	 */
	public function getNamespace(): int
	{
		return $this->mNamespace;
	}

	/**
	 * @return string
	 */
	public function getHead(): string
	{
		return $this->mHead;
	}

	/**
	 * @return string
	 */
	public function getPrefix(): string
	{
		if ($this->mPrefix === null) {
			if ($this->mNamespace == 0) {
				$this->mPrefix = '';
			} else {
				try {
					$formatter = self::getTitleFormatter();
					$nsText = $formatter->getNamespaceName($this->mNamespace, $this->mHead);
					$this->mPrefix = strtr($nsText, '_', '') . ':';
				} catch (Exception $ex) {
					$this->mPrefix = '';
				}
			}
		}
		return $this->mPrefix;
	}

	/**
	 * @return string
	 */
	public function getPrefixedHead(): string
	{
		return $this->getPrefix() . $this->getHead();
	}

	/**
	 * @return string
	 */
	public function getTail(): string
	{
		return $this->mTail;
	}

	/**
	 * @return string
	 */
	public function getExt(): string
	{
		return $this->mExt;
	}

	/**
	 * @return string
	 */
	public function getRules(): string
	{
		return $this->mRules;
	}

	/**
	 * @return string[]
	 */
	public function getRulesArray()
	{
		if ($this->mRulesArray === null) {
			$this->mRulesArray = self::trimRulesArray($this->mRules);
		}

		return $this->mRulesArray;
	}

	/**
	 * @return string
	 */
	public function getState(): string
	{
		return $this->mState;
	}

	/**
	 * @return string
	 */
	public function getSearch(): string
	{
		return $this->mSearch;
	}

	/**
	 * @return int
	 */
	public function getPage(): int
	{
		return $this->mPage;
	}

	/**
	 * @return bool
	 */
	public function getIsRedirect(): bool
	{
		return $this->mIsRedirect;
	}

	/**
	 * @return Title
	 */
	public function getTitle()
	{
		if ($this->mTitle === null) {
			$this->mTitle = Title::newFromID($this->getPage());
		}
		return $this->mTitle;
	}

	/**
	 * @param int $namespace
	 */
	public function setNamespace($namespace)
	{
		$this->mNamespace = (int) $namespace;
	}

	/**
	 * @param string $head
	 */
	public function setHead($head)
	{
		$this->mHead = trim($head);
	}

	/**
	 * @param string $tail
	 */
	public function setTail($tail)
	{
		$this->mTail = trim($tail);
	}

	/**
	 * @param string $ext
	 */
	public function setExt($ext)
	{
		$this->mExt = $ext;
	}

	/**
	 * @param string[]|string $rules
	 */
	public function setRules($rules)
	{
		$rules = self::trimRulesArray($rules);
		$this->mRules = implode(self::RULE_SEPARATOR, $rules);
		$this->mRulesArray = $rules;
	}

	/**
	 * @param string $state
	 */
	public function setState($state)
	{
		$this->mState = trim($state);
	}

	/**
	 * @param string $search
	 */
	public function setSearch($search)
	{
		$this->mSearch = trim($search);
	}

	/**
	 * @param int $page
	 */
	public function setPage($page)
	{
		if ($this->mPage !== $page) {
			$this->mPage = $page;
			$this->mTitle = null;
		}
	}

	/**
	 * @param bool $isRedirect
	 */
	public function setIsRedirect($isRedirect)
	{
		$this->mIsRedirect = $isRedirect;
	}

	/**
	 * @return bool
	 */
	public function hasHead(): bool
	{
		return $this->mHead !== '';
	}

	/**
	 * @return bool
	 */
	public function hasTail(): bool
	{
		return $this->mTail !== '';
	}

	/**
	 * @return bool
	 */
	public function hasRules(): bool
	{
		return !empty($this->getRulesArray());
	}

	/**
	 * @return bool
	 */
	public function hasState(): bool
	{
		return $this->mState !== '';
	}

	/**
	 * @return bool
	 */
	public function hasSearch(): bool
	{
		return $this->mSearch !== null && $this->mSearch !== '';
	}

	/**
	 * @param CollisionRule $rule
	 * @return bool
	 */
	public function isSameHeadAs($rule): bool
	{
		return $this->getNamespace() === $rule->getNamespace() && $this->getHead() === $rule->getHead();
	}

	/**
	 * @param CollisionRule $rule
	 * @return bool
	 */
	public function isSamePageAs($rule): bool
	{
		return $this->getPage() > 0 && $this->getPage() === $rule->getPage();
	}

	/**
	 * @param CollisionRule $requestedRule
	 * @return bool
	 */
	public function matches($requestedRule): bool
	{
		// auto success if to rules are reqested
		if (!$requestedRule->hasRules()) {
			return true;
		}

		// fail if some rules are reqested but no rules are present
		if (!$this->hasRules()) {
			return false;
		}

		$thisRules = $this->getRulesArray();
		foreach ($requestedRule->getRulesArray() as $rule) {
			// fail on the first mismatch 
			if (!in_array($rule, $thisRules)) {
				return false;
			}
		}

		// success if everything matches
		return true;
	}

	/**
	 * @param CollisionRule $rule
	 * @return bool
	 */
	public function equals($rule)
	{
		return $this->getNamespace() === $rule->getNamespace() &&
			$this->getHead() === $rule->getHead() &&
			$this->getRules() === $rule->getRules() &&
			$this->getState() === $rule->getState();
	}

	/**
	 * @return bool
	 */
	public function exists(): bool
	{
		return $this->getTitle() !== null && $this->getTitle()->exists();
	}

	/**
	 * @param string $text
	 */
	public function split($text)
	{
		if ($this->getNamespace() === NS_FILE) {
			$matched = preg_split('/\\.(?=[a-zA-Z]{1,5}$)/', $text, 2);

			if (count($matched) > 1) {
				$text = $matched[0];
				$this->setExt('.' . $matched[1]);
			}
		}

		list($head, $tail) = self::sepTail($text, $this->getRulesArray());
		$this->setHead($head);
		$this->setTail($tail);
	}

	/**
	 * @return string
	 */
	public function join()
	{
		return self::joinTail($this->getPrefixedHead(), $this->getTail()) . $this->getExt();
	}

	/**
	 * @return string
	 */
	public function joinRules()
	{
		return self::joinTail($this->getPrefixedHead(), $this->getRulesArray()) . $this->getExt();
	}

	/**
	 * @return string
	 */
	public function print()
	{
		return $this->joinRules() . ' - ' . $this->mState;
	}

	/**
	 * @return array
	 */
	public function toDbArray()
	{
		return [
			'ct_namespace' => $this->getNamespace(),
			'ct_page' => $this->getPage(),
			'ct_title' => $this->getHead(),
			'ct_rules' => $this->getRules(),
			'ct_state' => $this->getState(),
		] + (
			$this->hasSearch() ?
			['ct_search' => $this->getSearch()] :
			[]
		);
	}

	/**
	 * @return array
	 */
	public function __sleep()
	{
		return [
			'mNamespace',
			'mHead',
			'mTail',
			'mExt',
			'mRules',
			'mState',
			'mPage',
			'mIsRedirect',
		];
	}

	/**
	 * @param string[] $array
	 * @param ?string $delimiter
	 * @return string[]
	 */
	private static function preg_quote_array($array, $delimiter = null)
	{
		return array_map(fn(string $str): string => preg_quote($str, $delimiter), $array);
	}

	/**
	 * @return TitleFormatter
	 */
	private static function getTitleFormatter()
	{
		return MediaWikiServices::getInstance()->getTitleFormatter();
	}
}
