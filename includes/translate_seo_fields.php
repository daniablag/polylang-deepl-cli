<?php

/**
 * Translate AIO SEO fields
 */
WP_CLI::log("🧠 [translate_seo_fields.php] подключён");

function translate_seo_fields($object_id, $lang_from, $lang_to) {
	if (get_post_type($object_id)) {
		$getter = 'get_post_meta';
		$updater = 'update_post_meta';
		$type = 'post';
	} elseif (get_term($object_id)) {
		$getter = 'get_term_meta';
		$updater = 'update_term_meta';
		$type = 'term';
	} else {
		WP_CLI::warning("⚠ Не удалось определить тип объекта для SEO перевода: ID $object_id");
		return;
	}

	translate_aioseo_fields_for_object($getter, $updater, $object_id, $lang_from, $lang_to, $type);

	if ($type === 'term') {
		aioseo_force_save_term_meta($object_id);
	}
}

function translate_aioseo_fields_for_object($meta_getter, $meta_updater, $object_id, $lang_from, $lang_to, $type = 'post') {
	WP_CLI::log("🔍 Проверка наличия SEO мета:");
	WP_CLI::log('_aioseo_title: ' . var_export($meta_getter($object_id, '_aioseo_title', true), true));
	WP_CLI::log('_aioseo_description: ' . var_export($meta_getter($object_id, '_aioseo_description', true), true));

	$aio_title = $meta_getter($object_id, '_aioseo_title', true);
	$aio_desc  = $meta_getter($object_id, '_aioseo_description', true);
	$aio_keys  = $meta_getter($object_id, '_aioseo_keywords', true);

	if (is_string($aio_title) && strlen(trim($aio_title)) > 0) {
		WP_CLI::log("💬 Перевожу TITLE: $aio_title");
		$translated = preserve_aioseo_tags_translate($aio_title, $lang_from, $lang_to);
		$meta_updater($object_id, '_aioseo_title', $translated);
		$meta_updater($object_id, '_aioseo_title_set', 'custom');
		$meta_updater($object_id, '_aioseo_title_tag', '');
		$meta_updater($object_id, '_aioseo_title_source', 'custom');
	}

	if (is_string($aio_desc) && strlen(trim($aio_desc)) > 0) {
		WP_CLI::log("💬 Перевожу DESCRIPTION: $aio_desc");
		$translated = preserve_aioseo_tags_translate($aio_desc, $lang_from, $lang_to);
		$meta_updater($object_id, '_aioseo_description', $translated);
		$meta_updater($object_id, '_aioseo_description_set', 'custom');
		$meta_updater($object_id, '_aioseo_description_tag', '');
		$meta_updater($object_id, '_aioseo_description_source', 'custom');
	}

	if (is_string($aio_keys) && strlen(trim($aio_keys)) > 0) {
		WP_CLI::log("💬 Перевожу KEYWORDS (из _aioseo_keywords): $aio_keys");
		$translated = preserve_aioseo_tags_translate($aio_keys, $lang_from, $lang_to);

		$meta_updater($object_id, '_aioseo_keywords', $translated);
		if ($type === 'term') {
	aioseo_force_save_term_keywords($object_id, $translated);
}
		$meta_updater($object_id, '_aioseo_keywords_set', 'custom');
		$meta_updater($object_id, '_aioseo_keywords_source', 'custom');

		WP_CLI::log("✅ Ключевые слова переведены и обновлены в _aioseo_keywords.");
	}

	$add_keys = $meta_getter($object_id, '_aioseo_additional_keyphrases', true);
	if (!empty($add_keys) && is_array($add_keys)) {
		$translated_keys = [];
		foreach ($add_keys as $kp) {
			$t = $kp;
			if (!empty($kp['keyphrase'])) {
				$t['keyphrase'] = deepl_translate($kp['keyphrase'], $lang_from, $lang_to);
			}
			if (!empty($kp['synonyms']) && is_array($kp['synonyms'])) {
				$t['synonyms'] = array_map(fn($s) => deepl_translate($s, $lang_from, $lang_to), $kp['synonyms']);
			}
			$translated_keys[] = $t;
		}
		$meta_updater($object_id, '_aioseo_additional_keyphrases', $translated_keys);
	}
}

/**
 * Принудительно инициализирует SEO-данные термина в AIOSEO, чтобы они отображались в админке.
 */
