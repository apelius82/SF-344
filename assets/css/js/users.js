console.log("users.js loaded");

(function () {
    var base = typeof SF_BASE_URL !== "undefined"
        ? SF_BASE_URL.replace(/\/+$/, "")
        : "";

    var I18N = (window.SF_I18N && window.SF_I18N.users) ? window.SF_I18N.users : {};
    function tr(key, fallback) {
        return (I18N && I18N[key]) ? I18N[key] : fallback;
    }

    // Hae CSRF-token lomakkeesta tai meta-tagista
    function getCsrfToken() {
        var tokenInput = document.querySelector('input[name="csrf_token"]');
        if (tokenInput && tokenInput.value) {
            return tokenInput.value;
        }
        var metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken) {
            return metaToken.getAttribute('content');
        }
        return '';
    }

    // ===== MODAL UTILITIES =====
    function closeAllModals() {
        var modals = document.querySelectorAll('.sf-modal');
        modals.forEach(function (modal) {
            modal.classList.add('hidden');
        });
    }

    function closeModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
        }
    }

function initSfUserCustomSelects() {
    var selects = document.querySelectorAll('#sfUserRole, #sfUserHomeWorksite, #sfUserLanguage');

    selects.forEach(function (select) {
        if (!select || select.dataset.sfCustomSelectReady === '1') {
            return;
        }

        select.dataset.sfCustomSelectReady = '1';
        select.classList.add('sf-native-select-enhanced');

        var wrapper = document.createElement('div');
        wrapper.className = 'sf-custom-select';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'sf-custom-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        var triggerText = document.createElement('span');
        triggerText.className = 'sf-custom-select-text';

        var triggerIcon = document.createElement('span');
        triggerIcon.className = 'sf-custom-select-icon';
        triggerIcon.textContent = '⌄';

        trigger.appendChild(triggerText);
        trigger.appendChild(triggerIcon);

        var dropdown = document.createElement('div');
        dropdown.className = 'sf-custom-select-dropdown hidden';
        dropdown.setAttribute('role', 'listbox');

        Array.prototype.forEach.call(select.options, function (option) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'sf-custom-select-option';
            item.dataset.value = option.value;
            item.textContent = option.textContent.trim();
            item.setAttribute('role', 'option');

            if (option.disabled) {
                item.disabled = true;
                item.classList.add('is-disabled');
            }

            item.addEventListener('click', function () {
                if (item.disabled) {
                    return;
                }

                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                syncSfCustomSelect(select);
                closeSfCustomSelect(wrapper);
            });

            dropdown.appendChild(item);
        });

        wrapper.appendChild(trigger);
        select.parentNode.insertBefore(wrapper, select.nextSibling);
        document.body.appendChild(dropdown);

        trigger.addEventListener('click', function () {
            var isOpen = wrapper.classList.contains('is-open');

            document.querySelectorAll('.sf-custom-select.is-open').forEach(function (openSelect) {
                closeSfCustomSelect(openSelect);
            });

            if (!isOpen) {
                wrapper.classList.add('is-open');
                dropdown.classList.remove('hidden');
                trigger.setAttribute('aria-expanded', 'true');
                positionSfCustomSelectDropdown(wrapper);
            }
        });

        select.addEventListener('change', function () {
            syncSfCustomSelect(select);
        });

        select._sfCustomSelect = {
            wrapper: wrapper,
            trigger: trigger,
            triggerText: triggerText,
            dropdown: dropdown
        };

        wrapper._sfNativeSelect = select;

        syncSfCustomSelect(select);
    });
}

	function positionSfCustomSelectDropdown(wrapper) {
    if (!wrapper || !wrapper._sfNativeSelect || !wrapper._sfNativeSelect._sfCustomSelect) {
        return;
    }

    var data = wrapper._sfNativeSelect._sfCustomSelect;
    var trigger = data.trigger;
    var dropdown = data.dropdown;

    if (!trigger || !dropdown) {
        return;
    }

    var rect = trigger.getBoundingClientRect();
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    var gap = 8;
    var wantedHeight = Math.min(260, dropdown.scrollHeight || 260);
    var spaceBelow = viewportHeight - rect.bottom - gap;
    var spaceAbove = rect.top - gap;
    var openUp = spaceBelow < 180 && spaceAbove > spaceBelow;

    dropdown.style.left = rect.left + 'px';
    dropdown.style.width = rect.width + 'px';
    dropdown.style.maxHeight = Math.max(160, Math.min(wantedHeight, openUp ? spaceAbove : spaceBelow)) + 'px';

    if (openUp) {
        dropdown.style.top = 'auto';
        dropdown.style.bottom = (viewportHeight - rect.top + gap) + 'px';
    } else {
        dropdown.style.bottom = 'auto';
        dropdown.style.top = (rect.bottom + gap) + 'px';
    }
}

    function syncSfCustomSelect(select) {
        if (!select || !select._sfCustomSelect) {
            return;
        }

        var selectedOption = select.options[select.selectedIndex];
        var selectedText = selectedOption ? selectedOption.textContent.trim() : '';

        select._sfCustomSelect.triggerText.textContent = selectedText;

        select._sfCustomSelect.wrapper.querySelectorAll('.sf-custom-select-option').forEach(function (item) {
            var isSelected = item.dataset.value === select.value;
            item.classList.toggle('is-selected', isSelected);
            item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }

    function syncSfCustomSelects(scope) {
        var root = scope || document;
        root.querySelectorAll('#sfUserRole, #sfUserHomeWorksite, #sfUserLanguage').forEach(function (select) {
            syncSfCustomSelect(select);
        });
    }

function closeSfCustomSelect(wrapper) {
    if (!wrapper) {
        return;
    }

    var select = wrapper._sfNativeSelect;
    var dropdown = select && select._sfCustomSelect ? select._sfCustomSelect.dropdown : null;
    var trigger = select && select._sfCustomSelect ? select._sfCustomSelect.trigger : null;

    wrapper.classList.remove('is-open');

    if (dropdown) {
        dropdown.classList.add('hidden');
        dropdown.style.left = '';
        dropdown.style.top = '';
        dropdown.style.bottom = '';
        dropdown.style.width = '';
        dropdown.style.maxHeight = '';
    }

    if (trigger) {
        trigger.setAttribute('aria-expanded', 'false');
    }
}

document.addEventListener('click', function (e) {
    if (
        !e.target.closest('.sf-custom-select') &&
        !e.target.closest('.sf-custom-select-dropdown')
    ) {
        document.querySelectorAll('.sf-custom-select.is-open').forEach(function (wrapper) {
            closeSfCustomSelect(wrapper);
        });
    }
});

window.addEventListener('resize', function () {
    document.querySelectorAll('.sf-custom-select.is-open').forEach(function (wrapper) {
        positionSfCustomSelectDropdown(wrapper);
    });
}, { passive: true });

document.addEventListener('scroll', function () {
    document.querySelectorAll('.sf-custom-select.is-open').forEach(function (wrapper) {
        positionSfCustomSelectDropdown(wrapper);
    });
}, true);

    // ===== ESC KEY HANDLER =====
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });

    // ===== OVERLAY CLICK HANDLER =====
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('sf-modal')) {
            closeAllModals();
        }
    });

    // ===== BUTTON LOADING STATE =====
    function setButtonLoading(button, isLoading) {
        if (!button) return;
        button.disabled = isLoading;
        if (isLoading) {
            button.classList.add('sf-btn-loading');
        } else {
            button.classList.remove('sf-btn-loading');
        }
    }

    // ===== SECURE PASSWORD MODAL =====
    var passwordCountdownInterval = null;

    function showPasswordModal(password) {
        var modal = document.getElementById('sfPasswordModal');
        var display = document.getElementById('sfPasswordDisplay');
        var countdown = document.getElementById('sfPasswordCountdown');
        var toggleBtn = document.getElementById('sfTogglePassword');
        var copyBtn = document.getElementById('sfCopyPassword');

        if (!modal || !display || !countdown) return;

        // Set password
        display.value = password;
        display.type = 'password';

        // Reset toggle button
        var showText = toggleBtn.querySelector('.sf-toggle-show');
        var hideText = toggleBtn.querySelector('.sf-toggle-hide');
        if (showText) showText.classList.remove('hidden');
        if (hideText) hideText.classList.add('hidden');

        // Show modal
        modal.classList.remove('hidden');

        // Start countdown
        var secondsLeft = 60;
        countdown.textContent = secondsLeft;

        if (passwordCountdownInterval) {
            clearInterval(passwordCountdownInterval);
        }

        passwordCountdownInterval = setInterval(function () {
            secondsLeft--;
            countdown.textContent = secondsLeft;

            if (secondsLeft <= 0) {
                clearInterval(passwordCountdownInterval);
                modal.classList.add('hidden');
            }
        }, 1000);

        // Toggle password visibility
        toggleBtn.onclick = function () {
            var showText = toggleBtn.querySelector('.sf-toggle-show');
            var hideText = toggleBtn.querySelector('.sf-toggle-hide');

            if (display.type === 'password') {
                display.type = 'text';
                if (showText) showText.classList.add('hidden');
                if (hideText) hideText.classList.remove('hidden');
            } else {
                display.type = 'password';
                if (showText) showText.classList.remove('hidden');
                if (hideText) hideText.classList.add('hidden');
            }
        };

        // Copy to clipboard
        copyBtn.onclick = function () {
            // Use modern Clipboard API instead of deprecated execCommand
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(display.value)
                    .then(function () {
                        var originalText = copyBtn.textContent;
                        copyBtn.textContent = tr('password_copied', 'Kopioitu!');

                        setTimeout(function () {
                            copyBtn.textContent = originalText;
                        }, 2000);
                    })
                    .catch(function (err) {
                        console.error('Failed to copy:', err);
                        // Fallback to execCommand for older browsers
                        display.select();
                        try {
                            document.execCommand('copy');
                            var originalText = copyBtn.textContent;
                            copyBtn.textContent = tr('password_copied', 'Kopioitu!');
                            setTimeout(function () {
                                copyBtn.textContent = originalText;
                            }, 2000);
                        } catch (e) {
                            alert('Failed to copy password');
                        }
                    });
            } else {
                // Fallback for older browsers
                display.select();
                try {
                    document.execCommand('copy');
                    var originalText = copyBtn.textContent;
                    copyBtn.textContent = tr('password_copied', 'Kopioitu!');
                    setTimeout(function () {
                        copyBtn.textContent = originalText;
                    }, 2000);
                } catch (e) {
                    alert('Failed to copy password');
                }
            }
        };
    }

    // ===== FORM VALIDATION =====
    var validators = {
        email: {
            pattern: /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/,
            message: function () {
                return tr('validation_email_invalid', 'Anna kelvollinen sähköpostiosoite');
            }
        },
        first_name: {
            minLength: 2,
            message: function () {
                return tr('validation_min_length', 'Vähintään {n} merkkiä').replace('{n}', '2');
            }
        },
        last_name: {
            minLength: 2,
            message: function () {
                return tr('validation_min_length', 'Vähintään {n} merkkiä').replace('{n}', '2');
            }
        }
    };

    function validateField(input) {
        if (!input) return true;

        var name = input.name;
        var value = input.value.trim();
        var validator = validators[name];

        if (!validator) return true;

        // Clear previous error
        clearFieldError(input);

        // Check required
        if (input.required && !value) {
            showFieldError(input, tr('validation_required', 'Pakollinen kenttä'));
            return false;
        }

        // Check email pattern
        if (validator.pattern && value && !validator.pattern.test(value)) {
            showFieldError(input, validator.message());
            return false;
        }

        // Check min length
        if (validator.minLength && value && value.length < validator.minLength) {
            showFieldError(input, validator.message());
            return false;
        }

        // Mark as valid
        input.classList.add('sf-input-valid');
        return true;
    }

    function showFieldError(input, message) {
        input.classList.add('sf-input-error');
        input.classList.remove('sf-input-valid');

        // Add error message
        var errorMsg = input.parentNode.querySelector('.sf-field-error-message');
        if (!errorMsg) {
            errorMsg = document.createElement('span');
            errorMsg.className = 'sf-field-error-message';
            input.parentNode.appendChild(errorMsg);
        }
        errorMsg.textContent = message;
    }

    function clearFieldError(input) {
        input.classList.remove('sf-input-error');
        input.classList.remove('sf-input-valid');

        var errorMsg = input.parentNode.querySelector('.sf-field-error-message');
        if (errorMsg) {
            errorMsg.remove();
        }
    }

    // Attach validation listeners to form inputs
    function attachValidationListeners() {
        var userForm = document.getElementById('sfUserForm');
        if (!userForm) return;

        ['sfUserFirst', 'sfUserLast', 'sfUserEmail'].forEach(function (id) {
            var input = document.getElementById(id);
            if (!input) return;

            input.addEventListener('blur', function () {
                validateField(this);
            });

            input.addEventListener('input', function () {
                clearFieldError(this);
            });
        });
    }

    // Initialize validation listeners
    setTimeout(attachValidationListeners, 500);

    document.addEventListener("click", function (e) {
        var settingsPage = document.querySelector(".sf-settings-page");
        if (!settingsPage) return;

        if (e.target.closest("#sfUserAddBtn")) {
            var userModal = document.getElementById("sfUserModal");
            var userForm = document.getElementById("sfUserForm");
            var userTitle = document.getElementById("sfUserModalTitle");
            var inputId = document.getElementById("sfUserId");
            var inputFirst = document.getElementById("sfUserFirst");
            var inputLast = document.getElementById("sfUserLast");
            var inputEmail = document.getElementById("sfUserEmail");
            var inputPass = document.getElementById("sfUserPassword");
            var selectHomeWs = document.getElementById("sfUserHomeWorksite");
            var passwordField = document.getElementById("sfPasswordField");
            var autoPasswordInfo = document.getElementById("sfAutoPasswordInfo");
			var emailNotificationField = document.getElementById("sfEmailNotificationField");
			var emailNotificationSection = document.getElementById("sfEmailNotificationSection");
			var notificationNewUserInfo = document.getElementById("sfNotificationNewUserInfo");
            var additionalRolesSection = document.getElementById("sfAdditionalRolesSection");

            if (userModal && userForm) {
                userTitle.textContent = tr("addUser", "Lisää käyttäjä");
                userForm.reset();
                inputId.value = "";
                if (selectHomeWs) selectHomeWs.value = "";

                // Clear any previous validation errors
                if (inputFirst) clearFieldError(inputFirst);
                if (inputLast) clearFieldError(inputLast);
                if (inputEmail) clearFieldError(inputEmail);

                // Hide password field and show auto-password info for new users
                if (passwordField) passwordField.style.display = "none";
                if (autoPasswordInfo) autoPasswordInfo.style.display = "block";
                if (inputPass) inputPass.required = false;

                // Hide editable notification settings for new users and show explanatory info
                if (emailNotificationField) emailNotificationField.style.display = "none";
				if (emailNotificationSection) emailNotificationSection.style.display = "none";
				if (notificationNewUserInfo) notificationNewUserInfo.style.display = "block";

                // Hide additional roles section for new users
                if (additionalRolesSection) additionalRolesSection.style.display = "none";

                initSfUserCustomSelects();
                syncSfCustomSelects(userModal);

                userModal.classList.remove("hidden");
            }
            return;
        }

        var editBtn = e.target.closest(".sf-edit-user");
        if (editBtn) {
            var userModal = document.getElementById("sfUserModal");
            var userTitle = document.getElementById("sfUserModalTitle");
            var inputId = document.getElementById("sfUserId");
            var inputFirst = document.getElementById("sfUserFirst");
            var inputLast = document.getElementById("sfUserLast");
            var inputEmail = document.getElementById("sfUserEmail");
            var selectRole = document.getElementById("sfUserRole");
            var selectHomeWs = document.getElementById("sfUserHomeWorksite");
            var inputPass = document.getElementById("sfUserPassword");
            var passwordField = document.getElementById("sfPasswordField");
            var autoPasswordInfo = document.getElementById("sfAutoPasswordInfo");
            var emailNotificationField = document.getElementById("sfEmailNotificationField");
            var emailNotificationSection = document.getElementById("sfEmailNotificationSection");
            var notificationNewUserInfo = document.getElementById("sfNotificationNewUserInfo");
            var emailNotificationInput = document.getElementById("sfUserEmailNotifications");
            var additionalRolesSection = document.getElementById("sfAdditionalRolesSection");

            if (userModal) {
                userTitle.textContent = tr("editUser", "Muokkaa käyttäjää");
                inputId.value = editBtn.dataset.id || "";
                inputFirst.value = editBtn.dataset.first || "";
                inputLast.value = editBtn.dataset.last || "";
                inputEmail.value = editBtn.dataset.email || "";
                selectRole.value = editBtn.dataset.role || "";

                if (selectHomeWs) {
                    var homeWs = editBtn.dataset.homeWorksite || "";
                    selectHomeWs.value = homeWs === "0" ? "" : homeWs;
                }

                inputPass.value = "";
                inputPass.required = false;

                // Show password field and hide auto-password info for editing
                if (passwordField) passwordField.style.display = "block";
                if (autoPasswordInfo) autoPasswordInfo.style.display = "none";

                // Show editable notification settings for existing users
                if (notificationNewUserInfo) {
                    notificationNewUserInfo.style.display = "none";
                }

                if (emailNotificationSection) {
                    emailNotificationSection.style.display = "block";
                }

                if (emailNotificationField) {
                    emailNotificationField.style.display = "block";
                }

                // Default all notification toggles to checked first
                var notifCheckboxes = document.querySelectorAll('[data-notif-category]');
                notifCheckboxes.forEach(function (cb) { cb.checked = true; });

                // Load preferences for this user from API
                var editUserId = parseInt(editBtn.dataset.id || "0", 10);
                if (editUserId > 0) {
                    fetch(base + '/app/api/get_user_notification_preferences.php?user_id=' + editUserId, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.ok && data.preferences) {
                                Object.keys(data.preferences).forEach(function (category) {
                                    var cb = document.querySelector('[data-notif-category="' + category + '"]');
                                    if (cb) { cb.checked = data.preferences[category] === true; }
                                });
                            }
                        })
                        .catch(function (err) { console.error('Error loading notification preferences:', err); });
                }

                // Show and populate additional roles
                if (additionalRolesSection) {
                    additionalRolesSection.style.display = "block";

                    // Parse additional roles from data attribute (comma-separated IDs)
                    var additionalRolesStr = editBtn.dataset.additionalRoles || "";
                    var additionalRoleIds = additionalRolesStr ? additionalRolesStr.split(",").map(function (id) {
                        return parseInt(id.trim(), 10);
                    }) : [];

                    // Uncheck all checkboxes first
                    var checkboxes = document.querySelectorAll(".sf-additional-role-checkbox");
                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = false;
                    });

                    // Check the ones that should be checked
                    additionalRoleIds.forEach(function (roleId) {
                        var checkbox = document.querySelector('.sf-additional-role-checkbox[value="' + roleId + '"]');
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                }

                                initSfUserCustomSelects();
                syncSfCustomSelects(userModal);

                userModal.classList.remove("hidden");
            }
            return;
        }

        var delBtn = e.target.closest(".sf-delete-user");
        if (delBtn) {
            var deleteModal = document.getElementById("sfDeleteModal");
            var deleteName = document.getElementById("sfDeleteUserName");

            var row = delBtn.closest("tr");
            var card = delBtn.closest(".sf-user-card");
            var name = "käyttäjä";

            if (row) {
                var nameCell = row.querySelector("td");
                name = nameCell ? nameCell.textContent.trim() : name;
            } else if (card) {
                var nameEl = card.querySelector(".sf-user-card-name");
                name = nameEl ? nameEl.textContent.trim() : name;
            }

            if (deleteModal) {
                deleteModal.dataset.userId = delBtn.dataset.id || "";
                if (deleteName) deleteName.textContent = name;
                deleteModal.classList.remove("hidden");
            }
            return;
        }

        var resetBtn = e.target.closest(".sf-reset-pass");
        if (resetBtn) {
            var resetModal = document.getElementById("sfResetModal");
            var resetName = document.getElementById("sfResetUserName");

            var row = resetBtn.closest("tr");
            var card = resetBtn.closest(".sf-user-card");
            var email = "";

            if (row) {
                var emailCell = row.querySelector("td:nth-child(2)");
                email = emailCell ? emailCell.textContent.trim() : "";
            } else if (card) {
                var emailEl = card.querySelector(".sf-user-card-email");
                email = emailEl ? emailEl.textContent.trim() : "";
            }

            if (resetModal) {
                resetModal.dataset.userId = resetBtn.dataset.id || "";
                if (resetName) resetName.textContent = email;
                resetModal.classList.remove("hidden");
            }
            return;
        }

        if (e.target.closest("#sfRoleHelpOpen")) {
            var roleHelpModal = document.getElementById("sfRoleHelpModal");

            if (roleHelpModal) {
                if (roleHelpModal.parentElement !== document.body) {
                    document.body.appendChild(roleHelpModal);
                }

                roleHelpModal.classList.remove("hidden");
            }

            return;
        }

        if (
            e.target.closest("#sfRoleHelpClose") ||
            e.target.closest("#sfRoleHelpOk")
        ) {
            var roleHelpModal = document.getElementById("sfRoleHelpModal");
            if (roleHelpModal) roleHelpModal.classList.add("hidden");
            return;
        }

        if (e.target.id === "sfRoleHelpModal") {
            e.target.classList.add("hidden");
            return;
        }

        if (e.target.closest("#sfUserCancel")) {
            var modal = document.getElementById("sfUserModal");
            if (modal) modal.classList.add("hidden");
            return;
        }

        if (e.target.closest("#sfDeleteCancel")) {
            var modal = document.getElementById("sfDeleteModal");
            if (modal) modal.classList.add("hidden");
            return;
        }

        if (e.target.closest("#sfResetCancel")) {
            var modal = document.getElementById("sfResetModal");
            if (modal) modal.classList.add("hidden");
            return;
        }

        if (e.target.closest("#sfDeleteConfirm")) {
            var modal = document.getElementById("sfDeleteModal");
            var userId = modal ? modal.dataset.userId : null;
            var confirmBtn = document.getElementById("sfDeleteConfirm");

            if (userId) {
                // Set loading state
                setButtonLoading(confirmBtn, true);

                var body = new URLSearchParams();
                body.set("id", userId);

                // Lisää CSRF-token
                var csrfToken = getCsrfToken();
                if (csrfToken) {
                    body.set("csrf_token", csrfToken);
                }

                fetch(base + "/app/api/users_delete.php", {
                    method: "POST",
                    body: body
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        setButtonLoading(confirmBtn, false);

                        if (res.ok) {
                            window.location = base + "/index.php?page=settings&tab=users&notice=user_deleted";
                        } else {
                            alert(res.error || tr("errDelete", "Virhe poistossa"));
                        }
                    })
                    .catch(function () {
                        setButtonLoading(confirmBtn, false);
                        alert(tr("errNetwork", "Verkkovirhe."));
                    });
            }
            return;
        }

        if (e.target.closest("#sfResetConfirm")) {
            var modal = document.getElementById("sfResetModal");
            var userId = modal ? modal.dataset.userId : null;
            var confirmBtn = document.getElementById("sfResetConfirm");

            if (userId) {
                // Set loading state
                setButtonLoading(confirmBtn, true);

                var body = new URLSearchParams();
                body.set("id", userId);

                // Lisää CSRF-token
                var csrfToken = getCsrfToken();
                if (csrfToken) {
                    body.set("csrf_token", csrfToken);
                }

                fetch(base + "/app/api/users_reset_password.php", {
                    method: "POST",
                    body: body
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        setButtonLoading(confirmBtn, false);

                        if (res.ok) {
                            modal.classList.add("hidden");
                            // Use secure password modal instead of alert
                            showPasswordModal(res.password);

                            // Redirect after modal closes or user closes it
                            setTimeout(function () {
                                window.location = base + "/index.php?page=settings&tab=users&notice=user_pass_reset";
                            }, 65000); // 65 seconds to give time for countdown + interaction
                        } else {
                            alert(res.error || tr("errReset", "Virhe salasanan resetoinnissa"));
                        }
                    })
                    .catch(function () {
                        setButtonLoading(confirmBtn, false);
                        alert(tr("errNetwork", "Verkkovirhe."));
                    });
            }
            return;
        }
    });

    document.addEventListener("submit", function (e) {
        var userForm = e.target.closest("#sfUserForm");
        if (!userForm) return;

        e.preventDefault();

        // Validate all fields
        var firstNameInput = document.getElementById("sfUserFirst");
        var lastNameInput = document.getElementById("sfUserLast");
        var emailInput = document.getElementById("sfUserEmail");

        var isValid = true;
        if (!validateField(firstNameInput)) isValid = false;
        if (!validateField(lastNameInput)) isValid = false;
        if (!validateField(emailInput)) isValid = false;

        if (!isValid) {
            return;
        }

        var formData = new FormData(userForm);
        var inputId = document.getElementById("sfUserId");
        var isEdit = inputId && inputId.value !== "";
        var submitBtn = userForm.querySelector('button[type="submit"]');

        // Set loading state
        setButtonLoading(submitBtn, true);

        var csrfInput = userForm.querySelector('input[name="csrf_token"]');
        if (csrfInput && csrfInput.value) {
            formData.set("csrf_token", csrfInput.value);
        }

        if (formData.get("home_worksite_id") === "") {
            formData.set("home_worksite_id", "");
        }

        var url = base + (isEdit ? "/app/api/users_update.php" : "/app/api/users_create.php");

        fetch(url, {
            method: "POST",
            body: formData
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                setButtonLoading(submitBtn, false);

                if (res.ok) {
                    var notice = isEdit ? "user_updated" : "user_created";

                    // Show success message with toast if available
                    if (!isEdit && res.password_sent !== undefined) {
                        var successMsg = res.password_sent
                            ? tr("userCreatedEmailSent", "Käyttäjä luotu! Kirjautumistiedot lähetetty sähköpostiin.")
                            : tr("userCreated", "Käyttäjä luotu!");

                        // Try to show toast notification if showSuccessToast is available
                        if (typeof showSuccessToast === "function") {
                            showSuccessToast(successMsg);
                        }
                    }

                    window.location = base + "/index.php?page=settings&tab=users&notice=" + notice;
                } else {
                    alert(res.error || tr("errSave", "Virhe tallennuksessa"));
                }
            })
            .catch(function () {
                setButtonLoading(submitBtn, false);
                alert(tr("errNetwork", "Verkkovirhe."));
            });
    });

    // ===== USER FILTERING LOGIC (DELEGATED) =====
    (function () {
        var SEARCH_DEBOUNCE_MS = 400;
        var searchTimeout = null;

        // Apply filters by updating URL and reloading
        function applyFilters() {
            console.log('users.js: applyFilters() called');

            var filterRole = document.getElementById("sfFilterRole");
            var filterWorksite = document.getElementById("sfFilterWorksite");
            var filterSearch = document.getElementById("sfFilterSearch");
            var filterLoginStatus = document.getElementById("sfFilterLoginStatus");

            console.log('users.js: Filter elements found:', {
                role: !!filterRole,
                worksite: !!filterWorksite,
                search: !!filterSearch,
                loginStatus: !!filterLoginStatus
            });

            if (!filterRole || !filterWorksite || !filterSearch || !filterLoginStatus) {
                console.warn('users.js: Some filter elements not found, aborting');
                return; // Elements not present
            }

            var url = new URL(window.location.href);

            console.log('users.js: Filter values:', {
                role: filterRole.value,
                worksite: filterWorksite.value,
                search: filterSearch.value,
                loginStatus: filterLoginStatus.value
            });

            // Set or remove filter parameters
            if (filterRole.value) {
                url.searchParams.set('filter_role', filterRole.value);
            } else {
                url.searchParams.delete('filter_role');
            }

            if (filterWorksite.value) {
                url.searchParams.set('filter_worksite', filterWorksite.value);
            } else {
                url.searchParams.delete('filter_worksite');
            }

            if (filterSearch.value.trim()) {
                url.searchParams.set('filter_search', filterSearch.value.trim());
            } else {
                url.searchParams.delete('filter_search');
            }

            if (filterLoginStatus.value) {
                url.searchParams.set('filter_login', filterLoginStatus.value);
            } else {
                url.searchParams.delete('filter_login');
            }

            // Reset to first page when filtering
            url.searchParams.delete('p');

            console.log('users.js: Redirecting to:', url.toString());

            // Show loading state
            var loadingEl = document.getElementById('sfUsersLoading');
            if (loadingEl) {
                loadingEl.classList.remove('hidden');
            }

            // Reload with new filters
            window.location.href = url.toString();
        }

        // Clear all filters
        function clearFilters() {
            var url = new URL(window.location.href);
            url.searchParams.delete('filter_role');
            url.searchParams.delete('filter_worksite');
            url.searchParams.delete('filter_search');
            url.searchParams.delete('filter_login');
            url.searchParams.delete('p');

            // Show loading state
            var loadingEl = document.getElementById('sfUsersLoading');
            if (loadingEl) {
                loadingEl.classList.remove('hidden');
            }

            window.location.href = url.toString();
        }

        // DELEGATED: Filter toggle button
        document.addEventListener('click', function (e) {
            if (e.target.closest('#sfUsersFiltersToggle')) {
                var filterContent = document.getElementById('sfUsersFiltersContent');
                if (filterContent) {
                    filterContent.classList.toggle('active');
                }
            }

            // Clear filters button
            if (e.target.closest('#sfFilterClear')) {
                clearFilters();
            }
        });

        // DELEGATED: Dropdown changes
        document.addEventListener('change', function (e) {
            console.log('users.js: change event on', e.target.id);

            if (e.target.id === 'sfFilterRole' ||
                e.target.id === 'sfFilterWorksite' ||
                e.target.id === 'sfFilterLoginStatus') {
                console.log('users.js: Calling applyFilters()');
                applyFilters();
            }

            // Show deleted checkbox
            if (e.target.id === 'sfShowDeleted') {
                console.log('users.js: Show deleted checkbox changed');
                var url = new URL(window.location.href);
                if (e.target.checked) {
                    url.searchParams.set('show_deleted', '1');
                } else {
                    url.searchParams.delete('show_deleted');
                }
                url.searchParams.delete('p');
                window.location.href = url.toString();
            }
        });

        // DELEGATED: Search input with debounce
        document.addEventListener('input', function (e) {
            if (e.target.id === 'sfFilterSearch') {
                console.log('users.js: Search input changed');
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function () {
                    console.log('users.js: Search debounce complete, calling applyFilters()');
                    applyFilters();
                }, SEARCH_DEBOUNCE_MS);
            }
        });

        // DELEGATED: Pagination buttons
        document.addEventListener('click', function (e) {
            var pageBtn = e.target.closest('.sf-page-btn');
            if (pageBtn && pageBtn.dataset.page) {
                var url = new URL(window.location.href);
                url.searchParams.set('p', pageBtn.dataset.page);
                window.location.href = url.toString();
            }
        });
    })();

    // ===== REACTIVATE USER =====
    document.addEventListener("click", function (e) {
        var reactivateBtn = e.target.closest(".sf-reactivate-user");
        if (!reactivateBtn) return;

        var userId = reactivateBtn.dataset.id;
        if (!userId) return;

        if (!confirm(tr("users_reactivate_confirm", "Haluatko varmasti aktivoida käyttäjän uudelleen?"))) {
            return;
        }

        // Set loading state
        setButtonLoading(reactivateBtn, true);

        var body = new URLSearchParams();
        body.set("id", userId);

        // Lisää CSRF-token
        var csrfToken = getCsrfToken();
        if (csrfToken) {
            body.set("csrf_token", csrfToken);
        }

        fetch(base + "/app/api/users_reactivate.php", {
            method: "POST",
            body: body
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                setButtonLoading(reactivateBtn, false);

                if (res.ok) {
                    window.location = base + "/index.php?page=settings&tab=users&notice=user_reactivated";
                } else {
                    alert(res.error || tr("errReactivate", "Virhe aktivoinnissa"));
                }
            })
            .catch(function () {
                setButtonLoading(reactivateBtn, false);
                alert(tr("errNetwork", "Verkkovirhe."));
            });
    });

    // ===== BULK ACTIONS FOR EMAIL NOTIFICATIONS =====

    // Update selected count
    function updateSelectedCount() {
        var checkboxes = document.querySelectorAll('.sf-user-select:checked');
        var countEl = document.getElementById('sfBulkSelectedCount');
        if (countEl) {
            var count = checkboxes.length;
            if (count > 0) {
                // Use simple count display without translation
                countEl.textContent = count + ' ' + tr('selected', 'valittu');
            } else {
                countEl.textContent = '';
            }
        }
    }

    // Select all checkbox
    document.addEventListener('change', function (e) {
        if (e.target.id === 'sfSelectAllUsers') {
            var checkboxes = document.querySelectorAll('.sf-user-select');
            checkboxes.forEach(function (cb) {
                cb.checked = e.target.checked;
            });
            updateSelectedCount();
        }

        if (e.target.classList.contains('sf-user-select')) {
            updateSelectedCount();
        }
    });

    // Bulk apply button
    document.addEventListener('click', function (e) {
        if (e.target.id === 'sfBulkApply' || e.target.closest('#sfBulkApply')) {
            e.preventDefault();

            var action = document.getElementById('sfBulkAction').value;
            var checkboxes = document.querySelectorAll('.sf-user-select:checked');

            if (!action) {
                alert(tr('selectAction', 'Valitse toiminto'));
                return;
            }

            if (checkboxes.length === 0) {
                alert(tr('selectUsers', 'Valitse käyttäjiä ensin'));
                return;
            }

            var userIds = Array.from(checkboxes).map(function (cb) {
                return cb.dataset.userId;
            });

            var enabled = action === 'enable_emails' ? 1 : 0;
            var confirmMsg = action === 'enable_emails'
                ? tr('confirmEnableEmails', 'Otetaanko sähköpostit käyttöön valituille käyttäjille?')
                : tr('confirmDisableEmails', 'Poistetaanko sähköpostit käytöstä valituilla käyttäjillä?');

            // Show modal instead of browser confirm()
            var modal = document.getElementById('sfBulkConfirmModal');
            if (modal) {
                var confirmText = document.getElementById('sfBulkConfirmText');
                if (confirmText) {
                    confirmText.textContent = confirmMsg;
                }
                // Store action data on modal for later use
                modal.dataset.action = action;
                modal.dataset.enabled = enabled;
                modal.dataset.userIds = JSON.stringify(userIds);
                modal.classList.remove('hidden');
            }
        }

        // Bulk confirm modal - Cancel button
        if (e.target.id === 'sfBulkConfirmCancel' || e.target.closest('#sfBulkConfirmCancel')) {
            closeModal('sfBulkConfirmModal');
        }

        // Bulk confirm modal - OK button
        if (e.target.id === 'sfBulkConfirmOk' || e.target.closest('#sfBulkConfirmOk')) {
            var modal = document.getElementById('sfBulkConfirmModal');
            if (!modal) return;

            // Get stored data from modal
            var action = modal.dataset.action;
            var enabled = modal.dataset.enabled;
            var userIds = JSON.parse(modal.dataset.userIds || '[]');

            // Close modal
            closeModal('sfBulkConfirmModal');

            // Prepare form data
            var formData = new FormData();
            userIds.forEach(function (id) {
                formData.append('user_ids[]', id);
            });
            formData.append('email_notifications_enabled', enabled);

            // Add CSRF token
            var csrfToken = getCsrfToken();
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            fetch(base + '/app/api/bulk_update_notifications.php', {
                method: 'POST',
                body: formData
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.ok) {
                        if (typeof window.sfToast === 'function') {
                            window.sfToast('success', tr('bulkSuccess', 'Toiminto suoritettu onnistuneesti'));
                            // Wait 2 seconds for user to see the toast notification
                            setTimeout(function () {
                                window.location.reload();
                            }, 2000);
                        } else {
                            // If toast is not available, redirect with notice parameter
                            window.location = base + '/index.php?page=settings&tab=users&notice=bulk_success';
                        }
                    } else {
                        alert(res.error || tr('errBulk', 'Virhe massakomennon suorittamisessa'));
                    }
                })
                .catch(function (err) {
                    console.error('Bulk action error:', err);
                    alert(tr('errNetwork', 'Verkkovirhe.'));
                });
        }
    });

    // ===== HELPER TO HIDE LOADING STATES =====
    function hideLoadingStates() {
        var skeleton = document.getElementById('skeletonTable');
        var actualContent = document.querySelector('.actual-content');
        var loadingEl = document.getElementById('sfUsersLoading');

        if (skeleton) skeleton.style.display = 'none';
        if (actualContent) actualContent.style.opacity = '1';
        if (loadingEl) loadingEl.classList.add('hidden');
    }

    // ===== RE-INITIALIZE ON AJAX TAB LOAD =====
    // settings.js dispatches sf:content:updated when tab content is loaded via AJAX
    window.addEventListener('sf:content:updated', function (e) {
        if (e.detail && e.detail.tab === 'users') {
            console.log('users.js: sf:content:updated event received for users tab');

            // Re-attach validation listeners for the new DOM content
            setTimeout(function () {
                attachValidationListeners();
                initSfUserCustomSelects();
                syncSfCustomSelects(document);
            }, 100);

            // Hide skeleton/loading if still visible
            hideLoadingStates();
        }
    });

    // ===== LISTEN FOR USERS LOADED EVENT =====
    // tab_users.php dispatches sf:users:loaded when content is ready
    window.addEventListener('sf:users:loaded', function () {
        hideLoadingStates();
    });
})();