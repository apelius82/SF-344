// assets/js/modules/markdown-toolbar.js

function normalizeLineEndings(value) {
    return String(value || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

function dispatchNativeInput(textarea) {
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

function getSelectedLineRange(value, selectionStart, selectionEnd) {
    const normalizedValue = normalizeLineEndings(value);

    let lineStart = normalizedValue.lastIndexOf('\n', Math.max(0, selectionStart - 1));
    lineStart = lineStart === -1 ? 0 : lineStart + 1;

    let lineEnd = normalizedValue.indexOf('\n', selectionEnd);
    lineEnd = lineEnd === -1 ? normalizedValue.length : lineEnd;

    return {
        lineStart,
        lineEnd
    };
}

function replaceSelectedLines(textarea, transformer) {
    const value = normalizeLineEndings(textarea.value);
    const selectionStart = textarea.selectionStart || 0;
    const selectionEnd = textarea.selectionEnd || selectionStart;

    const range = getSelectedLineRange(value, selectionStart, selectionEnd);

    const before = value.slice(0, range.lineStart);
    const selectedBlock = value.slice(range.lineStart, range.lineEnd);
    const after = value.slice(range.lineEnd);

    const lines = selectedBlock.split('\n');
    const transformedLines = lines.map(transformer);
    const newBlock = transformedLines.join('\n');

    textarea.value = before + newBlock + after;

    const newSelectionStart = range.lineStart;
    const newSelectionEnd = range.lineStart + newBlock.length;

    textarea.focus();
    textarea.setSelectionRange(newSelectionStart, newSelectionEnd);

    dispatchNativeInput(textarea);
}

function addBulletList(textarea) {
    replaceSelectedLines(textarea, (line) => {
        if (line.trim() === '') {
            return '• ';
        }

        if (/^\s*[-•·*]\s*/.test(line)) {
            return line;
        }

        return '• ' + line;
    });
}

function removeBulletList(textarea) {
    replaceSelectedLines(textarea, (line) => {
        return line.replace(/^(\s*)[-•·*]\s*/, '$1');
    });
}

function createButton(label, title, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'sf-bullet-toolbar-btn';
    button.innerHTML = label;
    button.title = title;
    button.setAttribute('aria-label', title);

    button.addEventListener('click', (event) => {
        event.preventDefault();
        onClick();
    });

    return button;
}

function handleTextareaEnter(textarea, event) {
    if (event.key !== 'Enter') {
        return;
    }

    const value = normalizeLineEndings(textarea.value);
    const selectionStart = textarea.selectionStart || 0;

    const lineStart = value.lastIndexOf('\n', Math.max(0, selectionStart - 1)) + 1;
    const currentLine = value.slice(lineStart, selectionStart);

if (!/^\s*[-•·*]\s*/.test(currentLine)) {
    return;
}

    event.preventDefault();

if (/^\s*[-•·*]\s*$/.test(currentLine)) {
        const beforeLine = value.slice(0, lineStart);
        const afterCaret = value.slice(selectionStart);

        textarea.value = beforeLine + afterCaret.replace(/^\n?/, '');

        textarea.focus();
        textarea.setSelectionRange(lineStart, lineStart);
        dispatchNativeInput(textarea);
        return;
    }

const indentMatch = currentLine.match(/^(\s*)[-•·*]\s*/);
const prefix = indentMatch ? indentMatch[1] + '• ' : '• ';

    const before = value.slice(0, selectionStart);
    const after = value.slice(textarea.selectionEnd || selectionStart);
    const inserted = '\n' + prefix;

    textarea.value = before + inserted + after;

    const caretPosition = before.length + inserted.length;
    textarea.focus();
    textarea.setSelectionRange(caretPosition, caretPosition);

    dispatchNativeInput(textarea);
}

function createBulletEditor(textarea) {
    if (!textarea || textarea.dataset.bulletEditorReady === '1') {
        return;
    }

    textarea.dataset.bulletEditorReady = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'sf-bullet-editor-wrap';

    const toolbar = document.createElement('div');
    toolbar.className = 'sf-bullet-toolbar';
    toolbar.setAttribute('aria-label', textarea.dataset.markdownToolbarLabel || 'List formatting');

    const bulletButton = createButton(
        '<span class="sf-bullet-toolbar-icon">•</span><span class="sf-bullet-toolbar-text">' + (textarea.dataset.markdownBulletLabel || 'Lisää luettelo') + '</span>',
        textarea.dataset.markdownBulletLabel || 'Lisää luettelo',
        () => {
            addBulletList(textarea);
        }
    );

    const removeBulletButton = createButton(
        '<span class="sf-bullet-toolbar-icon">−</span><span class="sf-bullet-toolbar-text">' + (textarea.dataset.markdownRemoveBulletLabel || 'Poista luettelo') + '</span>',
        textarea.dataset.markdownRemoveBulletLabel || 'Poista luettelo',
        () => {
            removeBulletList(textarea);
        }
    );

    toolbar.appendChild(bulletButton);
    toolbar.appendChild(removeBulletButton);

    textarea.parentNode.insertBefore(wrapper, textarea);
    wrapper.appendChild(toolbar);
    wrapper.appendChild(textarea);

    textarea.addEventListener('keydown', (event) => {
        handleTextareaEnter(textarea, event);
    });
}

export function initMarkdownToolbar() {
    document
        .querySelectorAll('[data-markdown-toolbar="1"]')
        .forEach((textarea) => createBulletEditor(textarea));
}