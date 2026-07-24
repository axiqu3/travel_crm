(() => {
    const page = document.querySelector('[data-bulk-list]');
    if (!page) return;

    const singular = page.dataset.bulkSingular || 'record';
    const plural = page.dataset.bulkPlural || singular + 's';
    const endpoint = page.dataset.bulkEndpoint || 'bulk_delete.php';
    const token = page.dataset.bulkToken || '';
    const toggle = page.querySelector('[data-bulk-toggle]');
    const toolbar = page.querySelector('[data-bulk-toolbar]');
    const cancel = page.querySelector('[data-bulk-cancel]');
    const deleteSelected = page.querySelector('[data-bulk-delete-selected]');
    const selectAll = page.querySelector('[data-bulk-select-all]');
    const count = page.querySelector('[data-bulk-count]');
    const checkboxes = Array.from(page.querySelectorAll('[data-bulk-row]'));
    const confirmBackdrop = page.querySelector('[data-bulk-confirm]');
    const confirmMessage = page.querySelector('[data-bulk-confirm-message]');
    const confirmCancel = page.querySelector('[data-bulk-confirm-cancel]');
    const confirmDelete = page.querySelector('[data-bulk-confirm-delete]');
    const error = page.querySelector('[data-bulk-error]');
    let pendingIds = [];

    const visibleCheckboxes = () => checkboxes.filter(checkbox => {
        const row = checkbox.closest('tr');
        return row && window.getComputedStyle(row).display !== 'none';
    });

    const selectedCheckboxes = () => checkboxes.filter(checkbox => checkbox.checked);

    const refreshSelection = () => {
        const visible = visibleCheckboxes();
        const selected = selectedCheckboxes();

        checkboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            if (row) row.classList.toggle('bulk-selected', checkbox.checked);
        });

        if (count) {
            count.textContent = selected.length === 1
                ? `1 ${singular} selected`
                : `${selected.length} ${plural} selected`;
        }
        if (deleteSelected) deleteSelected.disabled = selected.length === 0;
        if (selectAll) {
            const visibleSelected = visible.filter(checkbox => checkbox.checked).length;
            selectAll.checked = visible.length > 0 && visibleSelected === visible.length;
            selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;
        }
    };

    const closeConfirm = () => {
        if (!confirmBackdrop) return;
        confirmBackdrop.classList.remove('open');
        document.body.style.overflow = '';
        pendingIds = [];
        if (error) {
            error.textContent = '';
            error.style.display = 'none';
        }
    };

    const closeDeleteMode = () => {
        page.classList.remove('bulk-delete-mode');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        if (toggle) toggle.textContent = 'Delete';
        refreshSelection();
    };

    if (toggle) {
        toggle.addEventListener('click', () => {
            if (page.classList.contains('bulk-delete-mode')) {
                closeDeleteMode();
                return;
            }
            page.classList.add('bulk-delete-mode');
            toggle.textContent = 'Cancel Delete';
            refreshSelection();
        });
    }

    if (cancel) cancel.addEventListener('click', closeDeleteMode);

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            visibleCheckboxes().forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            refreshSelection();
        });
    }

    checkboxes.forEach(checkbox => checkbox.addEventListener('change', refreshSelection));

    if (deleteSelected) {
        deleteSelected.addEventListener('click', () => {
            pendingIds = selectedCheckboxes().map(checkbox => checkbox.value);
            if (!pendingIds.length || !confirmBackdrop) return;

            if (confirmMessage) {
                confirmMessage.textContent = pendingIds.length === 1
                    ? `This ${singular} will be permanently deleted. This action cannot be undone.`
                    : `${pendingIds.length} ${plural} will be permanently deleted. This action cannot be undone.`;
            }
            confirmBackdrop.classList.add('open');
            document.body.style.overflow = 'hidden';
            if (confirmDelete) confirmDelete.focus();
        });
    }

    if (confirmCancel) confirmCancel.addEventListener('click', closeConfirm);
    if (confirmBackdrop) {
        confirmBackdrop.addEventListener('click', event => {
            if (event.target === confirmBackdrop) closeConfirm();
        });
    }

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && confirmBackdrop && confirmBackdrop.classList.contains('open')) {
            closeConfirm();
        }
    });

    if (confirmDelete) {
        confirmDelete.addEventListener('click', async () => {
            const ids = pendingIds.slice();
            if (!ids.length) return;

            const originalText = confirmDelete.textContent;
            confirmDelete.disabled = true;
            confirmDelete.textContent = 'Deleting...';
            if (confirmCancel) confirmCancel.disabled = true;

            const payload = new FormData();
            payload.append('csrf_token', token);
            ids.forEach(id => payload.append('ids[]', id));

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    body: payload,
                    headers: {'Accept': 'application/json'}
                });
                const result = await response.json().catch(() => null);
                if (!response.ok || !result || !result.success) {
                    throw new Error((result && result.message) || `The selected ${plural} could not be deleted.`);
                }

                const nextUrl = new URL(window.location.href);
                nextUrl.searchParams.set('deleted', String(result.deleted_count || ids.length));
                window.location.href = nextUrl.toString();
            } catch (deleteError) {
                if (error) {
                    error.textContent = deleteError.message;
                    error.style.display = 'block';
                }
                confirmDelete.disabled = false;
                confirmDelete.textContent = originalText;
                if (confirmCancel) confirmCancel.disabled = false;
            }
        });
    }

    // Search filters can change which rows are visible after delete mode opens.
    const search = page.querySelector('.search');
    if (search) {
        search.addEventListener('input', () => window.setTimeout(refreshSelection, 0));
    }

    refreshSelection();
})();
