(() => {
    'use strict';

    const stateKey = Symbol.for('liquidstack.blog.admin-list');
    const previous = window[stateKey];
    if (previous && typeof previous.dispose === 'function') {
        previous.dispose();
    }

    const controller = new AbortController();
    const selector = '[data-blog-trash-form]';

    document.addEventListener(
        'submit',
        (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches(selector)) {
                return;
            }

            const title = form.dataset.blogTitle || 'este borrador';
            const confirmed = window.confirm(
                `¿Mover “${title}” a la papelera? Podrás recuperarlo después.`
            );
            if (!confirmed) {
                event.preventDefault();
            }
        },
        { signal: controller.signal }
    );

    window[stateKey] = {
        dispose() {
            controller.abort();
            delete window[stateKey];
        },
    };
})();
