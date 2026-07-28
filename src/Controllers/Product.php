<?php

namespace Moloni\Controllers;

use Moloni\Curl;
use Moloni\Exceptions\APIException;
use Moloni\Exceptions\GenericException;
use Moloni\Helpers\SyncFields;
use Moloni\Storage;
use Moloni\Tools;
use WC_Product;
use WC_Tax;

/**
 * Class Product
 * @package Moloni\Controllers
 */
class Product
{

    /** @var WC_Product */
    private $product;

    /** @var WC_Product|false */
    private $productParent = false;

    private $moloniProduct = [];

    public $product_id;
    public $category_id;
    private $type;
    public $reference;
    public $name;
    public $summary = '';
    private $ean = '';
    public $price;
    private $unit_id;
    public $has_stock;
    public $stock;
    private $warehouse_id = 0;
    private $at_product_category = 'M';
    private $exemption_reason;
    public $taxes;
    public $visibility_id = 1;
    public $fiscalZone;

    public $composition_type = 0;
    /** @var false|array */
    public $child_products = false;

    /** @var array Moloni properties payload ([{property_id, value}]) built from WC attributes */
    private $properties = [];

    /** @var string Meta key holding the hash of the last image uploaded to Moloni */
    private const IMAGE_HASH_META = '_moloni_image_hash';

    /** @var int Refuse to upload images larger than this (filterable) */
    private const IMAGE_MAX_BYTES = 2097152; // 2 MB

    /** @var int Moloni "resumo" is a short text — cap what we send */
    private const SUMMARY_MAX_LENGTH = 5000;

    /**
     * Product constructor.
     * @param WC_Product $product
     */
    public function __construct($product)
    {
        $this->product = $product;

        $parentId = $this->product->get_parent_id();

        if ($parentId > 0) {
            $this->productParent = wc_get_product($parentId);
        }
    }

    /**
     * Loads a product
     *
     * @throws APIException
     */
    public function loadByReference()
    {
        $this->setReference();

        $searchProduct = Curl::simple('products/getByReference', ['reference' => $this->reference, 'with_invisible' => true, 'exact' => 1]);

        if (!empty($searchProduct) && isset($searchProduct[0]['product_id'])) {
            $this->moloniProduct = $searchProduct[0];

            $this->product_id = $this->moloniProduct['product_id'];
            $this->name = $this->moloniProduct['name'];
            $this->summary = $this->moloniProduct['summary'];
            $this->category_id = $this->moloniProduct['category_id'];
            $this->has_stock = $this->moloniProduct['has_stock'];
            $this->stock = $this->moloniProduct['stock'];
            $this->warehouse_id = $this->moloniProduct['warehouse_id'];
            $this->price = $this->moloniProduct['price'];
            $this->child_products = $this->moloniProduct['child_products'];
            $this->composition_type = $this->moloniProduct['composition_type'];
            $this->taxes = $this->moloniProduct['taxes'];
            $this->visibility_id = $this->moloniProduct['visibility_id'];

            return $this;
        }

        return false;
    }

    /**
     * Create a product based on a WooCommerce Product
     * @return Product
     *
     * @throws APIException
     * @throws GenericException
     */
    public function create()
    {
        $this->setProduct();

        $props = $this->mapPropsToValues();
        $props = apply_filters('moloni_before_moloni_product_insert', $props);

        $insert = Curl::simple('products/insert', $props);

        if (isset($insert['product_id'])) {
            $this->product_id = $insert['product_id'];

            Storage::$LOGGER->info(
                str_replace('{0}', $this->reference, __('Produto criado no Moloni ({0})')),
                [
                    'product_id' => $this->product_id,
                    'props' => $props
                ]
            );

            $this->syncImage();

            return $this;
        }

        throw new GenericException(__('Erro ao inserir o produto') . $this->name);
    }

