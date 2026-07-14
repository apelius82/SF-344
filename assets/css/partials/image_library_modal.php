<?php
/**
 * app/partials/image_library_modal.php
 * Kuvapankki-modaali lomakkeelle
 */

$categories = [
    'body'      => sf_term('library_cat_body', $currentUiLang) ?? 'Hahmokuvat',
    'warning'   => sf_term('library_cat_warning', $currentUiLang) ?? 'Varoitusmerkit',
    'equipment' => sf_term('library_cat_equipment', $currentUiLang) ?? 'Laitteet',
    'template'  => sf_term('library_cat_template', $currentUiLang) ?? 'Pohjat',
];
?>

<div class="sf-library-modal hidden" id="sfImageLibraryModal">
    <div class="sf-library-modal-content">
        <div class="sf-library-modal-header">
            <h2>
                <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-modal-icon">
                <?= htmlspecialchars(
                    sf_term('image_library_title', $currentUiLang) ?? 'Kuvapankki',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </h2>
            <button type="button"
                    class="sf-library-modal-close"
                    id="sfLibraryModalClose"
                    aria-label="Sulje">
                <svg width="24" height="24" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="sf-library-modal-body">
            <!-- Kategoriasuodattimet -->
            <div class="sf-library-categories" id="sfLibraryCategories">
                <button type="button"
                        class="sf-library-cat-btn active"
                        data-category="all">
                    <?= htmlspecialchars(
                        sf_term('library_cat_all', $currentUiLang) ?? 'Kaikki',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </button>
                <?php foreach ($categories as $key => $label): ?>
                    <button type="button"
                            class="sf-library-cat-btn"
                            data-category="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Kuvagalleria -->
            <div class="sf-library-gallery" id="sfLibraryGallery">
                <div class="sf-library-loading" id="sfLibraryLoading">
                    <div class="sf-library-spinner"></div>
                    <span>
                        <?= htmlspecialchars(
                            sf_term('library_loading', $currentUiLang) ?? 'Ladataan kuvia...',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </span>
                </div>

                <div class="sf-library-empty hidden" id="sfLibraryEmpty">
                    <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-library-empty-icon">
                    <p>
                        <?= htmlspecialchars(
                            sf_term('library_no_images', $currentUiLang) ?? 'Ei kuvia tässä kategoriassa.',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                </div>

                <div class="sf-library-grid" id="sfLibraryGrid"></div>
            </div>
        </div>

        <div class="sf-library-modal-footer">
            <div class="sf-library-selected-info" id="sfLibrarySelectedInfo">
                <span class="sf-library-selected-text hidden" id="sfLibrarySelectedText">
                    <img src=""
                         alt=""
                         id="sfLibrarySelectedThumb"
                         class="sf-library-selected-thumb">
                    <span id="sfLibrarySelectedName"></span>
                </span>
            </div>

            <div class="sf-library-modal-actions">
                <button type="button"
                        class="sf-btn sf-btn-secondary"
                        id="sfLibraryCancelBtn">
                    <?= htmlspecialchars(
                        sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </button>
                <button type="button"
                        class="sf-btn sf-btn-primary"
                        id="sfLibrarySelectBtn"
                        disabled>
                    <?= htmlspecialchars(
                        sf_term('library_select_btn', $currentUiLang) ?? 'Valitse kuva',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </button>
            </div>
        </div>
    </div>
</div>