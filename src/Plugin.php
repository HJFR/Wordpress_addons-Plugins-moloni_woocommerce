<?php

/** @noinspection PhpPropertyOnlyWrittenInspection */

namespace Moloni;

use Exception;
use WC_Order;
use Moloni\Exceptions\Core\MoloniException;
use Moloni\Exceptions\DocumentError;
use Moloni\Exceptions\DocumentWarning;
use Moloni\Helpers\Context;
use Moloni\Helpers\Logger;
use Moloni\Hooks\Ajax;
use Moloni\Models\Logs;
use Moloni\Services\Documents\DownloadDocument;
use Moloni\Services\Documents\OpenDocument;
use Moloni\Services\Orders\CreateMoloniDocument;
use Moloni\Services\Orders\DiscardOrder;
use Moloni\Services\Stocks\SyncStockFromMoloni;

/**
 * Class Plugin
 * 
 * Main plugin controller and entry point for the Moloni WooCommerce integration.
 * Handles routing of admin page requests, initializes WordPress hooks and cron jobs,
 * and orchestrates core plugin functionality including document generation,
 * stock synchronization, and order management.
 * 
 * @package Moloni
 */
class Plugin
{
    /**
     * @var string Current action being requested (from $_REQUEST['action'])
     */
    private $action = '';

    /**
     * @var string Currently active tab in the admin interface (from $_GET['tab'])
     */
    private $activeTab = '';

    /**
     * Constructor
     * 
     * Initializes the plugin by:
     * 1. Running startup configuration
     * 2. Registering WordPress actions and hooks
     * 3. Setting up scheduled cron jobs
     */
    public function __construct()
    {
        $this->onStart();
        $this->actions();
        $this->crons();
    }

    //            Privates            //

    /**
     * Perform initial setup before plugin operations
     * 
     * Sanitizes and stores request parameters, detects WooCommerce's
     * High-Performance Order Storage (HPOS) mode, and initializes the logger.
     *
     * @return void
     */
    private function onStart()
    {
        // Sanitize and capture request parameters for routing
        $this->action = sanitize_text_field($_REQUEST['action'] ?? '');
        $this->activeTab = sanitize_text_field($_GET['tab'] ?? '');

        // Detect if WooCommerce is using the new HPOS (High-Performance Order Storage)
        // This affects how orders are queried and stored
        Storage::$USES_NEW_ORDERS_SYSTEM = Context::isNewOrdersSystemEnabled();

        // Initialize the centralized logging system
        Storage::$LOGGER = new Logger();
    }

    /**
     * Register all WordPress hooks and initialize plugin components
     * 
     * Sets up:
     * - Admin menu pages
     * - AJAX handlers
     * - WooCommerce integration hooks for products and orders
     * - Plugin upgrade handlers
     *
     * @return void
     */
    private function actions(): void
    {
        /** Admin pages - Menu structure and AJAX endpoints */
        new Menus\Admin($this);
        new Ajax($this);

        /** WooCommerce and WordPress hooks */
        new Hooks\WoocommerceInitialize($this);  // Core WooCommerce integration
        new Hooks\ProductUpdate($this);           // Product save/update handling
        new Hooks\ProductView($this);             // Product admin page modifications
        new Hooks\OrderView($this);               // Order admin page modifications
        new Hooks\OrderStatusChanged($this);      // Order status transition handling
        new Hooks\OrderList($this);               // Orders list page modifications
        new Hooks\OrderRefunded($this);           // Refund processing
        new Hooks\OrderDetails($this);            // Order details page
        new Hooks\UpgradeProcess($this);          // Plugin version upgrade handling
    }

    /**
     * Configure WordPress cron jobs for scheduled tasks
     * 
     * Sets up a recurring product synchronization job that runs every 5 minutes.
     * This handles automatic stock updates between Moloni and WooCommerce.
     */
    private function crons(): void
    {
        // Register custom cron interval (every 5 minutes)
        add_filter('cron_schedules', '\Moloni\Crons::addCronInterval');

        // Register the sync action handler
        add_action('moloniProductsSync', '\Moloni\Crons::productsSync');

        // Schedule the cron job if not already scheduled
        if (!wp_next_scheduled('moloniProductsSync')) {
            wp_schedule_event(time(), 'everyficeminutes', 'moloniProductsSync');
        }
    }

    //            Publics            //

