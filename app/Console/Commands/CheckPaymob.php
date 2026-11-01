<?php

namespace App\Console\Commands;

use App\Modules\Payments\Gateways\PaymobGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Reports whether Paymob is configured for the selected environment, without printing secrets.
 * With --ping, also checks that the API key authenticates (no payment is created).
 */
class CheckPaymob extends Command
{
    protected $signature = 'payments:paymob-check {--ping : Authenticate against Paymob with the API key}';

    protected $description = 'Check the Paymob configuration (never prints credentials)';

    public function handle(PaymobGateway $gateway): int
    {
        $this->line('Environment: '.$gateway->environment().' · flow: '.$gateway->flow().' · base URL: '.config('services.paymob.base_url'));
        $this->line('Webhook URL to register in Paymob: '.route('payments.webhook', ['provider' => 'paymob']));
        $this->line('Return URL: '.route('payments.return', ['provider' => 'paymob']));

        if ($missing = $gateway->missingConfiguration()) {
            $this->error('Missing: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->info('All required settings are present.');

        if ($this->option('ping')) {
            $key = config('services.paymob.'.$gateway->environment().'.api_key');
            try {
                $response = Http::baseUrl(rtrim((string) config('services.paymob.base_url'), '/'))->acceptJson()->timeout(15)
                    ->post('/api/auth/tokens', ['api_key' => $key]);
            } catch (\Throwable) {
                $this->error('Paymob could not be reached.');

                return self::FAILURE;
            }

            if (!$response->successful() || blank($response->json('token'))) {
                $this->error("Authentication failed (HTTP {$response->status()}).");

                return self::FAILURE;
            }

            $this->info('Authentication succeeded.');
        }

        return self::SUCCESS;
    }
}
