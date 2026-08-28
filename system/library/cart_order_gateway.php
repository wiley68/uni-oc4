<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CartOrderGateway implements OpenCartOrderGatewayInterface
{
    public function __construct(private OpenCartOrderMaterializer $materializer)
    {
    }

    public function materialize(
        ValidatedFinancingSubmission $submission,
        FinancingAttemptContext $attempt
    ): CreatedOpenCartOrder {
        if ($submission->entryPoint !== OperationEntryPoint::CART) {
            throw new OrderMaterializationException('Cart gateway received a non-cart submission.');
        }

        return $this->materializer->materializeNew($submission, $attempt);
    }
}
