<?php

WP_CLI::add_command('translate-attribute-term', function ($args) {
    $term_id = (int) $args[0];
    $term = get_term($term_id);

    if (!$term || is_wp_error($term)) {
        WP_CLI::error("Термин с ID $term_id не найден.");
    }

    if (!str_starts_with($term->taxonomy, 'pa_')) {
        WP_CLI::error("Термин $term_id не является значением атрибута (ожидался taxonomy pa_*).");
    }

    $lang_from = pll_get_term_language($term_id);
    $lang_to = 'en';

    ensure_language_exists($lang_from);
    ensure_language_exists($lang_to);

    if (pll_get_term($term_id, $lang_to)) {
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

        // ✅ Устанавливаем язык нового термина
        pll_set_term_language($new_term_id, $lang_to);

        // ✅ Устанавливаем переводную связь
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