<?php
if (!defined('ABSPATH')) {
    exit;
}

?>

<?php
// Sweep state shared by the three sweep rows below (stock / fields M→WC / fields WC→M).
$mnState         = \Moloni\Services\Stocks\SyncStockByPriority::getState();
$mnFullArmed     = ($mnState !== null);
$mnMode          = $mnState['mode'] ?? \Moloni\Services\Stocks\SyncStockByPriority::MODE_STOCK;
$mnCancelPending = ($mnFullArmed && get_option(\Moloni\Services\Stocks\SyncStockByPriority::CANCEL_OPTION, false) !== false);

/**
 * Progress line for a sweep row: shown only when the armed sweep matches the
 * row's mode. Escaped output.
 */
$mnProgressLine = static function (string $rowMode) use ($mnState, $mnFullArmed, $mnMode, $mnCancelPending): void {
    if (!$mnFullArmed || $mnMode !== $rowMode) {
        return;
    }

    if ($mnCancelPending) {
        echo '<br/><strong>' . esc_html__('A cancelar — vai parar no próximo lote.') . '</strong>';

        return;
    }

    $mnPhase = (int) $mnState['phase'];
    $mnLabel = \Moloni\Services\Stocks\SyncStockByPriority::PHASES[$mnPhase]['label'] ?? '';

    echo '<br/><strong>' . esc_html(sprintf(
        __('Em curso, em segundo plano — fase %1$d/%2$d: %3$s (página %4$d).'),
        $mnPhase,
        \Moloni\Services\Stocks\SyncStockByPriority::LAST_PHASE,
        $mnLabel,
        (int) $mnState['page']
    )) . '</strong>';
};
?>

