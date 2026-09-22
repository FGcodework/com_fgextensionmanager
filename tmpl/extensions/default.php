<?php
/**
 * @package     FG.Administrator
 * @subpackage  com_fgextensionmanager
 *
 * @copyright   Copyright (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \FG\Component\FgExtensionManager\Administrator\View\Extensions\HtmlView $this */

$canManage = $this->canDoActions->get('core.manage') || $this->canDoActions->get('core.admin');
$canDelete = (bool) $this->canDoActions->get('core.admin');

$stateMeta = [
	'update_available' => ['badge' => 'bg-warning text-dark', 'label' => 'COM_FGEXTENSIONMANAGER_STATE_UPDATE_AVAILABLE'],
	'not_installed'     => ['badge' => 'bg-secondary', 'label' => 'COM_FGEXTENSIONMANAGER_STATE_NOT_INSTALLED'],
	'installed'         => ['badge' => 'bg-success', 'label' => 'COM_FGEXTENSIONMANAGER_STATE_INSTALLED'],
	'incompatible'      => ['badge' => 'bg-dark', 'label' => 'COM_FGEXTENSIONMANAGER_STATE_INCOMPATIBLE'],
	'error'             => ['badge' => 'bg-danger', 'label' => 'COM_FGEXTENSIONMANAGER_STATE_ERROR'],
];

$selfItem = null;

foreach ($this->items as $key => $item)
{
	if ($item->is_self)
	{
		$selfItem = $item;
		unset($this->items[$key]);
		break;
	}
}

$this->items = array_values($this->items);

$order = ['update_available', 'installed', 'not_installed', 'incompatible', 'error'];
usort($this->items, function ($a, $b) use ($order) {
	return array_search($a->state, $order) <=> array_search($b->state, $order);
});

