<?php
/**
 * Plugin Name: Recent Posts Plugin
 * Description: A simple plugin to display recent posts using a shortcode.
 * Version: 1.0
 * Author: Sharpeii
 */

// Определим пространство имен для плагина
namespace RecentPostsPlugin;

// Главный класс плагина
class RecentPostsPlugin {
    private $option_name = 'recent_posts_plugin'; // Имя опции в базе данных для хранения настроек
    // Конструктор класса
    public function __construct() {
        // Регистрируем шорткод при инициализации
        add_action('wp_loaded', array($this, 'register_shortcode'));  //хук wp_loaded загружается позже init, поэтому вешаем на него, чтобы успели зарегистрироваться все типы постов до регистрации шорткодов
        // Регистрируем страницу настроек в меню
        add_action('admin_menu', array($this, 'add_admin_menu'));
        // Настройки
        add_action('admin_init', array($this, 'register_settings'));
    }

    // Метод для регистрации шорткода
    public function register_shortcode(): void
    {
        // Получаем все зарегистрированные типы постов
        $post_types = get_post_types(array('public' => true, ), 'names');
        // Для каждого типа поста создаем свой шорткод
        foreach ($post_types as $post_type) {
            add_shortcode('recent_posts_' . $post_type, function($atts) use ($post_type) {
                return $this->display_recent_posts($atts, $post_type);
            });
        }
    }

    // Вывод списка записей для конкретного типа
    public function display_recent_posts($atts, $post_type): string
    {
        // Генерируем уникальный ключ для кэша на основе типа поста и параметров
        $cache_key = 'recent_posts_' . $post_type . '_' . md5(serialize($atts));
        $output = wp_cache_get($cache_key, 'recent_posts_plugin');

        // Если кэш существует, возвращаем его
        if ($output !== false) {
            return $output;
        }

        // Получаем настройки для типа записи
        $options = get_option($this->option_name);
        $post_count = isset($options[$post_type]['count']) ? $options[$post_type]['count'] : 5;

        // Параметры шорткода по умолчанию
        $atts = shortcode_atts(array(
            'posts' => $post_count,
            'category' => '' // Фильтрация по категории
        ), $atts, 'recent_posts_' . $post_type);

        // Условия для запроса записей
        $args = array(
            'posts_per_page' => $atts['posts'],
            'post_type'   => $post_type,
            'post_status' => 'publish',
        );

        // Определение таксономий для типа поста
        $taxonomies = get_object_taxonomies($post_type, 'objects');


        // Добавляем фильтр по категории, если указано
        if (!empty($atts['category']) && !empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $term = get_term_by('slug', $atts['category'], $taxonomy->name);
                if ($term) {
                    $args['tax_query'] = array(
                        array(
                            'taxonomy' => $taxonomy->name,
                            'field'    => 'slug',
                            'terms'    => $atts['category'],
                            'include_children' => false, // Убираем детей, чтобы сосредоточиться на точной категории
                        ),
                    );

                    break; // Прерываем после нахождения первой совпадающей таксономии
                }
            }
        }

        // Запрос через \WP_Query для получения записей
        $query = new \WP_Query($args);

        // Формируем HTML вывод
        if ($query->have_posts()) {
            $output = '<ul>';
            while ($query->have_posts()) {
                $query->the_post();
                $output .= '<li><a href="' . get_permalink() . '">' . esc_html(get_the_title()) . '</a></li>';
            }
            $output .= '</ul>';
        } else {
            $output = '<p>No recent posts found for ' . esc_html($post_type) . '.</p>';
        }
        // Сбрасываем пост после custom query
        wp_reset_postdata();

        // Сохраняем результат в кэше на 10 минут
        wp_cache_set($cache_key, $output, 'recent_posts_plugin', 600);

        return $output;
    }

    // Добавляем пункт меню для настроек
    public function add_admin_menu(): void
    {
        add_options_page(
            'Recent Posts Plugin Settings', // Название страницы
            'Recent Posts',                  // Название в меню
            'manage_options',                // Права доступа
            'recent-posts-plugin',           // Слаг страницы
            array($this, 'settings_page')    // Метод, выводящий страницу настроек
        );
    }
    // Регистрация настроек
    public function register_settings(): void
    {
        register_setting('recent_posts_plugin_group', $this->option_name);
    }

    // Вывод страницы настроек
    public function settings_page(): void
    {
        // Получаем все зарегистрированные типы постов
        $post_types = get_post_types(array('public' => true), 'names');
        $options = get_option($this->option_name);
        ?>
        <div class="wrap">
            <h1>Recent Posts Plugin Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('recent_posts_plugin_group');
                do_settings_sections('recent_posts_plugin');
                ?>
                <h2>Configure Shortcodes</h2>
                <table class="form-table">
                    <tr>
                        <th>Post Type</th>
                        <th>Number of Posts</th>
                        <th>Category</th>
                        <th>Generated Shortcode</th>
                    </tr>
                    <?php foreach ($post_types as $post_type) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html($post_type); ?>
                            </td>
                            <td>
                                <input type="number" id="count_<?php echo esc_attr($post_type); ?>" value="5" min="1" />
                            </td>

                            <td>
                                <?php
                                // Получаем таксономии для данного типа поста
                                $taxonomies = get_object_taxonomies($post_type, 'objects');
                                $categories = [];
                                foreach ($taxonomies as $taxonomy) {
                                    $terms = get_terms(array('taxonomy' => $taxonomy->name, 'hide_empty' => true));
                                    foreach ($terms as $term) {
                                        $categories[] = $term;
                                    }
                                }
                                ?>

                                <select id="category_<?php echo esc_attr($post_type); ?>">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category) : ?>
                                        <option value="<?php echo esc_attr($category->slug); ?>">
                                            <?php echo esc_html($category->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" id="shortcode_<?php echo esc_attr($post_type); ?>" readonly value="[recent_posts_<?php echo esc_attr($post_type); ?>]" />
                                <button type="button" class="copy-button" data-target="shortcode_<?php echo esc_attr($post_type); ?>">Копировать</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </form>
        </div>
        <script>
            // Скрипт для динамического обновления шорткодов
            document.querySelectorAll('input[type="number"], select').forEach(element => {
                element.addEventListener('input', function() {
                    const postType = this.closest('tr').querySelector('td:first-child').textContent.trim();
                    const count = document.getElementById('count_' + postType).value;
                    const category = document.getElementById('category_' + postType).value;
                    let shortcode = '[recent_posts_' + postType;

                    if (count) {
                        shortcode += ' posts="' + count + '"';
                    }
                    if (category) {
                        shortcode += ' category="' + category + '"';
                    }

                    shortcode += ']';
                    document.getElementById('shortcode_' + postType).value = shortcode;
                });
            });
            // Скрипт для копирования шорткодов в буфер обмена
            document.querySelectorAll('.copy-button').forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const shortcode = document.getElementById(targetId).value;

                    navigator.clipboard.writeText(shortcode).then(() => {
                        alert('Шорткод скопирован в буфер обмена: ' + shortcode);
                    }).catch(err => {
                        alert('Ошибка при копировании: ' + err);
                    });
                });
            });
        </script>

        <?php
    }
}

// Инициализация плагина
$recentPostsPlugin = new RecentPostsPlugin();