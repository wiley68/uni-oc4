<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CartResolution
{
    /** @var AvailableScheme[] */
    public array $standardSchemes;

    /** @var AvailableScheme[] */
    public array $promoSchemes;

    public ?Offer $standardOffer;

    public ?Offer $promoOffer;

    /** @param AvailableScheme[] $standardSchemes @param AvailableScheme[] $promoSchemes */
    public function __construct(
        array $standardSchemes,
        array $promoSchemes,
        ?Offer $standardOffer,
        ?Offer $promoOffer
    ) {
        $this->standardSchemes = array_values($standardSchemes);
        $this->promoSchemes = array_values($promoSchemes);
        $this->standardOffer = $standardOffer;
        $this->promoOffer = $promoOffer;
    }
}
