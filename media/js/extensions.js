/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Loaded via WebAssetManager (see HtmlView::display()) instead of an inline
 * <script> block - CSP-friendly, since a Content-Security-Policy without
 * 'unsafe-inline' blocks both inline <script> tags and inline event-handler
 * attributes (the oninput="..." this replaced), but not an externally
 * loaded, same-origin file like this one.
 *
 * Loaded as an ES module (see HtmlView::display()'s ['type' => 'module']) so
 * this can import joomla.dialog - an ES module has its own scope already,
 * so the IIFE wrapper an earlier, non-module version of this file used for
 * that purpose isn't needed any more.
 */
import JoomlaDialog from 'joomla.dialog';

var fgemActiveState = '';

var fgemOptions = {};

function fgemApplyFilters() {
	var query = (document.getElementById('fgem-filter') || {}).value || '';
	query = query.toLowerCase().trim();

	var rows  = document.querySelectorAll('#fgem-table [role="row"][data-fgem-search]');
	var shown = 0;

	rows.forEach(function (row) {
		var textMatches  = row.getAttribute('data-fgem-search').indexOf(query) !== -1;
		var stateMatches = fgemActiveState === '' || row.getAttribute('data-fgem-state') === fgemActiveState;
		var matches      = textMatches && stateMatches;

		if (matches) {
			shown++;
		}

		// The row wrapper is display:contents by default (see the CSS) so its
		// cells become direct grid items - clearing to '' here would fall
		// back to the element's normal default (block), breaking the grid
		// column alignment, so 'contents' has to be explicit (1.21.3's lesson,
		// re-applied to the CSS Grid version of this row in 1.22.0).
		row.style.display = matches ? 'contents' : 'none';

		var next = row.nextElementSibling;
		if (next && next.classList.contains('fgem-detail-row')) {
			next.style.display = matches ? 'contents' : 'none';
		}
	});

	// "Nothing matches" message (with a way out) instead of a blank area.
	var noResults = document.getElementById('fgem-no-results');

	if (noResults) {
		noResults.hidden = shown !== 0;
	}

	// Screen readers: announce the result count as the filter changes.
	var status = document.getElementById('fgem-filter-status');

	if (status && fgemOptions.shownText) {
		status.textContent = fgemOptions.shownText
			.replace('%1$d', shown)
			.replace('%2$d', rows.length);
	}
}

function fgemShowBusy() {
	if (document.getElementById('fgem-busy')) {
		return;
	}

	// Full-page overlay: shows that something is running AND swallows further
	// clicks, so a slow install/update can't be triggered twice by a second
	// click on the same (or another) button.
	var overlay = document.createElement('div');
	overlay.id = 'fgem-busy';
	overlay.setAttribute('role', 'alert');
	overlay.innerHTML = '<div class="fgem-busy-box"><span class="spinner-border" aria-hidden="true"></span><span class="fgem-busy-text"></span></div>';
	overlay.querySelector('.fgem-busy-text').textContent = fgemOptions.busyText || '';
	document.body.appendChild(overlay);
}

// "Update All" and "Refresh" (toolbar buttons) need to set the task and
// submit the form. Joomla's own Joomla.submitform() does this via
// `form.task.value = task`, but our form ALSO has several per-row
// <button name="task" value="..."> elements (Install/Update/Uninstall,
// intentionally - each contributes its own value only when directly
// clicked as the submit control). With multiple elements sharing the
// name "task", `form.task` resolves to a RadioNodeList instead of a
// single element, and RadioNodeList.value's setter is only meaningful
// for radio inputs - setting it here is a silent no-op, so the hidden
// task field never actually gets the task value. Bypassing that
// entirely: submit directly via our hidden field's unique id instead of
// the ambiguous form.task reference.
function fgemSubmitTask(task) {
	var form      = document.getElementById('adminForm');
	var taskField = document.getElementById('fgem-task-field');

	if (!form || !taskField) {
		return;
	}

	// Disabled by default so it never collides with the per-row
	// <button name="task" value="..."> elements (disabled form fields
	// are excluded from submission entirely) - only enabled right here,
	// for this one programmatic toolbar submit.
	taskField.disabled = false;
	taskField.value    = task;
	fgemShowBusy();
	form.submit();
}

// Deliberately NOT overriding the global Joomla.submitbutton (an
// earlier version of this did): that function is shared by every
// <joomla-toolbar-button> on the page, not scoped to this component, so
// if anything else ever also overrides it, only one of the two
// overrides survives - whichever assigns last. Attaching
// capturing-phase click listeners directly to our own two buttons
// instead intercepts the click before the component's own internal
// handler runs, without touching anything global at all.
function fgemInterceptToolbarButton(iconClass, onIntercepted) {
	var icon = document.querySelector('#toolbar .' + iconClass);

	if (!icon) {
		return;
	}

	var button = icon.closest('a, button, joomla-toolbar-button');

	if (!button) {
		return;
	}

	button.addEventListener('click', function (event) {
		event.preventDefault();
		event.stopPropagation();
		onIntercepted();
	}, true); // capture phase - runs before the component's own handler
}

document.addEventListener('DOMContentLoaded', function () {
	// Logos: assets/logo.webp first, then assets/logo.png, otherwise the
	// letter tile stays visible (CSS). Done with listeners instead of inline
	// onerror= so it also works under a strict CSP.
	document.querySelectorAll('.fgem-logo img').forEach(function (img) {
		var tile = img.parentNode;

		var markOk = function () {
			tile.classList.add('fgem-logo-ok');
		};
		var retried = false;
		var fail = function () {
			// One delayed retry of the same URL first: a burst of ~12 parallel
			// image requests to GitHub can get a few of them throttled (429)
			// - those would otherwise stay on the letter tile until reload.
			if (!retried) {
				retried = true;
				var current = img.src;

				window.setTimeout(function () {
					img.src = current + (current.indexOf('?') === -1 ? '?' : '&') + 'r=1';
				}, 1500);

				return;
			}

			var fallback = img.getAttribute('data-fgem-fallback');

			if (fallback) {
				img.removeAttribute('data-fgem-fallback');
				img.src = fallback;
			}
		};

		img.addEventListener('load', function () {
			if (img.naturalWidth > 0) {
				markOk();
			} else {
				fail();
			}
		});
		img.addEventListener('error', fail);

		// Image may have finished (or failed) before this listener existed.
		if (img.complete) {
			if (img.naturalWidth > 0) {
				markOk();
			} else {
				fail();
			}
		}
	});

	fgemOptions = window.Joomla && Joomla.getOptions ? Joomla.getOptions('com_fgextensionmanager.extensions', {}) : {};

	var filterInput = document.getElementById('fgem-filter');

	if (filterInput) {
		filterInput.addEventListener('input', fgemApplyFilters);

		// Enter in the search box would submit the whole form (no task) and
		// reload the page - it's only a live filter, so swallow it.
		filterInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
			}
		});
	}

	var clearButton = document.getElementById('fgem-filter-clear');

	if (clearButton) {
		clearButton.addEventListener('click', function () {
			if (filterInput) {
				filterInput.value = '';
			}

			var all = document.querySelector('#fgem-state-filters [data-fgem-state-filter=""]');

			if (all) {
				all.click();
			} else {
				fgemActiveState = '';
				fgemApplyFilters();
			}
		});
	}

	var stateFilters = document.getElementById('fgem-state-filters');

	if (stateFilters) {
		stateFilters.querySelectorAll('[data-fgem-state-filter]').forEach(function (chip) {
			chip.addEventListener('click', function () {
				stateFilters.querySelectorAll('[data-fgem-state-filter]').forEach(function (other) {
					other.classList.remove('active');
					other.setAttribute('aria-pressed', 'false');
				});
				chip.classList.add('active');
				chip.setAttribute('aria-pressed', 'true');
				fgemActiveState = chip.getAttribute('data-fgem-state-filter');
				fgemApplyFilters();
			});
		});
	}

	var options = fgemOptions;

	fgemInterceptToolbarButton('icon-loop', function () {
		var msg = options.updateAllConfirm || '';

		// JoomlaDialog.confirm() instead of the browser's native confirm() -
		// styled consistently with the rest of the admin UI (a proper modal
		// dialog, not a native OS dialog box docked at the top of the
		// viewport). Returns a Promise instead of blocking synchronously.
		JoomlaDialog.confirm(msg).then(function (result) {
			if (result) {
				fgemSubmitTask('extensions.updateAll');
			}
		});
	});

	fgemInterceptToolbarButton('icon-refresh', function () {
		fgemSubmitTask('extensions.refresh');
	});

	// Per-row confirmations (Uninstall): a data attribute + this one listener
	// instead of an inline onclick="return confirm(...)" - same Joomla dialog
	// as Update All, and no inline handler for a strict CSP to block.
	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-fgem-confirm]') : null;

		if (!button) {
			return;
		}

		if (button.getAttribute('data-fgem-confirmed') === '1') {
			button.removeAttribute('data-fgem-confirmed');

			return;
		}

		event.preventDefault();
		event.stopPropagation();

		JoomlaDialog.confirm(button.getAttribute('data-fgem-confirm') || '').then(function (result) {
			if (!result || !button.form) {
				return;
			}

			if (button.form.requestSubmit) {
				// Submits WITH this button as the submitter, so its name/value
				// and formaction are used exactly as in a normal click.
				button.form.requestSubmit(button);
			} else {
				button.setAttribute('data-fgem-confirmed', '1');
				button.click();
			}
		});
	}, true);

	// Any submit of the list form (Install / Update / Uninstall / toggle /
	// toolbar actions) shows the busy overlay.
	var adminForm = document.getElementById('adminForm');

	if (adminForm) {
		adminForm.addEventListener('submit', fgemShowBusy);
	}

	// Back/forward cache: coming back to this page must not show a stale overlay.
	window.addEventListener('pageshow', function (event) {
		var overlay = document.getElementById('fgem-busy');

		if (event.persisted && overlay) {
			overlay.remove();
		}
	});
});