    /**
     * Create a product based on a WooCommerce Product
     *
     * @return $this
     *
     * @throws APIException
     * @throws GenericException
     */
    public function update(): Product
    {
        $this->setProduct();

        $props = $this->mapPropsToValues();
        $props = apply_filters('moloni_before_moloni_product_update', $props);

        if (!$this->needsToUpdateProduct($props)) {
            // Product data is untouched, but the image may still have changed —
            // syncImage() has its own change detection (file hash) and is a no-op
            // when disabled or unchanged, so this costs no API call in the common case.
            $this->syncImage();

            return $this;
        }

        $update = Curl::simple('products/update', $props);

        if (isset($update['product_id'])) {
            $this->product_id = $update['product_id'];

            Storage::$LOGGER->info(
                str_replace('{0}', $this->reference, __('Produto atualizado no Moloni ({0})')),
                [
                    'product_id' => $this->product_id,
                    'props' => $props
                ]
            );

            $this->syncImage();

            return $this;
        }

        throw new GenericException(__('Erro ao atualizar o produto') . $this->name);
    }

    //          Gets          //

    /**
     * @return bool|int
     */
    public function getProductId()
    {
        return $this->product_id ?: false;
    }

    public function getDefaultTax()
    {
        $moloniTax = Tools::getTaxFromRate(-1);

        $tax = [];
        $tax['tax_id'] = $moloniTax['tax_id'];
        $tax['value'] = $moloniTax['value'];
        $tax['order'] = 1;
        $tax['cumulative'] = '0';

        if ((float)$moloniTax['value'] > 0) {
            return $tax;
        }

        return [];
    }

    //          Privates          //

    /**
     * @throws APIException
     * @throws GenericException
     */
    private function setProduct()
    {
        $this
            ->setReference()
            ->setCategory()
            ->setType()
            ->setWarehouse()
            ->setName()
            ->setPrice()
            ->setEan()
            ->setSummary()
            ->setProperties()
            ->setUnitId()
            ->setTaxes();
    }

    //          Sets          //

    /**
     * @return $this
     */
    private function setReference()
    {
        $this->reference = $this->product->get_sku();

        if (empty($this->reference)) {
            $this->reference = Tools::createReferenceFromString($this->product->get_name(), $this->product->get_id());
        }

        $this->reference = mb_substr($this->reference, 0, 30);

        return $this;
    }

    /**
     * @return Product
     *
     * @throws APIException
     * @throws GenericException
     */
    private function setCategory()
    {
        $categories = $this->product->get_category_ids();

        if (empty($categories) && $this->productParent) {
            $categories = $this->productParent->get_category_ids();
        }

        // Get the deepest category from all the trees
        if (!empty($categories) && is_array($categories)) {
            $categoryTree = [];

            foreach ($categories as $category) {
                $parents = get_ancestors($category, 'product_cat');
                $parents = array_reverse($parents);
                $parents[] = $category;

                if (is_array($parents) && count($parents) > count($categoryTree)) {
                    $categoryTree = $parents;
                }
            }

            $this->category_id = 0;
            foreach ($categoryTree as $categoryId) {
                $category = get_term_by('id', $categoryId, 'product_cat');
                if (!empty($category->name)) {
                    $categoryObj = new ProductCategory($category->name, $this->category_id);

                    if (!$categoryObj->loadByName()) {
                        $categoryObj->create();
                    }

                    $this->category_id = $categoryObj->category_id;
                }
            }
        }

        if ((int)$this->category_id === 0) {
            $categoryObj = new ProductCategory('Loja Online', 0);

            if (!$categoryObj->loadByName()) {
                $categoryObj->create();
            }

            $this->category_id = $categoryObj->category_id;
        }

        return $this;
    }

    /**
     * Available types:
     * 1 Product
     * 2 Service
     * 3 Other
     * @return $this
     */
    private function setType()
    {
        // If the product is virtual or downloadable then its a service
        if ($this->product->is_virtual() || $this->product->is_downloadable()) {
            $this->type = 2;
            $this->has_stock = 0;
        } else {
            $this->type = 1;
            $this->has_stock = $this->product->managing_stock() ? 1 : 0;
            $this->stock = (float)$this->product->get_stock_quantity();
        }

        return $this;
    }

