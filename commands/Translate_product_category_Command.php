<?php

function translate_single_category_term($term, $lang_to = null) {
    $lang_to = $lang_to ?: pll_deepl_get_lang_to();
    $taxonomy = 'product_cat';
    $term_id = $term->term_id;

    // ✅ Назначение языка через универсальную функцию
    $lang_from = ensure_polylang_language($term_id, 'term');

    ensure_language_exists($lang_from);
    ensure_language_exists($lang_to);

    // ✅ Проверка на существующий или битый перевод
    $translated_id = ensure_clean_polylang_translation_link($term_id, $lang_to, 'term');
    if ($translated_id) {
        log_category_skip($term_id, $term->name, 'перевод уже существует');
        return;
    }

    try {
        $name = retry_with_timeout(fn() => deepl_translate($term->name, $lang_from, $lang_to));
        $desc = retry_with_timeout(fn() => deepl_translate($term->description, $lang_from, $lang_to));

        $parent_translated = 0;
        if ($term->parent) {
            $parent_translated = pll_get_term($term->parent, $lang_to);
            if (!$parent_translated) {
                log_category_skip($term_id, $term->name, 'родитель не переведён');
                return;
            }
        }

        $new_term = wp_insert_term($name, $taxonomy, [
            'description' => $desc,
            'slug'        => $term->slug . '-' . $lang_to,
            'parent'      => $parent_translated,
        ]);

        if (is_wp_error($new_term)) {
            throw new Exception($new_term->get_error_message());
        }

        $new_term_id = $new_term['term_id'];

        // ✅ SEO-поля
        translate_seo_fields($new_term_id, $lang_from, $lang_to);

        // ✅ Кастомное HTML-поле
        $custom_content = get_term_meta($term_id, 'custom_term_meta', true);
        if (!empty($custom_content)) {
            $translated_content = translate_preserving_tags($custom_content, $lang_from, $lang_to);
            update_term_meta($new_term_id, 'custom_term_meta', wp_kses_post($translated_content));
        }

        pll_set_term_language($new_term_id, $lang_to);
        pll_save_term_translations([
            $lang_from => $term_id,
            $lang_to   => $new_term_id,
        ]);

        // обновление (может быть нужно для хуков)
        wp_update_term($new_term_id, $taxonomy, []);

        WP_CLI::log("✅ Категория $term_id → $new_term_id переведена.");
    } catch (Exception $e) {
        log_category_error($term_id, $term->name, $e->getMessage());
    }
}

WP_CLI::add_command('translate-product-category', function ($args) {
    $term_id = (int) $args[0];
    $taxonomy = 'product_cat';

    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) {
        WP_CLI::error("Категория с ID $term_id не найдена.");
    }

    translate_single_category_term($term);
});

WP_CLI::add_command('translate-all-product-categories', function () {
    $taxonomy = 'product_cat';
    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'orderby'    => 'parent',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        WP_CLI::error("Нет категорий для перевода.");
    }

    foreach ($terms as $term) {
        translate_single_category_term($term);
    }

    WP_CLI::success("Завершён массовый перевод категорий.");
});

function log_category_skip($term_id, $name, $reason) {
    $taxonomy = 'product_cat';
    $log_file = WP_CONTENT_DIR . '/translation-skipped.log';
    $msg = "[" . date('Y-m-d H:i:s') . "] SKIPPED $taxonomy #$term_id ($name): $reason\n";
    file_put_contents($log_file, $msg, FILE_APPEND);
    WP_CLI::log(trim($msg));
}

function log_category_error($term_id, $name, $error) {
    $taxonomy = 'product_cat';
    $log_file = WP_CONTENT_DIR . '/translation-skipped.log';
    $msg = "[" . date('Y-m-d H:i:s') . "] ERROR $taxonomy #$term_id ($name): $error\n";
    file_put_contents($log_file, $msg, FILE_APPEND);
    WP_CLI::warning(trim($msg));
}
