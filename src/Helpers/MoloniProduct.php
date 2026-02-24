<?php

namespace Moloni\Helpers;

use WC_Product;
use Moloni\Curl;
use Moloni\Storage;
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
}
