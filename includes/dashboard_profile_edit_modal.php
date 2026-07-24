<?php
$profile_edit_prefix = preg_replace('/[^a-z0-9_-]/i', '', (string) ($profile_edit_prefix ?? 'dashboard'));
$profile_edit_information = is_array($dashboard_profile_information ?? null)
    ? $dashboard_profile_information
    : [];
$profile_edit_name = (string) ($profile_edit_information['name'] ?? ($_SESSION['user_name'] ?? 'User'));
$profile_edit_email = (string) ($profile_edit_information['email'] ?? ($_SESSION['user_email'] ?? ''));
$profile_edit_modal_id = $profile_edit_prefix . '-dashboard-profile-edit';
$profile_edit_failed = isset($_POST['update_dashboard_profile_details'])
    && is_array($dashboard_profile_result ?? null)
    && empty($dashboard_profile_result['success']);
$profile_edit_value = static function ($field) use ($profile_edit_failed, $profile_edit_information) {
    if ($profile_edit_failed && array_key_exists($field, $_POST)) {
        return trim((string) $_POST[$field]);
    }
    return trim((string) ($profile_edit_information[$field] ?? ''));
};
?>
<div class="crm-profile-edit-modal" id="<?php echo htmlspecialchars($profile_edit_modal_id); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo htmlspecialchars($profile_edit_modal_id); ?>-title" data-profile-edit-modal <?php echo $profile_edit_failed ? '' : 'hidden'; ?>>
    <div class="crm-profile-edit-backdrop" data-profile-edit-close></div>
    <section class="crm-profile-edit-dialog">
        <header class="crm-profile-edit-header">
            <div>
                <span>My account</span>
                <h2 id="<?php echo htmlspecialchars($profile_edit_modal_id); ?>-title">Profile details</h2>
            </div>
            <button type="button" class="crm-profile-edit-close" aria-label="Close profile editor" data-profile-edit-close>&times;</button>
        </header>

        <?php if ($profile_edit_failed): ?>
            <div class="crm-profile-edit-error" role="alert"><?php echo htmlspecialchars($dashboard_profile_result['message']); ?></div>
        <?php endif; ?>

        <form method="POST" class="crm-profile-edit-form" autocomplete="on">
            <input type="hidden" name="update_dashboard_profile_details" value="1">
            <input type="hidden" name="dashboard_profile_csrf_token" value="<?php echo htmlspecialchars(dashboard_profile_csrf_token()); ?>">

            <div class="crm-profile-edit-section">
                <div class="crm-profile-edit-section-title">
                    <span>Personal information</span>
                    <p>Your saved details are shown below and can be updated here.</p>
                </div>
                <div class="crm-profile-edit-grid">
                    <label>
                        <span>Full name</span>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($profile_edit_failed ? $profile_edit_value('name') : $profile_edit_name); ?>" maxlength="255" autocomplete="name" required>
                    </label>
                    <label>
                        <span>Email</span>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($profile_edit_failed ? $profile_edit_value('email') : $profile_edit_email); ?>" maxlength="255" autocomplete="email" required>
                    </label>
                    <label>
                        <span>Phone</span>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($profile_edit_value('phone')); ?>" maxlength="50" autocomplete="tel">
                    </label>
                    <label>
                        <span>Date of birth</span>
                        <input type="date" name="dob" value="<?php echo htmlspecialchars($profile_edit_value('dob')); ?>" max="<?php echo date('Y-m-d'); ?>" autocomplete="bday">
                    </label>
                    <label class="is-full">
                        <span>Address</span>
                        <textarea name="address" rows="2" autocomplete="street-address"><?php echo htmlspecialchars($profile_edit_value('address')); ?></textarea>
                    </label>
                    <label>
                        <span>City</span>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($profile_edit_value('city')); ?>" maxlength="100" autocomplete="address-level2">
                    </label>
                    <label>
                        <span>State</span>
                        <input type="text" name="state" value="<?php echo htmlspecialchars($profile_edit_value('state')); ?>" maxlength="100" autocomplete="address-level1">
                    </label>
                    <label>
                        <span>Country</span>
                        <input type="text" name="country" value="<?php echo htmlspecialchars($profile_edit_value('country')); ?>" maxlength="100" autocomplete="country-name">
                    </label>
                    <label>
                        <span>ZIP / postal code</span>
                        <input type="text" name="zip_code" value="<?php echo htmlspecialchars($profile_edit_value('zip_code')); ?>" maxlength="20" autocomplete="postal-code">
                    </label>
                </div>
            </div>

            <div class="crm-profile-edit-section">
                <div class="crm-profile-edit-section-title">
                    <span>Change password</span>
                    <p>Leave these fields blank when you do not want to change the password.</p>
                </div>
                <div class="crm-profile-edit-grid">
                    <label>
                        <span>Current password</span>
                        <input type="password" name="current_password" autocomplete="current-password">
                    </label>
                    <label>
                        <span>New password</span>
                        <input type="password" name="new_password" minlength="8" autocomplete="new-password">
                    </label>
                    <label>
                        <span>Confirm new password</span>
                        <input type="password" name="confirm_password" minlength="8" autocomplete="new-password">
                    </label>
                </div>
            </div>

            <footer class="crm-profile-edit-actions">
                <button type="button" class="crm-button crm-button-secondary" data-profile-edit-close>Cancel</button>
                <button type="submit" class="crm-button crm-button-primary">Save profile</button>
            </footer>
        </form>
    </section>
</div>
