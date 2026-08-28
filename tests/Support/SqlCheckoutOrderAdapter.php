<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\CheckoutOrderModelPort;
use Opencart\System\Library\Extension\MtUniCredit\DbConnection;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;

/**
 * SQL-backed checkout order port mirroring OpenCart 4.1.0.3 addOrder semantics.
 * Uses production order tables with module recovery markers for safe cleanup.
 */
final class SqlCheckoutOrderAdapter implements CheckoutOrderModelPort
{
    public function __construct(private DbConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $orderData
     */
    public function addOrder(array $orderData): int
    {
        $prefix = $this->db->getPrefix();
        $paymentMethod = $orderData['payment_method'] ?? PaymentIdentity::paymentMethod();
        $shippingMethod = $orderData['shipping_method'] ?? ['name' => '', 'code' => ''];

        $this->db->query(
            "INSERT INTO `{$prefix}order` SET
                `subscription_id` = 0,
                `invoice_prefix` = '" . $this->db->escape((string) ($orderData['invoice_prefix'] ?? '')) . "',
                `store_id` = " . (int) ($orderData['store_id'] ?? 0) . ",
                `store_name` = '" . $this->db->escape((string) ($orderData['store_name'] ?? '')) . "',
                `store_url` = '" . $this->db->escape((string) ($orderData['store_url'] ?? '')) . "',
                `customer_id` = " . (int) ($orderData['customer_id'] ?? 0) . ",
                `customer_group_id` = " . (int) ($orderData['customer_group_id'] ?? 0) . ",
                `firstname` = '" . $this->db->escape((string) ($orderData['firstname'] ?? '')) . "',
                `lastname` = '" . $this->db->escape((string) ($orderData['lastname'] ?? '')) . "',
                `email` = '" . $this->db->escape((string) ($orderData['email'] ?? '')) . "',
                `telephone` = '" . $this->db->escape((string) ($orderData['telephone'] ?? '')) . "',
                `custom_field` = '" . $this->db->escape(json_encode($orderData['custom_field'] ?? [])) . "',
                `payment_address_id` = " . (int) ($orderData['payment_address_id'] ?? 0) . ",
                `payment_firstname` = '" . $this->db->escape((string) ($orderData['payment_firstname'] ?? '')) . "',
                `payment_lastname` = '" . $this->db->escape((string) ($orderData['payment_lastname'] ?? '')) . "',
                `payment_company` = '" . $this->db->escape((string) ($orderData['payment_company'] ?? '')) . "',
                `payment_address_1` = '" . $this->db->escape((string) ($orderData['payment_address_1'] ?? '')) . "',
                `payment_address_2` = '" . $this->db->escape((string) ($orderData['payment_address_2'] ?? '')) . "',
                `payment_city` = '" . $this->db->escape((string) ($orderData['payment_city'] ?? '')) . "',
                `payment_postcode` = '" . $this->db->escape((string) ($orderData['payment_postcode'] ?? '')) . "',
                `payment_country` = '" . $this->db->escape((string) ($orderData['payment_country'] ?? '')) . "',
                `payment_country_id` = " . (int) ($orderData['payment_country_id'] ?? 0) . ",
                `payment_zone` = '" . $this->db->escape((string) ($orderData['payment_zone'] ?? '')) . "',
                `payment_zone_id` = " . (int) ($orderData['payment_zone_id'] ?? 0) . ",
                `payment_address_format` = '" . $this->db->escape((string) ($orderData['payment_address_format'] ?? '')) . "',
                `payment_custom_field` = '" . $this->db->escape(json_encode($orderData['payment_custom_field'] ?? [])) . "',
                `payment_method` = '" . $this->db->escape(json_encode($paymentMethod)) . "',
                `shipping_address_id` = " . (int) ($orderData['shipping_address_id'] ?? 0) . ",
                `shipping_firstname` = '" . $this->db->escape((string) ($orderData['shipping_firstname'] ?? '')) . "',
                `shipping_lastname` = '" . $this->db->escape((string) ($orderData['shipping_lastname'] ?? '')) . "',
                `shipping_company` = '" . $this->db->escape((string) ($orderData['shipping_company'] ?? '')) . "',
                `shipping_address_1` = '" . $this->db->escape((string) ($orderData['shipping_address_1'] ?? '')) . "',
                `shipping_address_2` = '" . $this->db->escape((string) ($orderData['shipping_address_2'] ?? '')) . "',
                `shipping_city` = '" . $this->db->escape((string) ($orderData['shipping_city'] ?? '')) . "',
                `shipping_postcode` = '" . $this->db->escape((string) ($orderData['shipping_postcode'] ?? '')) . "',
                `shipping_country` = '" . $this->db->escape((string) ($orderData['shipping_country'] ?? '')) . "',
                `shipping_country_id` = " . (int) ($orderData['shipping_country_id'] ?? 0) . ",
                `shipping_zone` = '" . $this->db->escape((string) ($orderData['shipping_zone'] ?? '')) . "',
                `shipping_zone_id` = " . (int) ($orderData['shipping_zone_id'] ?? 0) . ",
                `shipping_address_format` = '" . $this->db->escape((string) ($orderData['shipping_address_format'] ?? '')) . "',
                `shipping_custom_field` = '" . $this->db->escape(json_encode($orderData['shipping_custom_field'] ?? [])) . "',
                `shipping_method` = '" . $this->db->escape(json_encode($shippingMethod)) . "',
                `comment` = '" . $this->db->escape((string) ($orderData['comment'] ?? '')) . "',
                `total` = " . (float) ($orderData['total'] ?? 0.0) . ",
                `affiliate_id` = 0,
                `commission` = 0,
                `marketing_id` = 0,
                `tracking` = '" . $this->db->escape((string) ($orderData['tracking'] ?? '')) . "',
                `language_id` = " . (int) ($orderData['language_id'] ?? 1) . ",
                `language_code` = '" . $this->db->escape((string) ($orderData['language_code'] ?? 'en-gb')) . "',
                `currency_id` = " . (int) ($orderData['currency_id'] ?? 0) . ",
                `currency_code` = '" . $this->db->escape((string) ($orderData['currency_code'] ?? 'BGN')) . "',
                `currency_value` = " . (float) ($orderData['currency_value'] ?? 1.0) . ",
                `ip` = '" . $this->db->escape((string) ($orderData['ip'] ?? '')) . "',
                `forwarded_ip` = '" . $this->db->escape((string) ($orderData['forwarded_ip'] ?? '')) . "',
                `user_agent` = '" . $this->db->escape((string) ($orderData['user_agent'] ?? '')) . "',
                `accept_language` = '" . $this->db->escape((string) ($orderData['accept_language'] ?? '')) . "',
                `date_added` = NOW(),
                `date_modified` = NOW()"
        );

        $orderId = $this->db->getLastId();

        foreach ($orderData['products'] ?? [] as $product) {
            $this->db->query(
                "INSERT INTO `{$prefix}order_product` SET
                    `order_id` = " . (int) $orderId . ",
                    `product_id` = " . (int) ($product['product_id'] ?? 0) . ",
                    `master_id` = " . (int) ($product['master_id'] ?? 0) . ",
                    `name` = '" . $this->db->escape((string) ($product['name'] ?? '')) . "',
                    `model` = '" . $this->db->escape((string) ($product['model'] ?? '')) . "',
                    `quantity` = " . (int) ($product['quantity'] ?? 0) . ",
                    `price` = " . (float) ($product['price'] ?? 0.0) . ",
                    `total` = " . (float) ($product['total'] ?? 0.0) . ",
                    `tax` = " . (float) ($product['tax'] ?? 0.0) . ",
                    `reward` = " . (int) ($product['reward'] ?? 0)
            );
            $orderProductId = $this->db->getLastId();
            foreach ($product['option'] ?? [] as $option) {
                $this->db->query(
                    "INSERT INTO `{$prefix}order_option` SET
                        `order_id` = " . (int) $orderId . ",
                        `order_product_id` = " . (int) $orderProductId . ",
                        `product_option_id` = " . (int) ($option['product_option_id'] ?? 0) . ",
                        `product_option_value_id` = " . (int) ($option['product_option_value_id'] ?? 0) . ",
                        `name` = '" . $this->db->escape((string) ($option['name'] ?? '')) . "',
                        `value` = '" . $this->db->escape((string) ($option['value'] ?? '')) . "',
                        `type` = '" . $this->db->escape((string) ($option['type'] ?? '')) . "'"
                );
            }
        }

        foreach ($orderData['totals'] ?? [] as $total) {
            $this->db->query(
                "INSERT INTO `{$prefix}order_total` SET
                    `order_id` = " . (int) $orderId . ",
                    `extension` = '" . $this->db->escape((string) ($total['extension'] ?? '')) . "',
                    `code` = '" . $this->db->escape((string) ($total['code'] ?? '')) . "',
                    `title` = '" . $this->db->escape((string) ($total['title'] ?? '')) . "',
                    `value` = " . (float) ($total['value'] ?? 0.0) . ",
                    `sort_order` = " . (int) ($total['sort_order'] ?? 0)
            );
        }

        return (int) $orderId;
    }

    public function getOrder(int $orderId): array
    {
        $prefix = $this->db->getPrefix();
        $result = $this->db->query(
            "SELECT * FROM `{$prefix}order` WHERE `order_id` = " . (int) $orderId . " LIMIT 1"
        );
        if (!is_object($result) || $result->num_rows !== 1) {
            return [];
        }

        $row = $result->row;
        $row['payment_method'] = $row['payment_method'] ? json_decode((string) $row['payment_method'], true) : [];

        return $row;
    }

    public function getProducts(int $orderId): array
    {
        $prefix = $this->db->getPrefix();
        $result = $this->db->query(
            "SELECT * FROM `{$prefix}order_product` WHERE `order_id` = " . (int) $orderId
        );

        return (is_object($result) && isset($result->rows)) ? $result->rows : [];
    }

    public function getTotals(int $orderId): array
    {
        $prefix = $this->db->getPrefix();
        $result = $this->db->query(
            "SELECT * FROM `{$prefix}order_total` WHERE `order_id` = " . (int) $orderId . " ORDER BY `sort_order` ASC"
        );

        return (is_object($result) && isset($result->rows)) ? $result->rows : [];
    }

    public function getProductOptions(int $orderId, int $orderProductId): array
    {
        $prefix = $this->db->getPrefix();
        $result = $this->db->query(
            "SELECT * FROM `{$prefix}order_option`
             WHERE `order_id` = " . (int) $orderId . "
               AND `order_product_id` = " . (int) $orderProductId
        );

        return (is_object($result) && isset($result->rows)) ? $result->rows : [];
    }

    public function addHistory(int $orderId, int $orderStatusId, string $comment = '', bool $notify = false): void
    {
        $prefix = $this->db->getPrefix();
        $this->db->query(
            "UPDATE `{$prefix}order`
             SET `order_status_id` = " . (int) $orderStatusId . ",
                 `date_modified` = NOW()
             WHERE `order_id` = " . (int) $orderId
        );
        $this->db->query(
            "INSERT INTO `{$prefix}order_history` SET
                `order_id` = " . (int) $orderId . ",
                `order_status_id` = " . (int) $orderStatusId . ",
                `notify` = " . ($notify ? 1 : 0) . ",
                `comment` = '" . $this->db->escape($comment) . "',
                `date_added` = NOW()"
        );
    }

    public function findOrderIdByRecoveryMarker(int $storeId, string $trackingMarker): ?int
    {
        $prefix = $this->db->getPrefix();
        $result = $this->db->query(
            "SELECT `order_id` FROM `{$prefix}order`
             WHERE `store_id` = " . (int) $storeId . "
               AND `tracking` = '" . $this->db->escape($trackingMarker) . "'
             LIMIT 1"
        );
        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        return (int) ($result->row['order_id'] ?? 0);
    }

    public function deleteTestOrdersByTrackingPrefix(string $prefixMarker = 'mtuc:'): void
    {
        $prefix = $this->db->getPrefix();
        $result = $this->db->query(
            "SELECT `order_id` FROM `{$prefix}order` WHERE `tracking` LIKE '" . $this->db->escape($prefixMarker) . "%'"
        );
        if (!is_object($result) || !isset($result->rows)) {
            return;
        }
        foreach ($result->rows as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $this->db->query("DELETE FROM `{$prefix}order_option` WHERE `order_id` = {$orderId}");
            $this->db->query("DELETE FROM `{$prefix}order_product` WHERE `order_id` = {$orderId}");
            $this->db->query("DELETE FROM `{$prefix}order_total` WHERE `order_id` = {$orderId}");
            $this->db->query("DELETE FROM `{$prefix}order_history` WHERE `order_id` = {$orderId}");
            $this->db->query("DELETE FROM `{$prefix}order` WHERE `order_id` = {$orderId}");
        }
    }
}
