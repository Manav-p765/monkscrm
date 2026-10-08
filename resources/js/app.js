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

// GET a server-rendered HTML fragment (never JSON). Same-origin, session cookie, no caching.
async function fetchFragment(url) {
    const response = await fetch(url, {
        headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' },
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!response.ok) {
        throw new Error(String(response.status));
    }
    return response.text();
}

// Header search: "/" focuses it (unless you are typing in a field), Escape leaves it. Typing 2+
// characters shows up to 8 matching leads (fragment from /leads/live-search); Enter opens the full list.
Alpine.data('globalSearch', () => ({
    open: false,
    async lookup() {
        const term = this.$refs.input.value.trim();
        if (term.length < 2) {
            this.open = false;
            return;
        }
        try {
            const html = await fetchFragment(this.$el.dataset.liveUrl + '?q=' + encodeURIComponent(term));
            if (this.$refs.input.value.trim() === term) {
                this.$refs.results.innerHTML = html;
                this.open = true;
            }
        } catch {
            this.open = false;
        }
    },
    reopen() {
        if (this.$refs.input.value.trim().length >= 2 && this.$refs.results.innerHTML !== '') {
            this.open = true;
        }
    },
    close() {
        this.open = false;
    },
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
        this.open = false;
        this.$refs.input.blur();
    },
}));

// Lead form: warns about a possible duplicate (email/phone) while typing. The server decides what
// may be shown: details only for a lead the user can see.
Alpine.data('duplicateCheck', () => ({
    async check() {
        const form = this.$el.closest('form') || this.$el;
        const email = form.querySelector('[name="email"]').value.trim();
        const phone = form.querySelector('[name="phone"]').value.trim();
        if (email.length < 2 && phone.replace(/\D/g, '').length < 6) {
            this.$refs.warning.innerHTML = '';
            return;
        }
        const params = new URLSearchParams({ email, phone, ignore: form.dataset.ignore || '' });
        try {
            this.$refs.warning.innerHTML = await fetchFragment(form.dataset.url + '?' + params.toString());
        } catch {
            // The check is a convenience; saving re-checks on the server anyway.
        }
    },
}));

