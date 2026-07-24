<div id="exportBookingsModal" class="booking-export-modal" role="dialog" aria-modal="true" aria-labelledby="exportBookingsTitle" aria-hidden="true">
    <div class="booking-export-dialog">
        <div class="booking-export-header"><h2 id="exportBookingsTitle">Export Bookings</h2></div>
        <form id="exportBookingsForm" class="booking-export-body" action="export_excel.php" method="GET">
            <label for="exportServiceType">Select Service Type</label>
            <select id="exportServiceType" name="export_service" required>
                <option value="all">All Services</option>
                <option value="flight">Flight Ticket</option>
                <option value="hotel">Hotel</option>
                <option value="visa">Visa</option>
                <option value="passport">Passport</option>
                <option value="insurance">Insurance</option>
                <option value="package">Tour Package</option>
                <option value="other">Other Service</option>
            </select>
            <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="customer_type" value="<?php echo htmlspecialchars($customer_type ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <?php if (isset($agent)): ?>
                <input type="hidden" name="agent" value="<?php echo htmlspecialchars($agent, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <div id="exportBookingsMessage" class="booking-export-message" role="alert"></div>
            <div class="booking-export-actions">
                <button type="button" id="cancelExportModal" class="btn btn-secondary">Cancel</button>
                <button type="submit" id="submitBookingExport" class="btn">Export Excel</button>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    const modal = document.getElementById('exportBookingsModal');
    const openButton = document.getElementById('openExportModal');
    const cancelButton = document.getElementById('cancelExportModal');
    const form = document.getElementById('exportBookingsForm');
    const submitButton = document.getElementById('submitBookingExport');
    const select = document.getElementById('exportServiceType');
    const message = document.getElementById('exportBookingsMessage');

    function showModal() {
        message.textContent = '';
        message.classList.remove('is-visible');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        select.focus();
    }

    function hideModal() {
        if (submitButton.disabled) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        openButton.focus();
    }

    openButton.addEventListener('click', showModal);
    cancelButton.addEventListener('click', hideModal);
    modal.addEventListener('click', function(event) {
        if (event.target === modal) hideModal();
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) hideModal();
    });

    form.addEventListener('submit', async function(event) {
        event.preventDefault();
        submitButton.disabled = true;
        submitButton.textContent = 'Generating...';
        message.textContent = '';
        message.classList.remove('is-visible');

        try {
            const url = form.action + '?' + new URLSearchParams(new FormData(form)).toString();
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (response.redirected) {
                window.location.href = response.url;
                return;
            }
            if (!response.ok) {
                let errorText = 'The booking export could not be generated.';
                try {
                    const body = await response.json();
                    errorText = body.message || errorText;
                } catch (ignore) {}
                throw new Error(errorText);
            }

            const blob = await response.blob();
            const disposition = response.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename="?([^";]+)"?/i);
            const filename = match ? match[1] : 'bookings_' + select.value + '.xlsx';
            const downloadUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(downloadUrl);
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        } catch (error) {
            message.textContent = error.message;
            message.classList.add('is-visible');
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = 'Export Excel';
        }
    });
})();
</script>
