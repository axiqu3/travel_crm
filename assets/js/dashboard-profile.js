document.addEventListener('DOMContentLoaded', function () {
    const profileForms = Array.from(document.querySelectorAll('[data-dashboard-profile]'));

    function closeProfile(form) {
        const toggle = form.querySelector('[data-profile-toggle]');
        const panel = form.querySelector('[data-profile-panel]');
        if (!toggle || !panel) return;
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    function closeEditModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        if (!document.querySelector('[data-profile-edit-modal]:not([hidden])')) {
            document.body.classList.remove('crm-profile-modal-open');
        }
    }

    profileForms.forEach(function (form) {
        const toggle = form.querySelector('[data-profile-toggle]');
        const panel = form.querySelector('[data-profile-panel]');
        const input = form.querySelector('[data-profile-image-input]');
        const preview = form.querySelector('[data-profile-preview]');
        const status = form.querySelector('[data-profile-upload-status]');
        const editOpen = form.querySelector('[data-profile-edit-open]');
        const editModal = editOpen
            ? document.getElementById(editOpen.getAttribute('aria-controls'))
            : null;
        if (!toggle || !panel || !input) return;

        toggle.addEventListener('click', function () {
            const willOpen = panel.hidden;
            profileForms.forEach(closeProfile);
            panel.hidden = !willOpen;
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        if (editOpen && editModal) {
            editOpen.addEventListener('click', function () {
                closeProfile(form);
                editModal.hidden = false;
                document.body.classList.add('crm-profile-modal-open');
                const firstField = editModal.querySelector('input:not([type="hidden"]), textarea');
                if (firstField) {
                    window.requestAnimationFrame(function () {
                        firstField.focus();
                    });
                }
            });

            editModal.querySelectorAll('[data-profile-edit-close]').forEach(function (closeButton) {
                closeButton.addEventListener('click', function () {
                    closeEditModal(editModal);
                    toggle.focus();
                });
            });

            if (!editModal.hidden) {
                document.body.classList.add('crm-profile-modal-open');
            }
        }

        input.addEventListener('change', function () {
            const file = input.files && input.files[0];
            if (!file) return;

            const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                if (status) {
                    status.textContent = 'Use a JPG, PNG, or WebP profile image.';
                    status.classList.add('is-error');
                }
                input.value = '';
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                if (status) {
                    status.textContent = 'Profile image must be 5MB or smaller.';
                    status.classList.add('is-error');
                }
                input.value = '';
                return;
            }

            if (status) {
                status.textContent = 'Uploading personal photo...';
                status.classList.remove('is-error');
                status.classList.add('is-loading');
            }

            if (preview) {
                const imageUrl = URL.createObjectURL(file);
                preview.innerHTML = '';
                const image = document.createElement('img');
                image.src = imageUrl;
                image.alt = '';
                image.addEventListener('load', function () {
                    URL.revokeObjectURL(imageUrl);
                }, {once: true});
                preview.appendChild(image);
            }

            window.requestAnimationFrame(function () {
                form.submit();
            });
        });
    });

    document.addEventListener('click', function (event) {
        profileForms.forEach(function (form) {
            if (!form.contains(event.target)) {
                closeProfile(form);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('[data-profile-edit-modal]:not([hidden])').forEach(closeEditModal);
        profileForms.forEach(closeProfile);
    });
});
