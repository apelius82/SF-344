export const state = {
    currentStep: 1,
    maxSteps: 6, // Always 6 steps, including translation mode (preview needed)
    selectedType: null,
    selectedLang: 'fi',
    clickedSubmitButtonValue: null,
    previewInitialized: false,
    previewTutkintaInitialized: false,
};

export const getters = {
    getEl: (id) => document.getElementById(id),
    qs: (sel) => document.querySelector(sel),
    qsa: (sel) => document.querySelectorAll(sel),
};

export function setSelectedLang(lang) {
    state.selectedLang = lang;
}
export function setSelectedType(type) {
    state.selectedType = type;
}
export function setClickedSubmitButtonValue(v) {
    state.clickedSubmitButtonValue = v;
}