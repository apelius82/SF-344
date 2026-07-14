<?php
/**
 * Feedback Page
 * 
 * Displays user feedback submissions.
 * - Regular users see their own feedback
 * - Admins see all feedback with management capabilities
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

// Require authentication
sf_require_login();

// Initialize Database
Database::setConfig($config['db'] ?? []);

$user = sf_current_user();
$isAdmin = $user && (int)$user['role_id'] === 1;
$userId = (int)$user['id'];
$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$base = rtrim($config['base_url'] ?? '', '/');

// Get filters
$filterStatus = $_GET['status'] ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterSearch = trim((string)($_GET['q'] ?? ''));
$filterReporter = $_GET['reporter'] ?? '';

if (strlen($filterSearch) > 120) {
    $filterSearch = substr($filterSearch, 0, 120);
}

$filterReporterId = ctype_digit((string)$filterReporter) ? (int)$filterReporter : 0;

// Build query
$db = Database::getInstance();
$whereClauses = [];
$params = [];

// Filter out merged feedbacks (show only non-merged or those merged into others)
$whereClauses[] = "f.merged_into_id IS NULL";

// Non-admins only see their own feedback
if (!$isAdmin) {
    $whereClauses[] = "f.reported_by = :user_id";
    $params[':user_id'] = $userId;
}

// Status filter
if ($filterStatus && in_array($filterStatus, ['new', 'in_progress', 'resolved', 'rejected'], true)) {
    $whereClauses[] = "f.status = :status";
    $params[':status'] = $filterStatus;
}

// Category filter
if ($filterCategory && in_array($filterCategory, ['critical', 'visual', 'improvement', 'bug', 'other'], true)) {
    $whereClauses[] = "f.category = :category";
    $params[':category'] = $filterCategory;
}

// Reporter filter, admin only
if ($isAdmin && $filterReporterId > 0) {
    $whereClauses[] = "f.reported_by = :reporter_id";
    $params[':reporter_id'] = $filterReporterId;
}

// Text search
if ($filterSearch !== '') {
    $whereClauses[] = "(
        f.title LIKE :search_title
        OR f.description LIKE :search_description
        OR CONCAT_WS(' ', COALESCE(u1.first_name, ''), COALESCE(u1.last_name, '')) LIKE :search_reporter
    )";

    $searchValue = '%' . $filterSearch . '%';

    $params[':search_title'] = $searchValue;
    $params[':search_description'] = $searchValue;
    $params[':search_reporter'] = $searchValue;
}

$whereClause = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

$sql = "SELECT f.*, 
               u1.first_name as reporter_first_name, u1.last_name as reporter_last_name,
               u2.first_name as resolver_first_name, u2.last_name as resolver_last_name,
               (SELECT COUNT(*) FROM sf_feedback_comments WHERE feedback_id = f.id) as comment_count,
               (SELECT COUNT(*) FROM sf_feedback WHERE merged_into_id = f.id) as merged_count
        FROM sf_feedback f
        LEFT JOIN sf_users u1 ON f.reported_by = u1.id
        LEFT JOIN sf_users u2 ON f.resolved_by = u2.id
        $whereClause
        ORDER BY f.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$feedbacks = $stmt->fetchAll();

$reporterOptions = [];

if ($isAdmin) {
    $reporterStmt = $db->prepare("
        SELECT DISTINCT 
            u.id,
            u.first_name,
            u.last_name
        FROM sf_feedback f
        INNER JOIN sf_users u ON f.reported_by = u.id
        WHERE f.merged_into_id IS NULL
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $reporterStmt->execute();
    $reporterOptions = $reporterStmt->fetchAll();
}

// Fetch comments for all feedbacks
$feedbackIds = array_column($feedbacks, 'id');
$commentsMap = [];
if (!empty($feedbackIds)) {
    $placeholders = implode(',', array_fill(0, count($feedbackIds), '?'));
    $commentsQuery = "
        SELECT c.*, u.first_name, u.last_name 
        FROM sf_feedback_comments c
        LEFT JOIN sf_users u ON c.user_id = u.id
        WHERE c.feedback_id IN ($placeholders)
        ORDER BY c.created_at ASC
    ";
    $stmt = $db->prepare($commentsQuery);
    $stmt->execute($feedbackIds);
    $comments = $stmt->fetchAll();
    
    foreach ($comments as $comment) {
        $fid = (int)$comment['feedback_id'];
        if (!isset($commentsMap[$fid])) {
            $commentsMap[$fid] = [];
        }
        $commentsMap[$fid][] = $comment;
    }
}

// Category configs with icons and colors
$categoryConfig = [
    'critical' => ['icon' => 'alert-circle.svg', 'color' => '#dc2626', 'label_key' => 'feedback_category_critical'],
    'visual' => ['icon' => 'eye_icon.svg', 'color' => '#9333ea', 'label_key' => 'feedback_category_visual'],
    'improvement' => ['icon' => 'idea.svg', 'color' => '#2563eb', 'label_key' => 'feedback_category_improvement'],
    'bug' => ['icon' => 'error.svg', 'color' => '#ea580c', 'label_key' => 'feedback_category_bug'],
    'other' => ['icon' => 'file-text.svg', 'color' => '#6b7280', 'label_key' => 'feedback_category_other'],
];

// Status configs with colors
$statusConfig = [
    'new' => ['color' => '#059669', 'label_key' => 'feedback_status_new'],
    'in_progress' => ['color' => '#eab308', 'label_key' => 'feedback_status_in_progress'],
    'resolved' => ['color' => '#0aa907', 'label_key' => 'feedback_status_resolved'],
    'rejected' => ['color' => '#dc2626', 'label_key' => 'feedback_status_rejected'],
];
?>

<style>
html,
body {
    background-color: #0f172a;
}

.sf-page-container.sf-feedback-page {
    background-color: transparent;
}

.sf-feedback-page img.sf-icon,
.sf-feedback-page img.sf-icon-feedback,
.sf-feedback-page img.sf-icon-orig,
.sf-feedback-page img.sf-icon-sm,
.sf-feedback-page img.sf-btn-icon {
    display: inline-block;
    flex: 0 0 auto;
    max-width: none;
    object-fit: contain;
    vertical-align: middle;
}

.sf-feedback-page img.sf-icon,
.sf-feedback-page img.sf-icon-feedback,
.sf-feedback-page img.sf-icon-orig {
    width: 16px;
    height: 16px;
}

.sf-feedback-page img.sf-icon-sm {
    width: 12px;
    height: 12px;
}

.sf-feedback-page img.sf-btn-icon {
    width: 20px;
    height: 20px;
}

	.sf-feedback-page .sf-feedback-card-meta > span::before,
.sf-feedback-page .sf-feedback-card-resolved > span::before,
.sf-feedback-page .sf-feedback-comment-author::before,
.sf-feedback-page .sf-feedback-comment-header > span:last-child::before {
    content: '';
    display: inline-block;
    width: 14px;
    height: 14px;
    flex: 0 0 14px;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-page .sf-content-card,
.sf-feedback-page .sf-feedback-empty {
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 31, 0.98));
    border: 1px solid rgba(148, 163, 184, 0.28);
    color: rgba(255, 255, 255, 0.9);
}

.sf-feedback-page.is-filtering {
    opacity: 0.72;
    pointer-events: none;
    transition: opacity 0.12s ease;
}
</style>

<div class="sf-page-container sf-feedback-page">
    <div class="sf-feedback-hero">
        <div class="sf-feedback-hero-main">
            <div class="sf-page-header">
                <h1 class="sf-page-title"><?= htmlspecialchars(sf_term('feedback_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <p class="sf-feedback-description">
                <?= htmlspecialchars(sf_term('feedback_description', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="sf-page-actions sf-feedback-hero-actions">
            <button type="button" class="sf-btn sf-btn-primary" id="btnNewFeedback">
                <img src="<?= $base ?>/assets/img/icons/feedback.svg" alt="" class="sf-btn-icon">
                <?= htmlspecialchars(sf_term('feedback_new', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>

    <?php if ($isAdmin): ?>
<form
    class="sf-filters sf-feedback-filter-form"
    method="get"
    action="<?= htmlspecialchars($base . '/index.php', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="page" value="feedback">
        <div class="sf-filter-group sf-filter-group-search">
            <label for="filterSearch" class="sf-filter-label"><?= htmlspecialchars(sf_term('feedback_filter_search', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <input
                type="search"
                id="filterSearch"
                name="q"
                class="sf-filter-input"
                value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="<?= htmlspecialchars(sf_term('feedback_filter_search_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="sf-filter-group">
            <label for="filterStatus" class="sf-filter-label"><?= htmlspecialchars(sf_term('feedback_filter_status', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="filterStatus" name="status" class="sf-filter-select">
                <option value=""><?= htmlspecialchars(sf_term('feedback_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="new" <?= $filterStatus === 'new' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('feedback_status_new', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('feedback_status_in_progress', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="resolved" <?= $filterStatus === 'resolved' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('feedback_status_resolved', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('feedback_status_rejected', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
            </select>
        </div>
        
        <div class="sf-filter-group">
            <label for="filterCategory" class="sf-filter-label"><?= htmlspecialchars(sf_term('feedback_filter_category', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="filterCategory" name="category" class="sf-filter-select">
                <option value=""><?= htmlspecialchars(sf_term('feedback_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($categoryConfig as $catKey => $catData): ?>
                    <option value="<?= htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8') ?>" <?= $filterCategory === $catKey ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term($catData['label_key'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sf-filter-group">
            <label for="filterReporter" class="sf-filter-label"><?= htmlspecialchars(sf_term('feedback_filter_user', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="filterReporter" name="reporter" class="sf-filter-select">
                <option value=""><?= htmlspecialchars(sf_term('feedback_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($reporterOptions as $reporter): ?>
                    <?php
                    $reporterOptionId = (int)$reporter['id'];
                    $reporterOptionName = trim(($reporter['first_name'] ?? '') . ' ' . ($reporter['last_name'] ?? ''));
                    ?>
                    <option value="<?= $reporterOptionId ?>" <?= $filterReporterId === $reporterOptionId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($reporterOptionName ?: ('#' . $reporterOptionId), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sf-filter-actions">
            <button type="submit" class="sf-btn sf-btn-primary sf-feedback-filter-submit">
                <?= htmlspecialchars(sf_term('feedback_filter_apply', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <a href="<?= htmlspecialchars($base . '/index.php?page=feedback', ENT_QUOTES, 'UTF-8') ?>" class="sf-feedback-filter-clear">
                <?= htmlspecialchars(sf_term('feedback_filter_clear', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </form>
    <?php endif; ?>

    <div class="sf-feedback-list">
        <?php if (empty($feedbacks)): ?>
            <div class="sf-feedback-empty">
                <p><?= htmlspecialchars(sf_term('feedback_no_results', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($feedbacks as $feedback): ?>
                <?php
                $category = $feedback['category'] ?? 'other';
                $status = $feedback['status'] ?? 'new';
                $catData = $categoryConfig[$category] ?? $categoryConfig['other'];
                $statusData = $statusConfig[$status] ?? $statusConfig['new'];
                $reporterName = trim(($feedback['reporter_first_name'] ?? '') . ' ' . ($feedback['reporter_last_name'] ?? ''));
                if (empty($reporterName)) $reporterName = 'Unknown';
                
                // Determine who can delete this feedback
                $canManage = $isAdmin;
                $canDelete = $isAdmin || ((int)$feedback['reported_by'] === $userId);
                
                // Get comments for this feedback
                $feedbackComments = $commentsMap[(int)$feedback['id']] ?? [];
                $commentCount = (int)($feedback['comment_count'] ?? 0);
                $mergedCount = (int)($feedback['merged_count'] ?? 0);
                ?>
                <div class="sf-content-card" id="feedback-<?= (int)$feedback['id'] ?>">
                    <div class="sf-feedback-card-header">
                        <div class="sf-feedback-card-badges">
                            <span class="sf-feedback-badge sf-feedback-badge-category" 
                                  style="background-color: <?= htmlspecialchars($catData['color']) ?>;">
                                <img src="<?= $base ?>/assets/img/icons/<?= htmlspecialchars($catData['icon']) ?>" alt="" class="sf-icon" aria-hidden="true">
                                <?= htmlspecialchars(sf_term($catData['label_key'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="sf-feedback-badge sf-feedback-badge-status<?= $status === 'new' ? ' sf-status-new-pulse' : '' ?>" 
                                  style="background-color: <?= htmlspecialchars($statusData['color']) ?>;">
                                <?php if ($status === 'in_progress'): ?>
                                    <span class="sf-spinner-icon" aria-hidden="true"></span>
                                <?php elseif ($status === 'resolved'): ?>
                                    <img src="<?= $base ?>/assets/img/icons/check.svg" alt="" class="sf-icon-orig" aria-hidden="true">
                                <?php endif; ?>
                                <?= htmlspecialchars(sf_term($statusData['label_key'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                    
                    <h3 class="sf-feedback-card-title"><?= htmlspecialchars($feedback['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    
                    <p class="sf-feedback-card-description"><?= nl2br(htmlspecialchars($feedback['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                    
                    <div class="sf-feedback-card-meta">
                        <span><?= htmlspecialchars(sf_term('feedback_reported_by', $uiLang), ENT_QUOTES, 'UTF-8') ?>: 
                              <strong><?= htmlspecialchars($reporterName, ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars(sf_term('feedback_created_at', $uiLang), ENT_QUOTES, 'UTF-8') ?>: 
                              <?= htmlspecialchars(date('Y-m-d H:i', strtotime($feedback['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    
                    <?php if ($mergedCount > 0): ?>
                    <div class="sf-feedback-merged">
                        <img src="<?= $base ?>/assets/img/icons/paperclip.svg" alt="" class="sf-icon" aria-hidden="true">
                        +<?= (int)$mergedCount ?> <?= htmlspecialchars(sf_term('feedback_merged_feedbacks', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($feedback['resolved_at']): ?>
                        <?php $resolverName = trim(($feedback['resolver_first_name'] ?? '') . ' ' . ($feedback['resolver_last_name'] ?? '')); ?>
                        <div class="sf-feedback-card-resolved">
                            <span><?= htmlspecialchars(sf_term('feedback_resolved_by', $uiLang), ENT_QUOTES, 'UTF-8') ?>: 
                                  <strong><?= htmlspecialchars($resolverName ?: 'Unknown', ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars(sf_term('feedback_resolved_at', $uiLang), ENT_QUOTES, 'UTF-8') ?>: 
                                  <?= htmlspecialchars(date('Y-m-d H:i', strtotime($feedback['resolved_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Comments and actions section -->
                    <div class="sf-feedback-comments-section">
                        <div class="sf-feedback-comments-toolbar">
                            <button class="sf-feedback-comments-toggle" data-feedback-id="<?= (int)$feedback['id'] ?>">
    <img src="<?= $base ?>/assets/img/icons/comment.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
    <span class="sf-feedback-action-label">
        <?= htmlspecialchars(sf_term('feedback_comments', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </span>
    <span class="sf-feedback-comments-number"><?= (int)$commentCount ?></span>
    <span class="sf-toggle-icon">
        <img src="<?= $base ?>/assets/img/icons/chevron-down.svg" alt="" class="sf-icon-sm" aria-hidden="true">
    </span>
</button>

                            <?php if ($canManage || $canDelete): ?>
                                <div class="sf-feedback-actions-menu">
                                    <button type="button"
                                            class="sf-feedback-actions-toggle"
                                            data-feedback-actions-toggle
                                            aria-expanded="false"
                                            title="<?= htmlspecialchars(sf_term('feedback_actions', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                                        <img src="<?= $base ?>/assets/img/icons/settings.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                                        <span class="sf-feedback-action-label"><?= htmlspecialchars(sf_term('feedback_actions', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                                        <img src="<?= $base ?>/assets/img/icons/chevron-down.svg" alt="" class="sf-icon-sm" aria-hidden="true">
                                    </button>

                                    <div class="sf-feedback-actions-dropdown" hidden>
                                        <?php if ($canManage): ?>
                                            <button type="button"
                                                    class="sf-feedback-actions-item btn-manage-feedback"
                                                    data-feedback-id="<?= (int)$feedback['id'] ?>">
                                                <img src="<?= $base ?>/assets/img/icons/settings.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                                                <span><?= htmlspecialchars(sf_term('feedback_manage', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                                            </button>
                                            <button type="button"
                                                    class="sf-feedback-actions-item btn-merge-feedback"
                                                    data-feedback-id="<?= (int)$feedback['id'] ?>"
                                                    data-feedback-title="<?= htmlspecialchars($feedback['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                <img src="<?= $base ?>/assets/img/icons/link.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                                                <span><?= htmlspecialchars(sf_term('feedback_merge_action', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($canDelete): ?>
                                            <button type="button"
                                                    class="sf-feedback-actions-item sf-feedback-actions-item-danger btn-delete-feedback"
                                                    data-feedback-id="<?= (int)$feedback['id'] ?>"
                                                    data-feedback-title="<?= htmlspecialchars($feedback['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                <img src="<?= $base ?>/assets/img/icons/delete.svg" alt="" class="sf-icon" aria-hidden="true">
                                                <span><?= htmlspecialchars(sf_term('feedback_delete', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="sf-feedback-comments-list" id="comments-<?= (int)$feedback['id'] ?>" style="display: none;">
                            <?php if (!empty($feedbackComments)): ?>
                                <?php foreach ($feedbackComments as $comment): ?>
                                    <?php 
                                    $commenterName = trim(($comment['first_name'] ?? '') . ' ' . ($comment['last_name'] ?? ''));
                                    if (empty($commenterName)) $commenterName = 'Unknown';
                                    $canDeleteComment = $isAdmin || ((int)$comment['user_id'] === $userId);
                                    ?>
                                    <div class="sf-feedback-comment" data-comment-id="<?= (int)$comment['id'] ?>">
                                        <div class="sf-feedback-comment-header">
                                            <span class="sf-feedback-comment-author"><?= htmlspecialchars($commenterName, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span><?= htmlspecialchars(date('Y-m-d H:i', strtotime($comment['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="sf-feedback-comment-text"><?= nl2br(htmlspecialchars($comment['comment'], ENT_QUOTES, 'UTF-8')) ?></div>
                                        <?php if ($canDeleteComment): ?>
                                        <button class="sf-feedback-comment-delete" data-comment-id="<?= (int)$comment['id'] ?>" title="<?= htmlspecialchars(sf_term('feedback_comment_delete_confirm', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                                            <img src="<?= $base ?>/assets/img/icons/delete.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <!-- Add comment form -->
                            <div class="sf-feedback-comment-form">
                                <textarea placeholder="<?= htmlspecialchars(sf_term('feedback_comment_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>" maxlength="2000" data-feedback-id="<?= (int)$feedback['id'] ?>"></textarea>
                                <button class="sf-btn sf-btn-primary sf-btn-small sf-btn-send-comment" data-feedback-id="<?= (int)$feedback['id'] ?>">
                                    <?= htmlspecialchars(sf_term('feedback_comment_send', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- New Feedback Modal -->
<div id="modalNewFeedback" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('feedback_new', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <form id="formNewFeedback" class="sf-modal-body">
            <?= sf_csrf_field() ?>
            
            <div class="sf-form-group">
                <label for="feedbackTitle"><?= htmlspecialchars(sf_term('feedback_title_label', $uiLang), ENT_QUOTES, 'UTF-8') ?> *</label>
                <input type="text" 
                       id="feedbackTitle" 
                       name="title" 
                       maxlength="255" 
                       required 
                       class="sf-form-input">
            </div>
            
            <div class="sf-form-group">
                <label for="feedbackCategory"><?= htmlspecialchars(sf_term('feedback_category_label', $uiLang), ENT_QUOTES, 'UTF-8') ?> *</label>
                <select id="feedbackCategory" name="category" required class="sf-form-input">
                    <?php foreach ($categoryConfig as $catKey => $catData): ?>
                        <option value="<?= htmlspecialchars($catKey) ?>">
                            <?= $catData['emoji'] ?> <?= htmlspecialchars(sf_term($catData['label_key'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="sf-form-group">
                <label for="feedbackDescription"><?= htmlspecialchars(sf_term('feedback_description_label', $uiLang), ENT_QUOTES, 'UTF-8') ?> *</label>
<textarea id="feedbackDescription" 
          name="description" 
          rows="3" 
          required 
          class="sf-form-input"></textarea>
            </div>

            <div class="sf-form-group">
                <h4 class="sf-toggle-section-heading"><?= htmlspecialchars(sf_term('feedback_notifications_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h4>
                <div class="sf-toggle-card-stack">
                    <div class="sf-toggle-card">
                        <div class="sf-toggle-info">
                            <div class="sf-toggle-label"><?= htmlspecialchars(sf_term('feedback_notify_status_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <label class="sf-switch">
                            <input type="checkbox" name="notify_status_change" id="notifyStatusChange" value="1" checked>
                            <span class="sf-slider"></span>
                        </label>
                    </div>
                    <div class="sf-toggle-card">
                        <div class="sf-toggle-info">
                            <div class="sf-toggle-label"><?= htmlspecialchars(sf_term('feedback_notify_comment_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <label class="sf-switch">
                            <input type="checkbox" name="notify_comment" id="notifyComment" value="1" checked>
                            <span class="sf-slider"></span>
                        </label>
                    </div>
                </div>
                <p class="sf-help-text"><?= htmlspecialchars(sf_term('feedback_notifications_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </form>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnSubmitFeedback">
                <?= htmlspecialchars(sf_term('feedback_submit', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Admin Manage Feedback Modal -->
<div id="modalManageFeedback" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('feedback_manage', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <form id="formManageFeedback" class="sf-modal-body">
            <?= sf_csrf_field() ?>
            <input type="hidden" id="manageFeedbackId" name="feedback_id">
            
            <div class="sf-form-group">
                <label><?= htmlspecialchars(sf_term('feedback_title_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <p id="manageFeedbackTitle" class="sf-feedback-display-text"></p>
            </div>
            
            <div class="sf-form-group">
                <label><?= htmlspecialchars(sf_term('feedback_description_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <p id="manageFeedbackDescription" class="sf-feedback-display-text"></p>
            </div>
            
            <div class="sf-form-group">
                <label for="manageStatus"><?= htmlspecialchars(sf_term('feedback_update_status', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="manageStatus" name="status" class="sf-form-input">
                    <option value="new"><?= htmlspecialchars(sf_term('feedback_status_new', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="in_progress"><?= htmlspecialchars(sf_term('feedback_status_in_progress', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="resolved"><?= htmlspecialchars(sf_term('feedback_status_resolved', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="rejected"><?= htmlspecialchars(sf_term('feedback_status_rejected', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </div>
            
            <div class="sf-form-group">
                <label for="manageAdminNotes"><?= htmlspecialchars(sf_term('feedback_admin_notes_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <textarea id="manageAdminNotes" 
                          name="admin_notes" 
                          rows="4" 
                          class="sf-form-input"></textarea>
            </div>
        </form>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-secondary" id="btnCreateUpdateFromFeedback"
                    title="<?= htmlspecialchars(sf_term('feedback_create_update', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $base ?>/assets/img/icons/changelog_icon.svg" alt="" class="sf-btn-icon" aria-hidden="true">
                <?= htmlspecialchars(sf_term('feedback_create_update', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnSaveFeedback">
                <?= htmlspecialchars(sf_term('feedback_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- Create Update from Feedback Modal -->
<div id="modalCreateUpdate" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content" style="max-width:660px;">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('feedback_create_update', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>

        <form id="formCreateUpdate" class="sf-modal-body">
            <?= sf_csrf_field() ?>
            <input type="hidden" id="createUpdateFeedbackId" name="feedback_id" value="0">

            <!-- Language tabs -->
            <?php
            $termsConfig   = sf_get_terms_config();
            $supportedLangs = $termsConfig['languages'] ?? ['fi', 'sv', 'en', 'it', 'el'];
            $langLabels = [
                'fi' => 'Suomi (FI)', 'sv' => 'Svenska (SV)', 'en' => 'English (EN)',
                'it' => 'Italiano (IT)', 'el' => 'Ελληνικά (EL)',
            ];
            ?>
            <div style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap;">
                <?php foreach ($supportedLangs as $idx => $lang): ?>
                    <button type="button"
                            class="sf-btn sf-btn-small <?= $idx === 0 ? 'sf-btn-primary' : 'sf-btn-secondary' ?> sf-cu-lang-tab"
                            data-lang="<?= htmlspecialchars($lang) ?>">
                        <?= htmlspecialchars($langLabels[$lang] ?? strtoupper($lang)) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($supportedLangs as $idx => $lang): ?>
                <div class="sf-cu-lang-panel" data-lang="<?= htmlspecialchars($lang) ?>"
                     <?= $idx !== 0 ? 'style="display:none;"' : '' ?>>
                    <div class="sf-form-group">
                        <label for="cuTitle_<?= $lang ?>">
                            <?= htmlspecialchars(sf_term('updates_field_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            (<?= strtoupper($lang) ?>)
                        </label>
                        <input type="text" id="cuTitle_<?= $lang ?>" class="sf-form-input sf-cu-title" data-lang="<?= $lang ?>">
                    </div>
                    <div class="sf-form-group">
                        <label for="cuContent_<?= $lang ?>">
                            <?= htmlspecialchars(sf_term('updates_field_content', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            (<?= strtoupper($lang) ?>)
                        </label>
                        <textarea id="cuContent_<?= $lang ?>" rows="4" class="sf-form-input sf-cu-content" data-lang="<?= $lang ?>"></textarea>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="sf-form-group" style="margin-top:12px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cuIsPublished" name="is_published" value="1"
                           style="width:18px;height:18px;cursor:pointer;">
                    <span><?= htmlspecialchars(sf_term('updates_field_is_published', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            </div>
        </form>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnSaveCreateUpdate">
                <?= htmlspecialchars(sf_term('feedback_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- Admin Merge Feedback Modal -->
<div id="modalMergeFeedback" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('feedback_merge_action', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <form id="formMergeFeedback" class="sf-modal-body">
            <?= sf_csrf_field() ?>
            <input type="hidden" id="mergeSourceId" name="source_id">
            
            <div class="sf-form-group">
                <label><?= htmlspecialchars(sf_term('feedback_title_label', $uiLang), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(sf_term('feedback_merge_action', $uiLang), ENT_QUOTES, 'UTF-8') ?>)</label>
                <p id="mergeSourceTitle" class="sf-feedback-display-text"></p>
            </div>
            
            <div class="sf-form-group">
                <label for="mergeTargetId"><?= htmlspecialchars(sf_term('feedback_merged_into', $uiLang), ENT_QUOTES, 'UTF-8') ?> *</label>
                <select id="mergeTargetId" name="target_id" required class="sf-form-input">
                    <option value="">-- <?= htmlspecialchars(sf_term('feedback_select_target', $uiLang), ENT_QUOTES, 'UTF-8') ?> --</option>
                </select>
                <small style="color: #64748b; display: block; margin-top: 0.25rem;">
                    <?= htmlspecialchars(sf_term('feedback_merge_helper_text', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </small>
            </div>
        </form>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnMergeFeedback">
                <?= htmlspecialchars(sf_term('feedback_merge_action', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    const BASE_URL = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN = <?= json_encode(sf_csrf_token()) ?>;
    const IS_ADMIN = <?= json_encode($isAdmin) ?>;
    const FEEDBACK_DATA = <?= json_encode($feedbacks) ?>;
    
    // Translations
    const i18n = {
        deleteConfirm: <?= json_encode(sf_term('feedback_delete_confirm', $uiLang)) ?>,
        deletedSuccess: <?= json_encode(sf_term('feedback_deleted_success', $uiLang)) ?>,
        deleteError: <?= json_encode(sf_term('feedback_delete_error', $uiLang)) ?>,
        networkError: <?= json_encode(sf_term('feedback_network_error', $uiLang)) ?>,
        error: <?= json_encode(sf_term('feedback_error_title_required', $uiLang)) ?>,
        commentEmpty: <?= json_encode(sf_term('feedback_comment_empty', $uiLang)) ?>,
        commentAdded: <?= json_encode(sf_term('feedback_comment_added', $uiLang)) ?>,
        commentAddError: <?= json_encode(sf_term('feedback_comment_add_error', $uiLang)) ?>,
        commentDeleted: <?= json_encode(sf_term('feedback_comment_deleted', $uiLang)) ?>,
        commentDeleteError: <?= json_encode(sf_term('feedback_comment_delete_error', $uiLang)) ?>,
        mergeTargetRequired: <?= json_encode(sf_term('feedback_merge_target_required', $uiLang)) ?>,
        mergeSuccess: <?= json_encode(sf_term('feedback_merge_success', $uiLang)) ?>,
        mergeError: <?= json_encode(sf_term('feedback_merge_error', $uiLang)) ?>,
        selectTarget: <?= json_encode(sf_term('feedback_select_target', $uiLang)) ?>
    };
    
// Modal handling
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) {
        return;
    }

    modal.setAttribute('aria-hidden', 'false');

    if (typeof window.sfOpenModal === 'function') {
        window.sfOpenModal(modal);
        return;
    }

    modal.classList.remove('hidden');
    document.body.classList.add('sf-modal-open');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) {
        return;
    }

    modal.setAttribute('aria-hidden', 'true');

    if (typeof window.sfCloseModal === 'function') {
        window.sfCloseModal(modal);
        return;
    }

    modal.classList.add('hidden');
    document.body.classList.remove('sf-modal-open');
}
    
    // New feedback button
    document.getElementById('btnNewFeedback')?.addEventListener('click', function() {
        document.getElementById('formNewFeedback')?.reset();
        openModal('modalNewFeedback');
    });
    
    // Submit new feedback
    document.getElementById('btnSubmitFeedback')?.addEventListener('click', async function() {
        const form = document.getElementById('formNewFeedback');
        const formData = new FormData(form);
        
        try {
            const response = await fetch(BASE_URL + '/app/api/feedback_create.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.ok) {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('success', <?= json_encode(sf_term('feedback_created_success', $uiLang)) ?>);
                }
                closeModal('modalNewFeedback');
                setTimeout(() => window.location.reload(), 500);
            } else {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', data.error || 'Error creating feedback');
                } else {
                    alert(data.error || 'Error creating feedback');
                }
            }
        } catch (error) {
            console.error('Error:', error);
            if (typeof window.sfToast === 'function') {
                window.sfToast('danger', 'Network error');
            } else {
                alert('Network error');
            }
        }
    });
    
    // Admin: Manage feedback buttons
    if (IS_ADMIN) {
        document.querySelectorAll('.btn-manage-feedback').forEach(btn => {
            btn.addEventListener('click', function() {
                const feedbackId = parseInt(this.dataset.feedbackId);
                const feedback = FEEDBACK_DATA.find(f => f.id == feedbackId);
                
                if (feedback) {
                    document.getElementById('manageFeedbackId').value = feedback.id;
                    document.getElementById('manageFeedbackTitle').textContent = feedback.title;
                    document.getElementById('manageFeedbackDescription').textContent = feedback.description;
                    document.getElementById('manageStatus').value = feedback.status;
                    document.getElementById('manageAdminNotes').value = feedback.admin_notes || '';
                    openModal('modalManageFeedback');
                }
            });
        });
        
        // Save managed feedback
        document.getElementById('btnSaveFeedback')?.addEventListener('click', async function() {
            const form = document.getElementById('formManageFeedback');
            const formData = new FormData(form);
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_update.php', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', <?= json_encode(sf_term('feedback_updated_success', $uiLang)) ?>);
                    }
                    closeModal('modalManageFeedback');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || 'Error updating feedback');
                    } else {
                        alert(data.error || 'Error updating feedback');
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', 'Network error');
                } else {
                    alert('Network error');
                }
            }
        });
    }
    
    // Create Update from Feedback
    if (IS_ADMIN) {
        const SUPPORTED_LANGS_FB = <?= json_encode($supportedLangs ?? ['fi', 'sv', 'en', 'it', 'el']) ?>;

        // Language tab switching for create-update modal
        document.querySelectorAll('.sf-cu-lang-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const lang = this.dataset.lang;
                document.querySelectorAll('.sf-cu-lang-tab').forEach(t => {
                    t.classList.remove('sf-btn-primary');
                    t.classList.add('sf-btn-secondary');
                });
                this.classList.add('sf-btn-primary');
                this.classList.remove('sf-btn-secondary');
                document.querySelectorAll('.sf-cu-lang-panel').forEach(p => {
                    p.style.display = p.dataset.lang === lang ? '' : 'none';
                });
            });
        });

        document.getElementById('btnCreateUpdateFromFeedback')?.addEventListener('click', function() {
            // Pre-fill FI title/content from feedback title/description
            const feedbackId = parseInt(document.getElementById('manageFeedbackId').value, 10);
            const feedbackTitle = document.getElementById('manageFeedbackTitle').textContent.trim();
            const feedbackDesc = document.getElementById('manageFeedbackDescription').textContent.trim();
            const adminNotes = document.getElementById('manageAdminNotes').value.trim();

            // Reset fields
            SUPPORTED_LANGS_FB.forEach(lang => {
                const t = document.getElementById('cuTitle_' + lang);
                const c = document.getElementById('cuContent_' + lang);
                if (t) t.value = '';
                if (c) c.value = '';
            });
            document.getElementById('cuIsPublished').checked = false;

            // Pre-fill Finnish fields
            const fiTitle = document.getElementById('cuTitle_fi');
            const fiContent = document.getElementById('cuContent_fi');
            if (fiTitle) fiTitle.value = feedbackTitle;
            if (fiContent) fiContent.value = adminNotes || feedbackDesc;

            document.getElementById('createUpdateFeedbackId').value = feedbackId;

            // Activate first tab
            const firstTab = document.querySelector('.sf-cu-lang-tab');
            if (firstTab) firstTab.click();

            closeModal('modalManageFeedback');
            openModal('modalCreateUpdate');
        });

        document.getElementById('btnSaveCreateUpdate')?.addEventListener('click', async function() {
            const feedbackId = parseInt(document.getElementById('createUpdateFeedbackId').value, 10) || 0;
            const isPublished = document.getElementById('cuIsPublished').checked ? 1 : 0;

            const translations = {};
            SUPPORTED_LANGS_FB.forEach(lang => {
                const title = (document.getElementById('cuTitle_' + lang)?.value || '').trim();
                const content = (document.getElementById('cuContent_' + lang)?.value || '').trim();
                if (title || content) {
                    translations[lang] = { title, content };
                }
            });

            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('feedback_id', feedbackId);
            formData.append('is_published', isPublished);
            formData.append('translations', JSON.stringify(translations));

            try {
                const response = await fetch(BASE_URL + '/app/api/changelog_create.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: formData
                });
                const data = await response.json();
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', <?= json_encode(sf_term('admin_updates_saved', $uiLang)) ?>);
                    }
                    closeModal('modalCreateUpdate');
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || <?= json_encode(sf_term('updates_error_save', $uiLang)) ?>);
                    } else {
                        alert(data.error || <?= json_encode(sf_term('updates_error_save', $uiLang)) ?>);
                    }
                }
            } catch (e) {
                console.error(e);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', <?= json_encode(sf_term('updates_error_save', $uiLang)) ?>);
                }
            }
        });
    }
    
    // Delete feedback (admin or owner)
    document.querySelectorAll('.btn-delete-feedback').forEach(btn => {
        btn.addEventListener('click', async function() {
            const feedbackId = this.dataset.feedbackId;
            const feedbackTitle = this.dataset.feedbackTitle;
            
            // Confirmation dialog
            const confirmMessage = i18n.deleteConfirm.replace('{title}', feedbackTitle);
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Send delete request
            const formData = new FormData();
            formData.append('feedback_id', feedbackId);
            formData.append('csrf_token', CSRF_TOKEN);
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_delete.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    // Show success message (toast or alert)
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', i18n.deletedSuccess);
                    } else {
                        alert(i18n.deletedSuccess);
                    }
                    // Reload page
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    // Show error message
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || i18n.deleteError);
                    } else {
                        alert(data.error || i18n.deleteError);
                    }
                }
            } catch (error) {
                console.error('Delete error:', error);
                alert(i18n.networkError);
            }
        });
    });
    
    // Helper function to create icon element
    function createIcon(filename, className = 'sf-icon-sm') {
        const img = document.createElement('img');
        img.src = BASE_URL + '/assets/img/icons/' + filename;
        img.alt = '';
        img.className = className;
        img.setAttribute('aria-hidden', 'true');
        return img;
    }

    function closeFeedbackActionMenus(exceptMenu) {
        document.querySelectorAll('.sf-feedback-actions-menu').forEach(menu => {
            if (exceptMenu && menu === exceptMenu) {
                return;
            }

            const toggle = menu.querySelector('[data-feedback-actions-toggle]');
            const dropdown = menu.querySelector('.sf-feedback-actions-dropdown');

            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }

            if (dropdown) {
                dropdown.hidden = true;
            }
        });
    }

    document.querySelectorAll('[data-feedback-actions-toggle]').forEach(toggle => {
        toggle.addEventListener('click', function(event) {
            event.stopPropagation();

            const menu = this.closest('.sf-feedback-actions-menu');
            const dropdown = menu ? menu.querySelector('.sf-feedback-actions-dropdown') : null;

            if (!menu || !dropdown) {
                return;
            }

            const isOpen = !dropdown.hidden;
            closeFeedbackActionMenus(menu);

            dropdown.hidden = isOpen;
            this.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.sf-feedback-actions-menu')) {
            closeFeedbackActionMenus(null);
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeFeedbackActionMenus(null);
        }
    });
	
    // Toggle comments section
    document.querySelectorAll('.sf-feedback-comments-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const feedbackId = this.dataset.feedbackId;
            const commentsList = document.getElementById('comments-' + feedbackId);
            const icon = this.querySelector('.sf-toggle-icon');
            
            // Clear existing content safely
            while (icon.firstChild) {
                icon.removeChild(icon.firstChild);
            }
            
            if (commentsList.style.display === 'none') {
                commentsList.style.display = 'flex';
                icon.appendChild(createIcon('chevron-up.svg'));
            } else {
                commentsList.style.display = 'none';
                icon.appendChild(createIcon('chevron-down.svg'));
            }
        });
    });
    
    // Add comment
    document.querySelectorAll('.sf-btn-send-comment').forEach(btn => {
        btn.addEventListener('click', async function() {
            const feedbackId = this.dataset.feedbackId;
            const form = this.closest('.sf-feedback-comment-form');
            const textarea = form.querySelector('textarea');
            const comment = textarea.value.trim();
            
            if (!comment) {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.commentEmpty);
                } else {
                    alert(i18n.commentEmpty);
                }
                return;
            }
            
            const formData = new FormData();
            formData.append('feedback_id', feedbackId);
            formData.append('comment', comment);
            formData.append('csrf_token', CSRF_TOKEN);
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_comment_add.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', i18n.commentAdded);
                    }
                    // Reload to show new comment
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || i18n.commentAddError);
                    } else {
                        alert(data.error || i18n.commentAddError);
                    }
                }
            } catch (e) {
                console.error('Error:', e);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.networkError);
                } else {
                    alert(i18n.networkError);
                }
            }
        });
    });
    
    // Delete comment
    document.querySelectorAll('.sf-feedback-comment-delete').forEach(btn => {
        btn.addEventListener('click', async function() {
            const commentId = this.dataset.commentId;
            
            if (!confirm(<?= json_encode(sf_term('feedback_comment_delete_confirm', $uiLang)) ?>)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('comment_id', commentId);
            formData.append('csrf_token', CSRF_TOKEN);
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_comment_delete.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', i18n.commentDeleted);
                    }
                    // Reload to update comment count
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || i18n.commentDeleteError);
                    } else {
                        alert(data.error || i18n.commentDeleteError);
                    }
                }
            } catch (e) {
                console.error('Error:', e);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.networkError);
                } else {
                    alert(i18n.networkError);
                }
            }
        });
    });
    
    // Admin filters
    if (IS_ADMIN) {
        const filterForm = document.querySelector('.sf-feedback-filter-form');
        const filterStatus = document.getElementById('filterStatus');
        const filterCategory = document.getElementById('filterCategory');
        const filterReporter = document.getElementById('filterReporter');

        function markFeedbackFiltering() {
            const page = document.querySelector('.sf-feedback-page');

            if (page) {
                page.classList.add('is-filtering');
            }
        }

        function submitFeedbackFilters() {
            if (!filterForm) {
                return;
            }

            markFeedbackFiltering();

            window.setTimeout(function () {
                const params = new URLSearchParams(new FormData(filterForm));
                const action = filterForm.getAttribute('action') || window.location.pathname;

                window.location.href = action + '?' + params.toString();
            }, 80);
        }

        if (filterForm) {
            filterForm.addEventListener('submit', function() {
                markFeedbackFiltering();
            });
        }

        if (filterStatus) {
            filterStatus.addEventListener('change', submitFeedbackFilters);
        }

        if (filterCategory) {
            filterCategory.addEventListener('change', submitFeedbackFilters);
        }

        if (filterReporter) {
            filterReporter.addEventListener('change', submitFeedbackFilters);
        }
        
        // Admin: Merge feedback button
        document.querySelectorAll('.btn-merge-feedback').forEach(btn => {
            btn.addEventListener('click', function() {
                const sourceId = parseInt(this.dataset.feedbackId);
                const sourceTitle = this.dataset.feedbackTitle;
                
                document.getElementById('mergeSourceId').value = sourceId;
                document.getElementById('mergeSourceTitle').textContent = sourceTitle;
                
                // Populate target select with other feedbacks
                const targetSelect = document.getElementById('mergeTargetId');
                targetSelect.innerHTML = '<option value="">-- ' + i18n.selectTarget + ' --</option>';
                
                FEEDBACK_DATA.forEach(feedback => {
                    if (feedback.id !== sourceId) {
                        const option = document.createElement('option');
                        option.value = feedback.id;
                        option.textContent = '#' + feedback.id + ' - ' + feedback.title;
                        targetSelect.appendChild(option);
                    }
                });
                
                openModal('modalMergeFeedback');
            });
        });
        
        // Save merged feedback
        document.getElementById('btnMergeFeedback')?.addEventListener('click', async function() {
            const form = document.getElementById('formMergeFeedback');
            const formData = new FormData(form);
            
            if (!formData.get('target_id')) {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.mergeTargetRequired);
                } else {
                    alert(i18n.mergeTargetRequired);
                }
                return;
            }
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_merge.php', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', i18n.mergeSuccess);
                    }
                    closeModal('modalMergeFeedback');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || i18n.mergeError);
                    } else {
                        alert(data.error || i18n.mergeError);
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.networkError);
                } else {
                    alert(i18n.networkError);
                }
            }
        });
    }
})();
</script>

<style>
/* Icon helper classes */
.sf-icon,
.sf-icon-feedback,
.sf-icon-orig {
    width: 1.2em;
    height: 1.2em;
    margin-right: 5px;
    vertical-align: middle;
    display: inline-block;
}

