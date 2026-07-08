document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.querySelector(".sidebar");
  if (!sidebar) return;

  sidebar.classList.add("sidebar-modern");

  // SVG Icon definitions
  const icons = {
    'dashboard': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>`,
    'users': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.109A11.386 11.386 0 0110.089 20M3 16.5a4.125 4.125 0 017.533-2.493M3 16.5a9.039 9.039 0 012.625-.372 9.337 9.337 0 014.121.952M3 16.5v-2.128C3 13.167 3.84 12.33 4.873 12.235A11.233 11.233 0 0110 11.25c2.569 0 4.957.859 6.873 2.308c1.033.095 1.873.932 1.873 2.067v2.128m0 0h-.002" /></svg>`,
    'bookings': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>`,
    'my-bookings': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>`,
    'add-booking': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`,
    'reports': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z" /><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z" /></svg>`,
    'customers': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.97 5.97 0 00-.75-2.985m-.008-3.225A9.01 9.01 0 0112 15a9.01 9.01 0 01-5.242-1.67M12 15a9.01 9.01 0 00-5.242-1.67M3 18.72A9.094 9.094 0 016.742 18.2M6.742 18.2a5.97 5.97 0 01-.75-2.985M6.742 18.2a5.97 5.97 0 00.75-2.985m-5.992 3.5l.002.031c0 .225.011.447.037.666A11.944 11.944 0 0012 21c2.17 0 4.207-.576 5.963-1.584A6.06 6.06 0 0018 18.72m-12 0a5.97 5.97 0 00.75-2.985m-.008-3.225A9.01 9.01 0 0112 15m0 0c-2.9 0-5.4.75-7.42 2.03M12 15c2.9 0 5.4.75 7.42 2.03M12 9a3 3 0 110-6 3 3 0 010 6zm0 0a3 3 0 100-6 3 3 0 000 6zm-7.5 1.5a2.25 2.25 0 110-4.5 2.25 2.25 0 010 4.5zm15 0a2.25 2.25 0 110-4.5 2.25 2.25 0 010 4.5z" /></svg>`,
    'my-tasks': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>`,
    'tasks': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>`,
    'activity': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`,
    'enquiry': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>`,
    'logout': `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" /></svg>`
  };

  // 1. Redraw Logo
  const logo = sidebar.querySelector(".logo");
  if (logo) {
    logo.innerHTML = `<span class="logo-icon">✈</span><span class="logo-text">Travel CRM</span>`;
  }

  // 2. Wrap all links with structure
  const links = sidebar.querySelectorAll("a");
  links.forEach(link => {
    const text = link.textContent.trim();
    const key = text.toLowerCase().replace(/\s+/g, '-');
    const svgIcon = icons[key] || icons['dashboard'];

    link.innerHTML = `<span class="nav-icon">${svgIcon}</span><span class="nav-text">${text}</span>`;
    link.classList.add("nav-item");
  });

  // Click to lock/unlock (tap anywhere on the sidebar body, except on navigation links)
  const main = document.querySelector(".main");

  // Load state from localStorage
  const isLocked = localStorage.getItem("sidebar-locked") === "true";
  if (isLocked) {
    sidebar.classList.add("locked");
    if (main) main.classList.add("sidebar-locked");
  }

  // Restore animations by removing pre-paint helper class
  document.documentElement.classList.remove("sidebar-pref-locked");

  sidebar.addEventListener("click", (e) => {
    // If clicking a link, let them navigate normally
    if (e.target.closest("a")) return;

    e.preventDefault();
    e.stopPropagation();

    const wasLocked = sidebar.classList.contains("locked");
    if (wasLocked) {
      sidebar.classList.remove("locked");
      if (main) main.classList.remove("sidebar-locked");
      localStorage.setItem("sidebar-locked", "false");
    } else {
      sidebar.classList.add("locked");
      if (main) main.classList.add("sidebar-locked");
      localStorage.setItem("sidebar-locked", "true");
    }
  });

  // 4. Activate Global Search Bar
  const searchInput = document.querySelector(".search");
  if (searchInput) {
    searchInput.addEventListener("input", function() {
      const searchVal = this.value.toLowerCase().trim();
      const rows = document.querySelectorAll("tbody tr");

      rows.forEach(row => {
        // Skip the "No records/bookings found" fallback row (which usually has a single cell spanning columns)
        if (row.cells.length === 1 && row.cells[0].colSpan > 1) return;

        const text = row.textContent.toLowerCase();
        if (text.includes(searchVal)) {
          row.style.display = "";
        } else {
          row.style.display = "none";
        }
      });
    });
  }

  // 3. Advanced WhatsApp Integration Logic
  const templates = {
    booking_confirmation: `Hello {Customer},

Your booking has been confirmed.

Passenger: {Passenger}
PNR: {PNR}
Travel Date: {Travel Date}
Service: {Service}

Thank you.`,

    payment_reminder: `Hello {Customer},

This is a reminder regarding your pending payment.

Balance Amount: {Balance}
Booking No: {Booking No}

Thank you.`,

    follow_up: `Hello {Customer},

We are following up regarding your enquiry.

Please let us know if you need any assistance.`,

    general_greeting: `Hello {Customer},

Thank you for contacting us.

How can we help you today?`
  };

  // Helper to clean/format phone numbers (Indian mobile format logic)
  function formatWhatsAppNumber(mobile) {
    if (!mobile) return '';
    let clean = mobile.replace(/\D/g, '');
    if (!clean) return '';
    if (clean.length === 10) {
      clean = '91' + clean;
    } else if (clean.length === 11 && clean.startsWith('0')) {
      clean = '91' + clean.substring(1);
    }
    return clean;
  }

  // Helper to interpolate template variables
  function interpolateTemplate(templateText, btn) {
    const data = {
      'Customer': btn.getAttribute('data-customer') || '',
      'Passenger': btn.getAttribute('data-passenger') || '',
      'PNR': btn.getAttribute('data-pnr') || '',
      'Travel Date': btn.getAttribute('data-travel-date') || '',
      'Service': btn.getAttribute('data-service') || '',
      'Balance': btn.getAttribute('data-balance') || '',
      'Booking No': btn.getAttribute('data-booking-no') || ''
    };
    let text = templateText;
    for (const key in data) {
      text = text.replace(new RegExp('{' + key + '}', 'g'), data[key]);
    }
    return text;
  }

  // Create custom message modal elements if not exists
  let modalOverlay = document.getElementById('waModalOverlay');
  if (!modalOverlay) {
    modalOverlay = document.createElement('div');
    modalOverlay.id = 'waModalOverlay';
    modalOverlay.className = 'wa-modal-overlay';
    modalOverlay.innerHTML = `
      <div class="wa-modal">
        <div class="wa-modal-header">
          <h3 class="wa-modal-title">✉ Custom WhatsApp Message</h3>
          <button type="button" class="wa-modal-close" id="waModalClose">&times;</button>
        </div>
        <div class="wa-modal-body">
          <label class="wa-modal-label" for="waTextarea">Message Content</label>
          <textarea class="wa-textarea" id="waTextarea" placeholder="Type your custom message here..."></textarea>
        </div>
        <div class="wa-modal-footer">
          <button type="button" class="wa-modal-btn wa-modal-btn-cancel" id="waModalCancel">Cancel</button>
          <button type="button" class="wa-modal-btn wa-modal-btn-send" id="waModalSend">Send Message</button>
        </div>
      </div>
    `;
    document.body.appendChild(modalOverlay);
  }

  let activeWaButton = null;

  // Toggle Dropdown menu visibility
  document.addEventListener('click', (e) => {
    // 1. Toggle dropdown active state
    const btn = e.target.closest('.wa-btn');
    if (btn) {
      const container = btn.closest('.wa-dropdown-container');
      // Close other dropdowns
      document.querySelectorAll('.wa-dropdown-container').forEach(c => {
        if (c !== container) c.classList.remove('active');
      });
      container.classList.toggle('active');
      e.preventDefault();
      return;
    }

    // Close all dropdowns if clicking outside
    if (!e.target.closest('.wa-dropdown-container')) {
      document.querySelectorAll('.wa-dropdown-container').forEach(c => {
        c.classList.remove('active');
      });
    }

    // 2. Handle option clicks
    const opt = e.target.closest('.wa-opt');
    if (opt) {
      const container = opt.closest('.wa-dropdown-container');
      const triggerBtn = container.querySelector('.wa-btn');
      const templateKey = opt.getAttribute('data-template');
      const rawMobile = triggerBtn.getAttribute('data-mobile') || '';
      const cleanMobile = formatWhatsAppNumber(rawMobile);

      container.classList.remove('active');
      e.preventDefault();

      if (!cleanMobile) {
        alert('Invalid or empty WhatsApp number.');
        return;
      }

      if (templateKey === 'custom_message') {
        activeWaButton = triggerBtn;
        const textarea = document.getElementById('waTextarea');
        const customerName = triggerBtn.getAttribute('data-customer') || '';
        // Pre-fill greeting with variable already resolved
        textarea.value = `Hello ${customerName},\n\n`;
        modalOverlay.classList.add('active');
        textarea.focus();
      } else if (templates[templateKey]) {
        const message = interpolateTemplate(templates[templateKey], triggerBtn);
        const url = `https://wa.me/${cleanMobile}?text=${encodeURIComponent(message)}`;
        window.open(url, '_blank');
      }
    }
  });

  // Modal Control Handlers
  const closeModal = () => {
    modalOverlay.classList.remove('active');
    activeWaButton = null;
  };

  document.getElementById('waModalClose').addEventListener('click', closeModal);
  document.getElementById('waModalCancel').addEventListener('click', closeModal);
  modalOverlay.addEventListener('click', (e) => {
    if (e.target === modalOverlay) closeModal();
  });

  document.getElementById('waModalSend').addEventListener('click', () => {
    if (!activeWaButton) return;
    const text = document.getElementById('waTextarea').value;
    const rawMobile = activeWaButton.getAttribute('data-mobile') || '';
    const cleanMobile = formatWhatsAppNumber(rawMobile);
    if (!cleanMobile) {
      alert('Invalid or empty WhatsApp number.');
      return;
    }
    
    // Replace custom variables in case they manually typed templates in modal
    const message = interpolateTemplate(text, activeWaButton);
    const url = `https://wa.me/${cleanMobile}?text=${encodeURIComponent(message)}`;
    window.open(url, '_blank');
    closeModal();
  });
});