// Pipeline (Kanban): native HTML5 drag-and-drop. A drop moves the card at once, then asks the server
// (PATCH /leads/{ref}/status, LeadStatusChanger); if the server refuses, the card goes back.
// Each card also has a keyboard-accessible "Move to…" menu (plain forms).
Alpine.data('kanban', () => ({
    dragged: null,
    origin: null,
    next: null,
    overColumn: null,
    message: '',
    ok: true,
    start(event) {
        this.dragged = event.currentTarget;
        this.origin = this.dragged.parentNode;
        this.next = this.dragged.nextSibling;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.dragged.dataset.ref);
        requestAnimationFrame(() => this.dragged && this.dragged.classList.add('opacity-40'));
    },
    end() {
        if (this.dragged) {
            this.dragged.classList.remove('opacity-40');
        }
        this.clearOver();
        this.dragged = null;
    },
    over(event) {
        event.preventDefault();
        const column = event.currentTarget;
        if (this.overColumn !== column) {
            this.clearOver();
            this.overColumn = column;
            column.classList.add('ring-2', 'ring-indigo-400/30', 'border-indigo-400', 'bg-indigo-50/70');
        }
    },
    clearOver() {
        if (this.overColumn) {
            this.overColumn.classList.remove('ring-2', 'ring-indigo-400/30', 'border-indigo-400', 'bg-indigo-50/70');
            this.overColumn = null;
        }
    },
    async drop(event) {
        event.preventDefault();
        const column = event.currentTarget;
        const card = this.dragged;
        this.clearOver();
        if (!card || column.dataset.status === card.dataset.status) {
            return;
        }
        const from = this.origin;
        const next = this.next;
        const fromColumn = from.closest('[data-status]');
        column.querySelector('[data-cards]').prepend(card);
        this.adjust(fromColumn, -1);
        this.adjust(column, 1);

        const body = new URLSearchParams({
            _method: 'PATCH',
            _token: document.querySelector('meta[name="csrf-token"]').content,
            lead_status_id: column.dataset.status,
            via: 'kanban',
        });
        try {
            const response = await fetch(card.dataset.url, {
                method: 'POST',
                headers: { Accept: 'text/plain', 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
                body,
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            card.dataset.status = column.dataset.status;
            this.toast(true, 'Moved ' + card.dataset.name + ' to ' + column.dataset.name);
        } catch {
            from.insertBefore(card, next);
            this.adjust(fromColumn, 1);
            this.adjust(column, -1);
            this.toast(false, 'Could not move ' + card.dataset.name + '. It may have changed or you may not have access. Refresh and try again.');
        }
    },
    adjust(column, delta) {
        const badge = column.querySelector('[data-count]');
        badge.textContent = String(Math.max(0, parseInt(badge.textContent, 10) + delta));
    },
    toast(ok, message) {
        this.ok = ok;
        this.message = message;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => (this.message = ''), ok ? 3000 : 6000);
    },
    get showToast() {
        return this.message !== '';
    },
    get toastClasses() {
        return this.ok
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
            : 'border-rose-200 bg-rose-50 text-rose-700';
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
        if (this.$el.dataset.closeHref) {
            window.location.assign(this.$el.dataset.closeHref);
            return;
        }
        this.$refs.dialog.close();
    },
    cancel(event) {
        // Escape: same as the × button (so a modal with its own URL goes back to the list).
        if (this.$el.dataset.closeHref) {
            event.preventDefault();
            this.close();
        }
    },
}));

// Copy button (credentials card): copies data-text to the clipboard and shows "Copied".
Alpine.data('copyText', () => ({
    copied: false,
    copy() {
        navigator.clipboard.writeText(this.$el.dataset.text).then(() => {
            this.copied = true;
            setTimeout(() => (this.copied = false), 2000);
        });
    },
    get notCopied() {
        return !this.copied;
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

// Drag-and-drop reordering (Settings statuses/sources). Rows have draggable="true" and data-id;
// dropping posts the full order to data-reorder-url. Move up/down buttons are the keyboard way.
Alpine.data('sortable', () => ({
    dragged: null,
    start(event) {
        this.dragged = event.currentTarget;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.dragged.dataset.id);
        this.dragged.classList.add('opacity-40');
    },
    over(event) {
        event.preventDefault();
        const target = event.currentTarget;
        if (!this.dragged || target === this.dragged) {
            return;
        }
        const rect = target.getBoundingClientRect();
        const after = event.clientY > rect.top + rect.height / 2;
        target.parentNode.insertBefore(this.dragged, after ? target.nextSibling : target);
    },
    drop(event) {
        event.preventDefault();
    },
    end() {
        if (!this.dragged) {
            return;
        }
        this.dragged.classList.remove('opacity-40');
        this.dragged = null;
        this.save();
    },
    save() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = this.$el.dataset.reorderUrl;
        const token = document.querySelector('meta[name="csrf-token"]').content;
        form.append(Object.assign(document.createElement('input'), { type: 'hidden', name: '_token', value: token }));
        this.$el.querySelectorAll('[data-id]').forEach((row) => {
            form.append(Object.assign(document.createElement('input'), { type: 'hidden', name: 'ids[]', value: row.dataset.id }));
        });
        document.body.append(form);
        form.submit();
    },
}));

// Lead list selection: row checkboxes (name="ids[]", form="bulk-form") drive the bulk action bar.
Alpine.data('bulkSelect', () => ({
    count: 0,
    total: 0,
    init() {
        this.update();
    },
    boxes() {
        return Array.from(this.$root.querySelectorAll('input[name="ids[]"]'));
    },
    update() {
        const boxes = this.boxes();
        this.total = boxes.length;
        this.count = boxes.filter((b) => b.checked).length;
    },
    toggleAll(event) {
        this.boxes().forEach((b) => (b.checked = event.target.checked));
        this.update();
    },
    clear() {
        this.boxes().forEach((b) => (b.checked = false));
        this.update();
    },
    get any() {
        return this.count > 0;
    },
    get allChecked() {
        return this.total > 0 && this.count === this.total;
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