    /**
     * Main routing function for the Moloni admin page
     * 
     * This is the primary entry point when accessing the "moloni" admin page.
     * Handles authentication verification and routes requests based on the
     * 'action' parameter to appropriate handler methods.
     * 
     * Supported actions:
     * - remInvoice: Discard a single order from pending list
     * - remInvoiceAll: Discard all orders from pending list
     * - genInvoice: Generate a Moloni document from an order
     * - syncStocks: Force stock synchronization from Moloni
     * - remLogs: Clear old log entries
     * - getInvoice: Open/view a Moloni document
     * - downloadDocument: Download a Moloni document PDF
     */
    public function run(): void
    {
        try {
            // Attempt to authenticate with Moloni API
            $authenticated = Start::login();

            // Only process actions if user is authenticated
            if ($authenticated) {
                switch ($this->action) {
                    case 'remInvoice':
                        $this->removeOrder();
                        break;
                    case 'remInvoiceAll':
                        $this->removeOrdersAll();
                        break;
                    case 'genInvoice':
                        $this->createDocument();
                        break;
                    case 'syncStocks':
                        $this->syncStocks();
                        break;
                    case 'syncStocksFull':
                        $this->syncStocksFull();
                        break;
                    case 'remLogs':
                        $this->removeLogs();
                        break;
                    case 'getInvoice':
                        $this->openDocument();
                        break;
                    case 'downloadDocument':
                        $this->downloadDocument();
                        break;
                }
            }
        } catch (MoloniException $error) {
            // Store exception for display in the admin template
            $pluginErrorException = $error;
        }

        // Render the main admin interface (skip for AJAX requests)
        if (isset($authenticated) && $authenticated && !wp_doing_ajax()) {
            include MOLONI_TEMPLATE_DIR . 'MainContainer.php';
        }
    }

    //            Actions            //

    /**
     * Create a Moloni document from a WooCommerce order
     * 
     * Generates an invoice or other document type in Moloni ERP based on
     * the specified order ID. Handles both warnings (non-fatal issues)
     * and errors (fatal issues) with appropriate logging.
     *
     * @throws DocumentError If document creation fails critically
     * @throws DocumentWarning If document creation succeeds with warnings
     */
    private function createDocument(): void
    {
        $service = new CreateMoloniDocument((int)$_REQUEST['id']);
        $orderName = $service->getOrderNumber();

        try {
            $service->run();
        } catch (DocumentWarning $e) {
            // Log warning but document may still have been created
            Storage::$LOGGER->alert(
                str_replace('{0}', $orderName, __('Houve um alerta ao gerar o documento ({0})')),
                // Translation: "There was an alert when generating the document ({order})"
                [
                    'message' => $e->getMessage(),
                    'request' => $e->getData()
                ]
            );

            throw $e;
        } catch (DocumentError $e) {
            // Log error - document creation failed
            Storage::$LOGGER->error(
                str_replace('{0}', $orderName, __('Houve um erro ao gerar o documento ({0})')),
                // Translation: "There was an error when generating the document ({order})"
                [
                    'message' => $e->getMessage(),
                    'request' => $e->getData()
                ]
            );

            throw $e;
        }

        // Display success message with link to view the document
        if ($service->getDocumentId()) {
            $adminUrl = admin_url('admin.php?page=moloni&action=getInvoice&id=' . $service->getDocumentId());

            $html = ' <a href="' . $adminUrl . '" target="_BLANK">';
            $html .= '  Ver documento';  // "View document"
            $html .= '</a>';

            add_settings_error('moloni', 'moloni-document-created-success', __('O documento foi gerado!') . $html, 'updated');
            // Translation: "The document was generated!"
        }
    }

    /**
     * Open a Moloni document in a new browser tab
     * 
     * Redirects to the Moloni web interface to view the specified document.
     * Displays an error if the document ID is invalid or not found.
     *
     * @return void
     */
    private function openDocument(): void
    {
        $documentId = (int)$_REQUEST['id'];

        if ($documentId > 0) {
            // OpenDocument service handles the redirect
            new OpenDocument($documentId);
        }

        // This message shows if document wasn't found or redirect failed
        add_settings_error('moloni', 'moloni-document-not-found', __('Documento não encontrado'));
        // Translation: "Document not found"
    }

    /**
     * Download a Moloni document as PDF
     * 
     * Fetches the document PDF from Moloni API and sends it as a download
     * to the user's browser.
     *
     * @return void
     */
    private function downloadDocument(): void
    {
        $documentId = (int)$_REQUEST['id'];

        if ($documentId > 0) {
            // DownloadDocument service handles the file download
            new DownloadDocument($documentId);
        }
    }