    /**
     * Set the name of the product
     * @return $this
     */
    private function setName()
    {
        $this->name = strip_tags($this->product->get_name());
        return $this;
    }

    /**
     * Set the price of the product.
     *
     * When the "price" field sync (WooCommerce → Moloni) is disabled and the
     * product already exists in Moloni, the price loaded from Moloni is kept —
     * the Moloni API REQUIRES price in update payloads, so it cannot simply be
     * omitted; re-sending the current Moloni value leaves it untouched.
     *
     * @return $this
     */
    private function setPrice()
    {
        if (!SyncFields::wmPrice() && !empty($this->product_id)) {
            return $this; // keep the Moloni price loaded by loadByReference()
        }

        $this->price = (float)wc_get_price_excluding_tax($this->product);

        if ((float)$this->price === 0 && $this->productParent) {
            $this->price = (float)wc_get_price_excluding_tax($this->productParent);
        }

        return $this;
    }

    /**
     * @return $this
     */
    private function setEan()
    {
        if (!SyncFields::wmEan()) {
            return $this; // EAN sync (WooCommerce → Moloni) disabled — field omitted
        }

        foreach (['barcode', '_ywbc_barcode_display_value'] as $key) {
            $metaBarcode = $this->product->get_meta($key, true);

            if (!empty($metaBarcode)) {
                $this->ean = $metaBarcode;
                return $this;
            }
        }

        if (method_exists($this->product, 'get_global_unique_id')) {
            $metaBarcode = $this->product->get_global_unique_id();
        } else {
            $metaBarcode = $this->product->get_meta('_global_unique_id', true);
        }

        if (!empty($metaBarcode)) {
            $this->ean = $metaBarcode;
        }

        return $this;
    }

    /**
     * Set the Moloni "resumo" (summary) from the WooCommerce short description,
     * when that field sync is enabled. Plain text, length-capped. An EMPTY short
     * description never clears an existing Moloni summary (data is never erased
     * implicitly). When disabled, the summary loaded from Moloni round-trips
     * untouched — the previous behaviour.
     *
     * @return $this
     */
    private function setSummary()
    {
        if (!SyncFields::wmSummary()) {
            return $this;
        }

        $short = (string)$this->product->get_short_description();

        if ($short === '' && $this->productParent) {
            $short = (string)$this->productParent->get_short_description();
        }

        $summary = trim(wp_strip_all_tags($short));

        if ($summary === '') {
            return $this; // nothing to send — keep the existing Moloni resumo
        }

        $this->summary = mb_substr($summary, 0, self::SUMMARY_MAX_LENGTH);

        return $this;
    }

