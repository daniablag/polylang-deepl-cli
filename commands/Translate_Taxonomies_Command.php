<?php
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('translate-taxonomy', 'Translate_Taxonomy_By_ID_Command');
}

class Translate_Taxonomy_By_ID_Command {
    /**
     * Переводит один термин по ID (например, категорию).
     *
     * ## OPTIONS
     * <id>
     * : ID термина
     *
     * [--lang_from=<lang>]
     * : Исходный язык (по умолчанию: uk)
     *
     * [--lang_to=<lang>]
     * : Язык перевода (по умолчанию: en)
     *
     * ## EXAMPLES
     * wp translate-taxonomy 123 --lang_from=uk --lang_to=en
     */
    public function __invoke($args, $assoc_args) {
        list($term_id) = $args;
        $lang_from = $assoc_args['lang_from'] ?? 'uk';
        $lang_to = $assoc_args['lang_to'] ?? 'en';

        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            WP_CLI::error("Термин с ID {$term_id} не найден.");
        }

        // Проверка: уже есть перевод?
        if (function_exists('pll_get_term_language') && pll_get_term_language($term_id) === $lang_to) {
            WP_CLI::warning("Перевод уже существует для термина ID {$term_id}");
            return;
        }

        $translated_name = deepl_translate($term->name, $lang_from, $lang_to);
        $translated_description = deepl_translate($term->description, $lang_from, $lang_to);

        $new_term = wp_insert_term($translated_name, $term->taxonomy, [
            'description' => $translated_description,
            'slug' => $term->slug . '-' . $lang_to
        ]);

        if (is_wp_error($new_term)) {
            WP_CLI::error("Ошибка при создании перевода: " . $new_term->get_error_message());
        }

        if (function_exists('pll_set_term_language')) {
            pll_set_term_language($new_term['term_id'], $lang_to);
            pll_save_term_translation($term_id, $lang_from);
            pll_save_term_translation($new_term['term_id'], $lang_to);
        }

        WP_CLI::success("Перевод термина '{$term->name}' создан.");
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('translate-all-taxonomies', 'Translate_All_Taxonomies_Command');
}

class Translate_All_Taxonomies_Command {
    /**
     * Переводит все термины всех таксономий с uk на en.
     *
     * ## OPTIONS
     * [--lang_from=<lang>]
     * : Исходный язык (по умолчанию: uk)
     *
     * [--lang_to=<lang>]
     * : Язык перевода (по умолчанию: en)
     *
     * ## EXAMPLES
     * wp translate-all-taxonomies --lang_from=uk --lang_to=en
     */
    public function __invoke($args, $assoc_args) {
        $lang_from = $assoc_args['lang_from'] ?? 'uk';
        $lang_to = $assoc_args['lang_to'] ?? 'en';

        $taxonomies = get_taxonomies([], 'names');
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ]);
            foreach ($terms as $term) {
                if (function_exists('pll_get_term_language') && pll_get_term_language($term->term_id) !== $lang_from) {
                    continue;
                }

                // Проверим, есть ли уже перевод
                $translated_term_id = pll_get_term($term->term_id, $lang_to);
                if ($translated_term_id) {
                    WP_CLI::log("Пропущено: '{$term->name}' (уже есть перевод)");
                    continue;
                }

                $translated_name = deepl_translate($term->name, $lang_from, $lang_to);
                $translated_description = deepl_translate($term->description, $lang_from, $lang_to);

                $new_term = wp_insert_term($translated_name, $taxonomy, [
                    'description' => $translated_description,
                    'slug' => $term->slug . '-' . $lang_to
                ]);

                if (!is_wp_error($new_term)) {
                    pll_set_term_language($new_term['term_id'], $lang_to);
                    pll_save_term_translation($term->term_id, $lang_from);
                    pll_save_term_translation($new_term['term_id'], $lang_to);
                    WP_CLI::success("Переведено: '{$term->name}'");
                } else {
                    WP_CLI::warning("Ошибка при переводе '{$term->name}': " . $new_term->get_error_message());
                }
            }
        }
    }
}
