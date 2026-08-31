(() => {
  "use strict";

  const ROOT_SELECTOR = "[data-mt-uni-credit-advertising]";
  const PANEL_ID = "mt-uni-credit-advertising-panel";
  const focusableSelector =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function init() {
    const root = document.querySelector(ROOT_SELECTOR);
    if (!root || root.dataset.mtucAdBound === "1") {
      return;
    }
    root.dataset.mtucAdBound = "1";

    root.querySelectorAll("img").forEach((img) => {
      img.addEventListener("error", () => {
        img.style.display = "none";
      });
    });

    const panel = document.getElementById(PANEL_ID);
    let lastTrigger = null;

    document.addEventListener("click", onDocumentClick);
    if (panel) {
      document.addEventListener("keydown", onDocumentKeydown);
    }

    function onDocumentClick(event) {
      const toggle = event.target.closest(
        "[data-mt-uni-credit-advertising-toggle]",
      );
      if (toggle) {
        event.preventDefault();
        if (panel && panel.classList.contains("is-visible")) {
          closePanel();
        } else {
          openPanel(toggle);
        }
        return;
      }

      const close = event.target.closest(
        "[data-mt-uni-credit-advertising-close]",
      );
      if (close) {
        event.preventDefault();
        closePanel();
        return;
      }

      const open = event.target.closest(
        "[data-mt-uni-credit-advertising-open]",
      );
      if (!open) {
        return;
      }
      const url =
        open.getAttribute("data-mt-uni-credit-advertising-open") || "";
      if (url) {
        window.open(url, "_blank", "noopener,noreferrer");
      }
    }

    function onDocumentKeydown(event) {
      if (!panel || !panel.classList.contains("is-visible")) {
        return;
      }
      trapFocus(event);
    }

    function openPanel(trigger) {
      if (!panel) {
        return;
      }
      lastTrigger = trigger || null;
      panel.hidden = false;
      panel.classList.add("is-visible");
      panel.setAttribute("aria-hidden", "false");
      if (trigger) {
        trigger.setAttribute("aria-expanded", "true");
      }
      setBackgroundInert(true);
      const focusables = getFocusables(panel);
      if (focusables.length > 0) {
        focusables[0].focus();
      } else {
        panel.focus();
      }
    }

    function closePanel() {
      if (!panel) {
        return;
      }
      panel.classList.remove("is-visible");
      panel.hidden = true;
      panel.setAttribute("aria-hidden", "true");
      setBackgroundInert(false);
      if (lastTrigger) {
        lastTrigger.setAttribute("aria-expanded", "false");
        lastTrigger.focus();
        lastTrigger = null;
      }
    }

    function getFocusables(container) {
      return [...container.querySelectorAll(focusableSelector)].filter(
        (el) => !el.hasAttribute("disabled"),
      );
    }

    function trapFocus(event) {
      const focusables = getFocusables(panel);
      if (focusables.length === 0) {
        if (event.key === "Escape") {
          closePanel();
        }
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.key === "Tab") {
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
      if (event.key === "Escape") {
        event.preventDefault();
        closePanel();
      }
    }

    function setBackgroundInert(inert) {
      document.querySelectorAll("body > *").forEach((element) => {
        if (element === root || element.contains(root)) {
          return;
        }
        if (inert) {
          element.setAttribute("aria-hidden", "true");
          element.setAttribute("inert", "");
        } else {
          element.removeAttribute("aria-hidden");
          element.removeAttribute("inert");
        }
      });
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
