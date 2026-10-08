// CSP-safe controls for the project-report page.
(() => {
    'use strict';

    document.addEventListener('click', event => {
        const control = event.target instanceof Element
            ? event.target.closest('[data-report-action]')
            : null;
        if (!control) return;

        event.preventDefault();
        if (control.dataset.reportAction === 'print') {
            window.print();
        } else if (control.dataset.reportAction === 'close') {
            // A report opened in a new tab can be closed. If the browser blocks
            // it, leave the report intact rather than navigating unexpectedly.
            window.close();
        }
    });
})();