/* Default icon filter (white) */
.sf-icon {
    filter: brightness(0) invert(1);
}
.sf-icon-orig {
    filter: none;
}
/* Feedback icon filter (original color) */
.sf-icon-feedback {
    filter: brightness(0) invert(0);
}

.sf-icon-sm {
    width: 0.75em;
    height: 0.75em;
    vertical-align: middle;
    display: inline-block;
}

/* Pseudo-element icon base styles */
.sf-icon-before::before {
    content: '';
    display: inline-block;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

/* Filter select styles - modernized with dropdown arrow */
.sf-filter-select {
    min-width: 180px;
    padding: 0.5rem 2rem 0.5rem 0.75rem;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
    color: #374151;
    cursor: pointer;
    transition: all 0.2s ease;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 12px;
}

.sf-filter-select:hover {
    border-color: #9ca3af;
    background-color: #fafafa;
}

.sf-filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Form input styles for modals */
.sf-form-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 1rem;
    background: white;
    transition: border-color 0.15s;
}

.sf-form-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

textarea.sf-form-input {
    resize: vertical;
    font-family: inherit;
}

/* Form group styles */
.sf-form-group {
    margin-bottom: 1.5rem;
}

.sf-form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #334155;
}

/* Feedback card header */
.sf-feedback-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
    gap: 1rem;
    flex-wrap: wrap;
}

