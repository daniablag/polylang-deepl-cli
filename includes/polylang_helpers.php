<?php

/**
 * Назначает язык по умолчанию, если у объекта он не установлен.
 */
function ensure_polylang_language($id, $type = 'post') {
    $get_lang = $type === 'term' ? 'pll_get_term_language' : 'pll_get_post_language';
    $set_lang = $type === 'term' ? 'pll_set_term_language' : 'pll_set_post_language';

    $lang = $get_lang($id);
    if (!$lang) {
        $lang = pll_default_language();
        $set_lang($id, $lang);

        $name = $type === 'term'
            ? get_term($id)->name
            : get_the_title($id);

        WP_CLI::warning("🛠 Назначен язык '{$lang}' для $type '{$name}' (ID $id)");
    }

    return $lang;
}

/**
 * Проверяет битую связь и удаляет её, если нужно. Возвращает ID перевода или false.
 */
function ensure_clean_polylang_translation_link($id, $lang_to, $type = 'post') {
    $get_link = $type === 'term' ? 'pll_get_term' : 'pll_get_post';
    $get_obj  = $type === 'term' ? 'get_term' : 'get_post';

    $linked_id = $get_link($id, $lang_to);
    if ($linked_id && !$get_obj($linked_id)) {
        delete_metadata($type, $id, '_translations');
        WP_CLI::warning("🧨 Битая связь перевода ($linked_id) удалена у $type ID $id");
        return false;
    }

    return $linked_id;
}
