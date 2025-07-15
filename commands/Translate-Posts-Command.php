<?php

WP_CLI::add_command('translate-post', function ($args) {
    $post_id = (int) $args[0];
    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'product') {
        WP_CLI::error("Пост с ID $post_id не найден или не является товаром.");
    }

    $lang_from = pll_get_post_language($post_id);
    $lang_to = 'en';

    if (!$lang_from || $lang_from === $lang_to) {
        WP_CLI::error("Исходный язык не определён или совпадает с целевым.");
    }

    if (pll_get_post($post_id, $lang_to)) {
        WP_CLI::success("Перевод уже существует.");
        return;
    }

    $translated_id = wp_insert_post([
        'post_type'    => $post->post_type,
        'post_status'  => $post->post_status,
        'post_title'   => $post->post_title,
        'post_content' => $post->post_content,
        'post_excerpt' => $post->post_excerpt,
        'post_author'  => $post->post_author,
        'post_name'    => $post->post_name . '-' . $lang_to,
        'post_parent'  => 0,
    ]);

    if (is_wp_error($translated_id)) {
        WP_CLI::error("Ошибка при создании перевода: " . $translated_id->get_error_message());
    }

    pll_set_post_language($translated_id, $lang_to);
    pll_save_post_translations([
        $lang_from => $post_id,
        $lang_to   => $translated_id,
    ]);

    $post_obj = get_post($translated_id);
    do_action('wp_insert_post', $translated_id, $post_obj, false);

    $meta = get_post_meta($post_id);
    $excluded = [
        '_edit_lock', '_edit_last', '_yoast_wpseo_title', '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw', '_aioseo_description', '_aioseo_title'
    ];

    foreach ($meta as $key => $values) {
        if (in_array($key, $excluded)) continue;
        foreach ($values as $value) {
            update_post_meta($translated_id, $key, maybe_unserialize($value));
        }
    }

    $product_type = wc_get_product($post_id)->get_type();
    wp_set_object_terms($translated_id, $product_type, 'product_type');
    update_post_meta($translated_id, '_product_type', $product_type);

    try {
        $translated_title   = deepl_translate($post->post_title, $lang_from, $lang_to);
        $translated_excerpt = translate_preserving_tags($post->post_excerpt, $lang_from, $lang_to);
        $translated_content = translate_preserving_tags($post->post_content, $lang_from, $lang_to);
    } catch (Exception $e) {
        WP_CLI::error("Ошибка перевода контента: " . $e->getMessage());
    }

    wp_update_post([
        'ID'           => $translated_id,
        'post_title'   => $translated_title,
        'post_excerpt' => $translated_excerpt,
        'post_content' => $translated_content,
    ]);

    $terms = wp_get_post_terms($post_id, 'product_cat');
    $translated_terms = [];
    foreach ($terms as $term) {
        $translated_term = pll_get_term($term->term_id, $lang_to);
        if ($translated_term) {
            $translated_terms[] = $translated_term;
        }
    }
    if (!empty($translated_terms)) {
        wp_set_post_terms($translated_id, $translated_terms, 'product_cat');
    }

    $taxonomies = wc_get_attribute_taxonomies();
    foreach ($taxonomies as $attr) {
        $taxonomy = 'pa_' . $attr->attribute_name;
        $terms = wp_get_post_terms($post_id, $taxonomy);
        $translated_attr_terms = [];
        foreach ($terms as $term) {
            $translated_term = pll_get_term($term->term_id, $lang_to);
            if ($translated_term) {
                $translated_attr_terms[] = $translated_term;
            }
        }
        if (!empty($translated_attr_terms)) {
            wp_set_post_terms($translated_id, $translated_attr_terms, $taxonomy);
        }
    }

    $attributes = get_post_meta($translated_id, '_product_attributes', true);
    if (is_array($attributes)) {
        foreach ($attributes as $key => &$attr) {
            if (!empty($attr['value']) && taxonomy_exists($key)) {
                $values = explode('|', $attr['value']);
                $translated_values = [];
                foreach ($values as $value_slug) {
                    $term = get_term_by('slug', trim($value_slug), $key);
                    if ($term && pll_get_term_language($term->term_id) === $lang_from) {
                        $translated_term_id = pll_get_term($term->term_id, $lang_to);
                        if ($translated_term_id) {
                            $translated_term = get_term($translated_term_id);
                            $translated_values[] = $translated_term->slug;
                        }
                    }
                }
                $attr['value'] = implode('|', $translated_values);
            }
        }
        update_post_meta($translated_id, '_product_attributes', $attributes);
    }

    $variations = get_children([
        'post_type'   => 'product_variation',
        'post_parent' => $post_id,
        'post_status' => ['publish', 'private'],
    ]);

    foreach ($variations as $variation) {
        $new_variation_id = wp_insert_post([
            'post_title'   => $variation->post_title,
            'post_name'    => $variation->post_name,
            'post_status'  => $variation->post_status,
            'post_type'    => 'product_variation',
            'post_parent'  => $translated_id,
            'menu_order'   => $variation->menu_order,
        ]);

        if (is_wp_error($new_variation_id)) continue;

        $meta = get_post_meta($variation->ID);
        foreach ($meta as $key => $values) {
            foreach ($values as $value) {
                if (strpos($key, 'attribute_pa_') === 0) {
                    $slug = $value;
                    $taxonomy = str_replace('attribute_', '', $key);
                    $term = get_term_by('slug', $slug, $taxonomy);
                    if ($term && pll_get_term_language($term->term_id) === $lang_from) {
                        $translated_term_id = pll_get_term($term->term_id, $lang_to);
                        if ($translated_term_id) {
                            $translated_term = get_term($translated_term_id);
                            $value = $translated_term->slug;
                        }
                    }
                }
                update_post_meta($new_variation_id, $key, maybe_unserialize($value));
            }
        }
    }

    WP_CLI::success("✅ Переведён товар $post_id → $translated_id, с вкладкой доставки и полной структурой");
});

WP_CLI::add_command('translate-all-products', function () {
    $lang_to = 'en';
    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'lang'           => 'uk'
    ]);

    foreach ($products as $product) {
        WP_CLI::log("🔄 Перевод товара ID {$product->ID}");
        try {
            WP_CLI::runcommand("translate-post {$product->ID}");
            sleep(1);
        } catch (Exception $e) {
            WP_CLI::warning("⚠️ Ошибка при переводе товара {$product->ID}: " . $e->getMessage());

            // ✅ Логируем ошибку
            $log_file = WP_CONTENT_DIR . '/translate-errors.log';
            file_put_contents(
                $log_file,
                "[" . date('Y-m-d H:i:s') . "] ID {$product->ID}: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
        }
    }

    WP_CLI::success("✅ Все товары переведены на {$lang_to}.");
});
