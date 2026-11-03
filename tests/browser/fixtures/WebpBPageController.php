<?php

namespace Restruct\WebpBrowser;

use SilverStripe\Assets\Image;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Versioned\Versioned;

/**
 * BROWSER-TEST FIXTURE ONLY - controller of WebpBPage. Renders its own HTML (the scratch host has
 * no theme): each seeded image through one module path, in an element the spec can find by id.
 *
 * @extends ContentController<WebpBPage>
 */
class WebpBPageController extends ContentController
{
    public function index()
    {
        $photo = Image::get()->filter('FileFilename', WebpBPage::PHOTO)->first();
        $gif = Image::get()->filter('FileFilename', WebpBPage::GIF)->first();
        # the draft-only image, as a CMS preview of an unpublished page would read it
        $draft = Versioned::get_by_stage(Image::class, Versioned::DRAFT)->filter('FileFilename', WebpBPage::DRAFT)->first();

        $parts = [
            'webpb-auto' => $photo ? $photo->ScaleWidth(200)->forTemplate() : '',
            'webpb-original' => $photo ? $photo->forTemplate() : '',
            'webpb-picture' => $photo ? (string) $photo->ScaleWidth(240)->Picture() : '',
            'webpb-gif' => $gif ? $gif->ScaleWidth(100)->forTemplate() : '',
            'webpb-draft' => $draft ? $draft->ScaleWidth(120)->forTemplate() : '',
        ];
        $body = '';
        foreach ($parts as $id => $html) {
            $body .= "<section id=\"{$id}\">{$html}</section>\n";
        }

        $response = HTTPResponse::create(
            "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>WebP images</title></head><body>\n{$body}</body></html>"
        );
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
}
