/**
 * Minimal stand-ins for the GrapesJS pieces the plugin touches.
 *
 * These are fakes, not mocks: they behave like the real thing (a component
 * tree you can walk, a trait collection you can append to, an undo stack whose
 * entries carry a magicFusionIndex), so tests assert on real outcomes rather
 * than on calls having happened.
 */

class FakeCollection extends Array {
  at(index) {
    return this[index];
  }

  add(item, options) {
    if (options && typeof options.at === 'number') {
      this.splice(options.at, 0, item);
    } else {
      this.push(item);
    }
    return item;
  }

  remove(item) {
    const index = this.indexOf(item);
    if (index !== -1) this.splice(index, 1);
    return item;
  }
}

class FakeComponent {
  constructor(props = {}, children = []) {
    this.props = Object.assign({ traits: new FakeCollection(), toolbar: [] }, props);
    this.children = new FakeCollection(...children);
    this.children.forEach((child) => {
      child.parentComponent = this;
    });
    this.parentComponent = null;
    this.classes = [];
    this.html = props.html || '';
  }

  get(key) {
    return this.props[key];
  }

  set(key, value) {
    this.props[key] = value;
    return this;
  }

  parent() {
    return this.parentComponent;
  }

  index() {
    if (!this.parentComponent) return 0;
    return this.parentComponent.children.indexOf(this);
  }

  components() {
    return this.children;
  }

  addTrait(trait, options) {
    const model = {
      values: Object.assign({}, trait),
      get(key) {
        return this.values[key];
      },
      set(key, value) {
        this.values[key] = value;
      },
    };
    this.props.traits.add(model, options);
    return model;
  }

  addClass(name) {
    this.classes.push(name);
  }

  removeClass(name) {
    this.classes = this.classes.filter((c) => c !== name);
  }

  toHTML() {
    return this.html;
  }

  /** Every appended fragment becomes one child plus one undo entry per node. */
  append(mjml, options) {
    const created = new FakeComponent({ type: 'mj-section', tagName: 'mj-section', html: mjml });
    created.parentComponent = this;
    this.children.add(created, options);

    // Mimic GrapesJS: appending a section records several undo entries.
    for (let i = 0; i < 4; i++) {
      this.editor.undoStack.push(makeUndoEntry(this.editor.undoStack.length));
    }

    return [created];
  }

  remove() {
    if (this.parentComponent) {
      this.parentComponent.children.remove(this);
    }
    this.editor && this.editor.undoStack.push(makeUndoEntry(this.editor.undoStack.length));
    return this;
  }

  find(selector) {
    const type = (selector.match(/data-gjs-type=([^\]]+)/) || [])[1];
    const out = [];
    const walk = (node) => {
      node.children.forEach((child) => {
        if (child.get('type') === type) out.push(child);
        walk(child);
      });
    };
    walk(this);
    return out;
  }
}

function makeUndoEntry(index) {
  const values = { magicFusionIndex: index };
  return {
    get: (key) => values[key],
    set: (key, value) => {
      values[key] = value;
    },
  };
}

function createFakeEditor() {
  const blocks = {};
  const types = {};
  const traitTypes = {};
  const commands = {};
  const listeners = {};
  const panelButtons = {};

  const editor = {
    undoStack: [],
    selected: null,

    BlockManager: {
      add: (id, def) => {
        blocks[id] = def;
      },
      get: (id) => blocks[id],
      all: () => blocks,
    },

    DomComponents: {
      addType: (id, def) => {
        types[id] = def;
      },
      getType: (id) => types[id],
    },

    TraitManager: {
      addType: (id, def) => {
        traitTypes[id] = def;
      },
      getType: (id) => traitTypes[id],
    },

    Commands: {
      add: (id, def) => {
        commands[id] = def;
      },
      run: (id, options) => commands[id] && commands[id].run(editor, null, options),
      get: (id) => commands[id],
    },

    Panels: {
      getButton: (panel, id) => {
        panelButtons[panel + ':' + id] = panelButtons[panel + ':' + id] || {
          active: false,
          set(key, value) {
            this[key] = value;
          },
        };
        return panelButtons[panel + ':' + id];
      },
      buttonState: (panel, id) => panelButtons[panel + ':' + id],
    },

    UndoManager: {
      getStack: () => editor.undoStack,
    },

    on: (event, handler) => {
      listeners[event] = listeners[event] || [];
      listeners[event].push(handler);
    },

    emit: (event, payload) => {
      (listeners[event] || []).forEach((handler) => handler(payload));
    },

    select: (component) => {
      editor.selected = component;
    },

    getSelected: () => editor.selected,
  };

  return editor;
}

/** Builds mj-section > mj-column > child, wired to the editor. */
function buildSectionTree(editor, child) {
  const column = new FakeComponent({ type: 'mj-column', tagName: 'mj-column' }, [child]);
  const section = new FakeComponent({ type: 'mj-section', tagName: 'mj-section', html: '<mj-section>original</mj-section>' }, [column]);
  const body = new FakeComponent({ type: 'mj-body', tagName: 'mj-body' }, [section]);

  [body, section, column, child].forEach((component) => {
    component.editor = editor;
  });

  return { body, section, column, child };
}

module.exports = { FakeComponent, FakeCollection, createFakeEditor, buildSectionTree };
