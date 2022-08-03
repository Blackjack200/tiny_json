<?php

/**
 * @see tiny_json_decode() as same as json_decode($json,true)
 * @see tiny_json_decode_file()
 */

const TOKENS = '{}[],:';

class Token {
	public function __construct(
		public string $type,
		public mixed  $value = null,
	) {
	}
}

class NestedCounter {
	private int $cnt = 0;

	public function open() : void { $this->cnt++; }

	public function close() : void {
		$this->cnt--;
		if ($this->cnt < 0) {
			throw new RuntimeException();
		}
	}

	public function finalizeCheck() : void {
		if ($this->cnt !== 0) {
			throw new RuntimeException();
		}
	}
}

class JsonNode implements JsonSerializable {
	public function __construct(
		public string $type,
		public mixed  $value = null,
		/** @ignored */
		public bool   $autoClose = true
	) {

	}

	public function jsonSerialize() {
		return [
			'type' => $this->type,
			'value' => $this->value,
		];
	}
}

function nested(string $chr, string $open, string $close, NestedCounter $counter) : bool {
	if ($chr === $open) {
		$counter->open();
		return true;
	}
	if ($chr === $close) {
		$counter->close();
		return true;
	}
	return false;
}

function parseLiteral(string $literal) : mixed {
	$lower = strtolower($literal);
	if ($lower === 'true') {
		return true;
	}
	if ($lower === 'false') {
		return false;
	}
	if ($lower === 'null') {
		return null;
	}
	if (is_numeric($literal)) {
		if (str_contains($literal, '.')) {
			return (float) $literal;
		}
		return (int) $literal;
	}
	//TODO SNBT literal
	if (str_starts_with($literal, '"') && str_ends_with($literal, '"')) {
		$reel = substr($literal, 1, -1);
		$buf = '';
		$escape = false;
		for ($i = 0, $m = strlen($reel); $i < $m; $i++) {
			$chr = $reel[$i];
			if (!$escape) {
				if ($chr === '\\') {
					$escape = true;
					continue;
				}
				$buf .= $chr;
			} else {
				$result = match ($chr) {
					'e' => "\e",
					'f' => "\f",
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'v' => "\v",
					'0' => "\0",
					'1' => "\1",
					'2' => "\2",
					'3' => "\3",
					'4' => "\4",
					'5' => "\5",
					'6' => "\6",
					'7' => "\7",
					'\\' => "\\",
					'"' => "\"",
				};
				$buf .= $result;
				$escape = false;
			}
		}
		return $buf;
	}
	throw new RuntimeException("invalid literal $literal");
}

/**
 * @return Generator|string[]
 */
function preprocess(string $str) {
	$line = 0;
	$pos = 0;
	$ignore = false;
	$documentCounter = 0;
	for ($i = 0, $m = strlen($str); $i < $m; $i++) {
		$pos++;
		$chr = $str[$i];

		if ($chr === "\n") {
			$line++;
			$ignore = false;
			$documentCounter = 0;
			continue;
		}
		if ($ignore) {
			continue;
		}
		if ($chr === '/') {
			$documentCounter++;
			if ($documentCounter === 2) {
				$ignore = true;
				$documentCounter = 0;
			}
			continue;
		}
		yield $chr;
	}
}

function tokenize(iterable $visitor) : Generator {
	$literal = false;
	$literalBuf = '';

	$counter1 = new NestedCounter();
	$counter2 = new NestedCounter();

	foreach ($visitor as $chr) {
		if (!$literal) {
			f:
			if (nested($chr, '{', '}', $counter1)) {
				yield new Token($chr);
				continue;
			}
			if (nested($chr, '[', ']', $counter2)) {
				yield new Token($chr);
				continue;
			}
			if (str_contains(":,", $chr)) {
				yield new Token($chr);
				continue;
			}
			if (str_contains(TOKENS, $chr)) {
				continue;
			}
			if (trim($chr) !== '') {
				$literal = true;
				$literalBuf .= $chr;
			}
		} else {
			if (trim($chr) !== '') {
				$literalBuf .= $chr;
			}
			if (str_contains(TOKENS, $chr)) {
				yield new Token('literal', substr($literalBuf, 0, -1));
				$literalBuf = '';
				$literal = false;
				goto f;
			}
		}
	}
	$counter1->finalizeCheck();
	$counter2->finalizeCheck();
}

function parse(iterable $visitor) : JsonNode {
	$root = new JsonNode("_");
	$stack = new SplStack();
	$stack->push($root);

	$pendingLiteral = new SplStack();
	$resolveMapKey = new SplStack();

	/** @var Token $token */
	foreach ($visitor as $token) {
		switch ($token->type) {
			case '{':
				$node = new JsonNode("MAP", [], true);
				if (!$resolveMapKey->isEmpty()) {
					$node->autoClose = false;
					[$writeKey, $writeNode] = $resolveMapKey->pop();
					$writeNode->value[$writeKey] = $node;
				}
				$stack->push($node);
				break;
			case '[':
				$node = new JsonNode("ARRAY", [], true);
				if (!$resolveMapKey->isEmpty()) {
					$node->autoClose = false;
					[$writeKey, $writeNode] = $resolveMapKey->pop();
					$writeNode->value[$writeKey] = $node;
				}
				$stack->push($node);
				break;
			case '}':
			case ']':
				$node = $stack->pop();
				if ($node->autoClose) {
					$stack->top()->value[] = $node;
				}
				break;
			case ':':
				$head = $stack->top();

				if ($pendingLiteral->isEmpty()) {
					throw new RuntimeException("syntax error");
				}
				$writeKey = $pendingLiteral->top();
				if (!is_string($writeKey)) {
					throw new RuntimeException("cannot use " . get_debug_type($writeKey) . " value as map key");
				}

				switch ($head->type) {
					case 'MAP':
						$resolveMapKey->push([$writeKey, $head]);
						break;
					case 'ARRAY':
						$head->value[] = $writeKey;
						break;
					default:
				}
				break;
			case 'literal':
				$literal = parseLiteral($token->value);
				if ($stack->top()->type === 'ARRAY') {
					$stack->top()->value[] = $literal;
					break;
				}
				if (!$resolveMapKey->isEmpty()) {
					[$writeKey, $writeNode] = $resolveMapKey->pop();
					$writeNode->value[$writeKey] = $literal;
				} else {
					$pendingLiteral->push($literal);
				}
				break;
			case ',':
				//FIXME: WTF
				break;

		}
	}
	return $root;
}

function transform($val, array &$data) {
	if (is_array($val)) {
		foreach ($val as $v) {
			$data = [...transformValue($v), ...$data];
		}
	}
	return transformValue($val);
}

function transformValue($node) {
	if ($node instanceof JsonNode) {
		//var_dump($node);
		if ($node->type === "ARRAY") {
			return transformValue($node->value);
		}
		if ($node->type === "MAP") {
			return transformValue($node->value);
		}
		if (is_scalar($node->value)) {
			return $node->value;
		}
	}
	if (is_array($node)) {
		foreach ($node as $idx => $value) {
			$node[$idx] = transformValue($value);
		}
	}
	return $node;
}

function tiny_json_decode(string $json) {
	$result = parse(tokenize(preprocess($json)));
	$arr = [];
	transform($result->value, $arr);
	return $arr;
}

function tiny_json_decode_file(string $file) {
	return tiny_json_decode(file_get_contents($file));
}
