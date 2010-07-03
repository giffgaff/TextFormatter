<?php

/**
* @package   s9e\toolkit
* @copyright Copyright (c) 2010 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\toolkit\markup;

/*

BBCodes could optionally carry an ID (a number), to make it easier to include non-parsed BBCodes

[b:1]Bold text uses [b] and [/b][/b:1]
<m:b><st>[b:1]</st>Bold text uses [b] and [/b]<et>[/b:1]</et></m:b>

===================

[url]foo [tag="[/url]"] bar[/url]

===================

During parsing. If we need to use the content as param, we can grab the content right after parsing
the first tag using stripos($text, '[/url]')

===================

Use case-sensitivity instead of namespace to differentiate BBCodes from other markup?

<rt><S>strikethrough</S><s>:smiley:</s></rt>
<rt xmlns:m="urn:markup"><m:s>strikethrough</m:s><s>:smiley:</s></rt>

===================

Add unique token to special BBCodes (from autolink, smilies, censors, etc...) so that the virtual close tag doesn't accidentally close an actual parent tag

*/

class parser
{
	/**
	* Opening tag, e.g. [b]
	* -- becomes <B><st>[b]</st>
	*/
	const TAG_OPEN  = 1;

	/**
	* Closing tag, e.g. [/b]
	* -- becomes <et>[/b]</et></B>
	*/
	const TAG_CLOSE = 2;

	/**
	* Self-closing tag, e.g. [img="http://..." /]
	* -- becomes <IMG>[img="http://..." /]</IMG>
	*
	* NOTE: TAG_SELF = TAG_OPEN | TAG_CLOSE
	*/
	const TAG_SELF  = 3;

	/**
	* @var	array
	*/
	public $msgs;

	/**
	* @var	array
	*/
	protected $config;

	public function __construct(array $config)
	{
		$this->filters = $config['filters'];
		unset($config['filters']);

		$this->config = $config;
	}

