// assets/js/role-categories.js
// Role Categories Admin Page JavaScript

(function () {
    'use strict';

    const baseUrl = window.SF_BASE_URL || '';
    const allUsers = window.SF_ALL_USERS || [];
    const terms = window.SF_ROLE_CATEGORY_TERMS || {};

    function getCsrfToken() {
        return document.querySelector('input[name="csrf_token"]')?.value || '';
    }

    function t(key, fallback) {
        return terms[key] || fallback;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    const categoryModal = document.getElementById('sfCategoryModal');
    const categoryModalTitle = document.getElementById('sfCategoryModalTitle');
    const categoryModalClose = document.getElementById('sfCategoryModalClose');
    const categoryForm = document.getElementById('sfCategoryForm');
    const categoryFormCancel = document.getElementById('sfCategoryFormCancel');

    const manageUsersModal = document.getElementById('sfManageUsersModal');
    const manageUsersModalTitle = document.getElementById('sfManageUsersModalTitle');
    const manageUsersModalClose = document.getElementById('sfManageUsersModalClose');
    const manageCategoryId = document.getElementById('manageCategoryId');
    const currentUsersList = document.getElementById('sfCurrentUsersList');
    const addUserSelect = document.getElementById('sfAddUserSelect');
    const addUserBtn = document.getElementById('sfAddUserBtn');

    [categoryModal, manageUsersModal].forEach(function (modal) {
        if (modal && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    });

    const addCategoryBtn = document.getElementById('sfAddCategoryBtn');
    const editCategoryBtns = document.querySelectorAll('.sf-edit-category-btn');
    const deleteCategoryBtns = document.querySelectorAll('.sf-delete-category-btn');
    const manageUsersBtns = document.querySelectorAll('.sf-manage-users-btn');

    if (addCategoryBtn && categoryModal && categoryForm) {
        addCategoryBtn.addEventListener('click', function () {
            categoryModalTitle.textContent = t('modal_add_title', 'Lisää kategoria');
            categoryForm.reset();
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryType').value = 'supervisor';
            document.getElementById('categoryIsActive').checked = true;
            showModal(categoryModal);
        });
    }

    editCategoryBtns.forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const categoryId = this.dataset.id;

            try {
                const response = await fetch(`${baseUrl}/app/api/get_role_category.php?id=${encodeURIComponent(categoryId)}`);
                const data = await response.json();

                if (data.ok && data.category) {
                    const cat = data.category;

                    categoryModalTitle.textContent = t('modal_edit_title', 'Muokkaa kategoriaa');
                    document.getElementById('categoryId').value = cat.id;
                    document.getElementById('categoryName').value = cat.name || '';
                    document.getElementById('categoryType').value = cat.type || 'supervisor';
                    document.getElementById('categoryWorksite').value = cat.worksite || '';
                    document.getElementById('categoryIsActive').checked = Number(cat.is_active) === 1;

                    showModal(categoryModal);
                } else {
                    window.sfToast('error', `${t('load_error', 'Virhe ladattaessa kategoriaa')}: ${data.error || t('unknown_error', 'Tuntematon virhe')}`);
                }
            } catch (error) {
                console.error('Error loading category:', error);
                window.sfToast('error', t('load_error', 'Virhe ladattaessa kategoriaa'));
            }
        });
    });

    deleteCategoryBtns.forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const categoryId = this.dataset.id;
            const categoryName = this.dataset.name || '';

            const deleteConfirmText = t(
                'delete_confirm',
                'Haluatko varmasti poistaa kategorian "{name}"?\n\nTämä poistaa myös kaikki käyttäjien liitokset tähän kategoriaan.'
            ).replace('{name}', categoryName);

            if (!confirm(deleteConfirmText)) {
                return;
            }

            try {
                const response = await fetch(`${baseUrl}/app/api/delete_role_category.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        id: parseInt(categoryId, 10)
                    })
                });

                const data = await response.json();

                if (data.ok) {
                    window.sfToast('success', t('delete_success', 'Kategoria poistettu'));
                    location.reload();
                } else {
                    window.sfToast('error', `${t('delete_error', 'Virhe poistettaessa kategoriaa')}: ${data.error || t('unknown_error', 'Tuntematon virhe')}`);
                }
            } catch (error) {
                console.error('Error deleting category:', error);
                window.sfToast('error', t('delete_error', 'Virhe poistettaessa kategoriaa'));
            }
        });
    });

    manageUsersBtns.forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const categoryId = this.dataset.id;
            const categoryName = this.dataset.name || '';

            manageUsersModalTitle.textContent = `${t('manage_users_title', 'Hallinnoi käyttäjiä')}: ${categoryName}`;
            manageCategoryId.value = categoryId;

            await loadCategoryUsers(categoryId);
            showModal(manageUsersModal);
        });
    });

    if (addUserBtn) {
        addUserBtn.addEventListener('click', async function () {
            const userId = parseInt(addUserSelect.value, 10);
            const categoryId = parseInt(manageCategoryId.value, 10);

            if (!userId) {
                window.sfToast('error', t('select_user', 'Valitse käyttäjä'));
                return;
            }

            try {
                const response = await fetch(`${baseUrl}/app/api/assign_user_to_category.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        category_id: categoryId
                    })
                });

                const data = await response.json();

                if (data.ok) {
                    window.sfToast('success', t('user_added', 'Käyttäjä lisätty'));
                    addUserSelect.value = '';
                    await loadCategoryUsers(categoryId);
                } else {
                    window.sfToast('error', `${t('add_user_error', 'Virhe lisättäessä käyttäjää')}: ${data.error || t('unknown_error', 'Tuntematon virhe')}`);
                }
            } catch (error) {
                console.error('Error adding user:', error);
                window.sfToast('error', t('add_user_error', 'Virhe lisättäessä käyttäjää'));
            }
        });
    }

    if (categoryForm) {
        categoryForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const formData = {
                id: parseInt(document.getElementById('categoryId').value, 10) || 0,
                name: document.getElementById('categoryName').value.trim(),
                type: document.getElementById('categoryType').value,
                worksite: document.getElementById('categoryWorksite').value || null,
                is_active: document.getElementById('categoryIsActive').checked
            };

            try {
                const response = await fetch(`${baseUrl}/app/api/save_role_category.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify(formData)
                });

                const data = await response.json();

                if (data.ok) {
                    window.sfToast('success', t('save_success', 'Kategoria tallennettu'));
                    location.reload();
                } else {
                    window.sfToast('error', `${t('save_error', 'Virhe tallennettaessa kategoriaa')}: ${data.error || t('unknown_error', 'Tuntematon virhe')}`);
                }
            } catch (error) {
                console.error('Error saving category:', error);
                window.sfToast('error', t('save_error', 'Virhe tallennettaessa kategoriaa'));
            }
        });
    }

    if (categoryModalClose) {
        categoryModalClose.addEventListener('click', function () {
            hideModal(categoryModal);
        });
    }

    if (categoryFormCancel) {
        categoryFormCancel.addEventListener('click', function () {
            hideModal(categoryModal);
        });
    }

    if (manageUsersModalClose) {
        manageUsersModalClose.addEventListener('click', function () {
            hideModal(manageUsersModal);
        });
    }

    window.addEventListener('click', function (e) {
        if (e.target === categoryModal) {
            hideModal(categoryModal);
        }

        if (e.target === manageUsersModal) {
            hideModal(manageUsersModal);
        }
    });

    function showModal(modal) {
        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('sf-modal-open');
        document.body.style.overflow = 'hidden';
    }

    function hideModal(modal) {
        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.style.display = 'none';

        const openModals = document.querySelectorAll(
            '#sfCategoryModal:not(.hidden), #sfManageUsersModal:not(.hidden)'
        );

        if (openModals.length === 0) {
            document.body.classList.remove('sf-modal-open');
            document.body.style.overflow = '';
        }
    }

    async function loadCategoryUsers(categoryId) {
        try {
            const response = await fetch(`${baseUrl}/app/api/get_role_category.php?id=${encodeURIComponent(categoryId)}`);
            const data = await response.json();

            if (data.ok && data.category) {
                renderCategoryUsers(data.category.users || [], categoryId);
            } else {
                currentUsersList.innerHTML = `<div class="sf-users-list-empty">${escapeHtml(t('users_load_error', 'Virhe ladattaessa käyttäjiä'))}</div>`;
            }
        } catch (error) {
            console.error('Error loading users:', error);
            currentUsersList.innerHTML = `<div class="sf-users-list-empty">${escapeHtml(t('users_load_error', 'Virhe ladattaessa käyttäjiä'))}</div>`;
        }
    }

    function renderCategoryUsers(users, categoryId) {
        if (!users || users.length === 0) {
            currentUsersList.innerHTML = `<div class="sf-users-list-empty">${escapeHtml(t('no_users', 'Ei käyttäjiä tässä kategoriassa'))}</div>`;
            return;
        }

        currentUsersList.innerHTML = users.map(function (user) {
            return `
                <div class="sf-user-item">
                    <div class="sf-user-info">
                        <div class="sf-user-name">${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)}</div>
                        <div class="sf-user-email">${escapeHtml(user.email)}</div>
                    </div>
                    <button class="sf-user-remove" type="button" data-user-id="${escapeHtml(user.id)}" data-category-id="${escapeHtml(categoryId)}">
                        ${escapeHtml(t('remove_user', 'Poista'))}
                    </button>
                </div>
            `;
        }).join('');

        currentUsersList.querySelectorAll('.sf-user-remove').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const userId = parseInt(this.dataset.userId, 10);
                const catId = parseInt(this.dataset.categoryId, 10);

                if (!confirm(t('remove_user_confirm', 'Haluatko varmasti poistaa käyttäjän tästä kategoriasta?'))) {
                    return;
                }

                try {
                    const response = await fetch(`${baseUrl}/app/api/remove_user_from_category.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': getCsrfToken()
                        },
                        body: JSON.stringify({
                            user_id: userId,
                            category_id: catId
                        })
                    });

                    const data = await response.json();

                    if (data.ok) {
                        window.sfToast('success', t('user_removed', 'Käyttäjä poistettu'));
                        await loadCategoryUsers(catId);
                    } else {
                        window.sfToast('error', `${t('remove_user_error', 'Virhe poistettaessa käyttäjää')}: ${data.error || t('unknown_error', 'Tuntematon virhe')}`);
                    }
                } catch (error) {
                    console.error('Error removing user:', error);
                    window.sfToast('error', t('remove_user_error', 'Virhe poistettaessa käyttäjää'));
                }
            });
        });
    }

    const GLOBAL_WORKSITE_VALUE = '__global__';
    const worksiteFilter = document.getElementById('sfWorksiteFilter');
    const filterCount = document.getElementById('sfFilterCount');

    if (worksiteFilter) {
        worksiteFilter.addEventListener('change', function () {
            const selectedWorksite = this.value;
            const cards = document.querySelectorAll('.sf-category-card');
            let visibleCount = 0;

            cards.forEach(function (card) {
                const cardWorksite = card.dataset.worksite || GLOBAL_WORKSITE_VALUE;

                if (selectedWorksite === '' || cardWorksite === selectedWorksite) {
                    card.classList.remove('sf-filtered-out');
                    visibleCount++;
                } else {
                    card.classList.add('sf-filtered-out');
                }
            });

            updateFilterCount(visibleCount, selectedWorksite === '');
        });

        const totalCards = document.querySelectorAll('.sf-category-card').length;

        if (filterCount && totalCards > 0) {
            filterCount.textContent = `${totalCards} ${t('count_total', 'kategoriaa yhteensä')}`;
        }
    }

    function updateFilterCount(count, isAllSelected) {
        if (!filterCount) {
            return;
        }

        if (isAllSelected) {
            const totalCards = document.querySelectorAll('.sf-category-card').length;
            filterCount.textContent = `${totalCards} ${t('count_total', 'kategoriaa yhteensä')}`;
            return;
        }

        filterCount.textContent = count === 1
            ? `1 ${t('count_one', 'kategoria')}`
            : `${count} ${t('count_many', 'kategoriaa')}`;
    }
})();