<?php

namespace Moloni\Services\Stocks;

use Moloni\Curl;
use Moloni\Storage;
use Moloni\Exceptions\APIException;
use Moloni\Exceptions\Stocks\StockLockedException;
use Moloni\Exceptions\Stocks\StockMatchingException;
use Moloni\Helpers\MoloniProduct;
use Moloni\Services\WcProducts\UpdateProductStock;

class SyncStockFromMoloni
{
    /**
     * Default cap on products processed per cron tick. Tunable via the
     * MOLONI_SYNC_MAX_PRODUCTS constant (set in plugin settings / DB).
     * When the cap is hit, the sync is marked truncated so the caller can
     * decide whether to advance the watermark or retry from the same point.
     */
    private const DEFAULT_MAX_PRODUCTS = 2000;

    /**
     * Default throttle (microseconds) between paginated API calls AND between
     * per-product processing iterations. ~5 req/s budget by default; tunable via
     * MOLONI_SYNC_THROTTLE_US (set 0 to disable).
     */
    private const DEFAULT_THROTTLE_US = 200000; // 200 ms

    /** Moloni hard page size for products/getModifiedSince */
    private const PAGE_SIZE = 50;

    private $since;

    private $offset = 0;
    private $limit;
    private $throttleUs;
    private $truncated = false;
    private $found = 0;

    private $updated = [];
    private $equal = [];
    private $notFound = [];
    private $locked = [];

    public function __construct($since)
    {
        if (is_numeric($since)) {
            $sinceTime = $since;
        } else {
            $sinceTime = strtotime($since);

            if (!$sinceTime) {
                $sinceTime = strtotime('-1 week');
            }
        }

        $this->since = gmdate('Y-m-d H:i:s', $sinceTime);

        $this->limit = self::DEFAULT_MAX_PRODUCTS;
        if (defined('MOLONI_SYNC_MAX_PRODUCTS') && (int)MOLONI_SYNC_MAX_PRODUCTS > 0) {
            $this->limit = (int)MOLONI_SYNC_MAX_PRODUCTS;
        }

        $this->throttleUs = self::DEFAULT_THROTTLE_US;
        if (defined('MOLONI_SYNC_THROTTLE_US') && (int)MOLONI_SYNC_THROTTLE_US >= 0) {
            $this->throttleUs = (int)MOLONI_SYNC_THROTTLE_US;
        }
    }

    //            Publics            //

    /**
     * Run the sync operation
     */
    public function run(): SyncStockFromMoloni
    {
        $updatedProducts = $this->getAllMoloniProducts();

        if (empty($updatedProducts)) {
            return $this;
        }

        $this->found = count($updatedProducts);

        foreach ($updatedProducts as $product) {
            if (empty($product['reference']) || !is_string($product['reference'])) {
                continue;
            }

            $reference = $product['reference'];

            if (empty($product['has_stock'])) {
                $this->notFound[$reference] = __('Artigo sem stock ativo');

                continue;
            }

            $wcProductId = wc_get_product_id_by_sku($reference);

            if ($wcProductId <= 0) {
                $this->notFound[$reference] = __('Artigo não encontrado');

                continue;
            }

            $wcProduct = wc_get_product($wcProductId);

            if (!$wcProduct) {
                $this->notFound[$reference] = __('Artigo não encontrado');

                continue;
            }

            // UpdateProductStock::run() does its own cost-price sync on the
            // refreshed product instance, so only fall back to syncCostPrice
            // when stock processing did NOT execute (match / locked / error).
            $costSyncHandledByStockService = false;

            try {
                $service = new UpdateProductStock($wcProduct, $product);

                do_action('moloni_before_product_stock_sync', $service);

                $service->run();

                $this->updated[$reference] = $service->getResultMessage();
                $costSyncHandledByStockService = true;
            } catch (StockMatchingException $error) {
                $this->equal[$reference] = $error->getMessage();
            } catch (StockLockedException $error) {
                $this->locked[$reference] = $error->getMessage();
            }

            if (!$costSyncHandledByStockService) {
                // Reload to avoid acting on a stale object — the stock service
                // may have already persisted unrelated changes.
                $freshProduct = wc_get_product($wcProductId);

                if ($freshProduct) {
                    $this->syncCostPrice($product, $freshProduct);
                }
            }

            // Throttle per-product to keep aggregate API rate well under the
            // Moloni 429 threshold. fetchCostPrice = 1 extra call per product.
            if ($this->throttleUs > 0) {
                usleep($this->throttleUs);
            }
        }

        return $this;
    }

    /**
     * Whether the sync stopped because it hit the per-tick product cap.
     * Callers should NOT advance the watermark when truncated.
     */
    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    //            Counts            //

