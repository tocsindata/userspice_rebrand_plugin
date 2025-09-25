// UserSpice ReBrand — Admin JS (lightweight, no dependencies)
(function () {
  "use strict";

  function onReady(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn, { once: true });
    } else {
      fn();
    }
  }

  onReady(function () {
    // 1) Diff prettifier: colorize + / - lines inside .rebrand-diff blocks
    document.querySelectorAll(".rebrand-diff").forEach(function (el) {
      // Already processed?
      if (el.dataset.rebrandDiffProcessed === "1") return;

      var src = el.textContent || "";
      var lines = src.split("\n");
      var out = document.createElement("div");

      lines.forEach(function (line) {
        var span = document.createElement("span");
        // classify lines by first two chars ("+ ", "- ", or otherwise)
        if (line.startsWith("+ ")) {
          span.className = "line-add";
        } else if (line.startsWith("- ")) {
          span.className = "line-del";
        } else {
          span.className = "line-ctx";
        }
        // Preserve spacing exactly
        span.appendChild(document.createTextNode(line));
        out.appendChild(span);
        out.appendChild(document.createTextNode("\n"));
      });

      // Replace content
      el.textContent = "";
      el.appendChild(out);
      el.dataset.rebrandDiffProcessed = "1";
    });

    // 2) Generic confirm handler for any clickable with [data-confirm]
    document.body.addEventListener("click", function (ev) {
      var t = ev.target;
      if (!(t instanceof Element)) return;

      // Walk up to find a data-confirm attribute
      var el = t.closest("[data-confirm]");
      if (!el) return;

      var msg = el.getAttribute("data-confirm") || "Are you sure?";
      // If element is a form submit button, let the form's onsubmit run if provided.
      if (!window.confirm(msg)) {
        ev.preventDefault();
        ev.stopPropagation();
      }
    });

    // 3) Optional file preview: any <input type="file" data-preview="#selector">
    // Shows a live image preview for supported types.
    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (inp) {
      var sel = inp.getAttribute("data-preview");
      var img = sel ? document.querySelector(sel) : null;
      if (!img) return;

      inp.addEventListener("change", function () {
        var file = inp.files && inp.files[0];
        if (!file) {
          // clear preview
          if (img.tagName.toLowerCase() === "img") img.src = "";
          return;
        }
        if (!/^image\/(png|jpeg|jpg|gif|webp|x-icon|vnd\.microsoft\.icon)$/.test(file.type)) {
          return; // ignore non-images
        }
        var reader = new FileReader();
        reader.onload = function (e) {
          if (img.tagName.toLowerCase() === "img") {
            img.src = e.target && e.target.result ? String(e.target.result) : "";
          }
        };
        reader.readAsDataURL(file);
      });
    });

    // 4) Autoscroll to flash messages (if present) to ensure user sees result
    var flash = document.querySelector(".alert.alert-success, .alert.alert-danger");
    if (flash && typeof flash.scrollIntoView === "function") {
      flash.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  });
})();
