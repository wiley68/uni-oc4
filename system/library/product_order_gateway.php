<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductOrderGateway implements OpenCartOrderGatewayInterface
{
    public function __construct(private OpenCartOrderMaterializer $materializer)
    {
    }

    public function materialize(
        ValidatedFinancingSubmission $submission,
        FinancingAttemptContext $attempt
    ): CreatedOpenCartOrder {
        if ($submission->entryPoint !== OperationEntryPoint::PRODUCT) {
            throw new OrderMaterializationException('Product gateway received a non-product submission.');
        }

        return $this->materializer->materializeNew($submission, $attempt);
    }
}
