<?php

namespace Moloni\Services\WcProducts;

use WC_Tax;
use WC_Product;
use Moloni\Curl;
use Moloni\Storage;
use Moloni\Enums\Boolean;
use Moloni\Enums\TaxType;
use Moloni\Enums\SaftType;
use Moloni\Helpers\MoloniProduct;
use Moloni\Exceptions\APIException;

/**
 * CreateProduct Service
 * 
 * This class handles the creation of WooCommerce products from Moloni product data.
 * It maps Moloni API product fields to WooCommerce product fields.
 * 
 * Moloni API Reference: https://www.moloni.pt/dev/products/products/getOne/
 * 
 * Moloni Product Structure (from API):
 * - product_id: int - Unique identifier in Moloni
 * - name: string - Product name (Designação)
 * - reference: string - Product reference/SKU (Referência)
 * - ean: string - EAN barcode
 * - price: float - Base price without tax
 * - summary: string - Short description (Resumo)
 * - notes: string - Full description/notes
 * - has_stock: int - Whether product has stock management (0 or 1)
 * - stock: float - Current stock quantity
 * - minimum_stock: float - Minimum stock alert level
 * - visibility_id: int - Product visibility (0 = hidden, 1 = visible)
 * - taxes: array - Tax information
 * - category_id: int - Product category ID
 */
class CreateProduct extends ImportService
{
    /**
     * Constructor
     * 
     * @param array $moloniProduct - Product data from Moloni API (products/getOne endpoint)
     *                               Contains all product fields returned by the API
     */
    public function __construct(array $moloniProduct)
    {
        $this->moloniProduct = $moloniProduct;
        $this->wcProduct = new WC_Product(); // Creates a new WooCommerce simple product
    }

    //            Public's            //

    /**
     * Execute the product creation process
     * 
     * Maps all Moloni product fields to WooCommerce product fields
     * and saves the product to the WooCommerce database.
     * 
     * Field Mapping:
     * - Moloni 'name' → WC Product Title
     * - Moloni 'reference' → WC SKU
     * - Moloni 'price' → WC Regular Price (with tax calculation if needed)
     * - Moloni 'taxes' → WC Tax Status and Tax Class
     * - Moloni 'category_id' → WC Product Categories
     * - Moloni 'summary' → WC Short Description
     * - Moloni 'notes' → WC Full Description
     * - Moloni 'visibility_id' → WC Catalog Visibility
     * - Moloni 'has_stock'/'stock' → WC Stock Management
     * - Moloni 'ean' → WC Meta '_barcode'
     * - Moloni 'getLastCostPrice' → WC COGS value (if enabled)
     */
    public function run()
    {
        $this->setName();
        $this->setReference();
        $this->setPrice();
        $this->setCostPrice();
        $this->enforceMinimumPrice();
        $this->setTaxes();
        $this->setCategories();
        $this->setDescription();
        $this->setVisibility();
        $this->setStock();
        $this->setEan();
        $this->setImage();

        $this->wcProduct->save(); // Persist product to database
    }

    /**
     * Log the product creation for debugging/auditing
     * 
     * Records the Moloni product ID, reference, and WooCommerce product ID
     */
    public function saveLog()
    {
        $msg = str_replace('{0}', $this->wcProduct->get_sku(), __('Produto criado no WooCommerce ({0})'));

        Storage::$LOGGER->info($msg, [
            'tag' => 'service:wcproduct:create',
            'ml_id' => $this->moloniProduct['product_id'],      // Moloni product_id
            'ml_reference' => $this->moloniProduct['reference'], // Moloni reference (SKU)
            'wc_id' => $this->wcProduct->get_id(),              // WooCommerce product ID
        ]);
    }

    //            Sets            //

    /**
     * Set product name from Moloni 'name' field (Designação)
     * 
     * Moloni API field: name (string)
     * Maps to: WooCommerce Product Title
     */
    private function setName()
    {
        $this->wcProduct->set_name($this->moloniProduct['name'] ?? '');
    }

    /**
     * Set product SKU from Moloni 'reference' field (Referência)
     * 
     * Moloni API field: reference (string) - Must be unique in Moloni
     * Maps to: WooCommerce SKU
     * 
     * Note: The reference field in Moloni must be unique and is used
     * as the primary identifier for product matching between systems.
     */
    private function setReference()
    {
        $this->wcProduct->set_sku($this->moloniProduct['reference'] ?? '');
    }

