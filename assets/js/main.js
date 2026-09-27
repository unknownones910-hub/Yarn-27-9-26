/* =====================================================================
   The Yarn Nest — front-end behaviour
   Vanilla JS, no dependencies, no build step. Loaded with `defer`.

   Everything here is progressive enhancement: with JS disabled the site
   still browses, filters (form submits), and checks out.
   ===================================================================== */
(function () {
  'use strict';

  const $  = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  /* ---------------------------------------------------------------- CSRF -- */
  const CSRF = () => {
    const m = $('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  };

  /** POST JSON with the CSRF header attached. Used by cart, wishlist, etc. */
  window.ynPost = async function (url, data) {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF(),
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(data || {})
    });
    let json = null;
    try { json = await res.json(); } catch (e) { /* non-JSON error page */ }
    if (!json) {
      return { ok: false, error: 'Something went wrong. Please try again.' };
    }
    return json;
  };

  /* --------------------------------------------------------------- toast -- */
  let toastWrap = null;

  window.ynToast = function (message, type, ms) {
    if (!toastWrap) {
      toastWrap = document.createElement('div');
      toastWrap.className = 'toast-wrap';
      toastWrap.setAttribute('role', 'status');
      toastWrap.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastWrap);
    }

    const el = document.createElement('div');
    el.className = 'toast' + (type ? ' toast-' + type : '');
    el.innerHTML =
      '<span></span><button type="button" aria-label="Dismiss">' + iconX() + '</button>';
    el.querySelector('span').textContent = message;

    const kill = () => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(8px)';
      setTimeout(() => el.remove(), 220);
    };
    el.querySelector('button').addEventListener('click', kill);
    toastWrap.appendChild(el);
    setTimeout(kill, ms || 4000);
  };

  function iconX() {
    return '<svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15"/></svg>';
  }

  /* ------------------------------------------------- scroll lock + focus -- */
  let scrollY = 0;
  let lockCount = 0;

  function lockScroll() {
    if (lockCount++ > 0) return;
    scrollY = window.scrollY;
    document.body.classList.add('no-scroll');
    document.body.style.top = -scrollY + 'px';
    document.body.style.position = 'fixed';
    document.body.style.width = '100%';
  }

  function unlockScroll() {
    if (--lockCount > 0) return;
    lockCount = 0;
    document.body.classList.remove('no-scroll');
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.width = '';
    window.scrollTo(0, scrollY);
  }

  const FOCUSABLE =
    'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  function trapFocus(container, e) {
    const items = $$(FOCUSABLE, container).filter(el => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  /* -------------------------------------------------------------- drawer -- */
  /* Markup contract:
       <button data-drawer-open="#nav-drawer">
       <aside id="nav-drawer" class="drawer"> … <button data-drawer-close>
       <div class="scrim" data-scrim></div>                                  */

  let openDrawer = null;
  let lastFocused = null;

  function drawerOpen(el) {
    if (!el) return;
    lastFocused = document.activeElement;
    openDrawer = el;
    el.classList.add('open');
    el.setAttribute('aria-hidden', 'false');
    scrim(true);
    lockScroll();
    // The .open class flips visibility synchronously (see the `visibility 0s`
    // transition in style.css), but adding a class only marks styles dirty —
    // it does not recalculate them. Without forcing a reflow first, focus()
    // still sees the element as hidden and silently does nothing.
    void el.offsetWidth;

    const target = $('[data-autofocus]', el) || $(FOCUSABLE, el);
    if (target) {
      target.focus();
      // Safety net for slow devices; never rely on rAF alone, it is paused
      // in background tabs.
      if (document.activeElement !== target) {
        requestAnimationFrame(() => target.focus());
      }
    }
  }

  function drawerClose() {
    if (!openDrawer) return;
    openDrawer.classList.remove('open');
    openDrawer.setAttribute('aria-hidden', 'true');
    openDrawer = null;
    scrim(false);
    unlockScroll();
    if (lastFocused) lastFocused.focus();
  }

  function scrim(on) {
    let s = $('[data-scrim]');
    if (!s) {
      s = document.createElement('div');
      s.className = 'scrim';
      s.setAttribute('data-scrim', '');
      document.body.appendChild(s);
      s.addEventListener('click', () => { drawerClose(); modalClose(); });
    }
    s.classList.toggle('open', !!on);
  }

  /* --------------------------------------------------------------- modal -- */
  let openModal = null;

  function modalOpen(el) {
    if (!el) return;
    lastFocused = document.activeElement;
    openModal = el;
    el.classList.add('open');
    el.setAttribute('aria-hidden', 'false');
    scrim(true);
    lockScroll();
    // The .open class flips visibility synchronously (see the `visibility 0s`
    // transition in style.css), but adding a class only marks styles dirty —
    // it does not recalculate them. Without forcing a reflow first, focus()
    // still sees the element as hidden and silently does nothing.
    void el.offsetWidth;

    const target = $('[data-autofocus]', el) || $(FOCUSABLE, el);
    if (target) {
      target.focus();
      // Safety net for slow devices; never rely on rAF alone, it is paused
      // in background tabs.
      if (document.activeElement !== target) {
        requestAnimationFrame(() => target.focus());
      }
    }
  }

  function modalClose() {
    if (!openModal) return;
    openModal.classList.remove('open');
    openModal.setAttribute('aria-hidden', 'true');
    openModal = null;
    scrim(false);
    unlockScroll();
    if (lastFocused) lastFocused.focus();
  }

  window.ynModal = { open: modalOpen, close: modalClose };
  window.ynDrawer = { open: drawerOpen, close: drawerClose };

  /* --------------------------------------------------- delegated actions -- */
  document.addEventListener('click', function (e) {
    const openBtn = e.target.closest('[data-drawer-open]');
    if (openBtn) {
      e.preventDefault();
      drawerOpen($(openBtn.getAttribute('data-drawer-open')));
      return;
    }

    if (e.target.closest('[data-drawer-close]')) {
      e.preventDefault();
      drawerClose();
      return;
    }

    const modalBtn = e.target.closest('[data-modal-open]');
    if (modalBtn) {
      e.preventDefault();
      modalOpen($(modalBtn.getAttribute('data-modal-open')));
      return;
    }

    if (e.target.closest('[data-modal-close]')) {
      e.preventDefault();
      modalClose();
      return;
    }

    // Announcement bar dismissal, remembered for this browser.
    const dismiss = e.target.closest('[data-dismiss-announce]');
    if (dismiss) {
      const bar = dismiss.closest('.announce');
      if (bar) bar.remove();
      try { localStorage.setItem('yn_announce_hidden', String(Date.now())); } catch (err) {}
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (openModal) modalClose();
      else if (openDrawer) drawerClose();
    }
    if (e.key === 'Tab') {
      if (openModal) trapFocus(openModal, e);
      else if (openDrawer) trapFocus(openDrawer, e);
    }
  });

  /* ------------------------------------------------------ quantity steps -- */
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.qty button');
    if (!btn) return;
    e.preventDefault();

    const wrap = btn.closest('.qty');
    const input = $('input', wrap);
    if (!input) return;

    const min = parseInt(input.min || '1', 10);
    const max = parseInt(input.max || '99', 10);
    const step = btn.dataset.step === 'down' ? -1 : 1;
    let val = parseInt(input.value || '1', 10) + step;

    val = Math.max(min, Math.min(max, isNaN(val) ? min : val));
    input.value = String(val);
    input.dispatchEvent(new Event('change', { bubbles: true }));

    $$('button', wrap).forEach(b => {
      b.disabled = b.dataset.step === 'down' ? val <= min : val >= max;
    });
  });

  /* ---------------------------------------------------------- OTP inputs -- */
  /* Six boxes that behave like one field: auto-advance, backspace goes back,
     pasting the whole code fills every box, and the form self-submits.      */
  $$('[data-otp]').forEach(function (wrap) {
    const boxes = $$('input', wrap);
    if (!boxes.length) return;

    const sync = () => {
      boxes.forEach(b => b.classList.toggle('filled', b.value !== ''));
      const hidden = $('[data-otp-value]', wrap.closest('form') || document);
      if (hidden) hidden.value = boxes.map(b => b.value).join('');
    };

    const maybeSubmit = () => {
      const code = boxes.map(b => b.value).join('');
      if (code.length === boxes.length && wrap.dataset.otp === 'autosubmit') {
        const form = wrap.closest('form');
        if (form) {
          const submitBtn = $('[type="submit"]', form);
          if (submitBtn) submitBtn.classList.add('is-loading');
          form.requestSubmit ? form.requestSubmit() : form.submit();
        }
      }
    };

    boxes.forEach(function (box, i) {
      box.setAttribute('inputmode', 'numeric');
      box.setAttribute('autocomplete', i === 0 ? 'one-time-code' : 'off');
      box.setAttribute('maxlength', '1');
      box.setAttribute('aria-label', 'Digit ' + (i + 1) + ' of ' + boxes.length);

      box.addEventListener('input', function () {
        box.value = box.value.replace(/\D/g, '').slice(0, 1);
        if (box.value && boxes[i + 1]) boxes[i + 1].focus();
        sync();
        maybeSubmit();
      });

      box.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !box.value && boxes[i - 1]) {
          boxes[i - 1].focus();
          boxes[i - 1].value = '';
          sync();
          e.preventDefault();
        }
        if (e.key === 'ArrowLeft' && boxes[i - 1]) boxes[i - 1].focus();
        if (e.key === 'ArrowRight' && boxes[i + 1]) boxes[i + 1].focus();
      });

      box.addEventListener('paste', function (e) {
        e.preventDefault();
        const digits = (e.clipboardData || window.clipboardData)
          .getData('text').replace(/\D/g, '').slice(0, boxes.length);
        digits.split('').forEach((d, k) => { if (boxes[k]) boxes[k].value = d; });
        const next = Math.min(digits.length, boxes.length - 1);
        boxes[next].focus();
        sync();
        maybeSubmit();
      });

      box.addEventListener('focus', () => box.select());
    });

    sync();
  });

  /* -------------------------------------------------------- resend timer -- */
  /* <button data-resend data-seconds="60"> — disabled with a live countdown */
  $$('[data-resend]').forEach(function (btn) {
    let left = parseInt(btn.dataset.seconds || '60', 10);
    const label = btn.dataset.label || 'Resend code';
    if (left <= 0) return;

    btn.disabled = true;
    const tick = () => {
      if (left <= 0) {
        btn.disabled = false;
        btn.textContent = label;
        clearInterval(timer);
        return;
      }
      btn.textContent = label + ' in ' + left + 's';
      left--;
    };
    tick();
    const timer = setInterval(tick, 1000);
  });

  /* --------------------------------------------------------- countdowns -- */
  /* <span data-countdown="600"> renders 9:59 and stops at 0 */
  $$('[data-countdown]').forEach(function (el) {
    let left = parseInt(el.dataset.countdown, 10);
    const done = el.dataset.expiredText || 'expired';
    const tick = () => {
      if (left <= 0) {
        el.textContent = done;
        clearInterval(t);
        return;
      }
      const m = Math.floor(left / 60);
      const s = left % 60;
      el.textContent = m + ':' + String(s).padStart(2, '0');
      left--;
    };
    tick();
    const t = setInterval(tick, 1000);
  });

  /* ------------------------------------------------- form submit loading -- */
  document.addEventListener('submit', function (e) {
    const form = e.target;
    if (form.hasAttribute('data-no-loading')) return;
    const btn = form.querySelector('[type="submit"]');
    if (btn && !btn.classList.contains('is-loading')) {
      btn.classList.add('is-loading');
      if (!btn.querySelector('.spinner')) {
        btn.insertAdjacentHTML('afterbegin', '<span class="spinner"></span>');
      }
      // Re-enable if the page is restored from bfcache.
      setTimeout(() => btn.classList.remove('is-loading'), 12000);
    }
  });

  /* ------------------------------------------------------ sticky buy bar -- */
  const buyBar = $('[data-buy-bar]');
  const buyAnchor = $('[data-buy-anchor]');
  if (buyBar && buyAnchor && 'IntersectionObserver' in window) {
    new IntersectionObserver(
      entries => buyBar.classList.toggle('show', !entries[0].isIntersecting),
      { rootMargin: '-80px 0px 0px 0px' }
    ).observe(buyAnchor);
  }

  /* --------------------------------------------------------- flash toasts -- */
  $$('[data-flash]').forEach(function (el) {
    window.ynToast(el.textContent.trim(), el.dataset.flash || '');
    el.remove();
  });

  /* -------------------------------------------------- announcement state -- */
  (function () {
    const bar = $('.announce');
    if (!bar) return;
    try {
      const hidden = localStorage.getItem('yn_announce_hidden');
      // Re-show after 7 days so a new promo is not permanently suppressed.
      if (hidden && Date.now() - Number(hidden) < 7 * 864e5) bar.remove();
    } catch (e) {}
  })();

  /* ------------------------------------------------- responsive panels -- */
  /* The shop filter panel is a drawer below 1024px and a static sidebar above.
     If it was closed on mobile and the viewport then widens, the leftover
     aria-hidden would hide the whole sidebar from assistive tech. */
  (function () {
    const panels = $$('[data-responsive-panel]');
    if (!panels.length) return;
    const sync = () => {
      const desktop = window.matchMedia('(min-width: 1024px)').matches;
      panels.forEach(p => {
        if (desktop) {
          p.removeAttribute('aria-hidden');
          p.classList.remove('open');
        }
      });
      if (desktop && openDrawer && openDrawer.hasAttribute('data-responsive-panel')) {
        openDrawer = null;
        scrim(false);
        unlockScroll();
      }
    };
    window.addEventListener('resize', sync);
    sync();
  })();

  /* ------------------------------------------------------- cart badge -- */
  function updateCartBadge(count) {
    const btn = $('.cart-btn');
    if (!btn) return;
    let bubble = $('.cart-count', btn);
    if (!count) {
      if (bubble) bubble.remove();
      return;
    }
    if (!bubble) {
      bubble = document.createElement('span');
      bubble.className = 'cart-count';
      btn.appendChild(bubble);
    }
    bubble.textContent = count > 99 ? '99+' : String(count);
    btn.setAttribute('aria-label', 'Cart, ' + count + ' items');
  }

  /* ------------------------------------------------------- add to cart -- */
  document.addEventListener('submit', async function (e) {
    const form = e.target.closest('[data-add-form]');
    if (!form) return;
    e.preventDefault();

    const btn = $('[data-add-btn]', form);
    if (btn) btn.classList.add('is-loading');

    const body = {
      variant_id: Number(($('[data-variant-input]', form) || {}).value || 0),
      qty: Number(($('[data-qty-input]', form) || {}).value || 1)
    };

    const res = await window.ynPost(form.action, body);

    if (btn) btn.classList.remove('is-loading');

    if (res.ok) {
      updateCartBadge(res.count);
      window.ynToast(res.error || 'Added to your cart', res.error ? 'warn' : 'success');
    } else {
      window.ynToast(res.error || 'Could not add that to your cart', 'error');
    }
  });

  // The sticky mobile bar just drives the real form.
  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-bar-add]')) return;
    const form = $('[data-add-form]');
    if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
  });

  /* ------------------------------------------------------ PDP: shades -- */
  /* Each swatch is a real link carrying ?shade=SKU, so this is enhancement:
     with JS off the server renders the right state on navigation. */
  const pdp = $('[data-pdp]');
  if (pdp) {
    const track      = $('[data-gallery-track]', pdp);
    const slides     = $$('[data-gallery-slide]', pdp);
    const thumbs     = $$('[data-gallery-thumb]', pdp);
    const dots       = $$('[data-gallery-dot]', pdp);
    const barImg     = $('[data-bar-image]');
    const priceNow   = $('[data-price-now]');
    const barPrice   = $('[data-bar-price]');
    const shadeName  = $('[data-shade-name]');
    const stockLine  = $('[data-stock-line]');
    const variantIn  = $('[data-variant-input]');
    const qtyInput   = $('[data-qty-input]');
    const addBtn     = $('[data-add-btn]');
    const barBtn     = $('[data-bar-add]');
    const dyeLot     = $('[data-dyelot]');
    const dispatch   = (stockLine && stockLine.dataset.dispatch) || '1-2';
    const swipeMq    = window.matchMedia('(max-width:899px)');
    let ignoreScroll = 0;

    const markGallery = function (variant) {
      slides.forEach(s => s.classList.toggle('is-on', s.dataset.variant === variant));
      thumbs.forEach(t => t.classList.toggle('is-on', t.dataset.variant === variant));
      dots.forEach(d => {
        const on = d.dataset.variant === variant;
        d.classList.toggle('is-on', on);
        d.setAttribute('aria-current', on ? 'true' : 'false');
      });
    };

    const scrollToSlide = function (slide) {
      if (!track || !slide || !swipeMq.matches) return;
      ignoreScroll++;
      track.scrollTo({ left: slide.offsetLeft, behavior: 'smooth' });
      const done = () => { ignoreScroll = Math.max(0, ignoreScroll - 1); };
      if ('onscrollend' in track) {
        track.addEventListener('scrollend', done, { once: true });
      } else {
        window.setTimeout(done, 450);
      }
    };

    const shadeFor = function (variant) {
      if (!variant) return null;
      return $$('[data-shade]', pdp).find(s => s.dataset.variant === variant) || null;
    };

    const selectShade = function (sw, fromGallery) {
      if (!sw) return;
      if (sw.getAttribute('aria-pressed') === 'true') {
        if (!fromGallery) {
          const slide = slides.find(s => s.dataset.variant === sw.dataset.variant);
          if (slide) scrollToSlide(slide);
        }
        return;
      }
      const stock = Number(sw.dataset.stock || 0);

      $$('[data-shade]', pdp).forEach(s => s.setAttribute('aria-pressed', 'false'));
      sw.setAttribute('aria-pressed', 'true');

      if (variantIn) variantIn.value = sw.dataset.variant;

      if (shadeName) {
        shadeName.innerHTML = '';
        shadeName.append(sw.dataset.name);
        if (sw.dataset.code) {
          const s = document.createElement('span');
          s.className = 'muted';
          s.textContent = ' · ' + sw.dataset.code;
          shadeName.appendChild(s);
        }
      }

      if (barImg) barImg.src = sw.dataset.img;
      if (priceNow) priceNow.textContent = sw.dataset.price;
      if (barPrice) barPrice.textContent = sw.dataset.price;
      if (dyeLot && sw.dataset.dyelot) dyeLot.textContent = sw.dataset.dyelot;

      if (stockLine) {
        const low = Number(sw.dataset.low || 5);
        stockLine.classList.remove('stock-in', 'stock-low', 'stock-out');
        if (stock < 1) {
          stockLine.classList.add('stock-out');
          stockLine.textContent = 'Out of stock in this shade';
        } else if (stock <= low) {
          stockLine.classList.add('stock-low');
          stockLine.textContent = 'Only ' + stock + ' ball' + (stock === 1 ? '' : 's') + ' left';
        } else {
          stockLine.classList.add('stock-in');
          stockLine.textContent = 'In stock · ships in ' + dispatch + ' business days';
        }
      }

      if (qtyInput) {
        qtyInput.max = String(Math.max(1, Math.min(stock, 20)));
        if (Number(qtyInput.value) > stock) qtyInput.value = String(Math.max(1, stock));
      }

      [addBtn, barBtn].forEach(b => { if (b) b.disabled = stock < 1; });
      if (addBtn) {
        const label = $('span', addBtn);
        if (label) label.textContent = stock < 1 ? 'Out of stock' : 'Add to cart';
      }
      if (barBtn) barBtn.textContent = stock < 1 ? 'Sold out' : 'Add to cart';

      const wishBtn = $('[data-wish-btn]');
      if (wishBtn) {
        wishBtn.dataset.wish = sw.dataset.variant;
        const saved = serverWish || readWish();
        wishBtn.classList.toggle('is-on', saved.indexOf(Number(sw.dataset.variant)) !== -1);
      }

      try {
        history.replaceState(null, '', sw.getAttribute('href'));
      } catch (err) {}

      markGallery(sw.dataset.variant);
      if (!fromGallery) {
        const slide = slides.find(s => s.dataset.variant === sw.dataset.variant);
        if (slide) scrollToSlide(slide);
      }
    };

    $$('[data-shade]', pdp).forEach(function (sw) {
      sw.addEventListener('click', function (ev) {
        ev.preventDefault();
        selectShade(sw);
      });
    });

    const pickSlide = function (el) {
      const sw = shadeFor(el.dataset.variant);
      if (sw) {
        selectShade(sw);
        return;
      }
      markGallery(el.dataset.variant);
      const slide = slides.find(s => s.dataset.variant === el.dataset.variant);
      if (slide) scrollToSlide(slide);
    };

    thumbs.concat(dots).forEach(function (el) {
      el.addEventListener('click', function () { pickSlide(el); });
    });

    if (track && slides.length > 1) {
      const startSlide = slides.find(s => s.classList.contains('is-on')) || slides[0];
      if (startSlide && swipeMq.matches) {
        track.scrollTo({ left: startSlide.offsetLeft, behavior: 'auto' });
      }

      let ticking = false;
      track.addEventListener('scroll', function () {
        if (ignoreScroll || ticking || !swipeMq.matches) return;
        ticking = true;
        requestAnimationFrame(function () {
          ticking = false;
          const w = track.clientWidth || 1;
          const i = Math.min(slides.length - 1, Math.max(0, Math.round(track.scrollLeft / w)));
          const slide = slides[i];
          if (!slide) return;
          const sw = shadeFor(slide.dataset.variant);
          if (sw) selectShade(sw, true);
          else markGallery(slide.dataset.variant);
        });
      }, { passive: true });

      track.addEventListener('keydown', function (ev) {
        if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
        const on = slides.findIndex(s => s.classList.contains('is-on'));
        const next = ev.key === 'ArrowRight' ? on + 1 : on - 1;
        if (next < 0 || next >= slides.length) return;
        ev.preventDefault();
        pickSlide(slides[next]);
      });
    }
  }

  /* ----------------------------------------------------- pincode check -- */
  const pinBtn = $('[data-pincode-check]');
  if (pinBtn) {
    const input  = $('[data-pincode-input]');
    const result = $('[data-pincode-result]');

    const check = async () => {
      const pin = (input.value || '').replace(/\D/g, '');
      if (pin.length !== 6) {
        result.textContent = 'Enter a valid 6-digit pincode.';
        result.style.color = 'var(--danger)';
        return;
      }
      result.style.color = '';
      result.textContent = 'Checking…';

      const res = await window.ynPost('/pincode-check.php', { pincode: pin });
      if (res.ok) {
        result.textContent = res.message;
        result.style.color = res.serviceable ? 'var(--success)' : 'var(--danger)';
        try { localStorage.setItem('yn_pincode', pin); } catch (e) {}
      } else {
        result.textContent = res.error || 'Could not check that pincode.';
        result.style.color = 'var(--danger)';
      }
    };

    pinBtn.addEventListener('click', check);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); check(); } });

    try {
      const saved = localStorage.getItem('yn_pincode');
      if (saved) input.value = saved;
    } catch (e) {}
  }

  /* ------------------------------------------------- pincode autofill -- */
  /* Typing a pincode on checkout fills city + state, so the customer types
     six digits instead of two more fields. Both stay editable. */
  const pinFill = $('[data-pincode-fill]');
  if (pinFill) {
    const cityEl  = $('[data-city-field]');
    const stateEl = $('[data-state-field]');
    const noteEl  = $('[data-pincode-note]');
    let lastLookup = '';

    const lookup = async () => {
      const pin = (pinFill.value || '').replace(/\D/g, '');
      if (pin.length !== 6 || pin === lastLookup) return;
      lastLookup = pin;

      const res = await window.ynPost('/pincode-check.php', { pincode: pin });
      if (!res.ok) {
        if (noteEl) { noteEl.textContent = res.error || 'Could not check that pincode.'; }
        return;
      }
      // Only fill from a pincode we actually recognise. res.message may say
      // "your area" for unknown pincodes — that is display copy, not data.
      if (res.known) {
        if (cityEl && !cityEl.value && res.city) cityEl.value = res.city;
        if (stateEl && !stateEl.value && res.state) {
          [...stateEl.options].forEach(o => { if (o.value === res.state) stateEl.value = res.state; });
        }
      }
      if (noteEl) {
        noteEl.textContent = res.message || '';
        noteEl.style.color = res.serviceable ? 'var(--success)' : 'var(--danger)';
      }
    };

    pinFill.addEventListener('blur', lookup);
    pinFill.addEventListener('input', () => {
      if ((pinFill.value || '').replace(/\D/g, '').length === 6) lookup();
    });
  }

  /* --------------------------------------------------- cart quantities -- */
  /* The stepper already updates the number; this submits the change. */
  document.addEventListener('change', function (e) {
    const input = e.target.closest('[data-cart-qty]');
    if (!input) return;
    const form = input.closest('[data-cart-form]');
    if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
  });

  /* -------------------------------------------------------- wishlist -- */
  /* Signed in  -> stored server-side, so it follows them between devices.
     Signed out -> localStorage, then migrated on their next sign-in.
     Keyed by VARIANT id: people want a specific shade, not "that yarn". */
  const WISH_KEY = 'yn_wishlist_v';
  const isAuthed = () => {
    const m = $('meta[name="yn-auth"]');
    return !!m && m.getAttribute('content') === '1';
  };
  const readWish = () => {
    try { return JSON.parse(localStorage.getItem(WISH_KEY) || '[]'); } catch (e) { return []; }
  };
  const writeWish = list => {
    try { localStorage.setItem(WISH_KEY, JSON.stringify(list)); } catch (e) {}
  };

  let serverWish = null;   // populated after a sync

  const paintWish = ids => {
    $$('[data-wish]').forEach(b => {
      b.classList.toggle('is-on', ids.indexOf(Number(b.dataset.wish)) !== -1);
    });
  };

  // On a signed-in page load, hand over anything saved while signed out.
  (async function syncWishlist() {
    if (!isAuthed()) {
      paintWish(readWish());
      return;
    }
    const local = readWish();
    const res = await window.ynPost('/wishlist-toggle.php', {
      action: 'sync',
      variant_ids: local.join(',')
    });
    if (res.ok) {
      if (local.length) {
        try { localStorage.removeItem(WISH_KEY); } catch (e) {}
      }
      serverWish = (res.ids || []).map(Number);
      paintWish(serverWish);
    }
  })();

  document.addEventListener('click', async function (e) {
    const btn = e.target.closest('[data-wish]');
    if (!btn) return;
    // Cards are wrapped in links; the heart must not navigate.
    e.preventDefault();
    e.stopPropagation();

    const id = Number(btn.dataset.wish);
    if (!id) {
      window.ynToast('Please choose a shade first', 'warn');
      return;
    }

    if (!isAuthed()) {
      let list = readWish();
      const on = list.indexOf(id) !== -1;
      list = on ? list.filter(x => x !== id) : list.concat(id);
      writeWish(list);
      paintWish(list);
      window.ynToast(on ? 'Removed from wishlist' : 'Saved — sign in to keep it', on ? '' : 'success');
      return;
    }

    const res = await window.ynPost('/wishlist-toggle.php', { variant_id: id });
    if (!res.ok) {
      window.ynToast(res.error || 'Could not update your wishlist', 'error');
      return;
    }
    $$('[data-wish="' + id + '"]').forEach(b => b.classList.toggle('is-on', res.on));
    window.ynToast(res.on ? 'Saved to your wishlist' : 'Removed from wishlist', res.on ? 'success' : '');
  });

  /* ------------------------------------------------------ overflow guard -- */
  /* The client requirement is zero horizontal overflow. This runs only with
     ?debug=1 and outlines any element wider than the viewport.              */
  if (location.search.indexOf('debug=1') !== -1) {
    /* An element is only a real offender if it pushes the PAGE wide.
       Five legitimate cases must be excluded, or the check cries wolf — and a
       checker that cries wolf is one nobody reads:
         1. left overflow — in LTR it never creates a horizontal scrollbar
         2. off-canvas panels parked at translateX(±100%)
         3. children of a self-scrolling rail (.rail, .scroll-x, .tabs)
         4. position:fixed — never contributes to document scrollWidth
         5. visibility:hidden — not painted, so it cannot be seen to overflow
       4 and 5 are what the closed .shop-filters panel relies on: it sits to the
       RIGHT of the viewport, which would be a genuine offence for any element
       that were actually in flow and visible.                               */
    const containedBy = el => {
      for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
        const ox = getComputedStyle(p).overflowX;
        if (ox === 'auto' || ox === 'scroll' || ox === 'hidden' || ox === 'clip') return true;
      }
      return false;
    };

    const check = () => {
      const w = document.documentElement.clientWidth;
      const bad = $$('*').filter(el => {
        const r = el.getBoundingClientRect();
        if (r.right <= w + 1) return false;
        if (el.closest('[aria-hidden="true"], .drawer:not(.open), .modal:not(.open)')) return false;
        const cs = getComputedStyle(el);
        if (cs.position === 'fixed' || cs.visibility === 'hidden') return false;
        return !containedBy(el);
      });
      $$('.debug-overflow').forEach(el => el.classList.remove('debug-overflow'));
      bad.forEach(el => el.classList.add('debug-overflow'));

      const scrollW = document.documentElement.scrollWidth;
      const style = bad.length ? 'color:#fff;background:#A82B22;padding:2px 6px'
                               : 'color:#fff;background:#3B7A50;padding:2px 6px';
      console.log(
        '%c overflow check ',
        style,
        'scrollWidth=' + scrollW + ' clientWidth=' + w + ' offenders=' + bad.length
      );
      if (bad.length) console.warn('Overflowing elements:', bad);
    };
    window.addEventListener('load', check);
    window.addEventListener('resize', () => setTimeout(check, 150));
  }

  /* --------------------------------------------------- show/hide password -- */
  /* Every input[type=password] gets a reveal button, so the six forms that have
     one do not each repeat the markup. Typing a password you cannot see is the
     main reason people mistype it and then blame the site.

     Progressive enhancement: without JS the field is a plain password input and
     still works. Opt a field out with data-no-reveal.                        */
  const EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
    + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M1.8 12S5.4 5.5 12 5.5 22.2 12 22.2 12 18.6 18.5 12 18.5 1.8 12 1.8 12z"/>'
    + '<circle cx="12" cy="12" r="3.2"/></svg>';

  const EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
    + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M2 2l20 20"/>'
    + '<path d="M9.9 5.8A9.8 9.8 0 0112 5.5c6.6 0 10.2 6.5 10.2 6.5a18 18 0 01-2.7 3.6"/>'
    + '<path d="M6.4 7.7A17.4 17.4 0 001.8 12S5.4 18.5 12 18.5a9.9 9.9 0 004-.8"/>'
    + '<path d="M9.9 9.9a3.2 3.2 0 004.3 4.3"/></svg>';

  $$('input[type="password"]').forEach(function (input) {
    if (input.dataset.noReveal !== undefined || input.closest('.pw-wrap')) return;

    const wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const btn = document.createElement('button');
    btn.type = 'button';               // never submits the form it sits in
    btn.className = 'pw-toggle';
    btn.innerHTML = EYE;
    btn.setAttribute('aria-label', 'Show password');
    btn.setAttribute('aria-pressed', 'false');
    // Stays in the tab order. It is a real control, and someone using a
    // keyboard without a mouse needs it more than a mouse user does.
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      const show = input.type === 'password';
      // Preserve the caret — changing `type` moves it to the end otherwise,
      // which is maddening halfway through a long password.
      const start = input.selectionStart;
      const end   = input.selectionEnd;

      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? EYE_OFF : EYE;
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');

      input.focus();
      try { input.setSelectionRange(start, end); } catch (e) { /* not all types allow it */ }
    });

    // Never leave a password on screen after the form goes.
    const form = input.form;
    if (form) {
      form.addEventListener('submit', function () {
        input.type = 'password';
      });
    }
  });
  /* ---------------------------------------------------------- auto-submit --
     CSP is nonce-based, so inline onchange="this.form.submit()" never runs.
     Sort (and any other [data-auto-submit] select) submits from here instead,
     keeping every hidden filter field on the form. */
  document.addEventListener('change', function (e) {
    const sel = e.target.closest('[data-auto-submit]');
    if (!sel || !sel.form) return;
    try { sessionStorage.setItem('ynShopScroll', String(window.scrollY)); } catch (err) {}
    if (sel.form.requestSubmit) sel.form.requestSubmit();
    else sel.form.submit();
  });

  /* ------------------------------------------------------- live filtering --
     Ticking a filter applies it immediately on the desktop sidebar. The old
     behaviour needed an explicit "Apply filters" click, which reads as the
     filters being broken — you tick Cotton, nothing happens, you conclude the
     shop is faulty and leave.

     Only for the sticky sidebar. Below 1024px the panel is an off-canvas
     drawer, and reloading on every tick would slam it shut each time, so there
     the "Show results" button stays.                                        */
  const filterForm = $('[data-filter-form]');
  if (filterForm) {
    const isSidebar = () => window.matchMedia('(min-width:1024px)').matches;

    const syncMode = () => {
      const auto = isSidebar();
      filterForm.classList.toggle('is-auto', auto);
      // Hide the now-redundant button, but only once JS is definitely running.
      const apply = $('[data-filter-apply]', filterForm);
      if (apply) apply.hidden = auto;
    };
    syncMode();
    window.addEventListener('resize', syncMode);

    // Empty price boxes would otherwise ride along as ?price_min=&price_max=
    // on every link the customer shares. Applies to the button too, not just
    // the auto path.
    filterForm.addEventListener('submit', function () {
      $$('input[name="price_min"], input[name="price_max"]', filterForm)
        .forEach(function (inp) { if (inp.value.trim() === '') inp.disabled = true; });
    });

    filterForm.addEventListener('change', function (ev) {
      if (!isSidebar()) return;
      const t = ev.target;
      if (!t || !t.matches('input, select')) return;

      // A price box fires `change` on blur or Enter, never per keystroke, so
      // this does not fire mid-typing.

      // Filtering must not throw you back to the top of the page.
      try { sessionStorage.setItem('ynShopScroll', String(window.scrollY)); } catch (e) {}

      filterForm.setAttribute('aria-busy', 'true');
      if (filterForm.requestSubmit) filterForm.requestSubmit();
      else filterForm.submit();
    });

    // Put the scroll position back after the reload.
    try {
      const y = sessionStorage.getItem('ynShopScroll');
      if (y !== null) {
        sessionStorage.removeItem('ynShopScroll');
        window.scrollTo(0, parseInt(y, 10) || 0);
      }
    } catch (e) {}
  }
  /* --------------------------------------------------------- header lift --
     Add a shadow only once the page has moved. A header that is already
     shadowed at rest looks detached from the page it belongs to.            */
  const siteHeader = $(".site-header");
  if (siteHeader) {
    let stuck = false;
    const onScroll = () => {
      const should = window.scrollY > 4;
      if (should !== stuck) {            // touch the DOM only when it changes
        stuck = should;
        siteHeader.classList.toggle("is-stuck", stuck);
      }
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }
  /* --------------------------------------------------------------- theme --
     The <head> script has already applied the saved choice before paint; this
     only handles switching. Three states, not two: "dark", "light", and no
     attribute at all, which means follow the operating system.               */
  const themeBtns = $$("[data-theme-toggle]");   // header icon + drawer button
  if (themeBtns.length) {
    const themeBtn = themeBtns[0];
    const root  = document.documentElement;
    const meta  = $("[data-theme-color]");
    const media = window.matchMedia("(prefers-color-scheme: dark)");

    const isDark = () => {
      const set = root.getAttribute("data-theme");
      return set ? set === "dark" : media.matches;
    };

    const paintMeta = () => {
      // Keep the browser chrome (Android address bar) in step with the page.
      if (meta) meta.setAttribute("content", isDark() ? "#191614" : "#FDFBF7");
      themeBtns.forEach(function (b) { b.setAttribute("aria-pressed", isDark() ? "true" : "false"); });
    };
    paintMeta();

    themeBtns.forEach(function (btn) { btn.addEventListener("click", function () {
      const next = isDark() ? "light" : "dark";
      root.setAttribute("data-theme", next);
      try { localStorage.setItem("yn-theme", next); } catch (e) {}
      paintMeta();
    }); });

    // If the OS flips while the page is open and the visitor never chose,
    // follow it.
    media.addEventListener("change", function () {
      if (!root.getAttribute("data-theme")) paintMeta();
    });
  }
})();

