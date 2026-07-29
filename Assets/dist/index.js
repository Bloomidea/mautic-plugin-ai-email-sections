/**
 * AI Email Sections, a plugin for Mautic's MJML email builder.
 *
 * Registers itself in window.MauticGrapesJsPlugins, which the
 * GrapesJsBuilderBundle BuilderService reads when the builder boots (initGrapesJS).
 *
 * It imports nothing. That is deliberate: with no imports there is no build step,
 * the file is served as-is, and the plugin receives `editor` as an argument.
 */
(function () {
  'use strict';

  var SLOT = 'assistant';
  var PLACEHOLDER_TYPE = 'assistant-placeholder';
  var TOOLBAR_ID = 'toolbar-assistant-edit';
  var TRAIT_TYPE = 'assistant-prompt';
  var TRAIT_NAME = 'prompt';
  var CATEGORY_ID = 'ai';

  /**
   * Mautic exposes no hidden field with the email id, so the edit URL is the
   * only source. A brand new email has no id yet, and that is not an error:
   * telemetry simply has nothing to correlate it with until it is saved.
   */
  function currentEmailId() {
    var match = /\/emails\/edit\/(\d+)/.exec(window.location.pathname);

    return match ? parseInt(match[1], 10) : null;
  }

  function config() {
    return window.MauticAiEmailSectionsConfig || {};
  }

  function t(key, fallback) {
    if (window.Mautic && typeof window.Mautic.translate === 'function') {
      var translated = window.Mautic.translate(key);
      if (translated && translated !== key) {
        return translated;
      }
    }
    return fallback;
  }

  /* ------------------------------------------------------------------ API */

  function requestGeneration(payload) {
    return fetch(config().endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        // Without this header Mautic's CSRF guard does not even run.
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': window.mauticAjaxCsrf || '',
      },
      body: JSON.stringify(payload),
    }).then(function (response) {
      return response
        .json()
        .catch(function () {
          return {};
        })
        .then(function (data) {
          if (!response.ok) {
            var error = new Error(data.message || t('mautic.aiemailsections.error.generic', 'The block could not be generated.'));
            error.code = data.error;
            throw error;
          }
          return data;
        });
    });
  }

  /* -------------------------------------------------------------- helpers */

  /** Walks up to the closest ancestor mj-section. */
  function resolveSection(component) {
    var current = component;
    while (current) {
      if (current.get('tagName') === 'mj-section' || current.get('type') === 'mj-section') {
        return current;
      }
      current = current.parent();
    }
    return null;
  }

  function isAssistantPlaceholder(component) {
    return !!component && component.get('type') === PLACEHOLDER_TYPE;
  }

  /** Adds the prompt trait to a section that does not have it yet. */
  function addPromptTrait(section) {
    var traits = section.get('traits');
    var already = traits.filter
      ? traits.filter(function (trait) {
          return trait.get('name') === TRAIT_NAME;
        }).length
      : 0;

    if (!already) {
      section.addTrait({ type: TRAIT_TYPE, name: TRAIT_NAME }, { at: 0 });
    }
  }

  /**
   * The Mautic preset exposes no Trait Manager button: traits live inside the
   * Style Manager panel, under Settings. Opening "open-tm" silently does
   * nothing.
   */
  function openSettingsPanel(editor) {
    var button = editor.Panels.getButton('views', 'open-sm');
    if (button) {
      button.set('active', true);
    }
  }

  /**
   * Replaces a component with MJML, keeping its exact position within the parent.
   * Both operations happen in the same tick so the UndoManager groups them.
   */
  function replaceWithMjml(editor, target, mjml) {
    var parent = target.parent();
    if (!parent) {
      return null;
    }

    var undoManager = editor.UndoManager;
    var stack = undoManager.getStack();
    var firstNewEntry = stackLength(stack);

    var index = target.index();
    var added = parent.append(mjml, { at: index });
    target.remove();

    coalesceUndoEntries(stack, firstNewEntry);

    return Array.isArray(added) ? added[0] : added;
  }

  function stackLength(stack) {
    return typeof stack.length === 'number' ? stack.length : stack.models.length;
  }

  function stackAt(stack, index) {
    return typeof stack.at === 'function' ? stack.at(index) : stack[index];
  }

  /**
   * Collapses one generation into a single undo step.
   *
   * Appending a section produces one undo entry per component created (section,
   * column, every text and button) plus one for removing the target, so a
   * single generation cost five undos. Entries sharing a magicFusionIndex are
   * undone together, which is how GrapesJS groups same-tick operations
   * internally. There is no public API for this.
   */
  function coalesceUndoEntries(stack, from) {
    var total = stackLength(stack);
    if (total <= from) {
      return;
    }

    var first = stackAt(stack, from);
    if (!first || typeof first.get !== 'function') {
      return;
    }

    var fusion = first.get('magicFusionIndex');
    if (fusion === undefined || fusion === null) {
      return;
    }

    for (var i = from + 1; i < total; i++) {
      var entry = stackAt(stack, i);
      if (entry && typeof entry.set === 'function') {
        entry.set('magicFusionIndex', fusion);
      }
    }
  }

  /* ------------------------------------------------------------ trait UI */

  function buildTraitUi(editor, component) {
    var wrapper = document.createElement('div');
    wrapper.className = 'gjs-assistant';

    var editing = !isAssistantPlaceholder(component);

    // Always operate at mj-section level, in both modes. The model returns a
    // whole section, and the placeholder is an mj-text inside an mj-column, so
    // replacing the placeholder itself would nest a section inside a column,
    // which is invalid MJML.
    var target = resolveSection(component);

    var label = document.createElement('div');
    label.className = 'gjs-assistant__target';

    var textarea = document.createElement('textarea');
    textarea.className = 'gjs-assistant__prompt';
    textarea.rows = 4;
    textarea.placeholder = editing
      ? t('mautic.aiemailsections.placeholder.edit', 'What do you want to change in this section?')
      : t('mautic.aiemailsections.placeholder.create', 'Describe the block you want to generate.');
    textarea.value = component.get('assistantPrompt') || '';

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'gjs-assistant__run';
    button.textContent = t('mautic.aiemailsections.generate', 'Generate');

    var status = document.createElement('div');
    status.className = 'gjs-assistant__status';

    // The theme decides the colours, fonts and spacing the generated section
    // uses. It opens on the theme matching this email's template when a file of
    // that name exists, and on the configured one otherwise. Hidden when there
    // is nothing to choose between.
    var themes = config().themes || {};
    var themeIds = Object.keys(themes);
    var themeSelect = null;

    if (themeIds.length > 1) {
      themeSelect = document.createElement('select');
      themeSelect.className = 'gjs-assistant__theme';
      themeSelect.title = t(
        'mautic.aiemailsections.theme.tooltip',
        "Opens on the theme matching this email's template when there is one, and on the configured default otherwise."
      );

      themeIds.forEach(function (id) {
        var option = document.createElement('option');
        option.value = id;
        option.textContent = themes[id];
        themeSelect.appendChild(option);
      });

      themeSelect.value = component.get('assistantTheme') || config().theme || 'default';

      themeSelect.addEventListener('change', function () {
        component.set('assistantTheme', themeSelect.value, { silent: true });
      });
    }

    wrapper.appendChild(label);
    wrapper.appendChild(textarea);

    // Only on create. When editing, the rule is the opposite: the copy keeps
    // whatever language the existing section is written in.
    if (!editing) {
      var hint = document.createElement('div');
      hint.className = 'gjs-assistant__hint gjs-assistant__hint--language';
      hint.textContent = t(
        'mautic.aiemailsections.hint.language',
        'Write your prompt in the language you want the copy in.'
      );
      wrapper.appendChild(hint);
    }

    if (themeSelect) {
      var themeLabel = document.createElement('div');
      themeLabel.className = 'gjs-assistant__label';
      themeLabel.textContent = t('mautic.aiemailsections.theme', 'Theme');

      var themeHint = document.createElement('div');
      themeHint.className = 'gjs-assistant__hint gjs-assistant__hint--theme';
      themeHint.textContent = t(
        'mautic.aiemailsections.theme.hint',
        'Colours, fonts and spacing for the generated section.'
      );

      wrapper.appendChild(themeLabel);
      wrapper.appendChild(themeSelect);
      wrapper.appendChild(themeHint);
    }

    wrapper.appendChild(button);
    wrapper.appendChild(status);

    function setStatus(message, kind) {
      status.textContent = message || '';
      status.className = 'gjs-assistant__status' + (kind ? ' is-' + kind : '');
    }

    if (editing) {
      if (!target) {
        label.textContent = t('mautic.aiemailsections.target.none', 'Select a section.');
        button.disabled = true;
      } else {
        var position = target.index() + 1;
        label.textContent = t('mautic.aiemailsections.target.editing', 'Editing: section') + ' ' + position;

        var sourceSize = (target.toHTML() || '').length;
        var maxBytes = config().maxSourceBytes || 12288;

        if (sourceSize > maxBytes) {
          button.disabled = true;
          setStatus(
            t(
              'mautic.aiemailsections.error.source_too_large',
              'This section is too large to edit in one go. Select a smaller part.'
            ),
            'error'
          );
        }
      }
    } else {
      label.textContent = t('mautic.aiemailsections.target.new', 'New block');
    }

    // The prompt survives failures: the user does not have to retype it.
    textarea.addEventListener('input', function () {
      component.set('assistantPrompt', textarea.value, { silent: true });
    });

    button.addEventListener('click', function () {
      var prompt = textarea.value.trim();

      if (!prompt) {
        setStatus(t('mautic.aiemailsections.error.empty', 'Describe what you want to generate.'), 'error');
        return;
      }

      if (!target) {
        return;
      }

      button.disabled = true;
      setStatus(t('mautic.aiemailsections.loading', 'Generating...'), 'loading');

      if (!editing) {
        component.addClass('gjs-assistant--loading');
      }

      var payload = { mode: editing ? 'edit' : 'create', prompt: prompt };

      if (themeSelect) {
        payload.theme = themeSelect.value;
      }
      var emailId = currentEmailId();

      if (emailId !== null) {
        payload.emailId = emailId;
      }

      if (editing) {
        payload.source = target.toHTML();
      }

      requestGeneration(payload)
        .then(function (data) {
          // On success the target is replaced in its exact position.
          var replaced = replaceWithMjml(editor, target, data.mjml);

          // Clear the loading state before anything else. The trait panel keeps
          // its DOM even after the component it was built for is removed, so
          // skipping this leaves it stuck on "generating" with a dead button.
          button.disabled = false;
          setStatus('');
          component.removeClass('gjs-assistant--loading');

          // Selecting the result rebuilds the panel for the new section, which
          // also puts the user straight into edit mode on what was just made.
          if (replaced) {
            editor.select(replaced);
          }

          if (data.warnings && data.warnings.length) {
            window.setTimeout(function () {
              alertWarnings(data.warnings);
            }, 0);
          }
        })
        .catch(function (error) {
          // On any error the target is left exactly as it was.
          button.disabled = false;
          component.removeClass('gjs-assistant--loading');
          setStatus(error.message, 'error');
        });
    });

    return wrapper;
  }

  function alertWarnings(warnings) {
    if (window.Mautic && typeof window.Mautic.addInfoFlashMessage === 'function') {
      window.Mautic.addInfoFlashMessage(warnings.join(' '));
      return;
    }
    /* eslint-disable-next-line no-console */
    console.warn('[AI Email Sections]', warnings.join(' '));
  }

  /* ------------------------------------------------------------- plugin */

  function AiEmailSections(editor) {
    var dc = editor.DomComponents;

    /* Trait with textarea, button and status line. */
    editor.TraitManager.addType('assistant-prompt', {
      noLabel: true,
      createInput: function (options) {
        return buildTraitUi(editor, options.component);
      },
    });

    /* Placeholder dropped on the canvas when the block is dragged in. */
    var baseText = dc.getType('mj-text');

    if (baseText) {
      dc.addType(PLACEHOLDER_TYPE, {
        isComponent: function (el) {
          if (el && typeof el.getAttribute === 'function' && el.getAttribute('data-slot') === SLOT) {
            return { type: PLACEHOLDER_TYPE };
          }
          return false;
        },
        model: {
          defaults: Object.assign({}, baseText.model.prototype.defaults, {
            name: t('mautic.aiemailsections.block.label', 'AI Section'),
            tagName: 'mj-text',
            draggable: '[data-gjs-type=mj-column]',
            droppable: false,
            editable: false,
            stylable: false,
            propagate: ['droppable', 'editable'],
            attributes: { 'data-slot': SLOT },
            traits: [{ type: TRAIT_TYPE, name: TRAIT_NAME }],
          }),
        },
        view: {
          attributes: { style: 'pointer-events: all;' },
        },
      });
    }

    /* Block in the left-hand panel. */
    editor.BlockManager.add('assistant', {
      label: t('mautic.aiemailsections.block.label', 'AI Section'),
      // Categories render in registration order and this plugin loads last, so
      // without an order the block sits below the fold. GrapesJS applies this
      // as a CSS flex order, which reorders the panel visually while leaving
      // the DOM in insertion order.
      category: {
        id: CATEGORY_ID,
        label: t('mautic.aiemailsections.block.category', 'AI'),
        order: -10,
      },
      media:
        '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M7.5 5.6 10 7 8.6 4.5 10 2 7.5 3.4 5 2l1.4 2.5L5 7zm12 9.8L17 14l1.4 2.5L17 19l2.5-1.4L22 19l-1.4-2.5L22 14zM22 2l-2.5 1.4L17 2l1.4 2.5L17 7l2.5-1.4L22 7l-1.4-2.5zm-7.6 8.3-2.7-2.7a1 1 0 0 0-1.4 0L2.3 15.6a1 1 0 0 0 0 1.4l2.7 2.7a1 1 0 0 0 1.4 0l8-8a1 1 0 0 0 0-1.4z"/></svg>',
      content:
        '<mj-section><mj-column><mj-text data-slot="' +
        SLOT +
        '" padding="24px" align="center" color="#777777">' +
        t('mautic.aiemailsections.placeholder.empty', 'Describe the block in the panel on the right.') +
        '</mj-text></mj-column></mj-section>',
      select: true,
      activate: true,
    });

    /* Command behind the edit button on the selected component's toolbar. */
    editor.Commands.add('assistant:edit', {
      run: function (ed, sender, options) {
        var component = (options && options.component) || ed.getSelected();
        var section = resolveSection(component);

        if (!section) {
          return;
        }

        // A section the assistant did not create carries only the stock traits
        // (id, title, full width), so the prompt UI has nowhere to render.
        // Attach it on demand, which keeps every other section untouched.
        addPromptTrait(section);

        ed.select(section);
        openSettingsPanel(ed);
      },
    });

    /* The button is added to any component that lives inside a section. */
    editor.on('component:selected', function (component) {
      if (!component || isAssistantPlaceholder(component)) {
        return;
      }

      var section = resolveSection(component);

      // The whole mj-body selected is not a valid target.
      if (!section || !section.parent()) {
        return;
      }

      var toolbar = component.get('toolbar') || [];

      if (toolbar.some(function (item) { return item.id === TOOLBAR_ID; })) {
        return;
      }

      toolbar.unshift({
        id: TOOLBAR_ID,
        command: 'assistant:edit',
        attributes: {
          class: 'fa fa-magic',
          title: t('mautic.aiemailsections.toolbar.edit', 'Edit with the assistant'),
        },
      });

      component.set('toolbar', toolbar);
    });

    /*
     * Dropping the block should land the user on the prompt, not leave them
     * hunting for it. Select the placeholder and open the panel that holds the
     * trait, so the flow is: drag, type, generate.
     */
    editor.on('component:add', function (component) {
      var placeholder = isAssistantPlaceholder(component)
        ? component
        : (component.find && component.find('[data-gjs-type=' + PLACEHOLDER_TYPE + ']')[0]);

      if (!placeholder) {
        return;
      }

      editor.select(placeholder);
      openSettingsPanel(editor);
    });
  }

  if (!window.MauticGrapesJsPlugins) {
    window.MauticGrapesJsPlugins = [];
  }

  window.MauticGrapesJsPlugins.push({
    name: 'AiEmailSections',
    plugin: AiEmailSections,
    context: ['email-mjml'],
    pluginOptions: {},
  });
})();
