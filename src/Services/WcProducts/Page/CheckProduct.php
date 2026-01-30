<?php

namespace Moloni\Services\WcProducts\Page;

use Moloni\Curl;
use Moloni\Enums\Domains;
use Moloni\Helpers\MoloniProduct;
use WC_Product;

/**
 * Class CheckProduct
 * 
 * Service responsible for checking and comparing WooCommerce products against 
 * their corresponding products in the Moloni ERP system.
 * Identifies discrepancies in stock levels, missing products, and configuration issues.
 */
class CheckProduct
{
    /**
     * @var WC_Product The WooCommerce product being checked
     */
    private $product;

    /**
     * @var int The Moloni warehouse ID used for stock comparison
     */
    private $warehouseId;

    /**
     * @var array Collection of product check results with status and metadata
     */
    private $rows = [];

    /**
     * Constructor
     * 
     * @param WC_Product $product The WooCommerce product to check
     * @param int $warehouseId The Moloni warehouse ID for stock lookups
     */
    public function __construct(WC_Product $product, int $warehouseId)
    {
        $this->product = $product;
        $this->warehouseId = $warehouseId;
    }

    /**
     * Execute the product check process
     * Entry point that initiates the recursive product checking
     */
    public function run()
    {
        $this->checkProduct($this->product);
    }

    //            Privates            //

    /**
     * Check a single product and recursively process variations if applicable
     * 
     * Initializes a result row for the product, then either:
     * - For variable products: checks parent and recursively processes all child variations
     * - For simple/variation products: performs standard product comparison
     * 
     * @param WC_Product $product The product to check
     */
    private function checkProduct($product)
    {
        // Initialize a new result row with default values
        $this->rows[] = [
            'tool_show_create_button' => false,      // Whether to show "Create in Moloni" button
            'tool_show_update_stock_button' => false, // Whether to show "Update Stock" button
            'tool_alert_message' => '',               // Warning/info message to display
            'wc_product_id' => $product->get_id(),
            'wc_product_parent_id' => $product->get_parent_id(),
            'wc_product_link' => '',                  // Link to WooCommerce product edit page
            'wc_product_object' => $product,
            'moloni_product_id' => 0,
            'moloni_product_array' => [],             // Full Moloni product data
            'moloni_product_link' => ''               // Link to Moloni product page
        ];

        // Get reference to the last added row for modification
        end($this->rows);
        $row = &$this->rows[key($this->rows)];

        // Handle variable products (products with variations) differently
        if ($product->is_type('variable') && $product->has_child()) {
            $this->checkParentProduct($row, $product);

            // Recursively check each variation
            $children = $product->get_children();

            foreach ($children as $child) {
                $childObject = wc_get_product($child);

                $this->checkProduct($childObject);
            }
        } else {
            // Simple products or individual variations
            $this->checkNormalProduct($row, $product);
        }
    }

    /**
     * Check a parent/variable product
     * 
     * Parent products should not manage stock directly - stock should be 
     * managed at the variation level. This method validates that configuration.
     * 
     * @param array $row Reference to the result row being populated
     * @param WC_Product $product The parent product to check
     */
    private function checkParentProduct(array &$row, WC_Product $product)
    {
        $this->createWcLink($row);

        // Warn if stock management is enabled at parent level (should be per-variation)
        if ($product->managing_stock()) {
            $row['tool_alert_message'] = __('Gestão de stock deve ser efetuada ao nível das variações');
            // Translation: "Stock management should be done at the variation level"

            return;
        }
    }