/* ---------------------------------------------------------------- mascot --
   Nesty greets on load (that animation is pure CSS, so it still happens with
   this file blocked) and says something new each time he is poked.

   The lines come from a data attribute rather than being hardcoded here,
   because two of them quote real settings — the free-delivery threshold and
   the returns window — and a copy in JS would drift the day the shop changes
   either one.                                                              */
(function () {
  "use strict";

  const wrap = document.querySelector("[data-mascot]");
  if (!wrap) return;

  const btn  = wrap.querySelector("[data-mascot-poke]");
  const box  = wrap.querySelector("[data-mascot-bubble]");
  const slot = wrap.querySelector("[data-mascot-text]");
  if (!btn || !box || !slot) return;

  let lines = [];
  try { lines = JSON.parse(wrap.dataset.greetings || "[]"); } catch (e) { return; }
  if (!Array.isArray(lines) || lines.length < 2) return;

  const still = window.matchMedia("(prefers-reduced-motion: reduce)");
  let at = 0;
  let busy = false;

  btn.addEventListener("click", function () {
    if (busy) return;
    busy = true;

    at = (at + 1) % lines.length;

    // Squash the character and fade the old line out together, then swap the
    // text at the midpoint so the change is never seen mid-fade.
    if (!still.matches) {
      btn.classList.remove("is-poked");
      void btn.offsetWidth;            // restart the animation on a rapid re-poke
      btn.classList.add("is-poked");
    }

    const swap = function () {
      slot.textContent = lines[at];
      box.classList.remove("is-swapping");
      busy = false;
    };

    if (still.matches) { swap(); return; }
    box.classList.add("is-swapping");
    window.setTimeout(swap, 180);
  });

  btn.addEventListener("animationend", function () {
    btn.classList.remove("is-poked");
  });
})();


