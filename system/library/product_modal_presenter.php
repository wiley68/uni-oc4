<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductModalPresenter
{
    public function __construct(private ConsentResolver $consents)
    {
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    public function present(array $shop, array $customer = []): array
    {
        $bannerLink = $this->url($shop['reklama_url'] ?? '');
        if ($bannerLink === '') {
            $bannerLink = $this->url($shop['uni_backurl'] ?? '');
        }

        return [
            'banner_url'        => $this->url($shop['uni_picture'] ?? ''),
            'banner_url_mobile' => $this->url($shop['uni_picturem'] ?? ''),
            'banner_link'       => $bannerLink,
            'currency_mode'     => (int) ($shop['uni_eur'] ?? 0),
            'customer'          => array_replace([
                'firstname'  => '',
                'lastname'   => '',
                'address_1'  => '',
                'city'       => '',
                'postcode'   => '',
                'zone'       => '',
                'country'    => '',
                'telephone'  => '',
                'email'      => '',
                'is_logged'  => false,
            ], $customer),
            'consents' => $this->consents->normalize($shop),
        ];
    }

    /** @param mixed $value */
    private function url($value): string
    {
        $url = trim((string) $value);

        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : '';
    }
}
