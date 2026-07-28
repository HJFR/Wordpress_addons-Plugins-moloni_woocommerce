<?php

namespace Moloni\Services\Stocks;

use Moloni\Storage;
use Moloni\Exceptions\Stocks\StockLockedException;
use Moloni\Exceptions\Stocks\StockMatchingException;
use Moloni\Helpers\MoloniProduct;
use Moloni\Services\WcProducts\UpdateProductStock;
use WC_Product;

/**
 * Shared per-product stock + cost-price processing.
 *
 * Both the recent-window/full Moloni-driven sweep (SyncStockFromMoloni) and the
 * WooCommerce-driven priority sweep (SyncStockByPriority) apply EXACTLY the same
 * logic to a single matched product: try to update stock via UpdateProductStock
 * (which also refreshes cost + enforces the minimum-price floor when stock
 * changes), and when stock processing does NOT run (match / locked / error) fall
 * back to a cost-price-only refresh so the price floor is still enforced.
 *
 * Keeping this in one trait means there is a single, audited code path for the
 * money-touching logic instead of two copies that can drift.
 */
trait ProcessesProductStock
{
    /**
     * Sync stock (and, as a side effect of UpdateProductStock, cost + price floor)
     * for a single product. NEVER throws — failures are logged and reported as the
     * 'error' outcome so one bad product never aborts a batch.
     *
     * @param array      $moloniProduct Moloni product payload (needs product_id, reference, taxes, optionally suppliers)
     * @param WC_Product $wcProduct     The matched WooCommerce product
     * @param string     $reference     SKU/reference (for logging)
     *
     * @return array{outcome: string, message: string} outcome is one of
     *               'updated' | 'equal' | 'locked' | 'error'
     */
    protected function processProductStock(array $moloniProduct, WC_Product $wcProduct, string $reference): array
    {
        // UpdateProductStock::run() does its own cost-price sync on the refreshed
        // product instance, so only fall back to a cost-only refresh when stock
        // processing did NOT execute (match / locked / error).
        $costSyncHandledByStockService = false;

        try {
            $service = new UpdateProductStock($wcProduct, $moloniProduct);

            do_action('moloni_before_product_stock_sync', $service);

            $service->run();

            $costSyncHandledByStockService = true;

            $result = ['outcome' => 'updated', 'message' => $service->getResultMessage()];
        } catch (StockMatchingException $error) {
            $result = ['outcome' => 'equal', 'message' => $error->getMessage()];
        } catch (StockLockedException $error) {
            $result = ['outcome' => 'locked', 'message' => $error->getMessage()];
        } catch (\Exception $error) {
            // Any other failure (API error, WooCommerce internal, etc.) must NOT
            // abort the whole batch — log this product and report it as an error so
            // the remaining products in the run still sync.
            Storage::$LOGGER->error(__('Erro ao sincronizar stock do produto'), [
                'tag' => 'stock:sync:service:product:error',
                'reference' => $reference,
                'message' => $error->getMessage(),
            ]);

            return ['outcome' => 'error', 'message' => $error->getMessage()];
        }

        if (!$costSyncHandledByStockService) {
            try {
                // Reload to avoid acting on a stale object — the stock service may
                // have already persisted unrelated changes.
                $freshProduct = wc_get_product($wcProduct->get_id());

                if ($freshProduct) {
                    $this->syncCostPriceFallback($moloniProduct, $freshProduct);
                }
            } catch (\Exception $error) {
                Storage::$LOGGER->error(__('Erro ao sincronizar preço de custo do produto'), [
                    'tag' => 'stock:sync:service:costprice:error',
                    'reference' => $reference,
                    'message' => $error->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Sync cost price from Moloni to WooCommerce and enforce the minimum sale
     * price WITHOUT a stock update. Used when stock did not change (or is not
     * managed) but the price floor must still be (re)applied.
     *
     * @param array      $product   Moloni product data (includes taxes array)
     * @param WC_Product $wcProduct WooCommerce product (fresh instance)
     */
    protected function syncCostPriceFallback(array $product, WC_Product $wcProduct): void
    {
        // Moloni-owned fields (EAN, IVA — per Settings) are independent of the
        // cost-price feature: sync them here too, so products that are NOT
        // stock-managed in Moloni (which never reach UpdateProductStock) still
        // get them — even when cost-price sync is off.
        if (MoloniProduct::applyMoloniFields($wcProduct, $product)) {
            $wcProduct->save();
        }

        // Shared cost path: supplier "Preço de Custo c/ Desc." first, last
        // document cost as fallback; enforces the minimum-price floor.
        MoloniProduct::syncCostAndPriceFloor($product, $wcProduct);
    }
}