<table class="wc_status_table wc_status_table--tools widefat">
    <tbody class="tools">
    <tr>
        <th class="p-8">
            <strong class="name">
                <?php esc_html_e('Forçar sincronização de stocks') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Sincronizar os stocks de todos os produtos usados nos últimos 7 dias') ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(wp_nonce_url(admin_url('admin.php?page=moloni&tab=tools&action=syncStocks&since=' . gmdate('Y-m-d', strtotime("-1 week"))), 'moloni_sync_stocks')) ?>'
               class="button button-large"
            >
                <?php esc_html_e('Forçar sincronização de stocks') ?>
            </a>
        </td>
    </tr>

    <tr>
        <th class="p-8">
            <strong class="name">
                <?php esc_html_e('Sincronização completa de stocks (por prioridade)') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Sincroniza stock e preço de custo de TODOS os produtos, por ordem de prioridade: primeiro os que têm stock e estão publicados (maior risco de preço errado face à última venda), depois com stock mas não publicados, depois publicados sem stock e por fim os restantes. Processa em lotes para respeitar o limite da API Moloni (60 pedidos/min). Clica UMA vez para arrancar — continua sozinho em segundo plano (a cada 5 min, via cron) até concluir.') ?>
                <?php $mnProgressLine(\Moloni\Services\Stocks\SyncStockByPriority::MODE_STOCK); ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(wp_nonce_url(admin_url('admin.php?page=moloni&tab=tools&action=syncStocksFull'), 'moloni_sync_stocks_full')) ?>'
               class="button button-large"
            >
                <?php echo ($mnFullArmed && $mnMode === \Moloni\Services\Stocks\SyncStockByPriority::MODE_STOCK) ? esc_html__('Sincronização completa (em curso)') : esc_html__('Iniciar sincronização completa'); ?>
            </a>
            <?php if ($mnFullArmed && $mnMode === \Moloni\Services\Stocks\SyncStockByPriority::MODE_STOCK && empty($mnCancelPending)) : ?>
                <br/><br/>
                <a href='<?= esc_url(wp_nonce_url(admin_url('admin.php?page=moloni&tab=tools&action=syncStocksCancel'), 'moloni_sync_stocks_cancel')) ?>'
                   class="button button-large"
                >
                    <?php esc_html_e('Cancelar sincronização') ?>
                </a>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <th class="p-8">
            <strong class="name">
                <?php esc_html_e('Sincronizar campos Moloni → WooCommerce') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Aplica APENAS os campos ativados nas Configurações ("Sincronização de campos — Moloni → WooCommerce": EAN, IVA e/ou preço de custo) a todos os produtos Moloni que EXISTEM no WooCommerce (correspondência por referência/SKU). Não altera stock, não cria produtos e não toca em mais nada. Decorre em segundo plano, em lotes (limite API 60 pedidos/min).') ?>
                <?php $mnProgressLine(\Moloni\Services\Stocks\SyncStockByPriority::MODE_FIELDS); ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(wp_nonce_url(admin_url('admin.php?page=moloni&tab=tools&action=syncFieldsToWc'), 'moloni_sync_fields_to_wc')) ?>'
               class="button button-large"
            >
                <?php echo ($mnFullArmed && $mnMode === \Moloni\Services\Stocks\SyncStockByPriority::MODE_FIELDS) ? esc_html__('Sincronização de campos (em curso)') : esc_html__('Sincronizar campos → WooCommerce'); ?>
            </a>
            <?php if ($mnFullArmed && $mnMode === \Moloni\Services\Stocks\SyncStockByPriority::MODE_FIELDS && empty($mnCancelPending)) : ?>
                <br/><br/>
                <a href='<?= esc_url(wp_nonce_url(admin_url('admin.php?page=moloni&tab=tools&action=syncStocksCancel'), 'moloni_sync_stocks_cancel')) ?>'
                   class="button button-large"
                >
                    <?php esc_html_e('Cancelar sincronização') ?>
                </a>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <th class="p-8">
            <strong class="name">
                <?php esc_html_e('Sincronizar campos WooCommerce → Moloni') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Envia APENAS os campos ativados nas Configurações ("Sincronização de campos — WooCommerce → Moloni": EAN, preço, propriedades, imagem e/ou resumo) para todos os produtos WooCommerce que EXISTEM no Moloni (correspondência por referência/SKU). Todos os restantes dados do artigo Moloni (nome, categoria, taxas, stock) ficam exatamente como estão. Não cria produtos. Decorre em segundo plano, em lotes (limite API 60 pedidos/min).') ?>
                <?php $mnProgressLine(\Moloni\Services\Stocks\SyncStockByPriority::MODE_FIELDS_WM); ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(wp_nonce_url(admin_url('admin.php?page=moloni&tab=tools&action=syncFieldsToMoloni'), 'moloni_sync_fields_to_moloni')) ?>'
               class="button button-large"
            >
                <?php echo ($mnFullArmed && $mnMode === \Moloni\Services\Stocks\SyncStockByPriority::MODE_FIELDS_WM) ? esc_html__('Sincronização de campos (em curso)') : esc_html__('Sincronizar campos → Moloni'); ?>
            </a>
            <?php if ($mnFullArmed && $mnMode === \Moloni\Services\Stocks\SyncStockByPriority::MODE_FIELDS_WM && empty($mnCancelPending)) : ?>
                <br/><br/>
                <a href='<?= esc_url(wp_nonce_url(admin_url('admin.php?page=moloni&tab=tools&action=syncStocksCancel'), 'moloni_sync_stocks_cancel')) ?>'
                   class="button button-large"
                >
                    <?php esc_html_e('Cancelar sincronização') ?>
                </a>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <th class="p-8">
            <strong class="name">
                <?php esc_html_e('Listar produtos Moloni') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Listar todos os produtos na empresa Moloni e importar dados para a sua loja WooCommerce') ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(admin_url('admin.php?page=moloni&tab=moloniProductsList')) ?>'
               class="button button-large"
            >
                <?php esc_html_e('Ver produtos Moloni') ?>
            </a>
        </td>
    </tr>

    <tr>
        <th class="p-8">
            <strong class="name">
                <?php esc_html_e('Listar produtos WooCommerce') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Listar todos os produtos na loja WooCommerce e exportar dados para a sua empresa Moloni') ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(admin_url('admin.php?page=moloni&tab=wcProductsList')) ?>'
               class="button button-large"
            >
                <?php esc_html_e('Ver produtos WooCommerce') ?>
            </a>
        </td>
    </tr>

    <tr>
        <th class="p-8">
            <strong class="name">
                <?php esc_html_e('Limpar encomendas pendentes') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Remover todas as encomendas da listagem de encomendas') ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(admin_url('admin.php?page=moloni&tab=tools&action=remInvoiceAll')) ?>'
               class="button button-large"
            >
                <?php esc_html_e('Limpar encomendas pendentes') ?>
            </a>
        </td>
    </tr>

    <tr>
        <th class="p-8">
            <strong class="name">
                <?php esc_html_e('Sair da empresa') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Iremos manter os dados referentes aos documentos já emitidos') ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(admin_url('admin.php?page=moloni&tab=tools&action=logout')) ?>'
               class="button button-large button-primary"
            >
                <?php esc_html_e('Sair da empresa') ?>
            </a>
        </td>
    </tr>
    </tbody>
</table>
