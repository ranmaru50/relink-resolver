<?php
// src/Domain/L2/WireStatement.php
// wire 文 `{payload, signature}` Envelope の strict パースと phase-1 wire 検証
// （spec resolver-l2-0.1-draft §7.1, §7.2, §7.3, §15.2 phase 1）。
// デコード前に raw wire text に対して検証する：
// - 重複メンバ名（入れ子 payload 内を含む）→ INVALID
// - payload の wire 正準性（raw payload span と JCS 正準化結果のバイト一致）→ INVALID
// - signature が payload の top-level メンバ → INVALID（入れ子の transfer.signature は可）
// - 整数フィールド（issuedAt/expiresAt/generation/recordGeneration/revokedFrom/
//   validFrom/validUntil）は 0..2^53-1 の非負整数 → INVALID
// envelope の framing whitespace（payload raw span の外側）は許容（§7.1 transport framing）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

/**
 * パース済み JSON ノード（raw wire span を保持する strict パーサの内部表現）。
 */
final class JsonValueNode
{
    /**
     * @param list<array{string, JsonValueNode}> $objectEntries
     * @param list<JsonValueNode> $arrayItems
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $start,
        public readonly int $end,
        public readonly array $objectEntries = [],
        public readonly array $arrayItems = [],
        public readonly int|float|string|bool|null $value = null,
    ) {
    }
}

final class WireStatement
{
    /** 2^53 - 1：整数フィールドの上限（§7.2）。 */
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /** @var list<string> */
    private const INTEGER_FIELDS = [
        'issuedAt',
        'expiresAt',
        'generation',
        'recordGeneration',
        'revokedFrom',
        'validFrom',
        'validUntil',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        private readonly array $payload,
        private readonly string $canonicalPayload,
        private readonly string $signatureBase64Url,
        private readonly string $signatureOctets,
    ) {
    }

