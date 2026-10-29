<?php

namespace Omnibus\Aramex\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Aramex\Api;
use Omnibus\Model\Address;
use Omnibus\Model\PickupPoint;
use Omnibus\Request\Pickup;
use Omnibus\Request\Request;

/** Location's FetchOffices: Aramex offices in the country, nearest city first. */
final class PickupAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Pickup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Pickup);
        $near = $request->near;
        $data = $this->api->call('Location', 'FetchOffices', ['CountryCode' => strtoupper($near->country)]);
        $points = [];
        $offices = array_values($data['Offices'] ?? []);
        $entities = array_count_values(array_map(static fn ($o) => (string) ($o['Entity'] ?? ''), $offices));
        foreach ($offices as $office) {
            $a = $office['OfficeAddress'] ?? [];
            $entity = (string) ($office['Entity'] ?? '');
            $id = '' === $entity ? (string) ($office['OfficeLocation'] ?? '') : ($entities[$entity] > 1 ? $entity.':'.($office['OfficeLocation'] ?? '') : $entity);
            $points[] = new PickupPoint('aramex', $id, (string) ($office['OfficeLocation'] ?? 'Aramex'),
                new Address((string) ($office['OfficeLocation'] ?? ''), array_values(array_filter([$a['Line1'] ?? null, $a['Line2'] ?? null])), (string) ($a['PostCode'] ?? ''), (string) ($a['City'] ?? ''), (string) ($a['CountryCode'] ?? $near->country)),
                isset($a['Latitude']) ? (float) $a['Latitude'] : null, isset($a['Longitude']) ? (float) $a['Longitude'] : null);
        }
        $request->setResult(\array_slice(self::nearestFirst($points, $near), 0, $request->limit));
    }

    /**
     * Aramex answers every office of the country, in no order and without distance, and the
     * address has no coordinates: the offices of its postcode, then of its city, first, then the
     * others by great-circle distance to those (the mean of their coordinates), then those
     * without coordinates, in Aramex's order. `distance` stays null: it would not be from the
     * address.
     *
     * @param PickupPoint[] $points
     *
     * @return PickupPoint[]
     */
    private static function nearestFirst(array $points, Address $near): array
    {
        $rank = static fn (PickupPoint $p) => '' !== $near->postcode && $p->address->postcode === $near->postcode ? 0 : (0 === strcasecmp($p->address->city, $near->city) ? 1 : 2);
        $anchors = array_filter($points, static fn (PickupPoint $p) => $rank($p) < 2 && null !== $p->latitude && null !== $p->longitude);
        $lat = $anchors ? array_sum(array_map(static fn ($p) => $p->latitude, $anchors)) / \count($anchors) : null;
        $lng = $anchors ? array_sum(array_map(static fn ($p) => $p->longitude, $anchors)) / \count($anchors) : null;
        $far = static function (PickupPoint $p) use ($lat, $lng): float {
            if (null === $lat || null === $p->latitude || null === $p->longitude) {
                return \INF;
            }
            $dLat = deg2rad($p->latitude - $lat);
            $dLng = deg2rad($p->longitude - $lng);
            $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($p->latitude)) * sin($dLng / 2) ** 2;

            return 2 * 6371000 * asin(min(1, sqrt($h)));
        };
        $keyed = [];
        foreach (array_values($points) as $i => $p) {
            $keyed[] = [$rank($p), $rank($p) < 2 ? 0 : $far($p), $i, $p];
        }
        usort($keyed, static fn ($x, $y) => [$x[0], $x[1], $x[2]] <=> [$y[0], $y[1], $y[2]]);

        return array_map(static fn ($k) => $k[3], $keyed);
    }
}
