<?php

namespace Moloni;

use Exception;
use Moloni\Services\Stocks\SyncStockFromMoloni;

/**
 * This crons will run in isolation
 */
class Crons
{
    public static function addCronInterval($schedules = [])
    {
        $schedules['everyficeminutes'] = array(
            'interval' => 300,
            'display' => __('A cada cinco minutos')
        );

        return $schedules;
    }

    /**
     * Service handler
     *
     * @return bool
     *
     * @global $wpdb
     */
    public static function productsSync(): bool
    {
        global $wpdb;

        $runningAt = time();

        try {
            self::requires();

            if (!Start::login(true)) {
                Storage::$LOGGER->error(__('Não foi possível estabelecer uma ligação a uma empresa Moloni'));
                return false;
            }

            if (defined('MOLONI_STOCK_SYNC') && (int)MOLONI_STOCK_SYNC !== 0) {
                // If a FULL sync is armed (started from Tools), continue it this
                // tick instead of the recent-window sync: process one small batch
                // and advance the persisted offset until the whole catalogue is
                // covered. Runs every 5 min, so a large catalogue completes
                // unattended without exceeding the 60 req/min API limit.
                $fullOffset = get_option('moloni_full_sync_offset', false);

                if ($fullOffset !== false) {
                    // The rate limiter may sleep to honour 60 req/min; lift the
                    // timeout so a batch always finishes within the cron tick.
                    if (function_exists('wc_set_time_limit')) {
                        wc_set_time_limit(0);
                    }

                    $batch = 50;
                    if (defined('MOLONI_FULL_SYNC_BATCH') && (int)MOLONI_FULL_SYNC_BATCH > 0) {
                        $batch = (int)MOLONI_FULL_SYNC_BATCH;
                    }

                    $service = new SyncStockFromMoloni('2000-01-01', (int)$fullOffset, $batch);
                    $service->run();

                    if ($service->wasTruncated()) {
                        update_option('moloni_full_sync_offset', $service->getNextOffset(), false);
                    } else {
                        // Whole catalogue covered — finish and align the recent-sync
                        // watermark to now so it does not re-process the sweep window.
                        delete_option('moloni_full_sync_offset');
                        Model::setOption('moloni_stock_sync_time', $runningAt);
                    }

                    Storage::$LOGGER->info(__('Sincronização completa (cron, em lote)'), [
                        'action' => 'stock:sync:cron:full',
                        'start_offset' => (int)$fullOffset,
                        'next_offset' => $service->getNextOffset(),
                        'truncated' => $service->wasTruncated(),
                        'found' => $service->countFoundRecord(),
                        'updated' => $service->getUpdated(),
                    ]);

                    return true;
                }

                if (!defined('MOLONI_STOCK_SYNC_TIME')) {
                    define('MOLONI_STOCK_SYNC_TIME', (time() - 600));

                    $wpdb->insert($wpdb->get_blog_prefix() . 'moloni_api_config', [
                        'config' => 'moloni_stock_sync_time',
                        'selected' => MOLONI_STOCK_SYNC_TIME
                    ]);
                }

                $service = new SyncStockFromMoloni(MOLONI_STOCK_SYNC_TIME);
                $service->run();

                if ($service->countFoundRecord() > 0) {
                    Storage::$LOGGER->info(__('Sincronização de stock automática'), [
                        'action' => 'stock:sync:cron',
                        'since' => $service->getSince(),
                        'truncated' => $service->wasTruncated(),
                        'equal' => $service->getEqual(),
                        'not_found' => $service->getNotFound(),
                        'get_updated' => $service->getUpdated(),
                        'get_locked' => $service->getLocked(),
                    ]);
                }

                // If the run was truncated (per-tick cap hit OR API error) keep
                // the existing watermark so the next cron tick re-fetches the
                // same window and processes what we missed. Otherwise advance.
                if (!$service->wasTruncated()) {
                    Model::setOption('moloni_stock_sync_time', $runningAt);
                }
            } else {
                // Stock sync disabled — still advance watermark so we don't
                // accumulate a stale window if it's re-enabled later.
                Model::setOption('moloni_stock_sync_time', $runningAt);
            }
        } catch (Exception $ex) {
            Storage::$LOGGER->critical(__('Erro fatal'), [
                'action' => 'stock:sync:cron:error',
                'exception' => $ex->getMessage()
            ]);
        }

        return true;
    }

    public static function requires()
    {
        $composer_autoloader = '../vendor/autoload.php';
        if (is_readable($composer_autoloader)) {
            /** @noinspection PhpIncludeInspection */
            require $composer_autoloader;
        }
    }
}
