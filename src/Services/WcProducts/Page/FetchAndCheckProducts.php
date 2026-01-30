<?php

namespace Moloni\Services\WcProducts\Page;

use Moloni\Helpers\MoloniProduct;
use Moloni\Exceptions\APIException;

/**
 * Class FetchAndCheckProducts
 * 
 * Service responsible for fetching WooCommerce products with pagination and filters,
 * then checking each product against the Moloni ERP system for synchronization status.
 * This is the main orchestrator for the WooCommerce products comparison page.
 */
class FetchAndCheckProducts
{
    /**
     * @var int Number of products to display per page (pagination limit)
     */
    private static $perPage = 20;

    /**
     * @var int Current page number for pagination (1-indexed)
     */
    private $page = 1;

    /**
     * @var array Active filters for product search (name, reference/SKU)
     */
    private $filters = [];

    /**
     * @var array Collection of rendered HTML rows for each checked product
     */
    private $rows = [];

    /**
     * @var array WooCommerce product objects fetched from the database
     */
    private $products = [];

    /**
     * @var int Total count of products matching current filters (for pagination)
     */
    private $totalProducts = 0;

    /**
     * @var int Moloni warehouse ID used for stock comparisons
     */
    private $warehouseId = 0;

    //            Public's            //

    /**
     * Execute the main service workflow
     * 
     * 1. Retrieves the appropriate Moloni warehouse ID for stock sync
     * 2. Fetches WooCommerce products based on current page and filters
     * 3. Checks each product against Moloni and collects rendered HTML results
     *
     * @return void
     *
     * @throws APIException If Moloni API communication fails
     */
    public function run()
    {
        // Get the configured warehouse ID for manual data synchronization tools
        $this->warehouseId = MoloniProduct::getWarehouseIdForManualDataSyncTools();

        // Fetch paginated and filtered products from WooCommerce
        $this->fetchProducts();

        // Process each product through the CheckProduct service
        foreach ($this->products as $product) {
            $service = new CheckProduct($product, $this->warehouseId);
            $service->run();

            // Collect the rendered HTML output for this product's check results
            $this->rows[] = $service->getRowsHtml();
        }
    }

    /**
     * Generate WordPress pagination links for the product listing
     * 
     * Creates pagination HTML using WordPress's paginate_links() function,
     * preserving current filter parameters across page navigation.
     * 
     * @return string|array|void Pagination HTML links (depends on WordPress settings)
     */
    public function getPaginator()
    {
        // Build base URL with current filters, using %#% as page number placeholder
        $baseArguments = add_query_arg([
            'paged' => '%#%',
            'filter_name' => $this->filters['filter_name'],
            'filter_reference' => $this->filters['filter_reference'],
        ]);

        // Configure pagination arguments
        $args = [
            'base' => $baseArguments,          // URL structure with page placeholder
            'format' => '',                     // No additional format needed
            'current' => $this->page,           // Current active page
            'total' => ceil($this->totalProducts / self::$perPage),  // Total number of pages
        ];

        return paginate_links($args);
    }

    //            Gets            //

    /**
     * Get the fetched WooCommerce products
     * 
     * @return array Array of WC_Product objects
     */
    public function getProducts(): array
    {
        return $this->products;
    }

    /**
     * Get the current page number
     * 
     * @return int Current pagination page (1-indexed)
     */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * Get the active filter values
     * 
     * @return array Associative array with 'filter_name' and 'filter_reference' keys
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Get the total count of products matching filters
     * 
     * @return int Total product count (before pagination)
     */
    public function getTotalProducts(): int
    {
        return $this->totalProducts;
    }

    /**
     * Get the rendered HTML rows for all checked products
     * 
     * @return array Array of HTML strings, one per product checked
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Get the Moloni warehouse ID being used for stock comparisons
     * 
     * @return int Warehouse ID
     */
    public function getWarehouseId(): int
    {
        return $this->warehouseId;
    }

    //            Sets            //

    /**
     * Set the current page for pagination
     * 
     * @param int $page Page number (1-indexed)
     */
    public function setPage(int $page): void
    {
        $this->page = $page;
    }

    /**
     * Set the filter criteria for product search
     * 
     * @param array $filters Expected keys: 'filter_name', 'filter_reference'
     */
    public function setFilters(array $filters): void
    {
        $this->filters = $filters;
    }

    //            Requests            //

    /**
     * Fetch products from WooCommerce database
     * 
     * Uses WooCommerce's wc_get_products() function with:
     * - Only published products
     * - Pagination support
     * - Optional SKU (reference) filter
     * - Optional product name filter
     * - Ordered by ID descending (newest first)
     * 
     * @see https://github.com/woocommerce/woocommerce/wiki/wc_get_products-and-WC_Product_Query
     */
    private function fetchProducts()
    {
        // Base query parameters
        $filters = [
            'status' => ['publish'],           // Only published/active products
            'limit' => self::$perPage,         // Products per page
            'page' => $this->page,             // Current page offset
            'paginate' => true,                // Return paginated result object
            'orderby' => [
                'ID' => 'DESC',                // Newest products first
            ],
        ];

        // Apply optional SKU/reference filter if provided
        if (!empty($this->filters['filter_reference'])) {
            $filters['sku'] = $this->filters['filter_reference'];
        }

        // Apply optional product name filter if provided
        if (!empty($this->filters['filter_name'])) {
            $filters['name'] = $this->filters['filter_name'];
        }

        // Execute WooCommerce product query
        $query = wc_get_products($filters);

        // Extract products array and total count from paginated result
        // Use null coalescing for safety in case of unexpected query result
        $this->products = $query->products ?? [];
        $this->totalProducts = (int)($query->total ?? 0);
    }
}
