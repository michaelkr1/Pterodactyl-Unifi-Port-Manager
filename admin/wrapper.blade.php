{{-- Injected into every admin page (admin.wrapper). Deliberately does
     nothing unless the current URL matches the create-server page or a
     server's Build Configuration page, so it's a no-op everywhere else. --}}
<script>
(function () {
  var STORAGE_KEY = 'unifisync_pending_auto_configure';
  var EXT_ROOT = '/admin/extensions/{identifier}';

  function onCreateServerPage() {
    return /\/admin\/servers\/new\/?$/.test(window.location.pathname);
  }

  // Matches every tab under a server's admin page -- used only to catch the
  // post-creation redirect and report a pending choice, wherever it lands.
  function serverIdFromAnyViewPage() {
    var match = window.location.pathname.match(/\/admin\/servers\/view\/(\d+)/);
    return match ? match[1] : null;
  }

  // The visible toggle for an *existing* server only shows on Build
  // Configuration, where its allocations already live.
  function serverIdFromBuildPage() {
    var match = window.location.pathname.match(/\/admin\/servers\/view\/(\d+)\/build\/?$/);
    return match ? match[1] : null;
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) { return meta.getAttribute('content'); }
    var input = document.querySelector('input[name="_token"]');
    return input ? input.value : null;
  }

  /**
   * Finds a section's .box-body by the exact text of its .box-title,
   * trying each candidate heading in order (different pages label the
   * allocation section differently). Returns null if none match -- callers
   * fall back to a standalone box rather than failing silently.
   */
  function findSectionBody(candidates) {
    var titles = document.querySelectorAll('.box-title');
    for (var i = 0; i < titles.length; i++) {
      var text = titles[i].textContent.trim();
      for (var j = 0; j < candidates.length; j++) {
        if (text === candidates[j]) {
          var box = titles[i].closest('.box');
          if (box) {
            var body = box.querySelector('.box-body');
            if (body) { return body; }
          }
        }
      }
    }
    return null;
  }

  function buildFieldGroup(idSuffix, initialValue) {
    var wrap = document.createElement('div');
    wrap.innerHTML =
      '<div class="form-group">' +
      '<label for="unifisync_auto_configure_' + idSuffix + '">Auto-configure UniFi ports</label>' +
      '<select id="unifisync_auto_configure_' + idSuffix + '" class="form-control">' +
      '<option value="1"' + (initialValue ? ' selected' : '') + '>Yes</option>' +
      '<option value="0"' + (initialValue ? '' : ' selected') + '>No</option>' +
      '</select>' +
      '<p class="text-muted small" id="unifisync_auto_configure_' + idSuffix + '_hint">Automatically create a matching UniFi port-forward + firewall rule for this server\'s allocation(s).</p>' +
      '</div>';
    return wrap.firstChild;
  }

  /**
   * Appends `field` as a new column sharing the section's actual row
   * (Bootstrap 3 floats), rather than creating a nested sibling row -- a
   * new row doesn't share the same grid/margin context, so its left edge
   * doesn't reliably line up with the row above it. Pterodactyl puts the
   * `row` class directly on `.box-body` itself (fields are its direct
   * children, e.g. `<div class="box-body row">`), not on a separate div
   * nested inside it, so that has to be checked for explicitly rather than
   * assumed to be a descendant.
   */
  function appendFieldToBody(body, field, colClass) {
    var col = document.createElement('div');
    col.className = colClass || 'col-sm-12';
    col.style.marginTop = '15px';
    col.appendChild(field);

    if (body.classList.contains('row')) {
      body.appendChild(col);
      return;
    }

    var existingRow = body.querySelector('.row');
    if (existingRow) {
      existingRow.appendChild(col);
    } else {
      var row = document.createElement('div');
      row.className = 'row';
      row.appendChild(col);
      body.appendChild(row);
    }
  }

  // The rest of this page's dropdowns are Select2-enhanced (dark themed,
  // styled option list) -- a plain <select> looks visibly out of place next
  // to them. Pterodactyl already loads jQuery + select2.full.min.js on
  // every admin page, so just apply it to match.
  function enhanceSelect(id) {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
      window.jQuery('#' + id).select2({ width: '100%', minimumResultsForSearch: -1 });
    }
  }

  function prependStandaloneBox(field) {
    var container = document.querySelector('section.content') || document.body;
    var box = document.createElement('div');
    box.className = 'box box-primary';
    box.innerHTML = '<div class="box-header with-border"><h3 class="box-title">UniFi Port Sync</h3></div><div class="box-body"></div>';
    box.querySelector('.box-body').appendChild(field);
    container.insertBefore(box, container.firstChild);
  }

  function rememberChoiceOnSubmit() {
    document.addEventListener('submit', function () {
      var select = document.getElementById('unifisync_auto_configure_new');
      if (!select) { return; }
      try { sessionStorage.setItem(STORAGE_KEY, select.value); } catch (e) { /* ignore */ }
    }, true);
  }

  function postAutoConfigure(serverId, value) {
    var token = csrfToken();
    var body = 'action=set_auto_configure&server_id=' + encodeURIComponent(serverId) +
      '&auto_configure=' + encodeURIComponent(value) +
      (token ? '&_token=' + encodeURIComponent(token) : '');

    // fetch() only rejects on network failure -- an HTTP error status (e.g.
    // a 419 CSRF mismatch) still resolves normally, so it has to be checked
    // explicitly or a failed save silently looks like a successful one.
    return fetch(EXT_ROOT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: body,
    }).then(function (res) {
      if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      }
      return res;
    });
  }

  function setupCreateServerPage() {
    if (document.getElementById('unifisync_auto_configure_new')) { return; }

    var field = buildFieldGroup('new', true);
    var body = findSectionBody(['Allocation Management']);

    if (body) {
      appendFieldToBody(body, field, 'col-sm-4');
    } else {
      prependStandaloneBox(field);
    }

    enhanceSelect('unifisync_auto_configure_new');
    rememberChoiceOnSubmit();
  }

  // Reports a pending create-page choice against the real server ID, on
  // whichever tab the post-creation redirect happens to land on. Silent --
  // no UI here, just applying what was already chosen.
  function reportPendingChoice(serverId) {
    var pending;
    try { pending = sessionStorage.getItem(STORAGE_KEY); } catch (e) { return; }
    if (pending === null) { return; }

    try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
    postAutoConfigure(serverId, pending);
  }

  function setupBuildPage(serverId) {
    if (document.getElementById('unifisync_auto_configure_existing')) { return; }

    fetch(EXT_ROOT + '?action=get_auto_configure&server_id=' + encodeURIComponent(serverId), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (res) { return res.json(); })
      .then(function (data) { renderBuildToggle(serverId, !!data.auto_configure); })
      .catch(function () { renderBuildToggle(serverId, true); });
  }

  function renderBuildToggle(serverId, currentValue) {
    if (document.getElementById('unifisync_auto_configure_existing')) { return; }

    var field = buildFieldGroup('existing', currentValue);
    var body = findSectionBody(['Allocation Management', 'Network']);

    if (body) {
      appendFieldToBody(body, field);
    } else {
      prependStandaloneBox(field);
    }

    enhanceSelect('unifisync_auto_configure_existing');

    var select = document.getElementById('unifisync_auto_configure_existing');
    var hint = document.getElementById('unifisync_auto_configure_existing_hint');
    var baseHint = hint.textContent;

    function currentSelectValue() {
      // Reading through jQuery/Select2's own accessor keeps this in sync
      // with Select2's internal state rather than racing its native-select
      // sync back after a UI-driven selection.
      return (window.jQuery && window.jQuery.fn.select2)
        ? window.jQuery(select).val()
        : select.value;
    }

    // Every other field on this page waits for "Update Build Configuration"
    // rather than saving itself on change -- match that instead of saving
    // immediately, which was also very likely fighting whatever that button
    // does to refresh the allocation section (explaining the "reverts back
    // to Yes" symptom: this field's own AJAX save was landing, then getting
    // stomped by Pterodactyl's own refresh of that box).
    function markPending() {
      hint.textContent = 'Will apply once you click "Update Build Configuration".';
    }

    if (window.jQuery) {
      window.jQuery(select).on('change', markPending);
    } else {
      select.addEventListener('change', markPending);
    }

    document.addEventListener('submit', function () {
      var value = currentSelectValue();
      hint.textContent = 'Saving...';
      postAutoConfigure(serverId, value)
        .then(function () {
          hint.textContent = value === '1'
            ? 'Saved -- creating rules in UniFi now.'
            : 'Saved -- removing any existing rules from UniFi now.';
          setTimeout(function () { hint.textContent = baseHint; }, 4000);
        })
        .catch(function (err) {
          hint.textContent = 'Could not save (' + err.message + ') -- try again.';
        });
    }, true);
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (onCreateServerPage()) {
      setupCreateServerPage();
      return;
    }

    var anyViewId = serverIdFromAnyViewPage();
    if (anyViewId) {
      reportPendingChoice(anyViewId);
    }

    var buildId = serverIdFromBuildPage();
    if (buildId) {
      setupBuildPage(buildId);
    }
  });
})();
</script>

{{-- Adds this extension's own link to the admin sidebar's "Service Management"
     section. Modeled directly on another installed extension's own sidebar
     script found in this panel's page source (id="bp-instances-sidebar"),
     which inserts after the Mounts link the same way -- reusing a pattern
     already confirmed to work in this exact theme rather than guessing. --}}
<script>
(function () {
  if (document.getElementById('unifisync-sidebar-link')) { return; }

  function inject() {
    var menu = document.querySelector('.sidebar-menu');
    if (!menu) { return; }

    var anchor = Array.prototype.find.call(
      menu.querySelectorAll('a'),
      function (a) { return /\/admin\/nests\/?$/.test(a.getAttribute('href') || ''); }
    );

    var li = document.createElement('li');
    li.id = 'unifisync-sidebar-link';

    if (window.location.pathname.indexOf('/admin/extensions/{identifier}') === 0) {
      li.className = 'active';
    }

    li.innerHTML =
      '<a href="/admin/extensions/{identifier}">' +
        '<i class="fa fa-wifi"></i> <span>UniFi Port Sync</span>' +
      '</a>';

    if (anchor && anchor.parentElement) {
      anchor.parentElement.insertAdjacentElement('afterend', li);
    } else {
      menu.appendChild(li);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }
})();
</script>
