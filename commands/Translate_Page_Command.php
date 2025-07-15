<?php
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('translate-page', 'Translate_Page_Command');
}

class Translate_Page_Command {
    public function __invoke($args, $assoc_args) {
        list($post_id) = $args;
        $lang_from = $assoc_args['lang_from'] ?? 'uk';
        $lang_to = $assoc_args['lang_to'] ?? 'en';

        $post = get_post($post_id);

        if (!$post || !in_array($post->post_type, ['post', 'page'])) {
            WP_CLI::error("Пост с ID $post_id не найден или не является страницей или записью.");
        }

        if (pll_get_post($post_id, $lang_to)) {
            WP_CLI::success("Перевод уже существует.");
            return;
        }

        $translated_title = deepl_translate($post->post_title, $lang_from, $lang_to);
        $translated_excerpt = deepl_translate($post->post_excerpt, $lang_from, $lang_to);
        $translated_content = translate_spectra_blocks($post->post_content, $lang_from, $lang_to);

        $new_post_id = wp_insert_post([
            'post_type'    => $post->post_type,
            'post_status'  => $post->post_status,
            'post_title'   => $translated_title,
            'post_content' => $translated_content,
            'post_excerpt' => $translated_excerpt,
            'post_author'  => $post->post_author,
            'post_name'    => $post->post_name . '-' . $lang_to,
            'post_parent'  => 0,
        ]);

        if (is_wp_error($new_post_id)) {
            WP_CLI::error("Ошибка при создании перевода: " . $new_post_id->get_error_message());
        }

        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($new_post_id, $lang_to);
            pll_set_post_language($post_id, $lang_from);
            pll_save_post_translations([
                $lang_from => $post_id,
                $lang_to   => $new_post_id,
            ]);
        }

        $custom_fields = get_post_custom($post_id);
        foreach ($custom_fields as $key => $values) {
            foreach ($values as $value) {
                if (is_serialized($value)) {
                    update_post_meta($new_post_id, $key, maybe_unserialize($value));
                } else {
                    update_post_meta($new_post_id, $key, $value);
                }
            }
        }

        WP_CLI::success("Перевод страницы/поста '{$post->post_title}' создан (ID $new_post_id).");
    }
}

// Переводит Spectra JSON-блоки внутри Gutenberg
function translate_spectra_blocks($content, $lang_from, $lang_to) {
    return preg_replace_callback(
        '/<!-- wp:([^\s]+)(\s+\{.*?\})? -->((.*?)<!-- \/wp:\1 -->)?/is',
        function ($matches) use ($lang_from, $lang_to) {
            $block_name = $matches[1];
            $attributes_json = $matches[2] ?? '';
            $inner_content = $matches[4] ?? '';

            if (preg_match('/^{.*}$/s', trim($attributes_json))) {
                $attributes = json_decode(trim($attributes_json), true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($attributes)) {
                    $fields_to_translate = ['headingTitle', 'prefix', 'description', 'label', 'text', 'title'];
                    foreach ($fields_to_translate as $field) {
                        if (!empty($attributes[$field])) {
                            $attributes[$field] = deepl_translate($attributes[$field], $lang_from, $lang_to);
                        }
                    }

                    $new_json = json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $new_opening_tag = "<!-- wp:{$block_name} {$new_json} -->";
                } else {
                    $new_opening_tag = $matches[0];
                }
            } else {
                $new_opening_tag = "<!-- wp:{$block_name} -->";
            }

            $translated_inner = deepl_translate($inner_content, $lang_from, $lang_to);
            $closing_tag = "<!-- /wp:{$block_name} -->";

            return "{$new_opening_tag}{$translated_inner}{$closing_tag}";
        },
        $content
    );
}
