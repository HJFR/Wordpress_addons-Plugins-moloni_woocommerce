<?php

namespace Moloni\Services\WcProducts;

use Moloni\Exceptions\Stocks\StockLockedException;
use Moloni\Exceptions\Stocks\StockMatchingException;
use Moloni\Helpers\MoloniProduct;
use Moloni\Storage;
use WC_Product;


/**
 * Class UpdateProductStock
 * 
 * Service responsible for synchronizing stock levels from Moloni ERP to WooCommerce.
 * Updates a WooCommerce product's stock quantity to match the value from Moloni,
 * with support for warehouse-specific stock and service locking to prevent concurrent updates.
 */
class UpdateProductStock extends ImportService
{
    /**
     * @var bool Service lock flag to prevent execution when locked
     */
    private $locked = false;

    /**
     * @var int Moloni warehouse ID for stock retrieval (0 = all warehouses/default)
     */
    private $warehouseId = 0;

    /**
     * @var int Current stock quantity in WooCommerce
     */
    private $wcStock = 0;

    /**
     * @var int Stock quantity from Moloni to be synchronized
     */
    private $moloniStock = 0;

    /**
     * @var string Human-readable result message for logging/display
     */
    private $resultMessage = '';

    /**
     * @var array Structured result data for detailed logging
     */
    private $resultData = [];

    /**
     * Constructor
     * 
     * Initializes the stock update service with product data and warehouse configuration.
     * Warehouse ID priority:
     * 1. Explicitly passed $warehouseId parameter
     * 2. MOLONI_STOCK_SYNC constant if defined and > 1
     * 3. Default to 0 (typically means aggregate stock or default warehouse)
     * 
     * @param WC_Product $wcProduct The WooCommerce product to update
     * @param array $moloniProduct The Moloni product data array containing stock info
     * @param int|null $warehouseId Optional specific warehouse ID for stock lookup
     */
    public function __construct(WC_Product $wcProduct, array $moloniProduct, ?int $warehouseId = null)
    {
        // Store product references (inherited properties from ImportService)
        $this->wcProduct = $wcProduct;
        $this->moloniProduct = $moloniProduct;

        // Determine warehouse ID with fallback logic
        if (!is_null($warehouseId)) {
            // Use explicitly provided warehouse ID
            $this->warehouseId = $warehouseId;
        } elseif (defined('MOLONI_STOCK_SYNC') && (int)MOLONI_STOCK_SYNC > 1) {
            // Use configured warehouse from plugin settings
            // Values > 1 represent specific warehouse IDs
            $this->warehouseId = (int)MOLONI_STOCK_SYNC;
        }

        // Initialize stock values from both systems
        $this->init();
    }

    //            Public's            //

    /**
     * Execute the stock synchronization
     * 
     * Performs the following:
     * 1. Checks if service is locked (throws exception if so)
     * 2. Validates that stock values actually differ (throws exception if matching)
     * 3. Updates WooCommerce stock to match Moloni value
     * 4. Prepares result message and data for logging
     *
     * @throws StockLockedException If the service has been locked
     * @throws StockMatchingException If stock values already match (no update needed)
     */
    public function run(): void
    {
        // Prevent execution if service is locked
        if ($this->locked) {
            throw new StockLockedException(__('Serviço foi bloqueado'));
            // Translation: "Service was locked"
        }

        // Skip update if stocks already match - this is not an error condition,
        // but we throw an exception to signal that no action was taken
        if ($this->wcStock === $this->moloniStock) {
            $message = sprintf(
                __('Stock já se encontra correto no WooCommerce (%d|%d) (%s)'),
                $this->wcStock,
                $this->moloniStock,
                $this->moloniProduct['reference']
            );
            // Translation: "Stock is already correct in WooCommerce (WC|Moloni) (reference)"

            throw new StockMatchingException($message);
        }

        // Perform the actual stock update in WooCommerce
        wc_update_product_stock($this->wcProduct, $this->moloniStock);

        // Update cost price if enabled
        $this->updateCostPrice();

        // Prepare human-readable result message
        $this->resultMessage = sprintf(
            __('Stock atualizado no WooCommerce (antes: %s | depois: %s) (%s)'),
            $this->wcStock,
            $this->moloniStock,
            $this->moloniProduct['reference']
        );
        // Translation: "Stock updated in WooCommerce (before: X | after: Y) (reference)"

        // Prepare structured data for detailed logging/auditing
        $this->resultData = [
            'tag' => 'service:wcproduct:update:stock',  // Log category identifier
            'wc_id' => $this->wcProduct->get_id(),      // WooCommerce product ID
            'wc_stock' => $this->wcStock,               // Previous WooCommerce stock
            'ml_id' => $this->moloniProduct['product_id'],  // Moloni product ID
            'ml_reference' => $this->moloniProduct['reference'],  // Moloni SKU/reference
            'ml_stock' => $this->moloniStock,           // New stock value (from Moloni)
        ];
    }

