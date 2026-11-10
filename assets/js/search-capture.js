(function() {
    function logSearch(term) {
        if (!term || term.length < 2) return;

        // Busca o nonce atualizado (ignora cache)
        fetch(vendevoltaConfig.ajaxUrl + '?action=vendevolta_get_nonce')
            .then(res => res.json())
            .then(res => {
                if (res.success && res.data.nonce) {
                    var url = new URL(vendevoltaConfig.ajaxUrl);
                    url.searchParams.append('action', 'vendevolta_log_ajax');
                    url.searchParams.append('term', term);
                    url.searchParams.append('_wpnonce', res.data.nonce);
                    
                    // keepalive: true garante que o envio termine mesmo se a página mudar
                    fetch(url.toString(), { method: 'GET', keepalive: true }).catch(() => {});
                }
            }).catch(() => {});
    }

    // Escuta o envio de qualquer formulário de busca padrão do WooCommerce/WordPress
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (form.matches('form[role="search"], form.woocommerce-product-search, form.search-form')) {
            var input = form.querySelector('input[type="search"], input[name="s"]');
            if (input) logSearch(input.value);
        }
    }, true);
})();