.sf-feedback-card-badges {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

.sf-feedback-card-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    margin-left: auto;
}

.sf-feedback-comments-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.sf-feedback-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.875rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: white;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.sf-feedback-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
}

/* Status badge animations */
@keyframes sf-pulse {
    0%, 100% {
        opacity: 1;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 0 0 0 rgba(5, 150, 105, 0.7);
    }
    50% {
        opacity: 0.9;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 0 0 8px rgba(5, 150, 105, 0);
    }
}

@keyframes sf-spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.sf-status-new-pulse {
    animation: sf-pulse 2s ease-in-out infinite;
}

.sf-spinner-icon {
    display: inline-block;
    width: 1.2em;
    height: 1.2em;
    margin-right: 0.375rem;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: sf-spin 0.8s linear infinite;
}

.sf-feedback-badge .sf-icon {
    width: 1.2em;
    height: 1.2em;
    margin-right: 0.375rem;
    vertical-align: middle;
}

.sf-feedback-card-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #0f172a;
    margin: 0 0 0.75rem 0;
}

.sf-feedback-card-description {
    color: #475569;
    line-height: 1.6;
    margin: 0 0 1rem 0;
}

.sf-feedback-card-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.8125rem;
    color: #64748b;
    flex-wrap: wrap;
    align-items: center;
    padding: 0.5rem 0;
}

