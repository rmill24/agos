<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CheckGotenberg extends Command
{
    protected $signature = 'gotenberg:check';

    protected $description = 'Check Render health, conversion authentication and a synthetic PDF';

    public function handle(): int
    {
        $url = rtrim((string) config('services.gotenberg.base_url'), '/');
        $username = config('services.gotenberg.username');
        $password = config('services.gotenberg.password');
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || array_diff(array_keys(parse_url($url) ?: []), ['scheme', 'host', 'port'])
            || parse_url($url, PHP_URL_HOST) === 'your-gotenberg-service.onrender.com'
            || blank($username) || blank($password)) {
            $this->error('Set the service HTTPS origin and both Basic Auth credentials in the backend environment.');

            return self::FAILURE;
        }

        $client = Http::baseUrl($url)->withoutRedirecting()->timeout(config('services.gotenberg.timeout'));
        $conversion = '/forms/chromium/convert/html';
        try {
            $health = (clone $client)->get('/health');
            if (! $health->ok() || $health->json('status') !== 'up' || $health->json('details.chromium.status') !== 'up') {
                $this->error('Gotenberg or Chromium health check failed (HTTP '.$health->status().').');

                return self::FAILURE;
            }
            if (! (clone $client)->post($conversion)->unauthorized()) {
                $this->error('The conversion route did not challenge the unauthenticated request with HTTP 401.');

                return self::FAILURE;
            }
            $response = $client->withBasicAuth($username, $password)
                ->attach('files', '<!doctype html><title>AGOS</title><p>AGOS connection check</p>', 'index.html')
                ->post($conversion);
        } catch (ConnectionException) {
            $this->error('Gotenberg connection failed. Check the service availability and TLS connection.');

            return self::FAILURE;
        }
        $mime = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! $response->ok() || $mime !== 'application/pdf' || ! str_starts_with($response->body(), '%PDF-')
            || ! str_ends_with(rtrim($response->body()), '%%EOF')) {
            $this->error('Authenticated conversion did not return the expected PDF (HTTP '.$response->status().').');

            return self::FAILURE;
        }

        $this->info('Gotenberg and Chromium are healthy. Basic Auth is enforced and the synthetic conversion returned a PDF.');

        return self::SUCCESS;
    }
}
