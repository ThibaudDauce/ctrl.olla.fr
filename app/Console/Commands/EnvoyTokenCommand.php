<?php

namespace App\Console\Commands;

use App\Support\Envoy\EnvoyClient;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class EnvoyTokenCommand extends Command
{
    protected $signature = 'app:envoy-token';

    protected $description = 'Fetch and store an Envoy JWT token';

    public function handle(): void
    {
        $host = config('services.envoy.host');
        $email = config('services.envoy.email');

        if (! $host || ! $email) {
            $this->error('ENVOY_HOST and ENVOY_EMAIL must be set in .env');

            return;
        }

        $client = new EnvoyClient($host, '');

        $this->info('Fetching serial number from Envoy...');
        $serial = $client->serialNumber();
        $this->info("Serial: {$serial}");

        $password = password("Enphase password for {$email}", required: true);

        $this->info('Fetching token...');

        $result = EnvoyClient::fetchToken($email, $password, $serial);

        $this->writeEnvValue('ENVOY_TOKEN', $result['token']);
        $this->writeEnvValue('ENVOY_TOKEN_EXPIRES_AT', $result['expires_at']);

        $this->info("Token saved. Expires at: {$result['expires_at']}");
    }

    private function writeEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');
        $content = file_get_contents($envPath);
        $quoted = '"'.addcslashes($value, '"').'"';

        if (str_contains($content, "{$key}=")) {
            $content = preg_replace("/^{$key}=.*/m", "{$key}={$quoted}", $content);
        } else {
            $content .= "\n{$key}={$quoted}";
        }

        file_put_contents($envPath, $content);
    }
}
