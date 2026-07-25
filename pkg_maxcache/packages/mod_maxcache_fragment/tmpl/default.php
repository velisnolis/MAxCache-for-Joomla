<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_maxcache_fragment
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

$sourceId = (int) $params->get('source_module_id', 0);
$fragmentId = (int) $module->id;
$trigger = (string) $params->get('trigger', 'visible');
$trigger = \in_array($trigger, ['visible', 'immediate', 'manual'], true) ? $trigger : 'visible';
$fallbackUrl = trim((string) $params->get('fallback_url', ''));
$fallbackText = trim((string) $params->get('fallback_text', 'Load content'));
$secret = (string) Factory::getConfig()->get('secret', '');
$signature = hash_hmac('sha256', 'fragment:' . $fragmentId . ':source:' . $sourceId, $secret);
$itemId = Factory::getApplication()->getInput()->getInt('Itemid');
$endpoint = Uri::root() . 'index.php?' . http_build_query(array_filter([
    'option' => 'com_ajax',
    'plugin' => 'maxcachefragment',
    'format' => 'raw',
    'fragment_id' => $fragmentId,
    'signature' => $signature,
    'Itemid' => $itemId > 0 ? $itemId : null,
]));

$document = Factory::getApplication()->getDocument();
$assets = $document->getWebAssetManager();
$assets->getRegistry()->addExtensionRegistryFile('mod_maxcache_fragment');
$assets->useScript('mod_maxcache_fragment.loader');
$moduleClass = trim((string) $params->get('moduleclass_sfx', ''));
$moduleClass = $moduleClass === '' ? '' : ' ' . htmlspecialchars($moduleClass, ENT_QUOTES, 'UTF-8');
?>
<div
	class="maxcache-fragment js-maxcache-fragment<?php echo $moduleClass; ?>"
	data-maxcache-fragment="<?php echo $fragmentId; ?>"
	data-maxcache-fragment-trigger="<?php echo htmlspecialchars($trigger, ENT_QUOTES, 'UTF-8'); ?>"
	data-maxcache-fragment-endpoint="<?php echo htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8'); ?>"
	aria-live="polite"
>
	<?php if ($fallbackUrl !== '') : ?>
		<a href="<?php echo htmlspecialchars($fallbackUrl, ENT_QUOTES, 'UTF-8'); ?>">
			<?php echo htmlspecialchars($fallbackText, ENT_QUOTES, 'UTF-8'); ?>
		</a>
	<?php elseif ($fallbackText !== '') : ?>
		<span class="visually-hidden"><?php echo htmlspecialchars($fallbackText, ENT_QUOTES, 'UTF-8'); ?></span>
	<?php endif; ?>
</div>