	static public function getBBCodeTags($text, array $config)
	{
		$tags = array();
		$msgs = array();
		$cnt  = preg_match_all($config['regexp'], $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

		if (!$cnt)
		{
			return;
		}

		if (!empty($config['limit'])
		 && $cnt > $config['limit'])
		{
			if ($config['limit_action'] === 'abort')
			{
				throw new \RuntimeException('BBCode tags limit exceeded');
			}
			else
			{
				$msg_type = ($config['limit_action'] === 'ignore') ? 'debug' : 'warning';
				$matches  = array_slice($matches, 0, $limit);

				$msgs[$msg_type][] = array(
					'pos'    => 0,
					'msg'    => 'BBCode tags limit exceeded. Only the first %1s tags will be processed',
					'params' => array($config['limit'])
				);
			}
		}

		$bbcodes  = $config['bbcodes'];
		$aliases  = $config['aliases'];
		$text_len = strlen($text);

		foreach ($matches as $m)
		{
			/**
			* @var Position of the first character of current BBCode, which should be a [
			*/
			$lpos = $m[0][1];

			/**
			* @var Position of the last character of current BBCode, starts as the position of
			*      the =, ] or : char, then moves to the right as the BBCode is parsed
			*/
			$rpos = $lpos + strlen($m[0][0]);

			/**
			* Check for BBCode suffix
			*
			* Used to skip the parsing of closing BBCodes, e.g.
			*   [code:1][code]type your code here[/code][/code:1]
			*
			*/
			if ($text[$rpos] === ':')
			{
				/**
				* [code:1] or [/code:1]
				* $suffix = ':1'
				*/
				$spn     = strspn($text, '1234567890', 1 + $rpos);
				$suffix  = substr($text, $rpos, 1 + $spn);
				$rpos   += 1 + $spn;
			}
			else
			{
				$suffix  = '';
			}

			$alias = strtoupper($m[1][0]);

			if (!isset($aliases[$alias]))
			{
				// Not a known BBCode or alias
				continue;
			}

			$bbcode_id = $aliases[$alias];
			$bbcode    = $bbcodes[$bbcode_id];

			if (!empty($bbcode['internal_use']))
			{
				$msgs['warning'][] = array(
					'pos'    => $lpos,
					'msg'    => 'BBCode %1$s is for internal use only',
					'params' => array($bbcode_id)
				);
				continue;
			}

			if ($m[0][0][1] === '/')
			{
				if ($text[$rpos] !== ']')
				{
					$msgs['warning'][] = array(
						'pos'    => $rpos,
						'msg'    => 'Unexpected character %1$s',
						'params' => array($text[$rpos])
					);
					continue;
				}
			}
			else
			{
				$well_formed = false;
				$params      = array();
				$param       = null;

				if ($text[$rpos] === '=')
				{
					/**
					* [quote=
					*
					* Set the default param. If there's no default param, we issue a warning and
					* reuse the BBCode's name instead
					*/
					if ($bbcode['default_param'])
					{
						$param = $bbcode['default_param'];
					}
					else
					{
						$param = strtolower($bbcode_id);

						$msgs['warning'][] = array(
							'pos'    => $rpos,
							'msg'    => "BBCode %1$s does not have a default param, using BBCode's name as param name",
							'params' => array($bbcode_id)
						);
					}

					++$rpos;
				}

				while ($rpos < $text_len)
				{
					$c = $text[$rpos];

					if ($c === ']')
					{
						if (isset($param))
						{
							/**
							* [quote=]
							* [quote username=]
							*/
							$msgs['warning'][] = array(
								'pos'    => $rpos,
								'msg'    => 'Unexpected character %1$s',
								'params' => array($c)
							);
							continue 2;
						}

						$well_formed = true;
						break;
					}

					if ($c === ' ')
					{
						continue;
					}

					if (!isset($param))
					{
						/**
						* Capture the param name
						*/
						$spn = strspn($text, 'abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJKLMNOPQRSTUVWXYZ', $rpos);

						if (!$spn)
						{
							$msgs['warning'][] = array(
								'pos'    => $rpos,
								'msg'    => 'Unexpected character %1$s',
								'params' => array($c)
							);
							continue 2;
						}

						if ($rpos + $spn >= $text_len)
						{
							continue 2;
						}

						$param = strtolower(substr($text, $rpos, $spn));
						$rpos += $spn;

						if ($text[$rpos] !== '=')
						{
							$msgs['warning'][] = array(
								'pos'    => $rpos,
								'msg'    => 'Unexpected character %1$s',
								'params' => array($text[$rpos])
							);
							continue 2;
						}

						if (++$rpos <= $text_len)
						{
							continue 2;
						}
					}

					if ($c === '"' || $c === "'")
					{
						 $value_pos = $rpos + 1;

						 while (++$rpos < $text_len)
						 {
							 $rpos = strpos($text, $c, $rpos);

							 if ($rpos === false)
							 {
								 /**
								 * No matching quote, apparently that string never ends...
								 */
								 continue 2;
							 }

							if ($text[$rpos - 1] === '\\')
							{
								$n = 1;
								do
								{
									++$n;
								}
								while ($text[$rpos - $n] === '\\');

								if ($n % 2 === 0)
								{
									continue;
								}
							}

							break;
						}

						$value = stripslashes(substr($text, $value_pos, $rpos - $value_pos));
					}
					else
					{
						$spn   = strcspn($text, "] \n\r", $rpos);
						$value = substr($text, $rpos, $spn);

						$rpos += $spn;
					}

					if (isset($bbcode['params'][$param]))
					{
						/**
						* We only keep params that exist in the BBCode's definition
						*/
						$params[$param] = $value;
					}

					unset($param, $value);
				}

				if (!$well_formed)
				{
					continue;
				}
			}

			$tags[] = array(
				'name'   => $bbcode_id,
				'pos'    => $lpos,
				'len'    => $rpos + 1 - $lpos,
				'type'   => ($m[0][0][1] === '/') ? self::TAG_CLOSE  : self::TAG_OPEN,
				'suffix' => $suffix,
				'params' => $params
			);
		}

		return array(
			'tags' => $tags,
			'msgs' => $msgs
		);
	}

	public function parse($text)
	{
		$this->msgs = $tags = array();

		$pass = 0;
		foreach ($this->config as $config)
		{
			if (isset($config['parser']))
			{
				$ret = call_user_func($config['parser'], $text, $config);

				if (!empty($ret['msgs']))
				{
					$this->msgs = array_merge_recursive($this->msgs, $ret['msgs']);
				}

				if (!empty($ret['tags']))
				{
					$suffix = '-' . mt_rand();
					foreach ($ret['tags'] as $tag)
					{
						if (!isset($tag['suffix']))
						{
							$tag['suffix'] = $suffix;
						}
						$tag['pass'] = $pass;
						$tags[]      = $tag;
					}
				}

				++$pass;
			}
		}

		$xml = new \XMLWriter;
		$xml->openMemory();

		if (empty($tags))
		{
			$xml->writeElement('pt', $text);
			return nl2br(trim($xml->outputMemory()));
		}

		/**
		* Sort by pos descending, tag type ascending (OPEN, CLOSE, SELF), pass descending
		*/
		usort($tags, function($a, $b)
		{
			return ($b['pos'] - $a['pos'])
			    ?: ($a['type'] - $b['type'])
			    ?: ($b['pass'] - $a['pass']);
		});


		//======================================================================
		// Time to get serious
		//======================================================================

		$aliases  = $this->config['bbcode']['aliases'];
		$bbcodes  = $this->config['bbcode']['bbcodes'];

		/**
		* @var	array	Open BBCodes
		*/
		$bbcode_stack = array();

		/**
		* @var	array	List of allowed BBCode tags in current context. Starts as a copy of $aliases
		*/
		$allowed = $aliases;

		/**
		* @var	array	Number of times each BBCode has been used
		*/
		$cnt_total = array_fill_keys($allowed, 0);

		/**
		* @var	array	Number of open tags for each bbcode_id
		*/
		$cnt_open = $cnt_total;

		/**
		* @var	array	Keeps track open tags (tags carry their suffix)
		*/
		$open_tags = array();

		$xml->startElement('rt');

		$pos = 0;
		do
		{
			$tag = array_pop($tags);

			if ($pos > $tag['pos'])
			{
				$this->msgs['debug'][] = array(
					'pos'    => $tag['pos'],
					'msg'    => 'Tag skipped',
					'params' => array()
				);
				continue;
			}

			$bbcode_id = $tag['name'];
			if (!isset($bbcodes[$bbcode_id]))
			{
				$bbcode_id = strtoupper($bbcode_id);

				if (!isset($aliases[$bbcode_id]))
				{
					$this->msgs['debug'][] = array(
						'pos'    => $tag['pos'],
						'msg'    => 'Unknown BBCode %1$s from pass %2$s',
						'params' => array($bbcode_id, $tag['pass'])
					);
					continue;
				}

				$bbcode_id = $aliases[$bbcode_id];
			}
			$bbcode = $bbcodes[$bbcode_id];


			//==================================================================
			// Start tag
			//==================================================================

			if ($tag['type'] & self::TAG_OPEN)
			{
				//==============================================================
				// Check that this BBCode is allowed here
				//==============================================================

				if (!empty($bbcode['close_parent']))
				{
					/**
					* Oh, wait, we may have to close its parent first
					*/
					$last_bbcode = end($bbcode_stack);
					foreach ($bbcode['close_parent'] as $parent)
					{
						if ($last_bbcode['bbcode_id'] === $parent)
						{
							/**
							* So we do have to close that parent. First we reinsert current tag then
							* we append a new closing tag for the parent.
							*/
							$tags[] = $tag;
							$tags[] = array(
								'pos'  => $tag['pos'],
								'name' => $parent,
								'len'  => 0,
								'type' => self::TAG_CLOSE
							);
							continue 2;
						}
					}
				}

				if ($bbcode['nesting_limit'] <= $cnt_open[$bbcode_id]
				 || $bbcode['tag_limit']     <= $cnt_total[$bbcode_id])
				{
					continue;
				}

				if (!isset($allowed[$bbcode_id]))
				{
					$this->msgs['debug'][] = array(
						'pos'    => $tag['pos'],
						'msg'    => 'BBCode %1$s is not allowed in this context',
						'params' => array($bbcode_id)
					);
					continue;
				}

				if (isset($bbcode['require_parent']))
				{
					$last_bbcode = end($bbcode_stack);

					if (!$last_bbcode
					 || $last_bbcode['bbcode_id'] !== $bbcode['require_parent'])
					{
						$this->msgs['debug'][] = array(
							'pos'    => $tag['pos'],
							'msg'    => 'BBCode %1$s requires %2$s as parent',
							'params' => array($bbcode_id, $bbcode['require_parent'])
						);

						continue;
					}
				}

				if (isset($bbcode['require_ascendant']))
				{
					foreach ($bbcode['require_ascendant'] as $ascendant)
					{
						if (empty($cnt_open[$ascendant]))
						{
							$this->msgs['debug'][] = array(
								'pos'    => $tag['pos'],
								'msg'    => 'BBCode %1$s requires %2$s as ascendant',
								'params' => array($bbcode_id, $ascendant)
							);
							continue 2;
						}
					}
				}

				//==============================================================
				// Ok, so we have a valid BBCode, we can append it to the XML
				//==============================================================

				if ($tag['pos'] !== $pos)
				{
					$xml->text(substr($text, $pos, $tag['pos'] - $pos));
				}
				$pos = $tag['pos'] + $tag['len'];

				++$cnt_total[$bbcode_id];

				$xml->startElement($bbcode_id);
				if (!empty($tag['params']))
				{
					foreach ($tag['params'] as $param => $value)
					{
						$xml->writeAttribute($param, $value);
					}
				}

				if ($tag['len'])
				{
					$xml->writeElement('st', substr($text, $tag['pos'], $tag['len']));
				}

				if ($tag['type'] & self::TAG_CLOSE)
				{
					$xml->endElement();
					continue;
				}

				++$cnt_open[$bbcode_id];

				$suffix = (isset($tag['suffix'])) ? $tag['suffix'] : '';
				if (isset($open_tags[$bbcode_id . $suffix]))
				{
					++$open_tags[$bbcode_id . $suffix];
				}
				else
				{
					$open_tags[$bbcode_id . $suffix] = 1;
				}

				$bbcode_stack[] = array(
					'bbcode_id' => $bbcode_id,
					'suffix'	=> $suffix,
					'allowed'   => $allowed
				);
				$allowed = array_intersect_key($allowed, $bbcode['allow']);
			}

			//==================================================================
			// End tag
			//==================================================================

			if ($tag['type'] & self::TAG_CLOSE)
			{
				if (empty($open_tags[$bbcode_id . $suffix]))
				{print_r($tag);print_r($open_tags);	
					/**
					* This is an end tag but there's no matching start tag
					*/
					$this->msgs['debug'][] = array(
						'pos'    => $tag['pos'],
						'msg'    => 'Could not find a matching start tag for BBCode %1$s',
						'params' => array($bbcode_id . $suffix)
					);
					continue;
				}

				if ($tag['pos'] > $pos)
				{
					$xml->text(substr($text, $pos, $tag['pos'] - $pos));
				}

				$pos = $tag['pos'] + $tag['len'];

				do
				{
					$cur     = array_pop($bbcode_stack);
					$allowed = $cur['allowed'];

					--$cnt_open[$cur['bbcode_id']];
					--$open_tags[$cur['bbcode_id'] . $cur['suffix']];

					if ($cur['bbcode_id'] === $bbcode_id)
					{
						if ($tag['len'])
						{
							$xml->writeElement('et', substr($text, $tag['pos'], $tag['len']));
						}
						$xml->endElement();
						break;
					}
					$xml->endElement();
				}
				while ($cur);
			}
		}
		while (!empty($tags));

		$xml->text(substr($text, $pos));
		$xml->endDocument();

		return nl2br(trim($xml->outputMemory()));
	}

	static public function getCensorTags($text, array $config)
	{
		$bbcode = $config['bbcode'];
		$param  = $config['param'];

		$cnt  = 0;
		$tags = array();

		foreach ($config['regexp'] as $k => $regexp)
		{
			if (substr($regexp, -1) !== 'u')
			{
				/**
				* The regexp isn't Unicode-aware, does $text contain more than ASCII?
				*/
				if (!isset($is_utf8))
				{
					$is_utf8 = preg_match('#[\\x80-\\xff]#', $text);
				}

				if ($is_utf8)
				{
					$regexp .= 'u';
				}
			}

			$_cnt = preg_match_all($regexp, $text, $matches, PREG_OFFSET_CAPTURE);

			if (!$_cnt)
			{
				continue;
			}

			$cnt += $_cnt;

			if (!empty($config['limit'])
			 && $cnt > $config['limit'])
			{
				if ($config['limit_action'] === 'abort')
				{
					throw new \RuntimeException('Censor limit exceeded');
				}
				else
				{
					$msg_type   = ($config['limit_action'] === 'ignore') ? 'debug' : 'warning';
					$matches[0] = array_slice($matches[0], 0, $limit);

					$msgs[$msg_type][] = array(
						'pos'    => 0,
						'msg'    => 'Censor limit exceeded. Only the first %1s matches will be processed',
						'params' => array($config['limit'])
					);
				}
			}

			$replacements = (isset($config['replacements'][$k])) ? $config['replacements'][$k] : array();

			foreach ($matches as $m)
			{
				$tag = array(
					'pos'  => $m[1],
					'name' => $bbcode,
					'type' => self::TAG_SELF,
					'len'  => strlen($m[0])
				);

				foreach ($replacements as $mask => $replacement)
				{
					if (preg_match($mask, $m[0]))
					{
						$tag['params'][$param] = $replacement;
						break;
					}
				}

				$tags[$pos] = $tag;
			}

			if (!empty($config['limit'])
			 && $cnt > $config['limit'])
			{
				break;
			}
		}

		return array(
			'tags' => $tags,
			'msgs' => $msgs
		);
	}

	static public function getAutolinkTags($text, array $config)
	{
		$tags = array();
		$msgs = array();
		$cnt  = preg_match_all($config['regexp'], $text, $matches, PREG_OFFSET_CAPTURE);

		if (!$cnt)
		{
			return;
		}

		if (!empty($config['limit'])
		 && $cnt > $config['limit'])
		{
			if ($config['limit_action'] === 'abort')
			{
				throw new \RuntimeException('Autolink limit exceeded');
			}
			else
			{
				$msg_type   = ($config['limit_action'] === 'ignore') ? 'debug' : 'warning';
				$matches[0] = array_slice($matches[0], 0, $limit);

				$msgs[$msg_type][] = array(
					'pos'    => 0,
					'msg'    => 'Autolink limit exceeded. Only the first %1s links will be processed',
					'params' => array($config['limit'])
				);
			}
		}

		$bbcode = $config['bbcode'];
		$param  = $config['param'];

		foreach ($matches[0] as $m)
		{
			$url = $m[0];

			/**
			* Remove some trailing punctuation. We preserve right parentheses if there's a left
			* parenthesis in the URL, as in http://en.wikipedia.org/wiki/Mars_(disambiguation) 
			*/
			$url   = rtrim($url);
			$rtrim = (strpos($url, '(')) ? '.' : ').';
			$url   = rtrim($url, $rtrim);

			$tags[] = array(
				'pos'    => $m[1],
				'name'   => $bbcode,
				'type'   => self::TAG_OPEN,
				'len'    => 0,
				'params' => array($param => $url)
			);
			$tags[] = array(
				'pos'    => $m[1] + strlen($url),
				'name'   => $bbcode,
				'type'   => self::TAG_CLOSE,
				'len'    => 0
			);
		}

		return array(
			'tags' => $tags,
			'msgs' => $msgs
		);
	}

	static public function getSmileyTags($text, array $config)
	{
		$cnt = preg_match_all($config['regexp'], $text, $matches, PREG_OFFSET_CAPTURE);

		if (!$cnt)
		{
			return;
		}

		if (!empty($config['limit'])
		 && $cnt > $config['limit'])
		{
			if ($config['limit_action'] === 'abort')
			{
				throw new \RuntimeException('Smilies limit exceeded');
			}
			else
			{
				$msg_type   = ($config['limit_action'] === 'ignore') ? 'debug' : 'warning';
				$matches[0] = array_slice($matches[0], 0, $limit);

				$msgs[$msg_type][] = array(
					'pos'    => 0,
					'msg'    => 'Smilies limit exceeded. Only the first %1s smilies will be processed',
					'params' => array($config['limit'])
				);
			}
		}

		$tags   = array();
		$bbcode = $config['bbcode'];
		$param  = $config['param'];

		foreach ($matches as $m)
		{
			$tags[] = array(
				'pos'    => $m[1],
				'name'   => $bbcode,
				'len'    => strlen($m[0]),
				'params' => array($param => $m[0])
			);
		}

		return $tags;
	}

	public function filter($var, $type)
	{
		if (isset($this->filters[$type]['callback']))
		{
			return call_user_func($this->filters[$type]['callback'], $var, $this->filters[$type]);
		}

		switch ($type)
		{
			case 'url':
				$var = filter_var($var, \FILTER_VALIDATE_URL);

				if (!$var)
				{
					return false;
				}

				$p = parse_url($var);

				if (!preg_match($this->filters['url']['allowed_schemes'], $p['scheme']))
				{
					return false;
				}

				if (isset($this->filters['url']['disallowed_hosts'])
				 && preg_match($this->filters['url']['disallowed_hosts'], $p['host']))
				{
					return false;
				}
				return $var;

			case 'email':
				break;

			case 'number':
				if (!is_numeric($var))
				{
					return false;
				}
				return (float) $var;

			case 'int':
			case 'integer':
				return filter_var($var, \FILTER_VALIDATE_INT);

			case 'uint':
				return filter_var($var, \FILTER_VALIDATE_INT, array(
					'options' => array('min_range' => 0)
				));

			case 'range':
				if (!preg_match('#^(-?\\d+),(-?\\d+)$#D', $extra, $m))
				{
					$this->msgs['debug'][] = array(
						'msg'    => 'Could not interpret range %s',
						'params' => array($extra)
					);
					return false;
				}
				return filter_var($var, \FILTER_VALIDATE_INT, array(
					'options' => array(
						'min_range' => $m[1],
						'max_range' => $m[2]
					)
				));

			default:
				$this->msgs['debug'][] = array(
					'msg'    => 'Unknown filter %s',
					'params' => array($type)
				);
				return false;
		}
	}
}