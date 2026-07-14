<?php
// assets/pages/worksites.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ . '/../../app/includes/statuses.php';

$user = sf_current_user();
if (!$user || !in_array((int)$user['role_id'], [1, 3], true)) {
    http_response_code(403);
    echo "Ei käyttöoikeutta työmaiden hallintaan.";
    exit;
}

$baseUrl = rtrim($config['base_url'] ?? '', '/');

try {
    $pdo = new PDO(
        'mysql:host=' . $config['db']['host'] .
        ';dbname=' . $config['db']['name'] .
        ';charset=' . $config['db']['charset'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p>Tietokantavirhe.</p>';
    exit;
}

// Käsittele lomaketoiminnot (lisää / disabloi / aktivoi)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $showInWorksiteLists = isset($_POST['show_in_worksite_lists']) ? 1 : 0;
        $showInDisplayTargets = isset($_POST['show_in_display_targets']) ? 1 : 0;
        if ($name !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO sf_worksites (
                    name,
                    is_active,
                    show_in_worksite_lists,
                    show_in_display_targets,
                    created_at
                ) VALUES (
                    :name,
                    1,
                    :show_in_worksite_lists,
                    :show_in_display_targets,
                    NOW()
                )
            ");
            $stmt->execute([
                ':name' => $name,
                ':show_in_worksite_lists' => $showInWorksiteLists,
                ':show_in_display_targets' => $showInDisplayTargets,
            ]);
        }
    } elseif ($action === 'toggle' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE sf_worksites SET is_active = 1 - is_active WHERE id = :id");
        $stmt->execute([':id' => $id]);
    } elseif ($action === 'toggle_visibility' && isset($_POST['id'], $_POST['field'])) {
        $id = (int)$_POST['id'];
        $field = (string)$_POST['field'];
        $allowedFields = ['show_in_worksite_lists', 'show_in_display_targets'];

        if (!in_array($field, $allowedFields, true)) {
            header('Location: ' . $baseUrl . '/index.php?page=worksites');
            exit;
        }

        if ($field === 'show_in_worksite_lists') {
            $stmt = $pdo->prepare("UPDATE sf_worksites SET show_in_worksite_lists = 1 - show_in_worksite_lists WHERE id = :id");
            $stmt->execute([':id' => $id]);
        } elseif ($field === 'show_in_display_targets') {
            $stmt = $pdo->prepare("UPDATE sf_worksites SET show_in_display_targets = 1 - show_in_display_targets WHERE id = :id");
            $stmt->execute([':id' => $id]);
        }
    }

    header('Location: ' . $baseUrl . '/index.php?page=worksites');
    exit;
}

// Hae kaikki työmaat
$stmt = $pdo->query("
    SELECT
        id,
        name,
        is_active,
        show_in_worksite_lists,
        show_in_display_targets,
        created_at
    FROM sf_worksites
    ORDER BY name ASC
");
$worksites = $stmt->fetchAll();
?>
<div class="sf-page-container">
    <div class="sf-page-header">
        <h1 class="sf-page-title">Työmaiden hallinta</h1>
    </div>

<div class="sf-worksites-page">

    <form method="post" class="sf-form-inline">
        <input type="hidden" name="action" value="add">
        <label for="ws-name">Uusi työmaa:</label>
        <input type="text" id="ws-name" name="name" required>
        <label for="ws-show-in-lists">
            <input type="checkbox" id="ws-show-in-lists" name="show_in_worksite_lists" checked>
            Näytä työmaalistoissa
        </label>
        <label for="ws-show-in-displays">
            <input type="checkbox" id="ws-show-in-displays" name="show_in_display_targets" checked>
            Näytä infonäyttövalinnoissa
        </label>
        <button type="submit">Lisää</button>
    </form>

    <table class="sf-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nimi</th>
                <th>Aktiivinen</th>
                <th>Työmaalistoissa</th>
                <th>Infonäytöissä</th>
                <th>Luotu</th>
                <th>Toiminnot</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($worksites as $ws): ?>
            <tr>
                <td><?= (int)$ws['id'] ?></td>
                <td><?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $ws['is_active'] ? 'Kyllä' : 'Ei' ?></td>
                <td><?= !empty($ws['show_in_worksite_lists']) ? 'Kyllä' : 'Ei' ?></td>
                <td><?= !empty($ws['show_in_display_targets']) ? 'Kyllä' : 'Ei' ?></td>
                <td><?= htmlspecialchars($ws['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$ws['id'] ?>">
                        <button type="submit">
                            <?= $ws['is_active'] ? 'Passivoi' : 'Aktivoi' ?>
                        </button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="toggle_visibility">
                        <input type="hidden" name="id" value="<?= (int)$ws['id'] ?>">
                        <input type="hidden" name="field" value="show_in_worksite_lists">
                        <button type="submit">
                            <?= !empty($ws['show_in_worksite_lists']) ? 'Piilota työmaalistoista' : 'Näytä työmaalistoissa' ?>
                        </button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="toggle_visibility">
                        <input type="hidden" name="id" value="<?= (int)$ws['id'] ?>">
                        <input type="hidden" name="field" value="show_in_display_targets">
                        <button type="submit">
                            <?= !empty($ws['show_in_display_targets']) ? 'Piilota infonäytöistä' : 'Näytä infonäytöissä' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>