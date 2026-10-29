<?php

namespace Omnibus\Aramex;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Aramex's web services as JSON (ws.aramex.net/.../json): every request carries the ClientInfo. */
final class Api
{
    public const LIVE = 'https://ws.aramex.net/ShippingAPI.V2';
    public const TEST = 'https://ws.sbx.aramex.net/ShippingAPI.V2';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $username,
        private readonly string $password,
        private readonly string $accountNumber,
        private readonly string $accountPin,
        private readonly string $accountEntity,
        private readonly string $accountCountry,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    /** @return array<string, mixed> */
    public function clientInfo(): array
    {
        return ['UserName' => $this->username, 'Password' => $this->password, 'Version' => 'v1', 'AccountNumber' => $this->accountNumber, 'AccountPin' => $this->accountPin, 'AccountEntity' => $this->accountEntity, 'AccountCountryCode' => strtoupper($this->accountCountry), 'Source' => 24];
    }

    /** @return array<string, mixed> */
    public function call(string $service, string $operation, array $body): array
    {
        try {
            $response = $this->http->request('POST', ($this->sandbox ? self::TEST : self::LIVE).'/'.$service.'/Service_1_0.svc/json/'.$operation, [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => json_encode(['ClientInfo' => $this->clientInfo(), 'Transaction' => ['Reference1' => 'omnibus']] + $body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('aramex', 'Aramex request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new CarrierException('aramex', sprintf('Aramex answered HTTP %d with a body that is not JSON.', $status)));
        }
        if ($status >= 400 || !empty($data['HasErrors'])) {
            $error = $data['Notifications'][0] ?? [];
            throw Redaction::withoutArguments(new CarrierException('aramex', (string) ($error['Message'] ?? sprintf('HTTP %d', $status)), isset($error['Code']) ? (string) $error['Code'] : null));
        }

        return $data;
    }

    /**
     * What a failure must not carry: the credentials, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->password, $this->accountPin, ...$more];
    }
}