    /**
     * Delete old log entries from the database
     * 
     * Cleans up historical logs to prevent database bloat.
     * The retention period is defined in the Logs model.
     *
     * @return void
     */
    private function removeLogs(): void
    {
        Logs::removeOlderLogs();

        add_settings_error('moloni', 'moloni-rem-logs', __('A limpeza de logs foi concluída.'), 'updated');
        // Translation: "Log cleanup was completed."
    }

    /**
     * Discard a single order from the pending documents list
     * 
     * Marks an order as processed without generating a document.
     * Requires confirmation via URL parameter to prevent accidental discards.
     * Uses a two-step process: first shows confirmation, then processes on confirm.
     *
     * @return void
     */
    private function removeOrder(): void
    {
        $orderId = (int)$_GET['id'];

        // Check if user has confirmed the action
        if (isset($_GET['confirm']) && sanitize_text_field($_GET['confirm']) === 'true') {
            $order = wc_get_order($orderId);

            // Execute the discard operation
            $service = new DiscardOrder($order);
            $service->run();
            $service->saveLog();

            add_settings_error(
                'moloni',
                'moloni-order-remove-success',
                sprintf(__('A encomenda foi descartada (%s)'), $orderId),
                // Translation: "The order was discarded ({id})"
                'updated'
            );
        } else {
            // Show confirmation prompt with action link
            add_settings_error(
                'moloni',
                'moloni-order-remove',
                __('Confirma que pretende marcar a encomenda ' . $orderId . " como paga? <a href='" . admin_url('admin.php?page=moloni&action=remInvoice&confirm=true&id=' . $orderId) . "'>Sim confirmo!</a>")
                // Translation: "Do you confirm you want to mark order {id} as paid? Yes, I confirm!"
            );
        }
    }

    /**
     * Discard all orders from the pending documents list
     * 
     * Marks all pending orders as processed without generating documents.
     * Useful for bulk cleanup when orders don't need Moloni documents.
     * Requires confirmation to prevent accidental bulk discards.
     *
     * @return void
     */
    private function removeOrdersAll(): void
    {
        // Check if user has confirmed the action
        if (isset($_GET['confirm']) && sanitize_text_field($_GET['confirm']) === 'true') {
            /** @var WC_Order[] $allOrders */
            $allOrders = Models\PendingOrders::getAllAvailable();

            if (!empty($allOrders)) {
                foreach ($allOrders as $order) {
                    // Mark order as processed with special value -1 (discarded)
                    $order->add_meta_data('_moloni_sent', '-1');
                    $order->add_order_note(__('Encomenda marcada como gerada'));
                    // Translation: "Order marked as generated"
                    $order->save();
                }

                $msg = __('Todas as encomendas foram marcadas como geradas!');
                // Translation: "All orders were marked as generated!"

                Storage::$LOGGER->info($msg);
                add_settings_error('moloni', 'moloni-order-all-remove-success', $msg, 'updated');
            } else {
                add_settings_error('moloni', 'moloni-order-all-remove-not-found', __('Não foram encontradas encomendas por gerar'));
                // Translation: "No orders pending generation were found"
            }
        } else {
            // Show confirmation prompt with action link
            add_settings_error(
                'moloni', 'moloni-order-remove', __("Confirma que pretende marcar todas as encomendas como já geradas? <a href='" . admin_url('admin.php?page=moloni&action=remInvoiceAll&confirm=true') . "'>Sim confirmo!</a>")
                // Translation: "Do you confirm you want to mark all orders as already generated? Yes, I confirm!"
            );
        }
    }

