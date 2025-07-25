=== Polylang + DeepL CLI Translator ===
Contributors: dcwebstudio
Tags: polylang, translation, wp-cli, woocommerce, deepl
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later

WP-CLI команды для перевода товаров, категорий и значений атрибутов WooCommerce через Polylang и DeepL.

== Описание ==

Плагин добавляет команды WP-CLI для автоматического перевода:


* `wp translate-product <id>` — переводит товар с учетом Spectra-блоков, описания и всех метаполей
* wp translate-all-products - переводит все товары
* `wp translate-product-category <id>` — переводит категорию товара, включая родительскую, если нужно
* wp translate-all-product-categories - Перевод всех категорий
* `wp translate-attribute-value <id>` — переводит значение атрибута (например, цвет, размер)
* wp translate-attribute-values <id> - перевод всех значений выбранного аттрибута
* wp translate-all-attribute-values - перевод всех аттрибутов
* wp translate-woocommerce - переводит все категории, затем значения аттрибутов, затем все товары.
* wp translate-custom-post <id> - перевод произвольного типа записи (например popup)
* wp translate-all-custom-posts <post_type> - массовый перевод всех записей указанного типа
* wp translate-post-category <term_id> - перевод категории поста по айди
* wp translate-all-post-categories - перевод всех категорий постов


Поддерживает:
- проверку наличия перевода
- автоматический перевод через DeepL (используется API-ключ из Polylang)
- исключение системных метаполей (Astra, Spectra, Elementor, SEO)
- повтор попытки при сбоях до 15 сек
- лог ошибок в файл `wp-content/cli-translations.log`

== Установка ==

1. Скопируйте папку плагина `polylang-deepl-cli` в `/wp-content/plugins/`
2. Активируйте плагин через админку WordPress
3. Убедитесь, что в Polylang настроен API-ключ DeepL (`pll_settings['machine_translation_services']['deepl']['api_key']`)
4. Настройте исходный и целевой языки в меню «Языки → Auto translate»
5. Вставьте API-ключ DeepL в $key основного файла плагина
6. Используйте WP-CLI команды:

wp translate-product 123
wp translate-product-category 456
wp translate-attribute-value 789


== Требования ==

- WooCommerce
- Polylang
- Активированный WP-CLI
- Настроенный DeepL API в Polylang

== Лицензия ==

GPLv2 или новее
