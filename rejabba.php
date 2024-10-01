<?php
/*
Plugin Name: ReJabba
Description: Плагин для автоматического переписывания черновиков с использованием OpenAI API и интеграцией с Yoast SEO.
Version: 2.6.1
Author: Ваше Имя
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ReJabba
{

    private $api_key_option_name = 'rejabba_api_key';
    private $frequency_option_name = 'rejabba_frequency';
    private $placement_option_name = 'rejabba_placement';
    private $content_prompt_option_name = 'rejabba_content_prompt';
    private $title_prompt_option_name = 'rejabba_title_prompt';
    private $rewrite_title_option_name = 'rejabba_rewrite_title';
    private $model_option_name = 'rejabba_model';
    private $style_option_name = 'rejabba_style';
    private $language_option_name = 'rejabba_language';
    private $temperature_option_name = 'rejabba_temperature';
    private $max_tokens_option_name = 'rejabba_max_tokens';
    private $categories_option_name = 'rejabba_categories';
    private $analytics_option_name = 'rejabba_analytics';
    private $filter_patterns_option_name = 'rejabba_filter_patterns';
    private $replace_pairs_option_name = 'rejabba_replace_pairs';
    private $additional_category_option_name = 'rejabba_additional_category';
    private $author_option_name = 'rejabba_author';
    private $yoast_settings_option_name = 'rejabba_yoast_settings';

    // Переменная для хранения ID черновика, который обрабатывается в данный момент
    private $current_draft_id_option = 'rejabba_current_draft_id';

    // Опции Yoast SEO по умолчанию
    private $yoast_default_settings = [
        'title_separator' => '-',
        'meta_robots_nofollow' => 'noindex',
        'meta_robots_noindex' => 'index',
        'opengraph-title' => '',
        'twitter-title' => '',
        // ... другие настройки Yoast по умолчанию
    ];

    // Опции для управления ключевыми словами
    private $keyword_options = [
        'keyword_count' => 3, // Количество ключевых слов по умолчанию
    ];

    // Опции для управления SEO заголовком
    private $seo_title_options = [
        'title_length' => 60, // Максимальная длина заголовка по умолчанию
        'title_format' => '%keyword% | %blog_name%' // Формат заголовка по умолчанию
    ];

    // Опции для управления мета описанием
    private $meta_description_options = [
        'description_length' => 160, // Максимальная длина мета описания по умолчанию
        'description_format' => '%excerpt%' // Формат мета описания по умолчанию
    ];

    // Флаг для отслеживания успешности обработки последнего черновика
    private $last_draft_processed_successfully = false;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Действие для обработки черновиков
        add_action('rejabba_process_drafts', [$this, 'process_drafts']);

        // Запланировать первое cron событие 
        if (!wp_next_scheduled('rejabba_process_drafts')) {
            $this->schedule_next_cron_event();
        }

        add_action('wp_ajax_rejabba_test_api', [$this, 'test_api_connection']);
        add_action('wp_ajax_rejabba_test_drafts', [$this, 'test_drafts']);
        add_action('save_post', [$this, 'assign_additional_category']);
        add_action('save_post', [$this, 'assign_random_author']);
    }

    public function create_admin_menu()
    {
        add_menu_page(
            'ReJabba Settings',
            'ReJabba',
            'manage_options',
            'rejabba',
            [$this, 'plugin_settings_page'],
            'dashicons-admin-tools'
        );

        add_submenu_page(
            'rejabba',
            'ReJabba Analytics',
            'Аналитика',
            'manage_options',
            'rejabba_analytics',
            [$this, 'plugin_analytics_page']
        );
    }

    public function plugin_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Объединяем все настройки по умолчанию
        $all_default_settings = array_merge(
            $this->yoast_default_settings,
            $this->keyword_options,
            $this->seo_title_options,
            $this->meta_description_options
        );

        // Получаем сохраненные настройки, используя объединенные настройки по умолчанию
        $yoast_settings = wp_parse_args(get_option($this->yoast_settings_option_name, []), $all_default_settings);

        ?>
        <div class="wrap">
            <h1>Настройки ReJabba</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('rejabba_settings_group');
                do_settings_sections('rejabba');
                ?>

                <!-- Раздел OpenAI API -->
                <h2>Настройки OpenAI API</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="<?php echo esc_attr($this->api_key_option_name); ?>">API
                                Key:</label></th>
                        <td><input type="text" name="<?php echo esc_attr($this->api_key_option_name); ?>"
                                   id="<?php echo esc_attr($this->api_key_option_name); ?>"
                                   value="<?php echo esc_attr(get_option($this->api_key_option_name)); ?>"/>
                        </td>
                    </tr>
                </table>

                <!-- Раздел публикации -->
                <h2>Настройки публикации</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Частота проверки (мин):</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr($this->frequency_option_name); ?>"
                                   value="<?php echo esc_attr(get_option($this->frequency_option_name, '10')); ?>"/>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Размещение поста:</th>
                        <td>
                            <select name="<?php echo esc_attr($this->placement_option_name); ?>">
                                <option value="publish" <?php selected(get_option($this->placement_option_name), 'publish'); ?>>
                                    Опубликовать
                                </option>
                                <option value="draft" <?php selected(get_option($this->placement_option_name), 'draft'); ?>>
                                    Черновик
                                </option>
                                <option value="private" <?php selected(get_option($this->placement_option_name), 'private'); ?>>
                                    Личный
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Дополнительная рубрика:</th>
                        <td>
                            <select name="<?php echo esc_attr($this->additional_category_option_name); ?>">
                                <option value="">Не выбрано</option>
                                <?php
                                $categories = get_categories(['hide_empty' => false]);
                                foreach ($categories as $category) {
                                    echo '<option value="' . esc_attr($category->term_id) . '"' . selected(get_option($this->additional_category_option_name), $category->term_id, false) . '>' . esc_html($category->name) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Автор записи:</th>
                        <td>
                            <select name="<?php echo esc_attr($this->author_option_name); ?>">
                                <option value="default" <?php selected(get_option($this->author_option_name), 'default'); ?>>
                                    По умолчанию
                                </option>
                                <option value="random" <?php selected(get_option($this->author_option_name), 'random'); ?>>
                                    Случайно (редактор)
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <!-- Раздел настройки переписывания -->
                <h2>Настройки переписывания</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Задача для Контента:</th>
                        <td>
                            <textarea name="<?php echo esc_attr($this->content_prompt_option_name); ?>" rows="5"
                                      cols="50"><?php echo esc_textarea(get_option($this->content_prompt_option_name)); ?></textarea>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Задача для Заголовка:</th>
                        <td>
                            <textarea name="<?php echo esc_attr($this->title_prompt_option_name); ?>" rows="5"
                                      cols="50"><?php echo esc_textarea(get_option($this->title_prompt_option_name)); ?></textarea>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Переписывать заголовок?:</th>
                        <td>
                            <select name="<?php echo esc_attr($this->rewrite_title_option_name); ?>">
                                <option value="yes" <?php selected(get_option($this->rewrite_title_option_name), 'yes'); ?>>
                                    Да
                                </option>
                                <option value="no" <?php selected(get_option($this->rewrite_title_option_name), 'no'); ?>>
                                    Нет
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Паттерны для фильтрации (регулярные выражения):</th>
                        <td>
                            <textarea name="<?php echo esc_attr($this->filter_patterns_option_name); ?>" rows="5"
                                      cols="50"><?php echo esc_textarea(get_option($this->filter_patterns_option_name)); ?></textarea>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Пары для замены текста (формат "что_заменить::на_что", каждая пара с новой
                            строки):
                        </th>
                        <td>
                            <textarea name="<?php echo esc_attr($this->replace_pairs_option_name); ?>" rows="5"
                                      cols="50"><?php echo esc_textarea(get_option($this->replace_pairs_option_name)); ?></textarea>
                        </td>
                    </tr>
                </table>


                <!-- Раздел настройки модели -->
                <h2>Настройки модели</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Модель OpenAI:</th>
                        <td>
                            <select name="<?php echo esc_attr($this->model_option_name); ?>">
                                <option value="gpt-4-0314" <?php selected(get_option($this->model_option_name), 'gpt-4-0314'); ?>>
                                    GPT-4-0314 (default)
                                </option>
                                <option value="gpt-4" <?php selected(get_option($this->model_option_name), 'gpt-4'); ?>>
                                    GPT-4
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Стиль текста:</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->style_option_name); ?>"
                                   value="<?php echo esc_attr(get_option($this->style_option_name, 'neutral')); ?>"/>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Язык текста:</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->language_option_name); ?>"
                                   value="<?php echo esc_attr(get_option($this->language_option_name, 'ru')); ?>"/>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Температура (0-1):</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr($this->temperature_option_name); ?>"
                                   min="0"
                                   max="1" step="0.1"
                                   value="<?php echo esc_attr(get_option($this->temperature_option_name, '0.7')); ?>"/>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Максимальное количество токенов:</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr($this->max_tokens_option_name); ?>"
                                   value="<?php echo esc_attr(get_option($this->max_tokens_option_name, '1024')); ?>"/>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Категории для обработки (ID через запятую):</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->categories_option_name); ?>"
                                   value="<?php echo esc_attr(is_array(get_option($this->categories_option_name)) ? implode(',', get_option($this->categories_option_name)) : ''); ?>"/>
                        </td>
                    </tr>
                </table>

                <!-- Раздел Yoast SEO -->
                <h2>Настройки Yoast SEO</h2>
                <p>Эти настройки будут использоваться для заполнения метаданных Yoast SEO при публикации или обновлении
                    записей.</p>
                <table class="form-table">
                    <?php

                    foreach ($this->yoast_default_settings as $key => $default_value) {
                        $label = ucwords(str_replace(['-', '_'], ' ', $key));
                        $option_name = $this->yoast_settings_option_name . '[' . $key . ']';
                        ?>
                        <tr valign="top">
                            <th scope="row"><label
                                        for="<?php echo esc_attr($option_name); ?>"><?php echo esc_html($label); ?>:</label>
                            </th>
                            <td>
                                <?php if (is_bool($default_value)): ?>
                                    <input type="checkbox" name="<?php echo esc_attr($option_name); ?>"
                                           id="<?php echo esc_attr($option_name); ?>" value="1" <?php checked($yoast_settings[$key], 1); ?> />
                                <?php else: ?>
                                    <input type="text" name="<?php echo esc_attr($option_name); ?>"
                                           id="<?php echo esc_attr($option_name); ?>"
                                           value="<?php echo esc_attr($yoast_settings[$key]); ?>"/>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php } ?>

                    <!-- Настройки ключевых слов -->
                    <tr valign="top">
                        <th scope="row"><label for="keyword_count">Количество ключевых слов:</label></th>
                        <td>
                            <input type="number" name="rejabba_yoast_settings[keyword_count]" id="keyword_count"
                                   value="<?php echo esc_attr($yoast_settings['keyword_count']); ?>" min="1">
                        </td>
                    </tr>

                    <!-- Настройки SEO заголовка -->
                    <tr valign="top">
                        <th scope="row"><label for="title_length">Максимальная длина заголовка:</label></th>
                        <td>
                            <input type="number" name="rejabba_yoast_settings[title_length]" id="title_length"
                                   value="<?php echo esc_attr($yoast_settings['title_length']); ?>" min="1">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="title_format">Формат заголовка:</label></th>
                        <td>
                            <input type="text" name="rejabba_yoast_settings[title_format]" id="title_format"
                                   value="<?php echo esc_attr($yoast_settings['title_format']); ?>">
                            <br>
                            <small>Доступные переменные: %keyword%, %blog_name%, %post_title%</small>
                        </td>
                    </tr>

                    <!-- Настройки мета описания -->
                    <tr valign="top">
                        <th scope="row"><label for="description_length">Максимальная длина мета
                                описания:</label></th>
                        <td>
                            <input type="number" name="rejabba_yoast_settings[description_length]" id="description_length"
                                   value="<?php echo esc_attr($yoast_settings['description_length']); ?>" min="1">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="description_format">Формат мета описания:</label></th>
                        <td>
                            <input type="text" name="rejabba_yoast_settings[description_format]" id="description_format"
                                   value="<?php echo esc_attr($yoast_settings['description_format']); ?>">
                            <br>
                            <small>Доступные переменные: %excerpt%, %post_content%, %blog_description%</small>
                        </td>
                    </tr>
                </table>


                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Тестирование API</h2>
            <button id="rejabba-test-api" class="button">Test API</button>
            <div id="rejabba-test-api-result"></div>

            <hr>
            <h2>Тестирование черновиков</h2>
            <label for="rejabba_drafts">Выберите черновики для теста:</label>
            <select id="rejabba_drafts" name="rejabba_drafts[]" multiple>
                <?php
                $drafts = get_posts(['post_status' => 'draft', 'post_type' => 'post']);
                foreach ($drafts as $draft) {
                    echo '<option value="' . esc_html($draft->ID) . '">' . esc_html($draft->post_title) . '</option>';
                }
                ?>
            </select>
            <button id="rejabba-test-drafts" class="button">Тестировать черновики</button>
            <div id="rejabba-test-draft-result"></div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                $('#rejabba-test-api').on('click', function () {
                    $.post(ajaxurl, {action: 'rejabba_test_api'}, function (response) {
                        $('#rejabba-test-api-result').html(response);
                    });
                });

                $('#rejabba-test-drafts').on('click', function () {
                    var draft_ids = $('#rejabba_drafts').val();
                    $.post(ajaxurl, {
                        action: 'rejabba_test_drafts',
                        draft_ids: draft_ids
                    }, function (response) {
                        $('#rejabba-test-draft-result').html(response);
                    });
                });
            });
        </script>
        <?php
    }

    public function plugin_analytics_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $analytics = get_option($this->analytics_option_name, []);
        ?>
        <div class="wrap">
            <h1>Аналитика ReJabba</h1>
            <form method="post">
                <input type="hidden" name="clear_analytics" value="true">
                <input type="submit" class="button" value="Очистить аналитику">
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <th>Дата</th>
                    <th>ID Записи</th>
                    <th>Действие</th>
                    <th>Результат</th>
                    <th>Количество слов (исходное/новое)
                    </th>
                    <th>Время выполнения</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($analytics)): ?>
                    <?php foreach ($analytics as $entry): ?>
                        <tr>
                            <td><?php echo esc_html($entry['date']); ?></td>
                            <td><?php echo esc_html($entry['post_id']); ?></td>
                            <td><?php echo esc_html($entry['action']); ?></td>
                            <td><?php echo esc_html($entry['result']); ?></td>
                            <td><?php echo esc_html($entry['word_count_before']) . '/' . esc_html($entry['word_count_after']); ?></td>
                            <td><?php echo esc_html($entry['execution_time']) . ' сек'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">Нет данных для отображения.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        if (isset($_POST['clear_analytics'])) {
            $this->clear_analytics();
            echo '<div class="updated"><p>Аналитика успешно очищена.</p></div>';
        }
    }

    public function register_settings()
    {
        // ... (регистрация остальных настроек) ...

        // Регистрация настроек Yoast SEO
        register_setting('rejabba_settings_group', $this->yoast_settings_option_name);

        
    }

    /**
     * Планирует следующее событие cron через заданный интервал. 
     */
    public function schedule_next_cron_event() {
        // Получаем интервал из настроек плагина (в минутах)
        $frequency = intval(get_option($this->frequency_option_name, '10'));

        // Преобразуем минуты в секунды 
        $interval = $frequency * 60;

        // Планируем следующее событие cron 
        wp_schedule_single_event(time() + $interval, 'rejabba_process_drafts'); 
        error_log("ReJabba: Scheduled next cron event in {$interval} seconds.");
    }

    /**
     * Удаляет все запланированные события `rejabba_process_drafts`. 
     */ 
    public function unschedule_cron_jobs() {
        while (wp_next_scheduled('rejabba_process_drafts')) {
            wp_unschedule_event(wp_next_scheduled('rejabba_process_drafts'), 'rejabba_process_drafts');
        }
        error_log("ReJabba: Unscheduled all cron events."); 
    }

    /** 
     * Обрабатывает все доступные черновики и планирует следующее событие cron.
     */ 
    public function process_drafts() {
        error_log("ReJabba: process_drafts function started.");

        $drafts = get_posts([ 
            'post_status' => 'draft',
            'post_type' => 'post',
            'posts_per_page' => -1
        ]);


        foreach ($drafts as $draft) {
            $this->process_single_draft($draft->ID);
        }

        // Планируем следующий запуск cron 
        $this->schedule_next_cron_event();
    }

    /** 
     * Обрабатывает один черновик.
     * 
     * @param int $post_id ID черновика.
     */ 
    public function process_single_draft($post_id)
    { 
        error_log("ReJabba: process_single_draft function started for post ID: {$post_id}"); 
        // Устанавливаем флаг успешности в false по умолчанию 
        $this->last_draft_processed_successfully = false;
        $draft = get_post($post_id);

        if (!$draft || $draft->post_status !== 'draft') {
            // Сбрасываем ID текущего черновика, если он не найден или не является черновиком 
            delete_option($this->current_draft_id_option);
            return; 
        }

        $start_time = microtime(true);

        $content_prompt = get_option($this->content_prompt_option_name);
        $title_prompt = get_option($this->title_prompt_option_name);
        $api_key = get_option($this->api_key_option_name);
        $model = get_option($this->model_option_name);
        $style = get_option($this->style_option_name, 'neutral');
        $language = get_option($this->language_option_name, 'ru'); 
        $rewrite_title = get_option($this->rewrite_title_option_name, 'no');
        $temperature = floatval(get_option($this->temperature_option_name, '0.7'));
        $max_tokens = intval(get_option($this->max_tokens_option_name, '1024'));

        // Получаем настройки Yoast
        $yoast_settings = get_option($this->yoast_settings_option_name, []);

        $content_response = $this->send_to_openai($draft->post_content, $content_prompt, $api_key, $model, $style, $language, $temperature, $max_tokens);

        error_log("ReJabba: Got content response for post ID: {$post_id}");

        $word_count_before = str_word_count($draft->post_content);
        $word_count_after = $content_response ? str_word_count($content_response) : 0; 
  
        if ($content_response) {
            error_log("ReJabba: Content response is not empty for post ID: {$post_id}"); 
            $new_title = $draft->post_title; 

            if ($rewrite_title === 'yes' && !empty($title_prompt)) {
                $new_title = $this->send_to_openai($draft->post_title, $title_prompt, $api_key, $model, $style, $language, $temperature, $max_tokens); 
                $new_title = $this->clean_title($new_title); // Очистка заголовка
            } 

            $cleaned_content = $this->clean_html_tags($content_response);

            // Применяем паттерны фильтрации, если они есть 
            $filtered_content = $this->apply_filter_patterns($cleaned_content);

            // Применяем замены текста, если они есть
            $filtered_content = $this->apply_custom_replacements($filtered_content);

            $focus_keyword = $this->generate_focus_keyword($filtered_content, $yoast_settings);
            $meta_description = $this->generate_meta_description($filtered_content, $yoast_settings);
            $this->update_yoast_seo_fields($draft->ID, $new_title, $focus_keyword, $meta_description, $yoast_settings); 

            $this->publish_post($draft->ID, $filtered_content, $new_title); 
            $execution_time = round(microtime(true) - $start_time, 2);

            // Устанавливаем флаг успешности в true, если все хорошо
            $this->last_draft_processed_successfully = true; 

            $this->log_analytics($draft->ID, 'Переписывание', 'Успех', $word_count_before, $word_count_after, $execution_time); 
        } else { 
            error_log("ReJabba: Content response is empty for post ID: {$post_id}");
            error_log("ReJabba: Ошибка переписывания черновика: ID $post_id");
            $this->log_analytics($draft->ID, 'Переписывание', 'Ошибка', $word_count_before, $word_count_after, 0);  // Логируем ошибку 
        }

        // Сбрасываем ID текущего черновика, если он был обработан успешно 
        if ($this->last_draft_processed_successfully) {
            delete_option($this->current_draft_id_option); 
        } 
    }


    public function test_drafts() {
        $draft_ids = $_POST['draft_ids'];

        if (!is_array($draft_ids)) {
            echo 'Выберите хотя бы один черновик.';
            wp_die();
        }

        foreach ($draft_ids as $draft_id) {
            // Асинхронная обработка черновиков
            echo "<div style='margin-bottom: 5px;'><span id='process-{$draft_id}'>Черновик ID {$draft_id} - <b>Процесс:</b> В обработке... </span></div>";
            $this->process_single_draft($draft_id);

            // Используем JavaScript для обновления статуса в UI 
            if ($this->last_draft_processed_successfully) {
                ?>
                <script>
                    document.getElementById('process-<?php echo $draft_id; ?>').innerHTML = "Черновик ID <?php echo $draft_id; ?> - <b>Процесс:</b> Успешно переписана запись ID <?php echo $draft_id; ?>";
                </script>
                <?php
            } else {
                ?>
                <script>
                    document.getElementById('process-<?php echo $draft_id; ?>').innerHTML = "Черновик ID <?php echo $draft_id; ?> - <b>Процесс:</b> Ошибка"; 
                </script>
                <?php
            }
        }

        // Сообщение об окончании обработки
        echo "Запросы на обработку черновиков отправлены. Обновляйте страницу, чтобы увидеть изменения в аналитике."; 
        wp_die();
    }


    /**
    * Генерирует ключевую фразу и синоним, используя API OpenAI.
    * 
    * @param string $content Контент записи.
    * @param array $yoast_settings Настройки Yoast SEO.
    * @return array Массив с ключевой фразой и синонимом.
    */
    private function generate_related_keywords($content, $yoast_settings)
    {
        $api_key = get_option($this->api_key_option_name);
        $model = get_option($this->model_option_name);
        $style = get_option($this->style_option_name, 'neutral');
        $language = get_option($this->language_option_name, 'ru');
        $temperature = floatval(get_option($this->temperature_option_name, '0.7'));
        $max_tokens = intval(get_option($this->max_tokens_option_name, '1024'));

        // Запрос к API OpenAI для получения ключевой фразы
        $keyword_phrase_prompt = "Выделите ключевую фразу из текста: \n\n" . $content;
        $keyword_phrase_response = $this->send_to_openai($content, $keyword_phrase_prompt, $api_key, $model, $style, $language, $temperature, $max_tokens);

        // Запрос к API OpenAI для получения синонима 
        $synonym_prompt = "Предложите синоним для ключевой фразы: " . $keyword_phrase_response;
        $synonym_response = $this->send_to_openai($content, $synonym_prompt, $api_key, $model, $style, $language, $temperature, $max_tokens);

        return [
            'keyword_phrase' => $keyword_phrase_response,
            'synonym' => $synonym_response
        ];
    }

    /** 
    * Применяет кастомные замены текста из настроек плагина.
    * Замены должны быть в формате "что_заменить::на_что", по одной на строке.
    *
    * @param string $content Текст, в котором нужно сделать замены.
    * @return string Текст с выполненными заменами. 
    */
    private function apply_custom_replacements($content)
    {
        $replacePairsString = get_option($this->replace_pairs_option_name, '');
        $replacePairs = array_filter(explode("\n", $replacePairsString)); // Разбиваем на пары

        foreach ($replacePairs as $pair) {
            list($search, $replace) = explode('::', $pair, 2);  // Разбиваем пару по "::"

            // Пропускаем некорректные пары
            if (!isset($search, $replace)) {
                continue;
            }

            $content = str_replace(trim($search), trim($replace), $content);
        }


        return $content;
    }

    public function send_to_openai($content, $prompt, $api_key, $model, $style, $language, $temperature, $max_tokens)
    {
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are a helpful assistant with a {$style} style and you will respond in {$language}."
                    ],
                    ['role' => 'user', 'content' => $prompt . "\n\n" . $content],
                ],
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
            ]), 
            'timeout' => 88, 
        ]; 

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args); 

        if (is_wp_error($response)) {
            error_log('ReJabba: OpenAI API Request Error: ' . $response->get_error_message()); 
            return false; 
        } 

        $body = wp_remote_retrieve_body($response); 

        $result = json_decode($body, true); 

        if (json_last_error() !== JSON_ERROR_NONE) { 
            error_log('ReJabba: Ошибка декодирования JSON ответа OpenAI API: ' . json_last_error_msg());
            return false; 
        }

        if (isset($result['error'])) { 
            error_log('ReJabba: OpenAI API Error: ' . print_r($result['error'], true)); 
            return false; 
        } 

        return $result['choices'][0]['message']['content'] ?? false;
    } 

    private function clean_html_tags($content) 
    { 
        $content = preg_replace('#<(html|body)[^>]*>#', '', $content);
        $content = preg_replace('#</(html|body)>#', '', $content); 

        return trim($content);
    } 

    /** 
     * Применяет паттерны для фильтрации текста, используя preg_replace.
     * 
     * @param string $content Текст для фильтрации. 
     * @return string Отфильтрованный текст.
     */ 
    private function apply_filter_patterns($content) 
    {
        $patterns = get_option($this->filter_patterns_option_name, []); 

        if (!empty($patterns)) {
            // Разбиваем паттерны на отдельные строки 
            $patterns = explode("\n", $patterns); 

            foreach ($patterns as $pattern) { 
                // Разбиваем паттерн на части поиска и замены 
                $parts = explode("::", $pattern);
                if (count($parts) === 2) {
                    $search = trim($parts[0]); 
                    $replace = trim($parts[1]);
                    $content = preg_replace($search, $replace, $content); 
                }
            } 
        } 

        return $content;
    } 


    private function generate_focus_keyword($content, $yoast_settings)
    {
        // Получаем количество ключевых слов из настроек 
        $keyword_count = intval($yoast_settings['keyword_count'] ?? $this->keyword_options['keyword_count']);

        $words = array_count_values(str_word_count(strip_tags($content), 1));
        arsort($words); 
        $stopwords = ['и', 'в', 'на', 'с', 'по', 'для', 'nbsp', 'это', 'как'];
        $filtered_words = array_diff(array_keys($words), $stopwords); 

        // Возвращаем нужное количество ключевых слов
        return implode(', ', array_slice($filtered_words, 0, $keyword_count));
    }

    private function generate_meta_description($content, $yoast_settings)
    { 
        $description_length = intval($yoast_settings['description_length'] ?? $this->meta_description_options['description_length']); 

        // Заменяем переменные в формате описания на фактические значения
        $description_format = $yoast_settings['description_format'] ?? $this->meta_description_options['description_format']; 
        $meta_description = str_replace( 
            [ '%excerpt%', '%post_content%', '%blog_description%' ], 
            [ wp_trim_words(strip_tags($content), 20), strip_tags($content), get_bloginfo('description') ], 
            $description_format 
        ); 
        return mb_strimwidth($meta_description, 0, $description_length, '...'); 
    }

    private function update_yoast_seo_fields($post_id, $title, $focus_keyword, $meta_description, $yoast_settings)
    { 
        if (class_exists('WPSEO_Meta')) {
            if ($focus_keyword) {
                update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($focus_keyword)); 
            }
            if ($meta_description) { 
                update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($meta_description)); 
            } 
            if ($title) { 
                // Заменяем переменные в формате заголовка на фактические значения 
                $title_format = $yoast_settings['title_format'] ?? $this->seo_title_options['title_format']; 
                $new_title = str_replace(
                    [ '%keyword%', '%blog_name%', '%post_title%'],
                    [$focus_keyword, get_bloginfo('name'), $title], 
                    $title_format 
                );
                // Обрезаем заголовок до максимальной длины, указанной в настройках
                $title_length = intval($yoast_settings['title_length'] ?? $this->seo_title_options['title_length']);
                $new_title = mb_strimwidth($new_title, 0, $title_length, '...'); 

                update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($new_title));
            }
        } 
    } 

      
    private function publish_post($post_id, $new_content, $new_title)
    { 
        $placement = get_option($this->placement_option_name, 'publish'); 
        wp_update_post([ 
            'ID' => $post_id,
            'post_content' => $new_content,
            'post_title' => $new_title,
            'post_status' => $placement
        ]); 
    }

    public function log_analytics($post_id, $action, $result, $word_count_before, $word_count_after, $execution_time)
    {
        $analytics = get_option($this->analytics_option_name, []); 

        if (!is_array($analytics)) { 
            $analytics = [];
        } 

        $analytics[] = [
            'date' => current_time('mysql'), 
            'post_id' => $post_id,
            'action' => $action,
            'result' => $result,
            'word_count_before' => $word_count_before,
            'word_count_after' => $word_count_after,
            'execution_time' => $execution_time 
        ]; 
        update_option($this->analytics_option_name, $analytics);
    } 

    public function clear_analytics() 
    { 
        update_option($this->analytics_option_name, []);
    } 

    public function test_api_connection()
    { 
        $api_key = get_option($this->api_key_option_name);
        $response = wp_remote_get('https://api.openai.com/v1/models', [
            'headers' => [ 
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]); 

        if (is_wp_error($response)) { 
            echo 'Ошибка подключения к API: ' . $response->get_error_message();
        } else { 
            echo 'Подключение успешно'; 
        } 
        wp_die(); 
    }

    public function assign_additional_category($post_id) 
    { 
        $category_id = get_option($this->additional_category_option_name);

        if (!empty($category_id) && get_post_status($post_id) === 'publish' && get_post_type($post_id) === 'post') {
            wp_set_post_categories($post_id, [$category_id], true); 
        } 
    } 

      
    public function assign_random_author($post_id)
    { 
        if (get_option($this->author_option_name) !== 'random' || get_post_status($post_id) !== 'publish' || get_post_type($post_id) !== 'post') {
            return;
        } 

        $editors = get_users(array('role' => 'editor'));

        if (!empty($editors)) { 
            $random_editor = $editors[array_rand($editors)]->ID;
            wp_update_post(array('ID' => $post_id, 'post_author' => $random_editor)); 
        }
    } 


    /**
     * Очищает заголовок от лишних символов и форматирования.
     * 
     * @param string $title Заголовок для очистки.
     * @return string Очищенный заголовок. 
     */ 
    private function clean_title($title) { 
        $title = wp_strip_all_tags($title); // Удаляем HTML теги 
        $title = trim($title); // Удаляем лишние пробелы 
        return $title; 
    }

} 
new ReJabba();