    /**
     * Check a simple product or product variation against Moloni
     * 
     * Performs the following validations:
     * 1. Ensures the product has a SKU/reference
     * 2. Checks if the product exists in Moloni
     * 3. Compares stock management settings
     * 4. Compares actual stock quantities
     * 
     * @param array $row Reference to the result row being populated
     * @param WC_Product $product The product to check
     */
    private function checkNormalProduct(array &$row, WC_Product $product)
    {
        // Only create WC edit link for standalone products (not variations)
        // Child products/variations don't have their own dedicated page
        if (empty($product->get_parent_id())) {
            $this->createWcLink($row);
        }

        // Validate SKU exists - required for Moloni matching
        if (empty($product->get_sku())) {
            $row['tool_alert_message'] = __('Produto WooCommerce sem referência');
            // Translation: "WooCommerce product without reference"

            return;
        }

        // Query Moloni API to find product by SKU reference
        // 'with_invisible' includes hidden products, 'exact' ensures exact match
        $mlProduct = Curl::simple('products/getByReference', ['reference' => $product->get_sku(), 'with_invisible' => true, 'exact' => 1]);

        // Product not found in Moloni - offer to create it
        if (empty($mlProduct)) {
            $row['tool_show_create_button'] = true;
            $row['tool_alert_message'] = __('Produto não encontrado na conta Moloni');
            // Translation: "Product not found in Moloni account"

            return;
        }

        // API returns array, get first (and should be only) result
        $mlProduct = $mlProduct[0];

        $row['moloni_product_id'] = $mlProduct['product_id'];
        $row['moloni_product_array'] = $mlProduct;

        $this->createMoloniLink($row);

        // Check if stock management settings match between systems
        // has_stock (Moloni) vs managing_stock (WooCommerce)
        if (!empty($mlProduct['has_stock']) !== $product->managing_stock()) {
            $row['tool_alert_message'] = __('Estado do controlo de stock diferente');
            // Translation: "Stock control status differs"

            return;
        }

        // If stock management is enabled, compare actual quantities
        if (!empty($mlProduct['has_stock'])) {
            $wcStock = (int)$product->get_stock_quantity();
            // Get Moloni stock for the specific warehouse
            $moloniStock = (int)MoloniProduct::parseMoloniStock($mlProduct, $this->warehouseId);

            // Stock mismatch detected - offer to sync
            if ($wcStock !== $moloniStock) {
                $row['tool_show_update_stock_button'] = true;
                $row['tool_alert_message'] = __('Stock não coincide no WooCommerce e Moloni');
                // Translation: "Stock does not match in WooCommerce and Moloni"
                $row['tool_alert_message'] .= " (Moloni: $moloniStock | WooCommerce: $wcStock)";

                return;
            }
        }
    }

    //            Gets            //

    /**
     * Get all product check results as raw array data
     * 
     * @return array Array of result rows with all product check data
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Get product check results rendered as HTML
     * 
     * Uses output buffering to capture the included template output.
     * Each row is rendered using the ProductRow.php template.
     * 
     * @return string Rendered HTML string of all product rows
     */
    public function getRowsHtml(): string
    {
        ob_start();

        foreach ($this->rows as $row) {
            include MOLONI_TEMPLATE_DIR . 'Blocks/WcProducts/ProductRow.php';
        }

        // Return buffered content, or empty string if buffer was empty
        return ob_get_clean() ?: '';
    }

    //            Auxiliary            //

    /**
     * Generate the Moloni product edit page URL
     * 
     * Constructs the full URL to edit the product in the Moloni web interface.
     * URL format: {homepage}/{company_slug}/Artigos/showUpdate/{product_id}/{category_id}
     * 
     * @param array $row Reference to the result row to populate with the link
     */
    private function createMoloniLink(array &$row)
    {
        $row['moloni_product_link'] = Domains::HOMEPAGE;

        // Use company slug if defined, otherwise default to 'ac'
        if (defined('COMPANY_SLUG')) {
            $row['moloni_product_link'] .= COMPANY_SLUG;
        } else {
            $row['moloni_product_link'] .= 'ac';
        }

        // Append the product edit route with product and category IDs
        $row['moloni_product_link'] .= '/Artigos/showUpdate/';
        $row['moloni_product_link'] .= $row['moloni_product_array']['product_id'];
        $row['moloni_product_link'] .= '/';
        $row['moloni_product_link'] .= $row['moloni_product_array']['category_id'];
    }

    /**
     * Generate the WooCommerce product edit page URL
     * 
     * Creates the WordPress admin URL for editing the product.
     * 
     * @param array $row Reference to the result row to populate with the link
     */
    private function createWcLink(array &$row)
    {
        $wcProductId = $row['wc_product_object']->get_id();

        // Use WordPress admin_url() for proper URL generation
        $row['wc_product_link'] = admin_url("post.php?post=$wcProductId&action=edit");
    }
}
