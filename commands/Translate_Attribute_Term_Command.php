<?php

WP_CLI::add_command('translate-attribute-value', function ($args) {
    $term_id = (int) $args[0];
    $term = get_term($term_id);

    if (!$term || is_wp_error($term)) {
        WP_CLI::error("Термин с ID $term_id не найден.");
    }

    if (!str_starts_with($term->taxonomy, 'pa_')) {
        WP_CLI::error("Термин $term_id не является значением атрибута (ожидался taxonomy pa_*).");
    }

    // ✅ Универсальное назначение языка
    $lang_from = ensure_polylang_language($term_id, 'term');
    $lang_to   = pll_deepl_get_lang_to();

    ensure_language_exists($lang_from);
    ensure_language_exists($lang_to);

    // ✅ Проверка и очистка битой связи
    $existing_id = ensure_clean_polylang_translation_link($term_id, $lang_to, 'term');
    if ($existing_id) {
        WP_CLI::success("Перевод уже существует.");
        return;
    }

    try {
        $name = retry_with_timeout(fn() => deepl_translate($term->name, $lang_from, $lang_to));
        $desc = retry_with_timeout(fn() => deepl_translate($term->description, $lang_from, $lang_to));

        $new_term = wp_insert_term($name, $term->taxonomy, [
            'slug'        => $term->slug . '-' . $lang_to,
            'description' => $desc,
        ]);

        if (is_wp_error($new_term)) {
            throw new Exception($new_term->get_error_message());
        }

        $new_term_id = $new_term['term_id'];

        // ✅ Перевод SEO-полей
        translate_seo_fields($new_term_id, $lang_from, $lang_to);

        // ✅ Установка языка
        pll_set_term_language($new_term_id, $lang_to);

        // ✅ Сохранение связи перевода
        pll_save_term_translations([
            $lang_from => $term_id,
            $lang_to   => $new_term_id,
        ]);

        WP_CLI::success("Атрибут переведён: $term_id → $new_term_id");
    } catch (Exception $e) {
        log_translation_failure('attribute', $term_id, $e->getMessage());
        WP_CLI::error("Ошибка перевода значения атрибута: " . $e->getMessage());
    }
});