.sf-feedback-card-meta > span {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.sf-feedback-card-meta > span::before {
    content: '';
    width: 0.875rem;
    height: 0.875rem;
    display: inline-block;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-card-meta > span:first-child::before {
    background-image: url('<?= $base ?>/assets/img/icons/user.svg');
}

.sf-feedback-card-meta > span:nth-child(2)::before {
    background-image: url('<?= $base ?>/assets/img/icons/calendar.svg');
}

.sf-feedback-card-resolved {
    display: flex;
    gap: 1rem;
    font-size: 0.8125rem;
    margin-top: 0.75rem;
    padding: 0.75rem 1rem;
    background: linear-gradient(135deg, #d1fae5 0%, #ecfdf5 100%);
    border: 1px solid #a7f3d0;
    border-radius: 0.5rem;
    color: #065f46;
    flex-wrap: wrap;
    align-items: center;
    box-shadow: 0 1px 2px rgba(5, 150, 105, 0.05);
}

.sf-feedback-card-resolved > span {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.sf-feedback-card-resolved > span::before {
    content: '';
    width: 0.875rem;
    height: 0.875rem;
    display: inline-block;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-card-resolved > span:first-child::before {
    background-image: url('<?= $base ?>/assets/img/icons/check.svg');
}

.sf-feedback-card-resolved > span:nth-child(2)::before {
    background-image: url('<?= $base ?>/assets/img/icons/calendar.svg');
}

.sf-feedback-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: #64748b;
}

.sf-feedback-display-text {
    background: #f8fafc;
    padding: 0.75rem;
    border-radius: 0.375rem;
    color: #475569;
    white-space: pre-wrap;
}

/* Comments section styles */
.sf-feedback-comments-section {
    margin-top: 1rem;
    border-top: 1px solid #e2e8f0;
    padding-top: 1rem;
}

.sf-feedback-comments-toggle {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    cursor: pointer;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1rem;
    transition: all 0.2s ease;
    width: auto;
    min-width: 0;
    justify-content: flex-start;
    border-radius: 0.5rem;
    font-weight: 500;
}

.sf-feedback-comments-toggle:hover {
    background: #e0e7ff;
    border-color: #c7d2fe;
    color: #4338ca;
}

.sf-toggle-icon {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: auto;
    height: auto;
    padding: 0;
    background: transparent;
    border: 0;
    box-shadow: none;
}

.sf-feedback-comments-list {
    margin-top: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.sf-feedback-comment {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    padding: 1rem;
    position: relative;
    transition: all 0.2s ease;
}

.sf-feedback-comment:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.sf-feedback-comment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.625rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.sf-feedback-comment-author {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.sf-feedback-comment-author::before {
    content: '';
    width: 0.875rem;
    height: 0.875rem;
    display: inline-block;
    background-image: url('<?= $base ?>/assets/img/icons/message-square.svg');
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-comment-header > span:last-child {
    font-size: 0.75rem;
    color: #94a3b8;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.sf-feedback-comment-header > span:last-child::before {
    content: '';
    width: 0.75rem;
    height: 0.75rem;
    display: inline-block;
    background-image: url('<?= $base ?>/assets/img/icons/clock.svg');
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-comment-text {
    color: #334155;
    line-height: 1.5;
    font-size: 0.875rem;
}

.sf-feedback-comment-delete {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.2s, color 0.2s;
    font-size: 1rem;
    padding: 0.25rem;
}

.sf-feedback-comment:hover .sf-feedback-comment-delete {
    opacity: 1;
}

.sf-feedback-comment-delete:hover {
    color: #ef4444;
}

.sf-feedback-comment-form {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
    align-items: flex-end;
    background: #ffffff;
    padding: 0.75rem;
    border-radius: 0.75rem;
    border: 1px solid #e2e8f0;
}

.sf-feedback-comment-form textarea {
    flex: 1;
    resize: none;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.625rem 0.875rem;
    font-size: 0.875rem;
    min-height: 60px;
    transition: all 0.2s ease;
    font-family: inherit;
    background: #fafafa;
}

.sf-feedback-comment-form textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    background: #ffffff;
}

.sf-feedback-comments-count {
    background: #e0e7ff;
    padding: 0.25rem 0.625rem;
    border-radius: 1rem;
    font-size: 0.75rem;
    color: #4338ca;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border: 1px solid #c7d2fe;
}

/* Merged indicator */
.sf-feedback-merged {
    background: linear-gradient(135deg, #fef3c7 0%, #fef9e7 100%);
    border: 1px solid #fde047;
    color: #92400e;
    padding: 0.625rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    margin-top: 0.75rem;
    font-weight: 500;
    box-shadow: 0 1px 2px rgba(146, 64, 14, 0.05);
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

@media (max-width: 768px) {
    .sf-feedback-card-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .sf-feedback-card-badges {
        margin-bottom: 0.5rem;
    }
    
    .sf-feedback-card-actions {
        margin-left: 0;
    }
    
    /* Action buttons full width */
    .sf-feedback-card-actions {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
    }
    
    .btn-manage-feedback,
    .btn-merge-feedback,
    .btn-delete-feedback {
        width: 100% !important;
        justify-content: center !important;
        padding: 0.75rem 1rem !important;
        font-size: 0.9rem !important;
        min-height: 44px; /* Apple/Google recommendation for touch targets */
    }
    
    .sf-feedback-card-meta,
    .sf-feedback-card-resolved {
        flex-direction: column;
        gap: 0.5rem;
        align-items: flex-start;
    }
    
    /* Modal on mobile */
    .sf-modal-content {
        width: 95%;
        max-width: 95%;
        margin: 1rem;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .sf-modal-header h3 {
        font-size: 1.25rem;
    }
    
    .sf-modal-actions {
        flex-direction: column;
        gap: 0.75rem;
    }
    
    .sf-modal-actions .sf-btn {
        width: 100%;
        justify-content: center;
        min-height: 44px;
    }
}

/* Very small screens */
@media (max-width: 480px) {
    .sf-feedback-badge {
        font-size: 0.75rem;
        padding: 0.3rem 0.7rem;
    }
    
    .sf-feedback-card-title {
        font-size: 1.1rem;
    }
    
    .btn-manage-feedback,
    .btn-merge-feedback,
    .btn-delete-feedback {
        padding: 0.875rem 1rem !important;
        font-size: 1rem !important;
        min-height: 48px; /* Larger touch target on small screens */
    }
    
    .sf-feedback-comments-toggle {
        font-size: 0.8125rem;
        padding: 0.5rem 0.75rem;
    }
}
.sf-feedback-page {
    padding-bottom: 48px;
}

.sf-feedback-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    width: 100%;
    margin: 0 0 34px;
}

.sf-feedback-hero-main {
    flex: 1 1 auto;
    min-width: 0;
}

.sf-feedback-description {
    max-width: 820px;
    margin: 1.25rem 0 0;
    color: rgba(255, 255, 255, 0.76);
    font-size: 1rem;
    line-height: 1.55;
}

.sf-feedback-hero-actions {
    flex-shrink: 0;
    margin-top: 10px;
}

.sf-feedback-hero-actions .sf-btn-primary {
    border: 1px solid rgba(254, 224, 0, 0.85);
    border-radius: 999px;
    background: #2563eb;
    color: #ffffff;
    box-shadow: 0 12px 30px rgba(37, 99, 235, 0.26);
}

.sf-feedback-hero-actions .sf-btn-primary:hover {
    transform: translateY(-1px);
    background: #1d4ed8;
    box-shadow: 0 16px 36px rgba(37, 99, 235, 0.34);
}

.sf-feedback-page .sf-filters {
    display: flex;
    align-items: flex-end;
    gap: 16px;
    flex-wrap: wrap;
    margin: 0 0 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.22);
}

.sf-feedback-page .sf-filter-label {
    display: block;
    margin-bottom: 8px;
    color: rgba(255, 255, 255, 0.62);
    font-size: 0.86rem;
    font-weight: 500;
}

.sf-feedback-page .sf-filter-select {
    min-width: 190px;
    min-height: 42px;
    border: 1px solid rgba(148, 163, 184, 0.32);
    border-radius: 12px;
    background-color: rgba(15, 23, 42, 0.72);
    color: rgba(255, 255, 255, 0.9);
    box-shadow: none;
}

.sf-feedback-page .sf-filter-select:hover {
    border-color: rgba(255, 255, 255, 0.36);
    background-color: rgba(15, 23, 42, 0.9);
}

.sf-feedback-page .sf-filter-select:focus {
    border-color: rgba(37, 99, 235, 0.9);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.22);
}

.sf-feedback-list {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.sf-feedback-list .sf-content-card {
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 31, 0.98));
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 18px;
    padding: 24px;
    color: rgba(255, 255, 255, 0.9);
    box-shadow: 0 18px 44px rgba(0, 0, 0, 0.22);
    transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.sf-feedback-list .sf-content-card:hover {
    transform: translateY(-3px);
    border-color: rgba(255, 255, 255, 0.34);
    box-shadow: 0 24px 54px rgba(0, 0, 0, 0.34);
}

.sf-feedback-card-title {
    color: #ffffff;
    font-size: 1.2rem;
    font-weight: 750;
}

.sf-feedback-card-description {
    max-width: 920px;
    color: rgba(226, 232, 240, 0.76);
}

.sf-feedback-card-meta {
    color: rgba(203, 213, 225, 0.72);
}

.sf-feedback-card-meta strong {
    color: #ffffff;
}

.sf-feedback-card-meta > span::before,
.sf-feedback-comment-author::before,
.sf-feedback-comment-header > span:last-child::before {
    filter: brightness(0) invert(1);
    opacity: 0.72;
}

.sf-feedback-card-resolved > span:nth-child(2)::before {
    filter: brightness(0) invert(1);
    opacity: 0.72;
}

.sf-feedback-badge {
    box-shadow: none;
}

.sf-feedback-badge-category {
    background: rgba(37, 99, 235, 0.18) !important;
    border: 1px solid rgba(37, 99, 235, 0.34);
    color: #bfdbfe !important;
}

.sf-feedback-badge-status {
    background: rgba(148, 163, 184, 0.16) !important;
    border: 1px solid rgba(148, 163, 184, 0.26);
    color: rgba(226, 232, 240, 0.9) !important;
}

.sf-feedback-badge-status[data-status="resolved"] {
    background: rgba(16, 185, 129, 0.14) !important;
    border-color: rgba(16, 185, 129, 0.34);
    color: #bbf7d0 !important;
}

.sf-feedback-badge-status[data-status="critical"] {
    background: rgba(239, 68, 68, 0.14) !important;
    border-color: rgba(239, 68, 68, 0.34);
    color: #fecaca !important;
}

.sf-feedback-card-actions .sf-btn-secondary {
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(15, 23, 42, 0.72);
    color: rgba(255, 255, 255, 0.84);
}

.sf-feedback-card-actions .sf-btn-secondary:hover {
    border-color: rgba(255, 255, 255, 0.34);
    background: rgba(15, 23, 42, 0.96);
    color: #ffffff;
}

.sf-feedback-card-actions .sf-btn-danger {
    border-radius: 999px;
}

.sf-feedback-card-actions .sf-icon-feedback,
.sf-feedback-comments-toggle .sf-icon-feedback,
.sf-feedback-comments-toggle .sf-icon-sm {
    filter: brightness(0) invert(1);
    opacity: 0.86;
}

.sf-feedback-card-resolved {
    background: rgba(16, 185, 129, 0.10);
    border-color: rgba(16, 185, 129, 0.32);
    color: #bbf7d0;
}

.sf-feedback-card-resolved strong {
    color: #ffffff;
}

.sf-feedback-comments-section {
    border-top-color: rgba(148, 163, 184, 0.22);
}

.sf-feedback-comments-toggle {
    width: auto;
    min-width: 0;
    background: rgba(15, 23, 42, 0.62);
    border-color: rgba(148, 163, 184, 0.24);
    color: rgba(255, 255, 255, 0.82);
}

.sf-feedback-comments-toggle:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.28);
    color: #ffffff;
}

.sf-feedback-comments-toggle .sf-toggle-icon {
    width: 28px;
    height: 28px;
    margin-left: 8px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.sf-feedback-comment {
    background: rgba(15, 23, 42, 0.68);
    border-color: rgba(148, 163, 184, 0.22);
}

.sf-feedback-comment:hover {
    border-color: rgba(255, 255, 255, 0.28);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22);
}

.sf-feedback-comment-header {
    border-bottom-color: rgba(148, 163, 184, 0.2);
}

.sf-feedback-comment-author {
    color: #ffffff;
}

.sf-feedback-comment-header > span:last-child {
    color: rgba(203, 213, 225, 0.66);
}

.sf-feedback-comment-text {
    color: rgba(226, 232, 240, 0.82);
}

.sf-feedback-comment-form {
    background: rgba(15, 23, 42, 0.72);
    border-color: rgba(148, 163, 184, 0.24);
}

.sf-feedback-comment-form textarea {
    background: rgba(2, 6, 23, 0.45);
    border-color: rgba(148, 163, 184, 0.28);
    color: #ffffff;
}

.sf-feedback-comment-form textarea:focus {
    background: rgba(2, 6, 23, 0.68);
    border-color: #2563eb;
}

.sf-feedback-comments-count {
    background: rgba(37, 99, 235, 0.16);
    border-color: rgba(37, 99, 235, 0.28);
    color: #bfdbfe;
}

.sf-feedback-empty {
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 31, 0.98));
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 18px;
    color: rgba(226, 232, 240, 0.78);
}

.sf-feedback-merged {
    background: rgba(234, 179, 8, 0.10);
    border-color: rgba(234, 179, 8, 0.26);
    color: #fde68a;
}

@media (max-width: 900px) {
    .sf-feedback-hero {
        display: block;
        margin-bottom: 24px;
    }

    .sf-feedback-description {
        margin-top: 1rem;
        font-size: 0.95rem;
    }

    .sf-feedback-hero-actions {
        margin-top: 18px;
    }

    .sf-feedback-hero-actions .sf-btn-primary {
        width: 100%;
        justify-content: center;
        min-height: 46px;
    }

    .sf-feedback-page .sf-filters {
        align-items: stretch;
        gap: 12px;
    }

    .sf-feedback-page .sf-filter-group,
    .sf-feedback-page .sf-filter-select {
        width: 100%;
    }

    .sf-feedback-list .sf-content-card {
        padding: 18px;
    }
}
#modalNewFeedback .sf-modal-content {
    width: min(92vw, 760px);
    max-height: min(92vh, 760px);
    overflow: hidden;
}

#modalNewFeedback .sf-modal-header {
    padding: 22px 24px 14px;
}

#modalNewFeedback .sf-modal-header h3 {
    font-size: 1.25rem;
}

