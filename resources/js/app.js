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

// Header search: "/" focuses it (unless you are typing in a field), Escape leaves it.
// Enter submits to the Leads list; live results come with the leads list (step 3).
Alpine.data('globalSearch', () => ({
    shortcut(event) {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        const active = document.activeElement;
        const typing = active && (active.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName));
        if (typing) {
            return;
        }
        event.preventDefault();
        this.$refs.input.focus();
    },
    blur() {
        this.$refs.input.blur();
    },
}));

// <x-filter-bar>: a GET form that submits as soon as a filter changes.
Alpine.data('autoSubmit', () => ({
    submit(event) {
        if (event.target.matches('select, input[type="date"], input[type="checkbox"], input[type="radio"]')) {
            this.$el.requestSubmit();
        }
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

// <x-dropdown> (and <x-more-menu>, which uses it).
Alpine.data('dropdown', () => ({
    open: false,
    toggle() {
        this.open = !this.open;
    },
    close() {
        this.open = false;
    },
}));

// <x-modal>: native <dialog>. Opened by a `modalTrigger` button (data-modal="name") or on load
// when the server marks it open (e.g. after a validation error inside it).
Alpine.data('modal', () => ({
    init() {
        if (this.$el.dataset.open === 'true') {
            this.$nextTick(() => this.$refs.dialog.showModal());
        }
    },
    handleOpen(event) {
        if (event.detail === this.$el.dataset.name) {
            this.$refs.dialog.showModal();
        }
    },
    close() {
        this.$refs.dialog.close();
    },
}));

Alpine.data('modalTrigger', () => ({
    open() {
        this.$dispatch('open-modal', this.$el.dataset.modal);
    },
}));

// <x-slide-over>: opens on load (it has its own URL); closing navigates to data-close-href.
Alpine.data('slideOver', () => ({
    init() {
        this.$nextTick(() => this.$refs.dialog.showModal());
    },
    close() {
        window.location.assign(this.$el.dataset.closeHref);
    },
    cancel(event) {
        event.preventDefault();
        this.close();
    },
    backdropClick(event) {
        // A click on the <dialog> element itself (not its content) is a click on the backdrop.
        if (event.target === this.$refs.dialog) {
            this.close();
        }
    },
}));

// <x-tabs> with in-page panels. tabButton/tabPanel are nested scopes reading the parent's `active`.
Alpine.data('tabs', () => ({
    active: '',
    init() {
        this.active = this.$el.dataset.active;
    },
}));

Alpine.data('tabButton', () => ({
    select() {
        this.active = this.$el.dataset.tab;
    },
    get selected() {
        return this.active === this.$el.dataset.tab;
    },
    get stateClasses() {
        return { [this.$el.dataset.on]: this.selected, [this.$el.dataset.off]: !this.selected };
    },
}));

Alpine.data('tabPanel', () => ({
    get visible() {
        return this.active === this.$el.dataset.tab;
    },
}));

// <x-input reveal>: show/hide password.
Alpine.data('passwordReveal', () => ({
    shown: false,
    toggle() {
        this.shown = !this.shown;
    },
    get hidden() {
        return !this.shown;
    },
    get inputType() {
        return this.shown ? 'text' : 'password';
    },
    get label() {
        return this.shown ? 'Hide password' : 'Show password';
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
