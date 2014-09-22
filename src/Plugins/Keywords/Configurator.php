<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2014 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Plugins\Keywords;

use s9e\TextFormatter\Configurator\Collections\NormalizedList;
use s9e\TextFormatter\Configurator\Helpers\RegexpBuilder;
use s9e\TextFormatter\Configurator\Items\Regexp;
use s9e\TextFormatter\Configurator\Traits\CollectionProxy;
use s9e\TextFormatter\Plugins\ConfiguratorBase;

/*
* @method mixed   add(string $key)
* @method array   asConfig()
* @method void    clear()
* @method bool    contains(mixed $value)
* @method integer count()
* @method mixed   current()
* @method void    delete(string $key)
* @method bool    exists(string $key)
* @method mixed   get(string $key)
* @method mixed   indexOf(mixed $value)
* @method integer|string key()
* @method mixed   next()
* @method string  normalizeKey(string $key)
* @method mixed   normalizeValue(mixed $value)
* @method bool    offsetExists(string|integer $offset)
* @method mixed   offsetGet(string|integer $offset)
* @method void    offsetSet(string|integer $offset)
* @method void    offsetUnset(string|integer $offset)
* @method string  onDuplicate(string|null $action)
* @method void    rewind()
* @method mixed   set(string $key)
* @method bool    valid()
*/
class Configurator extends ConfiguratorBase
{
	/*
	* Forward all unknown method calls to $this->collection
	*
	* @param  string $methodName
	* @param  array  $args
	* @return mixed
	*/
	public function __call($methodName, $args)
	{
		return \call_user_func_array(array($this->collection, $methodName), $args);
	}

	//==========================================================================
	// ArrayAccess
	//==========================================================================

	/*
	* @param  string|integer $offset
	* @return bool
	*/
	public function offsetExists($offset)
	{
		return isset($this->collection[$offset]);
	}

	/*
	* @param  string|integer $offset
	* @return mixed
	*/
	public function offsetGet($offset)
	{
		return $this->collection[$offset];
	}

	/*
	* @param  string|integer $offset
	* @param  mixed          $value
	* @return void
	*/
	public function offsetSet($offset, $value)
	{
		$this->collection[$offset] = $value;
	}

	/*
	* @param  string|integer $offset
	* @return void
	*/
	public function offsetUnset($offset)
	{
		unset($this->collection[$offset]);
	}

	//==========================================================================
	// Countable
	//==========================================================================

	/*
	* @return integer
	*/
	public function count()
	{
		return \count($this->collection);
	}

	//==========================================================================
	// Iterator
	//==========================================================================

	/*
	* @return mixed
	*/
	public function current()
	{
		return $this->collection->current();
	}

	/*
	* @return string|integer
	*/
	public function key()
	{
		return $this->collection->key();
	}

	/*
	* @return mixed
	*/
	public function next()
	{
		return $this->collection->next();
	}

	/*
	* @return void
	*/
	public function rewind()
	{
		$this->collection->rewind();
	}

	/*
	* @return boolean
	*/
	public function valid()
	{
		return $this->collection->valid();
	}

	/*
	* @var string Name of the attribute used by this plugin
	*/
	protected $attrName = 'value';

	/*
	* @var bool Whether keywords are case-sensitive
	*/
	public $caseSensitive = \true;

	/*
	* @var \s9e\TextFormatter\Configurator\Collections\NormalizedCollection List of [keyword => value]
	*/
	protected $collection;

	/*
	* @var boolean Whether to capture only the first occurence of each keyword
	*/
	public $onlyFirst = \false;

	/*
	* @var string Name of the tag used by this plugin
	*/
	protected $tagName = 'KEYWORD';

	/*
	* {@inheritdoc}
	*/
	protected function setUp()
	{
		$this->collection = new NormalizedList;

		$this->configurator->tags->add($this->tagName)->attributes->add($this->attrName);
	}

	/*
	* {@inheritdoc}
	*/
	public function asConfig()
	{
		if (!\count($this->collection))
			return \false;

		$config = array(
			'attrName' => $this->attrName,
			'tagName'  => $this->tagName
		);

		if (!empty($this->onlyFirst))
			$config['onlyFirst'] = $this->onlyFirst;

		// Sort keywords in order to keep keywords that start with the same characters together. We
		// also remove duplicates that would otherwise skew the length computation done below
		$keywords = \array_unique(\iterator_to_array($this->collection));
		\sort($keywords);

		// Group keywords by chunks of ~30KB to remain below PCRE's limit
		$groups   = array();
		$groupKey = 0;
		$groupLen = 0;
		foreach ($keywords as $keyword)
		{
			// NOTE: the value 4 is a guesstimate for the cost of each alternation
			$keywordLen  = 4 + \strlen($keyword);
			$groupLen   += $keywordLen;

			if ($groupLen > 30000)
			{
				$groupLen = $keywordLen;
				++$groupKey;
			}

			$groups[$groupKey][] = $keyword;
		}

		foreach ($groups as $keywords)
		{
			$regexp = RegexpBuilder::fromList(
				$keywords,
				array('caseInsensitive' => !$this->caseSensitive)
			);

			$regexp = '/\\b' . $regexp . '\\b/S';

			if (!$this->caseSensitive)
				$regexp .= 'i';

			if (\preg_match('/[^[:ascii:]]/', $regexp))
				$regexp .= 'u';

			$config['regexps'][] = new Regexp($regexp, \true);
		}

		return $config;
	}
}