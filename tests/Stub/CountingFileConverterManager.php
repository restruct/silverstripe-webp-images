<?php

namespace Restruct\Silverstripe\WebpImages\Tests\Stub;

use SilverStripe\Assets\Conversion\FileConverterManager;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Dev\TestOnly;

/**
 * Counts conversions, so a test can see whether the module asked core to convert again
 */
class CountingFileConverterManager extends FileConverterManager implements TestOnly
{
    public int $calls = 0;

    public function convert(DBFile|File $from, string $toExtension, array $options = []): DBFile
    {
        $this->calls++;
        return parent::convert($from, $toExtension, $options);
    }
}
