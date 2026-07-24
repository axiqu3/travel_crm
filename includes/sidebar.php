<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_script = $_SERVER['SCRIPT_NAME'];
$prefix = "";
$base_url = "/";
$is_admin = false;
$is_user = false;

if (stripos($current_script, '/admin_page/') !== false) {
    $is_admin = true;
    $parts = preg_split('~/admin_page/~i', $current_script);
    $subpath = $parts[1];
    $base_url = $parts[0] . "/";
    $depth = substr_count($subpath, '/');
    $prefix = str_repeat('../', $depth);
} elseif (stripos($current_script, '/user_page/') !== false) {
    $is_user = true;
    $parts = preg_split('~/user_page/~i', $current_script);
    $subpath = $parts[1];
    $base_url = $parts[0] . "/";
    $depth = substr_count($subpath, '/');
    $prefix = str_repeat('../', $depth);
}

$current_relative_path = ltrim(str_replace('\\', '/', $subpath ?? basename($current_script)), '/');

// Icon SVG Definitions
$icons = [
    'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>',
    'master' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75" /></svg>',
    'bookings' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>',
    'customers' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.97 5.97 0 00-.75-2.985m-.008-3.225A9.01 9.01 0 0112 15a9.01 9.01 0 01-5.242-1.67M12 15a9.01 9.01 0 00-5.242-1.67M3 18.72A9.094 9.094 0 016.742 18.2M6.742 18.2a5.97 5.97 0 01-.75-2.985M6.742 18.2a5.97 5.97 0 00.75-2.985m-5.992 3.5l.002.031c0 .225.011.447.037.666A11.944 11.944 0 0012 21c2.17 0 4.207-.576-5.963-1.584A6.06 6.06 0 0018 18.72m-12 0a5.97 5.97 0 00.75-2.985m-.008-3.225A9.01 9.01 0 0112 15m0 0c-2.9 0-5.4.75-7.42 2.03M12 15c2.9 0 5.4.75 7.42 2.03M12 9a3 3 0 110-6 3 3 0 010 6zm0 0a3 3 0 100-6 3 3 0 000 6zm-7.5 1.5a2.25 2.25 0 110-4.5 2.25 2.25 0 010 4.5zm15 0a2.25 2.25 0 110-4.5 2.25 2.25 0 010 4.5z" /></svg>',
    'tasks' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>',
    'activity' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
    'enquiry' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>',
    'logout' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" /></svg>',
    'walk-in' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" /></svg>',
    'b2b' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 .621-.504 1.125-1.125 1.125H4.875c-.621 0-1.125-.504-1.125-1.125v-4.25m16.5 0a2.25 2.25 0 00-1.883-2.212c-1.157-.191-2.34-.288-3.542-.288-1.203 0-2.385.097-3.542.288a2.25 2.25 0 00-1.883 2.212m16.5 0V9.75c0-.621-.504-1.125-1.125-1.125H4.875c-.621 0-1.125.504-1.125 1.125v4.4m16.5 0a9 9 0 00-16.5 0M12 3v3h3a3 3 0 00-6 0v3" /></svg>',
    'corporate' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 9h1.5v1.5H9V9zm0 3.75h1.5V14H9v-1.25zm0 3.75h1.5v1.5H9v-1.5zM13.5 9h1.5v1.5h-1.5V9zm0 3.75h1.5V14h-1.5v-1.25zm0 3.75h1.5v1.5h-1.5v-1.5z" /></svg>',
    'email' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>',
    'add-booking' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
    'reports' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z" /><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z" /></svg>',
];

$chevron = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>';

// Determine Active Page and Parameter States
$query_string = $_SERVER['QUERY_STRING'] ?? '';
parse_str($query_string, $query_params);

if (!function_exists('check_url_active')) {
    function check_url_active($item_url, $current_relative_path, $query_params) {
        $parsed = parse_url($item_url);
        $item_path = ltrim(str_replace('\\', '/', $parsed['path'] ?? ''), '/');
        $item_query = $parsed['query'] ?? '';

        // Handle active state for any page under the enquiry folder
        if ($item_path === 'enquiry/list.php' && strpos($current_relative_path, 'enquiry/') === 0) {
            return true;
        }

        // Compare the complete role-relative path. Several menu destinations use
        // the same filename (for example master/list.php, bookings/list.php and
        // enquiry/list.php), so basename-only matching highlights the wrong menu.
        if ($current_relative_path !== $item_path) {
            return false;
        }

        if ($item_query !== '') {
            parse_str($item_query, $item_q_params);
            foreach ($item_q_params as $k => $v) {
                if (($query_params[$k] ?? '') !== $v) {
                    return false;
                }
            }
        } else {
            // Fallback checks for filter query exclusions
            if (!empty($query_params['customer_type']) || !empty($query_params['source']) || !empty($query_params['filter_source'])) {
                return false;
            }
        }
        return true;
    }
}

