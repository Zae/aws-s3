<?php

namespace craft\awss3;

use craft\base\Element;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Filesystems;
use yii\base\Event;


/**
 * Plugin represents the Amazon S3 filesystem.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Plugin extends \craft\base\Plugin
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '2.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(Filesystems::class, Filesystems::EVENT_REGISTER_FILESYSTEM_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Fs::class;
        });

        Event::on(Asset::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            if (!$event->isNew) {
                return;
            }

            /** @var Asset $asset */
            $asset = $event->sender;

            /** @var Fs $filesystem */
            $filesystem = $asset->getVolume()->getFilesystem();

            if (!$filesystem instanceof Fs) {
                return;
            }

            if (!$filesystem->autoFocalPoint) {
                return;
            }

            $fullPath = (!empty($filesystem->subfolder) ? rtrim($filesystem->subfolder, '/') . '/' : '') . $asset->getPath();

            $focalPoint = $filesystem->detectFocalPoint($fullPath);

            if (!empty($focalPoint)) {
                $assetRecord = \craft\records\Asset::findOne($asset->id);
                $assetRecord->focalPoint = min(max($focalPoint[0], 0), 1) . ';' . min(max($focalPoint[1], 0), 1);
                $assetRecord->save();
            }
        });
    }
}
