<?php
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('translate-custom-post', [Translate_Custom_Post_Command::class, '__invoke']);
    WP_CLI::add_command('translate-all-custom-posts', [Translate_Custom_Post_Command::class, 'translate_all']);
}

class Translate_Custom_Post_Command {
    public function __invoke($args) {
        list($post_id) = $args;
        $post = get_post($post_id);
        $lang_from = PLL_DEEPL_LANG_FROM;
        $lang_to   = PLL_DEEPL_LANG_TO;

        if (!$post) {
            WP_CLI::error("Пост с ID $post_id не найден.");
        }

        if (pll_get_post($post_id, $lang_to)) {
            WP_CLI::success('Перевод уже существует.');
            return;
        }

        $translated_title   = deepl_translate($post->post_title, $lang_from, $lang_to);
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
            WP_CLI::error('Ошибка при создании перевода: ' . $new_post_id->get_error_message());
        }

        pll_set_post_language($new_post_id, $lang_to);
        pll_set_post_language($post_id, $lang_from);
        pll_save_post_translations([
            $lang_from => $post_id,
            $lang_to   => $new_post_id,
        ]);

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

        WP_CLI::success("Перевод {$post->post_type} '{$post->post_title}' создан (ID $new_post_id).");
    }

    public static function translate_all($args) {
        $post_type = $args[0] ?? '';
        if (!$post_type) {
            WP_CLI::error('Не указан тип записи.');
        }

        $posts = get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'lang'           => PLL_DEEPL_LANG_FROM,
        ]);

        if (empty($posts)) {
            WP_CLI::error("Нет записей типа {$post_type} для перевода.");
        }

        foreach ($posts as $post) {
            WP_CLI::log("🔄 Перевод {$post_type} ID {$post->ID}");
            WP_CLI::runcommand("translate-custom-post {$post->ID}");
            sleep(1);
        }

        WP_CLI::success("Все записи типа {$post_type} переведены.");
    }
}
?>
