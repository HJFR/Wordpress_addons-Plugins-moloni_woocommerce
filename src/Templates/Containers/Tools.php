<?php
if (!defined('ABSPATH')) {
    exit;
}

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
                <?php esc_html_e('Sincronização completa de stocks') ?>
            </strong>
            <p class='description'>
                <?php esc_html_e('Sincroniza stock e preço de custo de TODOS os produtos (não só os modificados na última semana). Processa em lotes de 50 para respeitar o limite da API Moloni (60 pedidos/min). Clica UMA vez para arrancar — a partir daí continua sozinho em segundo plano (a cada 5 min, via cron) até concluir.') ?>
                <?php
                $mnFullArmed  = (get_option('moloni_full_sync_offset', false) !== false);
                $mnFullOffset = (int) get_option('moloni_full_sync_offset', 0);
                if ($mnFullArmed) {
                    echo '<br/><strong>' . esc_html(sprintf(__('Em curso, em segundo plano — posição %d.'), $mnFullOffset)) . '</strong>';
                }
                ?>
            </p>
        </th>
        <td class="run-tool p-8 text-right">
            <a href='<?= esc_url(wp_nonce_url(admin_url('admin.php?page=moloni&tab=tools&action=syncStocksFull'), 'moloni_sync_stocks_full')) ?>'
               class="button button-large"
            >
                <?php echo (!empty($mnFullArmed)) ? esc_html__('Sincronização completa (em curso)') : esc_html__('Iniciar sincronização completa'); ?>
            </a>
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