    /**
     * Set product price from Moloni 'price' field
     * 
     * Moloni API field: price (float) - Price WITHOUT tax
     * Maps to: WooCommerce Regular Price
     * 
     * Important: Moloni stores prices WITHOUT tax (net price).
     * If WooCommerce is configured to display prices including tax
     * (wc_prices_include_tax() returns true), this method adds
     * the tax values to the base price.
     * 
     * Moloni taxes structure:
     * taxes: [
     *   {
     *     product_id: int,
     *     tax_id: int,
     *     value: float,      // Tax percentage (e.g., 23 for 23%)
     *     order: int,
     *     cumulative: int,
     *     tax: {
     *       tax_id: int,
     *       type: int,       // TaxType - 1=percentage, 2=fixed
     *       saft_type: int,  // SaftType - 1=IVA, 2=IS, etc.
     *       name: string,
     *       value: float,
     *       fiscal_zone: string
     *     }
     *   }
     * ]
     */
    private function setPrice()
    {
        $price = $this->moloniProduct['price'];

        // If WooCommerce shows prices WITH tax, add tax to the Moloni net price
        if (wc_prices_include_tax() && !empty($this->moloniProduct['taxes'])) {
            foreach ($this->moloniProduct['taxes'] as $tax) {
                // Add each tax value to the price
                // Note: This assumes 'value' is the percentage, but the calculation
                // here just adds it - this might be a bug, should multiply by percentage
                $price += (float)$tax['value'];
            }
        }

        $this->wcProduct->set_regular_price($price);
    }

    /** @var float|null Cached cost price fetched from Moloni */
    private $lastFetchedCostPrice = null;

    /**
     * Set product cost price from Moloni via getLastCostPrice API
     *
     * Fetches the last known cost price from Moloni and stores it in
     * WooCommerce using the native COGS field (if enabled) or custom meta.
     *
     * Guarded by the MOLONI_COST_PRICE_SYNC setting.
     * Failures are silently logged — cost sync should not block product creation.
     */
    private function setCostPrice()
    {
        if (!defined('MOLONI_COST_PRICE_SYNC') || (int)MOLONI_COST_PRICE_SYNC !== 1) {
            return;
        }

        $this->lastFetchedCostPrice = MoloniProduct::fetchCostPrice((int)$this->moloniProduct['product_id']);

        MoloniProduct::setCostPriceOnWcProduct($this->wcProduct, $this->lastFetchedCostPrice);
    }

    /**
     * Enforce minimum sale price based on cost price and configured margin
     *
     * If the current WC regular price is below the calculated minimum
     * (cost × margin × VAT), auto-corrects it and warns the admin.
     * Must be called after setCostPrice() so cost data is available.
     */
    private function enforceMinimumPrice()
    {
        if ($this->lastFetchedCostPrice === null || $this->lastFetchedCostPrice <= 0) {
            return;
        }

        MoloniProduct::enforceMinimumPrice(
            $this->wcProduct,
            $this->lastFetchedCostPrice,
            $this->moloniProduct['taxes'] ?? [],
            $this->moloniProduct['reference'] ?? ''
        );
    }

    /**
     * Set product tax status and tax class from Moloni 'taxes' array
     * 
     * Moloni API field: taxes (array)
     * Maps to: WooCommerce Tax Status and Tax Class
     * 
     * This method:
     * 1. If no taxes → sets tax status to 'none'
     * 2. If product exists → only sets status to 'taxable' (preserves existing class)
     * 3. If multiple taxes → only sets status (WooCommerce doesn't support multiple tax classes)
     * 4. For single IVA percentage tax → tries to find matching WooCommerce tax class
     * 
     * Moloni Tax Types (SaftType enum):
     * - IVA (1): Standard VAT
     * - IS (2): Stamp Tax
     * - Other types for specific Portuguese tax requirements
     * 
     * Moloni Tax Calculation Types (TaxType enum):
     * - PERCENTAGE (1): Tax is a percentage
     * - FIXED (2): Tax is a fixed amount
     */
    private function setTaxes()
    {
        // No taxes in Moloni = no tax in WooCommerce
        if (empty($this->moloniProduct['taxes'])) {
            $this->wcProduct->set_tax_status('none');
            return;
        }

        // Product has taxes - mark as taxable
        $this->wcProduct->set_tax_status('taxable');

        // If product already exists in WooCommerce, don't change tax class
        // (preserves admin customizations)
        if ($this->wcProduct->exists()) {
            return;
        }

        // WooCommerce only supports one tax class per product
        // If Moloni has multiple taxes, skip tax class mapping
        if (count($this->moloniProduct['taxes']) > 1) {
            return;
        }

        // Get the first (and only) tax
        $moloniTax = $this->moloniProduct['taxes'][0]['tax'] ?? [];

        if (empty($moloniTax)) {
            return;
        }

        // Only map IVA (VAT) percentage taxes
        // Skip Stamp Tax (IS), fixed amounts, etc.
        if (
            (int)$moloniTax['saft_type'] !== SaftType::IVA ||      // Must be IVA type
            (int)$moloniTax['type'] !== TaxType::PERCENTAGE        // Must be percentage
        ) {
            return;
        }

        // Get all WooCommerce tax classes (standard, reduced, zero, etc.)
        $taxClasses = wc_get_product_tax_class_options() ?? [];

        if (empty($taxClasses)) {
            return;
        }

        // Try to find a matching WooCommerce tax class
        foreach ($taxClasses as $taxClass => $taxClassLabel) {
            // Get tax rates for this class in the Moloni fiscal zone (e.g., 'PT')
            $taxRates = WC_Tax::find_rates([
                'country' => $moloniTax['fiscal_zone'],  // e.g., 'PT' for Portugal
                'tax_class' => $taxClass
            ]);

            foreach ($taxRates as $taxRate) {
                // Compare rates with precision (multiply by 100000 to avoid float issues)
                // e.g., 23% = 23.00000 → 2300000
                if ((int)($taxRate['rate'] * 100000) !== (int)($moloniTax['value'] * 100000)) {
                    continue;
                }

                // Found matching tax class - set it and return
                $this->wcProduct->set_tax_class($taxClass);
                return;
            }
        }
    }

