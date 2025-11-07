<?php
/**
 * Plugin Name: Twitter Importer
 * Plugin URI: https://github.com/chrismccoy/twitter-importer
 * Description: Search, fetch, and import Twitter/X media. Requires a private API URL configured in settings.
 * Author: Chris McCoy
 * Version: 1.0.0
 * Text Domain: twitter-importer
 */

if (!defined('ABSPATH')) {
    exit(); // Exit if accessed directly.
}

/**
 * This class handles all functionality, including hooks, admin pages,
 * API communication, media importing, and WP-CLI commands.
 */
final class TwitterImporter
{
    /**
     * The single instance of the class.
     */
    private static ?TwitterImporter $instance = null;

    /**
     * The key used to store plugin settings in the wp_options table.
     */
    public const OPTION_NAME = 'twitter_importer_settings';

    /**
     * Regex to extract a Status ID from a Twitter/X URL.
     */
    private const TWITTER_STATUS_REGEX =
        '/https?:\/\/(?:(?:www|m(?:obile)?)\.)?(?:x|twitter)\.com\/(?:#!\/)?(\w+)\/status(es)?\/(?<id>\d+)/';

    /**
     * The plugin's saved settings.
     */
    private array $options;

    /**
     * Settings sections array.
     */
    protected array $settings_sections = [];

    /**
     * Settings fields array.
     */
    protected array $settings_fields = [];

    /**
     * Get the singleton instance of the plugin.
     */
    public static function get_instance(): TwitterImporter
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initializes the plugin by setting up options and loading hooks.
     */
    private function __construct()
    {
        $this->load_options();
        $this->init_hooks();
    }

    /**
     * Merges stored options with default values to prevent errors.
     */
    private function load_options(): void
    {
        $this->options = wp_parse_args(
            get_option(self::OPTION_NAME, []),
            [
                'set_featured_image' => 'off',
                'api_base_url' => '',
            ]
        );
    }

    /**
     * Helper to retrieve the configured API Base URL.
     * returns string|false
     */
    private function get_api_base_url()
    {
        $url = $this->options['api_base_url'] ?? '';
        if (empty($url)) {
            return false;
        }
        // Ensure we have a trailing slash for concatenation consistency later
        return trailingslashit($url);
    }

