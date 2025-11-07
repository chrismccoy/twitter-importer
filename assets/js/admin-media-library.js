/**
 * Manages the Twitter/X importer on the Media Library screen.
 */

(function ($) {
  "use strict";

  const MediaImporter = {
    init: function () {
      this.cacheDOMElements();
      // Abort if the required elements are not on the page.
      if (!this.$wrapper.length) {
        return;
      }
      this.bindEvents();
    },

    cacheDOMElements: function () {
      this.$wrapper = $(".ti-media-importer-wrap");
      this.$urlInput = this.$wrapper.find(".ti-media-url");
      this.$submitBtn = this.$wrapper.find(".ti-media-submit-btn");
      this.$messageDiv = this.$wrapper.find(".ti-media-message");
    },

    bindEvents: function () {
      this.$submitBtn.on("click", this.handleImport.bind(this));
      this.$urlInput.on("keypress", (e) => {
        if (e.which === 13) {
          e.preventDefault();
          this.handleImport();
        }
      });
    },

    /**
     * Handles the import button click and initiates the AJAX request.
     */
    handleImport: function () {
      const url = this.$urlInput.val().trim();

      if (!url) {
        this.showMessage("error", tiMediaLibrary.empty_url_message);
        return;
      }

      this.setLoading(true);

      const ajaxData = {
        action: "ti_media_library_import",
        url: url,
        _ajax_nonce: tiMediaLibrary.nonce,
      };

      $.post(tiMediaLibrary.ajaxUrl, ajaxData)
        .done((response) => {
          if (response.success) {
            this.showMessage("success", response.data.message);
            this.$urlInput.val("");
            // Refresh the media library view if it's open.
            if (wp && wp.media && wp.media.frame) {
              wp.media.frame.content
                .mode("browse")
                .get()
                .collection.props.set({ ignore: +new Date() });
            }
          } else {
            this.showMessage("error", response.data.message);
          }
        })
        .fail(() => {
          this.showMessage("error", tiMediaLibrary.error_message);
        })
        .always(() => {
          this.setLoading(false);
        });
    },

    /**
     * Sets the loading state of the UI.
     */
    setLoading: function (isLoading) {
      this.$submitBtn.prop("disabled", isLoading);
      if (isLoading) {
        this.$messageDiv
          .html(
            '<span class="spinner is-active" style="float:none; vertical-align: middle;"></span>'
          )
          .removeClass("notice-error notice-success")
          .addClass("notice-info");
      }
    },

    /**
     * Displays a message to the user.
     */
    showMessage: function (type, text) {
      this.$messageDiv
        .html(`<p>${text}</p>`)
        .removeClass("notice-info notice-error notice-success")
        .addClass(`notice notice-${type}`);
    },
  };

  $(document).ready(() => MediaImporter.init());
})(jQuery);
