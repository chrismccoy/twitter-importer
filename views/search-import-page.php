<?php
/**
 * Admin Page View for Twitter Importer.
 *
 * This view is for the main "Search & Import" page.
 *
 * @package TwitterImporter
 * @since 3.0.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit();
}
?>
<div class="wrap twitter-importer-wrap">
    <h1><?php esc_html_e('Twitter Search & Import', 'twitter-importer'); ?></h1>

    <div id="twitter-importer-notifications"></div>

    <div class="twitter-importer-search">
        <h2><?php esc_html_e('Search Videos', 'twitter-importer'); ?></h2>

        <form class="twitter-importer-search-form" onsubmit="return false;">
            <div class="twitter-importer-search-type">
                <label>
                    <input type="radio" name="search_type" value="username" checked>
                    <?php esc_html_e('By Username', 'twitter-importer'); ?>
                </label>
                <label>
                    <input type="radio" name="search_type" value="keywords">
                    <?php esc_html_e('By Keywords', 'twitter-importer'); ?>
                </label>
                <label>
                    <input type="radio" name="search_type" value="tweet">
                    <?php esc_html_e('By Status ID', 'twitter-importer'); ?>
                </label>
            </div>

            <div class="twitter-importer-search-query">
                <input type="text" id="search_query" placeholder="<?php esc_attr_e('Enter username, keywords, or status ID...','twitter-importer'); ?>">
            </div>

            <div class="twitter-importer-search-button">
                <button type="button" id="search_button" class="button button-primary"><?php esc_html_e('Search','twitter-importer'); ?></button>
            </div>
        </form>
    </div>

    <div class="twitter-importer-results">
        <div class="twitter-importer-results-header" style="display: none;">
            <div class="twitter-importer-results-count"></div>
            <div class="twitter-importer-results-actions">
                <button id="select_all" class="button"><?php esc_html_e('Select All','twitter-importer'); ?></button>
                <button id="import_selected" class="button button-primary"><?php esc_html_e('Import Selected','twitter-importer'); ?></button>
            </div>
        </div>

        <div class="twitter-importer-results-content">
            <div class="twitter-importer-no-results">
                <?php esc_html_e('Search for videos to get started.','twitter-importer'); ?>
            </div>
        </div>
    </div>

</div>
