<?php
/**
 * View for the Bulk Importer page.
 */

if (!defined('ABSPATH')) {
    exit();
}
?>
<div class="wrap">
    <h1 style="padding-top: 15px;"><?php esc_html_e('Twitter Media Bulk Importer','twitter-importer'); ?></h1>
    <?php
    if (isset($_GET['status']) && $_GET['status'] === 'complete') {
        $success_count = isset($_GET['success']) ? absint($_GET['success']) : 0;
        $failed_count = isset($_GET['failed']) ? absint($_GET['failed']) : 0;
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            sprintf(
                esc_html__(
                    'Import complete. Succeeded: %d, Failed: %d.',
                    'twitter-importer'
                ),
                $success_count,
                $failed_count
            )
        );
    }
    ?>
    <div class="metabox-holder postbox">
        <div class="postbox-header"><h2><?php esc_html_e('Import Posts','twitter-importer'); ?></h2></div>
        <div class="inside">
            <p><?php esc_html_e('Enter one import per line in the format: TweetURL|Post Title','twitter-importer'); ?></p>
            <p><strong><?php esc_html_e('Example:','twitter-importer'); ?></strong> <code><?php echo esc_html('https://x.com/user/status/12345|My Awesome Post Title'); ?></code></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ti_bulk_import">
                <?php wp_nonce_field('ti_bulk_import_nonce'); ?>
                <textarea cols="100" rows="15" name="import_data" class="widefat" placeholder="<?php esc_attr_e('Paste your import data here...','twitter-importer'); ?>"></textarea>
                <?php submit_button(__('Import Posts', 'twitter-importer'),'primary','ti_submit_import'); ?>
            </form>
        </div>
    </div>
</div>
