(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function initSourceDock() {
    var dock = qs("[data-source-dock]");
    if (!dock) return;

    var panel = qs("[data-source-panel]", dock);
    var toggles = qsa("[data-source-toggle]", dock);
    var fileBtns = qsa("[data-source-file]", dock);
    var docs = qsa("[data-source-doc]", dock);

    function setOpen(open) {
      dock.classList.toggle("is-open", open);
      if (!panel) return;
      if (open) panel.removeAttribute("hidden");
      else panel.setAttribute("hidden", "");
      toggles.forEach(function (btn) {
        if (btn.hasAttribute("aria-expanded")) {
          btn.setAttribute("aria-expanded", open ? "true" : "false");
        }
      });
    }

    function showFile(name) {
      fileBtns.forEach(function (btn) {
        var active = btn.getAttribute("data-source-file") === name;
        btn.classList.toggle("is-active", active);
        btn.setAttribute("aria-pressed", active ? "true" : "false");
      });
      docs.forEach(function (doc) {
        var active = doc.getAttribute("data-source-doc") === name;
        if (active) doc.removeAttribute("hidden");
        else doc.setAttribute("hidden", "");
      });
    }

    dock.addEventListener("click", function (ev) {
      if (ev.target.closest("[data-quiz-open]")) return;

      var toggle = ev.target.closest("[data-source-toggle]");
      if (toggle && dock.contains(toggle)) {
        setOpen(!dock.classList.contains("is-open"));
        return;
      }

      var fileBtn = ev.target.closest("[data-source-file]");
      if (fileBtn && dock.contains(fileBtn)) {
        var name = fileBtn.getAttribute("data-source-file");
        if (!name) return;
        showFile(name);
        setOpen(true);
      }
    });
  }

  function initQuizModal() {
    if (!document.body || !document.body.getAttribute("data-quiz-ready")) return;

    var modal = qs("[data-quiz-modal]");
    if (!modal) return;

    var titleEl = qs("[data-quiz-modal-title]", modal);
    var bodyEl = qs("[data-quiz-modal-body]", modal);
    var copyBtn = qs("[data-quiz-copy]", modal);
    var copyLabel = qs("[data-quiz-copy-label]", modal);
    var lastFocus = null;
    var promptText = "";

    function setOpen(open, title, prompt) {
      if (open) {
        lastFocus = document.activeElement;
        promptText = prompt || "";
        if (titleEl) titleEl.textContent = title || "Quiz prompt";
        if (bodyEl) bodyEl.textContent = promptText;
        modal.hidden = false;
        document.body.style.overflow = "hidden";
        if (copyBtn) copyBtn.focus();
      } else {
        modal.hidden = true;
        promptText = "";
        if (bodyEl) bodyEl.textContent = "";
        document.body.style.overflow = "";
        if (copyLabel) copyLabel.textContent = "Copy";
        if (copyBtn) copyBtn.classList.remove("is-copied");
        if (lastFocus && typeof lastFocus.focus === "function") {
          lastFocus.focus();
        }
        lastFocus = null;
      }
    }

    function copyPrompt() {
      if (!promptText) return;
      var done = function () {
        if (copyLabel) copyLabel.textContent = "Copied";
        if (copyBtn) copyBtn.classList.add("is-copied");
        setTimeout(function () {
          if (copyLabel) copyLabel.textContent = "Copy";
          if (copyBtn) copyBtn.classList.remove("is-copied");
        }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(promptText).then(done).catch(function () {
          fallbackCopy(promptText, done);
        });
      } else {
        fallbackCopy(promptText, done);
      }
    }

    function fallbackCopy(text, done) {
      var ta = document.createElement("textarea");
      ta.value = text;
      ta.setAttribute("readonly", "");
      ta.style.position = "fixed";
      ta.style.left = "-9999px";
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand("copy");
        done();
      } finally {
        document.body.removeChild(ta);
      }
    }

    document.addEventListener("click", function (ev) {
      var openBtn = ev.target.closest("[data-quiz-open]");
      if (openBtn) {
        ev.preventDefault();
        setOpen(
          true,
          openBtn.getAttribute("data-quiz-title") || "Quiz prompt",
          openBtn.getAttribute("data-quiz-prompt") || ""
        );
        return;
      }

      if (!modal.hidden && ev.target.closest("[data-quiz-close]")) {
        setOpen(false);
        return;
      }

      if (!modal.hidden && ev.target.closest("[data-quiz-copy]")) {
        copyPrompt();
      }
    });

    document.addEventListener("keydown", function (ev) {
      if (modal.hidden) return;
      if (ev.key === "Escape") {
        setOpen(false);
      }
    });
  }

  initSourceDock();
  initQuizModal();

  if (!document.body || !document.body.getAttribute("data-phase1-editable")) {
    return;
  }

  var saveTimer = null;
  var responseTimer = null;
  var saveStatus = null;

  function ensureStatus() {
    if (saveStatus) return saveStatus;
    saveStatus = document.createElement("div");
    saveStatus.className = "save-status";
    saveStatus.setAttribute("aria-live", "polite");
    document.body.appendChild(saveStatus);
    return saveStatus;
  }

  function flashStatus(text, isError) {
    var el = ensureStatus();
    el.textContent = text;
    el.classList.toggle("is-error", !!isError);
    el.classList.add("is-visible");
    clearTimeout(flashStatus._t);
    flashStatus._t = setTimeout(function () {
      el.classList.remove("is-visible");
    }, 1600);
  }

  function collectChecked() {
    var guides = {};
    var moc = {};
    var documents = {};

    qsa('[data-kind="guide"]').forEach(function (el) {
      guides[el.getAttribute("data-id")] = el.getAttribute("data-checked") === "1";
    });
    qsa('[data-kind="moc"]').forEach(function (el) {
      moc[el.getAttribute("data-id")] = el.getAttribute("data-checked") === "1";
    });
    qsa('[data-kind="document"]').forEach(function (docEl) {
      var name = docEl.getAttribute("data-id");
      var entry = { _doc: docEl.getAttribute("data-checked") === "1", keypoints: {} };
      qsa('[data-kind="keypoint"]', docEl).forEach(function (kp) {
        entry.keypoints[kp.getAttribute("data-id")] = kp.getAttribute("data-checked") === "1";
      });
      documents[name] = entry;
    });

    return { checked: { guides: guides, moc: moc, documents: documents } };
  }

  function collectResponses() {
    var out = { guides: {}, documents: {}, keypoints: {}, moc: {} };
    qsa("[data-response-input]").forEach(function (ta) {
      var kind = ta.getAttribute("data-response-kind");
      var id = ta.getAttribute("data-response-id");
      var text = (ta.value || "").trim();
      if (!kind || !id || !out[kind]) return;
      if (text) out[kind][id] = text;
    });
    return out;
  }

  function postJson(action, body) {
    return fetch("index.php?action=" + encodeURIComponent(action), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data || !data.ok) {
          throw new Error((data && data.error) || "Save failed");
        }
        return data;
      });
    });
  }

  function scheduleCheckedSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      postJson("save-checked", collectChecked())
        .then(function () {
          flashStatus("Checks saved");
        })
        .catch(function (err) {
          flashStatus(err.message || "Save failed", true);
        });
    }, 250);
  }

  function scheduleResponseSave() {
    clearTimeout(responseTimer);
    responseTimer = setTimeout(function () {
      postJson("save-responses", collectResponses())
        .then(function () {
          flashStatus("Responses saved");
        })
        .catch(function (err) {
          flashStatus(err.message || "Save failed", true);
        });
    }, 400);
  }

  document.addEventListener("click", function (ev) {
    var checkBtn = ev.target.closest("[data-check]");
    if (checkBtn) {
      var card = checkBtn.closest("[data-kind]");
      if (!card) return;
      var next = card.getAttribute("data-checked") !== "1";
      card.setAttribute("data-checked", next ? "1" : "0");
      card.classList.toggle("is-checked", next);
      checkBtn.setAttribute("aria-pressed", next ? "true" : "false");
      scheduleCheckedSave();
      return;
    }

    var toggle = ev.target.closest("[data-response-toggle]");
    if (toggle) {
      var wrap = toggle.closest("[data-response-wrap]");
      if (!wrap) return;
      var panel = qs("[data-response-panel]", wrap);
      if (!panel) return;
      var open = panel.hasAttribute("hidden");
      if (open) panel.removeAttribute("hidden");
      else panel.setAttribute("hidden", "");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    }
  });

  document.addEventListener("input", function (ev) {
    if (!ev.target || !ev.target.matches("[data-response-input]")) return;
    var wrap = ev.target.closest("[data-response-wrap]");
    if (wrap) {
      wrap.classList.toggle("has-response", (ev.target.value || "").trim() !== "");
    }
    scheduleResponseSave();
  });
})();
