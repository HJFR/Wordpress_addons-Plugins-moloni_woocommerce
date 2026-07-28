<?php

namespace Moloni\Services\Stocks;

use Moloni\Curl;
use Moloni\Storage;
use Moloni\Exceptions\APIException;

/**
 * Class SyncStockByPriority
 *
 * WooCommerce-driven FULL sync that walks the catalogue in PRIORITY order so the
 * products most likely to carry a wrong (last-sale-based) price are corrected
 * FIRST. Unlike SyncStockFromMoloni (which enumerates Moloni products and is
 * blind to WooCommerce state), this service enumerates WooCommerce products and
 * orders them into four phases:
 *
 *   Phase 1: published    + in stock      (highest risk, commercially live)
 *   Phase 2: not published + in stock     (moved recently but hidden)
 *   Phase 3: published    + out of stock  (visible, lower risk)
 *   Phase 4: not published + out of stock (the rest)
 *
 * For each WooCommerce product it looks up the matching Moloni product by SKU
 * (products/getByReference) and applies the SAME stock + cost + price-floor logic
 * as the recent-window sweep (shared ProcessesProductStock trait).
 *
 * It is RESUMABLE (state = {phase, page} persisted in wp_options) and processed
 * one small page per 5-minute cron tick to stay under the 60 req/min API limit
 * and the PHP timeout. It is also CANCELLABLE at any time: a cancel flag is
 * checked at tick start AND periodically mid-batch (cache-proof direct DB read),
 * so a misconfiguration spotted after the first products can stop the rest.
 *
 * Stock basis for bucketing is the WooCommerce stock_status (instock vs
 * outofstock) — the only basis queryable without one Moloni API call PER product
 * just to classify the whole catalogue.
 */
class SyncStockByPriority
{
    use ProcessesProductStock;

    /** wp_options key holding the resumable progress state (array {phase,page,stats,mode}). */
    public const STATE_OPTION = 'moloni_full_sync_state';

    /** wp_options key acting as the cancel signal. Presence = cancel requested. */
    public const CANCEL_OPTION = 'moloni_full_sync_cancel';

    /** Sweep mode: stock + fields + cost (the original full sync). */
    public const MODE_STOCK = 'stock';

    /**
     * Sweep mode: FIELDS ONLY, Moloni → WooCommerce — applies the per-field
     * syncs enabled in Settings (EAN, IVA, preço de custo + piso) to every
     * Moloni product that EXISTS in WooCommerce (matched by SKU/reference).
     * Never touches stock and never creates products.
     */
    public const MODE_FIELDS = 'fields';

    /**
     * Sweep mode: FIELDS ONLY, WooCommerce → Moloni — pushes the per-field syncs
     * enabled in Settings (EAN, preço, propriedades, resumo, imagem) to every
     * WooCommerce product that EXISTS in Moloni (matched by SKU/reference), via
     * Product::updateFieldsOnly(), which echoes every other Moloni field back
     * unchanged. Never creates products in Moloni.
     */
    public const MODE_FIELDS_WM = 'fields_wm';

    /**
     * Priority phases. Each phase selects WooCommerce products by post status +
     * stock status. Lower phase number = processed first.
     */
    public const PHASES = [
        1 => ['statuses' => ['publish'],                       'stock_status' => 'instock',    'label' => 'Com stock + publicados'],
        2 => ['statuses' => ['draft', 'pending', 'private'],   'stock_status' => 'instock',    'label' => 'Com stock + não publicados'],
        3 => ['statuses' => ['publish'],                       'stock_status' => 'outofstock', 'label' => 'Publicados sem stock'],
        4 => ['statuses' => ['draft', 'pending', 'private'],   'stock_status' => 'outofstock', 'label' => 'Não publicados sem stock'],
    ];

    /** Highest phase number (sweep is complete once this phase finishes). */
    public const LAST_PHASE = 4;

    /** Default products per cron tick. Tunable via MOLONI_FULL_SYNC_BATCH. */
    private const DEFAULT_BATCH = 50;

    /** Default throttle (µs) between per-product iterations. Tunable via MOLONI_SYNC_THROTTLE_US. */
    private const DEFAULT_THROTTLE_US = 200000; // 200 ms

    /** Re-read the cancel flag from the DB every N products within a batch. */
    private const CANCEL_CHECK_EVERY = 5;

    private $phase;
    private $page;
    private $batch;
    private $throttleUs;