    /**
     * Map WooCommerce product attributes to Moloni product properties
     * ({property_id, value}), when that field sync is enabled.
     *
     * - Parent/simple products: each WC_Product_Attribute → one property; term
     *   names (taxonomy) or options (custom) joined with ", ".
     * - Variations: the defining attribute pairs (e.g. Cor → Azul).
     *
     * Missing Moloni properties are created by title (one productProperties/getAll
     * per request + one insert per genuinely new property — see ProductProperty).
     * Values are plain-text and length-capped. Products without attributes send
     * nothing, so existing Moloni properties are never cleared implicitly.
     *
     * @return $this
     */
    private function setProperties()
    {
        $this->properties = [];

        if (!SyncFields::wmProperties()) {
            return $this;
        }

        $attributes = $this->product->get_attributes();

        if (empty($attributes) || !is_array($attributes)) {
            return $this;
        }

        foreach ($attributes as $key => $attribute) {
            if ($attribute instanceof \WC_Product_Attribute) {
                $label = wc_attribute_label($attribute->get_name(), $this->product);

                if ($attribute->is_taxonomy()) {
                    $terms = wc_get_product_terms($this->product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                    $value = is_array($terms) ? implode(', ', $terms) : '';
                } else {
                    $options = $attribute->get_options();
                    $value = is_array($options) ? implode(', ', $options) : '';
                }
            } else {
                // Variation attribute pair: $key = taxonomy/custom name, $attribute = slug/value
                $label = wc_attribute_label((string)$key, $this->product);
                $value = (string)$attribute;

                if (taxonomy_exists((string)$key)) {
                    $term = get_term_by('slug', $value, (string)$key);

                    if ($term && !is_wp_error($term)) {
                        $value = $term->name;
                    }
                }
            }

            $label = trim(wp_strip_all_tags((string)$label));
            $value = trim(wp_strip_all_tags($value));

            if ($label === '' || $value === '') {
                continue;
            }

            $propertyId = ProductProperty::resolveIdByTitle($label);

            if ($propertyId === null) {
                continue; // could not resolve/create — skip this attribute, keep the rest
            }

            $this->properties[] = [
                'property_id' => $propertyId,
                'value' => mb_substr($value, 0, 100),
            ];
        }

        return $this;
    }

    /**
     * Set measurement unit
     *
     * @throws GenericException
     */
    private function setUnitId(): Product
    {
        if (defined('MEASURE_UNIT')) {
            $this->unit_id = MEASURE_UNIT;
        } else {
            throw new GenericException(__('Unidade de medida não definida!'));
        }

        return $this;
    }

    /**
     * Sets the taxes of a product or its exemption reason
     *
     * @throws APIException
     */
    private function setTaxes()
    {
        $hasIVA = false;
        $this->taxes = [];

        if ($this->product->get_tax_status() === 'taxable') {
            // Get taxes based on a tax class of a product
            // If the tax class is empty it means the products uses the shop default
            $productTaxes = $this->product->get_tax_class();
            $taxRates = WC_Tax::get_base_tax_rates($productTaxes);

            if (empty($this->fiscalZone)) {
                $company = Curl::simple('companies/getOne', []);
                $this->fiscalZone = strtoupper($company['country']['iso_3166_1']);
            }

            foreach ($taxRates as $order => $taxRate) {
                $moloniTax = Tools::getTaxFromRate((float)$taxRate['rate'], $this->fiscalZone);

                if (!$moloniTax) {
                    continue;
                }

                $tax = [];
                $tax['tax_id'] = $moloniTax['tax_id'];
                $tax['value'] = $taxRate['rate'];
                $tax['order'] = $order;
                $tax['cumulative'] = '0';

                if ((float)$taxRate['rate'] > 0) {
                    $this->taxes[] = $tax;

                    if ((int)$moloniTax['saft_type'] === 1) {
                        $hasIVA = true;
                    }
                }
            }
        }

        if (!$hasIVA) {
            if (defined('EXEMPTION_REASON') && EXEMPTION_REASON !== '') {
                $this->exemption_reason = defined('EXEMPTION_REASON') ? EXEMPTION_REASON : '';
            } else {
                $this->taxes[] = $this->getDefaultTax();
            }
        }

        return $this;
    }

    /**
     * Set default warehouse
     *
     * @return $this
     */
    private function setWarehouse()
    {
        if ($this->warehouse_id > 0) {
            return $this;
        }

        if (defined('MOLONI_PRODUCT_WAREHOUSE') && (int)MOLONI_PRODUCT_WAREHOUSE > 0) {
            $this->warehouse_id = (int)MOLONI_PRODUCT_WAREHOUSE;
        }

        return $this;
    }

    //          Auxiliary          //

    /**
     * Map this object properties to an array to insert/update a moloni document
     * @return array
     */
    private function mapPropsToValues()
    {
        $isNewProduct = empty($this->product_id);

        $values = [];
        $values['category_id'] = $this->category_id;
        $values['type'] = $this->type;
        $values['reference'] = $this->reference;
        $values['name'] = $this->name;
        $values['summary'] = $this->summary;

        if (!empty($this->ean)) {
            /** EAN is created from an external plugin so avoid to update it */
            $values['ean'] = $this->ean;
        }

        if (!empty($this->properties)) {
            // Only sent when the properties field sync is on AND the WC product
            // has attributes — an empty array would clear the Moloni properties.
            $values['properties'] = $this->properties;
        }

        $values['price'] = $this->price;
        $values['unit_id'] = $this->unit_id;
        $values['has_stock'] = $this->has_stock;
        $values['exemption_reason'] = $this->exemption_reason;
        $values['taxes'] = $this->taxes;
        $values['visibility_id'] = $this->visibility_id;
        $values['warehouse_id'] = $this->warehouse_id;

        if ($isNewProduct) {
            $values['at_product_category'] = $this->at_product_category;
        } else {
            $values['product_id'] = $this->product_id;
        }

        if ($this->warehouse_id > 0) {
            $values['warehouses'][] = [
                'warehouse_id' => $this->warehouse_id,
                'stock' => $this->stock,
            ];
        } else {
            $values['stock'] = $this->stock;
        }

        return $values;
    }

    /**
     * Compare the properties payload we are about to send with the ones Moloni
     * currently has. An EMPTY outgoing payload never forces an update (we omit
     * the field rather than clear data), so it also never loops.
     *
     * @param array $sent    Outgoing [{property_id, value}] payload
     * @param array $current Moloni product 'properties' array
     *
     * @return bool
     */
    private static function propertiesDiffer(array $sent, array $current): bool
    {
        if (empty($sent)) {
            return false;
        }

        $currentMap = [];

        foreach ($current as $property) {
            if (!is_array($property)) {
                continue;
            }

            $id = (int)($property['property_id'] ?? ($property['property']['property_id'] ?? 0));

            if ($id > 0) {
                $currentMap[$id] = trim((string)($property['value'] ?? ''));
            }
        }

        if (count($sent) !== count($currentMap)) {
            return true;
        }

        foreach ($sent as $property) {
            $id = (int)$property['property_id'];

            if (!isset($currentMap[$id]) || $currentMap[$id] !== trim((string)$property['value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort upload of the WooCommerce featured image to the Moloni product.
     *
     * The public Moloni API does not document image upload, so this attaches the
     * file to a multipart products/update and treats failure as non-fatal (logged
     * warning; the data sync has already succeeded). Guards, in order:
     *  - field sync toggle off → no-op
     *  - no featured image (own or parent's) → no-op
     *  - file missing, not a real image (getimagesize), disallowed type, or larger
     *    than the cap (moloni_image_max_bytes filter, default 2 MB) → skipped + log
     *  - image unchanged since the last successful upload (md5 hash stored in
     *    product meta) → no-op, so bulk saves never re-upload — this is what keeps
     *    the feature cheap under the 60 req/min API quota.
     */
    private function syncImage(): void
    {
        if (!SyncFields::wmImage() || empty($this->product_id)) {
            return;
        }

        try {
            $imageId = (int)$this->product->get_image_id();

            if ($imageId <= 0 && $this->productParent) {
                $imageId = (int)$this->productParent->get_image_id();
            }

            if ($imageId <= 0) {
                return;
            }

            $path = get_attached_file($imageId);

            if (empty($path) || !is_file($path) || !is_readable($path)) {
                return;
            }

            $maxBytes = (int)apply_filters('moloni_image_max_bytes', self::IMAGE_MAX_BYTES);
            $size = (int)filesize($path);

            if ($size <= 0 || $size > $maxBytes) {
                Storage::$LOGGER->warning(__('Imagem ignorada: excede o tamanho máximo para envio ao Moloni'), [
                    'tag' => 'controller:product:image:toolarge',
                    'reference' => $this->reference,
                    'bytes' => $size,
                    'max_bytes' => $maxBytes,
                ]);

                return;
            }

            // Must be a real image of an allowed type — never ship arbitrary files.
            $imageInfo = @getimagesize($path);
            $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

            if ($imageInfo === false || !in_array($imageInfo[2], $allowedTypes, true)) {
                Storage::$LOGGER->warning(__('Imagem ignorada: tipo de ficheiro não suportado'), [
                    'tag' => 'controller:product:image:badtype',
                    'reference' => $this->reference,
                    'file' => basename($path),
                ]);

                return;
            }

            $hash = md5_file($path);

            if ($hash !== false && $hash === (string)$this->product->get_meta(self::IMAGE_HASH_META, true)) {
                return; // already uploaded this exact file
            }

            // Re-send the current product payload with the file attached — the
            // update endpoint requires the mandatory fields either way.
            $props = $this->mapPropsToValues();
            $props['product_id'] = $this->product_id;
            $props['price'] = $props['price'] ?? $this->price;

            Curl::simpleWithFile('products/update', $props, $path);

            $this->product->update_meta_data(self::IMAGE_HASH_META, $hash);
            $this->product->save();

            Storage::$LOGGER->info(
                str_replace('{0}', $this->reference, __('Imagem enviada para o Moloni ({0})')),
                [
                    'tag' => 'controller:product:image:sent',
                    'product_id' => $this->product_id,
                    'file' => basename($path),
                    'bytes' => $size,
                ]
            );
        } catch (APIException $e) {
            // Best effort by design: the account/API may not accept image upload.
            Storage::$LOGGER->warning(__('Não foi possível enviar a imagem para o Moloni (envio não suportado ou rejeitado)'), [
                'tag' => 'controller:product:image:failed',
                'reference' => $this->reference,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Storage::$LOGGER->warning(__('Erro inesperado ao enviar a imagem para o Moloni'), [
                'tag' => 'controller:product:image:error',
                'reference' => $this->reference,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if any attribute is outdated
     *
     * @param array $props
     *
     * @return true
     */
    private function needsToUpdateProduct(array $props): bool
    {
        if (empty($this->moloniProduct)) {
            return true;
        }

        if (
            (int)$props['category_id'] !== (int)$this->moloniProduct['category_id'] ||
            (int)$props['unit_id'] !== (int)$this->moloniProduct['unit_id'] ||
            (int)$props['visibility_id'] !== (int)$this->moloniProduct['visibility_id'] ||
            round($props['price'], 5) !== round($this->moloniProduct['price'], 5) ||
            ($props['name'] ?? '') !== ($this->moloniProduct['name'] ?? '') ||
            ($props['summary'] ?? '') !== ($this->moloniProduct['summary'] ?? '') ||
            ($props['exemption_reason'] ?? '') !== ($this->moloniProduct['exemption_reason'] ?? '')
        ) {
            return true;
        }

        // EAN: only compared when that field sync is on — otherwise the payload
        // omits it and the mismatch would force a pointless update on every save.
        if (SyncFields::wmEan() && ($props['ean'] ?? '') !== ($this->moloniProduct['ean'] ?? '')) {
            return true;
        }

        if (self::propertiesDiffer($props['properties'] ?? [], $this->moloniProduct['properties'] ?? [])) {
            return true;
        }

        $propsTaxCount = count($props['taxes'] ?? []);
        $newTaxCount = count($this->taxes ?? []);

        if ($propsTaxCount !== $newTaxCount) {
            return true;
        }

        if ($propsTaxCount > 0 && $newTaxCount > 0) {
            foreach ($props['taxes'] as $propTax) {
                foreach ($this->taxes as $newTax) {
                    if ((int)$newTax['tax_id'] === (int)$propTax['tax_id']) {
                        continue 2;
                    }
                }

                return true;
            }
        }

        return false;
    }
}
