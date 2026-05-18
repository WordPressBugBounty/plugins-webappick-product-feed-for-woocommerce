/**
 * CTX Feed Onboarding Wizard JavaScript
 *
 * @package    Woo_Feed
 * @subpackage Woo_Feed/admin/js
 * @since      6.6.33
 */

(function ($) {
  "use strict";

  var WooFeedOnboardingWizard = {
    selectedPlugins: [],
    installQueue: [],
    currentIndex: 0,
    installTotal: 0,
    installedNow: 0,
    isInstalling: false,

    init: function () {
      this.i18n = wooFeedOnboarding.i18n;
      this.bindEvents();
      this.syncInitialSelection();
      this.updateSelectedPlugins();
    },

    bindEvents: function () {
      var self = this;

      $(document).on("change", ".ctx-feed-plugin-checkbox", function () {
        self.toggleCardSelection($(this));
        self.updateSelectedPlugins();
      });

      $(document).on("click", "#ctx-feed-onboarding-continue", function (e) {
        e.preventDefault();
        if (self.selectedPlugins.length === 0) {
          self.skipAndRedirect();
        } else {
          self.startInstallation();
        }
      });

      $(document).on("click", ".ctx-feed-onboarding-skip", function (e) {
        e.preventDefault();
        self.skipAndRedirect();
      });

      $(document).on("click", ".ctx-feed-plugin-card", function (e) {
        if (self.isInstalling) {
          return;
        }
        if ($(e.target).closest(".ctx-feed-plugin-checkbox-wrapper").length) {
          return;
        }
        var $checkbox = $(this).find(".ctx-feed-plugin-checkbox");
        if (!$checkbox.prop("disabled")) {
          $checkbox
            .prop("checked", !$checkbox.prop("checked"))
            .trigger("change");
        }
      });
    },

    /**
     * Reflect initial checkbox state onto card classes.
     */
    syncInitialSelection: function () {
      var self = this;
      $(".ctx-feed-plugin-checkbox").each(function () {
        self.toggleCardSelection($(this));
      });
    },

    toggleCardSelection: function ($checkbox) {
      var $card = $checkbox.closest(".ctx-feed-plugin-card");
      if ($checkbox.prop("checked")) {
        $card.addClass("is-selected");
      } else {
        $card.removeClass("is-selected");
      }
    },

    updateSelectedPlugins: function () {
      var self = this;
      self.selectedPlugins = [];

      $(".ctx-feed-plugin-checkbox:checked:not(:disabled)").each(function () {
        self.selectedPlugins.push($(this).val());
      });

      $("#ctx-feed-onboarding-continue").prop("disabled", false);
    },

    startInstallation: function () {
      var self = this;
      if (self.isInstalling || self.selectedPlugins.length === 0) {
        return;
      }

      self.installQueue = self.selectedPlugins.slice();
      self.installTotal = self.installQueue.length;
      self.installedNow = 0;
      self.currentIndex = 0;
      self.isInstalling = true;

      $(".ctx-feed-plugin-card").addClass("is-locked");
      $(".ctx-feed-plugin-checkbox").prop("disabled", true);
      $("#ctx-feed-onboarding-continue").prop("disabled", true);
      $("#ctx-feed-onboarding-continue span").text(self.i18n.installing);
      $(".ctx-feed-onboarding-progress").show();

      self.updateProgress(self.i18n.installing, 0);
      self.installNextPlugin();
    },

    updateProgress: function (text, completed) {
      $(".ctx-feed-progress-text").text(text || "");
      $(".ctx-feed-progress-count").text(completed + "/" + this.installTotal);
      this.updateProgressBar(completed, this.installTotal);
    },

    updateProgressBar: function (completed, total) {
      var percent =
        total > 0 ? Math.min(100, Math.round((completed / total) * 100)) : 0;
      $(".ctx-feed-progress-fill").css("width", percent + "%");
    },

    installNextPlugin: function () {
      var self = this;

      if (self.currentIndex >= self.installQueue.length) {
        self.completeInstallation();
        return;
      }

      var pluginSlug = self.installQueue[self.currentIndex];
      var $card = $('[data-plugin-slug="' + pluginSlug + '"]');

      $card.addClass("is-installing");
      self.updateProgress(self.i18n.installing, self.installedNow);

      $.ajax({
        url: wooFeedOnboarding.ajaxUrl,
        type: "POST",
        data: {
          action: "woo_feed_onboarding_install_plugin",
          plugin_slug: pluginSlug,
          nonce: wooFeedOnboarding.nonce,
        },
        success: function (response) {
          $card.removeClass("is-installing");

          if (response.success) {
            $card
              .removeClass("ctx-feed-plugin-card--blue")
              .addClass("ctx-feed-plugin-card--green is-installed is-active");
            $card.find(".ctx-feed-plugin-checkbox").prop("disabled", true);
            self.installedNow++;
          }

          self.currentIndex++;
          self.updateProgress(self.i18n.installing, self.installedNow);
          self.installNextPlugin();
        },
        error: function () {
          $card.removeClass("is-installing");
          self.currentIndex++;
          self.installNextPlugin();
        },
      });
    },

    completeInstallation: function () {
      var self = this;

      self.updateProgress(self.i18n.finished, self.installedNow);

      $("#ctx-feed-onboarding-continue").hide();
      $("#ctx-feed-onboarding-generate").show();

      self.completeOnboarding();
    },

    completeOnboarding: function () {
      $.ajax({
        url: wooFeedOnboarding.ajaxUrl,
        type: "POST",
        data: {
          action: "woo_feed_complete_onboarding",
          nonce: wooFeedOnboarding.nonce,
        },
        success: function () {
          if ($("#ctx-feed-onboarding-generate").is(":hidden")) {
            window.location.href = wooFeedOnboarding.redirectUrl;
          }
        },
      });
    },

    skipAndRedirect: function () {
      $.ajax({
        url: wooFeedOnboarding.ajaxUrl,
        type: "POST",
        data: {
          action: "woo_feed_complete_onboarding",
          nonce: wooFeedOnboarding.nonce,
        },
        complete: function () {
          window.location.replace(wooFeedOnboarding.redirectUrl);
        },
      });
    },

    resetState: function () {
      this.isInstalling = false;
      this.installQueue = [];
      this.currentIndex = 0;
      this.installTotal = 0;
      this.installedNow = 0;
    },
  };

  $(document).ready(function () {
    const container = document.getElementById("ctx-feed-onboarding");
    WooFeedOnboardingWizard.init();
    container.scrollIntoView({ behavior: "smooth" });
  });

  $(window).on("pageshow", function (event) {
    if (event.originalEvent && event.originalEvent.persisted) {
      WooFeedOnboardingWizard.resetState();
      WooFeedOnboardingWizard.updateSelectedPlugins();
    }
  });
})(jQuery);
