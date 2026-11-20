<?php

namespace App\Console\Commands;

use App\Integrations\Gotenberg\GotenbergClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class CheckGotenberg extends Command
{
    protected $signature = 'gotenberg:check';

    protected $description = 'Check Render health, conversion authentication and a synthetic PDF';

    public function handle(GotenbergClient $gotenberg): int
    {
        try {
            $client = $gotenberg->connection();
            $health = (clone $client)->get('/health');
            if (! $health->ok() || $health->json('status') !== 'up' || $health->json('details.chromium.status') !== 'up') {
                $this->error('Gotenberg or Chromium health check failed (HTTP '.$health->status().').');

                return self::FAILURE;
            }
            if (! (clone $client)->post(GotenbergClient::HTML_ROUTE)->unauthorized()) {
                $this->error('The conversion route did not challenge the unauthenticated request with HTTP 401.');

                return self::FAILURE;
            }
            $gotenberg->convert('<!doctype html><title>AGOS</title><p>AGOS connection check</p>');
        } catch (ConnectionException) {
            $this->error('Gotenberg connection failed. Check the service availability and TLS connection.');

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Gotenberg and Chromium are healthy. Basic Auth is enforced and the synthetic conversion returned a PDF.');

        return self::SUCCESS;
    }
}