#modalNewFeedback .sf-modal-body {
    display: grid;
    gap: 14px;
    padding: 14px 24px 12px;
    overflow: visible;
}

#modalNewFeedback .sf-form-group {
    margin-bottom: 0;
}

#modalNewFeedback .sf-form-group label {
    margin-bottom: 6px;
    font-size: 0.86rem;
}

#modalNewFeedback .sf-form-input {
    min-height: 44px;
    padding: 0.6rem 0.8rem;
    font-size: 0.95rem;
}

#modalNewFeedback textarea.sf-form-input {
    min-height: 118px;
    max-height: 150px;
    resize: vertical;
}

#modalNewFeedback .sf-toggle-section-heading {
    margin: 4px 0 8px;
    font-size: 0.82rem;
    letter-spacing: 0.06em;
}

#modalNewFeedback .sf-toggle-card-stack {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

#modalNewFeedback .sf-toggle-card {
    min-height: 52px;
    padding: 10px 12px;
    border-radius: 14px;
}

#modalNewFeedback .sf-toggle-label {
    font-size: 0.9rem;
    line-height: 1.25;
}

#modalNewFeedback .sf-help-text {
    margin: 8px 0 0;
    font-size: 0.8rem;
    line-height: 1.35;
}

#modalNewFeedback .sf-modal-actions {
    padding: 14px 24px 22px;
    border-top: 1px solid #e2e8f0;
}

