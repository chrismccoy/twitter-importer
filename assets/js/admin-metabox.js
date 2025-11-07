/**
 * Manages the "Fetch Twitter/X Media" meta box on post edit screens.
 */

class TI_Metabox_Handler {

  constructor(containerSelector) {
    this.container = document.querySelector(containerSelector);

    // Abort if the meta box is not on the page
    if (!this.container) {
      return;
    }

    this.fetchButton = this.container.querySelector("#ti_fetch_button");
    this.sourceInput = this.container.querySelector("#ti_fetch_source");
    this.noticeDiv = this.container.querySelector("#ti-fetch-notice");
    this.descriptionP = this.container.querySelector("#ti-fetch-description");
    this.btnStatus = this.container.querySelector('button[data-type="status"]');
    this.btnUser = this.container.querySelector('button[data-type="user"]');

    // Abort if any element is missing
    if (
      !this.fetchButton ||
      !this.sourceInput ||
      !this.noticeDiv ||
      !this.descriptionP ||
      !this.btnStatus ||
      !this.btnUser
    ) {
      console.error("TI Meta Box: Could not find all required elements.");
      return;
    }

    this.currentFetchType = "status"; // Default fetch type

    this.handleFetch = this.handleFetch.bind(this);
    this.handleTypeSwitch = this.handleTypeSwitch.bind(this);
    this.handleDismiss = this.handleDismiss.bind(this);

    this.bindEvents();
  }

  /**
   * Attaches all necessary event listeners using vanilla JS.
   */
  bindEvents() {
    this.fetchButton.addEventListener("click", this.handleFetch);
    this.sourceInput.addEventListener("keypress", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        this.handleFetch();
      }
    });
    this.btnStatus.addEventListener("click", this.handleTypeSwitch);
    this.btnUser.addEventListener("click", this.handleTypeSwitch);
    this.noticeDiv.addEventListener("click", this.handleDismiss);
  }

  /**
   * Handles the click event on the type switcher buttons.
   */
  handleTypeSwitch(event) {
    event.preventDefault();
    const newType = event.currentTarget.dataset.type;

    if (newType === this.currentFetchType) {
      return; // Do nothing if the type is already active
    }

    this.currentFetchType = newType;

    const isUserType = newType === "user";
    this.btnUser.classList.toggle("button-primary", isUserType);
    this.btnStatus.classList.toggle("button-primary", !isUserType);

    if (isUserType) {
      this.sourceInput.placeholder = "e.g., elonmusk";
    } else {
      this.sourceInput.placeholder = "e.g., https://x.com/user/status/12345";
    }
  }

  /**
   * Handles the click event on the notice dismiss button.
   */
  handleDismiss(event) {
    if (event.target.classList.contains("notice-dismiss")) {
      this.noticeDiv.innerHTML = "";
      this.noticeDiv.className = "";
    }
  }

  /**
   * Main function to handle the entire fetch process.
   */
  handleFetch() {
    const value = this.sourceInput.value.trim();

    if (value === "") {
      let errorMessage = "";
      if (this.currentFetchType === "status") {
        errorMessage = "Please enter a twitter.com/x.com status url";
      } else {
        errorMessage = "Please enter a twitter.com/x.com username";
      }
      this.showNotice("error", errorMessage);
      this.sourceInput.focus();
      return;
    }

    this.setLoadingState(true);

    // Use jQuery only for the AJAX call for WordPress compatibility
    jQuery
      .ajax({
        url: tiMetabox.ajaxUrl,
        type: "POST",
        data: {
          action: "ti_fetch_media",
          nonce: tiMetabox.nonce,
          type: this.currentFetchType,
          value: value,
        },
      })
      .done((res) => {
        if (!res.success) {
          this.showNotice("error", res.data.message);
          return;
        }
        this.showNotice("success", "Media inserted successfully!");
        this.insertContent(res.data);
        this.sourceInput.value = ""; // Clear input on success
      })
      .fail((jqXHR) => {
        const message =
          jqXHR.responseJSON?.data?.message ||
          "An unknown error occurred. Please try again.";
        this.showNotice("error", message);
      })
      .always(() => {
        this.setLoadingState(false);
      });
  }

  /**
   * Toggles the UI between a loading and idle state.
   */
  setLoadingState(isLoading) {
    if (isLoading) {
      this.fetchButton.textContent = "Fetching...";
      this.fetchButton.disabled = true;
      this.sourceInput.disabled = true;
      this.noticeDiv.innerHTML = "";
      this.noticeDiv.className = "";
    } else {
      this.fetchButton.textContent = "Fetch & Insert";
      this.fetchButton.disabled = false;
      this.sourceInput.disabled = false;
    }
  }

  /**
   * Displays a success or error notice to the user within the meta box.
   */
  showNotice(type, message) {
    this.noticeDiv.innerHTML = "";
    this.noticeDiv.className = ""; // Clear existing classes

    const colorClass = type === "success" ? "notice-success" : "notice-error";
    const noticeClass = `notice ${colorClass} is-dismissible`;
    const dismissButton =
      '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';

    this.noticeDiv.className = noticeClass;
    this.noticeDiv.innerHTML = `<p>${message}</p>${dismissButton}`;
  }

  /**
   * Inserts the fetched media content into the correct editor.
   */
  insertContent(content) {
    let insertCode = "";
    if (content.type === "image") {
      insertCode = `<img src="${content.src}" alt="" />`;
    } else {
      insertCode = `[video src="${content.src}" poster="${content.poster}"]`;
    }

    // Block Editor logic
    if (
      window.wp &&
      window.wp.data &&
      window.wp.data.dispatch("core/block-editor")
    ) {
      const block =
        content.type === "image"
          ? window.wp.blocks.createBlock("core/image", {
              url: content.src,
              linkDestination: "none",
            })
          : window.wp.blocks.createBlock("core/video", {
              src: content.src,
              poster: content.poster,
            });
      window.wp.data.dispatch("core/block-editor").insertBlocks(block);
      return;
    }

    // Classic Editor logic
    if (
      typeof tinyMCE !== "undefined" &&
      tinyMCE.activeEditor &&
      !tinyMCE.activeEditor.isHidden()
    ) {
      tinyMCE.activeEditor.execCommand("mceInsertContent", false, insertCode);
    } else {
      const contentTextarea = document.getElementById("content");
      if (contentTextarea) {
        const start = contentTextarea.selectionStart;
        const end = contentTextarea.selectionEnd;
        contentTextarea.value =
          contentTextarea.value.substring(0, start) +
          insertCode +
          contentTextarea.value.substring(end);
        contentTextarea.selectionStart = contentTextarea.selectionEnd =
          start + insertCode.length;
      }
    }
  }
}

document.addEventListener("DOMContentLoaded", () => {
  new TI_Metabox_Handler("#ti_metabox");
});
