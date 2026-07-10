document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.querySelector(".sidebar");
  if (!sidebar) return;

  sidebar.classList.add("sidebar-modern");

  // Mobile drawer controls. The desktop sidebar remains permanently visible,
  // while small screens use an off-canvas drawer so content keeps full width.
  if (!sidebar.id) sidebar.id = "appSidebar";

  const mobileToggle = document.createElement("button");
  mobileToggle.type = "button";
  mobileToggle.className = "sidebar-mobile-toggle";
  mobileToggle.textContent = "\u2630";
  mobileToggle.setAttribute("aria-label", "Open navigation menu");
  mobileToggle.setAttribute("aria-controls", sidebar.id);
  mobileToggle.setAttribute("aria-expanded", "false");

  const mobileOverlay = document.createElement("div");
  mobileOverlay.className = "sidebar-mobile-overlay";
  mobileOverlay.setAttribute("aria-hidden", "true");

  document.body.appendChild(mobileToggle);
  document.body.appendChild(mobileOverlay);

  const closeMobileSidebar = () => {
    document.body.classList.remove("sidebar-mobile-open");
    mobileToggle.textContent = "\u2630";
    mobileToggle.setAttribute("aria-label", "Open navigation menu");
    mobileToggle.setAttribute("aria-expanded", "false");
  };

  const openMobileSidebar = () => {
    document.body.classList.add("sidebar-mobile-open");
    mobileToggle.textContent = "\u00d7";
    mobileToggle.setAttribute("aria-label", "Close navigation menu");
    mobileToggle.setAttribute("aria-expanded", "true");
  };

  mobileToggle.addEventListener("click", () => {
    if (document.body.classList.contains("sidebar-mobile-open")) {
      closeMobileSidebar();
    } else {
      openMobileSidebar();
    }
  });

  mobileOverlay.addEventListener("click", closeMobileSidebar);

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeMobileSidebar();
  });

  sidebar.querySelectorAll("a").forEach(link => {
    link.addEventListener("click", () => {
      if (window.matchMedia("(max-width: 900px)").matches) {
        closeMobileSidebar();
      }
    });
  });

  const desktopMedia = window.matchMedia("(min-width: 901px)");
  const resetMobileState = (event) => {
    if (event.matches) closeMobileSidebar();
  };
  if (desktopMedia.addEventListener) {
    desktopMedia.addEventListener("change", resetMobileState);
  } else {
    desktopMedia.addListener(resetMobileState);
  }

  // Initialize maxHeight on open dropdowns so they collapse smoothly without flickering
  sidebar.querySelectorAll(".nav-dropdown.open .nav-dropdown-content").forEach(content => {
    content.style.maxHeight = content.scrollHeight + "px";
  });

  // Setup click handlers for accordion toggle
  sidebar.querySelectorAll(".nav-dropdown-btn").forEach(btn => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();

      const dropdown = btn.closest(".nav-dropdown");
      const content = dropdown.querySelector(".nav-dropdown-content");
      const isOpen = dropdown.classList.contains("open");

      // Close other dropdowns
      sidebar.querySelectorAll(".nav-dropdown").forEach(otherDropdown => {
        if (otherDropdown !== dropdown && otherDropdown.classList.contains("open")) {
          const otherContent = otherDropdown.querySelector(".nav-dropdown-content");
          otherDropdown.classList.remove("open");
          otherContent.style.maxHeight = "0px";
          if (otherDropdown.dataset.routeActive !== "true") {
            otherDropdown.querySelector(".nav-dropdown-btn").classList.remove("parent-active");
          }
        }
      });

      // Expand and pin the sidebar when a dropdown is opened.
      if (!sidebar.classList.contains("locked")) {
        sidebar.classList.add("locked");
        const main = document.querySelector(".main");
        if (main) main.classList.add("sidebar-locked");
        localStorage.setItem("sidebar-locked", "true");
      }

      if (isOpen) {
        // Collapse dropdown
        content.style.maxHeight = "0px";
        dropdown.classList.remove("open");
        if (dropdown.dataset.routeActive !== "true") {
          btn.classList.remove("parent-active");
        }
      } else {
        // Expand dropdown
        dropdown.classList.add("open");
        btn.classList.add("parent-active");
        content.style.maxHeight = content.scrollHeight + "px";
      }
    });
  });

  // Keep the sidebar permanently expanded at the standard full size.
  const main = document.querySelector(".main");
  sidebar.classList.add("locked");
  if (main) main.classList.add("sidebar-locked");
  localStorage.setItem("sidebar-locked", "true");

  document.documentElement.classList.remove("sidebar-pref-locked");

  // 4. Activate Global Search Bar
  const searchInput = document.querySelector(".search");
  if (searchInput) {
    searchInput.addEventListener("input", function() {
      const searchVal = this.value.toLowerCase().trim();

      // Filter standard table rows
      const rows = document.querySelectorAll("tbody tr");
      rows.forEach(row => {
        if (row.cells.length === 1 && row.cells[0].colSpan > 1) return;
        const text = row.textContent.toLowerCase();
        if (text.includes(searchVal)) {
          row.style.display = "";
        } else {
          row.style.display = "none";
        }
      });

      // Filter Gmail-style inbox rows (admin enquiries page)
      const inboxRows = document.querySelectorAll(".inbox-row");
      inboxRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchVal)) {
          row.style.display = "flex";
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
