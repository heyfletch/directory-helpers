/**
 * Data report click tracking.
 * Every element with a data-track name (downloads, chart files, embed copy buttons, credit link, press and page links)
 * sends one event named after that value on click, to GA4 (gtag, queued in dataLayer) and AnalyticsWP when present.
 */
(function () {
  function send(event, props) {
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
      if (window.AnalyticsWP && typeof window.AnalyticsWP.event === "function") {
        window.AnalyticsWP.event(event, props);
      }
    } catch (e) {}
  }

  document.addEventListener(
    "click",
    function (ev) {
      var el = ev.target && ev.target.closest ? ev.target.closest("[data-track]") : null;
      if (!el) return;
      var name = el.getAttribute("data-track");
      if (!name) return;
      send(name, {
        page_url: location.href.split("#")[0],
        link_url: el.getAttribute("href") || "",
      });
    },
    true,
  );
})();
