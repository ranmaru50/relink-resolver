<?php
// src/Adapters/NativeAdministrativeResourceFetcher.php
// 標準 PHP stream だけで bounded・HTTPS-only outbound fetch を行う管理アダプタ。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use Relink\Resolver\Application\OutboundNetworkPolicy;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\FetchedRepresentation;
use Relink\Resolver\Ports\AdministrativeResourceFetcher;
use RuntimeException;

final class NativeAdministrativeResourceFetcher implements AdministrativeResourceFetcher
{
    public function __construct(
        private readonly OutboundNetworkPolicy $networkPolicy,
        private readonly int $maxRedirects = 5,
        private readonly int $maxBodyBytes = 8_388_608,
        private readonly float $connectTimeout = 5.0,
        private readonly float $readTimeout = 10.0,
    ) {
        if ($this->maxRedirects < 0 || $this->maxBodyBytes < 1 || $this->connectTimeout <= 0 || $this->readTimeout <= 0) {
            throw new \InvalidArgumentException('Outbound fetch limits are invalid');
        }
    }

    public function fetch(DescriptionLocation $location): FetchedRepresentation
    {
        $url = $location->value;
        $redirects = [];
        for ($redirect = 0; $redirect <= $this->maxRedirects; $redirect++) {
            $parts = $this->urlParts($url);
            $addresses = $this->resolveAddresses($parts['host']);
            $socket = $this->connect($parts, $this->networkPolicy->allowedAddresses($addresses));
            try {
                [$status, $headers, $body] = $this->request($socket, $parts);
            } finally {
                fclose($socket);
            }
            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                return new FetchedRepresentation($url, $status, $body, $redirects);
            }
            $next = $headers['location'] ?? null;
            if ($next === null || $next === '') {
                throw new RuntimeException('OUTBOUND_REDIRECT_INVALID');
            }
            if ($redirect === $this->maxRedirects) {
                throw new RuntimeException('OUTBOUND_REDIRECT_LIMIT');
            }
            $redirects[] = $url;
            $url = $this->resolveRedirect($url, $next);
            if (!str_starts_with(strtolower($url), 'https://')) {
                throw new RuntimeException('OUTBOUND_HTTPS_DOWNGRADE');
            }
        }
        throw new RuntimeException('OUTBOUND_REDIRECT_LIMIT');
    }

    /** @return array{host:string,port:int,path:string} */
    private function urlParts(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user'], $parts['pass']) || !isset($parts['host'])) {
            throw new RuntimeException('OUTBOUND_HTTPS_REQUIRED');
        }
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1) {
            throw new RuntimeException('OUTBOUND_URL_INVALID');
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        return ['host' => $host, 'port' => $port, 'path' => $path];
    }

    /** @return list<string> */
    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('OUTBOUND_DNS_FAILED');
        }
        return array_values(array_unique($addresses));
    }

    /**
     * @param array{host:string,port:int,path:string} $parts
     * @param list<string> $addresses
     */
    private function connect(array $parts, array $addresses): mixed
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $parts['host'],
            'SNI_enabled' => true,
            'disable_compression' => false,
        ]]);
        foreach ($addresses as $address) {
            $host = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $socket = @stream_socket_client('ssl://' . $host . ':' . $parts['port'], $errno, $error, $this->connectTimeout, STREAM_CLIENT_CONNECT, $context);
            if (is_resource($socket)) {
                stream_set_timeout($socket, (int) $this->readTimeout, (int) (($this->readTimeout - (int) $this->readTimeout) * 1_000_000));
                return $socket;
            }
        }
        throw new RuntimeException('OUTBOUND_CONNECT_FAILED');
    }

    /**
     * @param array{host:string,port:int,path:string} $parts
     * @return array{0:int,1:array<string,string>,2:string}
     */
    private function request(mixed $socket, array $parts): array
    {
        $request = "GET {$parts['path']} HTTP/1.1\r\nHost: {$parts['host']}\r\nAccept: */*\r\nAccept-Encoding: gzip, deflate\r\nConnection: close\r\n\r\n";
        $written = 0;
        while ($written < strlen($request)) {
            $count = @fwrite($socket, substr($request, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('OUTBOUND_WRITE_FAILED');
            }
            $written += $count;
        }
        $headerBlock = '';
        while (!str_contains($headerBlock, "\r\n\r\n")) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($socket)['timed_out']) {
                    throw new RuntimeException('OUTBOUND_READ_TIMEOUT');
                }
                throw new RuntimeException('OUTBOUND_RESPONSE_FAILED');
            }
            $headerBlock .= $chunk;
            if (strlen($headerBlock) > 65536) {
                throw new RuntimeException('OUTBOUND_HEADERS_TOO_LARGE');
            }
        }
        [$rawHeaders, $body] = explode("\r\n\r\n", $headerBlock, 2);
        $lines = explode("\r\n", $rawHeaders);
        if (!preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s|$)/', trim($lines[0]), $match)) {
            throw new RuntimeException('OUTBOUND_RESPONSE_INVALID');
        }
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
        }
        $body .= $this->readBody($socket, $headers);
        if (isset($headers['transfer-encoding']) && str_contains(strtolower($headers['transfer-encoding']), 'chunked')) {
            $body = $this->decodeChunked($body);
        }
        $body = $this->decodeContentEncoding($body, $headers['content-encoding'] ?? null);
        if (strlen($body) > $this->maxBodyBytes) {
            throw new RuntimeException('OUTBOUND_BODY_TOO_LARGE');
        }
        return [(int) $match[1], $headers, $body];
    }

    /** @param array<string,string> $headers */
    private function readBody(mixed $socket, array $headers): string
    {
        $body = '';
        $remaining = isset($headers['content-length']) && ctype_digit($headers['content-length']) ? (int) $headers['content-length'] - strlen($body) : null;
        while ($remaining === null || $remaining > 0) {
            $size = $remaining === null ? 8192 : min(8192, $remaining);
            $chunk = fread($socket, $size);
            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($socket)['timed_out']) {
                    throw new RuntimeException('OUTBOUND_READ_TIMEOUT');
                }
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $this->maxBodyBytes * 2) {
                throw new RuntimeException('OUTBOUND_BODY_TOO_LARGE');
            }
            if ($remaining !== null) {
                $remaining -= strlen($chunk);
            }
        }
        return $body;
    }

    private function decodeChunked(string $body): string
    {
        $decoded = '';
        $offset = 0;
        while (true) {
            $end = strpos($body, "\r\n", $offset);
            if ($end === false) {
                throw new RuntimeException('OUTBOUND_CHUNK_INVALID');
            }
            $size = hexdec(trim(substr($body, $offset, $end - $offset)));
            $offset = $end + 2;
            if ($size === 0) {
                return $decoded;
            }
            if ($size < 0 || $size > $this->maxBodyBytes || strlen($body) < $offset + $size + 2) {
                throw new RuntimeException('OUTBOUND_CHUNK_INVALID');
            }
            $decoded .= substr($body, $offset, $size);
            if (strlen($decoded) > $this->maxBodyBytes) {
                throw new RuntimeException('OUTBOUND_BODY_TOO_LARGE');
            }
            $offset += $size + 2;
        }
    }

    private function decodeContentEncoding(string $body, ?string $encoding): string
    {
        foreach (array_reverse(array_map('trim', explode(',', strtolower((string) $encoding)))) as $value) {
            if ($value === '' || $value === 'identity') {
                continue;
            }
            $body = match ($value) {
                'gzip' => gzdecode($body),
                'deflate' => zlib_decode($body),
                default => throw new RuntimeException('OUTBOUND_ENCODING_UNSUPPORTED'),
            };
            if ($body === false) {
                throw new RuntimeException('OUTBOUND_ENCODING_INVALID');
            }
        }
        return $body;
    }

    private function resolveRedirect(string $current, string $next): string
    {
        if (parse_url($next, PHP_URL_SCHEME) !== null) {
            return $next;
        }
        $parts = parse_url($current);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('OUTBOUND_REDIRECT_INVALID');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return str_starts_with($next, '/') ? $origin . $next : $origin . '/' . $next;
    }
}
