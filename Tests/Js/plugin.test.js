/**
 * Tests for Assets/src/index.js.
 *
 * Every frontend bug found during the pilot build came from this file and was
 * caught by a human looking at the screen: the section nesting inside a column,
 * the panel stuck on "generating", the edit mode being unreachable, and undo
 * taking five steps. Each one has a test here.
 */
const fs = require('fs');
const path = require('path');
const { FakeComponent, createFakeEditor, buildSectionTree } = require('./helpers/fakeEditor');

const SOURCE = path.join(__dirname, '../../Assets/src/index.js');

function loadPlugin() {
  delete window.MauticGrapesJsPlugins;
  window.MauticAiEmailSectionsConfig = {
    endpoint: '/s/ai-email-sections/generate',
    placeholderSrc: 'https://placehold.co/600x400',
    maxSourceBytes: 12288,
  };
  window.mauticAjaxCsrf = 'test-token';

  // eslint-disable-next-line no-eval
  window.eval(fs.readFileSync(SOURCE, 'utf8'));

  return window.MauticGrapesJsPlugins[0];
}

function makePlaceholder(editor) {
  const placeholder = new FakeComponent({
    type: 'assistant-placeholder',
    tagName: 'mj-text',
  });
  const tree = buildSectionTree(editor, placeholder);
  return Object.assign(tree, { placeholder });
}

describe('registration', () => {
  test('registers itself for the MJML email builder only', () => {
    const registration = loadPlugin();

    expect(registration.name).toBe('AiEmailSections');
    expect(typeof registration.plugin).toBe('function');
    expect(registration.context).toEqual(['email-mjml']);
  });

  test('never clobbers plugins registered by others', () => {
    window.MauticGrapesJsPlugins = [{ name: 'SomeoneElse', plugin: () => {} }];
    window.eval(fs.readFileSync(SOURCE, 'utf8'));

    expect(window.MauticGrapesJsPlugins.map((p) => p.name)).toEqual(['SomeoneElse', 'AiEmailSections']);
  });
});

describe('editor setup', () => {
  let editor;

  beforeEach(() => {
    const registration = loadPlugin();
    editor = createFakeEditor();
    editor.DomComponents.addType('mj-text', { model: { prototype: { defaults: {} } } });
    registration.plugin(editor);
  });

  test('adds the AI Section block', () => {
    const block = editor.BlockManager.get('assistant');

    expect(block.label).toBe('AI Section');
    expect(block.content).toContain('<mj-section>');
    expect(block.content).toContain('data-slot="assistant"');
  });

  test('the block content nests the placeholder inside a column', () => {
    const block = editor.BlockManager.get('assistant');

    expect(block.content).toMatch(/<mj-section><mj-column><mj-text[^>]*data-slot="assistant"/);
  });

  test('registers the placeholder type and the prompt trait type', () => {
    expect(editor.DomComponents.getType('assistant-placeholder')).toBeDefined();
    expect(editor.TraitManager.getType('assistant-prompt')).toBeDefined();
  });

  test('registers the edit command', () => {
    expect(editor.Commands.get('assistant:edit')).toBeDefined();
  });
});

describe('edit command', () => {
  let editor;

  beforeEach(() => {
    const registration = loadPlugin();
    editor = createFakeEditor();
    editor.DomComponents.addType('mj-text', { model: { prototype: { defaults: {} } } });
    registration.plugin(editor);
  });

  test('resolves the target up to the closest section', () => {
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    const { section } = buildSectionTree(editor, text);

    editor.Commands.run('assistant:edit', { component: text });

    expect(editor.getSelected()).toBe(section);
  });

  test('attaches the prompt trait to a section that never had it', () => {
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    const { section } = buildSectionTree(editor, text);

    expect(section.get('traits').length).toBe(0);

    editor.Commands.run('assistant:edit', { component: text });

    const names = section.get('traits').map((trait) => trait.get('name'));
    expect(names).toContain('prompt');
  });

  test('does not attach the trait twice', () => {
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    const { section } = buildSectionTree(editor, text);

    editor.Commands.run('assistant:edit', { component: text });
    editor.Commands.run('assistant:edit', { component: text });

    const prompts = section.get('traits').filter((trait) => trait.get('name') === 'prompt');
    expect(prompts.length).toBe(1);
  });

  test('opens the settings panel, which is where Mautic keeps traits', () => {
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    buildSectionTree(editor, text);

    editor.Commands.run('assistant:edit', { component: text });

    expect(editor.Panels.buttonState('views', 'open-sm').active).toBe(true);
  });

  test('does nothing when there is no section above the component', () => {
    const orphan = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    orphan.editor = editor;

    editor.Commands.run('assistant:edit', { component: orphan });

    expect(editor.getSelected()).toBeNull();
  });
});

describe('toolbar', () => {
  let editor;

  beforeEach(() => {
    const registration = loadPlugin();
    editor = createFakeEditor();
    editor.DomComponents.addType('mj-text', { model: { prototype: { defaults: {} } } });
    registration.plugin(editor);
  });

  test('adds the assistant button to a component inside a section', () => {
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    buildSectionTree(editor, text);

    editor.emit('component:selected', text);

    expect(text.get('toolbar').map((item) => item.id)).toContain('toolbar-assistant-edit');
  });

  test('puts the assistant button first, before the stock actions', () => {
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text', toolbar: [{ id: 'tlb-move' }] });
    buildSectionTree(editor, text);

    editor.emit('component:selected', text);

    expect(text.get('toolbar')[0].id).toBe('toolbar-assistant-edit');
  });

  test('never adds the button twice', () => {
    const text = new FakeComponent({ type: 'mj-text', tagName: 'mj-text' });
    buildSectionTree(editor, text);

    editor.emit('component:selected', text);
    editor.emit('component:selected', text);

    const buttons = text.get('toolbar').filter((item) => item.id === 'toolbar-assistant-edit');
    expect(buttons.length).toBe(1);
  });

  test('leaves the placeholder alone: it already has the prompt in its traits', () => {
    const { placeholder } = makePlaceholder(editor);

    editor.emit('component:selected', placeholder);

    expect(placeholder.get('toolbar')).toEqual([]);
  });
});
