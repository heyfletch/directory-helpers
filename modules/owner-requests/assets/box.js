/**
 * Owner request box: fills the hidden fields and emits browser events.
 *
 * Events are DOM CustomEvents on window, named dh-request-box:<name>, and each
 * is also sent to GA4 (request_box_view, request_start, request_submit,
 * request_error) and AnalyticsWP with only the page and its type, never what
 * the owner typed.
 *
 *   view     the box is at least half on screen (once per page view)
 *   start    first input or focus inside the form (once)
 *   submit   Fluent Forms reports a successful submission
 *   error    Fluent Forms reports a failed submission
 */
(function () {
  "use strict";

  function emit(name, box, extra) {
    var detail = {
      name: name,
      source_type: box.getAttribute("data-source-type") || "other",
      source_post: parseInt(box.getAttribute("data-source-post") || "0", 10),
    };
    if (extra) {
      for (var k in extra) {
        detail[k] = extra[k];
      }
    }
    try {
      window.dispatchEvent(
        new CustomEvent("dh-request-box:" + name, { detail: detail }),
      );
    } catch (e) {
      /* old browser: no events */
    }
    track(name, detail);
  }

  var TRACKED = {
    view: "request_box_view",
    start: "request_start",
    submit: "request_submit",
    error: "request_error",
  };

  function track(name, detail) {
    var event = TRACKED[name];
    if (!event) {
      return;
    }
    var props = {
      source_type: detail.source_type,
      source_post: detail.source_post,
    };
    try {
      // Queued in dataLayer, so GA4 still gets it if gtag.js loads later.
      window.dataLayer = window.dataLayer || [];
      var gtag =
        window.gtag ||
        function () {
          window.dataLayer.push(arguments);
        };
      gtag("event", event, props);
    } catch (e) {
      /* tracking never blocks the form */
    }
    try {
      if (window.AnalyticsWP && typeof window.AnalyticsWP.event === "function") {
        window.AnalyticsWP.event(event, props);
      }
    } catch (e) {
      /* tracking never blocks the form */
    }
  }

  function setHidden(form, name, value) {
    var el = form.querySelector('input[name="' + name + '"]');
    if (el && !el.value) {
      el.value = value;
    }
  }

  function init(box) {
    if (box.__dhRb) {
      return;
    }
    box.__dhRb = true;

    var params = new URLSearchParams(window.location.search);
    var fill = function () {
      var form = box.querySelector("form");
      if (!form) {
        return false;
      }
      setHidden(
        form,
        "source_post_id",
        box.getAttribute("data-source-post") || "0",
      );
      setHidden(
        form,
        "source_type",
        box.getAttribute("data-source-type") || "other",
      );
      setHidden(
        form,
        "viewed_profile_id",
        box.getAttribute("data-viewed-profile") || "0",
      );
      ["utm_source", "utm_medium", "utm_campaign"].forEach(function (k) {
        if (params.get(k)) {
          setHidden(form, k, params.get(k));
        }
      });
      return true;
    };
    if (!fill()) {
      document.addEventListener("DOMContentLoaded", fill);
    }
    window.setTimeout(fill, 800); // Fluent Forms can render a moment after load.

    // view
    if ("IntersectionObserver" in window) {
      var seen = false;
      var io = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (e) {
            if (e.isIntersecting && e.intersectionRatio >= 0.5 && !seen) {
              seen = true;
              emit("view", box);
              io.disconnect();
            }
          });
        },
        { threshold: [0.5] },
      );
      io.observe(box);
    }

    // start
    var started = false;
    var start = function () {
      if (started) {
        return;
      }
      started = true;
      emit("start", box);
    };
    box.addEventListener("focusin", start);
    box.addEventListener("input", start);

    // submit and error (Fluent Forms triggers these on the form element through jQuery)
    if (window.jQuery) {
      window.jQuery(box).on("fluentform_submission_success", function () {
        emit("submit", box);
      });
      window.jQuery(box).on("fluentform_submission_failed", function () {
        emit("error", box);
      });
    }
  }

  function boot() {
    var boxes = document.querySelectorAll("[data-dh-rb]");
    for (var i = 0; i < boxes.length; i++) {
      init(boxes[i]);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
