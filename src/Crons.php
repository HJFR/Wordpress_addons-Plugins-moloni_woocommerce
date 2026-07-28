<?php

namespace Moloni;

use Exception;
use Moloni\Services\Stocks\SyncStockFromMoloni;
use Moloni\Services\Stocks\SyncStockByPriority;

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

            // If a sweep is armed (started from Tools), continue it this tick —
            // REGARDLESS of the automatic stock-sync setting: the field-only
            // sweeps (Moloni→WC / WC→Moloni) are explicitly armed by the user
            // and must run even with stock sync off; a stock-mode sweep was
            // armed deliberately too. The sweep walks the catalogue in PRIORITY
            // order, one small page per tick, under the 60 req/min API limit;
            // resumable (state = {phase,page,mode}) and cancellable any time.
            $state = SyncStockByPriority::getState();

            if ($state !== null) {
                $mode = $state['mode'];

                // The rate limiter may sleep to honour 60 req/min; lift the
                // timeout so a batch always finishes within the cron tick.
                if (function_exists('wc_set_time_limit')) {
                    wc_set_time_limit(0);
                }

                // Cancellation requested before this tick? Stop and clean up.
                if (SyncStockByPriority::cancelRequested()) {
                    SyncStockByPriority::clear();

                    Storage::$LOGGER->info(__('Sincronização (varrimento) cancelada'), [
                        'action' => 'stock:sync:cron:full:cancelled',
                        'mode' => $mode,
                        'phase' => $state['phase'],
                        'page' => $state['page'],
                    ]);

                    return true;
                }

                $batch = 50;
                if (defined('MOLONI_FULL_SYNC_BATCH') && (int)MOLONI_FULL_SYNC_BATCH > 0) {
                    $batch = (int)MOLONI_FULL_SYNC_BATCH;
                }

                $service = new SyncStockByPriority((int)$state['phase'], (int)$state['page'], $batch, $mode);
                $service->run();

                // Cancelled mid-batch — clean up and stop, leaving the rest
                // of the catalogue untouched.
                if ($service->wasCancelled()) {
                    SyncStockByPriority::clear();

                    Storage::$LOGGER->info(__('Sincronização (varrimento) cancelada (durante o lote)'), [
                        'action' => 'stock:sync:cron:full:cancelled',
                        'mode' => $mode,
                        'phase' => $state['phase'],
                        'page' => $state['page'],
                    ]);

                    return true;
                }

                // Accumulate lightweight running totals for the progress display.
                $stats = $state['stats'];
                $stats['processed'] = (int)($stats['processed'] ?? 0) + $service->countFoundRecord();
                $stats['updated'] = (int)($stats['updated'] ?? 0) + $service->countUpdated();
                $stats['priced'] = (int)($stats['priced'] ?? 0) + $service->countPricedOnly();
                $stats['equal'] = (int)($stats['equal'] ?? 0) + $service->countEqual();
                $stats['not_found'] = (int)($stats['not_found'] ?? 0) + $service->countNotFound();

                if ($service->isPhaseComplete()) {
                    $nextPhase = (int)$state['phase'] + 1;

                    if ($nextPhase > SyncStockByPriority::LAST_PHASE) {
                        // Every phase covered — finish. Only a STOCK sweep aligns
                        // the recent-sync watermark (field sweeps don't cover stock,
                        // so the recent window must still be re-processed).
                        SyncStockByPriority::clear();

                        if ($mode === SyncStockByPriority::MODE_STOCK) {
                            Model::setOption('moloni_stock_sync_time', $runningAt);
                        }
                    } else {
                        SyncStockByPriority::setState($nextPhase, 1, $stats, $mode);
                    }
                } else {
                    SyncStockByPriority::setState((int)$state['phase'], $service->getNextPage(), $stats, $mode);
                }

                Storage::$LOGGER->info(__('Varrimento por prioridade (cron, em lote)'), [
                    'action' => 'stock:sync:cron:full:priority',
                    'mode' => $mode,
                    'phase' => $service->getPhase(),
                    'page' => $service->getPage(),
                    'phase_complete' => $service->isPhaseComplete(),
                    'found' => $service->countFoundRecord(),
                    'updated' => $service->countUpdated(),
                    'priced_only' => $service->countPricedOnly(),
                    'equal' => $service->countEqual(),
                    'not_found' => $service->countNotFound(),
                ]);

                return true;
            }

            if (defined('MOLONI_STOCK_SYNC') && (int)MOLONI_STOCK_SYNC !== 0) {
                // Back-compat: a full sweep armed under a previous version used the
                // integer 'moloni_full_sync_offset' option. Retire it (the engine is
                // now priority-based) so it doesn't linger; the user re-arms in Tools.
                if (get_option('moloni_full_sync_offset', false) !== false) {
                    delete_option('moloni_full_sync_offset');

                    Storage::$LOGGER->info(
                        __('Sincronização completa antiga descontinuada — use o novo modo por prioridade nas Ferramentas.'),
                        ['action' => 'stock:sync:cron:full:legacy-retired']
                    );
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
