<?php

namespace Omnibus\Aramex\Tests;

use Omnibus\Aramex\AramexGatewayFactory;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AramexGatewayTest extends TestCase
{
    private array $calls = [];

    private ?array $tracking = null;

    private ?array $offices = null;

    private static function shipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['Sheikh Zayed Road'], '00000', 'Dubai', 'AE', phone: '+97141234567'), new Address('Alex Martin', ['King Abdullah II St'], '11118', 'Amman', 'JO', phone: '+96261234567'), [new Parcel(2000, 30, 20, 10, 5000, 'USD')], reference: 'ORDER-1042');
    }

    private function gateway(): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://ws.sbx.aramex.net/ShippingAPI.V2/', $url);
            $this->calls[] = [$url, json_decode((string) $options['body'], true)];
            $product = $this->calls[array_key_last($this->calls)][1]['ShipmentDetails']['ProductType'] ?? '';

            return match (true) {
                str_ends_with($url, '/CalculateRate') => new MockResponse(json_encode(['HasErrors' => false, 'TotalAmount' => ['CurrencyCode' => 'USD', 'Value' => 'PPX' === $product ? 48.5 : 31.0]])),
                str_ends_with($url, '/CreateShipments') => new MockResponse(json_encode(['HasErrors' => false, 'Shipments' => [['ID' => '42345678901', 'HasErrors' => false, 'ShipmentLabel' => ['LabelURL' => 'https://ws.sbx.aramex.net/content/rpt_cache/42345678901.pdf', 'LabelFileContents' => base64_encode('%PDF-1.4 aramex')]]]])),
                str_ends_with($url, '/TrackShipments') => new MockResponse(json_encode($this->tracking ?? ['HasErrors' => false, 'TrackingResults' => [['Key' => '42345678901', 'Value' => [
                    ['UpdateCode' => 'SH005', 'UpdateDescription' => 'Delivered', 'UpdateDateTime' => '/Date(1790933400000)/', 'UpdateLocation' => 'Amman, Jordan'],
                    ['UpdateCode' => 'SH014', 'UpdateDescription' => 'Record created.', 'UpdateDateTime' => '/Date(1790836000000)/', 'UpdateLocation' => 'Dubai, United Arab Emirates'],
                ]]]])),
                str_ends_with($url, '/FetchOffices') => new MockResponse(json_encode($this->offices ?? ['HasErrors' => false, 'Offices' => [['Entity' => 'AQJ', 'OfficeLocation' => 'Aqaba', 'OfficeAddress' => ['Line1' => 'Al Hammamat Al Tunisyya St', 'City' => 'Aqaba', 'CountryCode' => 'JO']], ['Entity' => 'AMM', 'OfficeLocation' => 'Amman', 'OfficeAddress' => ['Line1' => 'Mecca St', 'City' => 'Amman', 'CountryCode' => 'JO', 'Latitude' => 31.95, 'Longitude' => 35.91]]]])),
                default => new MockResponse(json_encode(['HasErrors' => true, 'Notifications' => [['Code' => 'ERR01', 'Message' => 'Unknown operation']]])),
            };
        });

        return (new AramexGatewayFactory($http))->create(['username' => 'ws@example.org', 'password' => 'pass', 'account_number' => '20016', 'account_pin' => '331421', 'account_entity' => 'DXB', 'account_country' => 'AE', 'sandbox' => true]);
    }

    public function testEachProductIsPricedCheapestFirst(): void
    {
        $rates = $this->gateway()->rate(self::shipment());
        self::assertSame(['EPX', 'PPX'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame(3100, $rates[0]->amount);
        self::assertSame('USD', $rates[0]->currency);
        self::assertSame('20016', $this->calls[0][1]['ClientInfo']['AccountNumber']);
        self::assertSame('EXP', $this->calls[0][1]['ShipmentDetails']['ProductGroup']);
        self::assertEquals(2.0, $this->calls[0][1]['ShipmentDetails']['ActualWeight']['Value']);
    }

    public function testAShipmentIsCreatedWithItsLabel(): void
    {
        $label = $this->gateway()->ship(self::shipment());
        self::assertSame('42345678901', $label->trackingNumber);
        self::assertSame('%PDF-1.4 aramex', $label->content);
        self::assertStringEndsWith('.pdf', $label->url);
        self::assertSame(9729, $this->calls[0][1]['LabelInfo']['ReportID']);
        self::assertSame('PPX', $this->calls[0][1]['Shipments'][0]['Details']['ProductType']);
        self::assertEquals(50.0, $this->calls[0][1]['Shipments'][0]['Details']['CustomsValueAmount']['Value']);
    }

    public function testTrackingAndOffices(): void
    {
        $gateway = $this->gateway();
        $tracking = $gateway->track('42345678901');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame(TrackingStatus::PENDING, $tracking->events[0]->status);
        self::assertSame('2026-10-02', $tracking->latest()->at->format('Y-m-d'));

        $points = $gateway->pickupPoints(self::shipment()->recipient);
        self::assertSame('AMM', $points[0]->id, 'the searched city first');
        self::assertSame(31.95, $points[0]->latitude);
    }

    public function testNotificationsAreRaised(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['HasErrors' => true, 'Notifications' => [['Code' => 'ERR52', 'Message' => 'Invalid account']]])));
        try {
            (new AramexGatewayFactory($http))->create(['username' => 'u', 'password' => 'p', 'account_number' => '1', 'account_pin' => '2', 'account_entity' => 'DXB', 'account_country' => 'AE'])->ship(self::shipment());
            self::fail('raised');
        } catch (CarrierException $e) {
            self::assertSame('ERR52', $e->carrierCode);
        }
    }

    /**
     * Aramex's updates: "Collected from shipper" is the pickup by Aramex (in transit), "Collected
     * by consignee" the parcel taken at an office (delivered), "held for collection" waiting at
     * one (available for pickup).
     */
    public function testCollectedFromTheShipperIsInTransit(): void
    {
        $this->tracking = ['HasErrors' => false, 'TrackingResults' => [['Key' => '42345678901', 'Value' => [
            ['UpdateCode' => '', 'UpdateDescription' => 'Collected by Consignee', 'UpdateDateTime' => '/Date(1791100000000)/', 'UpdateLocation' => 'Amman, Jordan'],
            ['UpdateCode' => '', 'UpdateDescription' => 'Held for collection at Aramex office', 'UpdateDateTime' => '/Date(1791000000000)/', 'UpdateLocation' => 'Amman, Jordan'],
            ['UpdateCode' => '', 'UpdateDescription' => 'Collected from shipper', 'UpdateDateTime' => '/Date(1790900000000)/', 'UpdateLocation' => 'Dubai, United Arab Emirates'],
        ]]]];
        $tracking = $this->gateway()->track('42345678901');
        self::assertSame([TrackingStatus::IN_TRANSIT, TrackingStatus::AVAILABLE_FOR_PICKUP, TrackingStatus::DELIVERED], array_map(static fn ($e) => $e->status, $tracking->events));
    }

    /**
     * FetchOffices answers every office of the country, in no order and without distance: the
     * searched postcode's and city's offices first, then the others nearest to them (great-circle,
     * from their coordinates); an Entity shared by several offices is told apart by the location.
     */
    public function testOfficesComeNearestFirst(): void
    {
        $office = static fn (string $entity, string $location, string $city, ?float $lat, ?float $lng, string $postcode = '') => ['Entity' => $entity, 'OfficeLocation' => $location, 'OfficeAddress' => array_filter(['Line1' => $location.' St', 'City' => $city, 'PostCode' => $postcode, 'CountryCode' => 'JO', 'Latitude' => $lat, 'Longitude' => $lng])];
        $this->offices = ['HasErrors' => false, 'Offices' => [
            $office('AQJ', 'Aqaba', 'Aqaba', 29.53, 35.00),
            $office('IRB', 'Irbid', 'Irbid', 32.55, 35.85),
            $office('AMM', 'Amman Sweifieh', 'Amman', 31.95, 35.86),
            $office('ZRQ', 'Zarqa', 'Zarqa', 32.07, 36.09),
            $office('AMM', 'Amman Abdali', 'Amman', 31.96, 35.91, '11118'),
            $office('MAD', 'Madaba', 'Madaba', null, null),
        ]];
        $points = $this->gateway()->pickupPoints(self::shipment()->recipient);
        self::assertSame(['AMM:Amman Abdali', 'AMM:Amman Sweifieh', 'ZRQ', 'IRB', 'AQJ', 'MAD'], array_map(static fn ($p) => $p->id, $points));
        self::assertNull($points[0]->distance, 'Aramex gives no distance from the address');
    }
}
