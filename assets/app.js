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

    function markActive(name) {
      fileBtns.forEach(function (btn) {
        var active = btn.getAttribute("data-source-file") === name;
        btn.classList.toggle("is-active", active);
        btn.setAttribute("aria-pressed", active ? "true" : "false");
      });
    }

    dock.addEventListener("click", function (ev) {
      if (ev.target.closest("[data-quiz-open]")) return;

      var toggle = ev.target.closest("[data-source-toggle]");
      if (toggle && dock.contains(toggle)) {
        setOpen(!dock.classList.contains("is-open"));
        return;
      }
    });

    document.addEventListener("heavy-docs:doc-open", function (ev) {
      var name = ev.detail && ev.detail.name;
      if (name) markActive(name);
    });

    window.__heavyDocsSourceDock = { setOpen: setOpen, markActive: markActive };
  }

  function initDocViewer() {
    if (!document.body || !document.body.getAttribute("data-doc-viewer")) return;

    var modal = qs("[data-doc-modal]");
    var store = qs("[data-doc-store]");
    var taskbar = qs("[data-doc-taskbar]");
    var taskbarItems = qs("[data-doc-taskbar-items]");
    if (!modal || !store || !taskbar || !taskbarItems) return;

    var titleEl = qs("[data-doc-modal-title]", modal);
    var bodyEl = qs("[data-doc-modal-body]", modal);
    var downloadEl = qs("[data-doc-download]", modal);
    var lastFocus = null;

    /** Keep live panes (esp. PDF iframes) rendered off-screen — never display:none. */
    var liveRoot = document.createElement("div");
    liveRoot.className = "doc-live-root";
    liveRoot.setAttribute("data-doc-live", "");
    liveRoot.setAttribute("aria-hidden", "true");
    document.body.appendChild(liveRoot);

    /**
     * @type {Object.<string, {
     *   scrollTop: number,
     *   scrollLeft: number,
     *   embedScrollTop: number,
     *   embedScrollLeft: number,
     *   hasEmbedScroll: boolean,
     *   line: number|null,
     *   endLine: number|null,
     *   anchor: string|null,
     *   minimized: boolean,
     *   pane: HTMLElement|null
     * }>}
     */
    var sessions = {};
    var activeName = null;
    var restoreScrollPending = false;

    function contentNode(name) {
      return qs('[data-doc-content="' + cssEscape(name) + '"]', store);
    }

    function cssEscape(value) {
      if (window.CSS && typeof window.CSS.escape === "function") {
        return window.CSS.escape(value);
      }
      return String(value).replace(/["\\]/g, "\\$&");
    }

    function ensureSession(name) {
      if (!sessions[name]) {
        sessions[name] = {
          scrollTop: 0,
          scrollLeft: 0,
          embedScrollTop: 0,
          embedScrollLeft: 0,
          hasEmbedScroll: false,
          line: null,
          endLine: null,
          anchor: null,
          minimized: false,
          pane: null,
        };
      }
      return sessions[name];
    }

    function embedFrameIn(host) {
      if (!host) return null;
      return host.querySelector("iframe.doc-embed");
    }

    function readEmbedScroll(frame) {
      if (!frame) return null;
      try {
        var win = frame.contentWindow;
        if (!win) return null;
        var doc = win.document;
        var top =
          win.scrollY ||
          (doc && doc.documentElement && doc.documentElement.scrollTop) ||
          (doc && doc.body && doc.body.scrollTop) ||
          0;
        var left =
          win.scrollX ||
          (doc && doc.documentElement && doc.documentElement.scrollLeft) ||
          (doc && doc.body && doc.body.scrollLeft) ||
          0;
        return { top: top, left: left };
      } catch (err) {
        /* PDF plugin / opaque viewer — keep state via off-screen parking. */
        return null;
      }
    }

    function writeEmbedScroll(frame, top, left) {
      if (!frame) return;
      try {
        var win = frame.contentWindow;
        if (!win) return;
        if (typeof win.scrollTo === "function") {
          win.scrollTo(left || 0, top || 0);
          return;
        }
        var doc = win.document;
        if (doc && doc.documentElement) {
          doc.documentElement.scrollTop = top || 0;
          doc.documentElement.scrollLeft = left || 0;
        }
        if (doc && doc.body) {
          doc.body.scrollTop = top || 0;
          doc.body.scrollLeft = left || 0;
        }
      } catch (err) {
        /* ignore opaque viewers */
      }
    }

    function docKind(name) {
      var session = sessions[name];
      if (session && session.pane) {
        return session.pane.getAttribute("data-doc-kind") || "";
      }
      var node = contentNode(name);
      return node ? node.getAttribute("data-doc-kind") || "" : "";
    }

    function isEmbedName(name) {
      return docKind(name) === "embed";
    }

    function syncLiveRootSize() {
      if (!modal || !liveRoot || !bodyEl) return;
      var w = 0;
      var h = 0;
      if (!modal.hidden) {
        var bodyRect = bodyEl.getBoundingClientRect();
        w = Math.round(bodyRect.width);
        h = Math.round(bodyRect.height);
      }
      if (w < 1 || h < 1) {
        var dialog = qs(".doc-modal__dialog", modal);
        var header = qs(".doc-modal__header", modal);
        if (dialog) {
          var dialogRect = dialog.getBoundingClientRect();
          var headerH = header ? header.getBoundingClientRect().height : 0;
          w = Math.round(dialogRect.width);
          h = Math.round(dialogRect.height - headerH);
        }
      }
      if (w < 1) w = Math.min(832, window.innerWidth || 800);
      if (h < 1) h = Math.min(Math.round((window.innerHeight || 600) * 0.85 - 52), 720);
      liveRoot.style.width = w + "px";
      liveRoot.style.height = h + "px";
      liveRoot.style.maxHeight = h + "px";
      return { width: w, height: h };
    }

    function updateLiveRootStacking() {
      var pinned = liveRoot.querySelector(".doc-live-pane.is-pinned");
      liveRoot.classList.toggle("is-pinning", !!pinned);
    }

    function pinEmbedPane(name) {
      var pane = ensurePane(name);
      if (!pane || !bodyEl) return false;
      var size = syncLiveRootSize();
      var rect = bodyEl.getBoundingClientRect();
      var left = Math.round(rect.left);
      var top = Math.round(rect.top);
      var w = Math.max(1, Math.round(rect.width) || size.width);
      var h = Math.max(1, Math.round(rect.height) || size.height);
      pane.classList.add("is-pinned");
      pane.style.position = "fixed";
      pane.style.left = left + "px";
      pane.style.top = top + "px";
      pane.style.width = w + "px";
      pane.style.height = h + "px";
      bodyEl.classList.add("has-pinned-embed");
      bodyEl.setAttribute("data-embed-host", "");
      updateLiveRootStacking();
      return true;
    }

    function unpinEmbedPane(name) {
      var session = sessions[name];
      if (!session || !session.pane) return;
      var pane = session.pane;
      syncLiveRootSize();
      pane.classList.remove("is-pinned");
      pane.style.position = "";
      pane.style.left = "";
      pane.style.top = "";
      pane.style.width = "";
      pane.style.height = "";
      if (bodyEl) {
        bodyEl.classList.remove("has-pinned-embed");
        bodyEl.removeAttribute("data-embed-host");
      }
      updateLiveRootStacking();
    }

    function ensurePane(name) {
      var session = ensureSession(name);
      if (session.pane) return session.pane;
      var node = contentNode(name);
      if (!node) return null;
      var pane = document.createElement("div");
      pane.className = "doc-live-pane";
      pane.setAttribute("data-doc-pane", name);
      var kind = node.getAttribute("data-doc-kind") || "";
      var lazyUrl = node.getAttribute("data-doc-lazy-url") || "";
      if (kind) pane.setAttribute("data-doc-kind", kind);
      if (lazyUrl) pane.setAttribute("data-doc-lazy-url", lazyUrl);
      var clone = node.cloneNode(true);
      while (clone.firstChild) {
        pane.appendChild(clone.firstChild);
      }
      syncLiveRootSize();
      liveRoot.appendChild(pane);
      session.pane = pane;
      return pane;
    }

    function loadSpreadsheetIfNeeded(name) {
      var session = ensureSession(name);
      if (session.sheetLoaded || session.sheetLoading) return;
      var host = activeName === name ? bodyEl : session.pane;
      if (!host) return;
      var pending = qs("[data-sheet-pending]", host);
      var lazyUrl =
        (session.pane && session.pane.getAttribute("data-doc-lazy-url")) ||
        (contentNode(name) && contentNode(name).getAttribute("data-doc-lazy-url")) ||
        "";
      if (!pending || !lazyUrl) {
        session.sheetLoaded = !pending;
        return;
      }
      session.sheetLoading = true;
      pending.innerHTML = '<p class="muted">Loading spreadsheet preview…</p>';
      fetch(lazyUrl, { headers: { Accept: "application/json" } })
        .then(function (res) {
          return res.json().then(function (data) {
            if (!res.ok || !data || !data.ok) {
              throw new Error((data && data.error) || "Could not load spreadsheet");
            }
            return data.html || "";
          });
        })
        .then(function (html) {
          session.sheetLoading = false;
          session.sheetLoaded = true;
          var target = activeName === name ? bodyEl : session.pane;
          if (!target) return;
          var slot = qs("[data-sheet-pending]", target);
          if (slot) {
            slot.outerHTML = html;
          } else {
            target.innerHTML = html;
          }
        })
        .catch(function (err) {
          session.sheetLoading = false;
          var target = activeName === name ? bodyEl : session.pane;
          var slot = target ? qs("[data-sheet-pending]", target) : null;
          var message = (err && err.message) || "Could not load spreadsheet";
          if (slot) {
            slot.innerHTML =
              '<div class="doc-binary"><p class="muted">' +
              message.replace(/</g, "&lt;") +
              "</p></div>";
          }
        });
    }

    function parkName(name) {
      if (!name) return;
      if (isEmbedName(name)) {
        /* Keep the iframe in liveRoot — only unpin visually. */
        unpinEmbedPane(name);
        return;
      }
      if (!bodyEl) return;
      var session = ensureSession(name);
      var pane = session.pane || ensurePane(name);
      if (!pane) return;
      syncLiveRootSize();
      while (bodyEl.firstChild) {
        pane.appendChild(bodyEl.firstChild);
      }
    }

    function attachName(name) {
      if (!name || !bodyEl) return false;
      if (isEmbedName(name)) {
        /* Embeds stay in liveRoot; pin over the modal body after it is shown. */
        if (!ensurePane(name)) return false;
        while (bodyEl.firstChild) {
          bodyEl.removeChild(bodyEl.firstChild);
        }
        bodyEl.classList.add("has-pinned-embed");
        bodyEl.setAttribute("data-embed-host", "");
        return true;
      }
      var pane = ensurePane(name);
      if (!pane) return false;
      while (pane.firstChild) {
        bodyEl.appendChild(pane.firstChild);
      }
      return true;
    }

    function destroySession(name) {
      if (!name || !sessions[name]) return;
      var session = sessions[name];
      if (isEmbedName(name)) {
        unpinEmbedPane(name);
      }
      if (session.pane && session.pane.parentNode) {
        session.pane.parentNode.removeChild(session.pane);
      }
      delete sessions[name];
      updateLiveRootStacking();
    }

    function embedFrameFor(name) {
      if (isEmbedName(name)) {
        var session = sessions[name];
        return session && session.pane ? embedFrameIn(session.pane) : null;
      }
      return embedFrameIn(bodyEl);
    }

    function saveScroll() {
      if (!activeName || !bodyEl) return;
      var session = ensureSession(activeName);
      session.scrollTop = bodyEl.scrollTop;
      session.scrollLeft = bodyEl.scrollLeft;
      var embedPos = readEmbedScroll(embedFrameFor(activeName));
      if (embedPos) {
        session.embedScrollTop = embedPos.top;
        session.embedScrollLeft = embedPos.left;
        session.hasEmbedScroll = true;
      }
    }

    function restoreScroll(session) {
      if (!bodyEl || !session) return;
      bodyEl.scrollTop = session.scrollTop || 0;
      bodyEl.scrollLeft = session.scrollLeft || 0;
      if (session.hasEmbedScroll) {
        writeEmbedScroll(
          embedFrameFor(activeName),
          session.embedScrollTop || 0,
          session.embedScrollLeft || 0
        );
      }
    }

    function clearLineHighlights() {
      qsa(".source-line.is-target", bodyEl).forEach(function (el) {
        el.classList.remove("is-target");
      });
    }

    function applyLineJump(line, endLine) {
      if (!line || !bodyEl) return false;
      clearLineHighlights();
      var start = line;
      var end = endLine && endLine >= line ? endLine : line;
      var first = qs('[data-line="' + start + '"]', bodyEl);
      if (!first) return false;
      for (var n = start; n <= end; n++) {
        var el = qs('[data-line="' + n + '"]', bodyEl);
        if (el) el.classList.add("is-target");
      }
      first.scrollIntoView({ block: "center" });
      return true;
    }

    function applyAnchorJump(anchor) {
      if (!anchor || !bodyEl) return false;
      clearLineHighlights();
      var needle = String(anchor).trim().toLowerCase();
      if (!needle) return false;
      var lines = qsa(".source-line", bodyEl);
      var match = null;
      for (var i = 0; i < lines.length; i++) {
        var textEl = qs(".source-line__t", lines[i]);
        var text = ((textEl && textEl.textContent) || "").trim().toLowerCase();
        if (
          text === needle ||
          text === "## " + needle ||
          text === "# " + needle ||
          text.indexOf(needle) !== -1
        ) {
          match = lines[i];
          break;
        }
      }
      if (!match) return false;
      match.classList.add("is-target");
      match.scrollIntoView({ block: "center" });
      return true;
    }

    function renderTaskbar() {
      var names = Object.keys(sessions).filter(function (name) {
        return sessions[name].minimized;
      });
      taskbarItems.innerHTML = "";
      if (names.length === 0) {
        taskbar.hidden = true;
        document.body.classList.remove("has-doc-taskbar");
        return;
      }
      taskbar.hidden = false;
      document.body.classList.add("has-doc-taskbar");
      names.forEach(function (name) {
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "doc-taskbar__item";
        btn.setAttribute("data-doc-restore", name);
        btn.title = "Restore " + name;
        btn.textContent = name;
        taskbarItems.appendChild(btn);
      });
    }

    function setChrome(name) {
      var node = contentNode(name);
      if (titleEl) titleEl.textContent = name || "Document";
      var url = node ? node.getAttribute("data-doc-url") || "" : "";
      if (downloadEl) {
        if (url) {
          downloadEl.href = url;
          downloadEl.setAttribute("download", name);
          downloadEl.hidden = false;
        } else {
          downloadEl.hidden = true;
          downloadEl.removeAttribute("href");
          downloadEl.removeAttribute("download");
        }
      }
    }

    function setModalOpen(open) {
      if (open) {
        modal.hidden = false;
        document.body.classList.add("has-doc-modal");
        document.body.style.overflow = "hidden";
      } else {
        modal.hidden = true;
        document.body.classList.remove("has-doc-modal");
        var quiz = qs("[data-quiz-modal]");
        if (!quiz || quiz.hidden) {
          document.body.style.overflow = "";
        }
      }
    }

    function showDoc(name, opts) {
      opts = opts || {};
      if (!contentNode(name) || !bodyEl || !titleEl) return false;

      var session = ensureSession(name);
      var jumpLine = opts.line != null;
      var jumpAnchor = !jumpLine && opts.anchor != null && String(opts.anchor).trim() !== "";
      if (jumpLine) {
        session.line = opts.line;
        session.endLine = opts.endLine != null ? opts.endLine : opts.line;
        session.anchor = null;
      } else if (jumpAnchor) {
        session.anchor = String(opts.anchor).trim();
        session.line = null;
        session.endLine = null;
      }

      var alreadyActive = activeName === name && !modal.hidden;
      if (alreadyActive) {
        if (jumpLine || jumpAnchor) {
          restoreScrollPending = true;
          requestAnimationFrame(function () {
            if (jumpLine) applyLineJump(session.line, session.endLine);
            else applyAnchorJump(session.anchor);
            restoreScrollPending = false;
          });
        }
        return true;
      }

      if (activeName && activeName !== name) {
        saveScroll();
        parkName(activeName);
        if (sessions[activeName]) {
          sessions[activeName].minimized = true;
        }
      } else if (activeName === name && modal.hidden) {
        // Restoring the parked active pane — content is in the live pane.
      } else if (activeName === name) {
        parkName(activeName);
      }

      if (!attachName(name)) return false;

      activeName = name;
      session.minimized = false;
      setChrome(name);
      setModalOpen(true);
      renderTaskbar();
      if (
        (session.pane && session.pane.getAttribute("data-doc-kind") === "spreadsheet") ||
        (contentNode(name) && contentNode(name).getAttribute("data-doc-kind") === "spreadsheet")
      ) {
        loadSpreadsheetIfNeeded(name);
      }
      document.dispatchEvent(
        new CustomEvent("heavy-docs:doc-open", { detail: { name: name } })
      );

      restoreScrollPending = true;
      requestAnimationFrame(function () {
        if (!bodyEl) return;
        if (isEmbedName(name)) {
          pinEmbedPane(name);
        }
        if (jumpLine) {
          applyLineJump(session.line, session.endLine);
        } else if (jumpAnchor) {
          if (!applyAnchorJump(session.anchor)) {
            restoreScroll(session);
          }
        } else {
          restoreScroll(session);
        }
        /* Opaque PDF viewers keep scroll via pin-in-place; HTML embeds re-apply. */
        if (!jumpLine && session.hasEmbedScroll) {
          requestAnimationFrame(function () {
            if (isEmbedName(name)) pinEmbedPane(name);
            restoreScroll(session);
            restoreScrollPending = false;
          });
        } else {
          restoreScrollPending = false;
        }
        var focusBtn = qs("[data-doc-minimize]", modal);
        if (focusBtn) focusBtn.focus();
      });
      return true;
    }

    function openDoc(name, opts) {
      if (!name) return;
      if (!contentNode(name)) return;
      if (!lastFocus) lastFocus = document.activeElement;
      showDoc(name, opts || {});
      if (window.__heavyDocsSourceDock && typeof window.__heavyDocsSourceDock.setOpen === "function") {
        window.__heavyDocsSourceDock.setOpen(false);
      }
    }

    function minimizeActive() {
      if (!activeName) {
        setModalOpen(false);
        return;
      }
      saveScroll();
      syncLiveRootSize();
      parkName(activeName);
      ensureSession(activeName).minimized = true;
      activeName = null;
      setModalOpen(false);
      renderTaskbar();
      if (lastFocus && typeof lastFocus.focus === "function") {
        lastFocus.focus();
      }
      lastFocus = null;
    }

    function closeActive() {
      if (activeName) {
        var closing = activeName;
        activeName = null;
        if (bodyEl) {
          bodyEl.innerHTML = "";
          bodyEl.classList.remove("has-pinned-embed");
          bodyEl.removeAttribute("data-embed-host");
        }
        destroySession(closing);
      }
      setModalOpen(false);
      if (downloadEl) {
        downloadEl.hidden = true;
        downloadEl.removeAttribute("href");
        downloadEl.removeAttribute("download");
      }
      renderTaskbar();
      if (lastFocus && typeof lastFocus.focus === "function") {
        lastFocus.focus();
      }
      lastFocus = null;
    }

    function restoreDoc(name) {
      if (!name || !sessions[name]) return;
      openDoc(name, {});
    }

    bodyEl.addEventListener("scroll", function () {
      if (restoreScrollPending) return;
      saveScroll();
    });

    window.addEventListener("resize", function () {
      if (!activeName || modal.hidden || !isEmbedName(activeName)) return;
      pinEmbedPane(activeName);
    });

    document.addEventListener("click", function (ev) {
      var openBtn = ev.target.closest("[data-open-doc]");
      if (openBtn) {
        ev.preventDefault();
        var name = openBtn.getAttribute("data-open-doc");
        var lineAttr = openBtn.getAttribute("data-open-line");
        var endAttr = openBtn.getAttribute("data-open-end-line");
        var anchorAttr = openBtn.getAttribute("data-open-anchor");
        var line = lineAttr ? parseInt(lineAttr, 10) : null;
        var endLine = endAttr ? parseInt(endAttr, 10) : null;
        openDoc(name, {
          line: line && !isNaN(line) ? line : null,
          endLine: endLine && !isNaN(endLine) ? endLine : null,
          anchor: anchorAttr || null,
        });
        return;
      }

      var restoreBtn = ev.target.closest("[data-doc-restore]");
      if (restoreBtn) {
        ev.preventDefault();
        restoreDoc(restoreBtn.getAttribute("data-doc-restore"));
        return;
      }

      if (!modal.hidden && ev.target.closest("[data-doc-minimize]")) {
        ev.preventDefault();
        minimizeActive();
        return;
      }

      if (!modal.hidden && ev.target.closest("[data-doc-close]")) {
        ev.preventDefault();
        closeActive();
      }

      var sheetTab = ev.target.closest("[data-sheet-tab]");
      if (sheetTab) {
        var preview = sheetTab.closest("[data-sheet-preview]");
        if (!preview) return;
        ev.preventDefault();
        var idx = sheetTab.getAttribute("data-sheet-tab");
        qsa("[data-sheet-tab]", preview).forEach(function (tab) {
          var on = tab.getAttribute("data-sheet-tab") === idx;
          tab.classList.toggle("is-active", on);
          tab.setAttribute("aria-selected", on ? "true" : "false");
        });
        qsa("[data-sheet-panel]", preview).forEach(function (panel) {
          if (panel.getAttribute("data-sheet-panel") === idx) {
            panel.removeAttribute("hidden");
          } else {
            panel.setAttribute("hidden", "");
          }
        });
      }
    });

    document.addEventListener("keydown", function (ev) {
      if (modal.hidden) return;
      var quiz = qs("[data-quiz-modal]");
      if (quiz && !quiz.hidden) return;
      if (ev.key === "Escape") {
        ev.preventDefault();
        minimizeActive();
      }
    });

    // Deep-link: ?file=name opens that document once.
    try {
      var params = new URLSearchParams(window.location.search);
      var initial = params.get("file");
      if (initial && contentNode(initial)) {
        openDoc(initial, {});
      }
    } catch (err) {
      /* ignore */
    }
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
  initDocViewer();
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
