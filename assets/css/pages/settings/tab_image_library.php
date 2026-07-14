<?php
// app/pages/settings/tab_image_library.php
declare(strict_types=1);

// Kategoriat
$categories = [
    'body'      => sf_term('library_cat_body', $currentUiLang) ?? 'Hahmokuvat',
    'warning'   => sf_term('library_cat_warning', $currentUiLang) ?? 'Varoitusmerkit',
    'equipment' => sf_term('library_cat_equipment', $currentUiLang) ?? 'Laitteet',
    'template'  => sf_term('library_cat_template', $currentUiLang) ?? 'Pohjat',
];

// Suodatin
$filterCategory = $_GET['cat'] ?? '';

// Hae kuvat
$where  = 'WHERE 1=1';
$params = [];
$types  = '';

if ($filterCategory !== '' && isset($categories[$filterCategory])) {
    $where   .= ' AND il.category = ? ';
    $params[] = $filterCategory;
    $types   .= 's';
}

$sql = "SELECT il.*, u.email AS uploader_email
        FROM sf_image_library il
        LEFT JOIN sf_users u ON u.id = il.created_by
        {$where}
        ORDER BY il.category ASC, il.sort_order ASC, il.title ASC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    throw new RuntimeException('Prepare failed: ' . $mysqli->error);
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Laske per kategoria (adminissa näytetään myös inaktiiviset -> lasketaan kaikki)
$countSql = "SELECT category, COUNT(*) as cnt FROM sf_image_library GROUP BY category";
$countRes = $mysqli->query($countSql);
$categoryCounts = [];
if ($countRes) {
    while ($row = $countRes->fetch_assoc()) {
        $categoryCounts[$row['category']] = (int)$row['cnt'];
    }
}
$totalCount = array_sum($categoryCounts);
?>