    /**
     * Set product categories from Moloni category tree
     * 
     * Moloni API endpoint: products/getCategoryTree
     * Maps to: WooCommerce Product Categories
     * 
     * This method:
     * 1. Fetches the full category hierarchy from Moloni API
     * 2. Creates WooCommerce categories if they don't exist
     * 3. Maintains parent-child relationships
     * 4. Assigns all categories in the tree to the product
     * 
     * Moloni Category Structure (from productCategories/getAll):
     * - category_id: int
     * - parent_id: int (0 for top-level)
     * - name: string
     * - description: string
     * - num_categories: int (number of subcategories)
     * - num_products: int (number of products)
     * 
     * The getCategoryTree endpoint returns the full path from root to product's category.
     */
    private function setCategories()
    {
        try {
            // Fetch complete category tree for this product
            // Returns array of categories from root to leaf
            $moloniCategoryTree = Curl::simple('products/getCategoryTree', [
                'product_id' => $this->moloniProduct['product_id'],
                'with_invisible' => true  // Include hidden categories
            ]);
        } catch (APIException $e) {
            $moloniCategoryTree = [];
        }

        $categoriesIds = [];

        if (!empty($moloniCategoryTree)) {
            $parentId = 0; // Start from root (no parent)

            // Iterate through category tree (root → leaf)
            foreach ($moloniCategoryTree as $moloniCategory) {
                $name = $moloniCategory['name'];
                
                // Check if category already exists in WooCommerce
                $existingTerm = term_exists($name, 'product_cat', $parentId);

                if (!$existingTerm) {
                    // Create new WooCommerce category with correct parent
                    $newTerm = wp_insert_term($name, 'product_cat', ['parent' => $parentId]);
                    $parentId = $newTerm['term_id'];

                    // Add to beginning of array (will reverse the order)
                    array_unshift($categoriesIds, $newTerm['term_id']);
                } else {
                    // Category exists - use it as parent for next level
                    $parentId = $existingTerm['term_id'];

                    array_unshift($categoriesIds, $existingTerm['term_id']);
                }
            }
        }

        // Assign all categories in the tree to the product
        if (!empty($categoriesIds)) {
            $this->wcProduct->set_category_ids($categoriesIds);
        }
    }

    /**
     * Set product stock management from Moloni stock fields
     * 
     * Moloni API fields:
     * - has_stock: int (0 or 1) - Whether product tracks stock
     * - stock: float - Current stock quantity
     * - minimum_stock: float - Low stock alert threshold
     * 
     * Maps to:
     * - WooCommerce Manage Stock setting
     * - WooCommerce Stock Quantity
     * - WooCommerce Low Stock Amount
     * 
     * Note: Uses MOLONI_STOCK_SYNC constant to determine which warehouse
     * stock to use (if multiple warehouses exist in Moloni).
     * 
     * Moloni warehouses structure (from products/getOne):
     * warehouses: [
     *   {
     *     product_id: int,
     *     warehouse_id: int,
     *     stock: float
     *   }
     * ]
     */
    private function setStock()
    {
        $hasStock = (bool)$this->moloniProduct['has_stock'];

        // Enable/disable WooCommerce stock management
        $this->wcProduct->set_manage_stock($hasStock);

        if ($hasStock) {
            // Parse stock from Moloni (may filter by warehouse based on settings)
            $stock = MoloniProduct::parseMoloniStock(
                $this->moloniProduct,
                defined('MOLONI_STOCK_SYNC') ? (int)MOLONI_STOCK_SYNC : 1
            );

            $this->wcProduct->set_stock_quantity($stock);
            
            // Set low stock threshold for WooCommerce notifications
            $this->wcProduct->set_low_stock_amount($this->moloniProduct['minimum_stock']);
        }
    }