/* ------------------------------------------------------------------ rail --
   Prev/next buttons for a horizontally scrolling [data-rail].

   Progressive enhancement: the rail scrolls natively with a swipe, a
   trackpad, or the keyboard whether or not this runs. The buttons are found
   relative to the rail's own section, so several rails can coexist on a page
   without wiring ids together.

   Buttons disable at each end. Without that they stay lit and do nothing,
   which reads as broken rather than as "you are at the end".            */
(function () {
  "use strict";

  // Not $$ — that helper is scoped to the main IIFE further up this file and
  // is not visible out here. Using it would throw a ReferenceError and the
  // arrows would silently never bind.
  const rails = Array.from(document.querySelectorAll("[data-rail]"));
  if (!rails.length) return;

  rails.forEach(function (rail) {
    const scope = rail.closest("section") || document;
    const prev  = scope.querySelector("[data-rail-prev]");
    const next  = scope.querySelector("[data-rail-next]");
    if (!prev || !next) return;

    // Scroll by one card plus its gap, so a press always lands on a card edge
    // rather than halfway through one.
    function step() {
      const first = rail.firstElementChild;
      if (!first) return rail.clientWidth * 0.8;
      const gap = parseFloat(getComputedStyle(rail).columnGap || "0") || 0;
      return first.getBoundingClientRect().width + gap;
    }

    function sync() {
      // 1px of slack: sub-pixel layout means scrollLeft rarely hits the
      // exact maximum, and a button that never enables at the end is worse
      // than one that enables a pixel early.
      const max = rail.scrollWidth - rail.clientWidth;
      prev.disabled = rail.scrollLeft <= 1;
      next.disabled = rail.scrollLeft >= max - 1;
    }

    const smooth = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const go = (dir) => rail.scrollBy({
      left: dir * step(),
      behavior: smooth ? "smooth" : "auto"
    });

    prev.addEventListener("click", () => go(-1));
    next.addEventListener("click", () => go(1));
    rail.addEventListener("scroll", sync, { passive: true });
    window.addEventListener("resize", sync);
    sync();
  });
})();
