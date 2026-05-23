<?php

namespace Moloni\Helpers;

use WC_Product;
use Moloni\Curl;
use Moloni\Notice;
use Moloni\Storage;
use Moloni\Enums\SaftType;
use Moloni\Exceptions\APIException;

class MoloniProduct
{
    public static function parseMoloniStock(array $moloniProduct, int $warehouseId): float
    {
        $stock = 0.0;

        if ($warehouseId > 1) {
            foreach ($moloniProduct['warehouses'] as $productWarehouse) {
                if ((int)$productWarehouse['warehouse_id'] === $warehouseId) {
                    $stock = (float)$productWarehouse['stock']; // Get the stock of the particular warehouse

                    break;
                }
            }
        } else {
            $stock = (float)$moloniProduct['stock'];
        }

        return $stock;
    }

    public static function getWarehouseIdForManualDataSyncTools(): int
    {
        if (defined('MOLONI_STOCK_SYNC') && !empty(MOLONI_STOCK_SYNC)) {
            if ((int)MOLONI_STOCK_SYNC === 1) {
                return 0;
            }

            return (int)MOLONI_STOCK_SYNC;
        }

        if (defined('MOLONI_PRODUCT_WAREHOUSE')) {
            if ((int)MOLONI_PRODUCT_WAREHOUSE === 0) {
                try {
                    $defaultWarehouse = Curl::simple('warehouses/getDefaultWarehouse', []);
                } catch (APIException $e) {
                    $defaultWarehouse = [];
                }

                if (!empty($defaultWarehouse) && !empty($defaultWarehouse['warehouse_id'])) {
                    return (int)$defaultWarehouse['warehouse_id'];
                }
            }

            return (int)MOLONI_PRODUCT_WAREHOUSE;
        }

        return 0;
    }

    /**
     * Fetch the last cost price for a product from Moloni API
     *
     * @param int $productId Moloni product ID
     *
     * @return float|null Cost price or null if unavailable
     */
    public static function fetchCostPrice(int $productId): ?float
    {
        try {
            $response = Curl::simple('products/getLastCostPrice', [
                'product_id' => $productId,
            ]);
        } catch (APIException $e) {
            Storage::$LOGGER->warning(
                str_replace('{0}', (string)$productId, __('Erro ao obter preço de custo do produto ({0})')),
                [
                    'tag' => 'helper:moloniproduct:costprice',
                    'ml_id' => $productId,
                    'message' => $e->getMessage(),
                ]
            );

            return null;
        }

        if (empty($response) || !isset($response['cost_price'])) {
            return null;
        }

        return (float)$response['cost_price'];
    }

    /**
     * Set cost price on a WooCommerce product
     *
     * Uses native WC COGS if enabled, otherwise falls back to custom meta
     *
     * @param WC_Product $product WooCommerce product
     * @param float|null $costPrice Cost price value
     */
    public static function setCostPriceOnWcProduct(WC_Product $product, ?float $costPrice): void
    {
        if ($costPrice === null) {
            return;
        }

        if (self::isWcCogsEnabled()) {
            $product->set_cogs_value($costPrice);
        } else {
            $product->update_meta_data('_moloni_cost_price', $costPrice);
        }
    }

