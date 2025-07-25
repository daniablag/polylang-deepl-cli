<?php

WP_CLI::add_command('translate-attribute-values', function ($args) {
    $attribute_id = (int) $args[0];

    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT attribute_name FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d",
        $attribute_id
    ));

    if (!$row) {
        WP_CLI::error("Атрибут с ID $attribute_id не найден.");
    }

    $taxonomy = 'pa_' . $row->attribute_name;
    translate_attribute_terms_by_taxonomy($taxonomy);
});

WP_CLI::add_command('translate-all-attribute-values', function () {
    global $wpdb;
    $results = $wpdb->get_results("SELECT attribute_name FROM {$wpdb->prefix}woocommerce_attribute_taxonomies");

    if (empty($results)) {
        WP_CLI::error("Атрибуты не найдены.");
    }

    foreach ($results as $row) {
        $taxonomy = 'pa_' . $row->attribute_name;
        WP_CLI::log("\n🔧 Перевод значений атрибута: $taxonomy");
        translate_attribute_terms_by_taxonomy($taxonomy);
    }

    WP_CLI::success("Завершён массовый перевод всех атрибутов.");
});

function translate_attribute_terms_by_taxonomy($taxonomy) {
    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        WP_CLI::log("⏭ Нет значений для $taxonomy.");
        return;
    }

    $lang_to = pll_deepl_get_lang_to();
    $log_file = WP_CONTENT_DIR . '/translation-skipped.log';

    foreach ($terms as $term) {
        $lang_from = ensure_polylang_language($term->term_id, 'term');
        ensure_language_exists($lang_from);
        ensure_language_exists($lang_to);

        $translated = ensure_clean_polylang_translation_link($term->term_id, $lang_to, 'term');
        if ($translated) {
            $msg = "[" . date('Y-m-d H:i:s') . "] SKIPPED $taxonomy #{$term->term_id} ({$term->name}): перевод уже существует\n";
            file_put_contents($log_file, $msg, FILE_APPEND);
            WP_CLI::log(trim($msg));
            continue;
        }

        try {
            $name = retry_with_timeout(fn() => deepl_translate($term->name, $lang_from, $lang_to));
            $desc = retry_with_timeout(fn() => deepl_translate($term->description, $lang_from, $lang_to));

            $new_term = wp_insert_term($name, $taxonomy, [
                'slug' => $term->slug . '-' . $lang_to,
                'description' => $desc,
            ]);

            if (is_wp_error($new_term)) {
                throw new Exception($new_term->get_error_message());
            }

            $new_term_id = $new_term['term_id'];

           // 🔁 Копируем все мета-данные термина
$meta = get_term_meta($term->term_id);
foreach ($meta as $meta_key => $values) {
    foreach ($values as $value) {
        update_term_meta($new_term_id, $meta_key, maybe_unserialize($value));
    }
}

// 🧠 Перевод SEO-полей
WP_CLI::log("🚨 Вызов translate_seo_fields для term $new_term_id ($lang_from → $lang_to)");
translate_seo_fields($new_term_id, $lang_from, $lang_to);

            pll_set_term_language($new_term_id, $lang_to);
            pll_save_term_translations([
                $lang_from => $term->term_id,
                $lang_to   => $new_term_id,
            ]);

            WP_CLI::log("✅ Переведён термин {$term->term_id} → {$new_term_id}");
        } catch (Exception $e) {
            $msg = "[" . date('Y-m-d H:i:s') . "] ERROR $taxonomy #{$term->term_id} ({$term->name}): {$e->getMessage()}\n";
            file_put_contents($log_file, $msg, FILE_APPEND);
            WP_CLI::warning(trim($msg));
        }
    }
}