    /**
     * This method registers all actions and filters used by the plugin.
     */
    private function init_hooks(): void
    {
        // Admin Menus & Settings
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'admin_init_settings']);

        // Scripts & Styles
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);

        // AJAX Handlers
        add_action('wp_ajax_twitter_search', [$this, 'ajax_search_videos']);
        add_action(
            'wp_ajax_twitter_import',
            [$this, 'ajax_import_single_video']
        );
        add_action(
            'wp_ajax_twitter_import_multiple',
            [$this, 'ajax_import_multiple_videos']
        );
        add_action(
            'wp_ajax_ti_fetch_media',
            [$this, 'ajax_fetch_media_for_editor']
        );
        add_action(
            'wp_ajax_ti_media_library_import',
            [$this, 'ajax_media_library_import']
        );

        // Post Editor Meta Box
        add_action('add_meta_boxes', [$this, 'add_editor_meta_box']);

        // Media Library UI
        add_action('post-upload-ui', [$this, 'render_media_library_importer']);

        // Bulk Importer Form Handler
        add_action('admin_post_ti_bulk_import', [$this, 'handle_bulk_import']);

        // Conditionally load the 'save_post' hook for setting the featured image.
        if (($this->options['set_featured_image'] ?? 'off') === 'on') {
            add_action('save_post', [$this, 'save_post_set_thumbnail'], 20, 2);
        }

        // Register WP-CLI commands if running in a CLI environment.
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('twitter get-media', [$this, 'cli_get_media']);
            WP_CLI::add_command(
                'twitter create-post',
                [$this, 'cli_create_post']
            );
        }
    }

    /**
     * Adds the plugin's pages to the WordPress admin menu.
     */
    public function add_admin_menu(): void
    {
        add_menu_page(
            'Twitter Importer',
            'Twitter Importer',
            'manage_options',
            'twitter-importer',
            [$this, 'render_search_import_page'],
            'dashicons-video-alt3'
        );
        add_submenu_page(
            'twitter-importer',
            'Bulk Importer',
            'Bulk Importer',
            'manage_options',
            'ti-bulk-importer',
            [$this, 'render_bulk_import_page']
        );
        add_submenu_page(
            'twitter-importer',
            'Settings',
            'Settings',
            'manage_options',
            self::OPTION_NAME,
            [$this, 'render_settings_page']
        );
    }

    /**
     * Renders the main "Search & Import" page.
     */
    public function render_search_import_page(): void
    {
        if (!$this->get_api_base_url()) {
            $this->render_missing_api_notice();
            return;
        }
        require_once plugin_dir_path(__FILE__) . 'views/search-import-page.php';
    }

    /**
     * Renders the "Bulk Importer" page.
     */
    public function render_bulk_import_page(): void
    {
        if (!$this->get_api_base_url()) {
            $this->render_missing_api_notice();
            return;
        }
        require_once plugin_dir_path(__FILE__) . 'views/bulk-import-page.php';
    }

    /**
     * Helper to render the missing API notice.
     */
    private function render_missing_api_notice(): void
    {
        echo '<div class="wrap"><h1>Twitter Importer</h1>';
        echo '<div class="notice notice-error"><p>' .
            sprintf(
                __('Please configure the <strong>API Base URL</strong> in the <a href="%s">Settings</a> before using the importer.', 'twitter-importer'),
                esc_url(admin_url('admin.php?page=' . self::OPTION_NAME))
            ) .
            '</p></div></div>';
    }

    /**
     * Renders the plugin's settings page.
     */
    public function render_settings_page(): void
    {
        echo '<div class="wrap"><h1>Twitter Importer Settings</h1>';
        $this->show_settings_navigation();
        $this->show_settings_forms();
        echo '</div>';
    }

    /**
     * Handles the form submission from the bulk importer page.
     */
    public function handle_bulk_import(): void
    {
        // Security checks
        if (
            !isset($_POST['_wpnonce']) ||
            !wp_verify_nonce(
                sanitize_key($_POST['_wpnonce']),
                'ti_bulk_import_nonce'
            )
        ) {
            wp_die('Security check failed.');
        }
        if (!current_user_can('publish_posts')) {
            wp_die('You do not have permission to publish posts.');
        }

        if (!$this->get_api_base_url()) {
             wp_die('API Base URL is not configured.');
        }

        $import_data =
            isset($_POST['import_data'])
                ? sanitize_textarea_field(wp_unslash($_POST['import_data']))
                : '';
        $lines = explode(PHP_EOL, trim($import_data));
        $success_count = 0;
        $failed_count = 0;

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) !== 2) {
                $failed_count++;
                continue;
            }

            $result = $this->create_post_from_bulk(
                trim($parts[0]),
                trim($parts[1])
            );

            if (!is_wp_error($result)) {
                $success_count++;
            } else {
                $failed_count++;
            }
        }

        // Redirect back with status query arguments.
        $redirect_url = add_query_arg(
            [
                'page' => 'ti-bulk-importer',
                'status' => 'complete',
                'success' => $success_count,
                'failed' => $failed_count,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit();
    }

    /**
     * Enqueues scripts and styles for the admin area.
     */
    public function admin_enqueue_scripts(string $hook): void
    {
        // Main Search & Import page
        if ('toplevel_page_twitter-importer' === $hook) {
            wp_enqueue_style(
                'ti-admin-style',
                plugin_dir_url(__FILE__) . 'assets/css/admin.css',
                [],
                '1.0.0'
            );
            wp_enqueue_script(
                'ti-admin-script',
                plugin_dir_url(__FILE__) . 'assets/js/admin-main-importer.js',
                ['jquery'],
                '1.0.0',
                true
            );
            wp_localize_script('ti-admin-script', 'twitterImporter', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('twitter-importer-nonce'),
            ]);
        }

        // Post Editor (for Meta Box)
        if ('post.php' === $hook || 'post-new.php' === $hook) {
            wp_enqueue_script(
                'ti-metabox-script',
                plugin_dir_url(__FILE__) . 'assets/js/admin-metabox.js',
                ['jquery'],
                '1.0.0',
                true
            );
            wp_localize_script('ti-metabox-script', 'tiMetabox', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ti-metabox-nonce'),
            ]);
        }

        // Media Library (for Media Library Importer)
        if ('upload.php' === $hook || 'media-new.php' === $hook) {
            wp_enqueue_script(
                'ti-media-library-script',
                plugin_dir_url(__FILE__) . 'assets/js/admin-media-library.js',
                ['jquery'],
                '1.0.0',
                true
            );
            wp_localize_script('ti-media-library-script', 'tiMediaLibrary', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ti-media-import-nonce'),
                'error_message' => __(
                    'An unknown error occurred.',
                    'twitter-importer'
                ),
                'empty_url_message' => __(
                    'Please provide a valid Twitter Status URL.',
                    'twitter-importer'
                ),
            ]);
        }
    }

    /**
     * Registers the meta box for the post editor screen.
     */
    public function add_editor_meta_box(): void
    {
        add_meta_box(
            'ti_metabox',
            __('Fetch Twitter/X Media', 'twitter-importer'),
            [$this, 'render_editor_meta_box'],
            ['post', 'page'],
            'normal',
            'high'
        );
    }

    /**
     * Renders the HTML content for the editor meta box.
     */
    public function render_editor_meta_box(): void
    {
        wp_nonce_field('ti-metabox-nonce', 'ti_nonce_field');
        
        if (!$this->get_api_base_url()) {
            echo '<p class="error">' . esc_html__('Error: API Base URL not configured in settings.', 'twitter-importer') . '</p>';
            return;
        }
        
        ?>
        <div id="ti_metabox">
            <div class="ti-fetch-type-switcher" style="margin-bottom: 10px; display: flex; gap: 10px;">
                <button type="button" class="button button-primary" data-type="status">
                    <?php esc_html_e('By Status URL / ID', 'twitter-importer'); ?>
                </button>
                <button type="button" class="button" data-type="user">
                    <?php esc_html_e('By Username', 'twitter-importer'); ?>
                </button>
            </div>
            <div style="display: flex; gap: 5px;">
                <input type='text' style='width:100%' placeholder="<?php esc_attr_e(
                    'Enter URL or Username...',
                    'twitter-importer'
                ); ?>" id='ti_fetch_source' />
                <button class='button button-primary' id='ti_fetch_button' type='button'><?php esc_html_e(
                    'Fetch & Insert',
                    'twitter-importer'
                ); ?></button>
            </div>
            <p id="ti-fetch-description" class="description" style="margin-top: 8px;"><?php esc_html_e(
                'Fetches media and inserts it into the editor.',
                'twitter-importer'
            ); ?></p>
            <div id="ti-fetch-notice" style="margin-top: 10px;"></div>
        </div>
        <?php
    }

    /**
     * Renders the UI for the importer on the Media Library upload screen.
     */
    public function render_media_library_importer(): void
    {
        if (!$this->get_api_base_url()) {
            return;
        }
        ?>
        <div class="ti-media-importer-wrap" style="padding: 1em; text-align: center; border-top: 1px solid #ddd; margin-top: 1em;">
            <h3 style="margin-top:0;"><?php esc_html_e(
                'Or Import from Twitter/X URL',
                'twitter-importer'
            ); ?></h3>
            <p class="description"><?php esc_html_e(
                'Enter a Tweet URL to import its video or image directly into the Media Library.',
                'twitter-importer'
            ); ?></p>
            <div style="display: flex; justify-content: center; gap: 5px; max-width: 500px; margin: auto;">
                <input name="ti-media-url" type="url" class="ti-media-url widefat" placeholder="<?php esc_attr_e(
                    'Twitter Status URL...',
                    'twitter-importer'
                ); ?>" autocomplete="off">
                <button type="button" class="ti-media-submit-btn button-primary"><?php esc_html_e(
                    'Import',
                    'twitter-importer'
                ); ?></button>
            </div>
            <div class="ti-media-message" style="margin-top: 15px;"></div>
        </div>
        <?php
    }

    /**
     * AJAX handler for searching videos on the main importer page.
     */
    public function ajax_search_videos(): void
    {
        check_ajax_referer('twitter-importer-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $query = isset($_POST['query'])
            ? sanitize_text_field($_POST['query'])
            : '';

        $videos = $this->search_videos($type, $query);

        if (is_wp_error($videos)) {
            wp_send_json_error(['message' => $videos->get_error_message()]);
        }

        if (empty($videos)) {
            wp_send_json_success(['videos' => []]);
        }

        // Check which videos have already been imported for UI state.
        $video_ids = array_column($videos, 'tweet_id');
        $existing_posts = [];
        if (!empty($video_ids)) {
            $posts_query = new WP_Query([
                'post_type' => 'post',
                'meta_query' => [
                    [
                        'key' => '_twitter_video_id',
                        'value' => $video_ids,
                        'compare' => 'IN',
                    ],
                ],
                'posts_per_page' => -1,
                'fields' => 'ID',
            ]);
            foreach ($posts_query->posts as $post_id) {
                $video_id = get_post_meta(
                    $post_id,
                    '_twitter_video_id',
                    true
                );
                if ($video_id) {
                    $existing_posts[$video_id] = get_permalink($post_id);
                }
            }
        }

        // Format results for the frontend.
        $results = array_map(function ($video) use ($existing_posts) {
            $video_id = $video['tweet_id'];
            $is_imported = isset($existing_posts[$video_id]);
            return [
                'id' => $video_id,
                'views' => $video['views'] ?? 0,
                'userName' => $video['username'] ?? '',
                'thumbnail' => $video['thumbnail'] ?? '',
                'download_url' => $video['download_url'] ?? '',
                'is_imported' => $is_imported,
                'post_url' => $is_imported ? $existing_posts[$video_id] : null,
            ];
        }, $videos);

        wp_send_json_success(['videos' => $results]);
    }

    /**
     * AJAX handler for importing a single video.
     */
    public function ajax_import_single_video(): void
    {
        check_ajax_referer('twitter-importer-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $video_data = isset($_POST['video_data'])
            ? json_decode(stripslashes($_POST['video_data']), true)
            : null;
        $result = $this->create_post_from_video_search($video_data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'post_id' => $result,
            'post_url' => get_permalink($result),
        ]);
    }

    /**
     * AJAX handler for importing multiple videos.
     */
    public function ajax_import_multiple_videos(): void
    {
        check_ajax_referer('twitter-importer-nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $videos_data = isset($_POST['videos_data'])
            ? json_decode(stripslashes($_POST['videos_data']), true)
            : [];
        $results = [];
        foreach ($videos_data as $video_data) {
            $result = $this->create_post_from_video_search($video_data);
            $id = $video_data['id'];
            if (is_wp_error($result)) {
                $results[] = [
                    'id' => $id,
                    'success' => false,
                    'message' => $result->get_error_message(),
                ];
            } else {
                $results[] = [
                    'id' => $id,
                    'success' => true,
                    'post_id' => $result,
                    'post_url' => get_permalink($result),
                ];
            }
        }
        wp_send_json_success(['results' => $results]);
    }

    /**
     * AJAX handler for the editor meta box to fetch media URLs.
     */
    public function ajax_fetch_media_for_editor(): void
    {
        check_ajax_referer('ti-metabox-nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'status';
        $value = isset($_POST['value'])
            ? sanitize_text_field(wp_unslash($_POST['value']))
            : '';

        if (empty($value)) {
            wp_send_json_error(['message' => 'No value provided.'], 400);
        }

        $content =
            $type === 'user'
                ? $this->get_media_by_user($value)
                : $this->get_media_by_status($value);

        if (is_wp_error($content)) {
            wp_send_json_error(['message' => $content->get_error_message()]);
        }

        wp_send_json_success($content);
    }

    /**
     * AJAX handler for the Media Library importer.
     */
    public function ajax_media_library_import(): void
    {
        check_ajax_referer('ti-media-import-nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(
                ['message' => 'You do not have permission to upload files.'],
                403
            );
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if (empty($url)) {
            wp_send_json_error(
                ['message' => 'Please provide a valid URL.'],
                400
            );
        }

        $media_data = $this->get_media_by_status($url);

        if (is_wp_error($media_data)) {
            wp_send_json_error(['message' => $media_data->get_error_message()]);
        }

        $attachment_id = $this->sideload_media($media_data['src']);
        if (is_wp_error($attachment_id)) {
            wp_send_json_error([
                'message' => $attachment_id->get_error_message(),
            ]);
        }

        wp_send_json_success(['message' => 'Import successful!']);
    }

    /**
     * Creates a post from video data from the main search importer.
     */
    private function create_post_from_video_search(?array $video_data)
    {
        if (empty($video_data)) {
            return new WP_Error('missing_data', 'No video data provided.');
        }

        $video_id = $video_data['id'] ?? '';
        if (empty($video_id)) {
            return new WP_Error('missing_data', 'Missing video ID.');
        }

        if ($this->is_duplicate($video_id)) {
            return new WP_Error(
                'duplicate',
                'This video has already been imported.'
            );
        }

        $video_url = $video_data['download_url'] ?? '';
        $poster_url = $video_data['thumbnail'] ?? '';
        if (empty($video_url) || empty($poster_url)) {
            return new WP_Error('missing_data', 'Missing video or poster URL.');
        }

        $username = $video_data['userName'] ?? '';
        $post_title =
            (!empty($username) ? $username . ' - ' : '') . 'Video ' . $video_id;

        $post_id = wp_insert_post([
            'post_title' => sanitize_text_field($post_title),
            'post_status' => 'publish',
            'post_type' => 'post',
        ]);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, '_twitter_video_id', $video_id);

        $thumbnail_id = $this->sideload_media($poster_url, $post_id);
        if (!is_wp_error($thumbnail_id)) {
            set_post_thumbnail($post_id, $thumbnail_id);
        }

        $video_attachment_id = $this->sideload_media($video_url, $post_id);
        if (!is_wp_error($video_attachment_id)) {
            $local_video_url = wp_get_attachment_url($video_attachment_id);
            $local_poster_url = is_wp_error($thumbnail_id)
                ? ''
                : wp_get_attachment_url($thumbnail_id);

            $content =
                '[video src="' .
                esc_url($local_video_url) .
                '" poster="' .
                esc_url($local_poster_url) .
                '"]';
            wp_update_post(['ID' => $post_id, 'post_content' => $content]);
        } else {
            // Clean up the created post if the video download fails.
            wp_delete_post($post_id, true);
            return new WP_Error(
                'video_download_failed',
                'Failed to download video file: ' .
                    $video_attachment_id->get_error_message()
            );
        }

        return $post_id;
    }

    /**
     * Creates a post from data provided to the bulk importer.
     */
    private function create_post_from_bulk(string $url, string $title)
    {
        $content_data = $this->get_media_by_status($url);
        if (is_wp_error($content_data)) {
            return $content_data;
        }

        $post_content = $this->get_content_as_string($content_data, $title);
        if (empty($post_content)) {
            return new WP_Error('no_content', 'Could not generate post content.');
        }

        $post_id = wp_insert_post([
            'post_title' => sanitize_text_field($title),
            'post_content' => $post_content,
            'post_status' => 'publish',
            'post_type' => 'post',
        ]);

        return is_wp_error($post_id) ? $post_id : (int) $post_id;
    }

    /**
     * Downloads a file from a URL and attaches it to the media library.
     */
    private function sideload_media(
        string $url,
        int $post_id = 0,
        ?string $desc = null
    ) {
        // These functions are required for sideloading.
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        // Download file to a temporary directory.
        $tmp = download_url($url, 120); // 120-second timeout
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $file_array = ['name' => basename($url), 'tmp_name' => $tmp];
        $attachment_id = media_handle_sideload($file_array, $post_id, $desc);

        // If an error occurred, unlink the temporary file.
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
        }

        return $attachment_id;
    }

    /**
     * Checks if a video has already been imported by its Twitter ID.
     */
    private function is_duplicate(string $video_id): bool
    {
        $existing_posts = get_posts([
            'post_type' => 'post',
            'meta_key' => '_twitter_video_id',
            'meta_value' => $video_id,
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        return !empty($existing_posts);
    }

    /**
     * Generates a content string (shortcode or img tag) from media data.
     */
    private function get_content_as_string(
        array $content_data,
        string $title = ''
    ): string {
        if (($content_data['type'] ?? '') === 'video') {
            return sprintf(
                '[video src="%s" poster="%s"]',
                esc_url($content_data['src']),
                esc_url($content_data['poster'])
            );
        } elseif (($content_data['type'] ?? '') === 'image') {
            return sprintf(
                '<img src="%s" alt="%s" />',
                esc_url($content_data['src']),
                esc_attr($title)
            );
        }
        return '';
    }

    /**
     * Sets the featured image from a video poster on post save.
     */
    public function save_post_set_thumbnail(int $post_id, WP_Post $post): void
    {
        // Don't run on autosave, revisions, or if a thumbnail already exists.
        if (
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ||
            wp_is_post_revision($post_id) ||
            'auto-draft' === $post->post_status ||
            has_post_thumbnail($post_id)
        ) {
            return;
        }

        // Find a poster URL in the post content using a regex.
        $regex = '/poster=[\'"](https?:\/\/[^\'"]+)[\'"]/';
        if (preg_match($regex, $post->post_content, $matches)) {
            $image_url = $matches[1];
            $attachment_id = $this->sideload_media($image_url, $post_id);
            if (!is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
    }

    /**
     * Searches for videos by username, keyword, or single tweet.
     */
    private function search_videos(string $type, string $query)
    {
        $api_base = $this->get_api_base_url();
        if (!$api_base) {
            return new WP_Error('missing_api_url', 'API URL is not configured.');
        }

        $endpoint = '';
        switch ($type) {
            case 'username':
                $endpoint = "timeline/{$query}";
                break;
            case 'keywords':
                $endpoint = "search/{$query}";
                break;
            case 'tweet':
                $endpoint = "media/{$query}";
                break;
            default:
                return new WP_Error(
                    'invalid_search_type',
                    'Invalid search type provided.'
                );
        }

        $response = $this->perform_api_request($api_base . $endpoint);

        if (is_wp_error($response)) {
            return $response;
        }

        // The external API might return videos in a nested key or as the top-level array.
        // This code gracefully handles different response structures.
        if (is_array($response)) {
            if (isset($response[0]['tweet_id'])) {
                return $response;
            }
            foreach ($response as $key => $value) {
                if (is_array($value) && isset($value[0]['tweet_id'])) {
                    return $value;
                }
            }
        }
        return []; // Return empty array if no videos found.
    }

    /**
     * Fetches media content from a single status URL or ID.
     */
    private function get_media_by_status(string $id_or_url)
    {
        $api_base = $this->get_api_base_url();
        if (!$api_base) {
            return new WP_Error('missing_api_url', 'API URL is not configured.');
        }

        $status_id = $id_or_url;
        // Extract the ID from a URL if one is provided.
        if (preg_match(self::TWITTER_STATUS_REGEX, $id_or_url, $matches)) {
            $status_id = $matches['id'];
        }

        $url = $api_base . 'media2/' . absint($status_id);
        $response_data = $this->perform_api_request($url);

        if (is_wp_error($response_data)) {
            return $response_data;
        }

        return $this->format_media_response($response_data['response'] ?? []);
    }

    /**
     * Fetches the latest media for a given username.
     */
    private function get_media_by_user(string $username)
    {
        $api_base = $this->get_api_base_url();
        if (!$api_base) {
            return new WP_Error('missing_api_url', 'API URL is not configured.');
        }

        $url = $api_base . 'latest/' . rawurlencode($username);
        $response_data = $this->perform_api_request($url);

        if (is_wp_error($response_data)) {
            return $response_data;
        }

        return $this->format_media_response($response_data['response'] ?? []);
    }

    /**
     * Formats a raw API response into a consistent content array.
     */
    private function format_media_response(array $response)
    {
        if (empty($response)) {
            return new WP_Error(
                'no_media_found',
                'Could not find media for this request.'
            );
        }

        $content = [];
        if (($response['type'] ?? '') === 'video') {
            $content = [
                'type' => 'video',
                'src' => esc_url_raw($response['download_url']),
                'poster' => esc_url_raw($response['thumbnail']),
            ];
        } elseif (($response['type'] ?? '') === 'image') {
            $content = [
                'type' => 'image',
                'src' => esc_url_raw($response['download_url']),
            ];
        }

        return !empty($content)
            ? $content
            : new WP_Error(
                'unsupported_media',
                'No compatible media found in the response.'
            );
    }

    /**
     * Performs the remote GET request to an API endpoint.
     */
    private function perform_api_request(string $url)
    {
        $args = [
            'timeout' => 60,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' =>
                    'WordPress/TwitterImporter ' . get_bloginfo('version'),
            ],
        ];
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'json_decode_error',
                'Failed to decode API response.'
            );
        }

        return $data;
    }

    /**
     * WP-CLI: Fetches media information from a Tweet URL/ID or a user's timeline.
     */
    public function cli_get_media(array $args, array $assoc_args): void
    {
        $value = $args[0];
        $type = $assoc_args['by'] ?? 'status';

        WP_CLI::line("Fetching media for '{$value}' by '{$type}'...");

        $response =
            $type === 'user'
                ? $this->get_media_by_user($value)
                : $this->get_media_by_status($value);

        if (is_wp_error($response)) {
            WP_CLI::error($response->get_error_message());
        } else {
            WP_CLI::success('Media found!');
            WP_CLI::line('Type: ' . $response['type']);
            WP_CLI::line('URL: ' . $response['src']);
            if (isset($response['poster'])) {
                WP_CLI::line('Poster: ' . $response['poster']);
            }
        }
    }

    /**
     * WP-CLI: Creates a new post from a user's latest media.
     */
    public function cli_create_post(array $args, array $assoc_args): void
    {
        $username = $args[0];
        WP_CLI::line("Fetching latest media for user: {$username}...");

        $content_data = $this->get_media_by_user($username);
        if (is_wp_error($content_data)) {
            WP_CLI::error($content_data->get_error_message());
        }

        $post_title =
            $assoc_args['post_title'] ??
            "Media from {$username} on " . gmdate('Y-m-d');
        $post_content = $this->get_content_as_string(
            $content_data,
            $post_title
        );

        if (empty($post_content)) {
            WP_CLI::error(
                'Could not generate post content from the fetched media.'
            );
        }

        $post_data = [
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_status' => $assoc_args['post_status'] ?? 'draft',
            'post_author' => $assoc_args['post_author'] ?? 1,
            'post_type' => 'post',
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            WP_CLI::error(
                "Failed to create post: {$post_id->get_error_message()}"
            );
        } else {
            WP_CLI::success("Successfully created post #{$post_id}.");
        }
    }

    /**
     * Initializes the settings API, registers sections and fields.
     */
    public function admin_init_settings(): void
    {
        // Define settings sections and fields
        $this->settings_sections = [
            [
                'id' => self::OPTION_NAME,
                'title' => __('General Settings', 'twitter-importer'),
            ],
        ];

        $this->settings_fields = [
            self::OPTION_NAME => [
                [
                    'name' => 'api_base_url',
                    'label' => __('API Base URL', 'twitter-importer'),
                    'desc' => __(
                        'The URL of the private API server (e.g., https://tweetcity.com/api/).',
                        'twitter-importer'
                    ),
                    'type' => 'text',
                    'default' => '',
                ],
                [
                    'name' => 'set_featured_image',
                    'label' => __('Auto Set Featured Image', 'twitter-importer'),
                    'desc' => __(
                        'When a post is saved, automatically find video posters in the content and set them as the Featured Image.',
                        'twitter-importer'
                    ),
                    'type' => 'checkbox',
                    'default' => 'off',
                ],
            ],
        ];

        // Register settings sections
        foreach ($this->settings_sections as $section) {
            if (false === get_option($section['id'])) {
                add_option($section['id']);
            }
            $callback =
                isset($section['desc']) && !empty($section['desc'])
                    ? fn() => print(
                        '<div class="inside">' .
                            esc_html($section['desc']) .
                            '</div>'
                    )
                    : null;
            add_settings_section(
                $section['id'],
                $section['title'],
                $callback,
                $section['id']
            );
        }

        // Register settings fields
        foreach ($this->settings_fields as $section => $field) {
            foreach ($field as $option) {
                $args = [
                    'id' => $option['name'],
                    'label_for' => "{$section}[{$option['name']}]",
                    'desc' => $option['desc'] ?? '',
                    'section' => $section,
                    'std' => $option['default'] ?? '',
                ];
                
                $callback = 'render_settings_field_' . ($option['type'] === 'checkbox' ? 'checkbox' : 'text');
                
                add_settings_field(
                    "{$section}[{$option['name']}]",
                    $option['label'],
                    [$this, $callback],
                    $section,
                    $section,
                    $args
                );
            }
        }

        // Register the setting to be saved
        foreach ($this->settings_sections as $section) {
            register_setting($section['id'], $section['id'], [
                $this,
                'sanitize_options',
            ]);
        }
    }

    /**
     * Renders the HTML for a text settings field.
     */
    public function render_settings_field_text(array $args): void
    {
        $options = get_option($args['section']);
        $value = $options[$args['id']] ?? $args['std'];

        $html = sprintf(
            '<input type="text" class="regular-text" id="ti-%1$s[%2$s]" name="%1$s[%2$s]" value="%3$s" />',
            $args['section'],
            $args['id'],
            esc_attr($value)
        );
        if ( !empty($args['desc']) ) {
            $html .= '<p class="description">' . esc_html($args['desc']) . '</p>';
        }
        echo $html; 
    }

    /**
     * Renders the HTML for a checkbox settings field.
     */
    public function render_settings_field_checkbox(array $args): void
    {
        $options = get_option($args['section']);
        $value = $options[$args['id']] ?? $args['std'];

        $html = sprintf(
            '<fieldset><label for="ti-%1$s[%2$s]">',
            $args['section'],
            $args['id']
        );
        // A hidden field ensures a value ('off') is always sent, even when the checkbox is unchecked.
        $html .= sprintf(
            '<input type="hidden" name="%1$s[%2$s]" value="off" />',
            $args['section'],
            $args['id']
        );
        $html .= sprintf(
            '<input type="checkbox" class="checkbox" id="ti-%1$s[%2$s]" name="%1$s[%2$s]" value="on" %3$s />',
            $args['section'],
            $args['id'],
            checked($value, 'on', false)
        );
        $html .= ' ' . esc_html($args['desc']) . '</label></fieldset>';
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Sanitizes the options before saving them to the database.
     */
    public function sanitize_options($options)
    {
        if (!$options) {
            return $options;
        }
        // Sanitize Checkbox
        if (isset($options['set_featured_image'])) {
            $options['set_featured_image'] =
                $options['set_featured_image'] === 'on' ? 'on' : 'off';
        }
        
        // Sanitize API URL
        if (isset($options['api_base_url'])) {
            $url = esc_url_raw(trim($options['api_base_url']));
            // Remove trailing slash for storage to avoid double slash issues on retrieval logic
            $options['api_base_url'] = untrailingslashit($url);
        }
        
        return $options;
    }

    /**
     * Renders the navigation tabs for the settings page.
     */
    private function show_settings_navigation(): void
    {
        if (count($this->settings_sections) <= 1) {
            return;
        }
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($this->settings_sections as $tab) {
            printf(
                '<a href="#%1$s" class="nav-tab" id="%1$s-tab">%2$s</a>',
                esc_attr($tab['id']),
                esc_html($tab['title'])
            );
        }
        echo '</h2>';
    }

    /**
     * Renders the form containers for the settings page.
     */
    private function show_settings_forms(): void
    {
        ?>
        <div class="metabox-holder">
            <?php foreach ($this->settings_sections as $form) : ?>
                <div id="<?php echo esc_attr($form['id']); ?>" class="group" style="display: none;">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields($form['id']);
                        do_settings_sections($form['id']);
                        if (isset($this->settings_fields[$form['id']])) {
                            submit_button();
                        }
                        ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        $this->render_settings_script();
    }

    /**
     * Renders the JavaScript for tab navigation on the settings page.
     */
    private function render_settings_script(): void
    {
        ?>
        <script>
            jQuery(document).ready(function ($) {
                $('.group').hide();
                var activetab = '';
                if (typeof localStorage !== 'undefined') {
                    activetab = localStorage.getItem('activetab');
                }
                if (activetab !== '' && $(activetab).length) {
                    $(activetab).fadeIn();
                } else {
                    $('.group:first').fadeIn();
                }
                $('.nav-tab-wrapper a:first').addClass('nav-tab-active');
                $('.nav-tab-wrapper a').click(function (evt) {
                    evt.preventDefault();
                    $('.nav-tab-wrapper a').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active').blur();
                    var clicked_group = $(this).attr('href');
                    if (typeof localStorage !== 'undefined') {
                        localStorage.setItem('activetab', clicked_group);
                    }
                    $('.group').hide();
                    $(clicked_group).fadeIn();
                });
            });
        </script>
        <?php
    }
}

/**
 * Run Forrest Run
 */
function twitter_importer_init(): TwitterImporter
{
    return TwitterImporter::get_instance();
}

add_action('plugins_loaded', 'twitter_importer_init');
