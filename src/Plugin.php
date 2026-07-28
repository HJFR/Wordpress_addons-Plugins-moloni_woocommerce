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
use Moloni\Services\Stocks\SyncStockByPriority;

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
                    case 'syncFieldsToWc':
                        $this->syncFieldsToWc();
                        break;
                    case 'syncFieldsToMoloni':
                        $this->syncFieldsToMoloni();
                        break;
                    case 'syncStocksCancel':
                        $this->syncStocksCancel();
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

        // The rate limiter (60 req/min) can pace this run; lift the PHP timeout
        // so a larger 7-day window does not abort mid-sync.
        if (function_exists('wc_set_time_limit')) {
            wc_set_time_limit(0);
        }

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
     * Resumable FULL stock sync in PRIORITY order: walks the ENTIRE WooCommerce
     * catalogue (not only products modified in the last week), processing the
     * highest price-risk products first — phase 1: published + in stock, phase 2:
     * not published + in stock, phase 3: published + out of stock, phase 4: not
     * published + out of stock. Progress (phase + page) is persisted between cron
     * ticks (option moloni_full_sync_state); each tick handles one small page so a
     * large catalogue completes unattended within the 60 req/min API limit. It can
     * be cancelled at any time from Tools (syncStocksCancel).
     *
     * @return void
     */
    private function syncStocksFull(): void
    {
        check_admin_referer('moloni_sync_stocks_full');

        // ARM-ONLY: do not run a heavy batch inside this HTTP request (it could
        // exceed the PHP timeout and the API rate limit). We just mark the full
        // sync as armed; the 5-minute cron then processes it in background batches
        // in priority order until the whole catalogue is covered. (Crons::productsSync.)
        if (SyncStockByPriority::arm()) {
            add_settings_error(
                'moloni',
                'moloni-full-sync-armed',
                __('Sincronização completa por prioridade agendada — vai decorrer em segundo plano, em lotes a cada 5 minutos. Começa pelos produtos com stock e publicados (maior risco de preço errado), depois com stock não publicados, depois publicados sem stock e por fim os restantes. Podes cancelá-la a qualquer momento aqui.'),
                'updated'
            );

            Storage::$LOGGER->info(__('Sincronização completa agendada (manual)'), [
                'action' => 'stock:sync:manual:full:armed',
            ]);
        } else {
            $this->noticeSweepAlreadyRunning();
        }
    }

    /**
     * Arm the FIELD sync sweep Moloni → WooCommerce: applies ONLY the per-field
     * syncs enabled in Settings (EAN, IVA, preço de custo + piso) to every Moloni
     * product that exists in WooCommerce (matched by SKU). Never touches stock,
     * never creates products. Background batches via the 5-min cron, cancellable.
     *
     * @return void
     */
    private function syncFieldsToWc(): void
    {
        check_admin_referer('moloni_sync_fields_to_wc');

        $enabled = [];

        if (\Moloni\Helpers\SyncFields::mwEan()) {
            $enabled[] = __('EAN');
        }
        if (\Moloni\Helpers\SyncFields::mwTax()) {
            $enabled[] = __('IVA');
        }
        if (defined('MOLONI_COST_PRICE_SYNC') && (int)MOLONI_COST_PRICE_SYNC === 1) {
            $enabled[] = __('preço de custo');
        }

        if (empty($enabled)) {
            add_settings_error(
                'moloni',
                'moloni-fields-sync-none',
                __('Nenhum campo Moloni → WooCommerce está ativo nas Configurações ("Sincronização de campos") — ativa pelo menos um (EAN, IVA ou preço de custo) antes de iniciar.')
            );

            return;
        }

        if (SyncStockByPriority::arm(SyncStockByPriority::MODE_FIELDS)) {
            add_settings_error(
                'moloni',
                'moloni-fields-sync-armed',
                sprintf(
                    __('Sincronização de campos Moloni → WooCommerce agendada (campos ativos: %s) — decorre em segundo plano, em lotes a cada 5 minutos, apenas para produtos que existem nas duas plataformas. O stock não é alterado. Podes cancelar aqui a qualquer momento.'),
                    implode(', ', $enabled)
                ),
                'updated'
            );

            Storage::$LOGGER->info(__('Sincronização de campos Moloni → WooCommerce agendada (manual)'), [
                'action' => 'fields:sync:manual:mw:armed',
                'fields' => $enabled,
            ]);
        } else {
            $this->noticeSweepAlreadyRunning();
        }
    }

    /**
     * Arm the FIELD sync sweep WooCommerce → Moloni: pushes ONLY the per-field
     * syncs enabled in Settings (EAN, preço, propriedades, resumo, imagem) to
     * every WooCommerce product that exists in Moloni (matched by SKU), echoing
     * all other Moloni fields back unchanged. Never creates products in Moloni.
     * Background batches via the 5-min cron, cancellable.
     *
     * @return void
     */
    private function syncFieldsToMoloni(): void
    {
        check_admin_referer('moloni_sync_fields_to_moloni');

        $enabled = [];

        if (\Moloni\Helpers\SyncFields::wmEan()) {
            $enabled[] = __('EAN');
        }
        if (\Moloni\Helpers\SyncFields::wmPrice()) {
            $enabled[] = __('preço de venda');
        }
        if (\Moloni\Helpers\SyncFields::wmProperties()) {
            $enabled[] = __('propriedades');
        }
        if (\Moloni\Helpers\SyncFields::wmImage()) {
            $enabled[] = __('imagem');
        }
        if (\Moloni\Helpers\SyncFields::wmSummary()) {
            $enabled[] = __('resumo');
        }

        if (empty($enabled)) {
            add_settings_error(
                'moloni',
                'moloni-fields-sync-wm-none',
                __('Nenhum campo WooCommerce → Moloni está ativo nas Configurações ("Sincronização de campos") — ativa pelo menos um (EAN, preço, propriedades, imagem ou resumo) antes de iniciar.')
            );

            return;
        }

        if (SyncStockByPriority::arm(SyncStockByPriority::MODE_FIELDS_WM)) {
            add_settings_error(
                'moloni',
                'moloni-fields-sync-wm-armed',
                sprintf(
                    __('Sincronização de campos WooCommerce → Moloni agendada (campos ativos: %s) — decorre em segundo plano, em lotes a cada 5 minutos, apenas para produtos que existem nas duas plataformas. Os restantes dados do artigo Moloni ficam exatamente como estão. Podes cancelar aqui a qualquer momento.'),
                    implode(', ', $enabled)
                ),
                'updated'
            );

            Storage::$LOGGER->info(__('Sincronização de campos WooCommerce → Moloni agendada (manual)'), [
                'action' => 'fields:sync:manual:wm:armed',
                'fields' => $enabled,
            ]);
        } else {
            $this->noticeSweepAlreadyRunning();
        }
    }

    /**
     * Shared "a sweep is already running" notice, naming the running mode so the
     * user knows what to cancel first.
     *
     * @return void
     */
    private function noticeSweepAlreadyRunning(): void
    {
        $state = SyncStockByPriority::getState();
        $mode = $state['mode'] ?? SyncStockByPriority::MODE_STOCK;

        $labels = [
            SyncStockByPriority::MODE_STOCK => __('sincronização completa de stocks'),
            SyncStockByPriority::MODE_FIELDS => __('sincronização de campos Moloni → WooCommerce'),
            SyncStockByPriority::MODE_FIELDS_WM => __('sincronização de campos WooCommerce → Moloni'),
        ];

        add_settings_error(
            'moloni',
            'moloni-sweep-running',
            sprintf(
                __('Já existe um varrimento em curso (%1$s, fase %2$d/%3$d) — espera que termine ou cancela-o antes de iniciar outro.'),
                $labels[$mode] ?? $mode,
                (int)($state['phase'] ?? 1),
                SyncStockByPriority::LAST_PHASE
            ),
            'updated'
        );
    }

    /**
     * Cancel a running priority full sync. Sets a cancel flag that the cron checks
     * at tick start AND mid-batch, so the remaining products are left untouched —
     * useful when a misconfiguration is spotted after the first products synced.
     *
     * @return void
     */
    private function syncStocksCancel(): void
    {
        check_admin_referer('moloni_sync_stocks_cancel');

        if (!SyncStockByPriority::isArmed()) {
            add_settings_error(
                'moloni',
                'moloni-full-sync-cancel-none',
                __('Não há nenhuma sincronização completa em curso para cancelar.')
            );

            return;
        }

        SyncStockByPriority::requestCancel();

        add_settings_error(
            'moloni',
            'moloni-full-sync-cancelling',
            __('Cancelamento pedido — a sincronização completa vai parar dentro do lote atual (no máximo alguns produtos a mais) e os restantes não serão alterados.'),
            'updated'
        );

        Storage::$LOGGER->info(__('Cancelamento de sincronização completa pedido (manual)'), [
            'action' => 'stock:sync:manual:full:cancel',
        ]);
    }
}
