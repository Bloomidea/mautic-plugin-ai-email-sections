/**
 * Regression tests for the three bugs that reached the screen during the build.
 *
 * All of them lived in the success path, which is the expensive one to test:
 * it needs a canvas and a model. These drive the real trait UI against a fake
 * editor and a stubbed provider instead.
 */
const fs = require('fs');
const path = require('path');
const { FakeComponent, createFakeEditor, buildSectionTree } = require('./helpers/fakeEditor');

const SOURCE = path.join(__dirname, '../../Assets/src/index.js');
const GENERATED = '<mj-section background-color="#ffffff"><mj-column><mj-text>Hi</mj-text></mj-column></mj-section>';

function setup() {
  delete window.MauticGrapesJsPlugins;
  window.MauticAiEmailSectionsConfig = {
    endpoint: '/s/ai-email-sections/generate',
    placeholderSrc: 'https://placehold.co/600x400',
    maxSourceBytes: 12288,
  };
  window.mauticAjaxCsrf = 'test-token';

  // eslint-disable-next-line no-eval
  window.eval(fs.readFileSync(SOURCE, 'utf8'));

  const editor = createFakeEditor();
  editor.DomComponents.addType('mj-text', { model: { prototype: { defaults: {} } } });
  window.MauticGrapesJsPlugins[0].plugin(editor);

  return editor;
}

function stubProvider(payload, ok = true) {
  window.fetch = jest.fn(() =>
    Promise.resolve({
      ok,
      json: () => Promise.resolve(payload),
    })
  );
}

function openPromptUi(editor, component) {
  const traitType = editor.TraitManager.getType('assistant-prompt');
  const el = traitType.createInput({ component, trait: {} });
  document.body.innerHTML = '';
  document.body.appendChild(el);

  return {
    el,
    textarea: el.querySelector('.gjs-assistant__prompt'),
    button: el.querySelector('.gjs-assistant__run'),
    status: el.querySelector('.gjs-assistant__status'),
    label: el.querySelector('.gjs-assistant__target'),
    theme: el.querySelector('.gjs-assistant__theme'),
  };
}

function flush() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('create mode', () => {
  let editor;
  let placeholder;
  let section;
  let body;

  beforeEach(() => {
    editor = setup();
    placeholder = new FakeComponent({ type: 'assistant-placeholder', tagName: 'mj-text' });
    const tree = buildSectionTree(editor, placeholder);
    section = tree.section;
    body = tree.body;
    stubProvider({ mjml: GENERATED, attempts: 1, warnings: [] });
  });

  test('replaces the whole section, never nesting one inside a column', async () => {
    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();
    await flush();

    // The placeholder's section is gone and the generated one took its slot.
    expect(body.components().indexOf(section)).toBe(-1);
    expect(body.components().length).toBe(1);
    expect(body.components().at(0).toHTML()).toBe(GENERATED);
  });

  test('keeps the exact position within the parent', async () => {
    const first = new FakeComponent({ type: 'mj-section', tagName: 'mj-section' });
    first.editor = editor;
    body.components().add(first, { at: 0 });
    first.parentComponent = body;

    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();
    await flush();

    // The generated section must land where the placeholder was: index 1.
    expect(body.components().at(0)).toBe(first);
    expect(body.components().at(1).toHTML()).toBe(GENERATED);
  });

  test('clears the loading state so the panel is not stuck on generating', async () => {
    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();

    expect(ui.button.disabled).toBe(true);
    expect(ui.status.textContent).toBe('Generating...');

    await flush();

    expect(ui.button.disabled).toBe(false);
    expect(ui.status.textContent).toBe('');
  });

  test('selects the generated section so the user lands on what was made', async () => {
    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();
    await flush();

    expect(editor.getSelected().toHTML()).toBe(GENERATED);
  });

  test('collapses the whole replacement into a single undo step', async () => {
    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();
    await flush();

    const fusions = editor.undoStack.map((entry) => entry.get('magicFusionIndex'));
    const distinct = Array.from(new Set(fusions));

    expect(fusions.length).toBeGreaterThan(1);
    expect(distinct.length).toBe(1);
  });

  test('sends create mode without a source section', async () => {
    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();
    await flush();

    const body = JSON.parse(window.fetch.mock.calls[0][1].body);
    expect(body.mode).toBe('create');
    expect(body.source).toBeUndefined();
  });
});