    /**
     * Lock the service to prevent execution
     * 
     * Used to prevent concurrent stock updates or to temporarily
     * disable the service during maintenance/batch operations.
     */
    public function lockService(): void
    {
        $this->locked = true;
    }

    /**
     * Unlock the service to allow execution
     */
    public function unlockService(): void
    {
        $this->locked = false;
    }

    /**
     * Check if the service is currently locked
     * 
     * @return bool True if locked, false if available for execution
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Persist the operation result to the logging system
     * 
     * Should be called after successful run() execution to record
     * the stock update for auditing purposes.
     */
    public function saveLog()
    {
        Storage::$LOGGER->info($this->resultMessage, $this->resultData);
    }

    //            Privates            //

    /**
     * Update cost price from Moloni and enforce minimum sale price
     *
     * Fetches the last cost price from Moloni API, sets it on
     * the WooCommerce product, then validates the selling price
     * against the calculated minimum (cost × margin × VAT).
     */
    private function updateCostPrice(): void
    {
        if (!defined('MOLONI_COST_PRICE_SYNC') || (int)MOLONI_COST_PRICE_SYNC !== 1) {
            return;
        }

        $costPrice = MoloniProduct::fetchCostPrice((int)$this->moloniProduct['product_id']);

        if ($costPrice === null) {
            return;
        }

        // Reload product to get fresh object after stock update
        $wcProduct = wc_get_product($this->wcProduct->get_id());

        if (!$wcProduct) {
            return;
        }

        MoloniProduct::setCostPriceOnWcProduct($wcProduct, $costPrice);

        // Supplier discount: the getModifiedSince payload is light and may omit
        // the suppliers array — use it when present, otherwise fetch via getOne.
        $discountInfo = !empty($this->moloniProduct['suppliers'])
            ? MoloniProduct::extractSupplierDiscount($this->moloniProduct)
            : MoloniProduct::fetchSupplierDiscount((int)$this->moloniProduct['product_id']);

        // Enforce minimum sale price based on cost + the discount-based tier
        MoloniProduct::enforceMinimumPrice(
            $wcProduct,
            $costPrice,
            $this->moloniProduct['taxes'] ?? [],
            $this->moloniProduct['reference'] ?? '',
            $discountInfo
        );

        $wcProduct->save();
    }

    /**
     * Initialize stock values from both systems
     *
     * Retrieves and stores:
     * - Moloni stock: Parsed from product data for the specified warehouse
     * - WooCommerce stock: Current stock quantity from the WC product
     */
    private function init(): void
    {
        // Parse Moloni stock for the specific warehouse (or total if warehouseId = 0)
        $this->moloniStock = (int)MoloniProduct::parseMoloniStock($this->moloniProduct, $this->warehouseId);

        // Get current WooCommerce stock quantity
        $this->wcStock = $this->wcProduct->get_stock_quantity();
    }

    //            Gets            //

    /**
     * Get the current WooCommerce stock quantity
     * 
     * @return int Stock quantity before update
     */
    public function getWcStock(): int
    {
        return $this->wcStock;
    }

    /**
     * Get the Moloni stock quantity
     * 
     * @return int Stock quantity from Moloni (target value for sync)
     */
    public function getMoloniStock(): int
    {
        return $this->moloniStock;
    }

    /**
     * Get the warehouse ID used for stock lookup
     * 
     * @return int Warehouse ID (0 typically means default/aggregate)
     */
    public function getWarehouseId(): int
    {
        return $this->warehouseId;
    }

    /**
     * Get the human-readable result message
     * 
     * @return string Result message (empty if run() hasn't completed successfully)
     */
    public function getResultMessage(): string
    {
        return $this->resultMessage ?? '';
    }

    //            Sets            //

    /**
     * Override the WooCommerce stock value
     * 
     * Allows manual adjustment of the "before" value for custom scenarios.
     * 
     * @param int|null $wcStock Stock quantity (defaults to 0 if null)
     */
    public function setWcStock(?int $wcStock = 0): void
    {
        $this->wcStock = $wcStock;
    }

    /**
     * Override the Moloni stock value
     * 
     * Allows manual adjustment of the target stock for custom scenarios.
     * 
     * @param int|null $moloniStock Stock quantity (defaults to 0 if null)
     */
    public function setMoloniStock(?int $moloniStock = 0): void
    {
        $this->moloniStock = $moloniStock;
    }
}
