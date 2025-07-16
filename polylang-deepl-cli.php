<?php
/**
 * Plugin Name: Polylang + DeepL CLI Translator
 * Description: Команды WP-CLI для перевода товаров, категорий и атрибутов через Polylang и DeepL с улучшениями.
*/

// Global translation languages
if (!defined('PLL_DEEPL_LANG_FROM')) {
    define('PLL_DEEPL_LANG_FROM', 'uk');
}

if (!defined('PLL_DEEPL_LANG_TO')) {
    define('PLL_DEEPL_LANG_TO', 'en');
}

/**
 * Получить код исходного языка.
 * Приоритет: опция WordPress -> константа.
 */
function pll_deepl_get_lang_from() {
    $opt = get_option('pll_deepl_lang_from');
    return $opt ? $opt : PLL_DEEPL_LANG_FROM;
}

/**
 * Получить код языка перевода.
 * Приоритет: опция WordPress -> константа.
 */
function pll_deepl_get_lang_to() {
    $opt = get_option('pll_deepl_lang_to');
    return $opt ? $opt : PLL_DEEPL_LANG_TO;
}

add_action('admin_init', 'pll_deepl_register_settings');
function pll_deepl_register_settings() {
    register_setting('pll_deepl_settings', 'pll_deepl_lang_from');
    register_setting('pll_deepl_settings', 'pll_deepl_lang_to');
}

add_action('admin_menu', 'pll_deepl_add_admin_page');
function pll_deepl_add_admin_page() {
    add_menu_page(
        'DeepL Auto Translate',     // Заголовок страницы
        'Auto Translate',           // Название в меню
        'manage_options',           // Права доступа
        'pll-deepl-auto',           // Slug
        'pll_deepl_render_page',    // Функция
        'dashicons-translation',    // Иконка
        60                          // Позиция
    );
}