function aioseo_force_save_term_meta($term_id) {
	global $wpdb;

	$title       = get_term_meta($term_id, '_aioseo_title', true);
	$description = get_term_meta($term_id, '_aioseo_description', true);
	$keywords    = get_term_meta($term_id, '_aioseo_keywords', true);

	if (empty($title) && empty($description) && empty($keywords)) {
		WP_CLI::warning("⛔ Нет данных для сохранения в wp_aioseo_terms.");
		return;
	}

	$data = [
		'title'       => $title,
		'description' => $description,
		'updated'     => current_time('mysql'),
	];

	if (!empty($keywords) && is_string($keywords)) {
	$data['keywords'] = $keywords;

	// 👇 Добавляем поле как будто пользователь ввёл вручную в админке AIOSEO
	$settings = [
		'general' => [
			'keywords' => $keywords,
		],
	];
	update_term_meta($term_id, '_aioseo_settings', wp_json_encode($settings));
}

	$exists = $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}aioseo_terms WHERE term_id = %d",
		$term_id
	));

	if ($exists) {
		$wpdb->update(
			"{$wpdb->prefix}aioseo_terms",
			$data,
			[ 'term_id' => $term_id ]
		);
		WP_CLI::success("🔁 Обновлены данные для term_id $term_id в wp_aioseo_terms.");
	} else {
		$data['term_id'] = $term_id;
		$data['created'] = current_time('mysql');

		$wpdb->insert(
			"{$wpdb->prefix}aioseo_terms",
			$data
		);
		WP_CLI::success("🆕 Создана запись в wp_aioseo_terms для term_id $term_id.");
	}
}

/**
 * Translate string while preserving template tags like #tag, %%tag%%, {tag}, [tag]
 */
function preserve_aioseo_tags_translate($text, $lang_from, $lang_to) {
	preg_match_all('/(#\w+|%%[^%]+%%|\{[^}]+\}|\[[^\]]+\])/', $text, $matches);
	$placeholders = [];
	$replacements = [];

	foreach ($matches[0] as $i => $tag) {
		$placeholder = '__TAG' . $i . '__';
		$text = str_replace($tag, $placeholder, $text);
		$placeholders[] = $placeholder;
		$replacements[] = $tag;
	}

	$plain = trim(str_replace($placeholders, '', $text));
	if (strlen($plain) < 2) {
		WP_CLI::log("⏭ Нечего переводить: только теги");
		return str_replace($placeholders, $replacements, $text);
	}

	$translated = deepl_translate($text, $lang_from, $lang_to);

	if ($translated === $text) {
		WP_CLI::log("⚠ Перевод идентичен исходному — добавляем пробел для обхода шаблона");
		$translated .= ' ';
	}

	return str_replace($placeholders, $replacements, $translated);
}

function aioseo_force_save_term_keywords($term_id, $keywords) {
	global $wpdb;

	if (empty($keywords)) {
		WP_CLI::log("⏭ Ключевые слова пусты, ничего сохранять.");
		return;
	}

	// Обновляем таблицу wp_aioseo_terms напрямую
	$exists = $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}aioseo_terms WHERE term_id = %d",
		$term_id
	));

	$data = [
		'keywords' => $keywords,
		'updated'  => current_time('mysql'),
	];

	if ($exists) {
		$wpdb->update("{$wpdb->prefix}aioseo_terms", $data, ['term_id' => $term_id]);
		WP_CLI::success("🔁 Ключевые слова обновлены для term_id $term_id в wp_aioseo_terms.");
	} else {
		$data['term_id'] = $term_id;
		$data['created'] = current_time('mysql');
		$wpdb->insert("{$wpdb->prefix}aioseo_terms", $data);
		WP_CLI::success("🆕 Ключевые слова добавлены в wp_aioseo_terms для term_id $term_id.");
	}

	// 💡 Ключ: модель AIOSEO — так данные появятся в админке!
	$term_class = class_exists('\AIOSEO\Plugin\Pro\Models\Term') ? '\AIOSEO\Plugin\Pro\Models\Term' : (
		class_exists('\AIOSEO\Plugin\Common\Models\Term') ? '\AIOSEO\Plugin\Common\Models\Term' : null
	);

	if ($term_class) {
	try {
		$term = $term_class::getTerm($term_id);
		$term->keywords = $keywords;

		// 💡 Это ключевая часть
		if (!isset($term->settings['general'])) {
			$term->settings['general'] = [];
		}
		$term->settings['general']['keywords']          = $keywords;
$term->settings['general']['keywordsSet']       = 'custom';
$term->settings['general']['keywordsSource']    = 'custom';
$term->settings['general']['keywordsModified']  = true; // 💥 ЭТО КЛЮЧЕВОЕ

		$term->save();
		$term_class::clearCache($term_id);

		WP_CLI::success("💾 Ключевые слова сохранены и отображаются в AIOSEO для term_id $term_id.");
	} catch (\Throwable $e) {
		WP_CLI::warning("⚠ Не удалось сохранить через модель: " . $e->getMessage());
	}
}
 else {
		WP_CLI::warning("⚠ Не найден класс AIOSEO Term для сохранения модели.");
	}
}

/**
 * Возвращает список мета-ключей, которые нужно исключить при копировании
 */
function get_seo_excluded_meta_keys(): array {
	return [
		'_aioseo_title',
		'_aioseo_description',
		'_aioseo_keywords',
		'_aioseo_additional_keyphrases',
		'_aioseo_settings',
	];
}