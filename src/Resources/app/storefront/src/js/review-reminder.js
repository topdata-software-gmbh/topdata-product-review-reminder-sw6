/**
 * Opens the review tab when a customer arrives from a review reminder mail.
 *
 * The mail links to `#review-form`, which is the anchor core's review
 * component renders. On its own that fragment cannot work: the element is a
 * Bootstrap `.collapse` without `show`, and the review tab is only `.active`
 * after core redirects back from the save route with `success`. So the native
 * fragment jump scrolls to a zero-height hidden box and the customer sees
 * nothing happen.
 *
 * `?success=1` is core's own way into the review tab, but it also renders
 * "thank you for your review" — a lie on an invitation that has not been
 * written yet. Hence the fragment plus this script.
 *
 * Imported by `src/main.js`; the Shopware storefront webpack build bundles it
 * to `dist/storefront/js/<plugin>/<plugin>.js`, which is where Shopware loads
 * plugin storefront JS from.
 */

(function () {
    'use strict';

    var REVIEW_ANCHOR = '#review-form';
    var HEADER_OFFSET = 80;

    /**
     * Bootstrap 5 tabs are class-driven, so switching them is a class swap.
     * Both the nav link and the pane are marked active; `show` is already on
     * every pane server-side and only `active` decides visibility.
     *
     * Scoped to the review tab's own group on purpose: the cross-selling
     * widget is a second, independent tab group on the same page and has its
     * own active pane, which a document-wide selector would close.
     */
    function activateReviewTab() {
        var tab = document.querySelector('.review-tab');

        if (!tab) {
            return false;
        }

        var paneId = tab.getAttribute('aria-controls');
        var pane = paneId ? document.getElementById(paneId) : null;

        if (!pane) {
            return false;
        }

        var navList = tab.closest('.nav-tabs');
        var content = pane.closest('.tab-content');

        if (navList) {
            navList.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (link) {
                link.classList.remove('active');
                link.setAttribute('aria-selected', 'false');
            });
        }

        if (content) {
            Array.prototype.forEach.call(content.children, function (child) {
                if (child.classList.contains('tab-pane')) {
                    child.classList.remove('active');
                }
            });
        }

        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');
        pane.classList.add('active');

        return true;
    }

    /**
     * Core renders the review list expanded and the form collapsed; both share
     * the `.multi-collapse` class, which is how one Bootstrap collapse toggles
     * the pair. Adding `show` to the form and removing it from the list is
     * what the "write a review" teaser does on click.
     */
    function expandReviewForm() {
        var form = document.getElementById('review-form');

        if (!form) {
            return null;
        }

        var list = document.getElementById('review-list');

        if (list) {
            list.classList.remove('show');
        }

        form.classList.add('show');

        return form;
    }

    function openReview() {
        if (window.location.hash !== REVIEW_ANCHOR) {
            return;
        }

        if (!activateReviewTab()) {
            return;
        }

        var form = expandReviewForm();

        if (form) {
            form.style.scrollMarginTop = HEADER_OFFSET + 'px';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openReview);
    } else {
        openReview();
    }
})();