<h2>
    <img src="<?= $baseUrl ?>/assets/img/icons/image.svg" alt="" class="sf-heading-icon" aria-hidden="true">
    <?= htmlspecialchars(
        sf_term('settings_image_library_heading', $currentUiLang) ?? 'Kuvapankin hallinta',
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</h2>

<p class="sf-help-text" style="margin-bottom: 1.5rem;">
    <?= htmlspecialchars(
        sf_term('settings_image_library_help', $currentUiLang) ??
        'Lisää kuvia, joita käyttäjät voivat valita tiedotteisiin. Esimerkiksi hahmokuvia loukkaantumiskohtien merkintään.',
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>

<!-- LISÄÄ KUVA -->
<details class="sf-library-upload-section sf-collapsible" id="sfLibraryUploadBox">
    <summary class="sf-collapsible-summary">
        <span class="sf-collapsible-title">
            <img
                src="<?= $baseUrl ?>/assets/img/icons/add_new_icon.png"
                alt=""
                class="sf-icon sf-icon--summary"
                aria-hidden="true"
            >
            <?= htmlspecialchars(sf_term('library_upload_heading', $currentUiLang) ?? 'Lisää uusi kuva', ENT_QUOTES, 'UTF-8') ?>
        </span>
        <span class="sf-collapsible-hint" aria-hidden="true">+</span>
    </summary>

    <div class="sf-collapsible-body">
        <form method="post"
              action="<?= $baseUrl ?>/app/actions/image_library_save.php"
              enctype="multipart/form-data"
              class="sf-library-upload-form"
              data-sf-ajax="0">
            <input type="hidden" name="sf_action" value="add">

            <div class="sf-library-form-grid">
                <div class="sf-field">
                    <label for="lib-image"><?= htmlspecialchars(sf_term('library_label_image', $currentUiLang) ?? 'Kuvatiedosto', ENT_QUOTES, 'UTF-8') ?> *</label>
                    <input type="file" id="lib-image" name="image" accept="image/*" required class="sf-input">
                </div>

                <div class="sf-field">
                    <label for="lib-title"><?= htmlspecialchars(sf_term('library_label_title', $currentUiLang) ?? 'Otsikko', ENT_QUOTES, 'UTF-8') ?> *</label>
                    <input type="text" id="lib-title" name="title" required class="sf-input" placeholder="esim. Ihmishahmo edestä">
                </div>

                <div class="sf-field">
                    <label for="lib-category"><?= htmlspecialchars(sf_term('library_label_category', $currentUiLang) ?? 'Kategoria', ENT_QUOTES, 'UTF-8') ?> *</label>
                    <select id="lib-category" name="category" required class="sf-select">
                        <?php foreach ($categories as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sf-field">
                    <label for="lib-description"><?= htmlspecialchars(sf_term('library_label_description', $currentUiLang) ?? 'Kuvaus (valinnainen)', ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="lib-description" name="description" class="sf-input" placeholder="Lyhyt kuvaus käyttötarkoituksesta">
                </div>
            </div>

            <button type="submit" class="sf-btn sf-btn-primary" id="sfLibraryUploadBtn">
                <span class="btn-text">
                    <?= htmlspecialchars(sf_term('btn_add', $currentUiLang) ?? 'Lisää', ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="btn-spinner hidden" aria-hidden="true"></span>
            </button>
        </form>
    </div>
</details>

<!-- SUODATIN -->
<div class="sf-library-filters">
    <span class="sf-library-filter-label">
        <?= htmlspecialchars(sf_term('library_filter_label_show', $currentUiLang) ?? 'Näytä:', ENT_QUOTES, 'UTF-8') ?>
    </span>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=images&sub=library"
       class="sf-library-filter-btn <?= $filterCategory === '' ? 'active' : '' ?>">
        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang) ?? 'Kaikki', ENT_QUOTES, 'UTF-8') ?> (<?= (int)$totalCount ?>)
    </a>

    <?php foreach ($categories as $key => $label): ?>
        <a href="<?= $baseUrl ?>/index.php?page=settings&tab=images&sub=library&cat=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
           class="sf-library-filter-btn <?= $filterCategory === $key ? 'active' : '' ?>">
            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> (<?= (int)($categoryCounts[$key] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</div>

<!-- KUVAGALLERIA -->
<?php if (empty($images)): ?>
    <div class="sf-library-empty">
        <p>
            <?= htmlspecialchars(
                $filterCategory
                    ? (sf_term('library_empty_in_category', $currentUiLang) ?? 'Ei kuvia tässä kategoriassa.')
                    : (sf_term('library_empty', $currentUiLang) ?? 'Ei kuvia.'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>
    </div>
<?php else: ?>
    <div class="sf-library-admin-grid">
        <?php foreach ($images as $img): ?>
            <div class="sf-library-admin-item <?= (int)$img['is_active'] === 1 ? '' : 'inactive' ?>"
                 data-id="<?= (int)$img['id'] ?>">

                <div class="sf-library-admin-thumb">
                    <img src="<?= $baseUrl ?>/uploads/library/<?= htmlspecialchars($img['filename'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($img['title'], ENT_QUOTES, 'UTF-8') ?>">

                    <?php if ((int)$img['is_active'] !== 1): ?>
                        <span class="sf-library-inactive-badge">
                            <?= htmlspecialchars(sf_term('library_badge_hidden', $currentUiLang) ?? 'Piilotettu', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="sf-library-admin-info">
                    <div class="sf-library-admin-title"><?= htmlspecialchars($img['title'], ENT_QUOTES, 'UTF-8') ?></div>

                    <div class="sf-library-admin-meta">
                        <span class="sf-library-category-badge category-<?= htmlspecialchars($img['category'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($categories[$img['category']] ?? $img['category'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <?php if (!empty($img['description'])): ?>
                        <div class="sf-library-admin-desc"><?= htmlspecialchars($img['description'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>

                <div class="sf-library-admin-actions">
                    <form method="post"
                          action="<?= $baseUrl ?>/app/actions/image_library_save.php"
                          style="display:inline;"
                          data-sf-ajax="1">
                        <input type="hidden" name="sf_action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">

                        <button type="submit"
                                class="sf-btn-small <?= (int)$img['is_active'] === 1 ? '' : 'sf-btn-success' ?>">
                            <img
                                src="<?= $baseUrl ?>/assets/img/icons/eye_icon.svg"
                                alt=""
                                class="sf-icon sf-icon--btn sf-icon--white"
                                aria-hidden="true"
                            >
                            <?= (int)$img['is_active'] === 1
                                ? htmlspecialchars(sf_term('library_action_hide', $currentUiLang) ?? 'Piilota', ENT_QUOTES, 'UTF-8')
                                : htmlspecialchars(sf_term('library_action_show', $currentUiLang) ?? 'Näytä', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </form>

                    <button type="button"
                            class="sf-btn-small sf-btn-danger sf-delete-library-image"
                            data-id="<?= (int)$img['id'] ?>"
                            data-title="<?= htmlspecialchars($img['title'], ENT_QUOTES, 'UTF-8') ?>"
                            title="<?= htmlspecialchars(sf_term('library_action_delete', $currentUiLang) ?? 'Poista', ENT_QUOTES, 'UTF-8') ?>">
                        <img
                            src="<?= $baseUrl ?>/assets/img/icons/delete_icon.svg"
                            alt=""
                            class="sf-icon sf-icon--btn sf-icon--white"
                            aria-hidden="true"
                        >
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- POISTO-MODAALI -->
<div class="sf-modal hidden" id="sfLibraryDeleteModal">
    <div class="sf-modal-content">
        <h2>
            <?= htmlspecialchars(sf_term('library_delete_heading', $currentUiLang) ?? 'Poista kuva', ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <p>
            <?= htmlspecialchars(sf_term('library_delete_confirm', $currentUiLang) ?? 'Haluatko varmasti poistaa kuvan', ENT_QUOTES, 'UTF-8') ?>
            <strong id="sfLibraryDeleteTitle"></strong>?
        </p>

        <p class="sf-help-text">
            <?= htmlspecialchars(sf_term('library_delete_help', $currentUiLang) ?? 'Kuva poistetaan pysyvästi kuvapankista.', ENT_QUOTES, 'UTF-8') ?>
        </p>

        <form
            method="post"
            action="<?= $baseUrl ?>/app/actions/image_library_save.php"
            id="sfLibraryDeleteForm"
            data-sf-ajax="0"
        >
            <input type="hidden" name="sf_action" value="delete">
            <input type="hidden" name="id" id="sfLibraryDeleteId">

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="sfLibraryDeleteCancel">
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
                </button>

                <button type="submit" class="sf-btn sf-btn-danger">
                    <?= htmlspecialchars(sf_term('btn_delete_permanently', $currentUiLang) ?? 'Poista pysyvästi', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // Avaa poisto-modaali (delegointi)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.sf-delete-library-image');
        if (!btn) return;

        var modal = document.getElementById('sfLibraryDeleteModal');
        var idField = document.getElementById('sfLibraryDeleteId');
        var titleField = document.getElementById('sfLibraryDeleteTitle');

        if (modal && idField && titleField) {
            idField.value = btn.dataset.id || '';
            titleField.textContent = btn.dataset.title || '';
            modal.classList.remove('hidden');
        }
    });

    // Sulje modaali
    var cancelBtn = document.getElementById('sfLibraryDeleteCancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            var modal = document.getElementById('sfLibraryDeleteModal');
            if (modal) modal.classList.add('hidden');
        });
    }

    // Upload spinner (tämä voi jäädä vaikka submit olisi normaali)
    var uploadForm = document.querySelector('.sf-library-upload-form');
    var uploadBtn = document.getElementById('sfLibraryUploadBtn');

    if (uploadForm && uploadBtn) {
        uploadForm.addEventListener('submit', function () {
            var btnText = uploadBtn.querySelector('.btn-text');
            var btnSpinner = uploadBtn.querySelector('.btn-spinner');

            if (btnText && btnSpinner) {
                btnText.textContent = '<?= htmlspecialchars(sf_term('status_uploading', $currentUiLang) ?? 'Uploading...', ENT_QUOTES, 'UTF-8') ?>';
                btnSpinner.classList.remove('hidden');
                uploadBtn.disabled = true;
            }
        });
    }
})();
</script>