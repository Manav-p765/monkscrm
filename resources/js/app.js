// Alpine's CSP build: no eval/new Function, so it works under our strict
// Content-Security-Policy. Components must be registered here with Alpine.data()
// and referenced by name in x-data; inline object literals are not supported.
import Alpine from '@alpinejs/csp';

// Sidebar open/closed state on tablet and phone widths.
Alpine.data('sidebar', () => ({
    open: false,
    toggle() {
        this.open = !this.open;
    },
    close() {
        this.open = false;
    },
    get panelClasses() {
        return { 'translate-x-0': this.open, '-translate-x-full': !this.open };
    },
}));

// <x-flash>: success messages fade out after a few seconds; errors stay until dismissed.
Alpine.data('flash', () => ({
    visible: true,
    init() {
        if (this.$el.dataset.autoDismiss === 'true') {
            setTimeout(() => this.dismiss(), 5000);
        }
    },
    dismiss() {
        this.visible = false;
    },
}));

// <x-confirm-dialog>: native <dialog> handles focus trapping and Escape.
Alpine.data('confirmDialog', () => ({
    open() {
        this.$refs.dialog.showModal();
    },
    close() {
        this.$refs.dialog.close();
    },
}));

// <x-more-menu>: the single "More" menu for secondary actions.
Alpine.data('moreMenu', () => ({
    open: false,
    toggle() {
        this.open = !this.open;
    },
    close() {
        this.open = false;
    },
}));

// <x-more-details>: expandable "More details" section in forms.
// Starts open when the server marks it (e.g. a hidden field has a validation error).
Alpine.data('moreDetails', () => ({
    open: false,
    init() {
        this.open = this.$el.dataset.open === 'true';
    },
    toggle() {
        this.open = !this.open;
    },
    get closed() {
        return !this.open;
    },
}));

// <x-table>: clicking anywhere on a row opens the item (the row's first link
// remains the accessible, keyboard-reachable way to open it).
Alpine.data('clickableRows', () => ({
    go(event) {
        if (event.target.closest('a, button, input, select, textarea, label, summary')) {
            return;
        }
        const row = event.target.closest('tr[data-href]');
        if (!row) {
            return;
        }
        if (event.ctrlKey || event.metaKey) {
            window.open(row.dataset.href, '_blank', 'noopener');
        } else {
            window.location.assign(row.dataset.href);
        }
    },
}));

// Two-factor challenge: switch between authenticator code and recovery code.
Alpine.data('twoFactorChallenge', () => ({
    useRecovery: false,
    toggleMode() {
        this.useRecovery = !this.useRecovery;
    },
    get usingCode() {
        return !this.useRecovery;
    },
}));

// Loading state + double-submit protection for every form.
// <x-button type="submit" loading="Saving…"> renders data-loading-text.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented) {
        return;
    }
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = 'true';

    const submitter = event.submitter;
    // Defer so the clicked button's name/value is still included in the request.
    setTimeout(() => {
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
        });
        if (submitter && submitter.dataset.loadingText) {
            submitter.dataset.originalText = submitter.textContent;
            submitter.textContent = submitter.dataset.loadingText;
        }
    }, 0);
});

// Restore buttons if the page is shown again from the back/forward cache.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }
    document.querySelectorAll('form[data-submitting="true"]').forEach((form) => {
        delete form.dataset.submitting;
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = false;
            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        });
    });
});

window.Alpine = Alpine;
Alpine.start();
