<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2015 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\JavaScript;
use RuntimeException;
use s9e\TextFormatter\Configurator\Items\Regexp;
use s9e\TextFormatter\Configurator\JavaScript\Code;
use s9e\TextFormatter\Configurator\JavaScript\Dictionary;
use s9e\TextFormatter\Configurator\JavaScript\Encoder;
class Encoder
{
	public $objectEncoders;
	public $typeEncoders;
	public function __construct()
	{
		$this->objectEncoders = [
			's9e\\TextFormatter\\Configurator\\Items\\Regexp'          => [$this, 'encodeRegexp'],
			's9e\\TextFormatter\\Configurator\\JavaScript\\Code'       => [$this, 'encodeCode'],
			's9e\\TextFormatter\\Configurator\\JavaScript\\Dictionary' => [$this, 'encodeDictionary']
		];
		$this->typeEncoders = [
			'array'   => [$this, 'encodeArray'],
			'boolean' => [$this, 'encodeBoolean'],
			'double'  => [$this, 'encodeScalar'],
			'integer' => [$this, 'encodeScalar'],
			'object'  => [$this, 'encodeObject'],
			'string'  => [$this, 'encodeScalar']
		];
	}
	public function encode($value)
	{
		$type = \gettype($value);
		if (!isset($this->typeEncoders[$type]))
			throw new RuntimeException('Cannot encode ' . $type . ' value');
		return $this->typeEncoders[$type]($value);
	}
	protected function encodeArray(array $array)
	{
		return (empty($array) || \array_keys($array) === \range(0, \count($array) - 1)) ? $this->encodeIndexedArray($array) : $this->encodeAssociativeArray($array);
	}
	protected function encodeAssociativeArray(array $array, $preserveNames = \false)
	{
		\ksort($array);
		$src = '{';
		$sep = '';
		foreach ($array as $k => $v)
		{
			$src .= $sep . $this->encodePropertyName($k, $preserveNames) . ':' . $this->encode($v);
			$sep = ',';
		}
		$src .= '}';
		return $src;
	}
	protected function encodeBoolean($value)
	{
		return ($value) ? '!0' : '!1';
	}
	protected function encodeCode(Code $code)
	{
		return (string) $code;
	}
	protected function encodeDictionary(Dictionary $dict)
	{
		return $this->encodeAssociativeArray($dict->getArrayCopy(), \true);
	}
	protected function encodeIndexedArray(array $array)
	{
		return '[' . \implode(',', \array_map([$this, 'encode'], $array)) . ']';
	}
	protected function encodeObject($object)
	{
		foreach ($this->objectEncoders as $className => $callback)
			if ($object instanceof $className)
				return $callback($object);
		throw new RuntimeException('Cannot encode instance of ' . \get_class($object));
	}
	protected function encodePropertyName($name, $preserveNames)
	{
		return ($preserveNames || !$this->isLegalProp($name)) ? \json_encode($name) : $name;
	}
	protected function encodeRegexp(Regexp $regexp)
	{
		return (string) $regexp->toJS();
	}
	protected function encodeScalar($value)
	{
		return \json_encode($value);
	}
	protected function isLegalProp($name)
	{
		$reserved = ['abstract', 'boolean', 'break', 'byte', 'case', 'catch', 'char', 'class', 'const', 'continue', 'debugger', 'default', 'delete', 'do', 'double', 'else', 'enum', 'export', 'extends', 'false', 'final', 'finally', 'float', 'for', 'function', 'goto', 'if', 'implements', 'import', 'in', 'instanceof', 'int', 'interface', 'let', 'long', 'native', 'new', 'null', 'package', 'private', 'protected', 'public', 'return', 'short', 'static', 'super', 'switch', 'synchronized', 'this', 'throw', 'throws', 'transient', 'true', 'try', 'typeof', 'var', 'void', 'volatile', 'while', 'with'];
		if (\in_array($name, $reserved, \true))
			return \false;
		return (bool) \preg_match('#^[$_\\pL][$_\\pL\\pNl]+$#Du', $name);
	}
}