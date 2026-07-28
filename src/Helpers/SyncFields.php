<?php

namespace Moloni\Helpers;

/**
 * Per-field synchronisation toggles (Settings → "Sincronização de campos").
 *
 * Each toggle is stored in the moloni_api_config table and surfaces as an
 * UPPERCASE constant (see Model::defineConfigs). Defaults are chosen so that a
 * site upgrading from a previous version keeps EXACTLY the behaviour it had:
 * fields that were always sent stay on, new capabilities start off.
 *
 * MW = Moloni → WooCommerce · WM = WooCommerce → Moloni
 */
class SyncFields
{
    /**
     * Moloni → WooCommerce: apply the Moloni product tax (IVA) to the
     * WooCommerce tax status/class. Default OFF — overwriting tax classes on
     * existing products is impactful, so it is opt-in.
     */
    public static function mwTax(): bool
    {
        return defined('MOLONI_SYNC_MW_TAX') && (int)MOLONI_SYNC_MW_TAX === 1;
    }

    /**
     * Moloni → WooCommerce: apply the Moloni EAN/barcode to the native
     * WooCommerce GTIN field. Default ON (Moloni is the barcode source of truth).
     */
    public static function mwEan(): bool
    {
        if (!defined('MOLONI_SYNC_MW_EAN')) {
            return true;
        }

        return (int)MOLONI_SYNC_MW_EAN === 1;
    }

    /**
     * WooCommerce → Moloni: send the WooCommerce EAN with product inserts and
     * updates. Default ON (previous versions always sent it when present).
     */
    public static function wmEan(): bool
    {
        if (!defined('MOLONI_SYNC_WM_EAN')) {
            return true;
        }

        return (int)MOLONI_SYNC_WM_EAN === 1;
    }

    /**
     * WooCommerce → Moloni: send the WooCommerce price on product UPDATES.
     * Inserts always carry the price (mandatory in the Moloni API). Default ON
     * (previous versions always sent it).
     */
    public static function wmPrice(): bool
    {
        if (!defined('MOLONI_SYNC_WM_PRICE')) {
            return true;
        }

        return (int)MOLONI_SYNC_WM_PRICE === 1;
    }

    /**
     * WooCommerce → Moloni: map WooCommerce product attributes to Moloni
     * product properties ({property_id, value}). Default OFF.
     */
    public static function wmProperties(): bool
    {
        return defined('MOLONI_SYNC_WM_PROPERTIES') && (int)MOLONI_SYNC_WM_PROPERTIES === 1;
    }

    /**
     * WooCommerce → Moloni: upload the WooCommerce featured image to the Moloni
     * product. Default OFF. Best-effort: the public Moloni API does not document
     * image upload, so failures are logged and never block the data sync.
     */
    public static function wmImage(): bool
    {
        return defined('MOLONI_SYNC_WM_IMAGE') && (int)MOLONI_SYNC_WM_IMAGE === 1;
    }

    /**
     * WooCommerce → Moloni: write the WooCommerce short description into the
     * Moloni "resumo" (summary). Default OFF — when off, the existing Moloni
     * summary is round-tripped untouched (previous behaviour).
     */
    public static function wmSummary(): bool
    {
        return defined('MOLONI_SYNC_WM_SUMMARY') && (int)MOLONI_SYNC_WM_SUMMARY === 1;
    }
}
