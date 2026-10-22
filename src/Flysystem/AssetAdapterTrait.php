<?php

declare(strict_types=1);

namespace WeDevelop\WebpImages\Flysystem;

// use League\Flysystem\PathPrefixer;

trait AssetAdapterTrait
{
    public function delete($path): void
    {
        // A PathPrefixer with an empty prefix returns $path unchanged, so the unlink below was
        // relative to the process cwd instead of the assets root and never removed the .webp sibling.
        // $location = new PathPrefixer('');
        // $location = $location->prefixPath($path);
        //
        // @unlink($location . '.webp');

        # Let the adapter resolve the sibling against its own (private) root prefixer;
        # LocalFilesystemAdapter::delete() is a no-op when the file does not exist.
        parent::delete($path . '.webp');

        parent::delete($path);
    }
}