// Check route folders to keep dropdowns expanded
$master_route_active = strpos($current_script, '/master/') !== false;
$bookings_route_active = strpos($current_script, '/bookings/') !== false;
$enquiry_route_active = strpos($current_script, '/enquiry/') !== false;
$customers_route_active = strpos($current_script, '/customers/') !== false;

// Determine menu items
$menu = [];
if ($is_admin) {
    $menu = [
        ['type' => 'link', 'text' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'dashboard'],
        [
            'type' => 'dropdown',
            'text' => 'Master',
            'icon' => 'master',
            'route_active' => $master_route_active,
            'children' => [
                ['text' => 'All Customers', 'url' => 'master/list.php', 'icon' => 'customers'],
                ['text' => 'Walk-in Customers', 'url' => 'master/list.php?customer_type=Walk-in+Customer', 'icon' => 'walk-in'],
                ['text' => 'B2B Customers', 'url' => 'master/list.php?customer_type=B2B', 'icon' => 'b2b'],
                ['text' => 'Corporate Customers', 'url' => 'master/list.php?customer_type=Corporate', 'icon' => 'corporate'],
                ['text' => 'Users', 'url' => 'master/list.php?customer_type=User', 'icon' => 'customers']
            ]
        ],
        [
            'type' => 'dropdown',
            'text' => 'Bookings',
            'icon' => 'bookings',
            'route_active' => $bookings_route_active,
            'children' => [
                ['text' => 'Bookings List', 'url' => 'bookings/list.php', 'icon' => 'bookings'],
                ['text' => 'Add Booking', 'url' => 'bookings/add.php', 'icon' => 'add-booking'],
                ['text' => 'Bulk Import', 'url' => 'bookings/import.php', 'icon' => 'add-booking'],
                ['text' => 'Reports', 'url' => 'bookings/reports.php', 'icon' => 'reports']
            ]
        ],
        ['type' => 'link', 'text' => 'Enquiry', 'url' => 'enquiry/list.php', 'icon' => 'enquiry'],
        ['type' => 'link', 'text' => 'Tasks', 'url' => 'tasks/index.php', 'icon' => 'tasks'],
        ['type' => 'link', 'text' => 'Activity', 'url' => 'admin/activity.php', 'icon' => 'activity'],
        ['type' => 'link', 'text' => 'Logout', 'url' => $base_url . 'login.php?logout=1', 'icon' => 'logout', 'class' => 'logout']
    ];
} else {
    $menu = [
        ['type' => 'link', 'text' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'dashboard'],
        [
            'type' => 'dropdown',
            'text' => 'Bookings',
            'icon' => 'bookings',
            'route_active' => $bookings_route_active,
            'children' => [
                ['text' => 'My Bookings', 'url' => 'bookings/list.php', 'icon' => 'bookings'],
                ['text' => 'Add Booking', 'url' => 'bookings/add.php', 'icon' => 'add-booking'],
                ['text' => 'Bulk Import', 'url' => 'bookings/import.php', 'icon' => 'add-booking']
            ]
        ],
        ['type' => 'link', 'text' => 'Customers', 'url' => 'customers/list.php', 'icon' => 'customers'],
        ['type' => 'link', 'text' => 'Enquiry', 'url' => 'enquiry/list.php', 'icon' => 'enquiry'],
        ['type' => 'link', 'text' => 'My Tasks', 'url' => 'tasks/index.php', 'icon' => 'tasks'],
        ['type' => 'link', 'text' => 'Logout', 'url' => $base_url . 'login.php?logout=1', 'icon' => 'logout', 'class' => 'logout']
    ];
}
?>
<div class="sidebar sidebar-modern locked">
    <a class="logo" href="<?= $prefix ?>dashboard.php" aria-label="Go back to Dashboard" title="Go back to Dashboard"><span class="logo-icon">&#9992;</span><span class="logo-text"><?= htmlspecialchars(COMPANY_NAME) ?></span></a>
    <?php foreach ($menu as $item): ?>
        <?php if ($item['type'] === 'link'): ?>
            <?php
            $active = check_url_active($item['url'], $current_relative_path, $query_params);
            $active_class = $active ? ' active' : '';
            $custom_class = isset($item['class']) ? ' ' . $item['class'] : '';
            ?>
            <a href="<?= (strpos($item['url'], '/') === 0 ? '' : $prefix) . $item['url'] ?>" class="nav-item<?= $active_class . $custom_class ?>">
                <span class="nav-icon"><?= $icons[$item['icon']] ?></span>
                <span class="nav-text"><?= $item['text'] ?></span>
            </a>
        <?php elseif ($item['type'] === 'dropdown'): ?>
            <?php
            $children_html = '';
            $any_active = $item['route_active'];
            foreach ($item['children'] as $child) {
                $child_active = check_url_active($child['url'], $current_relative_path, $query_params);
                if ($child_active) {
                    $any_active = true;
                }
                $child_active_class = $child_active ? ' active' : '';
                $children_html .= '
                    <a href="' . (strpos($child['url'], '/') === 0 ? '' : $prefix) . $child['url'] . '" class="nav-subitem' . $child_active_class . '">
                        <span class="nav-icon">' . $icons[$child['icon']] . '</span>
                        <span class="nav-text">' . $child['text'] . '</span>
                    </a>';
            }
            $open_class = $any_active ? ' open' : '';
            $parent_active_class = $any_active ? ' parent-active' : '';
            $style = $any_active ? ' style="max-height: none;"' : '';
            ?>
            <div class="nav-dropdown<?= $open_class ?>" data-route-active="<?= $any_active ? 'true' : 'false' ?>">
                <button class="nav-dropdown-btn<?= $parent_active_class ?>">
                    <span class="nav-icon"><?= $icons[$item['icon']] ?></span>
                    <span class="nav-text"><?= $item['text'] ?></span>
                    <span class="nav-arrow"><?= $chevron ?></span>
                </button>
                <div class="nav-dropdown-content"<?= $style ?>>
                    <?= $children_html ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<script>
