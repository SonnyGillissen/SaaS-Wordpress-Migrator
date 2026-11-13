<?php
declare(strict_types=1);

final class SerializedRewriter
{
    private int $position = 0;
    private int $tokens = 0;
    public function __construct(private string $input, private array $replacements, private int $nested = 0) {}

    public static function rewrite(string $value, array $replacements, int $nested = 0): string
    {
        if ($nested > 16) {
            return $value;
        }
        if (!preg_match('/^(?:[aObisdCRr]:|N;)/', $value)) {
            return strtr($value, $replacements);
        }
        $parser = new self($value, $replacements, $nested);
        try {
            $result = $parser->token(0);
            if ($parser->position !== strlen($value)) {
                return $value;
            }
            return $result;
        } catch (RuntimeException) {
            // Unknown or malformed serialization is kept intact, never unserialized.
            return $value;
        }
    }

    private function take(string $pattern): string
    {
        $pattern = substr($pattern, 0, 1) . '\\G' . substr($pattern, 2);
        if (!preg_match($pattern, $this->input, $match, 0, $this->position)) {
            throw new RuntimeException('Invalid serialized value.');
        }
        $this->position += strlen($match[0]);
        return $match[0];
    }

    private function token(int $depth): string
    {
        if ($depth > 64 || ++$this->tokens > 100000) {
            throw new RuntimeException('Serialization limit exceeded.');
        }
        $type = $this->input[$this->position] ?? '';
        if ($type === 'N') {
            return $this->take('/^N;/');
        }
        if (in_array($type, ['b', 'i', 'd', 'r', 'R'], true)) {
            return $this->take('/^[bidrR]:[+\-0-9.eEINFAN]+;/');
        }
        if ($type === 's') {
            $header = $this->take('/^s:[0-9]+:"/');
            $length = (int) substr($header, 2, -2);
            if ($length > strlen($this->input) - $this->position) {
                throw new RuntimeException('Invalid string length.');
            }
            $value = substr($this->input, $this->position, $length);
            $this->position += $length;
            $this->take('/^";/');
            $value = self::rewrite($value, $this->replacements, $this->nested + 1);
            return 's:' . strlen($value) . ':"' . $value . '";';
        }
        if ($type === 'a') {
            $header = $this->take('/^a:[0-9]+:\{/');
            $count = (int) substr($header, 2, -2);
            if ($count > 50000) {
                throw new RuntimeException('Array too large.');
            }
            $out = $header;
            for ($i = 0; $i < $count * 2; $i++) {
                $out .= $this->token($depth + 1);
            }
            return $out . $this->take('/^\}/');
        }
        if ($type === 'O' || $type === 'C') {
            $header = $this->take('/^[OC]:[0-9]+:"/');
            $length = (int) substr($header, 2, -2);
            $class = substr($this->input, $this->position, $length);
            $this->position += $length;
            $middle = $this->take('/^":[0-9]+:\{/');
            $count = (int) substr($middle, 2, -2);
            $out = $header . $class . $middle;
            if ($type === 'C') {
                // Custom Serializable payloads are opaque and must not be modified.
                $out .= substr($this->input, $this->position, $count);
                $this->position += $count;
            } else {
                if ($count > 50000) {
                    throw new RuntimeException('Object too large.');
                }
                for ($i = 0; $i < $count * 2; $i++) {
                    $out .= $this->token($depth + 1);
                }
            }
            return $out . $this->take('/^\}/');
        }
        throw new RuntimeException('Unsupported serialization.');
    }
}
