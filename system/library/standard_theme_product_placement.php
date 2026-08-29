<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Standard OpenCart 4.1 product page placement adapter (isolated string hook).
 *
 * Must insert the financing fragment AFTER the native #form-product closes.
 * Nested &lt;form&gt; inside #form-product is invalid HTML5: browsers ignore the
 * inner form start tag, so #mt-uni-credit-product-form never exists in the DOM
 * and Product final submit fails with activeFormPresent=false.
 */
final class StandardThemeProductPlacement
{
    public const HOOK_BEFORE = '<button type="submit" id="button-cart"';

    public const HOOK_AFTER = '</div>';

    public function insertAfterAddToCartBlock(string $html, string $fragment): string
    {
        if ($fragment === '') {
            return $html;
        }

        $positionHook1 = strpos($html, self::HOOK_BEFORE);
        if ($positionHook1 === false) {
            return $html;
        }

        // Prefer after the product form closes (avoids nested financing form).
        $afterButton = substr($html, $positionHook1);
        $formCloseRel = stripos($afterButton, '</form>');
        if ($formCloseRel !== false) {
            $insertAt = $positionHook1 + $formCloseRel + strlen('</form>');

            return substr($html, 0, $insertAt) . $fragment . substr($html, $insertAt);
        }

        // Fallback: after first </div> following button-cart (incomplete fixtures).
        $suboutputAfterHook1 = substr($html, $positionHook1 + strlen(self::HOOK_BEFORE));
        $positionHook2InSuboutput = strpos($suboutputAfterHook1, self::HOOK_AFTER);
        if ($positionHook2InSuboutput === false) {
            return $html;
        }

        $positionHook2After = $positionHook1 + strlen(self::HOOK_BEFORE) + $positionHook2InSuboutput + strlen(self::HOOK_AFTER);

        return substr($html, 0, $positionHook2After) . $fragment . substr($html, $positionHook2After);
    }
}
