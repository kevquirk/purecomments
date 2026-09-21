<?php
declare(strict_types=1);

/**
 * Shared Settings Navigation Bar for Pure Comments admin
 *
 * @var string $activeTab
 * @var array $config
 */

$activeTab = $activeTab ?? 'general';
$config = $config ?? [];

$settingsTabs = [
    'general'       => ['label' => t('settings.tab_general'),       'icon' => 'icon-globe',      'url' => pc_url('/settings.php?tab=general', $config)],
    'notifications' => ['label' => t('settings.tab_notifications'), 'icon' => 'icon-mail',       'url' => pc_url('/settings.php?tab=notifications', $config)],
    'webmentions'   => ['label' => t('settings.tab_webmentions'),   'icon' => 'icon-shield',     'url' => pc_url('/settings.php?tab=webmentions', $config)],
    'customise'     => ['label' => t('settings.tab_customise'),     'icon' => 'icon-paintbrush', 'url' => pc_url('/settings.php?tab=customise', $config)],
    'user'          => ['label' => t('settings.tab_user'),          'icon' => 'icon-user',       'url' => pc_url('/settings.php?tab=user', $config)],
    'updates'       => ['label' => t('settings.tab_updates'),       'icon' => 'icon-upgrade',    'url' => pc_url('/updates.php', $config)],
];
?>
<nav class="settings-tabs-nav" aria-label="<?php echo e(t('settings.heading')); ?>">
    <ul class="settings-tabs-list">
        <?php foreach ($settingsTabs as $tabKey => $tabInfo): ?>
            <?php $isCurrent = ($activeTab === $tabKey); ?>
            <li class="settings-tab-item">
                <a href="<?php echo e($tabInfo['url']); ?>" class="settings-tab-link<?php echo $isCurrent ? ' active' : ''; ?>">
                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo e(pc_url('/public/icons/sprite.svg', $config)); ?>#<?php echo e($tabInfo['icon']); ?>"></use></svg>
                    <span><?php echo e($tabInfo['label']); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
