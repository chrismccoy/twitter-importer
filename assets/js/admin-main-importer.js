/**
 * Manages the main "Search & Import" page for the Twitter Importer plugin.
 */

(function ($) {
  "use strict";

  const MainImporter = {
    init: function () {
      this.cacheDOMElements();
      this.bindEvents();
      this.selectedVideos = new Set();
    },

    cacheDOMElements: function () {
      this.$searchButton = $("#search_button");
      this.$searchInput = $("#search_query");
      this.$searchType = $('input[name="search_type"]');
      this.$resultsContent = $(".twitter-importer-results-content");
      this.$resultsHeader = $(".twitter-importer-results-header");
      this.$resultsCount = $(".twitter-importer-results-count");
      this.$notifications = $("#twitter-importer-notifications");
      this.$selectAllButton = $("#select_all");
      this.$importSelectedButton = $("#import_selected");
    },

    bindEvents: function () {
      this.$searchButton.on("click", this.handleSearch.bind(this));
      this.$searchInput.on("keypress", (e) => {
        if (e.which === 13) {
          e.preventDefault();
          this.handleSearch();
        }
      });
      this.$resultsContent.on(
        "click",
        ".twitter-importer-video-item",
        this.handleItemClick.bind(this)
      );
      this.$resultsContent.on(
        "click",
        ".import-btn",
        this.handleSingleImport.bind(this)
      );
      this.$selectAllButton.on("click", this.handleSelectAll.bind(this));
      this.$importSelectedButton.on(
        "click",
        this.handleBulkImport.bind(this)
      );
    },

    /**
     * Handles the search button click and initiates the AJAX request.
     */
    handleSearch: function () {
      const query = this.$searchInput.val().trim();
      const type = this.$searchType.filter(":checked").val();

      if (!query) {
        this.showNotification("error", "Please enter a search query.");
        return;
      }

      this.setLoading(true);
      this.selectedVideos.clear();

      $.post(twitterImporter.ajaxUrl, {
        action: "twitter_search",
        nonce: twitterImporter.nonce,
        query: query,
        type: type,
      })
        .done((response) => {
          if (response.success) {
            this.renderResults(response.data.videos);
          } else {
            this.showNotification("error", response.data.message);
            this.renderResults([]);
          }
        })
        .fail(() => {
          this.showNotification("error", "An unknown error occurred.");
          this.renderResults([]);
        })
        .always(() => {
          this.setLoading(false);
        });
    },

    /**
     * Sets the loading state of the UI.
     */
    setLoading: function (isLoading) {
      this.$searchButton.prop("disabled", isLoading);
      if (isLoading) {
        this.$resultsHeader.hide();
        this.$resultsContent.html('<div class="twitter-importer-loading"></div>');
      }
    },

    /**
     * Renders the search results in the results container.
     */
    renderResults: function (videos) {
      if (!videos || videos.length === 0) {
        this.$resultsHeader.hide();
        this.$resultsContent.html(
          '<div class="twitter-importer-no-results">No videos found for your search.</div>'
        );
        return;
      }

      const html = videos.map((video) => this.getVideoItemHTML(video)).join("");
      this.$resultsContent.html(html);
      this.$resultsCount.text(`${videos.length} videos found.`);
      this.$resultsHeader.show();
      this.updateSelectionUI();
    },

    /**
     * Generates the HTML for a single video item.
     */
    getVideoItemHTML: function (video) {
      const isImported = video.is_imported;
      const itemClass = isImported ? "imported" : "";
      const views = video.views
        ? `${Number(video.views).toLocaleString()} views`
        : "N/A";

      const actionButton = isImported
        ? `<a href="${video.post_url}" target="_blank" class="button imported-btn">View Post</a>`
        : `<button class="button button-primary import-btn" data-video-id="${video.id}">Import</button>`;

      return `
        <div class="twitter-importer-video-item ${itemClass}" data-video-id="${
        video.id
      }" data-video-data='${JSON.stringify(video)}'>
          <div class="twitter-importer-video-thumbnail">
            <img src="${video.thumbnail}" alt="Video thumbnail">
          </div>
          <div class="twitter-importer-video-info">
            <div class="twitter-importer-video-meta">
              <span class="twitter-importer-username">@${video.userName}</span>
              <span class="twitter-importer-views">${views}</span>
            </div>
          </div>
          <div class="twitter-importer-video-actions">
            ${actionButton}
          </div>
        </div>`;
    },

    /**
     * Handles a click on a video item to select/deselect it.
     */
    handleItemClick: function (e) {
      const $item = $(e.currentTarget);
      if ($item.hasClass("imported") || $item.hasClass("importing")) {
        return;
      }

      const videoId = $item.data("videoId");
      if (this.selectedVideos.has(videoId)) {
        this.selectedVideos.delete(videoId);
        $item.removeClass("selected");
      } else {
        this.selectedVideos.add(videoId);
        $item.addClass("selected");
      }
      this.updateSelectionUI();
    },

    /**
     * Handles the "Select All" button click.
     */
    handleSelectAll: function () {
      const $items = this.$resultsContent.find(
        ".twitter-importer-video-item:not(.imported)"
      );
      const allSelected = this.selectedVideos.size === $items.length;

      $items.each((_, el) => {
        const $item = $(el);
        const videoId = $item.data("videoId");
        if (allSelected) {
          this.selectedVideos.delete(videoId);
          $item.removeClass("selected");
        } else {
          this.selectedVideos.add(videoId);
          $item.addClass("selected");
        }
      });
      this.updateSelectionUI();
    },

    /**
     * Updates the UI related to selections (e.g., button text and state).
     */
    updateSelectionUI: function () {
      const numSelected = this.selectedVideos.size;
      const numSelectable = this.$resultsContent.find(
        ".twitter-importer-video-item:not(.imported)"
      ).length;

      this.$importSelectedButton.prop("disabled", numSelected === 0);
      this.$importSelectedButton.text(`Import Selected (${numSelected})`);

      if (numSelected === numSelectable && numSelectable > 0) {
        this.$selectAllButton.text("Deselect All");
      } else {
        this.$selectAllButton.text("Select All");
      }
    },

    /**
     * Handles the import of a single video.
     */
    handleSingleImport: function (e) {
      e.stopPropagation();
      const $button = $(e.currentTarget);
      const $item = $button.closest(".twitter-importer-video-item");
      const videoData = $item.data("videoData");

      this.importVideos([videoData]);
    },

    /**
     * Handles the import of all selected videos.
     */
    handleBulkImport: function () {
      const videosToImport = [];
      this.selectedVideos.forEach((videoId) => {
        const $item = this.$resultsContent.find(
          `.twitter-importer-video-item[data-video-id="${videoId}"]`
        );
        videosToImport.push($item.data("videoData"));
      });

      if (videosToImport.length > 0) {
        this.importVideos(videosToImport);
      }
    },

    /**
     * Performs the AJAX request to import one or more videos.
     */
    importVideos: function (videos) {
      const isBulk = videos.length > 1;
      const action = isBulk ? "twitter_import_multiple" : "twitter_import";
      const dataKey = isBulk ? "videos_data" : "video_data";
      const data = isBulk ? videos : videos[0];

      videos.forEach((video) => {
        this.setItemState(video.id, "importing");
      });

      $.post(twitterImporter.ajaxUrl, {
        action: action,
        nonce: twitterImporter.nonce,
        [dataKey]: JSON.stringify(data),
      })
        .done((response) => {
          if (response.success) {
            if (isBulk) {
              this.handleBulkImportResponse(response.data.results);
            } else {
              this.setItemState(data.id, "imported", response.data.post_url);
              this.showNotification(
                "success",
                "Video imported successfully."
              );
            }
          } else {
            this.showNotification("error", response.data.message);
            videos.forEach((video) =>
              this.setItemState(video.id, "error", response.data.message)
            );
          }
        })
        .fail(() => {
          const errorMsg = "An unknown error occurred during import.";
          this.showNotification("error", errorMsg);
          videos.forEach((video) => this.setItemState(video.id, "error", errorMsg));
        })
        .always(() => {
          this.selectedVideos.clear();
          this.updateSelectionUI();
        });
    },

    /**
     * Processes the response from a bulk import AJAX call.
     */
    handleBulkImportResponse: function (results) {
      let successCount = 0;
      let errorCount = 0;

      results.forEach((result) => {
        if (result.success) {
          this.setItemState(result.id, "imported", result.post_url);
          successCount++;
        } else {
          this.setItemState(result.id, "error", result.message);
          errorCount++;
        }
      });

      if (successCount > 0) {
        this.showNotification(
          "success",
          `${successCount} videos imported successfully.`
        );
      }
      if (errorCount > 0) {
        this.showNotification(
          "error",
          `${errorCount} videos failed to import.`
        );
      }
    },

    /**
     * Updates the visual state of a video item during/after import.
     */
    setItemState: function (videoId, state, data) {
      const $item = this.$resultsContent.find(
        `.twitter-importer-video-item[data-video-id="${videoId}"]`
      );
      $item.removeClass("importing imported import-error selected");

      switch (state) {
        case "importing":
          $item.addClass("importing");
          break;
        case "imported":
          $item.addClass("imported");
          $item
            .find(".twitter-importer-video-actions")
            .html(
              `<a href="${data}" target="_blank" class="button imported-btn">View Post</a>`
            );
          break;
        case "error":
          $item.addClass("import-error");
          $item
            .find(".twitter-importer-video-actions")
            .html(
              `<p class="error-message" style="color: #d63638; font-size: 12px;">Error: ${data}</p>`
            );
          break;
      }
    },

    /**
     * Displays a notification message to the user.
     */
    showNotification: function (type, message) {
      const notice = `
        <div class="twitter-importer-notice notice-${type}">
          <p>${message}</p>
          <button type="button" class="twitter-importer-notice-dismiss">&times;</button>
        </div>`;
      const $notice = $(notice).hide();
      this.$notifications.append($notice);
      $notice.fadeIn();

      $notice.find(".twitter-importer-notice-dismiss").on("click", function () {
        $(this).closest(".twitter-importer-notice").fadeOut(300, function () {
          $(this).remove();
        });
      });

      setTimeout(() => {
        $notice.fadeOut(300, () => $notice.remove());
      }, 5000);
    },
  };

  $(document).ready(() => MainImporter.init());
})(jQuery);