    /**
     * Get the amount of records found
     *
     * @return int
     */
    public function countFoundRecord(): int
    {
        return $this->found;
    }

    /**
     * Get the amount of records updates
     *
     * @return int
     */
    public function countUpdated(): int
    {
        return count($this->updated);
    }

    /**
     * Get the amount of records that had the same stock count
     *
     * @return int
     */
    public function countEqual(): int
    {
        return count($this->equal);
    }

    /**
     * Get the amount of products not found in WooCommerce
     *
     * @return int
     */
    public function countNotFound(): int
    {
        return count($this->notFound);
    }

    /**
     * Get the amount of products locked
     *
     * @return int
     */
    public function countLocked(): int
    {
        return count($this->locked);
    }

    //            Gets            //

    /**
     * Get date used to fetch
     *
     * @return false|string
     */
    public function getSince()
    {
        return $this->since ?? '';
    }

    /**
     * Return the updated products
     *
     * @return array
     */
    public function getUpdated(): array
    {
        return $this->updated;
    }

    /**
     * Return the list of products that had the same stock as in WooCommerce
     *
     * @return array
     */
    public function getEqual(): array
    {
        return $this->equal;
    }

    /**
     * Return the list of products update in Moloni but not found in WooCommerce
     *
     * @return array
     */
    public function getNotFound(): array
    {
        return $this->notFound;
    }

    /**
     * Return the list of products locked
     *
     * @return array
     */
    public function getLocked(): array
    {
        return $this->locked;
    }

    //            Cost Price            //

    /**
     * Sync cost price from Moloni to WooCommerce and enforce minimum sale price
     *
     * @param array $product Moloni product data (includes taxes array)
     * @param \WC_Product $wcProduct WooCommerce product
     */
    private function syncCostPrice(array $product, $wcProduct): void
    {
        if (!defined('MOLONI_COST_PRICE_SYNC') || (int)MOLONI_COST_PRICE_SYNC !== 1) {
            return;
        }

        if (empty($product['product_id'])) {
            return;
        }

        $costPrice = MoloniProduct::fetchCostPrice((int)$product['product_id']);

        if ($costPrice === null) {
            return;
        }

        MoloniProduct::setCostPriceOnWcProduct($wcProduct, $costPrice);

        // Enforce minimum sale price based on cost
        MoloniProduct::enforceMinimumPrice(
            $wcProduct,
            $costPrice,
            $product['taxes'] ?? [],
            $product['reference'] ?? ''
        );

        $wcProduct->save();
    }

    //            Auxiliary            //

    /**
     * Fetch all modified products from Moloni, paginated.
     *
     * Moloni caps responses at 50 records per request (products/getModifiedSince).
     * We paginate via offset, throttle between requests, and stop when either:
     *   - the API returns fewer than PAGE_SIZE results (last page reached),
     *   - the cumulative count reaches the per-tick cap (sets $this->truncated),
     *   - the API errors out (stop, do not infinite-loop).
     *
     * @return array
     */
    private function getAllMoloniProducts(): array
    {
        $productsList = [];

        while (true) {
            $values = [
                'company_id' => Storage::$MOLONI_COMPANY_ID,
                'lastmodified' => $this->since,
                'offset' => $this->offset,
                'qty' => self::PAGE_SIZE,
            ];

            try {
                $fetched = Curl::simple('products/getModifiedSince', $values);
            } catch (APIException $e) {
                Storage::$LOGGER->error(__('Atenção, erro ao obter todos os artigos via API'), [
                    'action' => 'stock:sync:service',
                    'message' => $e->getMessage(),
                    'exception' => $e->getData(),
                ]);

                // Mark as truncated so caller does not advance the watermark
                // past products we never saw.
                $this->truncated = true;

                break;
            }

            // No more products (empty page or unexpected shape)
            if (!is_array($fetched) || !isset($fetched[0]['product_id'])) {
                break;
            }

            foreach ($fetched as $item) {
                $productsList[] = $item;
            }

            $this->offset += count($fetched);

            // Last page (partial fill)
            if (count($fetched) < self::PAGE_SIZE) {
                break;
            }

            // Hit the per-tick cap — stop and signal truncation so the next
            // cron tick will resume from the same watermark.
            if (count($productsList) >= $this->limit) {
                $this->truncated = true;

                Storage::$LOGGER->warning(
                    __('Sincronização de stock truncada: limite de produtos por ciclo atingido'),
                    [
                        'action' => 'stock:sync:service:truncated',
                        'limit' => $this->limit,
                        'fetched_so_far' => count($productsList),
                        'next_offset' => $this->offset,
                    ]
                );

                break;
            }

            // Throttle between paginated fetches.
            if ($this->throttleUs > 0) {
                usleep($this->throttleUs);
            }
        }

        return $productsList;
    }

}
