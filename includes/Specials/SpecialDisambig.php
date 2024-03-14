<?php

namespace MediaWiki\Extension\CollisionManager\Specials;

use IncludableSpecialPage;
use MediaWiki\Extension\CollisionManager\DisambigBuilder;
use Title;
use FormOptions;
use Xml;
use Html;

class SpecialDisambig extends IncludableSpecialPage
{
	/** @var DisambigBuilder */
	private $disambigBuilder;

	/** @var FormOptions */
	protected $opts;

	/** @var Title */
	protected $target;

	/**
	 * @param DisambigBuilder $disambigBuilder
	 */
	public function __construct(
		DisambigBuilder $disambigBuilder
	) {
		parent::__construct('Disambig');
		$this->disambigBuilder = $disambigBuilder;
	}

	/**
	 * @param ?string $query
	 */
	function execute($query)
	{
		$output = $this->getOutput();

		$this->setHeaders();
		$this->outputHeader();

		$opts = new FormOptions();

		$opts->add('target', '');

		$opts->fetchValuesFromRequest($this->getRequest());

		if ($query !== null) {
			$opts->setValue('target', $query);
		}

		$this->opts = $opts;

		$this->target = Title::newFromURL($opts->getValue('target'));
		if ($this->target === null) {
			$output->addHTML($this->disambigForm());
			return;
		}

		$text = $this->target->getPrefixedText();

		$output->setPageTitle($this->msg('disambig-title', $text));
		$output->addBacklinkSubtitle($this->target);

		$output->addHTML($this->disambigBuilder->getDisambig($text, -1));
	}

	function disambigForm()
	{
		// We get nicer value from the title object
		$this->opts->consumeValue('target');

		$target = $this->target ? $this->target->getPrefixedText() : '';

		# Build up the form
		$f = Xml::openElement('form', ['action' => wfScript()]);

		# Values that should not be forgotten
		$f .= Html::hidden('title', $this->getPageTitle()->getPrefixedText());
		foreach ($this->opts->getUnconsumedValues() as $name => $value) {
			$f .= Html::hidden($name, $value);
		}

		$f .= Xml::fieldset($this->msg('disambig')->text());

		# Target input
		$f .= Xml::inputLabel(
			$this->msg('whatlinkshere-page')->text(),
			'target',
			'mw-disambig-target',
			40,
			$target
		);

		$f .= ' ';

		# Submit
		$f .= Xml::submitButton($this->msg('allpagessubmit')->text());

		# Close
		$f .= Html::closeElement('fieldset') . Xml::closeElement('form') . "\n";

		return $f;
	}

	protected function getGroupName()
	{
		return 'pagetools';
	}
}
