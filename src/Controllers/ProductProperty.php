<?php

namespace Moloni\Controllers;

use Moloni\Curl;
use Moloni\Storage;
use Moloni\Exceptions\APIException;

/**
 * Resolves Moloni product properties (Definições → Propriedades de artigos) by
 * title, creating missing ones on demand.
 *
 * The full list is fetched ONCE per request (static cache) so mapping the
 * attributes of many products during a sync costs a single productProperties/getAll
 * call plus one insert per genuinely new property — relevant under Moloni's
 * 60 requests/minute API quota.
 */
class ProductProperty
{
    /** @var int Moloni caps property titles at a short varchar — stay safely under it */
    private const TITLE_MAX_LENGTH = 50;

    /** @var array<int,array>|null Cached productProperties/getAll response */
    private static $propertiesCache = null;

    /**
     * Find (or create) a Moloni property for an attribute name.
     *
     * @param string $title Attribute label (e.g. "Cor")
     *
     * @return int|null property_id, or null when it cannot be resolved
     */
    public static function resolveIdByTitle(string $title): ?int
    {
        $title = self::normalizeTitle($title);

        if ($title === '') {
            return null;
        }

        $properties = self::getAll();

        foreach ($properties as $property) {
            if (isset($property['title']) && self::sameTitle((string)$property['title'], $title)) {
                return (int)$property['property_id'];
            }
        }

        try {
            $insert = Curl::simple('productProperties/insert', ['title' => $title]);
        } catch (APIException $e) {
            Storage::$LOGGER->warning(__('Erro ao criar propriedade de artigo no Moloni'), [
                'tag' => 'controller:productproperty:insert',
                'title' => $title,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (empty($insert['property_id'])) {
            return null;
        }

        $propertyId = (int)$insert['property_id'];

        // Keep the request cache coherent so repeated titles in the same run
        // don't re-insert.
        if (is_array(self::$propertiesCache)) {
            self::$propertiesCache[] = ['property_id' => $propertyId, 'title' => $title];
        }

        return $propertyId;
    }

    /**
     * @return array<int,array> productProperties/getAll (cached per request)
     */
    private static function getAll(): array
    {
        if (self::$propertiesCache !== null) {
            return self::$propertiesCache;
        }

        try {
            $response = Curl::simple('productProperties/getAll', []);
        } catch (APIException $e) {
            Storage::$LOGGER->warning(__('Erro ao obter propriedades de artigos do Moloni'), [
                'tag' => 'controller:productproperty:getall',
                'message' => $e->getMessage(),
            ]);

            $response = [];
        }

        self::$propertiesCache = is_array($response) ? $response : [];

        return self::$propertiesCache;
    }

    /**
     * Plain-text, length-capped property title.
     */
    private static function normalizeTitle(string $title): string
    {
        $title = trim(wp_strip_all_tags($title));

        return mb_substr($title, 0, self::TITLE_MAX_LENGTH);
    }

    /**
     * Case/whitespace-insensitive title comparison, so "Cor" matches "cor ".
     */
    private static function sameTitle(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
