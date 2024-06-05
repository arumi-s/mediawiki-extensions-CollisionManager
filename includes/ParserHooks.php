<?php

namespace MediaWiki\Extension\CollisionManager;

use MediaWiki\MediaWikiServices;
use Parser;
use Title;
use Html;

class ParserHooks implements \MediaWiki\Hook\ParserFirstCallInitHook
{
	/**
	 * {@inheritDoc}
	 * @param Parser $parser
	 * @return bool|void
	 */
	public function onParserFirstCallInit($parser)
	{
		$parser->setFunctionHook('colrule', [ParserHooks::class, 'colrule']);
		$parser->setFunctionHook('colfind', [ParserHooks::class, 'colfind']);
		$parser->setFunctionHook('colrelate', [ParserHooks::class, 'colrelate']);
		$parser->setFunctionHook('colgetstate', [ParserHooks::class, 'colgetstate']);
	}

	/**
	 * @param Parser $parser
	 * @param string $state
	 * @param string ...$rules
	 * @return string
	 */
	public static function colrule($parser, $state = '', ...$rules)
	{
		$title = $parser->getTitle();
		$rule = CollisionRule::newFromTitle($title, $rules, $state);

		$collisionStore = self::getCollisionStore();
		$normalizeHead = $collisionStore->normalize($rule->getHead());

		$output = $parser->getOutput();
		$output->setPageProperty('collision-title', $normalizeHead);
		$output->setPageProperty('collision-rules', $rule->getRules());
		$output->setPageProperty('collision-state', $rule->getState());

		return Html::element(
			'div',
			[
				'class' => 'disambig-description',
				'style' => 'display:none;'
			],
			$rule->print()
		);
	}

	/**
	 * @param Parser $parser
	 * @param string $text
	 * @param string ...$rules
	 * @return string
	 */
	public static function colfind($parser, $text = '', ...$rules)
	{
		$title = Title::newFromText($text);
		if ($title === null) {
			return '';
		}

		$collisionStore = self::getCollisionStore();
		return $collisionStore->find($title, $rules);
	}

	/**
	 * @param Parser $parser
	 * @param string $title
	 * @param string $desc
	 * @return string
	 */
	public static function colrelate($parser, $title = '', $desc = '')
	{
		if ($title === '') {
			return '';
		}

		$desc = trim($desc);
		if ($desc === '') {
			$collisionStore = self::getCollisionStore();
			$title = $collisionStore->normalize($title);
		}

		if ($title !== '') {
			$output = $parser->getOutput();
			$relate = $output->getPageProperty('collision-relate');
			$relate = $relate === null ? [] : unserialize($relate);

			$relate[$title] = $desc;
			$output->setPageProperty('collision-relate', serialize($relate));
		}

		return '';
	}

	/**
	 * @param Parser $parser
	 * @param string $text
	 * @return string
	 */
	public static function colgetstate($parser, $text = '')
	{
		$title = Title::newFromText($text);
		if ($title === null) {
			return '';
		}

		$collisionStore = self::getCollisionStore();
		$rule = $collisionStore->get($title);
		if ($rule === null) {
			return '';
		}

		return $rule->getState();
	}

	/**
	 * @return CollisionStore
	 */
	private static function getCollisionStore()
	{
		static $collisionStore = null;

		if ($collisionStore === null) {
			$collisionStore = MediaWikiServices::getInstance()->get('CollisionManager.CollisionStore');
		}

		return $collisionStore;
	}
}
