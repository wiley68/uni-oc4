<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Standard OpenCart 4.1 cart page placement — outside #shopping-cart so AJAX list reloads keep the fragment. */
final class StandardThemeCartPlacement
{
    public function insertAfterShoppingCart(string $html, string $fragment): string
    {
        if ($fragment === '') {
            return $html;
        }

        $marker = '<div id="shopping-cart">';
        $pos = strpos($html, $marker);
        if ($pos === false) {
            return $this->insertBeforeCheckoutButton($html, $fragment);
        }

        $depth = 0;
        $i = $pos;
        $len = strlen($html);
        while ($i < $len) {
            if (preg_match('/\G<div\b/i', $html, $m, 0, $i) === 1) {
                $depth++;
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G<\/div>/i', $html, $m, 0, $i) === 1) {
                $depth--;
                $i += strlen($m[0]);
                if ($depth === 0) {
                    return substr($html, 0, $i) . $fragment . substr($html, $i);
                }
                continue;
            }
            $i++;
        }

        return $this->insertBeforeCheckoutButton($html, $fragment);
    }

    private function insertBeforeCheckoutButton(string $html, string $fragment): string
    {
        $needles = [
            'href="index.php?route=checkout/checkout',
            "href='index.php?route=checkout/checkout",
            'route=checkout/checkout',
        ];
        foreach ($needles as $needle) {
            $pos = strpos($html, $needle);
            if ($pos === false) {
                continue;
            }
            $open = strrpos(substr($html, 0, $pos), '<a ');
            if ($open === false) {
                $open = strrpos(substr($html, 0, $pos), '<button');
            }
            if ($open !== false) {
                return substr($html, 0, $open) . $fragment . substr($html, $open);
            }
        }

        return $html . $fragment;
    }
}
