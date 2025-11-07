# 🐦 Twitter Importer for WordPress

> A powerful and intuitive toolkit to search, fetch, and import media from Twitter/X directly into your WordPress site.

## ✨ Features

*   **🔍 Advanced Search & Import Page**
    *   Search for media by **Username**, **Keywords**, or a specific **Tweet URL/ID**.
    *   Enjoy a fast, **AJAX-powered** search experience without page reloads.
    *   View results in a clean, visual grid layout showing thumbnails, usernames, and view counts.
    *   **Duplicate import prevention** automatically detects and flags media you've already imported.

*   **📥 Effortless Importing**
    *   **One-click import** to create a new WordPress post from any search result.
    *   Select multiple videos and **bulk import** them all at once.
    *   Automatically creates posts with a WordPress `[video]` shortcode, including the poster image.
    *   Intelligently generates a post title from the Twitter username and video ID.

*   **🖼️ Seamless Media Handling**
    *   All media (videos, images, posters) is **sideloaded directly** into your WordPress Media Library.
    *   The imported video's poster is **automatically set as the post's Featured Image**.
    *   Enable an optional setting to **scan any post on save** and set a featured image if a video poster is found in the content.

*   **✍️ Deep Editor Integration**
    *   A handy **"Fetch Twitter/X Media" meta box** is available in both the Classic and Block Editors.
    *   Fetch media by **Status URL** or a **Username's latest media** without leaving the editor.
    *   Inserts content correctly for your editor version: `<img>` tags and `[video]` shortcodes for Classic, or `core/image` and `core/video` blocks for the Block Editor.

*   **📂 Direct Media Library Importing**
    *   A new import option is added directly to the **Media Library's "Add New" screen**.
    *   Simply paste a Tweet URL to download its media straight into your library.

*   **📚 Bulk & Power User Tools**
    *   A dedicated **Bulk Importer** page to create multiple posts from a list.
    *   Use the simple `TweetURL|Post Title` format to import dozens of posts at once.

*   **💻 Full WP-CLI Support**
    *   Manage imports from the command line with comprehensive WP-CLI commands.
    *   `wp twitter get-media <url_or_user>`: Fetch and display media information for a tweet or user.
    *   `wp twitter create-post <username>`: Create a new post from a user's latest media with customizable title, status, and author.

*   **🎨 Modern & Intuitive UI**
    *   Clean, modern admin interface that is easy to navigate.
    *   Real-time status indicators show which items are **selected**, **importing**, **imported**, or have **failed**.
    *   Clear, user-friendly notifications for success and error messages.
    *   A simple settings page to easily toggle plugin features.
