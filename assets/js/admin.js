/* =====================================================================
   Admin panel behaviour. Loaded after main.js, which supplies ynPost,
   ynToast, modals and the overflow checker.
   ===================================================================== */
(function () {
  'use strict';

  const $  = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  /* --------------------------------------------------------- side nav -- */
  const navBtn = $('[data-admin-nav]');
  if (navBtn) {
    navBtn.addEventListener('click', () => {
      const nav = $('#admin-nav');
      const open = nav.classList.toggle('open');
      navBtn.setAttribute('aria-expanded', String(open));
      document.body.style.overflow = open ? 'hidden' : '';
    });
  }

  /* ------------------------------------------------------ bulk select -- */
  const bulkBar = $('[data-bulk-bar]');
  if (bulkBar) {
    const boxes  = () => $$('[data-bulk-item]');
    const count  = $('[data-bulk-count]');
    const master = $('[data-bulk-all]');

    const sync = () => {
      const on = boxes().filter(b => b.checked);
      bulkBar.classList.toggle('show', on.length > 0);
      if (count) count.textContent = String(on.length);
      if (master) {
        master.checked = on.length > 0 && on.length === boxes().length;
        master.indeterminate = on.length > 0 && on.length < boxes().length;
      }
    };

    document.addEventListener('change', e => {
      if (e.target.matches('[data-bulk-all]')) {
        boxes().forEach(b => { b.checked = e.target.checked; });
        sync();
      } else if (e.target.matches('[data-bulk-item]')) {
        sync();
      }
    });
    sync();
  }

  /* ------------------------------------------- inline stock/price edit -- */
  /* Save on blur, and show it landed. Nothing worse than a grid where you
     cannot tell whether your edit stuck. */
  document.addEventListener('focusout', async function (e) {
    const cell = e.target.closest('[data-cell-edit]');
    if (!cell) return;

    const original = cell.dataset.original ?? '';
    const value    = cell.value.trim();
    if (value === original) return;

    const res = await window.ynPost('/admin/inline-save.php', {
      entity: cell.dataset.entity,
      id:     cell.dataset.id,
      field:  cell.dataset.field,
      value:  value
    });

    if (res.ok) {
      cell.dataset.original = res.value !== undefined ? String(res.value) : value;
      if (res.value !== undefined) cell.value = res.value;
      cell.classList.add('saved');
      setTimeout(() => cell.classList.remove('saved'), 1200);
    } else {
      cell.value = original;
      window.ynToast(res.error || 'Could not save that change', 'error');
    }
  });

  // Enter should commit, not submit whatever form the cell happens to be in.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.closest('[data-cell-edit]')) {
      e.preventDefault();
      e.target.blur();
    }
  });

  /* ------------------------------------------------- shade (variant) rows -- */
  const shadeWrap = $('[data-shade-rows]');
  if (shadeWrap) {
    const tpl = $('#shade-template');
    let seq = Number(shadeWrap.dataset.nextIndex || 0);

    const addRow = () => {
      if (!tpl) return;
      const html = tpl.innerHTML.replace(/__IDX__/g, 'new' + (seq++));
      const div = document.createElement('div');
      div.innerHTML = html.trim();
      const row = div.firstElementChild;
      shadeWrap.appendChild(row);
      const first = row.querySelector('input[type="text"]');
      if (first) first.focus();
    };

    const addBtn = $('[data-add-shade]');
    if (addBtn) addBtn.addEventListener('click', addRow);

    // Removing an existing shade only marks it; the server decides whether it
    // can be deleted or must just be deactivated (it may be on past orders).
    shadeWrap.addEventListener('click', function (e) {
      const del = e.target.closest('[data-remove-shade]');
      if (!del) return;
      const row = del.closest('.shade-row');
      const flag = row.querySelector('[data-remove-flag]');
      if (flag) {
        const now = flag.value !== '1';
        flag.value = now ? '1' : '0';
        row.classList.toggle('is-removed', now);
        del.setAttribute('aria-label', now ? 'Undo remove' : 'Remove shade');
      } else {
        row.remove();
      }
    });

    // Typing a shade name with no SKU yet? Derive one so the field is never blank.
    shadeWrap.addEventListener('input', function (e) {
      if (!e.target.matches('[data-shade-name]')) return;
      const row = e.target.closest('.shade-row');
      const sku = row.querySelector('[data-shade-sku]');
      if (!sku || sku.dataset.touched === '1' || sku.value.trim() !== '') return;
      const base = shadeWrap.dataset.skuBase || 'SKU';
      sku.value = base + '-' + e.target.value.trim().toUpperCase()
        .replace(/[^A-Z0-9]+/g, '').slice(0, 8);
    });
    shadeWrap.addEventListener('change', function (e) {
      if (e.target.matches('[data-shade-sku]')) e.target.dataset.touched = '1';
    });
  }

  /* --------------------------------------------------- slug from name -- */
  const nameField = $('[data-slug-source]');
  const slugField = $('[data-slug-target]');
  if (nameField && slugField) {
    nameField.addEventListener('input', () => {
      if (slugField.dataset.touched === '1' || slugField.value.trim() !== '') return;
      slugField.value = nameField.value.toLowerCase().trim()
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    });
    slugField.addEventListener('change', () => { slugField.dataset.touched = '1'; });
  }

  /* --------------------------------------------- confirm before submit -- */
  document.addEventListener('submit', function (e) {
    const msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  });

  /* ------------------------------------------------------ image upload -- */
  const dropZone = $('[data-image-drop]');
  if (dropZone) {
    const input = $('[data-image-input]');
    dropZone.addEventListener('click', () => input && input.click());
    ['dragover', 'dragenter'].forEach(ev =>
      dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(ev =>
      dropZone.addEventListener(ev, () => dropZone.classList.remove('is-over')));
    dropZone.addEventListener('drop', e => {
      e.preventDefault();
      if (input && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  }
  /* ------------------------------------------------------------ help tips --
     Keep a tooltip inside the viewport. A centred bubble near the right edge
     would extend the page and break the zero-horizontal-overflow rule, so
     measure on first reveal and pin it to whichever side has room.

     Measured lazily rather than on load: the bubble has no size until it is
     shown, and there can be dozens on a page.                              */
  function placeTip(tip) {
    var bubble = tip.querySelector(".tip__bubble");
    if (!bubble || bubble.dataset.placed) return;

    bubble.classList.remove("is-left", "is-right");
    var r = bubble.getBoundingClientRect();
    var vw = document.documentElement.clientWidth;

    if (r.right > vw - 8)      bubble.classList.add("is-right");
    else if (r.left < 8)       bubble.classList.add("is-left");

    bubble.dataset.placed = "1";
  }

  document.addEventListener("mouseover", function (e) {
    var tip = e.target.closest && e.target.closest(".tip");
    if (tip) placeTip(tip);
  });
  document.addEventListener("focusin", function (e) {
    var tip = e.target.closest && e.target.closest(".tip");
    if (tip) placeTip(tip);
  });
  // Re-measure after a resize; the side with room may have changed.
  window.addEventListener("resize", function () {
    document.querySelectorAll(".tip__bubble[data-placed]").forEach(function (b) {
      delete b.dataset.placed;
    });
  });
  /* --------------------------------------------------- shade photo preview --
     Show the chosen picture immediately. Uploading blind and only seeing
     the result after a save is how the wrong file ends up on a shade. */
  document.addEventListener("change", function (e) {
    var input = e.target;
    if (!input.matches || !input.matches("[data-shade-photo]")) return;
    var file = input.files && input.files[0];
    if (!file) return;
    var wrap = input.closest(".shade-photo");
    var url  = URL.createObjectURL(file);
    var img  = wrap.querySelector("img");
    if (!img) {
      var ph = wrap.querySelector(".shade-photo__empty");
      if (ph) ph.remove();
      img = document.createElement("img");
      img.width = 38; img.height = 38; img.alt = "";
      wrap.insertBefore(img, input);
    }
    // Free the previous blob before replacing it.
    if (img.dataset.blob) URL.revokeObjectURL(img.src);
    img.src = url; img.dataset.blob = "1";
  });
})();