    /**
     * wire text（UTF-8）を strict にパースし、phase-1 wire 検証を通す。
     * 失敗は常に WireStatementException（判定 INVALID）。
     */
    public static function parse(string $wire): self
    {
        if ($wire === '') {
            throw new WireStatementException('empty wire text');
        }
        if (!mb_check_encoding($wire, 'UTF-8')) {
            throw new WireStatementException('wire text is not valid UTF-8');
        }

        $envelope = self::parseTop($wire);
        if ($envelope->kind !== 'object') {
            throw new WireStatementException('envelope must be a JSON object');
        }
        if (count($envelope->objectEntries) !== 2) {
            throw new WireStatementException('envelope must have exactly payload and signature members');
        }

        $payloadNode = null;
        $signatureNode = null;
        foreach ($envelope->objectEntries as $entry) {
            $name = $entry[0];
            if ($name === 'payload') {
                $payloadNode = $entry[1];
            } elseif ($name === 'signature') {
                $signatureNode = $entry[1];
            } else {
                throw new WireStatementException('unexpected top-level envelope member: ' . $name);
            }
        }

        if ($payloadNode === null || $signatureNode === null) {
            throw new WireStatementException('envelope is missing payload or signature member');
        }
        if ($payloadNode->kind !== 'object') {
            throw new WireStatementException('payload must be a JSON object');
        }
        if ($signatureNode->kind !== 'string') {
            throw new WireStatementException('signature must be a JSON string');
        }

        $rawPayload = substr($wire, $payloadNode->start, $payloadNode->end - $payloadNode->start);
        $payload = self::nodeToValue($payloadNode);
        if (!is_array($payload)) {
            throw new WireStatementException('payload decoded to a non-object value');
        }

        $canonical = JcsCanonicalizer::canonicalize($payload);
        if ($canonical !== $rawPayload) {
            throw new WireStatementException('payload is not JCS canonical on the wire');
        }

        $payloadMap = [];
        foreach ($payload as $key => $value) {
            $payloadMap[(string) $key] = $value;
        }
        if (array_key_exists('signature', $payloadMap)) {
            throw new WireStatementException('payload must not contain a top-level signature member');
        }

        self::checkIntegerFields($payloadMap);

        $signatureBase64Url = (string) $signatureNode->value;
        try {
            $signatureOctets = Base64Url::decode($signatureBase64Url);
        } catch (Base64UrlException $exception) {
            throw new WireStatementException(
                'signature is not canonical base64url: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        if (strlen($signatureOctets) !== 64) {
            throw new WireStatementException('signature must decode to 64 octets (Ed25519, RFC 8032)');
        }

        return new self($payloadMap, $canonical, $signatureBase64Url, $signatureOctets);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /** 署名対象となる payload の JCS 正準オクテット（UTF-8）。 */
    public function canonicalPayloadOctets(): string
    {
        return $this->canonicalPayload;
    }

    public function signatureBase64Url(): string
    {
        return $this->signatureBase64Url;
    }

    /** 署名のデコード済み 64 オクテット。 */
    public function signatureOctets(): string
    {
        return $this->signatureOctets;
    }

    public function algorithm(): string
    {
        $algorithm = $this->payload['algorithm'] ?? null;
        if (!is_string($algorithm) || $algorithm === '') {
            throw new WireStatementException('payload algorithm member is missing or not a string');
        }

        return $algorithm;
    }

    private static function parseTop(string $wire): JsonValueNode
    {
        $node = self::parseValue($wire, self::skipWs($wire, 0));
        if (self::skipWs($wire, $node->end) !== strlen($wire)) {
            throw new WireStatementException('trailing content after top-level JSON value');
        }

        return $node;
    }

    private static function skipWs(string $wire, int $index): int
    {
        $length = strlen($wire);
        while ($index < $length) {
            $character = $wire[$index];
            if ($character !== ' ' && $character !== "\t" && $character !== "\n" && $character !== "\r") {
                break;
            }
            $index++;
        }

        return $index;
    }

    private static function parseValue(string $wire, int $index): JsonValueNode
    {
        $length = strlen($wire);
        if ($index >= $length) {
            throw new WireStatementException('unexpected end of wire text');
        }

        $character = $wire[$index];
        if ($character === '{') {
            return self::parseObject($wire, $index);
        }
        if ($character === '[') {
            return self::parseArray($wire, $index);
        }
        if ($character === '"') {
            return self::parseString($wire, $index);
        }
        if ($character === '-' || ($character >= '0' && $character <= '9')) {
            return self::parseNumber($wire, $index);
        }

        $rest = substr($wire, $index);
        if (str_starts_with($rest, 'true')) {
            return new JsonValueNode('literal', $index, $index + 4, value: true);
        }
        if (str_starts_with($rest, 'false')) {
            return new JsonValueNode('literal', $index, $index + 5, value: false);
        }
        if (str_starts_with($rest, 'null')) {
            return new JsonValueNode('literal', $index, $index + 4, value: null);
        }

        throw new WireStatementException('unexpected character in wire text');
    }

    private static function parseObject(string $wire, int $index): JsonValueNode
    {
        $length = strlen($wire);
        $start = $index;
        $index++;

        $entries = [];
        $names = [];

        $index = self::skipWs($wire, $index);
        if ($index < $length && $wire[$index] === '}') {
            return new JsonValueNode('object', $start, $index + 1);
        }

        while (true) {
            $index = self::skipWs($wire, $index);
            if ($index >= $length || $wire[$index] !== '"') {
                throw new WireStatementException('object member name must be a string');
            }

            $nameNode = self::parseString($wire, $index);
            $name = (string) $nameNode->value;
            if (in_array($name, $names, true)) {
                throw new WireStatementException('duplicate object member name in wire text: ' . $name);
            }
            $names[] = $name;

            $index = self::skipWs($wire, $nameNode->end);
            if ($index >= $length || $wire[$index] !== ':') {
                throw new WireStatementException('expected ":" after object member name');
            }

            $valueNode = self::parseValue($wire, self::skipWs($wire, $index + 1));
            $entries[] = [$name, $valueNode];

            $index = self::skipWs($wire, $valueNode->end);
            if ($index < $length && $wire[$index] === ',') {
                $index = self::skipWs($wire, $index + 1);
                continue;
            }
            if ($index < $length && $wire[$index] === '}') {
                return new JsonValueNode('object', $start, $index + 1, objectEntries: $entries);
            }

            throw new WireStatementException('expected "," or "}" in object');
        }
    }

    private static function parseArray(string $wire, int $index): JsonValueNode
    {
        $length = strlen($wire);
        $start = $index;
        $index = self::skipWs($wire, $index + 1);
        if ($index < $length && $wire[$index] === ']') {
            return new JsonValueNode('array', $start, $index + 1);
        }

        $items = [];
        while (true) {
            $valueNode = self::parseValue($wire, self::skipWs($wire, $index));
            $items[] = $valueNode;

            $index = self::skipWs($wire, $valueNode->end);
            if ($index < $length && $wire[$index] === ',') {
                $index = self::skipWs($wire, $index + 1);
                continue;
            }
            if ($index < $length && $wire[$index] === ']') {
                return new JsonValueNode('array', $start, $index + 1, arrayItems: $items);
            }

            throw new WireStatementException('expected "," or "]" in array');
        }
    }

    /**
     * JSON 文字列を strict に読む。raw C0 制御バイト（<0x20）と
     * 孤立サロゲートは拒否。UTF-16 サロゲートペアは code point に結合する。
     */
    private static function parseString(string $wire, int $index): JsonValueNode
    {
        $length = strlen($wire);
        $start = $index;
        $index++;
        $decoded = '';

        while (true) {
            if ($index >= $length) {
                throw new WireStatementException('unterminated string in wire text');
            }

            $character = $wire[$index];
            if ($character === '"') {
                return new JsonValueNode('string', $start, $index + 1, value: $decoded);
            }

            if ($character === '\\') {
                $index++;
                if ($index >= $length) {
                    throw new WireStatementException('unterminated escape in wire string');
                }
                $escape = $wire[$index];
                if ($escape === '"') {
                    $decoded .= '"';
                    $index++;
                    continue;
                }
                if ($escape === '\\') {
                    $decoded .= '\\';
                    $index++;
                    continue;
                }
                if ($escape === '/') {
                    $decoded .= '/';
                    $index++;
                    continue;
                }
                if ($escape === 'b') {
                    $decoded .= "\x08";
                    $index++;
                    continue;
                }
                if ($escape === 'f') {
                    $decoded .= "\x0C";
                    $index++;
                    continue;
                }
                if ($escape === 'n') {
                    $decoded .= "\n";
                    $index++;
                    continue;
                }
                if ($escape === 'r') {
                    $decoded .= "\r";
                    $index++;
                    continue;
                }
                if ($escape === 't') {
                    $decoded .= "\t";
                    $index++;
                    continue;
                }
                if ($escape === 'u') {
                    $hex = substr($wire, $index + 1, 4);
                    if (strlen($hex) !== 4 || !ctype_xdigit($hex)) {
                        throw new WireStatementException('invalid \\u escape in wire string');
                    }
                    $code = (int) hexdec($hex);
                    $index += 5;

                    if ($code >= 0xD800 && $code <= 0xDBFF) {
                        if ($index + 6 > $length || $wire[$index] !== '\\' || $wire[$index + 1] !== 'u') {
                            throw new WireStatementException('lone high surrogate in wire string');
                        }
                        $lowHex = substr($wire, $index + 2, 4);
                        if (strlen($lowHex) !== 4 || !ctype_xdigit($lowHex)) {
                            throw new WireStatementException('invalid surrogate escape in wire string');
                        }
                        $low = (int) hexdec($lowHex);
                        if ($low < 0xDC00 || $low > 0xDFFF) {
                            throw new WireStatementException('high surrogate not followed by low surrogate');
                        }
                        $combined = 0x10000 + ($code - 0xD800) * 0x400 + ($low - 0xDC00);
                        // 0x10000..0x10FFFF の有効 code point なので mb_chr は成功する
                        $characterText = mb_chr($combined, 'UTF-8');
                        $decoded .= $characterText;
                        $index += 6;
                        continue;
                    }
                    if ($code >= 0xDC00 && $code <= 0xDFFF) {
                        throw new WireStatementException('lone low surrogate in wire string');
                    }
                    $characterText = mb_chr($code, 'UTF-8');
                    $decoded .= $characterText;
                    continue;
                }

                throw new WireStatementException('invalid escape character in wire string');
            }

            if (ord($character) < 0x20) {
                throw new WireStatementException('raw control byte in wire string');
            }

            $decoded .= $character;
            $index++;
        }
    }

    private static function parseNumber(string $wire, int $index): JsonValueNode
    {
        $token = '';
        if (preg_match('/\A(?:-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/', substr($wire, $index), $matches) === 1) {
            $token = $matches[0];
        }
        if ($token === '') {
            throw new WireStatementException('invalid number in wire text');
        }

        $value = self::numberValue($token);

        return new JsonValueNode('number', $index, $index + strlen($token), value: $value);
    }

    private static function numberValue(string $token): int|float
    {
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $token) === 1) {
            $integer = filter_var($token, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                return $integer;
            }
        }

        return (float) $token;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function checkIntegerFields(array $value): void
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                /** @var array<array-key, mixed> $child */
                self::checkIntegerFields($child);
                continue;
            }

            if (in_array($key, self::INTEGER_FIELDS, true)) {
                if (!is_int($child)) {
                    throw new WireStatementException('integer field is not a wire integer: ' . $key);
                }
                if ($child < 0 || $child > self::MAX_SAFE_INTEGER) {
                    throw new WireStatementException('integer field out of range 0..2^53-1: ' . $key);
                }
            }
        }
    }

    /**
     * @return mixed
     */
    private static function nodeToValue(JsonValueNode $node): mixed
    {
        if ($node->kind === 'object') {
            $map = [];
            foreach ($node->objectEntries as $entry) {
                $map[$entry[0]] = self::nodeToValue($entry[1]);
            }

            return $map;
        }
        if ($node->kind === 'array') {
            $items = [];
            foreach ($node->arrayItems as $item) {
                $items[] = self::nodeToValue($item);
            }

            return $items;
        }

        return $node->value;
    }
}
