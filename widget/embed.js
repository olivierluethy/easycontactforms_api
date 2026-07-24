// EasyContactForm vanilla-JS embed. Drop this into any HTML page alongside a
// <div data-easycontact="<your-token>"></div> and the form auto-mounts on load.
// No build step required — served directly to customer sites from a CDN.
//
// ──────────────────────────────────────────────────────────────────────────
//  Theming
// ──────────────────────────────────────────────────────────────────────────
// Pick a palette by adding `data-easycontact-theme` to the mount node:
//
//   <div data-easycontact="…" data-easycontact-theme="auto"></div>  (default)
//   <div data-easycontact="…" data-easycontact-theme="light"></div>
//   <div data-easycontact="…" data-easycontact-theme="dark"></div>
//
// `auto` follows the visitor's `prefers-color-scheme`. Once mounted, the
// wrapper exposes the resolved value as `data-theme="…"` on the `.ecf-wrap`
// element so your own CSS can target it (e.g.
// `.ecf-wrap[data-theme="dark"] .ecf-button { … }`).
//
// ──────────────────────────────────────────────────────────────────────────
//  Layout
// ──────────────────────────────────────────────────────────────────────────
// By default the form is horizontally centered with a small horizontal
// gutter. For dedicated contact pages where the form is the page's main
// content, opt into the full-page layout via `data-easycontact-layout="page"`
// and optionally provide a heading and description:
//
//   <div data-easycontact="…"
//        data-easycontact-theme="dark"
//        data-easycontact-layout="page"
//        data-easycontact-heading="Get in touch"
//        data-easycontact-description="Questions? Send us a message."></div>
//
// In page mode, the mount node becomes the `.ecf-page` section (filling the
// available vertical space) and the form is rendered as a `.ecf-wrap` child.
//
// ──────────────────────────────────────────────────────────────────────────
//  Alignment
// ──────────────────────────────────────────────────────────────────────────
// Position the form within its container with the `data-easycontact-align`
// and `data-easycontact-offset-x` attributes. `align` accepts `left`,
// `center` (default), or `right`. `offset-x` accepts a bare number (treated
// as pixels) or any CSS length (`2rem`, `5%`, `clamp(0px,4vw,48px)`, …).
// The offset applies to the leading edge for left alignment and the
// trailing edge for right alignment; it is a no-op when centered.
//
//   <div data-easycontact="…" data-easycontact-align="left"></div>
//   <div data-easycontact="…" data-easycontact-align="right"></div>
//   <div data-easycontact="…" data-easycontact-align="left"  data-easycontact-offset-x="32"></div>
//   <div data-easycontact="…" data-easycontact-align="right" data-easycontact-offset-x="2rem"></div>
//
// The form keeps its `max-width: 480px` cap, so alignment only reveals
// itself when the surrounding container is wider than the form.
//
// NOTE: the palette and layout rules here are duplicated in
// `ContactForm.jsx` so the React widget and the script-tag version render
// identically. Update both if you retouch them.

