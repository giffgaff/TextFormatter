<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2014 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\Items;

use InvalidArgumentException;
use s9e\TextFormatter\Configurator\ConfigProvider;
use s9e\TextFormatter\Configurator\Items\Variant;
use s9e\TextFormatter\Configurator\JavaScript\RegexpConvertor;

class Regexp implements ConfigProvider
{
	/*
	* @var bool Whether this regexp should become a JavaScript RegExp object with global flag
	*/
	protected $isGlobal;

	/*
	* @var string PCRE regexp, with delimiters and modifiers, e.g. "/foo/i"
	*/
	protected $regexp;

	/*
	* Constructor
	*
	* @param  string $regexp PCRE regexp, with delimiters and modifiers, e.g. "/foo/i"
	* @return void
	*/
	public function __construct($regexp, $isGlobal = \false)
	{
		if (@\preg_match($regexp, '') === \false)
			throw new InvalidArgumentException('Invalid regular expression ' . \var_export($regexp, \true));

		$this->regexp   = $regexp;
		$this->isGlobal = $isGlobal;
	}

	/*
	* Return this regexp as a string
	*
	* @return string
	*/
	public function __toString()
	{
		return $this->regexp;
	}

	/*
	* {@inheritdoc}
	*/
	public function asConfig()
	{
		$_this = $this;

		$variant = new Variant($this->regexp);
		$variant->setDynamic(
			'JS',
			function () use ($_this)
			{
				return $_this->toJS();
			}
		);

		return $variant;
	}

	/*
	* Return this regexp as a JavaScript RegExp
	*
	* @return RegExp
	*/
	public function toJS()
	{
		$obj = RegexpConvertor::toJS($this->regexp);

		if ($this->isGlobal)
			$obj->flags .= 'g';

		return $obj;
	}
}