    /**
     * Set product descriptions from Moloni 'summary' and 'notes' fields
     * 
     * Moloni API fields:
     * - summary: string - Brief product description (Resumo)
     * - notes: string - Full product description/notes
     * 
     * Maps to:
     * - summary → WooCommerce Short Description (shown on product listing)
     * - notes → WooCommerce Full Description (shown on product page)
     */
    private function setDescription()
    {
        $this->wcProduct->set_short_description($this->moloniProduct['summary'] ?? '');
        $this->wcProduct->set_description($this->moloniProduct['notes'] ?? '');
    }

    /**
     * Set product visibility from Moloni 'visibility_id' field
     * 
     * Moloni API field: visibility_id (int)
     * - 0 (Boolean::NO): Product is hidden/inactive
     * - 1 (Boolean::YES): Product is visible/active
     * 
     * Maps to WooCommerce Catalog Visibility:
     * - 'visible': Show in catalog and search
     * - 'hidden': Hide from catalog and search
     */
    private function setVisibility()
    {
        $this->wcProduct->set_catalog_visibility(
            (int)$this->moloniProduct['visibility_id'] === Boolean::YES ? 'visible' : 'hidden'
        );
    }

    /**
     * Set product EAN/barcode from Moloni 'ean' field
     * 
     * Moloni API field: ean (string) - EAN barcode
     * Maps to: WooCommerce custom meta '_barcode'
     * 
     * Note: WooCommerce doesn't have a native EAN field, so it's stored
     * as custom product meta. This can be used by barcode scanner plugins
     * or for inventory management.
     * 
     * The official Moloni plugin also checks for '_global_unique_id' (GTIN)
     * which was added in WooCommerce for product identification.
     */
    private function setEan()
    {
        $this->wcProduct->add_meta_data('_barcode', $this->moloniProduct['ean']);
    }
    /**
 * Set product image from Moloni 'image' field
 * 
 * Moloni API field: image (string) - URL to product image
 * Maps to: WooCommerce Featured Image (Product Thumbnail)
 * 
 * Moloni products/getOne response includes:
 * - image: string - Full URL to the product image hosted on Moloni servers
 *                   e.g., "https://www.moloni.pt/_imagens/[...]/image.jpg"
 * 
 * This method:
 * 1. Checks if the product already has an image (skip if exists to avoid duplicates)
 * 2. Downloads the image from Moloni's servers
 * 3. Uploads it to WordPress Media Library
 * 4. Sets it as the product's featured image (thumbnail)
 * 
 * Note: Images are stored on Moloni's CDN and referenced by URL in the API response.
 * The URL is publicly accessible and can be downloaded without authentication.
 */
/**
 * Set product image from Moloni 'image' field (with update support)
 * 
 * This version checks if the Moloni image URL has changed before re-downloading.
 */
private function setImage()
{
    if (empty($this->moloniProduct['image'])) {
        return;
    }

    $imageUrl = $this->moloniProduct['image'];
    $currentImageId = $this->wcProduct->get_image_id();

    // Check if we already have this exact image
    if ($currentImageId) {
        $existingUrl = get_post_meta($currentImageId, '_moloni_image_url', true);
        
        // Same image URL - no need to re-download
        if ($existingUrl === $imageUrl) {
            return;
        }
    }

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    try {
        $tempFile = download_url($imageUrl);

        if (is_wp_error($tempFile)) {
            return;
        }

        $filename = basename(parse_url($imageUrl, PHP_URL_PATH));
        if (empty($filename) || $filename === '/') {
            $filename = sanitize_file_name($this->moloniProduct['reference'] . '.jpg');
        }

        $fileArray = [
            'name'     => $filename,
            'tmp_name' => $tempFile,
        ];

        $attachmentId = media_handle_sideload($fileArray, 0);

        if (is_wp_error($attachmentId)) {
            @unlink($tempFile);
            return;
        }

        // Store Moloni image URL for future comparison
        update_post_meta($attachmentId, '_moloni_image_url', $imageUrl);

        // Optionally delete the old image attachment to save space
        // if ($currentImageId) {
        //     wp_delete_attachment($currentImageId, true);
        // }

        $this->wcProduct->set_image_id($attachmentId);

    } catch (\Exception $e) {
        // Log error silently
    }
}
}