@media (max-width: 640px) {
    #modalNewFeedback .sf-modal-content {
        width: min(94vw, 520px);
        max-height: 92vh;
    }

    #modalNewFeedback .sf-modal-header {
        padding: 18px 18px 12px;
    }

    #modalNewFeedback .sf-modal-body {
        padding: 12px 18px;
        gap: 12px;
    }

    #modalNewFeedback textarea.sf-form-input {
        min-height: 96px;
        max-height: 120px;
    }

    #modalNewFeedback .sf-toggle-card-stack {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    #modalNewFeedback .sf-modal-actions {
        padding: 12px 18px 18px;
    }
}
	/* Compact mobile feedback cards */
@media (max-width: 768px) {
    .sf-feedback-list .sf-content-card {
        padding: 16px;
        border-radius: 16px;
    }

    .sf-feedback-card-header {
        gap: 12px;
        margin-bottom: 14px;
    }

    .sf-feedback-card-badges {
        gap: 8px;
        margin-bottom: 0;
    }

    .sf-feedback-comments-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
    }

    .sf-feedback-comments-toggle {
        flex: 1 1 auto;
        min-width: 0;
        min-height: 40px;
        padding: 0.55rem 0.7rem;
    }

    .sf-feedback-card-actions {
        display: flex;
        flex: 0 0 auto;
        gap: 6px;
        width: auto;
        margin: 0;
    }

    .sf-feedback-card-actions .sf-btn {
        width: 40px;
        height: 40px;
        min-height: 40px !important;
        padding: 0 !important;
        border-radius: 999px;
    }

    .sf-feedback-card-actions .btn-delete-feedback {
        width: 40px;
        height: 40px;
        min-height: 40px !important;
    }

    .sf-feedback-card-actions .sf-feedback-action-label {
        display: none;
    }

    .sf-feedback-card-title {
        margin-top: 6px;
        font-size: 1.08rem;
        line-height: 1.25;
    }

    .sf-feedback-card-description {
        font-size: 0.92rem;
        line-height: 1.45;
    }
}

