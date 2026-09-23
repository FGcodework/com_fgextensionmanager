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
 */
(function () {
	'use strict';

	function fgemFilterRows(query) {
		query = query.toLowerCase().trim();
		var rows = document.querySelectorAll('#fgem-table [role="row"][data-fgem-search]');
		rows.forEach(function (row) {
			var matches = row.getAttribute('data-fgem-search').indexOf(query) !== -1;
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
		var filterInput = document.getElementById('fgem-filter');

		if (filterInput) {
			filterInput.addEventListener('input', function () {
				fgemFilterRows(filterInput.value);
			});
		}

		var options = window.Joomla && Joomla.getOptions ? Joomla.getOptions('com_fgextensionmanager.extensions', {}) : {};

		fgemInterceptToolbarButton('icon-loop', function () {
			var msg = options.updateAllConfirm || '';

			if (confirm(msg)) {
				fgemSubmitTask('extensions.updateAll');
			}
		});

		fgemInterceptToolbarButton('icon-refresh', function () {
			fgemSubmitTask('extensions.refresh');
		});
	});
})();