    /**
     * Check if WooCommerce native COGS feature is enabled
     *
     * @return bool
     */
    public static function isWcCogsEnabled(): bool
    {
        if (!method_exists('WC_Product', 'set_cogs_value')) {
            return false;
        }

        try {
            $featuresController = wc_get_container()->get(
                'Automattic\WooCommerce\Internal\Features\FeaturesController'
            );

            return $featuresController->feature_is_enabled('cost_of_goods_sold');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Calculate the minimum sale price based on cost price, margin and VAT
     *
     * Formula: cost_price × margin × VAT_multiplier (if WC includes tax)
     *          cost_price × margin (if WC excludes tax)
     *
     * @param float $costPrice Product cost price from Moloni
     * @param array $moloniTaxes Moloni taxes array from product data
     *
     * @return float Minimum sale price (in WC price format — with or without tax)
     */
    public static function calculateMinimumSalePrice(float $costPrice, array $moloniTaxes): float
    {
        if ($costPrice <= 0) {
            return 0.0;
        }

        $margin = 1.3;

        if (defined('MOLONI_COST_PRICE_MARGIN') && (float)MOLONI_COST_PRICE_MARGIN >= 1.0) {
            $margin = (float)MOLONI_COST_PRICE_MARGIN;
        }

        $minimumPrice = $costPrice * $margin;

        // If WooCommerce stores prices INCLUDING tax, apply VAT to the minimum
        if (wc_prices_include_tax() && !empty($moloniTaxes)) {
            $ivaRate = 0.0;

            foreach ($moloniTaxes as $tax) {
                $taxData = $tax['tax'] ?? $tax;

                // Only sum IVA taxes (saft_type = 1), not stamp duty or others
                if (isset($taxData['saft_type']) && (int)$taxData['saft_type'] === SaftType::IVA) {
                    $ivaRate += (float)($taxData['value'] ?? $tax['value'] ?? 0);
                }
            }

            if ($ivaRate > 0) {
                $minimumPrice *= (1 + $ivaRate / 100);
            }
        }

        return round($minimumPrice, 2);
    }

    /**
     * Enforce minimum sale price on a WooCommerce product
     *
     * Two behaviours, in order:
     *   1. If the product currently has no regular price (0 or empty), initialise it
     *      to the calculated minimum so the product never ends up shown as €0 after
     *      a cost-price sync.
     *   2. Otherwise, if the current price is below the minimum, lower-bound it.
     *
     * Variable products are skipped: their displayed price derives from variations,
     * not the parent's regular_price.
     *
     * @param WC_Product $product WooCommerce product
     * @param float $costPrice Cost price from Moloni
     * @param array $moloniTaxes Moloni taxes array
     * @param string $reference Product SKU/reference for logging
     *
     * @return bool True if price was adjusted, false otherwise
     */
    public static function enforceMinimumPrice(
        WC_Product $product,
        float $costPrice,
        array $moloniTaxes,
        string $reference
    ): bool {
        // Variable parents have no own price — variations carry the price.
        if (method_exists($product, 'is_type') && $product->is_type('variable')) {
            return false;
        }

        $minimumPrice = self::calculateMinimumSalePrice($costPrice, $moloniTaxes);

        if ($minimumPrice <= 0) {
            return false;
        }

        $rawCurrent = $product->get_regular_price();
        $hasNoPrice = ($rawCurrent === '' || $rawCurrent === null);
        $currentPrice = $hasNoPrice ? 0.0 : (float)$rawCurrent;

        // No price set → initialise from cost; price below minimum → raise to minimum.
        if (!$hasNoPrice && $currentPrice > 0 && $currentPrice >= $minimumPrice) {
            return false;
        }

        $product->set_regular_price((string)$minimumPrice);

        if ($hasNoPrice || $currentPrice <= 0) {
            $message = sprintf(
                __('Preço do produto %1$s definido em %2$.2f€ a partir do preço de custo (sem preço de venda anterior)'),
                $reference,
                $minimumPrice
            );

            Storage::$LOGGER->warning($message, [
                'tag' => 'helper:moloniproduct:minimumprice:init',
                'reference' => $reference,
                'wc_id' => $product->get_id(),
                'new_price' => $minimumPrice,
                'cost_price' => $costPrice,
            ]);

            if (!function_exists('wp_doing_cron') || !wp_doing_cron()) {
                Notice::addMessageWarning($message);
            }

            return true;
        }

        $message = sprintf(
            __('Preço do produto %1$s ajustado de %2$.2f€ para %3$.2f€ (abaixo do custo mínimo de venda)'),
            $reference,
            $currentPrice,
            $minimumPrice
        );

        Storage::$LOGGER->warning($message, [
            'tag' => 'helper:moloniproduct:minimumprice',
            'reference' => $reference,
            'wc_id' => $product->get_id(),
            'old_price' => $currentPrice,
            'new_price' => $minimumPrice,
            'cost_price' => $costPrice,
        ]);

        if (!function_exists('wp_doing_cron') || !wp_doing_cron()) {
            Notice::addMessageWarning($message);
        }

        return true;
    }
}
