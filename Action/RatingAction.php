<?php

namespace Omnibus\Aramex\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Aramex\Api;
use Omnibus\Aramex\Mapping;
use Omnibus\Model\Rate;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;

/** RateCalculator's CalculateRate, once per product (option products: the codes to price; by default PPX and EPX across borders, OND and CDS at home). */
final class RatingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Rating;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Rating);
        $s = $request->shipment;
        $international = strtoupper($s->sender->country) !== strtoupper($s->recipient->country);
        $rates = [];
        $failure = null;
        $answered = 0;
        foreach ($s->option('products', $international ? ['PPX', 'EPX'] : ['OND', 'CDS']) as $product) {
            try {
                $data = $this->api->call('RateCalculator', 'CalculateRate', [
                    'OriginAddress' => Mapping::party($s->sender)['PartyAddress'],
                    'DestinationAddress' => Mapping::party($s->recipient)['PartyAddress'],
                    'ShipmentDetails' => Mapping::details($s, $product),
                    'PreferredCurrencyCode' => $s->option('currency', 'USD'),
                ]);
            } catch (\Omnibus\Exception\CarrierException $e) {
                $failure = $e;
                continue;
            }
            ++$answered;
            $total = $data['TotalAmount'] ?? [];
            $rates[] = new Rate('aramex', $product, Mapping::PRODUCTS[$product] ?? 'Aramex '.$product, (int) round(((float) ($total['Value'] ?? 0)) * 100), strtoupper((string) ($total['CurrencyCode'] ?? 'USD')));
        }
        if (0 === $answered && null !== $failure) {
            throw $failure; // every service refused: the carrier failed, not "no rate"
        }
        usort($rates, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);
        $request->setResult($rates);
    }
}
