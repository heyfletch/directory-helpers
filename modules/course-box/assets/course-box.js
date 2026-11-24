/**
 * Free training course signup tracking.
 * puppy_box_view: once per box, when it scrolls into view.
 * puppy_signup: a successful Fluent Forms submit of the course form (before the confirm click).
 * Both go to GA4 (gtag, queued in dataLayer) and AnalyticsWP with placement and page_url.
 * Forms with no [data-dh-cb] wrapper (landing page, puppy guides) use the page placement from PHP.
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
    return box
      ? box.getAttribute("data-placement") || "other"
      : cfg.placement || "other";
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

  /* Feature layout: the inbox preview shows the first lessons for the dog type the visitor picks. */
  function watchPicks() {
    var boxes = document.querySelectorAll(".dh-cb--feature[data-lessons]");
    Array.prototype.forEach.call(boxes, function (box) {
      var data;
      try {
        data = JSON.parse(box.getAttribute("data-lessons"));
      } catch (e) {
        return;
      }
      var label = box.querySelector(".dh-cbf__for");
      var rows = box.querySelectorAll(".dh-cbf__mail");
      box.addEventListener("change", function (e) {
        var t = e.target;
        if (!t || t.name !== "dog_type" || !data[t.value]) {
          return;
        }
        var pick = data[t.value];
        if (label) {
          label.textContent = pick.label;
        }
        Array.prototype.forEach.call(rows, function (row, i) {
          var week = pick.weeks[i];
          if (!week) {
            return;
          }
          row.querySelector(".dh-cbf__subj").textContent = week[0];
          row.querySelector(".dh-cbf__prev").textContent = week[1];
          row.classList.remove("is-new");
          void row.offsetWidth;
          row.classList.add("is-new");
        });
      });
    });
  }

  function start() {
    watchViews();
    watchSubmits();
    watchPicks();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