    /** @var string Sweep mode (MODE_STOCK | MODE_FIELDS) */
    private $mode = self::MODE_STOCK;

    private $phaseComplete = false;
    private $cancelled = false;

    private $found = 0;
    private $updated = [];
    private $equal = [];
    private $notFound = [];
    private $locked = [];
    private $pricedOnly = 0;

    public function __construct(int $phase, int $page = 1, int $batchOverride = 0, string $mode = self::MODE_STOCK)
    {
        $this->phase = $phase;
        $this->page = max(1, $page);
        $this->mode = self::normalizeMode($mode);

        $this->batch = self::DEFAULT_BATCH;
        if ((int)$batchOverride > 0) {
            $this->batch = (int)$batchOverride;
        } elseif (defined('MOLONI_FULL_SYNC_BATCH') && (int)MOLONI_FULL_SYNC_BATCH > 0) {
            $this->batch = (int)MOLONI_FULL_SYNC_BATCH;
        }

        $this->throttleUs = self::DEFAULT_THROTTLE_US;
        if (defined('MOLONI_SYNC_THROTTLE_US') && (int)MOLONI_SYNC_THROTTLE_US >= 0) {
            $this->throttleUs = (int)MOLONI_SYNC_THROTTLE_US;
        }
    }

    //            Public            //

