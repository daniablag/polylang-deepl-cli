<?php

WP_CLI::add_command('translate-woocommerce', function () {
    WP_CLI::log("🌐 Запуск комплексного перевода WooCommerce…");

    WP_CLI::log("📁 Перевод категорий...");
    WP_CLI::runcommand("translate-all-categories");

    WP_CLI::log("🔣 Перевод атрибутов и значений...");
    WP_CLI::runcommand("translate-all-attributes");

    WP_CLI::log("🛒 Перевод товаров...");
    WP_CLI::runcommand("translate-all-products");

    WP_CLI::success("✅ Перевод WooCommerce завершён полностью.");
});