/* Compact and scrollable new feedback modal */
#modalNewFeedback .sf-modal-content {
    width: min(94vw, 720px);
    max-height: min(92dvh, 720px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

#modalNewFeedback .sf-modal-header {
    flex: 0 0 auto;
    padding: 18px 22px 12px;
}

#modalNewFeedback .sf-modal-header h3 {
    font-size: 1.18rem;
}

#modalNewFeedback .sf-modal-body {
    flex: 1 1 auto;
    display: grid;
    gap: 12px;
    padding: 12px 22px;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}

#modalNewFeedback .sf-form-group {
    margin-bottom: 0;
}

#modalNewFeedback .sf-form-group label {
    margin-bottom: 5px;
    font-size: 0.84rem;
}

#modalNewFeedback .sf-form-input {
    min-height: 42px;
    padding: 0.55rem 0.75rem;
    font-size: 0.94rem;
    border-radius: 10px;
}

#modalNewFeedback textarea.sf-form-input {
    min-height: 92px;
    max-height: 120px;
    resize: vertical;
}

#modalNewFeedback .sf-toggle-section-heading {
    margin: 2px 0 6px;
    font-size: 0.78rem;
    letter-spacing: 0.06em;
}

#modalNewFeedback .sf-toggle-card-stack {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

#modalNewFeedback .sf-toggle-card {
    min-height: 48px;
    padding: 9px 10px;
    border-radius: 12px;
}

