<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2014 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\Collections;

use s9e\TextFormatter\Configurator\TemplateCheck;

class TemplateCheckList extends NormalizedList
{
	public function normalizeValue($check)
	{
		if (!($check instanceof TemplateCheck))
		{
			$className = 's9e\\TextFormatter\\Configurator\\TemplateChecks\\' . $check;
			$check     = new $className;
		}

		return $check;
	}
}