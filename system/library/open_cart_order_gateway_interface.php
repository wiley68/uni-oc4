<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

interface OpenCartOrderGatewayInterface
{
    public function materialize(
        ValidatedFinancingSubmission $submission,
        FinancingAttemptContext $attempt
    ): CreatedOpenCartOrder;
}
