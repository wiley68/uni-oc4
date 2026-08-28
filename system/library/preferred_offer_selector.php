<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class PreferredOfferSelector
{
    /** @param Offer[] $offers */
    public function select(array $offers, int $preferredMonths): ?Offer
    {
        if ($offers === []) {
            return null;
        }
        $matches = $preferredMonths > 0
            ? array_values(array_filter(
                $offers,
                static fn(Offer $offer): bool => $offer->months === $preferredMonths
            ))
            : [];
        if ($matches === []) {
            $highest = max(array_map(static fn(Offer $offer): int => $offer->months, $offers));
            $matches = array_values(array_filter(
                $offers,
                static fn(Offer $offer): bool => $offer->months === $highest
            ));
        }
        usort($matches, static fn(Offer $a, Offer $b): int => $a->monthlyInstallment <=> $b->monthlyInstallment);

        return $matches[0] ?? null;
    }
}