?>
<form action="<?php echo Route::_('index.php?option=com_fgextensionmanager&view=extensions'); ?>" method="post" name="adminForm" id="adminForm">

	<?php if ($this->stale) : ?>
		<div class="alert alert-warning">
			<?php
			$when = $this->staleSince ? Factory::getDate($this->staleSince)->format('d.m.Y H:i') : '?';
			echo Text::sprintf('COM_FGEXTENSIONMANAGER_STALE_DATA_WARNING', $when);
			?>
		</div>
	<?php endif; ?>

	<?php if ($selfItem !== null) : ?>
		<div class="card mb-4" style="border-left: 4px solid #FF6B4A;">
			<div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
				<div>
					<strong class="text-primary"><?php echo htmlspecialchars($selfItem->name ?? $selfItem->label, ENT_QUOTES, 'UTF-8'); ?></strong>
					<span class="badge bg-secondary"><?php echo Text::_('COM_FGEXTENSIONMANAGER_THIS_EXTENSION'); ?></span>
					<?php if (!empty($selfItem->technical_id)) : ?>
						<div class="small"><code><?php echo htmlspecialchars($selfItem->technical_id, ENT_QUOTES, 'UTF-8'); ?></code></div>
					<?php endif; ?>
					<div class="small text-muted mt-1">
						<?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_INSTALLED_VERSION'); ?>:
						<strong><?php echo $selfItem->installed_version ? htmlspecialchars($selfItem->installed_version, ENT_QUOTES, 'UTF-8') : '&#8212;'; ?></strong>
						<?php if ($selfItem->state === 'update_available') : ?>
							&rarr; <strong class="text-warning-emphasis"><?php echo htmlspecialchars($selfItem->available_version, ENT_QUOTES, 'UTF-8'); ?></strong>
						<?php endif; ?>
					</div>
				</div>
				<div class="d-flex align-items-center gap-2">
					<?php $selfMeta = $stateMeta[$selfItem->state] ?? $stateMeta['error']; ?>
					<span class="badge <?php echo $selfMeta['badge']; ?>"><?php echo Text::_($selfMeta['label']); ?></span>

					<?php if ($canManage && $selfItem->state === 'update_available') : ?>
						<button type="submit" name="task" value="extensions.update" formaction="<?php echo Route::_('index.php?option=com_fgextensionmanager&task=extensions.update&key=' . urlencode($selfItem->key)); ?>" class="btn btn-warning btn-sm">
							<?php echo Text::_('COM_FGEXTENSIONMANAGER_BUTTON_UPDATE'); ?>
						</button>
					<?php endif; ?>

					<?php if (!empty($selfItem->changelog_preview)) : ?>
						<button
							type="button"
							class="btn btn-link btn-sm"
							data-bs-toggle="collapse"
							data-bs-target="#fgem-changelog-self"
							aria-expanded="false"
							aria-controls="fgem-changelog-self"
						>
							<?php echo Text::_('COM_FGEXTENSIONMANAGER_LINK_CHANGELOG'); ?>
						</button>
					<?php elseif (!empty($selfItem->changelog_url)) : ?>
						<a href="<?php echo htmlspecialchars($selfItem->changelog_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-link btn-sm">
							<?php echo Text::_('COM_FGEXTENSIONMANAGER_LINK_CHANGELOG'); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
			<?php if (!empty($selfItem->changelog_preview)) : ?>
				<div class="collapse" id="fgem-changelog-self">
					<div class="card-body border-top small" style="white-space: pre-wrap;">
						<?php echo htmlspecialchars($selfItem->changelog_preview, ENT_QUOTES, 'UTF-8'); ?>
						<div class="mt-2">
							<a href="<?php echo htmlspecialchars($selfItem->changelog_full_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo Text::_('COM_FGEXTENSIONMANAGER_LINK_FULL_CHANGELOG'); ?>
							</a>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="d-flex align-items-center mb-3 flex-wrap gap-2">
		<?php if (!empty($this->items)) : ?>
		<input
			type="search"
			id="fgem-filter"
			class="form-control form-control-sm"
			style="max-width: 320px;"
			placeholder="<?php echo Text::_('COM_FGEXTENSIONMANAGER_FILTER_PLACEHOLDER'); ?>"
			oninput="fgemFilterRows(this.value)"
		>
		<?php endif; ?>
		<p class="mb-0 text-muted ms-auto">
			<?php echo Text::sprintf('COM_FGEXTENSIONMANAGER_EXTENSIONS_COUNT', count($this->items)); ?>
			<?php if (!empty($this->lastChecked)) : ?>
				&#183;
				<?php echo Text::sprintf('COM_FGEXTENSIONMANAGER_LAST_CHECKED', Factory::getDate($this->lastChecked)->format('d.m.Y H:i')); ?>
			<?php endif; ?>
		</p>
	</div>

	<?php if (empty($this->items)) : ?>
		<div class="alert alert-info">
			<?php echo Text::_('COM_FGEXTENSIONMANAGER_NO_REPOS_CONFIGURED'); ?>
		</div>
	<?php else : ?>

	<style>
		/*
		 * Below the breakpoint, the table becomes a stack of labelled cards
		 * instead of a horizontally-scrolling table - same general technique
		 * as his own plg_system_fgresponsivetables, built independently here
		 * so this table works regardless of what other plugins are active.
		 * Scoped entirely to #fgem-table so nothing else on the page is
		 * affected. The detail (changelog) row is excluded - it's already
		 * forced full-width block unconditionally (see fgem-detail-row).
		 */
		@media (max-width: 767.98px) {
			#fgem-table, #fgem-table thead, #fgem-table tbody, #fgem-table th, #fgem-table td, #fgem-table tr {
				display: block;
			}
			#fgem-table thead tr {
				position: absolute;
				width: 1px;
				height: 1px;
				overflow: hidden;
				clip: rect(0, 0, 0, 0);
				white-space: nowrap;
			}
			#fgem-table tbody tr:not(.fgem-detail-row) {
				border: 1px solid var(--bs-border-color, #dee2e6);
				border-radius: 6px;
				margin-bottom: 12px;
				padding: 10px 12px;
			}
			#fgem-table tbody tr:not(.fgem-detail-row) td {
				border: none;
				padding: 4px 0 4px 42%;
				position: relative;
				text-align: left !important;
			}
			#fgem-table tbody tr:not(.fgem-detail-row) td[data-label]::before {
				content: attr(data-label) ":";
				position: absolute;
				left: 0;
				width: 38%;
				font-weight: bold;
				white-space: normal;
			}
			#fgem-table tbody tr:not(.fgem-detail-row) td:first-child {
				padding-left: 0;
			}
		}
	</style>

	<div class="table-responsive">
	<table class="table" id="fgem-table" style="table-layout: fixed; width: 100%;">
		<thead>
			<tr>
				<th scope="col" style="width: 40%;"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_NAME'); ?></th>
				<th scope="col" style="width: 9%;"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_STATE'); ?></th>
				<th scope="col" style="width: 9%;"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_ENABLED'); ?></th>
				<th scope="col" style="width: 7%;"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_INSTALLED_VERSION'); ?></th>
				<th scope="col" style="width: 7%;"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_AVAILABLE_VERSION'); ?></th>
				<th scope="col" style="width: 16%;"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_REPO'); ?></th>
				<th scope="col" class="text-end" style="width: 12%;"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_ACTIONS'); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($this->items as $i => $item) :
			$meta = $stateMeta[$item->state] ?? $stateMeta['error'];
			$searchText = htmlspecialchars(
				mb_strtolower(($item->name ?? $item->label) . ' ' . ($item->technical_id ?? '') . ' ' . $item->owner_repo),
				ENT_QUOTES,
				'UTF-8'
			);
		?>
			<tr data-fgem-search="<?php echo $searchText; ?>"<?php echo $item->is_self ? ' style="background-color: rgba(255, 107, 74, 0.08);"' : ($i % 2 === 1 ? ' class="table-light"' : ''); ?>>
				<td>
					<strong class="text-primary"><?php echo htmlspecialchars($item->name ?? $item->label, ENT_QUOTES, 'UTF-8'); ?></strong>
					<?php if ($item->is_self) : ?>
						<span class="badge bg-primary"><?php echo Text::_('COM_FGEXTENSIONMANAGER_THIS_EXTENSION'); ?></span>
					<?php endif; ?>
					<?php if (!empty($item->technical_id)) : ?>
						<div class="small"><code><?php echo htmlspecialchars($item->technical_id, ENT_QUOTES, 'UTF-8'); ?></code></div>
					<?php endif; ?>
					<?php if (!empty($item->description)) : ?>
						<?php
						$description = $item->description;
						if (function_exists('mb_strlen') && mb_strlen($description) > 140)
						{
							$description = rtrim(mb_substr($description, 0, 140)) . '…';
						}
						elseif (strlen($description) > 140)
						{
							$description = rtrim(substr($description, 0, 140)) . '…';
						}
						?>
						<div class="small text-muted"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>
				</td>
				<td data-label="<?php echo htmlspecialchars(Text::_('COM_FGEXTENSIONMANAGER_COL_STATE'), ENT_QUOTES, 'UTF-8'); ?>">
					<span class="badge <?php echo $meta['badge']; ?>"><?php echo Text::_($meta['label']); ?></span>
					<?php if (in_array($item->state, ['error', 'incompatible'], true) && !empty($item->error)) : ?>
						<div class="small mt-1 <?php echo $item->state === 'error' ? 'text-danger' : 'text-muted'; ?>"><?php echo htmlspecialchars($item->error, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>
				</td>
				<td data-label="<?php echo htmlspecialchars(Text::_('COM_FGEXTENSIONMANAGER_COL_ENABLED'), ENT_QUOTES, 'UTF-8'); ?>">
					<?php if (!in_array($item->state, ['installed', 'update_available'], true)) : ?>
						&#8212;
					<?php elseif ($item->protected) : ?>
						<span class="badge bg-secondary" title="<?php echo Text::_('COM_FGEXTENSIONMANAGER_PROTECTED_HINT'); ?>">
							<span class="icon-lock" aria-hidden="true"></span>
							<?php echo Text::_($item->enabled ? 'COM_FGEXTENSIONMANAGER_STATE_ENABLED' : 'COM_FGEXTENSIONMANAGER_STATE_DISABLED'); ?>
						</span>
					<?php elseif ($canManage) : ?>
						<button
							type="submit"
							name="task"
							value="extensions.toggle"
							formaction="<?php echo Route::_('index.php?option=com_fgextensionmanager&task=extensions.toggle&key=' . urlencode($item->key)); ?>"
							class="btn btn-sm <?php echo $item->enabled ? 'btn-success' : 'btn-danger'; ?>"
							title="<?php echo Text::_($item->enabled ? 'COM_FGEXTENSIONMANAGER_ENABLED' : 'COM_FGEXTENSIONMANAGER_DISABLED'); ?>"
						>
							<?php echo Text::_($item->enabled ? 'COM_FGEXTENSIONMANAGER_STATE_ENABLED' : 'COM_FGEXTENSIONMANAGER_STATE_DISABLED'); ?>
						</button>
					<?php else : ?>
						<span class="badge <?php echo $item->enabled ? 'bg-success' : 'bg-danger'; ?>">
							<?php echo Text::_($item->enabled ? 'COM_FGEXTENSIONMANAGER_STATE_ENABLED' : 'COM_FGEXTENSIONMANAGER_STATE_DISABLED'); ?>
						</span>
					<?php endif; ?>
				</td>
				<td data-label="<?php echo htmlspecialchars(Text::_('COM_FGEXTENSIONMANAGER_COL_INSTALLED_VERSION'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $item->installed_version ? htmlspecialchars($item->installed_version, ENT_QUOTES, 'UTF-8') : '&#8212;'; ?></td>
				<td data-label="<?php echo htmlspecialchars(Text::_('COM_FGEXTENSIONMANAGER_COL_AVAILABLE_VERSION'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $item->available_version ? htmlspecialchars($item->available_version, ENT_QUOTES, 'UTF-8') : '&#8212;'; ?></td>
				<td data-label="<?php echo htmlspecialchars(Text::_('COM_FGEXTENSIONMANAGER_COL_REPO'), ENT_QUOTES, 'UTF-8'); ?>">
					<?php
					$slashPos    = strpos($item->owner_repo, '/');
					$repoDisplay = $slashPos !== false ? substr($item->owner_repo, $slashPos + 1) : $item->owner_repo;
					?>
					<a href="<?php echo htmlspecialchars($item->repo_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo htmlspecialchars($item->owner_repo, ENT_QUOTES, 'UTF-8'); ?>">
						<?php echo htmlspecialchars($repoDisplay, ENT_QUOTES, 'UTF-8'); ?>
					</a>
				</td>
				<td class="text-end" data-label="<?php echo htmlspecialchars(Text::_('COM_FGEXTENSIONMANAGER_COL_ACTIONS'), ENT_QUOTES, 'UTF-8'); ?>">
					<div>
						<?php if ($canManage && $item->state === 'not_installed') : ?>
							<button type="submit" name="task" value="extensions.install" formaction="<?php echo Route::_('index.php?option=com_fgextensionmanager&task=extensions.install&key=' . urlencode($item->key)); ?>" class="btn btn-success btn-sm">
								<?php echo Text::_('COM_FGEXTENSIONMANAGER_BUTTON_INSTALL'); ?>
							</button>
						<?php elseif ($canManage && $item->state === 'update_available') : ?>
							<button type="submit" name="task" value="extensions.update" formaction="<?php echo Route::_('index.php?option=com_fgextensionmanager&task=extensions.update&key=' . urlencode($item->key)); ?>" class="btn btn-warning btn-sm">
								<?php echo Text::_('COM_FGEXTENSIONMANAGER_BUTTON_UPDATE'); ?>
							</button>
						<?php endif; ?>

						<?php if ($canManage && !empty($item->manage_url)) : ?>
							<a href="<?php echo Route::_($item->manage_url); ?>" class="btn btn-dark btn-sm">
								<?php echo Text::_('COM_FGEXTENSIONMANAGER_BUTTON_SETTINGS'); ?>
							</a>
						<?php endif; ?>

						<?php if ($canDelete && !$item->is_self && in_array($item->state, ['installed', 'update_available'], true)) : ?>
							<button
								type="submit"
								name="task"
								value="extensions.uninstall"
								formaction="<?php echo Route::_('index.php?option=com_fgextensionmanager&task=extensions.uninstall&key=' . urlencode($item->key)); ?>"
								class="btn btn-danger btn-sm"
								onclick="return confirm(<?php echo htmlspecialchars(json_encode(Text::sprintf('COM_FGEXTENSIONMANAGER_UNINSTALL_CONFIRM', $item->name ?? $item->label)), ENT_QUOTES, 'UTF-8'); ?>);"
							>
								<?php echo Text::_('COM_FGEXTENSIONMANAGER_BUTTON_UNINSTALL'); ?>
							</button>
						<?php endif; ?>
					</div>

					<?php if (!empty($item->changelog_preview)) : ?>
						<button
							type="button"
							class="btn btn-link btn-sm p-0"
							data-bs-toggle="collapse"
							data-bs-target="#fgem-changelog-<?php echo (int) $i; ?>"
							aria-expanded="false"
							aria-controls="fgem-changelog-<?php echo (int) $i; ?>"
						>
							<?php echo Text::_('COM_FGEXTENSIONMANAGER_LINK_CHANGELOG'); ?>
						</button>
					<?php elseif (!empty($item->changelog_url)) : ?>
						<a
							href="<?php echo htmlspecialchars($item->changelog_url, ENT_QUOTES, 'UTF-8'); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="btn btn-link btn-sm p-0"
						>
							<?php echo Text::_('COM_FGEXTENSIONMANAGER_LINK_CHANGELOG'); ?>
						</a>
					<?php endif; ?>
				</td>
			</tr>
			<?php if (!empty($item->changelog_preview)) : ?>
				<tr class="fgem-detail-row" style="display: block; width: 100%;">
					<td colspan="7" class="p-0 border-0" style="display: block; width: 100%; box-sizing: border-box;">
						<div class="collapse" id="fgem-changelog-<?php echo (int) $i; ?>">
							<div class="p-3 bg-light">
								<div class="small" style="white-space: pre-wrap; overflow-wrap: break-word;"><?php echo htmlspecialchars($item->changelog_preview, ENT_QUOTES, 'UTF-8'); ?></div>
								<a href="<?php echo htmlspecialchars($item->changelog_full_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="small">
									<?php echo Text::_('COM_FGEXTENSIONMANAGER_LINK_FULL_CHANGELOG'); ?>
								</a>
							</div>
						</div>
					</td>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<?php endif; ?>

	<input type="hidden" name="task" id="fgem-task-field" value="" disabled>
	<input type="hidden" name="option" value="com_fgextensionmanager">
	<input type="hidden" name="view" value="extensions">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>

<script>
function fgemFilterRows(query) {
	query = query.toLowerCase().trim();
	var rows = document.querySelectorAll('#fgem-table tbody tr[data-fgem-search]');
	rows.forEach(function (row) {
		var matches = row.getAttribute('data-fgem-search').indexOf(query) !== -1;
		row.style.display = matches ? '' : 'none';

		var next = row.nextElementSibling;
		if (next && next.classList.contains('fgem-detail-row')) {
			next.style.display = matches ? '' : 'none';
		}
	});
}

// The detail row's CSS width:100% doesn't reliably resolve against the
// table's actual width in every browser once display is overridden to
// block on a <tr>/<td> (percentage-width containing-block rules get
// ambiguous there). Measuring the table's real rendered width in JS and
// applying it as an explicit pixel value sidesteps that entirely.
function fgemSyncDetailRowWidths() {
	var table = document.getElementById('fgem-table');
	if (!table) {
		return;
	}
	var width = table.offsetWidth + 'px';
	document.querySelectorAll('#fgem-table .fgem-detail-row > td').forEach(function (td) {
		td.style.width = width;
	});
}

window.addEventListener('load', fgemSyncDetailRowWidths);
window.addEventListener('resize', fgemSyncDetailRowWidths);
document.addEventListener('show.bs.collapse', fgemSyncDetailRowWidths);
fgemSyncDetailRowWidths();

// "Update All" and "Refresh" (toolbar buttons) need to set the task and
// submit the form. Joomla's own Joomla.submitform() does this via
// `form.task.value = task`, but our form ALSO has several per-row
// <button name="task" value="..."> elements (Install/Update/Uninstall,
// intentionally - each contributes its own value only when directly
// clicked as the submit control). With multiple elements sharing the name
// "task", `form.task` resolves to a RadioNodeList instead of a single
// element, and RadioNodeList.value's setter is only meaningful for radio
// inputs - setting it here is a silent no-op, so the hidden task field
// never actually gets the task value. Bypassing that entirely: submit
// directly via our hidden field's unique id instead of the ambiguous
// form.task reference.
(function () {
	if (typeof Joomla === 'undefined') {
		return;
	}

	var originalSubmitbutton = typeof Joomla.submitbutton === 'function' ? Joomla.submitbutton : null;

	Joomla.submitbutton = function (task) {
		if (task === 'extensions.updateAll') {
			var msg = <?php echo json_encode(Text::sprintf('COM_FGEXTENSIONMANAGER_UPDATE_ALL_CONFIRM', $this->updateAvailableCount)); ?>;

			if (!confirm(msg)) {
				return;
			}
		}

		var form      = document.getElementById('adminForm');
		var taskField = document.getElementById('fgem-task-field');

		if (form && taskField) {
			// Disabled by default so it never collides with the per-row
			// <button name="task" value="..."> elements (disabled form
			// fields are excluded from submission entirely) - only enabled
			// right here, for this one programmatic toolbar submit.
			taskField.disabled = false;
			taskField.value    = task;
			form.submit();

			return;
		}

		// Fallback, should never be needed given the field above always exists.
		if (originalSubmitbutton) {
			originalSubmitbutton(task);
		}
	};
})();
</script>
