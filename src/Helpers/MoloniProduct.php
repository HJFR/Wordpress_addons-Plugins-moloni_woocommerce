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
    /**
     * Number of configurable discount→margin tier slots exposed in Settings.
     * Each slot N is read from the constants MOLONI_MARGIN_TIER_N_DISCOUNT and
     * MOLONI_MARGIN_TIER_N_MARGIN (defined from the saved options).
     */
    const MARGIN_TIER_SLOTS = 6;

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
     * Extract the supplier cost-discount info from an already-loaded Moloni
     * product array (products/getOne response). Each supplier entry carries:
     *   cost_price            → list / gross supplier cost
     *   comercial_discount    → commercial discount %
     *   financial_discount    → financial discount %
     *   cost_price_discounted → net cost after both discounts (authoritative)
     *
     * The effective discount % is derived from gross-vs-net (which already
     * captures commercial + financial as Moloni compounds them); it falls back
     * to the sum of the two percentages if the discounted figure is missing.
     *
     * @param array $moloniProduct products/getOne product array
     *
     * @return array{has_discount:bool,discount_pct:float,comercial_discount:float,financial_discount:float,cost_gross:float,cost_net:float,supplier_id:int}
     */
    public static function extractSupplierDiscount(array $moloniProduct): array
    {
        $empty = [
            'has_discount'       => false,
            'discount_pct'       => 0.0,
            'comercial_discount' => 0.0,
            'financial_discount' => 0.0,
            'cost_gross'         => 0.0,
            'cost_net'           => 0.0,
            'supplier_id'        => 0,
        ];

        if (empty($moloniProduct['suppliers']) || !is_array($moloniProduct['suppliers'])) {
            return $empty;
        }

        // Use the first supplier that carries a usable gross cost price.
        $supplier = null;
        foreach ($moloniProduct['suppliers'] as $candidate) {
            if (is_array($candidate) && isset($candidate['cost_price']) && (float)$candidate['cost_price'] > 0) {
                $supplier = $candidate;
                break;
            }
        }

        if ($supplier === null) {
            return $empty;
        }

        $gross       = (float)($supplier['cost_price'] ?? 0);
        $netProvided = isset($supplier['cost_price_discounted']);
        $net         = $netProvided ? (float)$supplier['cost_price_discounted'] : $gross;
        $comercial   = (float)($supplier['comercial_discount'] ?? 0);
        $financial   = (float)($supplier['financial_discount'] ?? 0);

        // Effective discount %, in priority order:
        //  1. gross-vs-net when Moloni gives a sane discounted cost — authoritative,
        //     already reflects commercial + financial compounded;
        //  2. otherwise compound the two percentages (Moloni applies them in
        //     sequence, NOT additively: 1 − (1−c)(1−f));
        //  3. corrupt data (net > gross) or no signal → 0, i.e. use the base margin.
        $discountPct = 0.0;
        if ($gross > 0) {
            if ($netProvided && $net >= 0 && $net <= $gross) {
                $discountPct = (1 - ($net / $gross)) * 100;
            } elseif (!$netProvided && ($comercial > 0 || $financial > 0)) {
                $discountPct = (1 - (1 - $comercial / 100) * (1 - $financial / 100)) * 100;
            }
        }
        $discountPct = max(0.0, min(100.0, round($discountPct, 2)));

        $costNet = ($netProvided && $net >= 0 && $net <= $gross) ? $net : $gross;

        return [
            'has_discount'       => ($discountPct > 0),
            'discount_pct'       => $discountPct,
            'comercial_discount' => $comercial,
            'financial_discount' => $financial,
            'cost_gross'         => $gross,
            'cost_net'           => $costNet,
            'supplier_id'        => (int)($supplier['supplier_id'] ?? 0),
        ];
    }

    /**
     * Fetch supplier cost-discount info for a product by ID (products/getOne).
     * Used where only the product ID is available (e.g. the stock-sync path,
     * whose lighter payload does not include the suppliers array).
     *
     * @param int $productId Moloni product ID
     *
     * @return array Same shape as extractSupplierDiscount() (all-zero on failure)
     */
    public static function fetchSupplierDiscount(int $productId): array
    {
        try {
            $response = Curl::simple('products/getOne', ['product_id' => $productId]);
        } catch (APIException $e) {
            Storage::$LOGGER->warning(
                str_replace('{0}', (string)$productId, __('Erro ao obter desconto de fornecedor do produto ({0})')),
                [
                    'tag' => 'helper:moloniproduct:supplierdiscount',
                    'ml_id' => $productId,
                    'message' => $e->getMessage(),
                ]
            );

            return self::extractSupplierDiscount([]);
        }

        if (empty($response) || !is_array($response)) {
            return self::extractSupplierDiscount([]);
        }

        return self::extractSupplierDiscount($response);
    }

    /**
     * Resolve the margin multiplier to apply, given the supplier cost-discount %.
     *
     * Starts from the base margin (MOLONI_COST_PRICE_MARGIN, default 1.30) and,
     * if a discount exists, overrides it with the matching configurable tier:
     *   MOLONI_MARGIN_TIER_N_DISCOUNT → minimum discount % to qualify
     *   MOLONI_MARGIN_TIER_N_MARGIN   → margin multiplier when qualified
     * The qualifying tier with the HIGHEST minimum-discount wins. Empty/invalid
     * slots are ignored, so with no tiers configured the result equals the base
     * margin (no behaviour change until tiers are defined).
     *
     * @param float $discountPct Effective supplier discount percentage (0–100)
     *
     * @return float Margin multiplier (>= 1.0)
     */
    public static function resolveMargin(float $discountPct): float
    {
        $margin = 1.3;

        if (defined('MOLONI_COST_PRICE_MARGIN') && (float)MOLONI_COST_PRICE_MARGIN >= 1.0) {
            $margin = (float)MOLONI_COST_PRICE_MARGIN;
        }

        if ($discountPct <= 0) {
            return $margin;
        }

        $bestMin = -1.0;
        for ($i = 1; $i <= self::MARGIN_TIER_SLOTS; $i++) {
            $dConst = 'MOLONI_MARGIN_TIER_' . $i . '_DISCOUNT';
            $mConst = 'MOLONI_MARGIN_TIER_' . $i . '_MARGIN';

            if (!defined($dConst) || !defined($mConst)) {
                continue;
            }

            $tierMin    = (float)constant($dConst);
            $tierMargin = (float)constant($mConst);

            if ($tierMin <= 0 || $tierMargin < 1.0) {
                continue;
            }

            if ($discountPct >= $tierMin && $tierMin > $bestMin) {
                $bestMin = $tierMin;
                $margin  = $tierMargin;
            }
        }

        return $margin;
    }

    /**
     * Calculate the minimum sale price based on cost price, margin and VAT.
     *
     * The margin is selected from the configurable discount tiers via
     * resolveMargin($discountPct): a bigger supplier cost-discount can map to a
     * different minimum margin. With no tiers configured (or no discount) the
     * base margin (MOLONI_COST_PRICE_MARGIN, default 1.30) is used — identical
     * to the previous behaviour.
     *
     * Formula: cost_price × margin(discount) × VAT_multiplier (if WC includes tax)
     *          cost_price × margin(discount) (if WC excludes tax)
     *
     * @param float $costPrice Product cost price from Moloni
     * @param array $moloniTaxes Moloni taxes array from product data
     * @param float $discountPct Effective supplier cost-discount % (default 0)
     *
     * @return float Minimum sale price (in WC price format — with or without tax)
     */
    public static function calculateMinimumSalePrice(float $costPrice, array $moloniTaxes, float $discountPct = 0.0): float
    {
        if ($costPrice <= 0) {
            return 0.0;
        }

        $margin = self::resolveMargin($discountPct);

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
        string $reference,
        array $discountInfo = []
    ): bool {
        // Variable parents have no own price — variations carry the price.
        if (method_exists($product, 'is_type') && $product->is_type('variable')) {
            return false;
        }

        $discountPct = isset($discountInfo['discount_pct']) ? (float)$discountInfo['discount_pct'] : 0.0;

        // Persist the supplier discount % on the product so it is visible and
        // queryable (lets the operator work the profit based on the discount).
        $product->update_meta_data('_moloni_supplier_discount_pct', $discountPct);

        $minimumPrice = self::calculateMinimumSalePrice($costPrice, $moloniTaxes, $discountPct);
        $appliedMargin = self::resolveMargin($discountPct);

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
                'discount_pct' => $discountPct,
                'applied_margin' => $appliedMargin,
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
            'discount_pct' => $discountPct,
            'applied_margin' => $appliedMargin,
        ]);

        if (!function_exists('wp_doing_cron') || !wp_doing_cron()) {
            Notice::addMessageWarning($message);
        }

        return true;
    }
}
