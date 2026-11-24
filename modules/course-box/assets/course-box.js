/**
 * Free training course signup tracking.
 * puppy_box_view: once per box, when it scrolls into view.
 * puppy_signup: a successful Fluent Forms submit of the course form (before the confirm click).
 * Both go to GA4 (gtag, queued in dataLayer) and AnalyticsWP with placement and page_url.
 * The landing page form has no [data-dh-cb] wrapper and is tracked as placement "landing".
 */
(function () {
  var cfg = window.dhCourseBox || {};
  var formId = String(cfg.formId || 13);

  function track(event, placement) {
    var props = { placement: placement, page_url: location.href.split("#")[0] };
    try {
      window.dataLayer = window.dataLayer || [];
      var gtag =
        window.gtag ||
        function () {
          window.dataLayer.push(arguments);
        };
      gtag("event", event, props);
    } catch (e) {}
    try {
      if (
        window.AnalyticsWP &&
        typeof window.AnalyticsWP.event === "function"
      ) {
        window.AnalyticsWP.event(event, props);
      }
    } catch (e) {}
  }

  function placementOf(el) {
    var box = el && el.closest ? el.closest("[data-dh-cb]") : null;
    return box ? box.getAttribute("data-placement") || "other" : "landing";
  }

  function courseForms() {
    return document.querySelectorAll(
      'form[data-form_id="' + formId + '"], form.fluent_form_' + formId,
    );
  }

  function watchViews() {
    var seen = [];
    var forms = courseForms();
    for (var i = 0; i < forms.length; i++) {
      var target = forms[i].closest("[data-dh-cb]") || forms[i];
      if (seen.indexOf(target) === -1) {
        seen.push(target);
      }
    }
    seen.forEach(function (el) {
      var place = placementOf(el.querySelector("form") || el);
      if (!("IntersectionObserver" in window)) {
        track("puppy_box_view", place);
        return;
      }
      var io = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (en) {
            if (en.isIntersecting) {
              track("puppy_box_view", place);
              io.disconnect();
            }
          });
        },
        { threshold: 0.4 },
      );
      io.observe(el);
    });
  }

  function watchSubmits() {
    if (!window.jQuery) {
      return;
    }
    window
      .jQuery(document)
      .on("fluentform_submission_success", function (e, data) {
        var form = data && data.form ? data.form[0] || data.form : e.target;
        if (!form || !form.getAttribute) {
          return;
        }
        var id = form.getAttribute("data-form_id");
        if (
          id !== formId &&
          !(form.classList && form.classList.contains("fluent_form_" + formId))
        ) {
          return;
        }
        track("puppy_signup", placementOf(form));
      });
  }

  function start() {
    watchViews();
    watchSubmits();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