    /**
     * Force manual stock synchronization from Moloni to WooCommerce
     * 
     * Fetches products modified in Moloni since a specified date (default: 1 week ago)
     * and updates their stock quantities in WooCommerce to match.
     * 
     * Displays summary statistics:
     * - Updated: Products with stock successfully synchronized
     * - Equal: Products already having matching stock
     * - Not Found: Moloni products without matching WooCommerce products
     * - Locked: Products that were skipped due to sync locks
     *
     * @return void
     */
    private function syncStocks(): void
    {
        check_admin_referer('moloni_sync_stocks');

        // Get the 'since' date parameter, default to 1 week ago
        $date = isset($_GET['since']) ? sanitize_text_field($_GET['since']) : gmdate('Y-m-d', strtotime('-1 week'));

        try {
            $service = new SyncStockFromMoloni($date);
            $service->run();

            // Display statistics for each result category
            if ($service->countUpdated() > 0) {
                $message = sprintf(__('Foram atualizados %d artigos.'), $service->countUpdated());
                // Translation: "{n} products were updated."

                add_settings_error('moloni', 'moloni-sync-stocks-updated', $message, 'updated');
            }

            if ($service->countEqual() > 0) {
                $message = sprintf(__('Existem %d artigos com stock igual.'), $service->countEqual());
                // Translation: "There are {n} products with equal stock."

                add_settings_error('moloni', 'moloni-sync-stocks-equal', $message, 'updated');
            }

            if ($service->countNotFound() > 0) {
                $message = sprintf(__('Não foram encontrados no WooCommerce %d artigos.'), $service->countNotFound());
                // Translation: "{n} products were not found in WooCommerce."

                add_settings_error('moloni', 'moloni-sync-stocks-not-found', $message);
            }

            if ($service->countLocked() > 0) {
                $message = sprintf(__('Foram bloqueados %d artigos.'), $service->countLocked());
                // Translation: "{n} products were locked."

                add_settings_error('moloni', 'moloni-sync-stocks-locked', $message);
            }

            // Log detailed sync results if any records were processed
            if ($service->countFoundRecord() > 0) {
                Storage::$LOGGER->info(__('Sincronização de stock manual'), [
                    // Translation: "Manual stock synchronization"
                    'action' => 'stock:sync:manual',
                    'since' => $service->getSince(),
                    'equal' => $service->getEqual(),
                    'not_found' => $service->getNotFound(),
                    'get_updated' => $service->getUpdated(),
                    'get_locked' => $service->getLocked(),
                ]);
            }
        } catch (Exception $ex) {
            // Handle unexpected errors during synchronization
            $message = __('Erro fatal');
            // Translation: "Fatal error"

            add_settings_error('moloni', 'moloni-sync-stocks-error', $message);

            Storage::$LOGGER->critical($message, [
                'action' => 'stock:sync:manual:error',
                'exception' => $ex->getMessage()
            ]);
        }
    }

    /**
     * Resumable FULL stock sync: walks the ENTIRE Moloni catalogue, not only the
     * products modified in the last week. Each run is capped at
     * MOLONI_SYNC_MAX_PRODUCTS (default 2000), so the progress offset is persisted
     * between runs (option moloni_full_sync_offset) and repeated clicks advance
     * through the catalogue instead of re-processing the first batch. When a run
     * reaches the final page (not truncated) the progress is cleared = complete.
     *
     * @return void
     */
    private function syncStocksFull(): void
    {
        check_admin_referer('moloni_sync_stocks_full');

        $since  = '2000-01-01'; // far past → getModifiedSince returns the whole catalogue
        $offset = (int) get_option('moloni_full_sync_offset', 0);

        try {
            $service = new SyncStockFromMoloni($since, $offset);
            $service->run();

            $processedNow = $service->countFoundRecord();

            if ($service->wasTruncated()) {
                update_option('moloni_full_sync_offset', $service->getNextOffset(), false);

                $message = sprintf(
                    __('Sincronização completa em curso: processados %1$d artigos (posição %2$d). Clica novamente em "Sincronização completa" para continuar.'),
                    $processedNow,
                    $service->getNextOffset()
                );
                add_settings_error('moloni', 'moloni-full-sync-progress', $message, 'updated');
            } else {
                delete_option('moloni_full_sync_offset');

                add_settings_error('moloni', 'moloni-full-sync-done', __('Sincronização completa concluída — todo o catálogo foi percorrido.'), 'updated');
            }

            if ($service->countUpdated() > 0) {
                add_settings_error('moloni', 'moloni-full-sync-updated', sprintf(__('Foram atualizados %d artigos.'), $service->countUpdated()), 'updated');
            }
            if ($service->countNotFound() > 0) {
                add_settings_error('moloni', 'moloni-full-sync-not-found', sprintf(__('Não foram encontrados no WooCommerce %d artigos.'), $service->countNotFound()));
            }

            if ($processedNow > 0) {
                Storage::$LOGGER->info(__('Sincronização de stock completa (manual)'), [
                    'action' => 'stock:sync:manual:full',
                    'since' => $service->getSince(),
                    'start_offset' => $offset,
                    'next_offset' => $service->getNextOffset(),
                    'truncated' => $service->wasTruncated(),
                    'found' => $processedNow,
                    'updated' => $service->getUpdated(),
                ]);
            }
        } catch (Exception $ex) {
            add_settings_error('moloni', 'moloni-full-sync-error', __('Erro fatal'));

            Storage::$LOGGER->critical(__('Erro fatal'), [
                'action' => 'stock:sync:manual:full:error',
                'exception' => $ex->getMessage(),
            ]);
        }
    }
}