describe('language hint', () => {
  /**
   * The system prompt and the few-shot examples are English while the copy must
   * follow the request. Telling the person that up front costs one line and
   * avoids a prompt written in the wrong language.
   */
  test('tells the person which language drives the copy, when creating', () => {
    const editor = setup();
    const placeholder = new FakeComponent({ type: 'assistant-placeholder', tagName: 'mj-text' });
    buildSectionTree(editor, placeholder);

    const ui = openPromptUi(editor, placeholder);
    const hint = ui.el.querySelector('.gjs-assistant__hint--language');

    expect(hint).not.toBeNull();
    expect(hint.textContent).toContain('language');
  });

  /**
   * In edit mode the rule is the opposite: keep whatever language the section
   * is already written in. The create hint would say the wrong thing.
   */
  test('does not show it when editing an existing section', () => {
    const editor = setup();
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    const section = buildSectionTree(editor, text).section;

    const ui = openPromptUi(editor, section);

    expect(ui.el.querySelector('.gjs-assistant__hint--language')).toBeNull();
  });
});

describe('edit mode', () => {
  let editor;
  let section;

  beforeEach(() => {
    editor = setup();
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    section = buildSectionTree(editor, text).section;
    stubProvider({ mjml: GENERATED, attempts: 1, warnings: [] });
  });

  test('names the target so there is no surprise about what changes', () => {
    const ui = openPromptUi(editor, section);

    expect(ui.label.textContent).toContain('Editing: section');
  });

  test('sends the current section as the source', async () => {
    const ui = openPromptUi(editor, section);
    ui.textarea.value = 'make the background cream';
    ui.button.click();
    await flush();

    const payload = JSON.parse(window.fetch.mock.calls[0][1].body);
    expect(payload.mode).toBe('edit');
    expect(payload.source).toBe('<mj-section>original</mj-section>');
  });

  test('refuses a section larger than the configured limit', () => {
    section.html = '<mj-section>' + 'a'.repeat(13 * 1024) + '</mj-section>';

    const ui = openPromptUi(editor, section);

    expect(ui.button.disabled).toBe(true);
    expect(ui.status.textContent).toContain('too large');
  });
});

describe('failures', () => {
  let editor;
  let placeholder;
  let section;
  let body;

  beforeEach(() => {
    editor = setup();
    placeholder = new FakeComponent({ type: 'assistant-placeholder', tagName: 'mj-text' });
    const tree = buildSectionTree(editor, placeholder);
    section = tree.section;
    body = tree.body;
  });

  test('leaves the target exactly as it was', async () => {
    stubProvider({ error: 'validation_failed', message: 'Could not generate a valid block.' }, false);

    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'something impossible';
    ui.button.click();
    await flush();

    expect(body.components().at(0)).toBe(section);
    expect(body.components().length).toBe(1);
  });

  test('shows the server message and frees the button for another try', async () => {
    stubProvider({ error: 'validation_failed', message: 'Could not generate a valid block.' }, false);

    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'something impossible';
    ui.button.click();
    await flush();

    expect(ui.status.textContent).toBe('Could not generate a valid block.');
    expect(ui.status.className).toContain('is-error');
    expect(ui.button.disabled).toBe(false);
  });

  test('keeps the prompt written so it does not have to be retyped', async () => {
    stubProvider({ error: 'validation_failed', message: 'nope' }, false);

    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'something impossible';
    ui.textarea.dispatchEvent(new window.Event('input', { bubbles: true }));
    ui.button.click();
    await flush();

    expect(placeholder.get('assistantPrompt')).toBe('something impossible');
  });

  test('does not call the provider with an empty prompt', () => {
    stubProvider({ mjml: GENERATED });

    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = '   ';
    ui.button.click();

    expect(window.fetch).not.toHaveBeenCalled();
    expect(ui.status.textContent).toBe('Describe what you want to generate.');
  });
});

