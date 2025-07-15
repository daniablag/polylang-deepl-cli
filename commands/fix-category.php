<?php

WP_CLI::add_command('fix-category-languages', function () {
    $taxonomy = 'product_cat';
    $lang_to = 'en';

    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        WP_CLI::error("Категории не найдены.");
    }

    $fixed = 0;
    $skipped = 0;

    foreach ($terms as $term) {
        $current_lang = pll_get_term_language($term->term_id);
        if (!$current_lang) {
            pll_set_term_language($term->term_id, $lang_to);
            WP_CLI::log("✅ Установлен язык '$lang_to' для категории #{$term->term_id} ({$term->name})");
            $fixed++;
        } else {
            $skipped++;
        }
    }

    WP_CLI::success("Завершено. Язык был установлен для $fixed категорий. Пропущено: $skipped.");
});