(function () {
  'use strict';

  // Production backend for the hosted EasyContactForm service.
  var API_BASE = 'https://api.easycontactforms.com';

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  var PHONE_RE = /^[0-9+()\/.\s-]{3,40}$/;
  var STYLE_ID = 'ecf-embed-styles';

  // The fields every form had before the builder existed. Used when the form
  // definition cannot be fetched — a visitor filling in the classic three
  // fields (which the backend still accepts) beats a visitor staring at a blank
  // space because our API had a bad minute.
  var FALLBACK_FIELDS = [
    { key: 'full_name', label: 'Full name', type: 'text', required: true },
    { key: 'email', label: 'Email', type: 'email', required: true },
    { key: 'message', label: 'Message', type: 'textarea', required: true },
  ];

  var FIELD_LIMITS = { text: 150, email: 190, phone: 40, textarea: 5000 };

  var AUTOCOMPLETE = {
    full_name: 'name',
    name: 'name',
    email: 'email',
    phone: 'tel',
    company: 'organization',
  };

  var DARK_VARS = [
    '--ecf-fg:#e2e8f0;--ecf-muted:#94a3b8;--ecf-input-bg:#0b1322;--ecf-input-fg:#e2e8f0;',
    '--ecf-border:#334155;--ecf-primary:#60a5fa;--ecf-primary-fg:#0f172a;',
    '--ecf-error-bg:#3f1d1d;--ecf-error-border:#7f1d1d;--ecf-error-fg:#fecaca;',
    '--ecf-success-bg:#14352a;--ecf-success-border:#166534;--ecf-success-fg:#bbf7d0;',
  ].join('');

  var STYLES = [
    '.ecf-wrap,.ecf-page{',
      '--ecf-bg:transparent;',
      '--ecf-fg:#1f2933;',
      '--ecf-muted:#4b5563;',
      '--ecf-input-bg:#ffffff;',
      '--ecf-input-fg:#1f2933;',
      '--ecf-border:#d1d5db;',
      '--ecf-primary:#2563eb;',
      '--ecf-primary-fg:#ffffff;',
      '--ecf-error-bg:#fef2f2;',
      '--ecf-error-border:#fecaca;',
      '--ecf-error-fg:#991b1b;',
      '--ecf-success-bg:#f0fdf4;',
      '--ecf-success-border:#bbf7d0;',
      '--ecf-success-fg:#166534;',
      'font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;',
      'box-sizing:border-box;',
    '}',
    '.ecf-wrap{',
      'max-width:480px;padding-inline:16px;width:100%;',
      'font-size:15px;line-height:1.5;color:var(--ecf-fg);background:var(--ecf-bg);',
    '}',
    '.ecf-wrap *{box-sizing:border-box}',
    '.ecf-wrap[data-align="center"]{margin-inline:auto}',
    '.ecf-wrap[data-align="left"]{margin-inline-start:var(--ecf-offset-x,0);margin-inline-end:auto}',
    '.ecf-wrap[data-align="right"]{margin-inline-start:auto;margin-inline-end:var(--ecf-offset-x,0)}',
    '.ecf-wrap[data-theme="dark"],.ecf-page[data-theme="dark"]{', DARK_VARS, '}',
    '@media (prefers-color-scheme: dark){',
      '.ecf-wrap[data-theme="auto"],.ecf-page[data-theme="auto"]{', DARK_VARS, '}',
    '}',
    '.ecf-page{',
      'display:flex;flex-direction:column;align-items:center;justify-content:center;',
      'min-height:clamp(420px,calc(100vh - 12rem),100vh);width:100%;',
      'padding:48px 16px;color:var(--ecf-fg);',
    '}',
    '@media (min-width:640px){.ecf-page{padding:64px 24px}}',
    '@media (min-width:768px){.ecf-page{padding:80px 24px}}',
    '.ecf-page[data-align="left"]{align-items:flex-start}',
    '.ecf-page[data-align="right"]{align-items:flex-end}',
    '.ecf-page .ecf-wrap{padding-inline:0}',
    '.ecf-page-heading{text-align:center;width:100%;max-width:560px;margin:0 auto 32px}',
    '@media (min-width:640px){.ecf-page-heading{margin-bottom:40px}}',
    '.ecf-page[data-align="left"] .ecf-page-heading{text-align:left;margin-inline-start:var(--ecf-offset-x,0);margin-inline-end:auto}',
    '.ecf-page[data-align="right"] .ecf-page-heading{text-align:right;margin-inline-start:auto;margin-inline-end:var(--ecf-offset-x,0)}',
    '.ecf-page-title{font-size:1.875rem;font-weight:800;letter-spacing:-0.025em;line-height:1.1;margin:0 0 12px 0;color:var(--ecf-fg)}',
    '@media (min-width:640px){.ecf-page-title{font-size:2.25rem}}',
    '@media (min-width:768px){.ecf-page-title{font-size:3rem}}',
    '.ecf-page-description{font-size:1rem;line-height:1.6;color:var(--ecf-muted);margin:0}',
    '@media (min-width:640px){.ecf-page-description{font-size:1.125rem}}',
    '.ecf-wrap .ecf-field{margin-bottom:14px}',
    '.ecf-wrap .ecf-label{display:block;margin-bottom:6px;font-weight:500;font-size:13px;color:var(--ecf-muted)}',
    '.ecf-wrap .ecf-input,.ecf-wrap .ecf-textarea{width:100%;padding:10px 12px;border:1px solid var(--ecf-border);border-radius:6px;font:inherit;background:var(--ecf-input-bg);color:var(--ecf-input-fg)}',
    '.ecf-wrap .ecf-input:focus,.ecf-wrap .ecf-textarea:focus{outline:none;border-color:var(--ecf-primary);box-shadow:0 0 0 3px color-mix(in srgb, var(--ecf-primary) 25%, transparent)}',
    '.ecf-wrap .ecf-textarea{min-height:120px;resize:vertical}',
    '.ecf-wrap .ecf-button{padding:10px 18px;background:var(--ecf-primary);color:var(--ecf-primary-fg);border:none;border-radius:6px;font:inherit;font-weight:500;cursor:pointer}',
    '.ecf-wrap .ecf-button:disabled{opacity:0.7;cursor:not-allowed}',
    '@media (max-width:640px){.ecf-wrap .ecf-button{width:100%}}',
    '.ecf-wrap .ecf-error{background:var(--ecf-error-bg);border:1px solid var(--ecf-error-border);color:var(--ecf-error-fg);padding:10px 12px;border-radius:6px;margin-bottom:14px;font-size:14px}',
    '.ecf-wrap .ecf-success{background:var(--ecf-success-bg);border:1px solid var(--ecf-success-border);color:var(--ecf-success-fg);padding:14px 16px;border-radius:6px;font-size:15px}',
    '.ecf-wrap .ecf-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}',
    '.ecf-wrap .ecf-optional{font-weight:400;opacity:0.75}',
    '.ecf-wrap .ecf-loading{display:flex;flex-direction:column;gap:14px}',
    '.ecf-wrap .ecf-skeleton{display:block;height:62px;border-radius:6px;background:var(--ecf-input-bg);border:1px solid var(--ecf-border);opacity:0.6}',
    '.ecf-wrap .ecf-skeleton-tall{height:140px}',
  ].join('');

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.appendChild(document.createTextNode(STYLES));
    document.head.appendChild(style);
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (k === 'style') node.setAttribute('style', attrs[k]);
        else if (k === 'className') node.className = attrs[k];
        else if (k in node) node[k] = attrs[k];
        else node.setAttribute(k, attrs[k]);
      }
    }
    (children || []).forEach(function (c) {
      if (c == null) return;
      node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
  }

  function normalizeTheme(value) {
    return value === 'light' || value === 'dark' ? value : 'auto';
  }

  function normalizeLayout(value) {
    return value === 'page' ? 'page' : 'inline';
  }

  function normalizeAlign(value) {
    return value === 'left' || value === 'right' ? value : 'center';
  }

  // Returns a CSS length string for `data-easycontact-offset-x`, or null
  // when there is nothing to apply. Bare numbers become px; anything else
  // passes through so callers can use any CSS length.
  function formatOffsetX(raw) {
    if (raw == null) return null;
    var s = String(raw).trim();
    if (s === '' || s === '0') return null;
    if (/^-?\d+(?:\.\d+)?$/.test(s)) return s + 'px';
    return s;
  }

  // Build one field row and remember its input, so validation and submission
  // can read the value back without another DOM lookup.
  function buildField(field, idPrefix) {
    var inputId = idPrefix + '-' + field.key;
    var limit = FIELD_LIMITS[field.type] || 255;
    var input;

    if (field.type === 'textarea') {
      input = el('textarea', { className: 'ecf-textarea', maxLength: limit, id: inputId });
    } else {
      input = el('input', {
        className: 'ecf-input',
        type: field.type === 'email' ? 'email' : field.type === 'phone' ? 'tel' : 'text',
        maxLength: limit,
        autocomplete: AUTOCOMPLETE[field.key] || 'on',
        id: inputId,
      });
    }

    var labelChildren = [field.label];
    if (!field.required) {
      labelChildren.push(el('span', { className: 'ecf-optional' }, [' (optional)']));
    }

    var row = el('div', { className: 'ecf-field' }, [
      el('label', { className: 'ecf-label', htmlFor: inputId }, labelChildren),
      input,
    ]);

    return { row: row, input: input, field: field };
  }

  // Build the `<form class="ecf-wrap">` element with all fields, validation,
  // and submission wired up. Returns the form element so callers can decide
  // where to mount it (directly into the target, or inside an .ecf-page).
  //
  // `tokens` carries whichever identifier was configured; `fields` is the
  // definition fetched from /form/config, or FALLBACK_FIELDS.
  function buildForm(tokens, fields, theme, align, offset, host) {
    var errBox  = el('div', { className: 'ecf-error', style: 'display:none' });
    var honeypot = el('input', { type: 'text', tabIndex: -1, autocomplete: 'off' });
    var idPrefix = 'ecf-' + String(tokens.form || tokens.project || '').slice(0, 12);

    var built = [];
    for (var i = 0; i < fields.length; i++) {
      built.push(buildField(fields[i], idPrefix));
    }

    var submitBtn = el('button', { className: 'ecf-button', type: 'submit' }, ['Send message']);

    var children = [errBox];
    for (var j = 0; j < built.length; j++) {
      children.push(built[j].row);
    }
    children.push(el('div', { className: 'ecf-hp', 'aria-hidden': 'true' }, [
      el('label', null, ['Website', honeypot]),
    ]));
    children.push(submitBtn);

    var form = el('form', { className: 'ecf-wrap', noValidate: true }, children);
    form.setAttribute('data-theme', theme);
    form.setAttribute('data-align', align);

    function showError(msg) {
      errBox.textContent = msg;
      errBox.style.display = 'block';
    }
    function clearError() {
      errBox.textContent = '';
      errBox.style.display = 'none';
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      clearError();

      var payload = {};

      for (var k = 0; k < built.length; k++) {
        var entry = built[k];
        var value = (entry.input.value || '').trim();

        if (!value) {
          if (entry.field.required) return showError(entry.field.label + ' is required.');
          continue;
        }
        if (entry.field.type === 'email' && !EMAIL_RE.test(value)) {
          return showError(entry.field.label + ' must be a valid email address.');
        }
        if (entry.field.type === 'phone' && !PHONE_RE.test(value)) {
          return showError(entry.field.label + ' must be a valid phone number.');
        }
        payload[entry.field.key] = value;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending…';

      var body = { fields: payload, website: honeypot.value };
      if (tokens.form) body.form_token = tokens.form;
      else body.project_token = tokens.project;

      fetch(API_BASE.replace(/\/$/, '') + '/form/submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (json) {
          if (!json || json.success !== true) {
            throw new Error((json && json.error) || 'Submission failed. Please try again.');
          }
          // Swap the form out for the success state, preserving the .ecf-wrap
          // class on whatever element took its place so existing CSS still
          // applies. `host` is the element listeners (`easycontact:success`)
          // should fire from — the original mount target.
          var success = el('div', { className: 'ecf-wrap' }, [
            el('div', { className: 'ecf-success' }, ['✓ Thanks! Your message has been sent.']),
          ]);
          success.setAttribute('data-theme', theme);
          success.setAttribute('data-align', align);
          if (offset) success.style.setProperty('--ecf-offset-x', offset);
          if (form.parentNode) form.parentNode.replaceChild(success, form);
          host.dispatchEvent(new CustomEvent('easycontact:success', {
            bubbles: true,
            // projectToken is kept in the payload for listeners written against
            // the pre-forms release.
            detail: { projectToken: tokens.project, formToken: tokens.form, data: json.data },
          }));
        })
        .catch(function (e) {
          var msg = (e && e.message) || 'Submission failed. Please try again.';
          showError(msg);
          submitBtn.disabled = false;
          submitBtn.textContent = 'Send message';
          host.dispatchEvent(new CustomEvent('easycontact:error', {
            bubbles: true,
            detail: { projectToken: tokens.project, formToken: tokens.form, error: e, message: msg },
          }));
        });
    });

    return form;
  }

  function buildHeading(heading, description) {
    var children = [];
    if (heading) children.push(el('h1', { className: 'ecf-page-title' }, [heading]));
    if (description) children.push(el('p', { className: 'ecf-page-description' }, [description]));
    return el('header', { className: 'ecf-page-heading' }, children);
  }

  // Fetch a form's field definitions. Any failure resolves to the classic three
  // fields rather than rejecting — a contact form that renders is worth more
  // than one that is exactly right.
  function loadFields(tokens, done) {
    var query = tokens.form
      ? 'form_token=' + encodeURIComponent(tokens.form)
      : 'project_token=' + encodeURIComponent(tokens.project);

    fetch(API_BASE.replace(/\/$/, '') + '/form/config?' + query)
      .then(function (r) { return r.json(); })
      .then(function (json) {
        var fields = json && json.success === true && json.data && json.data.form
          ? json.data.form.fields
          : null;
        done(fields && fields.length ? fields : FALLBACK_FIELDS);
      })
      .catch(function () { done(FALLBACK_FIELDS); });
  }

  function mount(target, tokens) {
    var theme = normalizeTheme(target.getAttribute('data-easycontact-theme'));
    var layout = normalizeLayout(target.getAttribute('data-easycontact-layout'));
    var align = normalizeAlign(target.getAttribute('data-easycontact-align'));
    var offset = align === 'center' ? null : formatOffsetX(target.getAttribute('data-easycontact-offset-x'));

    target.innerHTML = '';
    target.setAttribute('data-theme', theme);

    // In page mode the CSS variable lives on the section so it cascades to
    // both the heading and the form. In inline mode the form is the root —
    // pass `offset` into buildForm so the inline style survives the
    // form→success swap on submission.
    var inlineOffset = layout === 'page' ? null : offset;

    if (layout === 'page') {
      target.classList.add('ecf-page');
      target.setAttribute('data-align', align);
      if (offset) target.style.setProperty('--ecf-offset-x', offset);
      var heading = target.getAttribute('data-easycontact-heading');
      var description = target.getAttribute('data-easycontact-description');
      if (heading || description) {
        target.appendChild(buildHeading(heading, description));
      }
    }

    // Placeholder rows while the definition is in flight, so the page does not
    // reflow around the form once it arrives.
    var placeholder = el('div', { className: 'ecf-wrap' }, [
      el('div', { className: 'ecf-loading' }, [
        el('span', { className: 'ecf-skeleton' }),
        el('span', { className: 'ecf-skeleton' }),
        el('span', { className: 'ecf-skeleton ecf-skeleton-tall' }),
      ]),
    ]);
    placeholder.setAttribute('data-theme', theme);
    placeholder.setAttribute('data-align', align);
    target.appendChild(placeholder);

    loadFields(tokens, function (fields) {
      var form = buildForm(tokens, fields, theme, align, inlineOffset, target);
      if (inlineOffset) form.style.setProperty('--ecf-offset-x', inlineOffset);
      if (placeholder.parentNode) placeholder.parentNode.replaceChild(form, placeholder);
      else target.appendChild(form);
    });
  }

  function mountAll() {
    injectStyles();
    // `data-easycontact` names a project and posts to its default form;
    // `data-easycontact-form` names one specific form. Both are supported —
    // the first is what is already pasted into live sites.
    var nodes = document.querySelectorAll('[data-easycontact], [data-easycontact-form]');
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      if (node.dataset.ecfMounted === '1') continue;

      var formToken = node.getAttribute('data-easycontact-form');
      var projectToken = node.getAttribute('data-easycontact');
      if (!formToken && !projectToken) continue;

      node.dataset.ecfMounted = '1';
      mount(node, { form: formToken || null, project: projectToken || null });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountAll);
  } else {
    mountAll();
  }

  window.EasyContactForm = { mount: mountAll };
})();
