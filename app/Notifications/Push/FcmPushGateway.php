<?php

namespace App\Notifications\Push;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1 (PUSH_DRIVER=fcm). Authenticates with a service account
 * (FCM_CREDENTIALS = path to its JSON key file) using a signed JWT; no SDK needed.
 * A token Firebase reports as unregistered is deleted so it is not tried again.
 */
class FcmPushGateway implements PushGateway
{
    public function send(DeviceToken $device, string $title, string $body, array $data = []): bool
    {
        $credentials = $this->credentials();

        $response = Http::withToken($this->accessToken($credentials))
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                'message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                ],
            ]);

        if ($response->successful()) {
            return true;
        }

        if (in_array($response->json('error.status'), ['NOT_FOUND', 'UNREGISTERED'], true) || $response->status() === 404) {
            $device->delete();
        }

        return false;
    }

    private function credentials(): array
    {
        $path = (string) config('services.push.fcm_credentials');
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key']) || empty($json['project_id'])) {
            throw new RuntimeException('FCM credentials are missing or invalid (FCM_CREDENTIALS).');
        }

        return $json;
    }

    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm.access_token.'.md5($credentials['client_email']), 3300, function () use ($credentials) {
            $now = time();
            $segments = [
                $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64Url(json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];

            if (!openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the FCM token request.');
            }
            $segments[] = $this->base64Url($signature);

            return Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