    /**
     * Process ONE page of the current phase.
     */
    public function run(): SyncStockByPriority
    {
        $phaseDef = self::PHASES[$this->phase] ?? null;

        if ($phaseDef === null) {
            // Unknown/finished phase — nothing to do, signal completion.
            $this->phaseComplete = true;

            return $this;
        }

        // Abort early if a cancellation was requested before we even start.
        if (self::cancelRequested()) {
            $this->cancelled = true;

            return $this;
        }

        $query = wc_get_products([
            'status' => $phaseDef['statuses'],
            'stock_status' => $phaseDef['stock_status'],
            // 'simple' + 'variation' covers both standalone products and the
            // individual variants that carry their own SKU/stock; variable PARENT
            // products are excluded on purpose (they hold no stock/price of their
            // own — the price floor is enforced per variation).
            'type' => ['simple', 'variation'],
            'limit' => $this->batch,
            'page' => $this->page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'paginate' => true,
            'return' => 'objects',
        ]);

        $products = is_object($query) && isset($query->products) ? $query->products : [];
        $maxPages = is_object($query) && isset($query->max_num_pages) ? (int)$query->max_num_pages : 0;

        $this->found = count($products);

        // This is the last page of the phase when we've reached/passed max pages
        // (also covers the empty-result case where maxPages is 0).
        $this->phaseComplete = ($this->page >= $maxPages);

        if (empty($products)) {
            return $this;
        }

        $index = 0;

        foreach ($products as $wcProduct) {
            // Cancellation can be requested mid-sweep — check periodically using a
            // cache-proof read so a click takes effect within the current batch.
            if (($index % self::CANCEL_CHECK_EVERY) === 0 && self::cancelRequested()) {
                $this->cancelled = true;

                return $this;
            }

            $index++;

            if (!is_object($wcProduct) || !method_exists($wcProduct, 'get_sku')) {
                continue;
            }

            $reference = (string)$wcProduct->get_sku();

            if ($reference === '') {
                $this->notFound[(string)$wcProduct->get_id()] = __('Produto WooCommerce sem referência');

                continue;
            }

            // FIELDS WC→Moloni mode: push only the enabled fields to the matched
            // Moloni product (updateFieldsOnly echoes everything else unchanged).
            if ($this->mode === self::MODE_FIELDS_WM) {
                $this->pushFieldsToMoloni($wcProduct, $reference);

                if ($this->throttleUs > 0) {
                    usleep($this->throttleUs);
                }

                continue;
            }

            try {
                $mlProduct = Curl::simple('products/getByReference', [
                    'reference' => $reference,
                    'with_invisible' => true,
                    'exact' => 1,
                ]);
            } catch (APIException $e) {
                // A single failed lookup must not abort the batch — log and move on.
                Storage::$LOGGER->error(__('Erro ao obter o artigo Moloni por referência'), [
                    'tag' => 'stock:sync:priority:getbyreference:error',
                    'reference' => $reference,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            if (empty($mlProduct) || empty($mlProduct[0]['product_id'])) {
                $this->notFound[$reference] = __('Produto não encontrado na conta Moloni');

                continue;
            }

            $mlProduct = $mlProduct[0];

            // FIELDS mode: apply only the per-field syncs enabled in Settings
            // (EAN, IVA, custo + piso de preço) — never touch stock.
            if ($this->mode === self::MODE_FIELDS) {
                try {
                    // The saves below fire woocommerce_update_product — suppress the
                    // WC→Moloni push hook for this product so a Moloni→WC field sync
                    // can never trigger a reverse full update.
                    \Moloni\Helpers\SyncLogs::addTimeout(\Moloni\Enums\SyncLogsType::WC_PRODUCT, (int)$wcProduct->get_id());

                    $fresh = wc_get_product($wcProduct->get_id());

                    if ($fresh) {
                        $this->syncCostPriceFallback($mlProduct, $fresh);
                        $this->pricedOnly++;
                    }
                } catch (\Exception $error) {
                    Storage::$LOGGER->error(__('Erro ao sincronizar campos do produto'), [
                        'tag' => 'fields:sync:priority:error',
                        'reference' => $reference,
                        'message' => $error->getMessage(),
                    ]);
                }

                if ($this->throttleUs > 0) {
                    usleep($this->throttleUs);
                }

                continue;
            }

            if (empty($mlProduct['has_stock'])) {
                // Not stock-managed in Moloni — do NOT touch WC stock, but still
                // refresh cost + enforce the price floor (the point of this sweep).
                try {
                    $fresh = wc_get_product($wcProduct->get_id());

                    if ($fresh) {
                        $this->syncCostPriceFallback($mlProduct, $fresh);
                        $this->pricedOnly++;
                    }
                } catch (\Exception $error) {
                    Storage::$LOGGER->error(__('Erro ao sincronizar preço de custo do produto'), [
                        'tag' => 'stock:sync:priority:costprice:error',
                        'reference' => $reference,
                        'message' => $error->getMessage(),
                    ]);
                }
            } else {
                $result = $this->processProductStock($mlProduct, $wcProduct, $reference);

                switch ($result['outcome']) {
                    case 'updated':
                        $this->updated[$reference] = $result['message'];
                        break;
                    case 'equal':
                        $this->equal[$reference] = $result['message'];
                        break;
                    case 'locked':
                        $this->locked[$reference] = $result['message'];
                        break;
                    // 'error' was already logged inside processProductStock()
                }
            }

            if ($this->throttleUs > 0) {
                usleep($this->throttleUs);
            }
        }

        return $this;
    }

    /**
     * Push the enabled WC→Moloni fields for one product. Never throws — failures
     * are logged so one bad product never aborts the batch.
     *
     * @param object $wcProduct WooCommerce product
     * @param string $reference SKU/reference (match key + logging)
     */
    private function pushFieldsToMoloni($wcProduct, string $reference): void
    {
        try {
            // Suppress the woocommerce_update_product hook for this product for a
            // few seconds: updateFieldsOnly()/syncImage() may save() WC meta, and
            // without this a loaded ProductUpdate hook would push a FULL update
            // (all fields) — defeating the whole point of a fields-only sweep.
            \Moloni\Helpers\SyncLogs::addTimeout(\Moloni\Enums\SyncLogsType::WC_PRODUCT, (int)$wcProduct->get_id());

            $controller = new \Moloni\Controllers\Product($wcProduct);

            if (!$controller->loadByReference()) {
                $this->notFound[$reference] = __('Produto não encontrado na conta Moloni');

                return;
            }

            if ($controller->updateFieldsOnly()) {
                $this->updated[$reference] = __('Campos atualizados no Moloni');
            } else {
                $this->equal[$reference] = __('Campos já corretos no Moloni');
            }
        } catch (\Exception $error) {
            Storage::$LOGGER->error(__('Erro ao sincronizar campos para o Moloni'), [
                'tag' => 'fields:sync:priority:wm:error',
                'reference' => $reference,
                'message' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Whether the current phase has no more pages after this run.
     */
    public function isPhaseComplete(): bool
    {
        return $this->phaseComplete;
    }

    /**
     * Whether the run stopped because a cancellation was requested.
     */
    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Next page within the current phase.
     */
    public function getNextPage(): int
    {
        return $this->page + 1;
    }

    public function getPhase(): int
    {
        return $this->phase;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    //            Counts            //

    public function countFoundRecord(): int
    {
        return $this->found;
    }

    public function countUpdated(): int
    {
        return count($this->updated);
    }

    public function countEqual(): int
    {
        return count($this->equal);
    }

    public function countNotFound(): int
    {
        return count($this->notFound);
    }

    public function countLocked(): int
    {
        return count($this->locked);
    }

    public function countPricedOnly(): int
    {
        return $this->pricedOnly;
    }

    public function getUpdated(): array
    {
        return $this->updated;
    }

    public function getEqual(): array
    {
        return $this->equal;
    }

    public function getNotFound(): array
    {
        return $this->notFound;
    }

    public function getLocked(): array
    {
        return $this->locked;
    }

    //            State helpers (wp_options)            //

    /**
     * Read the resumable progress state. Returns null when no full sync is armed.
     *
     * @return array|null {phase:int, page:int, stats:array, mode:string}
     */
    public static function getState(): ?array
    {
        $raw = get_option(self::STATE_OPTION, false);

        if ($raw === false) {
            return null;
        }

        $state = is_array($raw) ? $raw : json_decode((string)$raw, true);

        if (!is_array($state) || empty($state['phase'])) {
            return null;
        }

        return [
            'phase' => (int)$state['phase'],
            'page' => max(1, (int)($state['page'] ?? 1)),
            'stats' => is_array($state['stats'] ?? null) ? $state['stats'] : [],
            // States written by pre-5.4 versions carry no mode — they are stock sweeps.
            'mode' => self::normalizeMode((string)($state['mode'] ?? self::MODE_STOCK)),
        ];
    }

    /**
     * Clamp a mode string to one of the known sweep modes (unknown → stock).
     */
    public static function normalizeMode(string $mode): string
    {
        return in_array($mode, [self::MODE_FIELDS, self::MODE_FIELDS_WM], true) ? $mode : self::MODE_STOCK;
    }

    /**
     * Persist progress. Stored non-autoloaded (transient-like progress state).
     */
    public static function setState(int $phase, int $page, array $stats = [], string $mode = self::MODE_STOCK): void
    {
        $value = [
            'phase' => $phase,
            'page' => max(1, $page),
            'stats' => $stats,
            'mode' => self::normalizeMode($mode),
        ];

        if (get_option(self::STATE_OPTION, false) === false) {
            add_option(self::STATE_OPTION, $value, '', false);
        } else {
            update_option(self::STATE_OPTION, $value, false);
        }
    }

    /**
     * Arm the priority full sync at phase 1, in the given mode. Returns false if
     * a sweep (either mode) is already armed.
     */
    public static function arm(string $mode = self::MODE_STOCK): bool
    {
        if (get_option(self::STATE_OPTION, false) !== false) {
            return false;
        }

        $mode = self::normalizeMode($mode);

        // Atomically claim the armed state first. add_option() returns false if the
        // option already exists (a concurrent double-click lost the race) — bail
        // WITHOUT touching the cancel flag, so we never accidentally un-cancel a
        // sweep that another request is already cancelling.
        $added = add_option(self::STATE_OPTION, ['phase' => 1, 'page' => 1, 'stats' => [], 'mode' => $mode], '', false);

        if (!$added) {
            return false;
        }

        // We won the race and just armed — clear any stale cancel flag from a
        // previous run so this fresh sweep is not immediately cancelled.
        delete_option(self::CANCEL_OPTION);

        return true;
    }

    public static function isArmed(): bool
    {
        return get_option(self::STATE_OPTION, false) !== false;
    }

    /**
     * Request cancellation of the running full sync.
     */
    public static function requestCancel(): void
    {
        if (get_option(self::CANCEL_OPTION, false) === false) {
            add_option(self::CANCEL_OPTION, '1', '', false);
        } else {
            update_option(self::CANCEL_OPTION, '1', false);
        }
    }

    /**
     * Whether a cancellation has been requested. Reads the options table DIRECTLY
     * (bypassing the object cache) so a cancel issued by a separate HTTP request is
     * visible to a batch already running inside a cron process.
     */
    public static function cancelRequested(): bool
    {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::CANCEL_OPTION
            )
        );

        return $value !== null;
    }

    /**
     * Clear all full-sync state (progress + cancel flag). Used on completion or
     * cancellation.
     */
    public static function clear(): void
    {
        delete_option(self::STATE_OPTION);
        delete_option(self::CANCEL_OPTION);
    }
}