describe('request shape', () => {
  /**
   * The controller has always read payload.emailId and the frontend has never
   * sent it, so the telemetry column was silently null on every row. Without it
   * a generation cannot be traced back to the email it landed in.
   */
  test('carries the email id, taken from the edit URL', async () => {
    const editor = setup();
    const placeholder = new FakeComponent({ type: 'assistant-placeholder', tagName: 'mj-text' });
    buildSectionTree(editor, placeholder);
    stubProvider({ mjml: GENERATED, attempts: 1, warnings: [] });

    window.history.pushState({}, '', '/s/emails/edit/42');

    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();
    await flush();

    const body = JSON.parse(window.fetch.mock.calls[0][1].body);
    expect(body.emailId).toBe(42);
  });

  test('omits the email id on an email that has never been saved', async () => {
    const editor = setup();
    const placeholder = new FakeComponent({ type: 'assistant-placeholder', tagName: 'mj-text' });
    buildSectionTree(editor, placeholder);
    stubProvider({ mjml: GENERATED, attempts: 1, warnings: [] });

    window.history.pushState({}, '', '/s/emails/new');

    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();
    await flush();

    const body = JSON.parse(window.fetch.mock.calls[0][1].body);
    expect(body.emailId).toBeUndefined();
  });

  test('carries the headers Mautic needs to even run its CSRF guard', async () => {
    const editor = setup();
    const placeholder = new FakeComponent({ type: 'assistant-placeholder', tagName: 'mj-text' });
    buildSectionTree(editor, placeholder);
    stubProvider({ mjml: GENERATED, attempts: 1, warnings: [] });

    const ui = openPromptUi(editor, placeholder);
    ui.textarea.value = 'a welcome banner';
    ui.button.click();
    await flush();

    const options = window.fetch.mock.calls[0][1];
    expect(options.headers['X-Requested-With']).toBe('XMLHttpRequest');
    expect(options.headers['X-CSRF-Token']).toBe('test-token');
    expect(options.credentials).toBe('same-origin');
  });
});

describe('theme selector', () => {
  function setupWithThemes(themes, preselected) {
    const editor = setup();
    window.MauticAiEmailSectionsConfig.themes = themes;
    window.MauticAiEmailSectionsConfig.theme = preselected;

    return editor;
  }

  test('offers every theme and opens on the one the server preselected', () => {
    const editor = setupWithThemes({ default: 'Default', brienz: 'Brienz', attract: 'Attract' }, 'brienz');
    const ui = openPromptUi(editor, new FakeComponent({ type: 'mj-text' }));

    expect(Array.from(ui.theme.options).map((o) => o.value)).toEqual(['default', 'brienz', 'attract']);
    expect(ui.theme.value).toBe('brienz');
  });

  test('sends the chosen theme with the request', async () => {
    const editor = setupWithThemes({ default: 'Default', brienz: 'Brienz' }, 'default');
    const placeholder = new FakeComponent({ type: 'assistant-placeholder', tagName: 'mj-text' });
    buildSectionTree(editor, placeholder);
    stubProvider({ mjml: GENERATED, attempts: 1, warnings: [] });

    const ui = openPromptUi(editor, placeholder);
    ui.theme.value = 'brienz';
    ui.textarea.value = 'a hero';
    ui.button.click();
    await flush();

    const body = JSON.parse(window.fetch.mock.calls[0][1].body);
    expect(body.theme).toBe('brienz');
  });

  test('says what the theme actually controls, and where the preselection came from', () => {
    const editor = setupWithThemes({ default: 'Default', blank: 'Blank' }, 'blank');
    const ui = openPromptUi(editor, new FakeComponent({ type: 'mj-text' }));

    expect(ui.el.querySelector('.gjs-assistant__hint--theme').textContent).toContain('Colours');
    expect(ui.theme.title).toContain("email's template");
  });

  test('labels the field as a label rather than as another hint', () => {
    const editor = setupWithThemes({ default: 'Default', blank: 'Blank' }, 'blank');
    const ui = openPromptUi(editor, new FakeComponent({ type: 'mj-text' }));

    expect(ui.el.querySelector('.gjs-assistant__label').textContent).toBe('Theme');
  });

  test('stays out of the way when there is nothing to choose between', () => {
    const editor = setupWithThemes({ default: 'Default' }, 'default');
    const ui = openPromptUi(editor, new FakeComponent({ type: 'mj-text' }));

    expect(ui.theme).toBeNull();
  });
});
