<?php

namespace Restruct\Silverstripe\WebpImages\Tests\Stub;

use SilverStripe\Assets\Conversion\FileConverterManager;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Dev\TestOnly;

/**
 * Counts conversions, so a test can see whether the module asked core to convert again, and keeps
 * what it was handed
 */
class CountingFileConverterManager extends FileConverterManager implements TestOnly
{
    public int $calls = 0;

    /**
     * The objects handed to convert(), in call order
     *
     * @var array<int, DBFile|File>
     */
    public array $from = [];

    public function convert(DBFile|File $from, string $toExtension, array $options = []): DBFile
    {
        $this->calls++;
        $this->from[] = $from;
        return parent::convert($from, $toExtension, $options);
    }
}
