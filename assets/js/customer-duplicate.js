(function () {
    'use strict';

    function field(form, names) {
        for (var i = 0; i < names.length; i += 1) {
            var found = form.querySelector('[name="' + names[i] + '"]');
            if (found) return found;
        }
        return null;
    }

    function setBadge(panel, type) {
        panel.classList.remove('is-exact', 'is-possible', 'is-none');
        panel.classList.add('is-' + type);
        var badge = panel.querySelector('[data-duplicate-badge]');
        if (!badge) return;
        badge.textContent = type === 'exact'
            ? 'Exact Duplicate'
            : (type === 'possible' ? 'Possible Duplicate' : 'No Duplicate');
    }

    function appendDetail(list, label, value) {
        if (!value) return;
        var item = document.createElement('span');
        item.className = 'duplicate-customer-detail';
        item.textContent = label + ': ' + value;
        list.appendChild(item);
    }

    function renderMatches(container, matches) {
        container.textContent = '';
        matches.forEach(function (match) {
            var card = document.createElement('div');
            card.className = 'duplicate-customer-match';

            var heading = document.createElement('div');
            heading.className = 'duplicate-customer-match-name';
            heading.textContent = match.name || 'Unnamed customer';
            card.appendChild(heading);

            var type = document.createElement('span');
            type.className = 'duplicate-customer-type';
            type.textContent = match.customer_type || 'Customer';
            card.appendChild(type);

            var details = document.createElement('div');
            details.className = 'duplicate-customer-details';
            appendDetail(details, 'Mobile', match.mobile);
            appendDetail(details, 'Email', match.email);
            card.appendChild(details);
            container.appendChild(card);
        });
    }

    function renderDuplicateReasons(container, matches) {
        container.textContent = '';
        var matchedBy = [];
        matches.forEach(function (match) {
            (match.matched_by || []).forEach(function (identifier) {
                if (matchedBy.indexOf(identifier) === -1) matchedBy.push(identifier);
            });
        });

        var messages = [];
        if (matchedBy.indexOf('mobile') !== -1) {
            messages.push('Mobile number already exists. Enter a different mobile number.');
        }
        if (matchedBy.indexOf('email') !== -1) {
            messages.push('Email address already exists. Enter a different email address.');
        }
        if (messages.length === 0) {
            messages.push('These customer details may already exist. Please change them before saving.');
        }

        messages.forEach(function (message) {
            var line = document.createElement('p');
            line.className = 'duplicate-customer-error-message';
            line.textContent = message;
            container.appendChild(line);
        });
    }

    function initDuplicateForm(form) {
        var nameInput = field(form, ['name', 'customer_name']);
        var mobileInput = field(form, ['mobile', 'customer_mobile', 'customer_phone']);
        var emailInput = field(form, ['email', 'customer_email']);
        var customerTypeInput = field(form, ['customer_type']);
        var existingIdInput = field(form, ['existing_customer_id', 'customer_master_id']);
        var panel = form.querySelector('[data-duplicate-panel]');
        var resultContainer = panel ? panel.querySelector('[data-duplicate-results]') : null;
        var useButton = panel ? panel.querySelector('[data-use-existing]') : null;
        var viewButton = panel ? panel.querySelector('[data-view-existing]') : null;
        var overrideInput = panel ? panel.querySelector('[name="confirm_override"]') : null;
        var overrideContainer = overrideInput ? overrideInput.closest('.duplicate-customer-override') : null;
        var saveButton = form.querySelector('button[type="submit"]');
        var endpoint = form.getAttribute('data-duplicate-endpoint');
        var table = form.getAttribute('data-duplicate-table') || 'customer_master';
        var viewTemplate = form.getAttribute('data-duplicate-view-url') || '';
        var autoUse = form.getAttribute('data-duplicate-auto-use') === '1';
        var useMode = form.getAttribute('data-duplicate-use-mode') || 'select';
        var simpleError = form.getAttribute('data-duplicate-simple-error') === '1';
        var requestController = null;
        var selectedMatch = null;
        var currentType = 'none';
        var lastCheckedSignature = null;
        var checking = false;

        if (!panel || !endpoint || (!mobileInput && !emailInput)) return;

        function setSaveState() {
            if (!saveButton) return;
            if (currentType !== 'exact') {
                saveButton.disabled = false;
                return;
            }
            var selectedExisting = existingIdInput && existingIdInput.value !== '';
            var overrideConfirmed = overrideInput && overrideInput.checked;
            saveButton.disabled = !(selectedExisting || overrideConfirmed);
        }

        function clearSelection() {
            selectedMatch = null;
            if (existingIdInput) existingIdInput.value = '';
            if (overrideInput) overrideInput.checked = false;
        }

        function selectExisting(match) {
            selectedMatch = match;
            if (nameInput) nameInput.value = match.name || '';
            if (mobileInput) mobileInput.value = match.mobile || '';
            if (emailInput) emailInput.value = match.email || '';
            if (existingIdInput) existingIdInput.value = String(match.id || '');
            if (overrideInput) overrideInput.checked = false;
            lastCheckedSignature = currentSignature();
            setSaveState();

            if (useMode === 'navigate' && viewTemplate) {
                window.location.href = viewTemplate.replace('{id}', encodeURIComponent(match.id));
            } else {
                panel.classList.add('existing-selected');
                var title = panel.querySelector('[data-duplicate-title]');
                if (title) title.textContent = 'Existing customer selected';
            }
        }

        function showResult(data) {
            currentType = data.match_type || 'none';
            panel.hidden = false;
            panel.classList.remove('existing-selected');
            setBadge(panel, currentType);

            var title = panel.querySelector('[data-duplicate-title]');
            if (title) {
                title.textContent = simpleError && currentType === 'exact'
                    ? 'Walk-in customer cannot be saved'
                    : currentType === 'exact'
                    ? 'A matching customer already exists'
                    : (currentType === 'possible' ? 'Please review possible matches' : 'No matching customer found');
            }

            var matches = Array.isArray(data.duplicates) ? data.duplicates : [];
            if (resultContainer) {
                if (simpleError) renderDuplicateReasons(resultContainer, matches);
                else renderMatches(resultContainer, matches);
            }
            selectedMatch = matches.length ? matches[0] : null;

            if (useButton) useButton.hidden = simpleError || !selectedMatch;
            if (viewButton) {
                viewButton.hidden = simpleError || !selectedMatch || !viewTemplate;
                if (selectedMatch && viewTemplate) {
                    viewButton.href = viewTemplate.replace('{id}', encodeURIComponent(selectedMatch.id));
                }
            }

            if (overrideContainer) {
                overrideContainer.hidden = simpleError || currentType !== 'exact';
            }

            if (currentType === 'exact' && autoUse && selectedMatch && existingIdInput) {
                existingIdInput.value = String(selectedMatch.id);
            }
            setSaveState();
        }

        function currentSignature() {
            return JSON.stringify([
                nameInput ? nameInput.value.trim() : '',
                mobileInput ? mobileInput.value.trim() : '',
                emailInput ? emailInput.value.trim() : '',
                customerTypeInput ? customerTypeInput.value : 'Walk-in Customer'
            ]);
        }

        function runCheck() {
            var name = nameInput ? nameInput.value.trim() : '';
            var mobile = mobileInput ? mobileInput.value.trim() : '';
            var email = emailInput ? emailInput.value.trim() : '';

            // Duplicate blocking is a Walk-in Customer rule. B2B, Corporate,
            // User and Other customer types may have repeated contact details.
            if (customerTypeInput && customerTypeInput.value !== 'Walk-in Customer') {
                currentType = 'none';
                panel.hidden = true;
                clearSelection();
                setSaveState();
                return Promise.resolve({status: 'success', match_type: 'none', duplicates: []});
            }

            // Empty contact fields never participate in duplicate matching.
            if (mobile === '' && email === '') {
                currentType = 'none';
                panel.hidden = true;
                clearSelection();
                setSaveState();
                return Promise.resolve({status: 'success', match_type: 'none', duplicates: []});
            }

            if (requestController) requestController.abort();
            requestController = new AbortController();
            var params = new URLSearchParams({name: name, mobile: mobile, email: email, table: table});
            return fetch(endpoint + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                signal: requestController.signal
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Duplicate check failed');
                    return response.json();
                })
                .then(function (data) {
                    if (data.status === 'success') {
                        showResult(data);
                        return data;
                    }
                    throw new Error('Duplicate check failed');
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') return null;
                    currentType = 'none';
                    panel.hidden = true;
                    setSaveState();
                    return {status: 'error', match_type: 'none', duplicates: []};
                });
        }

        function resetCheck() {
            clearSelection();
            currentType = 'none';
            lastCheckedSignature = null;
            panel.hidden = true;
            panel.classList.remove('existing-selected');
            setSaveState();
        }

        [nameInput, mobileInput, emailInput].forEach(function (input) {
            if (input) input.addEventListener('input', resetCheck);
        });
        if (customerTypeInput) customerTypeInput.addEventListener('change', resetCheck);
        if (useButton) {
            useButton.addEventListener('click', function () {
                if (selectedMatch) selectExisting(selectedMatch);
            });
        }
        if (overrideInput) overrideInput.addEventListener('change', setSaveState);

        form.addEventListener('submit', function (event) {
            var signature = currentSignature();
            var selectedExisting = existingIdInput && existingIdInput.value !== '';
            var overrideConfirmed = overrideInput && overrideInput.checked;
            var canSubmitCheckedResult = currentType === 'none'
                || currentType === 'possible'
                || selectedExisting
                || overrideConfirmed;

            if (lastCheckedSignature === signature && canSubmitCheckedResult) return;

            event.preventDefault();
            if (checking) return;
            checking = true;
            if (saveButton) saveButton.disabled = true;

            runCheck().then(function (data) {
                checking = false;
                if (!data) return;
                lastCheckedSignature = signature;
                setSaveState();

                // A clean result can continue immediately. Possible and exact
                // matches remain visible for review and require another tap.
                if (data.match_type === 'none' && form.isConnected) {
                    form.requestSubmit(saveButton || undefined);
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-duplicate-check]').forEach(initDuplicateForm);
    });
}());