(() => {
    if (window.__crmSuccessAutoHideInitialized) return;
    window.__crmSuccessAutoHideInitialized = true;

    const initializeSuccessAutoHide = () => {
        const schedule = element => {
            if (!(element instanceof HTMLElement) || element.dataset.autoHideScheduled === '1') return;

            const text = element.textContent.trim();
            const explicitSuccess = element.matches(
                '[data-auto-hide="1"], .booking-alert.is-success, .profile-alert.is-success, .alert-success, .alert.is-success'
            );
            const simpleSuccessMessage =
                ['DIV', 'P', 'SECTION'].includes(element.tagName) &&
                element.children.length <= 3 &&
                text.length > 0 &&
                text.length <= 300 &&
                /\bsuccess(?:ful(?:ly)?)?\b/i.test(text);
            const errorOrWarning =
                element.matches('[data-auto-hide="0"], .is-error, .alert-error, .error, .warning, .is-warning') ||
                /\b(error|failed|warning|unable|could not)\b/i.test(text);

            if ((!explicitSuccess && !simpleSuccessMessage) || errorOrWarning) return;

            element.dataset.autoHideScheduled = '1';
            element.style.transition = 'opacity .3s ease, transform .3s ease';
            window.setTimeout(() => {
                if (!element.isConnected) return;
                element.style.opacity = '0';
                element.style.transform = 'translateY(-6px)';
                window.setTimeout(() => element.remove(), 300);
            }, 5000);
        };

        const scan = root => {
            if (!(root instanceof Element || root instanceof Document)) return;
            if (root instanceof Element) schedule(root);
            root.querySelectorAll(
                '[data-auto-hide], [role="alert"], .booking-alert, .profile-alert, .alert-success, .alert, div, p, section'
            ).forEach(schedule);
        };

        scan(document);
        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node instanceof Element) scan(node);
                });
            });
        });
        observer.observe(document.body, {childList: true, subtree: true});
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSuccessAutoHide, {once: true});
    } else {
        initializeSuccessAutoHide();
    }
})();
</script>
