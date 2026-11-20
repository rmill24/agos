<?php

namespace App\Integrations\Gotenberg;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GotenbergClient
{
    public const HTML_ROUTE = '/forms/chromium/convert/html';

    public function connection(): PendingRequest
    {
        $url = rtrim((string) config('services.gotenberg.base_url'), '/');
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || array_diff(array_keys(parse_url($url) ?: []), ['scheme', 'host', 'port'])
            || parse_url($url, PHP_URL_HOST) === 'your-gotenberg-service.onrender.com'
            || blank(config('services.gotenberg.username')) || blank(config('services.gotenberg.password'))) {
            throw new RuntimeException('Set the service HTTPS origin and both Basic Auth credentials in the backend environment.');
        }

        return Http::baseUrl($url)->withoutRedirecting()->timeout(config('services.gotenberg.timeout'));
    }

    public function convert(string $html, int $remainingSeconds = 20): string
    {
        if ($remainingSeconds < 1) {
            throw new RuntimeException('The PDF job deadline has expired.');
        }
        try {
            $response = $this->connection()->timeout(min($remainingSeconds, config('services.gotenberg.timeout'), 20))
                ->withBasicAuth(config('services.gotenberg.username'), config('services.gotenberg.password'))
                ->attach('files', $html, 'index.html')->post(self::HTML_ROUTE);
        } catch (ConnectionException) {
            throw new RuntimeException('Gotenberg connection failed. Check the service availability and TLS connection.');
        }
        if (in_array($response->status(), [429, 503], true)) {
            throw new TemporaryConversionFailure($response->status(), $response->header('Retry-After'));
        }
        $mime = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! $response->ok() || $mime !== 'application/pdf' || ! str_starts_with($response->body(), '%PDF-')
            || ! str_ends_with(rtrim($response->body()), '%%EOF')) {
            throw new RuntimeException('Authenticated conversion did not return the expected PDF (HTTP '.$response->status().').');
        }

        return $response->body();
    }
}
