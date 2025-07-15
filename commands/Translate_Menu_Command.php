<?php

WP_CLI::add_command('translate-menu', function ($args) {
    $menu_id = 8;
    $lang_from = 'uk';
    $lang_to = 'en';

    $menu = wp_get_nav_menu_object($menu_id);
    if (!$menu) {
        WP_CLI::error("❌ Меню с ID $menu_id не найдено.");
    }

    $translated_menu_id = pll_get_term($menu_id, $lang_to);

    if ($translated_menu_id) {
        $new_menu_id = $translated_menu_id;
        WP_CLI::log("♻️ Обновляем существующее меню ID $new_menu_id");

        // Удаляем старые элементы меню
        $existing_items = wp_get_nav_menu_items($new_menu_id);
        if ($existing_items) {
            foreach ($existing_items as $item) {
                wp_delete_post($item->ID, true);
            }
        }
    } else {
        $new_menu_name = $menu->name . ' (' . strtoupper($lang_to) . ')';
        $new_menu_id = wp_create_nav_menu($new_menu_name);
        if (is_wp_error($new_menu_id)) {
            WP_CLI::error("❌ Не удалось создать меню: " . $new_menu_id->get_error_message());
        }
        pll_set_term_language($new_menu_id, $lang_to);
        pll_save_term_translations([
            $lang_from => $menu_id,
            $lang_to   => $new_menu_id,
        ]);
    }

    $items = wp_get_nav_menu_items($menu_id, ['orderby' => 'menu_order']);
    $id_map = [];

    foreach ($items as $item) {
        $translated_object_id = null;

        if ($item->object_id && $item->type === 'post_type') {
            $translated_object_id = pll_get_post($item->object_id, $lang_to);
        } elseif ($item->object_id && $item->type === 'taxonomy') {
            $translated_object_id = pll_get_term($item->object_id, $lang_to);
        }

        try {
            $title = retry_with_timeout(fn() => deepl_translates($item->title, $lang_from, $lang_to));
        } catch (Exception $e) {
            $title = $item->title;
            WP_CLI::warning("⚠️ Не удалось перевести '{$item->title}': {$e->getMessage()}");
        }

        $args = [
            'menu-item-title'     => $title,
            'menu-item-url'       => $item->url,
            'menu-item-object'    => $item->object,
            'menu-item-object-id' => $translated_object_id ?: $item->object_id,
            'menu-item-type'      => $item->type,
            'menu-item-status'    => 'publish',
            'menu-item-menu-item-parent-id' => 0,
        ];

        if (!empty($item->menu_item_parent) && isset($id_map[$item->menu_item_parent])) {
            $args['menu-item-parent-id'] = $id_map[$item->menu_item_parent];
        }

        $new_item_id = wp_update_nav_menu_item($new_menu_id, 0, $args);
        if (!is_wp_error($new_item_id)) {
            $id_map[$item->ID] = $new_item_id;

            $meta = get_post_custom($item->ID);
            foreach ($meta as $meta_key => $meta_values) {
                foreach ($meta_values as $value) {
                    update_post_meta($new_item_id, $meta_key, maybe_unserialize($value));
                }
            }
        } else {
            WP_CLI::warning("❌ Не удалось добавить пункт меню {$item->title}: " . $new_item_id->get_error_message());
        }
    }

    WP_CLI::success("✅ Меню '{$menu->name}' переведено и назначено как ID {$new_menu_id}");
});
