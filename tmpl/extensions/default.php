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

$updateAvailableCount = count(array_filter($this->items, static fn ($item) => $item->state === 'update_available'))
	+ ($selfItem !== null && $selfItem->state === 'update_available' ? 1 : 0);
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

	<div class="d-flex justify-content-between align-items-center mb-3">
		<p class="mb-0 text-muted">
			<?php echo Text::sprintf('COM_FGEXTENSIONMANAGER_EXTENSIONS_COUNT', count($this->items)); ?>
		</p>
		<div>
			<?php if ($canManage && $updateAvailableCount > 0) : ?>
			<button
				type="submit"
				name="task"
				value="extensions.updateAll"
				formaction="<?php echo Route::_('index.php?option=com_fgextensionmanager&task=extensions.updateAll'); ?>"
				class="btn btn-warning btn-sm"
				onclick="return confirm(<?php echo htmlspecialchars(json_encode(Text::sprintf('COM_FGEXTENSIONMANAGER_UPDATE_ALL_CONFIRM', $updateAvailableCount)), ENT_QUOTES, 'UTF-8'); ?>);"
			>
				<span class="icon-loop" aria-hidden="true"></span>
				<?php echo Text::sprintf('COM_FGEXTENSIONMANAGER_BUTTON_UPDATE_ALL', $updateAvailableCount); ?>
			</button>
			<?php endif; ?>
			<?php if ($canManage) : ?>
			<button type="submit" name="task" value="extensions.refresh" class="btn btn-secondary btn-sm">
				<span class="icon-refresh" aria-hidden="true"></span>
				<?php echo Text::_('COM_FGEXTENSIONMANAGER_BUTTON_REFRESH'); ?>
			</button>
			<?php endif; ?>
		</div>
	</div>

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

	<?php if (empty($this->items)) : ?>
		<div class="alert alert-info">
			<?php echo Text::_('COM_FGEXTENSIONMANAGER_NO_REPOS_CONFIGURED'); ?>
		</div>
	<?php else : ?>

	<div class="table-responsive">
	<table class="table">
		<thead>
			<tr>
				<th scope="col"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_NAME'); ?></th>
				<th scope="col"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_STATE'); ?></th>
				<th scope="col"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_ENABLED'); ?></th>
				<th scope="col"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_INSTALLED_VERSION'); ?></th>
				<th scope="col"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_AVAILABLE_VERSION'); ?></th>
				<th scope="col"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_REPO'); ?></th>
				<th scope="col" class="text-end"><?php echo Text::_('COM_FGEXTENSIONMANAGER_COL_ACTIONS'); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($this->items as $i => $item) :
			$meta = $stateMeta[$item->state] ?? $stateMeta['error'];
		?>
			<tr<?php echo $item->is_self ? ' style="background-color: rgba(255, 107, 74, 0.08);"' : ($i % 2 === 1 ? ' class="table-light"' : ''); ?>>
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
				<td>
					<span class="badge <?php echo $meta['badge']; ?>"><?php echo Text::_($meta['label']); ?></span>
					<?php if (in_array($item->state, ['error', 'incompatible'], true) && !empty($item->error)) : ?>
						<div class="small mt-1 <?php echo $item->state === 'error' ? 'text-danger' : 'text-muted'; ?>"><?php echo htmlspecialchars($item->error, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>
				</td>
				<td>
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
				<td><?php echo $item->installed_version ? htmlspecialchars($item->installed_version, ENT_QUOTES, 'UTF-8') : '&#8212;'; ?></td>
				<td><?php echo $item->available_version ? htmlspecialchars($item->available_version, ENT_QUOTES, 'UTF-8') : '&#8212;'; ?></td>
				<td>
					<?php
					$slashPos    = strpos($item->owner_repo, '/');
					$repoDisplay = $slashPos !== false ? substr($item->owner_repo, $slashPos + 1) : $item->owner_repo;
					?>
					<a href="<?php echo htmlspecialchars($item->repo_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo htmlspecialchars($item->owner_repo, ENT_QUOTES, 'UTF-8'); ?>">
						<?php echo htmlspecialchars($repoDisplay, ENT_QUOTES, 'UTF-8'); ?>
					</a>
				</td>
				<td class="text-end">
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
				<tr>
					<td colspan="7" class="p-0 border-0">
						<div class="collapse" id="fgem-changelog-<?php echo (int) $i; ?>">
							<div class="p-3 bg-light">
								<div class="small" style="white-space: pre-wrap;"><?php echo htmlspecialchars($item->changelog_preview, ENT_QUOTES, 'UTF-8'); ?></div>
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

	<input type="hidden" name="option" value="com_fgextensionmanager">
	<input type="hidden" name="view" value="extensions">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
