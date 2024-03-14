<?php

namespace MediaWiki\Extension\CollisionManager;

class Ranker
{
	/** @var mixed */
	public $key = null;

	/** @var number */
	public $value = null;

	/** @var int */
	public $count = 0;

	/** @var bool */
	public $equal = false;

	/**
	 * @param mixed $key
	 * @param number $value
	 * @return bool True if success
	 */
	public function min($key, $value): bool
	{
		++$this->count;

		if ($this->value !== null && $value > $this->value) {
			return false;
		}

		$this->equal = $value === $this->value;
		$this->key = $key;
		$this->value = $value;

		return true;
	}

	/**
	 * @param mixed $key
	 * @param number $value
	 * @return bool True if success
	 */
	public function max($key, $value): bool
	{
		++$this->count;

		if ($this->value !== null && $value < $this->value) {
			return false;
		}

		$this->equal = $value === $this->value;
		$this->key = $key;
		$this->value = $value;

		return true;
	}
}
