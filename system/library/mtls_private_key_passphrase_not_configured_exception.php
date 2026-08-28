<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Thrown when mTLS private-key passphrase is required but not configured.
 */
final class MtlsPrivateKeyPassphraseNotConfiguredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'mTLS private-key passphrase is not configured ('
                . MtlsPrivateKeyPassphraseProvider::RELATIVE_PATH
                . ').'
        );
    }
}