#modalNewFeedback .sf-toggle-label {
    font-size: 0.84rem;
    line-height: 1.2;
}

#modalNewFeedback .sf-help-text {
    margin: 6px 0 0;
    font-size: 0.76rem;
    line-height: 1.3;
}

#modalNewFeedback .sf-modal-actions {
    flex: 0 0 auto;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 12px 22px 18px;
    border-top: 1px solid #e2e8f0;
    background: #ffffff;
}

#modalNewFeedback .sf-modal-actions .sf-btn {
    min-height: 40px;
    border-radius: 999px;
}

@media (max-width: 640px) {
    #modalNewFeedback .sf-modal-content {
        width: min(94vw, 420px);
        max-height: 90dvh;
    }

    #modalNewFeedback .sf-modal-header {
        padding: 16px 18px 10px;
    }

    #modalNewFeedback .sf-modal-body {
        gap: 10px;
        padding: 10px 18px;
    }

    #modalNewFeedback textarea.sf-form-input {
        min-height: 78px;
        max-height: 96px;
    }

    #modalNewFeedback .sf-toggle-card-stack {
        grid-template-columns: 1fr;
        gap: 7px;
    }

    #modalNewFeedback .sf-toggle-card {
        min-height: 44px;
    }

    #modalNewFeedback .sf-modal-actions {
        padding: 10px 18px 16px;
    }
}

.sf-feedback-comments-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
}

.sf-feedback-actions-menu {
    position: relative;
    flex: 0 0 auto;
}

.sf-feedback-comments-toggle,
.sf-feedback-actions-toggle {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 40px;
    padding: 0 14px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.72);
    color: rgba(255, 255, 255, 0.86);
    font: inherit;
    font-size: 0.88rem;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
}

.sf-feedback-comments-toggle:hover,
.sf-feedback-actions-toggle:hover,
.sf-feedback-actions-toggle[aria-expanded="true"] {
    border-color: rgba(254, 224, 0, 0.38);
    background: rgba(15, 23, 42, 0.96);
    color: #ffffff;
}

.sf-feedback-comments-toggle .sf-toggle-icon,
.sf-feedback-actions-toggle .sf-icon-sm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    margin-left: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
}

.sf-feedback-comments-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 7px;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.22);
    border: 1px solid rgba(37, 99, 235, 0.34);
    color: #bfdbfe;
    font-size: 0.78rem;
    font-weight: 800;
    line-height: 1;
}

.sf-feedback-actions-dropdown {
    position: absolute;
    right: 0;
    bottom: calc(100% + 8px);
    z-index: 20;
    width: 220px;
    padding: 8px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 14px;
    background: #0f172a;
    box-shadow: 0 18px 42px rgba(0, 0, 0, 0.38);
}

.sf-feedback-actions-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 40px;
    padding: 0 12px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: rgba(255, 255, 255, 0.86);
    font: inherit;
    font-size: 0.9rem;
    font-weight: 650;
    text-align: left;
    cursor: pointer;
}

.sf-feedback-actions-item:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}

.sf-feedback-actions-item-danger {
    color: #fecaca;
}

.sf-feedback-actions-item-danger:hover {
    background: rgba(239, 68, 68, 0.16);
    color: #ffffff;
}

.sf-feedback-comments-toggle .sf-icon-feedback,
.sf-feedback-comments-toggle .sf-icon,
.sf-feedback-comments-toggle .sf-icon-sm,
.sf-feedback-actions-toggle .sf-icon-feedback,
.sf-feedback-actions-toggle .sf-icon,
.sf-feedback-actions-toggle .sf-icon-sm,
.sf-feedback-actions-dropdown .sf-icon-feedback,
.sf-feedback-actions-dropdown .sf-icon,
.sf-feedback-actions-dropdown .sf-icon-sm {
    filter: brightness(0) invert(1);
    opacity: 0.9;
}

@media (max-width: 768px) {
    .sf-feedback-comments-toolbar {
        display: grid;
        grid-template-columns: auto auto;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .sf-feedback-comments-toggle,
    .sf-feedback-actions-toggle {
        width: 42px;
        height: 42px;
        min-width: 42px;
        min-height: 42px;
        padding: 0;
        gap: 0;
    }

    .sf-feedback-comments-toggle .sf-feedback-action-label,
    .sf-feedback-comments-toggle .sf-toggle-icon,
    .sf-feedback-actions-toggle .sf-feedback-action-label,
    .sf-feedback-actions-toggle .sf-icon-sm {
        display: none !important;
    }

    .sf-feedback-comments-toggle .sf-icon-feedback,
    .sf-feedback-actions-toggle .sf-icon-feedback {
        width: 18px;
        height: 18px;
    }

    .sf-feedback-comments-number {
        position: absolute;
        transform: translate(13px, -13px);
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        font-size: 0.68rem;
    }

    .sf-feedback-actions-dropdown {
        right: 0;
        bottom: calc(100% + 8px);
        width: min(220px, 72vw);
    }
}
	
	.sf-feedback-filter-form {
    display: grid !important;
    grid-template-columns: minmax(260px, 1.4fr) minmax(170px, 0.8fr) minmax(190px, 0.9fr) minmax(190px, 0.9fr) auto;
    align-items: end !important;
    gap: 14px !important;
}

.sf-feedback-page .sf-filter-input {
    width: 100%;
    min-height: 42px;
    border: 1px solid rgba(148, 163, 184, 0.32);
    border-radius: 12px;
    background-color: rgba(15, 23, 42, 0.72);
    color: rgba(255, 255, 255, 0.92);
    padding: 0 14px;
    font-size: 0.92rem;
    box-shadow: none;
}

.sf-feedback-page .sf-filter-input::placeholder {
    color: rgba(203, 213, 225, 0.46);
}

.sf-feedback-page .sf-filter-input:hover,
.sf-feedback-page .sf-filter-input:focus {
    border-color: rgba(254, 224, 0, 0.42);
    background-color: rgba(15, 23, 42, 0.9);
    outline: none;
}

.sf-filter-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 42px;
}

.sf-feedback-filter-submit {
    min-height: 42px;
    padding: 0 18px;
    border-radius: 999px;
    white-space: nowrap;
}

.sf-feedback-filter-clear {
    display: inline-flex;
    align-items: center;
    min-height: 42px;
    color: rgba(203, 213, 225, 0.72);
    font-size: 0.88rem;
    font-weight: 650;
    text-decoration: none;
    white-space: nowrap;
}

.sf-feedback-filter-clear:hover {
    color: #ffffff;
    text-decoration: underline;
}

@media (max-width: 1180px) {
    .sf-feedback-filter-form {
        grid-template-columns: minmax(260px, 1fr) minmax(170px, 0.7fr) minmax(190px, 0.8fr);
    }

    .sf-filter-actions {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {
    .sf-feedback-filter-form {
        grid-template-columns: 1fr;
        gap: 12px !important;
    }

    .sf-filter-actions {
        display: grid;
        grid-template-columns: 1fr auto;
        width: 100%;
    }

    .sf-feedback-filter-submit {
        width: 100%;
        justify-content: center;
    }
}
</style>