<?php

namespace MediaWiki\Extension\CollisionManager;

use Config;
use MediaWiki\Linker\LinkRenderer;
use SpecialPage;
use Title;
use OutputPage;
use ParserOutput;
use Html;

class DisambigBuilder
{
	/** @var Config */
	private $config;

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var CollisionStore */
	private $collisionStore;

	/**
	 * @param Config $config
	 * @param LinkRenderer $linkRenderer
	 * @param CollisionStore $collisionStore
	 */
	public function __construct(
		Config $config,
		LinkRenderer $linkRenderer,
		CollisionStore $collisionStore
	) {
		$this->config = $config;
		$this->linkRenderer = $linkRenderer;
		$this->collisionStore = $collisionStore;
	}

	/**
	 * {@inheritDoc}
	 * @param OutputPage $outputPage
	 * @param ParserOutput $parserOutput
	 * @param Title $title
	 * @return void
	 */
	public function addDisambig($outputPage, $parserOutput, $title)
	{
		$rule = $this->collisionStore->get($title);

		if ($rule) {
			$rules = $rule->getRulesArray();
			$state = $rule->getState();
		} else {
			$rules = [];
			$state = '';
		}

		$relate = unserialize($parserOutput->getPageProperty('collision-relate'));
		$isNormalPage = $this->collisionStore->isValidTarget($title);

		$rule = CollisionRule::newFromTitle($title, $rules, null, true);

		$head = $rule->getPrefixedHead();

		if ($isNormalPage && $state !== '') {
			$outputPage->addMeta('description', $rule->joinRules() . ' - ' . $state);
		}

		$pageExists = $title->exists();

		if ($isNormalPage) {
			$html = $this->getRedirect($title) . $this->getDisambig($head, $pageExists ? $title->getArticleID() : -1, $relate, $title->getNamespace());
		} else {
			$html = $this->getDisambig($head, -1, $relate, $title->getNamespace());
		}

		if ($html === '') {
			return;
		}

		if (!$pageExists) {
			$outputPage->setStatusCode(200);
		}

		$outputPage->prependHTML($html);
	}

	/**
	 * @param string $target
	 * @param int $id
	 * @param ?string[] $relate
	 * @param ?int $namespace
	 * @return string
	 */
	public function getDisambig($target, $id = -1, $relate = null, $namespace = null)
	{
		$title = Title::newFromText($target, $namespace);
		$namespace = $title->getNamespace();

		$list = '';
		$rows = $this->collisionStore->match($namespace, $title->getText());

		if (!empty($relate)) {
			foreach ($relate as $relateHead => $relateState) {
				$relateTitle = Title::newFromText($relateHead, $namespace);
				if ($relateState === '') {
					if ($relateTitle !== null) {
						$rows += $this->collisionStore->match($relateTitle->getNamespace(), $relateTitle->getText());
					} else {
						$rows += $this->collisionStore->match($namespace, $relateHead);
					}
				} else {
					if ($relateTitle !== null && $relateTitle->exists()) {
						$rows[$relateTitle->getArticleID()] = CollisionRule::newFromTitle($relateTitle, '', $relateState);
					}
				}
			}
		}

		foreach ($rows as $key => $row) {
			if (!$this->collisionStore->isValidTarget($row->getTitle())) {
				unset($rows[$key]);
			}
		}

		if (empty($rows)) {
			return '';
		}

		if ($id !== -1) {
			$state = '';
			$rules = '';
			if (isset($rows[$id])) {
				$state = $rows[$id]->getState();
				$rules = $rows[$id]->getRules();
				unset($rows[$id]);
				if (empty($rows)) {
					return '';
				}
			}
			$maimTitle = SpecialPage::getTitleFor('Disambig', $target);
			$list .= Html::openElement('ul');
			foreach ($rows as $row) {
				$list .= $this->makeListItem($row);
			}
			$list .= Html::closeElement('ul');
			return $this->wrapDisambigBox(
				Html::openElement('div', ['class' => 'mw-collapsible' . (count($rows) >= 10 ? ' mw-collapsed' : '')]) .
				Html::rawElement(
					'div',
					['class' => 'mw-collapsible-caption'],
					wfMessage('collision-manager-disambig')->rawParams(
						$state === '' ? CollisionRule::joinTail($target, $rules) : $target . ' - ' . $state,
						$this->linkRenderer->makeKnownLink($maimTitle, CollisionRule::joinTail($target, wfMessage('disambig')->text()))
					)->text()
				) .
				Html::rawElement('div', ['class' => 'mw-collapsible-content'], $list) .
				Html::closeElement('div')
			);
		} else {
			$list .= wfMessage('disambig-start')->rawParams($target)->text();
			$list .= Html::openElement('ul');
			foreach ($rows as $row) {
				$list .= $this->makeListItem($row);
			}
			$list .= Html::closeElement('ul');
			$list .= $this->wrapDisambigBox(wfMessage('disambig-end')->text());
		}

		return $list;
	}

	/**
	 * @param Title $ogtarget
	 * @return string
	 */
	public function getRedirect($ogtarget)
	{
		if (!($ogtarget instanceof Title) || !$ogtarget->exists()) {
			return '';
		}

		$text = '';
		foreach ($ogtarget->getRedirectsHere() as $title) {
			$target = $title->getPrefixedText();

			$rows = $this->collisionStore->match($title->getNamespace(), $title->getText());

			if (empty($rows) || isset($rows[$ogtarget->getArticleID()])) {
				continue;
			}

			$disambig = SpecialPage::getTitleFor('Disambig', $target);
			$list = '';
			$list .= Html::openElement('ul');
			foreach ($rows as $row) {
				$list .= $this->makeListItem($row);
			}
			$list .= Html::closeElement('ul');
			$text .= $this->wrapDisambigBox(
				Html::openElement('div', ['class' => 'mw-collapsible mw-collapsed']) .
				Html::rawElement(
					'div',
					['class' => 'mw-collapsible-caption'],
					wfMessage('collision-manager-redirect')->rawParams(
						$target,
						$this->linkRenderer->makeKnownLink($disambig, CollisionRule::joinTail($target, wfMessage('disambig')->text()))
					)->text()
				) .
				Html::rawElement('div', ['class' => 'mw-collapsible-content'], $list) .
				Html::closeElement('div')
			);
		}
		return $text;
	}

	/**
	 * @param CollisionRule $rule
	 * @return string
	 */
	private function makeListItem($rule)
	{
		$title = $rule->getTitle();
		$state = $rule->getState();
		$props = $rule->getRulesArray();


		$link = $this->linkRenderer->makeKnownLink($title);
		$props = $state === '' ? implode(wfMessage('comma-separator'), $props) : $state;
		return Html::rawElement(
			'li',
			[],
			Html::rawElement('b', [], $link) . ' - ' . ($props === '' ? wfMessage('disambig-nodesc')->plain() : $props)
		);
	}

	/**
	 * @param string $content
	 * @return string
	 */
	private function wrapDisambigBox($content)
	{
		$disambigIcon = $this->config->get('CollisionManagerDisambigIcon');

		return Html::rawElement(
			'div',
			['class' => 'disambig-box'],
			Html::rawElement(
				'div',
				['class' => 'disambig-box-image'],
				Html::rawElement('img', ['src' => $disambigIcon, 'height' => '24'])
			) .
			Html::rawElement(
				'div',
				['class' => 'disambig-box-text'],
				$content
			)
		);
	}
}
