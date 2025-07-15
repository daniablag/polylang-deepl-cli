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

* `wp translate-post <id>` — переводит товар с учетом Spectra-блоков, описания и всех метаполей
* wp translate-all-products - переводит все товары
* `wp translate-term <id>` — переводит категорию товара, включая родительскую, если нужно
* wp translate-all-categories - Перевод всех категорий
* `wp translate-attribute-term <id>` — переводит значение атрибута (например, цвет, размер)
* wp translate-attribute id - перевод всех значений выбранного аттрибута
* wp translate-all-attributes - перевод всех аттрибутов
* wp translate-woocommerce - переводит все категории, затем значения аттрибутов, затем все товары.
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
4. Вставьте API-ключ DeepL в $key основного файла плагина
5. Используйте WP-CLI команды:

wp translate-post 123
wp translate-term 456
wp translate-attribute-term 789


== Требования ==

- WooCommerce
- Polylang
- Активированный WP-CLI
- Настроенный DeepL API в Polylang

== Лицензия ==

GPLv2 или новее