function pll_deepl_render_page() { ?>
    <div class="wrap">
        <h1><?php esc_html_e('Auto translate', 'polylang-deepl-cli'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('pll_deepl_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="pll_deepl_lang_from"><?php esc_html_e('Translate from', 'polylang-deepl-cli'); ?></label></th>
                    <td><input name="pll_deepl_lang_from" id="pll_deepl_lang_from" type="text" value="<?php echo esc_attr( get_option('pll_deepl_lang_from', PLL_DEEPL_LANG_FROM) ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pll_deepl_lang_to"><?php esc_html_e('Translate to', 'polylang-deepl-cli'); ?></label></th>
                    <td><input name="pll_deepl_lang_to" id="pll_deepl_lang_to" type="text" value="<?php echo esc_attr( get_option('pll_deepl_lang_to', PLL_DEEPL_LANG_TO) ); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<?php }

function translate_preserving_tags($html, $lang_from, $lang_to) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $body = $doc->getElementsByTagName('body')->item(0);

    // Переводим текст внутри всех видимых узлов
    $nodes = $xpath->query('//body//text()[normalize-space()]');
    foreach ($nodes as $node) {
        if (trim($node->nodeValue)) {
            $translated = deepl_translate($node->nodeValue, $lang_from, $lang_to);
            $node->nodeValue = $translated;
        }
    }

    // Переводим Spectra JSON-блоки внутри комментариев
    $html = $doc->saveHTML();
    $html = preg_replace_callback(
        '/<!--\s*wp:uagb\/(.*?)\{(.*?)\}\s*-->/',
        function ($matches) use ($lang_from, $lang_to) {
            $prefix = "<!-- wp:uagb/" . $matches[1];
            $json_str = '{' . $matches[2] . '}';

            $decoded = json_decode($json_str, true);
            if (!$decoded || !is_array($decoded)) return $matches[0];

            $fields = ['heading', 'subHeading', 'prefix', 'suffix', 'text', 'label'];
            foreach ($fields as $field) {
                if (!empty($decoded[$field]) && is_string($decoded[$field])) {
                    $decoded[$field] = deepl_translate($decoded[$field], $lang_from, $lang_to);
                }
            }

            $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $prefix . $encoded . ' -->';
        },
        $html
    );

    // Возвращаем только innerHTML <body>
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $body = $doc->getElementsByTagName('body')->item(0);
    $innerHTML = '';
    foreach ($body->childNodes as $child) {
        $innerHTML .= $doc->saveHTML($child);
    }

    return trim($innerHTML);
}

if (defined('WP_CLI') && WP_CLI) {
	require_once __DIR__ . '/includes/polylang_helpers.php';
	require_once __DIR__ . '/includes/translate_seo_fields.php';
    require_once __DIR__ . '/commands/Translate_product_category_Command.php';
    require_once __DIR__ . '/commands/Translate_Attribute_Term_Command.php';
	require_once __DIR__ . '/commands/Translate_Attribute_Command.php';
	require_once __DIR__ . '/commands/translate-woocommerce.php';
	require_once __DIR__ . '/commands/Translate-Posts-Command.php';
    require_once __DIR__ . '/commands/Translate_Taxonomies_Command.php';
    require_once __DIR__ . '/commands/Translate_Page_Command.php';
    require_once __DIR__ . '/commands/Translate_Menu_Command.php';
    require_once __DIR__ . '/commands/Translate_Custom_Post_Command.php';
	
}

/**
 * Выполняет перевод текста через DeepL.
 */
function deepl_translate($text, $from, $to) {
    // 👉 Ручной ключ (можно заменить)
    $key = '451d4e24-9768-4544-980e-5a62474cb703:fx';

    // 🔍 Логируем для отладки
    WP_CLI::log("KEY: $key");
    WP_CLI::log("TEXT: " . (is_string($text) ? $text : '[не строка]'));

    // 🛑 Проверка ключа
    if (!$key || !is_string($key) || strlen($key) < 10) {
        WP_CLI::error("❌ Нет API-ключа DeepL.");
    }

    // 🛑 Проверка текста
    if (!is_string($text) || trim($text) === '') {
        WP_CLI::log("⏭ Пустой или некорректный текст — пропускаем перевод.");
        return $text;
    }

    // 🛰 Отправка запроса
    $response = wp_remote_post('https://api-free.deepl.com/v2/translate', [
        'timeout' => 15,
        'body' => [
            'auth_key'    => $key,
            'text'        => $text,
            'source_lang' => strtoupper($from),
            'target_lang' => strtoupper($to),
        ]
    ]);

    // ⚠️ Ошибка запроса
    if (is_wp_error($response)) {
        throw new Exception("Ошибка запроса DeepL: " . $response->get_error_message());
    }

    // 🧾 Ответ
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!isset($data['translations'][0]['text'])) {
        throw new Exception("Ответ DeepL некорректен: $body");
    }

    // ✅ Успех
    return $data['translations'][0]['text'];
}


/**
 * Переводит значение, если оно строка.
 */
function maybe_translate($value, $from, $to) {
    return (is_string($value) && strlen($value) > 1 && mb_detect_encoding($value, 'UTF-8', true))
        ? deepl_translate($value, $from, $to)
        : $value;
}

/**
 * Исключает метаполя, не подлежащие переводу.
 */
function is_meta_excluded($key) {
    $excluded_prefixes = [
        '_edit_', '_wp_', '_thumbnail_id', 'astra-', '_astra',
        'uagb_', '_spectra', 'spectra_', '_vc_', '_elementor_',
        '_yoast_', 'rank_math_'
    ];
    foreach ($excluded_prefixes as $prefix) {
        if (stripos($key, $prefix) === 0) return true;
    }
    return false;
}

/**
 * Запись ошибки в лог.
 */
function log_translation_failure($type, $id, $error) {
    $log_file = WP_CONTENT_DIR . '/cli-translations.log';
    $line = sprintf("[%s] ❌ Ошибка %s #%d: %s\n", date('Y-m-d H:i:s'), $type, $id, $error);
    file_put_contents($log_file, $line, FILE_APPEND);
}

/**
 * Повторяет выполнение функции при ошибке, максимум 15 секунд.
 */
function retry_with_timeout($callback, $max_seconds = 15) {
    $start = time();
    do {
        try {
            return $callback();
        } catch (Exception $e) {
            if ((time() - $start) >= $max_seconds) {
                throw $e;
            }
            sleep(1);
        }
    } while (true);
}

/**
 * Проверяет наличие языка в Polylang.
 */
function ensure_language_exists($lang_code) {
    $langs = pll_languages_list();
    if (!in_array($lang_code, $langs, true)) {
        WP_CLI::error("Язык '$lang_code' не найден в настройках Polylang.");
    }
}
